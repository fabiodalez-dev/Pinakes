<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Services;

require_once __DIR__ . '/ContributionService.php';

/** CSV preview is a bounded snapshot; commit rechecks the revision of every row. */
final class ContributionCsv
{
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
        if (strlen($csv) > 5 * 1024 * 1024) {
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
        $line = 1;
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            ++$line;
            if ($row === [null]) {
                continue;
            }
            if (count($out) >= 500) {
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
                if (!$existing) {
                    $candidates = $this->service->rows('SELECT id FROM emeroteca_contributi WHERE titolo=? AND COALESCE(autori,\'\')=? AND COALESCE(contenitore_titolo,\'\')=? AND COALESCE(volume,\'\')=? AND COALESCE(numero,\'\')=? AND COALESCE(pagine,\'\')=? LIMIT 1', [$item['data']['titolo'],$item['data']['autori'] ?? '',$item['data']['contenitore_titolo'] ?? '',$item['data']['volume'] ?? '',$item['data']['numero'] ?? '',$item['data']['pagine'] ?? '']);
                    if ($candidates || (!empty($item['data']['doi']) && $this->service->rows('SELECT id FROM emeroteca_contributi WHERE doi=? LIMIT 1', [$item['data']['doi']]))) {
                        $item['error'] = __('Possibile duplicato: usa la reference_key esistente per aggiornare, oppure verifica la citazione.');
                    }
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
        $report = [];
        foreach ($preview as $row) {
            if ($row['error']) {
                $report[] = ['line' => $row['line'],'error' => $row['error']];
                continue;
            }
            try {
                // A retry/new concurrent import with the same identity is never overwritten.
                $id = $this->service->save($row['data'], (int)$row['id'], $row['revision']);
                $report[] = ['line' => $row['line'],'id' => $id,'error' => null];
            } catch (\Throwable $e) {
                $report[] = ['line' => $row['line'],'error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : __('Importazione non riuscita. Verifica identificatore e dati.')];
            }
        }
        return $report;
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
     * Dump every contribution as CSV (machine round-trip format: literal cells, not meant to be
     * opened as a spreadsheet), keyset-paginated by id in batches of 500 so the whole table
     * never has to be held in memory at once. record_type is derived from contenitore_tipo, not
     * stored, and is what makes the file distinguishable from a book-catalog CSV on re-import.
     */
    public function export(): string
    {
        $fp = fopen('php://temp', 'w+');
        // Machine round-trip CSV: preserve literal cells; do not use as a spreadsheet.
        fputcsv($fp, ContributionService::CSV_HEADER, ',', '"', '');
        $cursor = 0;
        do {
            $rows = $this->service->rows('SELECT * FROM emeroteca_contributi WHERE id>? ORDER BY id LIMIT 500', [$cursor]);
            foreach ($rows as $row) {
                // record_type round-trips the publication type AND is what makes
                // this file refusable by the book importer. Derived, not stored.
                $cells = [self::recordTypeFor((string)($row['contenitore_tipo'] ?? ''))];
                foreach (ContributionService::CSV_FIELDS as $key) {
                    $cells[] = $row[$key] ?? '';
                }
                fputcsv($fp, $cells, ',', '"', '');
                $cursor = (int)$row['id'];
            }
        } while (count($rows) === 500);
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }
}
