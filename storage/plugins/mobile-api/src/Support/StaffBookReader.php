<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Read side of the staff book editor: the `StaffBook` edit model, the
 * complete current record a PUT is merged over, and the duplicate probe used
 * by the ISBN lookup.
 *
 * Every `libri` read carries `deleted_at IS NULL` (CLAUDE.md rule 2): a
 * soft-deleted title is a 404 for the staff surface too. No catalogue
 * visibility filter is applied — operators manage wanted titles as well.
 */
final class StaffBookReader
{
    public function __construct(private \mysqli $db)
    {
    }

    /**
     * @return array<string, mixed>|null the libri row joined with genre names and shelf data
     */
    public function row(int $bookId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, g.nome AS genre_name, sg.nome AS subgenre_name,
                    s.codice AS shelf_code, m.numero_livello AS level_number
               FROM libri l
               LEFT JOIN generi g ON g.id = l.genere_id
               LEFT JOIN generi sg ON sg.id = l.sottogenere_id
               LEFT JOIN scaffali s ON s.id = l.scaffale_id
               LEFT JOIN mensole m ON m.id = l.mensola_id
              WHERE l.id = ? AND l.deleted_at IS NULL
              LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('staff book read prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    /**
     * The `StaffBook` edit model, or null when the title does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function load(int $bookId): ?array
    {
        $row = $this->row($bookId);
        if ($row === null) {
            return null;
        }

        $cover = trim((string) ($row['copertina_url'] ?? ''));
        $coverUrl = null;
        if ($cover !== '') {
            $coverUrl = preg_match('#^https?://#i', $cover) === 1 ? $cover : absoluteUrl($cover);
        }

        $location = null;
        $shelfId = (int) ($row['scaffale_id'] ?? 0);
        $levelId = (int) ($row['mensola_id'] ?? 0);
        $label = trim((string) ($row['collocazione'] ?? ''));
        if ($shelfId > 0 || $levelId > 0 || $label !== '') {
            $location = [
                'shelf_id'     => $shelfId > 0 ? $shelfId : null,
                'shelf_code'   => self::nullable($row['shelf_code'] ?? null),
                'level_id'     => $levelId > 0 ? $levelId : null,
                'level_number' => isset($row['level_number']) ? (int) $row['level_number'] : null,
                'position'     => isset($row['posizione_progressiva']) && (int) $row['posizione_progressiva'] > 0
                    ? (int) $row['posizione_progressiva'] : null,
                'label'        => $label !== '' ? $label : null,
            ];
        }

        $genreId = (int) ($row['genere_id'] ?? 0);
        $subgenreId = (int) ($row['sottogenere_id'] ?? 0);

        return [
            'id'               => (int) $row['id'],
            'title'            => (string) ($row['titolo'] ?? ''),
            'subtitle'         => self::nullable($row['sottotitolo'] ?? null),
            'isbn10'           => self::nullable($row['isbn10'] ?? null),
            'isbn13'           => self::nullable($row['isbn13'] ?? null),
            'ean'              => self::nullable($row['ean'] ?? null),
            'issn'             => self::nullable($row['issn'] ?? null),
            'year'             => isset($row['anno_pubblicazione']) ? (int) $row['anno_pubblicazione'] : null,
            'pub_date'         => self::nullable($row['data_pubblicazione'] ?? null),
            'edition'          => self::nullable($row['edizione'] ?? null),
            'place'            => self::nullable($row['luogo_pubblicazione'] ?? null),
            'pages'            => isset($row['numero_pagine']) ? (int) $row['numero_pagine'] : null,
            'language'         => self::nullable($row['lingua'] ?? null),
            'format'           => self::nullable($row['formato'] ?? null),
            'media_type'       => self::nullable($row['tipo_media'] ?? null),
            'description'      => self::nullable($row['descrizione'] ?? null),
            'keywords'         => self::nullable($row['parole_chiave'] ?? null),
            'notes'            => self::nullable($row['note_varie'] ?? null),
            'series'           => self::nullable($row['collana'] ?? null),
            'series_number'    => self::nullable($row['numero_serie'] ?? null),
            'dewey'            => self::nullable($row['classificazione_dewey'] ?? null),
            'price'            => isset($row['prezzo']) ? (float) $row['prezzo'] : null,
            'acquisition_date' => self::nullable($row['data_acquisizione'] ?? null),
            'acquisition_type' => self::nullable($row['tipo_acquisizione'] ?? null),
            'inventory_base'   => self::nullable($row['numero_inventario'] ?? null),
            'copies_total'     => (int) ($row['copie_totali'] ?? 0),
            'copies_available' => (int) ($row['copie_disponibili'] ?? 0),
            'cover_url'        => $coverUrl,
            'cover_is_local'   => str_starts_with($cover, '/uploads/copertine/'),
            'genre'            => $genreId > 0 ? ['id' => $genreId, 'name' => (string) ($row['genre_name'] ?? '')] : null,
            'subgenre'         => $subgenreId > 0 ? ['id' => $subgenreId, 'name' => (string) ($row['subgenre_name'] ?? '')] : null,
            'authors'          => $this->authors($bookId),
            'publishers'       => $this->publishers($bookId, (int) ($row['editore_id'] ?? 0)),
            'location'         => $location,
            'copies'           => $this->copies($bookId),
            'is_wanted'        => (int) ($row['is_desiderata'] ?? 0) === 1,
            'created_at'       => self::nullable($row['created_at'] ?? null),
            'updated_at'       => self::nullable($row['updated_at'] ?? null),
        ] + $this->cataloguing($row);
    }

    /**
     * Pinakes 0.9.0 cataloguing fields, each group only while its module is
     * on (Settings → Modules): the supplier, and the RDA content / media /
     * carrier overrides with the effective triple (override, or derived
     * from the media type).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function cataloguing(array $row): array
    {
        $out = [];
        if (\App\Support\Features::enabled('suppliers') && array_key_exists('fornitore_id', $row)) {
            $supplierId = (int) ($row['fornitore_id'] ?? 0);
            $supplier = null;
            if ($supplierId > 0) {
                $found = (new \App\Models\SupplierRepository($this->db))->getById($supplierId);
                $supplier = $found !== null ? ['id' => $supplierId, 'name' => (string) $found['nome']] : null;
            }
            $out['supplier_id'] = $supplier !== null ? $supplierId : null;
            $out['supplier'] = $supplier;
        }
        if (\App\Support\Features::enabled('rda_types')) {
            foreach (StaffBookPayload::RDA_FIELDS as $key => $kind) {
                $out[$key] = \App\Support\RdaTypes::normalize($kind, $row[$key] ?? null);
            }
            $effective = \App\Support\RdaTypes::effective($row);
            $out['rda_effective'] = [
                'content_type' => $effective['content'],
                'media_type'   => $effective['media'],
                'carrier_type' => $effective['carrier'],
            ];
        }

        return $out;
    }

    /**
     * Everything a PUT must re-post so LibriController::update() keeps it.
     *
     * @return array{
     *   row: array<string, mixed>,
     *   creator_ids: list<int>,
     *   contributor_ids: array<string, list<int>>,
     *   publisher_ids: list<int>,
     *   other_series: string
     * }|null
     */
    public function currentState(int $bookId): ?array
    {
        $row = $this->row($bookId);
        if ($row === null) {
            return null;
        }

        $creators = [];
        $contributors = array_fill_keys(array_keys(StaffBookPayload::PREFIX_TO_ROLE), []);
        $roleToPrefix = array_flip(StaffBookPayload::PREFIX_TO_ROLE);
        foreach ($this->authorLinks($bookId) as $link) {
            if (in_array($link['role'], ['principale', 'co-autore'], true)) {
                $creators[] = $link['id'];
            } elseif (isset($roleToPrefix[$link['role']])) {
                $contributors[$roleToPrefix[$link['role']]][] = $link['id'];
            }
        }

        $publisherIds = array_map(
            static fn (array $p): int => (int) $p['id'],
            $this->publishers($bookId, (int) ($row['editore_id'] ?? 0))
        );

        $otherSeries = '';
        try {
            $otherSeries = (string) (new \App\Models\SeriesRepository($this->db))
                ->getOtherSeriesText($bookId, isset($row['collana']) ? (string) $row['collana'] : null);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[MobileApi] staff book series read failed: ' . $e->getMessage());
        }

        return [
            'row'             => $row,
            'creator_ids'     => array_values(array_unique($creators)),
            'contributor_ids' => array_map(static fn (array $ids): array => array_values(array_unique($ids)), $contributors),
            'publisher_ids'   => $publisherIds,
            'other_series'    => $otherSeries,
        ];
    }

    /**
     * First non-deleted title carrying any of the codes in isbn10/isbn13/ean
     * (same cross-column rule as LibriController::store()).
     *
     * @param list<string> $codes
     * @return array<string, mixed>|null
     */
    public function findByIdentifiers(array $codes): ?array
    {
        $codes = array_values(array_unique(array_filter($codes, static fn (string $c): bool => $c !== '')));
        if ($codes === []) {
            return null;
        }
        $in = '(' . implode(',', array_fill(0, count($codes), '?')) . ')';
        $stmt = $this->db->prepare(
            "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.ean, l.copie_totali, l.collocazione,
                    l.posizione_progressiva, s.codice AS shelf_code, m.numero_livello AS level_number
               FROM libri l
               LEFT JOIN scaffali s ON s.id = l.scaffale_id
               LEFT JOIN mensole m ON m.id = l.mensola_id
              WHERE (l.isbn10 IN {$in} OR l.isbn13 IN {$in} OR l.ean IN {$in})
                AND l.deleted_at IS NULL
              ORDER BY l.id
              LIMIT 1"
        );
        if ($stmt === false) {
            throw new \RuntimeException('identifier probe prepare failed: ' . $this->db->error);
        }
        $params = array_merge($codes, $codes, $codes);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id'           => (int) $row['id'],
            'title'        => (string) ($row['titolo'] ?? ''),
            'isbn10'       => self::nullable($row['isbn10'] ?? null),
            'isbn13'       => self::nullable($row['isbn13'] ?? null),
            'ean'          => self::nullable($row['ean'] ?? null),
            'copies_total' => (int) ($row['copie_totali'] ?? 0),
            'location'     => self::locationLabel($row),
        ];
    }

    /**
     * Same label format LibriController uses in its duplicate payload.
     *
     * @param array<string, mixed> $row
     */
    public static function locationLabel(array $row): ?string
    {
        if (!empty($row['shelf_code']) && !empty($row['level_number']) && !empty($row['posizione_progressiva'])) {
            return $row['shelf_code'] . '.' . $row['level_number'] . '.' . $row['posizione_progressiva'];
        }
        $label = trim((string) ($row['collocazione'] ?? ''));

        return $label !== '' ? $label : null;
    }

    /**
     * @return list<array{id:int, name:string, role:string}>
     */
    private function authors(int $bookId): array
    {
        return array_map(
            static fn (array $l): array => ['id' => $l['id'], 'name' => $l['name'], 'role' => $l['role']],
            $this->authorLinks($bookId)
        );
    }

    /**
     * @return list<array{id:int, name:string, role:string}>
     */
    private function authorLinks(int $bookId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.id, a.nome, a.pseudonimo, la.ruolo
               FROM libri_autori la
               JOIN autori a ON a.id = la.autore_id
              WHERE la.libro_id = ?
              ORDER BY CASE la.ruolo
                    WHEN 'principale' THEN 1 WHEN 'co-autore' THEN 2 WHEN 'traduttore' THEN 3
                    WHEN 'illustratore' THEN 4 WHEN 'curatore' THEN 5 WHEN 'colorista' THEN 6 ELSE 7 END,
                    la.ordine_credito, a.nome, a.id"
        );
        if ($stmt === false) {
            throw new \RuntimeException('staff authors read prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $out[] = [
                'id'   => (int) $r['id'],
                'name' => \App\Support\AuthorName::display($r),
                'role' => (string) ($r['ruolo'] ?? 'principale'),
            ];
        }
        $stmt->close();

        return $out;
    }

