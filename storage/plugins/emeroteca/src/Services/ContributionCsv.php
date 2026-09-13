<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Services;

require_once __DIR__ . '/ContributionService.php';

/** CSV preview is a bounded snapshot; commit rechecks the revision of every row. */
final class ContributionCsv
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @param ContributionService $service used for both lookups during preview and the actual save in commit() */
    public function __construct(private ContributionService $service)
    {
    }
    /**
     * Parse and validate an uploaded CSV (≤5 MB, UTF-8, ≤500 data rows) into a per-row preview:
     * each entry carries its line number, resolved id/revision if a matching reference_key
     * already exists, the normalized data to save, and either an error (row will be skipped by
     * commit()) or a non-blocking warning (e.g. missing container title). Header aliases are
     * mapped to internal field names, duplicate/unknown headers reject the whole file, and a
     * likely duplicate (same title/authors/container/volume/issue/pages, or matching DOI) on a
     * new row is flagged as an error rather than silently imported twice.
     *
     * @return array<int, array{line: int, error: string|null, id: int, revision: int|null, data: array<string, mixed>, warning: string|null}>
     * @throws \InvalidArgumentException on a file-level problem (size, encoding, headers, row count)
     */
    public function preview(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(__('Il CSV supera 5 MB.'));
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw new \InvalidArgumentException(__('Il CSV deve essere UTF-8.'));
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if (!$headers) {
            fclose($stream);
            throw new \InvalidArgumentException(__('CSV vuoto.'));
        }
        $aliases = ['title' => 'titolo','authors' => 'autori','container_title' => 'contenitore_titolo','journal_title' => 'contenitore_titolo','issue' => 'numero','pages' => 'pagine','volume' => 'volume','date' => 'data_pubblicazione_testo','year' => 'anno_pubblicazione','media_type' => 'record_type'];
        $headers = array_map(static fn ($h) => $aliases[strtolower(trim((string)$h))] ?? strtolower(trim((string)$h)), $headers);
        if (count($headers) !== count(array_unique($headers)) || !in_array('titolo', $headers, true)) {
            fclose($stream);
            throw new \InvalidArgumentException(__('Intestazioni duplicate o titolo mancante.'));
        }
        $unknown = array_diff($headers, [...ContributionService::CSV_FIELDS,'record_type']);
        if ($unknown) {
            fclose($stream);
            throw new \InvalidArgumentException(__('Colonne non riconosciute: ') . implode(', ', $unknown));
        }
        $out = [];
        $seen = [];
        $citations = [];
        $dois = [];
        $line = 1;
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            ++$line;
            if ($row === [null]) {
                continue;
            }
            if (count($out) >= self::MAX_ROWS) {
                fclose($stream);
                throw new \InvalidArgumentException(__('Importa al massimo 500 articoli alla volta.'));
            }
            $item = ['line' => $line,'error' => null,'id' => 0,'revision' => null,'data' => [],'warning' => null];
            try {
                if (count($row) !== count($headers)) {
                    throw new \InvalidArgumentException(__('Numero di colonne non valido.'));
                }
                $data = array_combine($headers, $row);
                $type = strtolower(trim((string)($data['record_type'] ?? 'article')));
                if (!in_array($type, ['article','articolo','journal_article','newspaper_article'], true)) {
                    throw new \InvalidArgumentException(__('Tipo di record non supportato.'));
                }
                if ($type === 'journal_article') {
                    $data['contenitore_tipo'] = 'rivista';
                }
                if ($type === 'newspaper_article') {
                    $data['contenitore_tipo'] = 'giornale';
                }
                unset($data['record_type']);
                $key = trim((string)($data['reference_key'] ?? ''));
                if ($key === '') {
                    $key = bin2hex(random_bytes(16));
                } elseif (!preg_match(ContributionService::REFERENCE_KEY_PATTERN, $key)) {
                    throw new \InvalidArgumentException(__('Identificatore non valido.'));
                }
                if (isset($seen[$key])) {
                    throw new \InvalidArgumentException(__('Identificatore ripetuto nel CSV.'));
                }
                $seen[$key] = true;
                $data['reference_key'] = $key;
                $existing = $this->service->rows('SELECT * FROM emeroteca_contributi WHERE reference_key=?', [$key])[0] ?? null;
                if ($existing) {
                    $item['id'] = (int)$existing['id'];
                    $item['revision'] = (int)$existing['revision'];
                }
                $merged = array_replace($existing ?? [], $data);
                $item['data'] = array_replace($merged, ContributionService::normalize($merged));
                // Updates are checked too: a row can carry a known
                // reference_key and the DOI or citation of a DIFFERENT article,
                // which would store the same article twice. What is NOT checked
                // is an update that keeps its own citation — otherwise
                // re-importing an export would trip over rows that were already
                // there, itself included.
                $citation = self::citationKey($item['data']);
                $doi = (string)($item['data']['doi'] ?? '');
                $takesNewIdentity = !$existing
                    || $citation !== self::citationKey($existing)
                    || $doi !== (string)($existing['doi'] ?? '');
                if ($takesNewIdentity) {
                    if ($this->hasDuplicate($item['data'], (int)$item['id']) || isset($citations[$citation]) || ($doi !== '' && isset($dois[$doi]))) {
                        $item['error'] = self::duplicateMessage();
                    }
                    $citations[$citation] = true;
                    if ($doi !== '') { $dois[$doi] = true; }
                }
                if (empty($item['data']['contenitore_titolo'])) {
                    $item['warning'] = __('Citazione incompleta: pubblicazione non indicata.');
                }
            } catch (\InvalidArgumentException $e) {
                $item['error'] = $e->getMessage();
            }
            $out[] = $item;
        }
        fclose($stream);
        return $out;
    }
    /**
     * Save every non-erroring row from a preview() result, passing along the id/revision it
     * captured so a row whose target was changed since the preview was taken (or by a
     * concurrent import of the same reference_key) is rejected rather than overwritten.
     *
     * @param array<int, array{line: int, error: string|null, id: int, revision: int|null, data: array<string, mixed>, warning: string|null}> $preview
     * @return array<int, array{line: int, id?: int, error: string|null}>
     */
    public function commit(array $preview): array
    {
        // One lock for the whole batch, not one per row: waiting per row
        // multiplies the timeout by the number of rows, so a contended import of
        // 500 rows would hold the request for well over an hour and be killed by
        // max_execution_time instead of reporting that another import is running.
        $lock = $this->lockName();
        if ((int)($this->service->rows('SELECT GET_LOCK(?, 10) acquired', [$lock])[0]['acquired'] ?? 0) !== 1) {
            throw new \InvalidArgumentException(__('Un altro import di articoli è in corso. Riprova tra qualche istante.'));
        }
        try {
            $report = [];
            foreach ($preview as $row) {
                if ($row['error']) {
                    $report[] = ['line' => $row['line'],'error' => $row['error']];
                    continue;
                }
                try {
                    // A retry/new concurrent import with the same identity is never overwritten.
                    $id = $this->saveImportRow($row);
                    $report[] = ['line' => $row['line'],'id' => $id,'error' => null];
                } catch (\Throwable $e) {
                    $report[] = ['line' => $row['line'],'error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : __('Importazione non riuscita. Verifica identificatore e dati.')];
                }
            }
            return $report;
        } finally {
            $this->service->rows('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
    private static function duplicateMessage(): string
    {
        return __('Possibile duplicato: usa la reference_key esistente per aggiornare, oppure verifica la citazione.');
    }

    /**
     * The identity the in-batch duplicate check compares.
     *
     * Folded the way the column collation (utf8mb4_unicode_ci) compares, so a
     * batch flags exactly what the commit-time query would: case AND accents are
     * ignored. Comparing case alone let «Città» and «Citta» through the preview
     * only to be refused at commit — the right outcome reported at the wrong
     * moment. Without ext-intl the fold stops at case, which is what the check
     * did before, so a missing extension can only report later, never wronger.
     */
    private static function citationKey(array $data): string
    {
        $fold = static function (string $value): string {
            $value = mb_strtolower($value);
            if (class_exists('\Normalizer')) {
                $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
                if (is_string($decomposed)) {
                    $value = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $value;
                }
            }
            return $value;
        };
        return json_encode(array_map(static fn($key) => $fold((string)($data[$key] ?? '')), ['titolo','autori','contenitore_titolo','volume','numero','pagine']), JSON_THROW_ON_ERROR);
    }

    /** @param int $excludeId the row being updated, which is never a duplicate of itself */
    private function hasDuplicate(array $data, int $excludeId = 0): bool
    {
        $params = array_map(static fn($key) => $data[$key] ?? '', ['titolo','autori','contenitore_titolo','volume','numero','pagine']);
        $params[] = $excludeId;
        $matches = $this->service->rows("SELECT id FROM emeroteca_contributi WHERE titolo=? AND COALESCE(autori,'')=? AND COALESCE(contenitore_titolo,'')=? AND COALESCE(volume,'')=? AND COALESCE(numero,'')=? AND COALESCE(pagine,'')=? AND id<>? LIMIT 1", $params);
        return $matches !== [] || (!empty($data['doi']) && $this->service->rows('SELECT id FROM emeroteca_contributi WHERE doi=? AND id<>? LIMIT 1', [$data['doi'], $excludeId]) !== []);
    }

    /** The named lock commit() holds: one per database, so two imports never interleave. */
    private function lockName(): string
    {
        $database = $this->service->rows('SELECT DATABASE() name')[0]['name'];
        return 'emeroteca_csv_' . substr(hash('sha256', (string)$database), 0, 40);
    }

    /** Runs under the commit() lock, so this recheck cannot race another import. */
    private function saveImportRow(array $row): int
    {
        $id = (int)$row['id'];
        // Same rule as the preview, rechecked under the lock: a row that keeps
        // the citation it already had is not a duplicate of itself.
        $stored = $id ? $this->service->get($id) : null;
        $takesNewIdentity = $stored === null
            || self::citationKey($row['data']) !== self::citationKey($stored)
            || (string)($row['data']['doi'] ?? '') !== (string)($stored['doi'] ?? '');
        if ($takesNewIdentity && $this->hasDuplicate($row['data'], $id)) {
            throw new \InvalidArgumentException(self::duplicateMessage());
        }
        return $this->service->save($row['data'], (int)$row['id'], $row['revision']);
    }

    /** CSV files bounded by the same row and byte limits as preview(), including their header. */
    public function exportParts(): \Generator
    {
        $encode = static function(array $cells): string {
            $fp = fopen('php://temp', 'w+');
            try {
                fputcsv($fp, $cells, ',', '"', '');
                rewind($fp);
                return stream_get_contents($fp);
            } finally { fclose($fp); }
        };
        $header = $encode(ContributionService::CSV_HEADER);
        $part = $header;
        $count = 0;
        $cursor = 0;
        do {
            $rows = $this->service->rows('SELECT * FROM emeroteca_contributi WHERE id>? ORDER BY id LIMIT 500', [$cursor]);
            foreach ($rows as $row) {
                $line = $encode([self::recordTypeFor((string)($row['contenitore_tipo'] ?? '')), ...array_map(static fn($key) => $row[$key] ?? '', ContributionService::CSV_FIELDS)]);
                if ($count >= self::MAX_ROWS || strlen($part) + strlen($line) > self::MAX_BYTES) {
                    yield $part;
                    $part = $header;
                    $count = 0;
                }
                $part .= $line;
                $count++;
                $cursor = (int)$row['id'];
            }
        } while (count($rows) === 500);
        yield $part;
    }

    /** The record_type a row should declare, mirroring the importer's own mapping. */
    private static function recordTypeFor(string $containerType): string
    {
        return match ($containerType) {
            'rivista'  => 'journal_article',
            'giornale' => 'newspaper_article',
            default    => 'article',
        };
    }

    /**
     * Combined CSV for programmatic callers. The download controller uses exportParts()
     * so large collections produce import-sized files. Literal cells preserve round-trip
     * values; record_type distinguishes articles from book imports.
     */
    public function export(): string
    {
        $csv = '';
        foreach ($this->exportParts() as $index => $part) {
            // Every part starts with the same single-line header.
            $csv .= $index === 0 ? $part : substr($part, strpos($part, "\n") + 1);
        }
        return $csv;
    }
}
