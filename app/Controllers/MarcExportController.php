<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Marc\BookMarcBuilder;
use App\Support\Marc\GeneratorStream;
use App\Support\Marc\Iso2709Writer;
use App\Support\Marc\MarcXmlWriter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bulk MARC export of the catalogue or of a selection (?ids=1,2,3, as the CSV
 * export of #77): MARC 21 in ISO 2709 (.mrc), a MARCXML collection, or
 * UNIMARC in ISO 2709. Books are read in pages of 200 and written while the
 * response is sent, so the size of the catalogue never reaches memory. The
 * records come from BookMarcBuilder, the OAI server's own serializer, with
 * the exchange fields (EAN, LC, Dewey for UNIMARC, cover) added.
 */
class MarcExportController
{
    public const FORMATS = ['marc21', 'marcxml', 'unimarc'];
    private const PAGE = 200;
    private const MAX_SELECTED = 1000;

    public function export(Request $request, Response $response, \mysqli $db): Response
    {
        $params = $request->getQueryParams();
        $format = (string) ($params['format'] ?? 'marc21');
        if (!in_array($format, self::FORMATS, true)) {
            $format = 'marc21';
        }
        $ids = null;
        if (array_key_exists('ids', $params)) {
            $raw = is_string($params['ids']) ? $params['ids'] : '';
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id): bool => $id > 0)));
            if (count($ids) > self::MAX_SELECTED) {
                $_SESSION['error_message'] = sprintf(
                    __('Selezione troppo grande per l\'esportazione: %d libri (massimo 1000). Riduci la selezione e riprova.'),
                    count($ids)
                );
                return $response->withHeader('Location', url('/admin/books'))->withStatus(302);
            }
        }

        $stamp = date('Y-m-d');
        [$contentType, $filename] = match ($format) {
            'marcxml' => ['application/marcxml+xml; charset=UTF-8', "catalogo_marcxml_{$stamp}.xml"],
            'unimarc' => ['application/marc', "catalogo_unimarc_{$stamp}.mrc"],
            default => ['application/marc', "catalogo_marc21_{$stamp}.mrc"],
        };

        \App\Support\SecureLogger::info('[MARC export]', [
            'format' => $format,
            'selected' => $ids === null ? null : count($ids),
            'user_id' => $_SESSION['user']['id'] ?? null,
        ]);

        return $response
            ->withBody(new GeneratorStream($this->generate($db, $format, $ids)))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('X-Accel-Buffering', 'no');
    }

    /**
     * @param list<int>|null $ids
     * @return \Generator<int, string>
     */
    public function generate(\mysqli $db, string $format, ?array $ids): \Generator
    {
        $xml = null;
        if ($format === 'marcxml') {
            $xml = new MarcXmlWriter(MarcXmlWriter::NS_MARC21);
            $xml->startCollection();
            yield $xml->output();
        }
        foreach ($this->books($db, $ids) as [$row, $authors, $publishers, $genre]) {
            $record = $format === 'unimarc'
                ? BookMarcBuilder::unimarc($row, $authors, $publishers, $genre, true)
                : BookMarcBuilder::marc21($row, $authors, $publishers, $genre, true);
            if ($xml !== null) {
                $xml->writeRecord($record);
                yield $xml->output();
                continue;
            }
            try {
                yield Iso2709Writer::serialize($record);
            } catch (\OverflowException $e) {
                \App\Support\SecureLogger::warning('[MARC export] record too long for ISO 2709, skipped', ['book_id' => $row['id'] ?? null]);
            }
        }
        if ($xml !== null) {
            $xml->endCollection();
            yield $xml->output();
        }
    }

    /**
     * The books to export, page by page, with their authors (in credit
     * order), publishers and genre.
     *
     * @param list<int>|null $ids
     * @return \Generator<int, array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: array<string, mixed>|null}>
     */
    private function books(\mysqli $db, ?array $ids): \Generator
    {
        if ($ids === []) {
            return;
        }
        $hasJunction = \App\Support\SchemaInfo::hasLibriEditori($db);
        $lastId = 0;
        while (true) {
            $sql = 'SELECT * FROM libri WHERE deleted_at IS NULL AND id > ?';
            $types = 'i';
            $params = [$lastId];
            if ($ids !== null) {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $types .= str_repeat('i', count($ids));
                $params = array_merge($params, $ids);
            }
            $sql .= ' ORDER BY id LIMIT ' . self::PAGE;
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if ($rows === []) {
                return;
            }
            $pageIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $authors = $this->grouped($db,
                "SELECT la.libro_id, a.nome, la.ruolo, la.ordine_credito
                   FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                  WHERE la.libro_id IN (%s)
                  ORDER BY la.libro_id, CASE la.ruolo WHEN 'principale' THEN 0 WHEN 'co-autore' THEN 1 ELSE 2 END,
                           la.ordine_credito IS NULL, la.ordine_credito, a.nome",
                $pageIds);
            $publishers = $hasJunction ? $this->grouped($db,
                'SELECT le.libro_id, e.id, e.nome FROM libri_editori le JOIN editori e ON e.id = le.editore_id
                  WHERE le.libro_id IN (%s) ORDER BY le.libro_id, le.ordine, e.nome',
                $pageIds) : [];
            $primary = $this->byId($db, 'SELECT id, nome FROM editori WHERE id IN (%s)', array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['editore_id'] ?? 0), $rows))));
            $genres = $this->byId($db, 'SELECT id, nome FROM generi WHERE id IN (%s)', array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['genere_id'] ?? 0), $rows))));
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $bookPublishers = $publishers[$id] ?? [];
                $primaryId = (int) ($row['editore_id'] ?? 0);
                if ($bookPublishers === [] && $primaryId > 0 && isset($primary[$primaryId])) {
                    $bookPublishers = [$primary[$primaryId]];
                }
                $genreId = (int) ($row['genere_id'] ?? 0);
                yield [$row, $authors[$id] ?? [], $bookPublishers, $genreId > 0 ? ($genres[$genreId] ?? null) : null];
                $lastId = $id;
            }
            if (count($rows) < self::PAGE) {
                return;
            }
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function grouped(\mysqli $db, string $sql, array $ids): array
    {
        $out = [];
        foreach ($this->fetch($db, $sql, $ids) as $row) {
            $out[(int) $row['libro_id']][] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function byId(\mysqli $db, string $sql, array $ids): array
    {
        $out = [];
        foreach ($this->fetch($db, $sql, array_values(array_unique($ids))) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function fetch(\mysqli $db, string $sql, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $db->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