    /**
     * Ordered publishers, the primary first.
     *
     * @return list<array{id:int, name:string}>
     */
    private function publishers(int $bookId, int $primaryId): array
    {
        $out = [];
        if (\App\Support\SchemaInfo::hasLibriEditori($this->db)) {
            $stmt = $this->db->prepare(
                'SELECT e.id, e.nome FROM libri_editori le JOIN editori e ON e.id = le.editore_id
                  WHERE le.libro_id = ? ORDER BY le.ordine, e.id'
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $bookId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
                    $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['nome']];
                }
                $stmt->close();
            }
        }
        if ($out === [] && $primaryId > 0) {
            $stmt = $this->db->prepare('SELECT id, nome FROM editori WHERE id = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('i', $primaryId);
                $stmt->execute();
                $result = $stmt->get_result();
                $r = $result !== false ? $result->fetch_assoc() : null;
                $stmt->close();
                if (is_array($r)) {
                    $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['nome']];
                }
            }
        }
        if ($primaryId > 0 && $out !== [] && $out[0]['id'] !== $primaryId) {
            // libri.editore_id is authoritative for "primary": move it first.
            usort($out, static fn (array $a, array $b): int => ($b['id'] === $primaryId) <=> ($a['id'] === $primaryId));
        }

        return $out;
    }

    /**
     * @return list<array{id:int, inventory_number:string, status:string, notes:?string}>
     */
    private function copies(int $bookId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, numero_inventario, stato, note FROM copie WHERE libro_id = ? ORDER BY id'
        );
        if ($stmt === false) {
            throw new \RuntimeException('staff copies read prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $out[] = [
                'id'               => (int) $r['id'],
                'inventory_number' => (string) $r['numero_inventario'],
                'status'           => (string) ($r['stato'] ?? 'disponibile'),
                'notes'            => self::nullable($r['note'] ?? null),
            ];
        }
        $stmt->close();

        return $out;
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s !== '' ? $s : null;
    }
}
