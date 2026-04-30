<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class SeriesRepository
{
    /** @var array<string, bool> */
    private static array $tableCache = [];

    /** @var array<string, array<string, bool>> */
    private static array $columnCache = [];

    /** PERF-1 (review): cache the DB name once per request. The previous
     *  implementation issued `SELECT DATABASE()` on every cache lookup, so
     *  supportsHierarchy() (5 hasColumn calls) fired 5 round trips per
     *  invocation — multiplied across the book detail page (BookRepository::find,
     *  CollaneController::show, getBookMemberships) the cost was ~15 query
     *  round trips for cache hits alone. */
    private static ?string $cachedDbName = null;

    /** @var array<string, bool> Memoised supportsHierarchy()/supportsMemberships() */
    private static array $supportsCache = [];

    public function __construct(private mysqli $db)
    {
    }

    public function hasCollaneTable(): bool
    {
        return $this->tableExists('collane');
    }

    public function supportsHierarchy(): bool
    {
        $key = $this->currentDatabaseName() . ':hierarchy';
        if (!isset(self::$supportsCache[$key])) {
            self::$supportsCache[$key] = $this->hasColumn('collane', 'gruppo_serie')
                && $this->hasColumn('collane', 'ciclo')
                && $this->hasColumn('collane', 'ordine_ciclo')
                && $this->hasColumn('collane', 'parent_id')
                && $this->hasColumn('collane', 'tipo');
        }
        return self::$supportsCache[$key];
    }

    public function supportsMemberships(): bool
    {
        return $this->tableExists('libri_collane') && $this->hasCollaneTable();
    }

    public function ensureCollana(string $nome, array $metadata = [], bool $updateMetadata = true): ?int
    {
        $nome = $this->cleanName($nome);
        if ($nome === '' || !$this->hasCollaneTable()) {
            return null;
        }

        $stmt = $this->db->prepare('INSERT IGNORE INTO collane (nome) VALUES (?)');
        if ($stmt) {
            $stmt->bind_param('s', $nome);
            $stmt->execute();
            $stmt->close();
        }

        $id = $this->findCollanaId($nome);
        if ($id === null || !$updateMetadata || $metadata === []) {
            return $id;
        }

        $this->updateCollanaMetadata($id, $nome, $metadata);
        return $id;
    }

    public function findCollanaId(string $nome): ?int
    {
        if (!$this->hasCollaneTable()) {
            return null;
        }

        $nome = $this->cleanName($nome);
        if ($nome === '') {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id FROM collane WHERE nome = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $nome);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['id'] : null;
    }

    public function syncBookFromForm(int $bookId, array $fields, array $formData): void
    {
        $primaryName = $this->cleanName((string) ($fields['collana'] ?? ''));
        $metadataPosted = array_key_exists('gruppo_serie', $formData)
            || array_key_exists('ciclo_serie', $formData)
            || array_key_exists('ordine_ciclo', $formData)
            || array_key_exists('serie_padre', $formData)
            || array_key_exists('tipo_collana', $formData);

        $metadata = [];
        if ($metadataPosted) {
            // CRUD-1/2 (review): only set fields that the form actually posted.
            // Pre-fix every book save unconditionally rewrote tipo/gruppo/etc,
            // so two books in the same series with different gruppo values
            // fought each other (last-write-wins) and a missing tipo_collana
            // key in older forms silently downgraded `universo` to `serie`.
            if (array_key_exists('gruppo_serie', $formData)) {
                $metadata['gruppo_serie'] = $this->nullableString($formData['gruppo_serie']);
            }
            if (array_key_exists('ciclo_serie', $formData)) {
                $metadata['ciclo'] = $this->nullableString($formData['ciclo_serie']);
            }
            if (array_key_exists('ordine_ciclo', $formData)) {
                $metadata['ordine_ciclo'] = $this->nullableCycleOrder($formData['ordine_ciclo']);
            }
            if (array_key_exists('serie_padre', $formData)) {
                $metadata['parent_nome'] = $this->nullableString($formData['serie_padre']);
            }
            if (array_key_exists('tipo_collana', $formData)
                && trim((string) $formData['tipo_collana']) !== ''
            ) {
                $metadata['tipo'] = $this->normalizeType((string) $formData['tipo_collana']);
            }
        }

        $otherNames = $this->splitNames((string) ($formData['altre_collane'] ?? ''));

        // RACE-1 (review): the sync is delete-then-upsert; wrap in a tx so
        // mid-flight readers don't see the book attached to zero series.
        // Detect nested transaction via @@autocommit (project pattern from
        // recalculateBookAvailability) and only manage tx state when the
        // caller hasn't already opened one.
        $wasInTransaction = $this->isInTransaction();
        if (!$wasInTransaction) {
            $this->db->begin_transaction();
        }
        try {
            $this->syncBookMemberships(
                $bookId,
                $primaryName,
                $this->nullableString($fields['numero_serie'] ?? null),
                $otherNames,
                $metadata
            );
            if (!$wasInTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if (!$wasInTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Detect whether the caller already opened a transaction. The project
     * convention (CLAUDE.md / recalculateBookAvailability) is to inspect
     * @@autocommit: 0 means InnoDB started an implicit/explicit tx and any
     * begin_transaction() here would force a silent commit.
     */
    private function isInTransaction(): bool
    {
        $result = $this->db->query('SELECT @@autocommit AS ac');
        if ($result === false) {
            return false;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['ac']) && (int) $row['ac'] === 0;
    }

    /**
     * @param array<int, string> $otherNames
     * @param array<string, mixed> $primaryMetadata
     */
    public function syncBookMemberships(
        int $bookId,
        string $primaryName,
        ?string $numeroSerie,
        array $otherNames = [],
        array $primaryMetadata = []
    ): void {
        if ($bookId <= 0 || !$this->hasCollaneTable()) {
            return;
        }

        $primaryName = $this->cleanName($primaryName);
        $primaryId = null;
        if ($primaryName !== '') {
            $primaryId = $this->ensureCollana($primaryName, $primaryMetadata, $primaryMetadata !== []);
        }

        $memberships = [];
        if ($primaryId !== null) {
            $memberships[$primaryId] = [
                'numero_serie' => $numeroSerie,
                'tipo_appartenenza' => 'principale',
                'is_principale' => 1,
            ];
        }

        foreach ($otherNames as $name) {
            $name = $this->cleanName($name);
            if ($name === '' || $name === $primaryName) {
                continue;
            }
            $id = $this->ensureCollana($name, [], false);
            if ($id === null || isset($memberships[$id])) {
                continue;
            }
            $memberships[$id] = [
                'numero_serie' => null,
                'tipo_appartenenza' => 'secondaria',
                'is_principale' => 0,
            ];
        }

        if (!$this->supportsMemberships()) {
            return;
        }

        if ($memberships === []) {
            $stmt = $this->db->prepare('DELETE FROM libri_collane WHERE libro_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $bookId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        $ids = array_keys($memberships);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$bookId], $ids);
        $stmt = $this->db->prepare("DELETE FROM libri_collane WHERE libro_id = ? AND collana_id NOT IN ($placeholders)");
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }

        $stmtReset = $this->db->prepare('UPDATE libri_collane SET is_principale = 0, tipo_appartenenza = IF(tipo_appartenenza = "principale", "secondaria", tipo_appartenenza) WHERE libro_id = ?');
        if ($stmtReset) {
            $stmtReset->bind_param('i', $bookId);
            $stmtReset->execute();
            $stmtReset->close();
        }

        $stmtUpsert = $this->db->prepare("
            INSERT INTO libri_collane (libro_id, collana_id, numero_serie, tipo_appartenenza, is_principale)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                numero_serie = VALUES(numero_serie),
                tipo_appartenenza = VALUES(tipo_appartenenza),
                is_principale = VALUES(is_principale),
                updated_at = NOW()
        ");
        if (!$stmtUpsert) {
            return;
        }

        foreach ($memberships as $collanaId => $data) {
            $num = $data['numero_serie'];
            $kind = $data['tipo_appartenenza'];
            $primary = (int) $data['is_principale'];
            $stmtUpsert->bind_param('iissi', $bookId, $collanaId, $num, $kind, $primary);
            $stmtUpsert->execute();
        }
        $stmtUpsert->close();
    }

    public function assignPrimarySeries(int $bookId, string $collana, ?string $numeroSerie = null): void
    {
        $collana = $this->cleanName($collana);
        if ($bookId <= 0 || $collana === '') {
            return;
        }

        $stmt = $this->db->prepare('UPDATE libri SET collana = ?, numero_serie = COALESCE(?, numero_serie), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        if ($stmt) {
            $stmt->bind_param('ssi', $collana, $numeroSerie, $bookId);
            $stmt->execute();
            $stmt->close();
        }

        $collanaId = $this->ensureCollana($collana, [], false);
        if ($collanaId === null || !$this->supportsMemberships()) {
            return;
        }

        $stmtReset = $this->db->prepare('UPDATE libri_collane SET is_principale = 0, tipo_appartenenza = IF(tipo_appartenenza = "principale", "secondaria", tipo_appartenenza) WHERE libro_id = ?');
        if ($stmtReset) {
            $stmtReset->bind_param('i', $bookId);
            $stmtReset->execute();
            $stmtReset->close();
        }

        $kind = 'principale';
        $isPrimary = 1;
        $stmtUpsert = $this->db->prepare("
            INSERT INTO libri_collane (libro_id, collana_id, numero_serie, tipo_appartenenza, is_principale)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                numero_serie = VALUES(numero_serie),
                tipo_appartenenza = VALUES(tipo_appartenenza),
                is_principale = VALUES(is_principale),
                updated_at = NOW()
        ");
        if ($stmtUpsert) {
            $stmtUpsert->bind_param('iissi', $bookId, $collanaId, $numeroSerie, $kind, $isPrimary);
            $stmtUpsert->execute();
            $stmtUpsert->close();
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function listSeries(): array
    {
        if (!$this->hasCollaneTable()) {
            return $this->legacySeriesList();
        }

        $selectMeta = $this->supportsHierarchy()
            ? 'c.tipo, c.parent_id, p.nome AS parent_nome, c.gruppo_serie, c.ciclo, c.ordine_ciclo'
            : 'NULL AS tipo, NULL AS parent_id, NULL AS parent_nome, NULL AS gruppo_serie, NULL AS ciclo, NULL AS ordine_ciclo';

        if ($this->supportsMemberships()) {
            $sql = "
                SELECT c.nome AS collana,
                       {$selectMeta},
                       COUNT(DISTINCT m.libro_id) AS book_count,
                       MIN(CASE WHEN TRIM(m.numero_serie) REGEXP '^[0-9]+$' THEN CAST(m.numero_serie AS UNSIGNED) END) AS min_num,
                       MAX(CASE WHEN TRIM(m.numero_serie) REGEXP '^[0-9]+$' THEN CAST(m.numero_serie AS UNSIGNED) END) AS max_num
                FROM collane c
                " . ($this->supportsHierarchy() ? 'LEFT JOIN collane p ON p.id = c.parent_id' : '') . "
                LEFT JOIN (
                    SELECT lc.collana_id, lc.libro_id, lc.numero_serie
                      FROM libri_collane lc
                      JOIN libri l ON l.id = lc.libro_id AND l.deleted_at IS NULL
                    UNION ALL
                    SELECT c2.id AS collana_id, l.id AS libro_id, l.numero_serie
                      FROM libri l
                      JOIN collane c2 ON c2.nome = l.collana
                     WHERE l.collana IS NOT NULL AND l.collana != '' AND l.deleted_at IS NULL
                ) m ON m.collana_id = c.id
                GROUP BY c.id, c.nome" . ($this->supportsHierarchy() ? ', c.tipo, c.parent_id, p.nome, c.gruppo_serie, c.ciclo, c.ordine_ciclo' : '') . "
                ORDER BY " . $this->seriesOrderClause('c') . "
            ";
        } else {
            $sql = "
                SELECT c.nome AS collana,
                       {$selectMeta},
                       COUNT(l.id) AS book_count,
                       MIN(CASE WHEN TRIM(l.numero_serie) REGEXP '^[0-9]+$' THEN CAST(l.numero_serie AS UNSIGNED) END) AS min_num,
                       MAX(CASE WHEN TRIM(l.numero_serie) REGEXP '^[0-9]+$' THEN CAST(l.numero_serie AS UNSIGNED) END) AS max_num
                FROM collane c
                " . ($this->supportsHierarchy() ? 'LEFT JOIN collane p ON p.id = c.parent_id' : '') . "
                LEFT JOIN libri l ON l.collana = c.nome AND l.deleted_at IS NULL
                GROUP BY c.id, c.nome" . ($this->supportsHierarchy() ? ', c.tipo, c.parent_id, p.nome, c.gruppo_serie, c.ciclo, c.ordine_ciclo' : '') . "
                ORDER BY " . $this->seriesOrderClause('c') . "
            ";
        }

        return $this->fetchAll($sql);
    }

    public function getSeriesByName(string $name): ?array
    {
        if (!$this->hasCollaneTable()) {
            return null;
        }

        $name = $this->cleanName($name);
        $selectMeta = $this->supportsHierarchy()
            ? 'c.*, p.nome AS parent_nome'
            : 'c.*, NULL AS parent_nome, NULL AS parent_id, NULL AS tipo, NULL AS gruppo_serie, NULL AS ciclo, NULL AS ordine_ciclo';
        $join = $this->supportsHierarchy() ? 'LEFT JOIN collane p ON p.id = c.parent_id' : '';
        $stmt = $this->db->prepare("SELECT {$selectMeta} FROM collane c {$join} WHERE c.nome = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getRelatedSeries(string $name): array
    {
        $current = $this->getSeriesByName($name);
        if (!$current || !$this->supportsHierarchy()) {
            return [];
        }

        $conditions = ['c.nome <> ?'];
        $types = 's';
        $params = [$name];

        $group = trim((string) ($current['gruppo_serie'] ?? ''));
        if ($group !== '') {
            $conditions[] = 'c.gruppo_serie = ?';
            $types .= 's';
            $params[] = $group;
        }

        $currentId = (int) ($current['id'] ?? 0);
        $parentId = $current['parent_id'] !== null ? (int) $current['parent_id'] : null;
        if ($parentId !== null) {
            $conditions[] = 'c.parent_id = ?';
            $types .= 'i';
            $params[] = $parentId;
            $conditions[] = 'c.id = ?';
            $types .= 'i';
            $params[] = $parentId;
        }
        if ($currentId > 0) {
            $conditions[] = 'c.parent_id = ?';
            $types .= 'i';
            $params[] = $currentId;
        }

        if (count($conditions) === 1) {
            return [];
        }

        $where = array_shift($conditions) . ' AND (' . implode(' OR ', $conditions) . ')';
        if ($this->supportsMemberships()) {
            $bookCountExpr = 'COUNT(DISTINCT m.libro_id)';
            $bookJoin = "
              LEFT JOIN (
                    SELECT lc.collana_id, lc.libro_id
                      FROM libri_collane lc
                      JOIN libri l ON l.id = lc.libro_id AND l.deleted_at IS NULL
                    UNION ALL
                    SELECT c2.id, l.id
                      FROM libri l
                      JOIN collane c2 ON c2.nome = l.collana
                     WHERE l.collana IS NOT NULL AND l.collana != '' AND l.deleted_at IS NULL
              ) m ON m.collana_id = c.id";
        } else {
            $bookCountExpr = 'COUNT(l.id)';
            $bookJoin = 'LEFT JOIN libri l ON l.collana = c.nome AND l.deleted_at IS NULL';
        }

        $sql = "
            SELECT c.nome, c.tipo, c.gruppo_serie, c.ciclo, c.ordine_ciclo, p.nome AS parent_nome,
                   {$bookCountExpr} AS book_count
              FROM collane c
              LEFT JOIN collane p ON p.id = c.parent_id
              {$bookJoin}
             WHERE {$where}
             GROUP BY c.id, c.nome, c.tipo, c.gruppo_serie, c.ciclo, c.ordine_ciclo, p.nome
             ORDER BY " . $this->seriesOrderClause('c');
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function getBooksForSeries(string $name): array
    {
        $name = $this->cleanName($name);
        if ($name === '') {
            return [];
        }

        $collanaId = $this->findCollanaId($name);
        if ($collanaId !== null && $this->supportsMemberships()) {
            $stmt = $this->db->prepare("
                SELECT l.id, l.titolo,
                       COALESCE(
                           MAX(CASE WHEN m.is_principale = 1 THEN m.numero_serie END),
                           MAX(m.numero_serie),
                           l.numero_serie
                       ) AS numero_serie,
                       l.isbn13, l.isbn10, l.copertina_url,
                       (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
                  FROM (
                        SELECT lc.libro_id, lc.numero_serie, lc.is_principale
                          FROM libri_collane lc
                         WHERE lc.collana_id = ?
                        UNION ALL
                        SELECT l.id AS libro_id, l.numero_serie, 1 AS is_principale
                          FROM libri l
                         WHERE l.collana = ? AND l.deleted_at IS NULL
                  ) m
                  JOIN libri l ON l.id = m.libro_id AND l.deleted_at IS NULL
                 GROUP BY l.id, l.titolo, l.numero_serie, l.isbn13, l.isbn10, l.copertina_url
                 ORDER BY
                    CASE WHEN TRIM(COALESCE(MAX(CASE WHEN m.is_principale = 1 THEN m.numero_serie END), MAX(m.numero_serie), l.numero_serie)) REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
                    CAST(COALESCE(MAX(CASE WHEN m.is_principale = 1 THEN m.numero_serie END), MAX(m.numero_serie), l.numero_serie) AS UNSIGNED),
                    l.titolo
            ");
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('is', $collanaId, $name);
        } else {
            $stmt = $this->db->prepare("
                SELECT l.id, l.titolo, l.numero_serie, l.isbn13, l.isbn10, l.copertina_url,
                       (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
                  FROM libri l
                 WHERE l.collana = ? AND l.deleted_at IS NULL
                 ORDER BY
                    CASE WHEN TRIM(l.numero_serie) REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
                    CAST(l.numero_serie AS UNSIGNED),
                    l.titolo
            ");
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('s', $name);
        }

        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function getBookMemberships(int $bookId): array
    {
        if ($bookId <= 0 || !$this->supportsMemberships()) {
            return [];
        }

        $selectMeta = $this->supportsHierarchy()
            ? 'c.tipo, c.gruppo_serie, c.ciclo, c.ordine_ciclo, p.nome AS parent_nome'
            : 'NULL AS tipo, NULL AS gruppo_serie, NULL AS ciclo, NULL AS ordine_ciclo, NULL AS parent_nome';
        $join = $this->supportsHierarchy() ? 'LEFT JOIN collane p ON p.id = c.parent_id' : '';
        $stmt = $this->db->prepare("
            SELECT c.id AS collana_id, c.nome, lc.numero_serie, lc.tipo_appartenenza, lc.is_principale,
                   {$selectMeta}
              FROM libri_collane lc
              JOIN collane c ON c.id = lc.collana_id
              {$join}
             WHERE lc.libro_id = ?
             ORDER BY lc.is_principale DESC,
                      CASE WHEN TRIM(lc.numero_serie) REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
                      CAST(lc.numero_serie AS UNSIGNED),
                      c.nome
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $rows = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    public function getOtherSeriesText(int $bookId, ?string $primaryName = null): string
    {
        $primaryName = $this->cleanName((string) $primaryName);
        $names = [];
        foreach ($this->getBookMemberships($bookId) as $membership) {
            $name = $this->cleanName((string) ($membership['nome'] ?? ''));
            if ($name === '' || (int) ($membership['is_principale'] ?? 0) === 1 || $name === $primaryName) {
                continue;
            }
            $names[] = $name;
        }

        return implode("\n", array_values(array_unique($names)));
    }

    public function updatePrimaryOrder(int $bookId, ?string $numeroSerie): bool
    {
        if ($bookId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE libri SET numero_serie = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $numeroSerie, $bookId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && $this->supportsMemberships()) {
            $stmtLc = $this->db->prepare('UPDATE libri_collane SET numero_serie = ?, updated_at = NOW() WHERE libro_id = ? AND is_principale = 1');
            if ($stmtLc) {
                $stmtLc->bind_param('si', $numeroSerie, $bookId);
                $stmtLc->execute();
                $stmtLc->close();
            }
        }

        return $ok;
    }

    public function removeBookFromSeries(int $bookId, string $seriesName): int
    {
        $seriesName = $this->cleanName($seriesName);
        if ($bookId <= 0 || $seriesName === '') {
            return 0;
        }

        $affected = 0;
        $collanaId = $this->findCollanaId($seriesName);
        if ($collanaId !== null && $this->supportsMemberships()) {
            $stmt = $this->db->prepare('DELETE FROM libri_collane WHERE libro_id = ? AND collana_id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $bookId, $collanaId);
                $stmt->execute();
                $affected += max(0, $stmt->affected_rows);
                $stmt->close();
            }
        }

        $stmtLegacy = $this->db->prepare('SELECT collana FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if ($stmtLegacy) {
            $stmtLegacy->bind_param('i', $bookId);
            $stmtLegacy->execute();
            $row = $stmtLegacy->get_result()->fetch_assoc();
            $stmtLegacy->close();
            if ($row && $this->cleanName((string) ($row['collana'] ?? '')) === $seriesName) {
                $this->promoteLegacySeries($bookId);
                $affected++;
            }
        }

        return $affected;
    }

    public function deleteSeries(string $seriesName): int
    {
        $seriesName = $this->cleanName($seriesName);
        if ($seriesName === '') {
            return 0;
        }

        $bookIds = [];
        $collanaId = $this->findCollanaId($seriesName);
        if ($collanaId !== null) {
            // RACE-3 (review): lock the row so concurrent INSERTs to libri_collane
            // can't race the DELETE. Caller (CollaneController::delete) wraps this
            // in a transaction; the FOR UPDATE serialises against any concurrent
            // writer touching the same collana row.
            $stmtLock = $this->db->prepare('SELECT id FROM collane WHERE id = ? FOR UPDATE');
            if ($stmtLock) {
                $stmtLock->bind_param('i', $collanaId);
                $stmtLock->execute();
                $stmtLock->get_result()->fetch_assoc();
                $stmtLock->close();
            }
        }
        if ($collanaId !== null && $this->supportsMemberships()) {
            $stmtIds = $this->db->prepare('SELECT DISTINCT libro_id FROM libri_collane WHERE collana_id = ?');
            if ($stmtIds) {
                $stmtIds->bind_param('i', $collanaId);
                $stmtIds->execute();
                $res = $stmtIds->get_result();
                while ($row = $res->fetch_assoc()) {
                    $bookIds[(int) $row['libro_id']] = true;
                }
                $stmtIds->close();
            }
            $stmtDel = $this->db->prepare('DELETE FROM libri_collane WHERE collana_id = ?');
            if ($stmtDel) {
                $stmtDel->bind_param('i', $collanaId);
                $stmtDel->execute();
                $stmtDel->close();
            }
        }

        $stmtLegacyIds = $this->db->prepare('SELECT id FROM libri WHERE collana = ? AND deleted_at IS NULL');
        if ($stmtLegacyIds) {
            $stmtLegacyIds->bind_param('s', $seriesName);
            $stmtLegacyIds->execute();
            $res = $stmtLegacyIds->get_result();
            while ($row = $res->fetch_assoc()) {
                $bookIds[(int) $row['id']] = true;
            }
            $stmtLegacyIds->close();
        }

        foreach (array_keys($bookIds) as $bookId) {
            $this->promoteLegacySeries((int) $bookId);
        }

        // DATA-2 (review): clear the legacy varchar so books matched only via
        // libri.collana don't keep pointing to a deleted series name.
        $stmtClearLegacy = $this->db->prepare('UPDATE libri SET collana = NULL, numero_serie = NULL WHERE collana = ? AND deleted_at IS NULL');
        if ($stmtClearLegacy) {
            $stmtClearLegacy->bind_param('s', $seriesName);
            $stmtClearLegacy->execute();
            $stmtClearLegacy->close();
        }

        // DATA-1 (review): re-parent grandchildren up one level so they don't
        // become orphans when a mid-tier series is deleted. The FK on
        // collane.parent_id is ON DELETE SET NULL, but it only nulls direct
        // children — grandchildren keep dangling references.
        if ($collanaId !== null && $this->hasColumn('collane', 'parent_id')) {
            $parentOfDeleted = null;
            $stmtParent = $this->db->prepare('SELECT parent_id FROM collane WHERE id = ?');
            if ($stmtParent) {
                $stmtParent->bind_param('i', $collanaId);
                $stmtParent->execute();
                $parentRow = $stmtParent->get_result()->fetch_assoc();
                $parentOfDeleted = $parentRow['parent_id'] ?? null;
                $stmtParent->close();
            }
            $stmtReparent = $this->db->prepare('UPDATE collane SET parent_id = ? WHERE parent_id = ?');
            if ($stmtReparent) {
                $stmtReparent->bind_param('ii', $parentOfDeleted, $collanaId);
                $stmtReparent->execute();
                $stmtReparent->close();
            }
        }

        if ($this->hasCollaneTable()) {
            $stmtDelete = $this->db->prepare('DELETE FROM collane WHERE nome = ?');
            if ($stmtDelete) {
                $stmtDelete->bind_param('s', $seriesName);
                $stmtDelete->execute();
                $stmtDelete->close();
            }
        }

        return count($bookIds);
    }

    public function renameSeries(string $oldName, string $newName): int
    {
        $oldName = $this->cleanName($oldName);
        $newName = $this->cleanName($newName);
        if ($oldName === '' || $newName === '' || $oldName === $newName) {
            return 0;
        }

        $affected = 0;
        if ($this->findCollanaId($newName) !== null) {
            return $this->mergeSeries($oldName, $newName);
        }

        $stmtBooks = $this->db->prepare('UPDATE libri SET collana = ?, updated_at = NOW() WHERE collana = ? AND deleted_at IS NULL');
        if ($stmtBooks) {
            $stmtBooks->bind_param('ss', $newName, $oldName);
            $stmtBooks->execute();
            $affected = max(0, $stmtBooks->affected_rows);
            $stmtBooks->close();
        }

        if ($this->hasCollaneTable()) {
            $stmt = $this->db->prepare('UPDATE collane SET nome = ? WHERE nome = ?');
            if ($stmt) {
                $stmt->bind_param('ss', $newName, $oldName);
                $stmt->execute();
                $stmt->close();
            }
        }

        // CRUD-5 (review): backfill libri_collane for books that matched only
        // via the legacy `libri.collana` varchar before the migration. Without
        // this, getBookMemberships returns nothing for those books and the
        // edit form's "altre_collane" chips silently lose them.
        // (We inline the lookup so PHPStan doesn't infer null from the early
        // return above — the UPDATE collane SET nome = newName moved a row
        // that did not exist under newName before, so the id IS findable now.)
        $newCollanaId = null;
        $stmtFind = $this->db->prepare('SELECT id FROM collane WHERE nome = ? LIMIT 1');
        if ($stmtFind) {
            $stmtFind->bind_param('s', $newName);
            $stmtFind->execute();
            $row = $stmtFind->get_result()->fetch_assoc();
            $newCollanaId = $row ? (int) $row['id'] : null;
            $stmtFind->close();
        }
        if ($newCollanaId !== null && $this->supportsMemberships()) {
            $stmtBackfill = $this->db->prepare(
                'INSERT IGNORE INTO libri_collane (libro_id, collana_id, numero_serie, tipo_appartenenza, is_principale)
                 SELECT l.id, ?, l.numero_serie, "principale", 1
                   FROM libri l
                  WHERE l.collana = ?
                    AND l.deleted_at IS NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM libri_collane lc WHERE lc.libro_id = l.id AND lc.collana_id = ?
                    )'
            );
            if ($stmtBackfill) {
                $stmtBackfill->bind_param('isi', $newCollanaId, $newName, $newCollanaId);
                $stmtBackfill->execute();
                $stmtBackfill->close();
            }
        }

        return $affected;
    }

    public function mergeSeries(string $sourceName, string $targetName): int
    {
        $sourceName = $this->cleanName($sourceName);
        $targetName = $this->cleanName($targetName);
        if ($sourceName === '' || $targetName === '' || $sourceName === $targetName) {
            return 0;
        }

        $targetId = $this->ensureCollana($targetName, [], false);
        $sourceId = $this->findCollanaId($sourceName);

        // RACE-2 (review): lock source + target rows so concurrent
        // assignPrimarySeries / syncBookMemberships can't write to source
        // between our snapshot and the final DELETE. Caller (CollaneController::merge)
        // wraps this in a transaction so the FOR UPDATE is meaningful.
        if ($sourceId !== null && $targetId !== null) {
            $stmtLock = $this->db->prepare('SELECT id FROM collane WHERE id IN (?, ?) FOR UPDATE');
            if ($stmtLock) {
                $stmtLock->bind_param('ii', $sourceId, $targetId);
                $stmtLock->execute();
                $stmtLock->get_result();
                $stmtLock->close();
            }
        }

        $stmtBooks = $this->db->prepare('UPDATE libri SET collana = ?, updated_at = NOW() WHERE collana = ? AND deleted_at IS NULL');
        $affected = 0;
        if ($stmtBooks) {
            $stmtBooks->bind_param('ss', $targetName, $sourceName);
            $stmtBooks->execute();
            $affected = max(0, $stmtBooks->affected_rows);
            $stmtBooks->close();
        }

        if ($sourceId !== null && $targetId !== null && $this->supportsMemberships()) {
            $bookIds = [];
            $stmtSourceBooks = $this->db->prepare('SELECT libro_id FROM libri_collane WHERE collana_id = ?');
            if ($stmtSourceBooks) {
                $stmtSourceBooks->bind_param('i', $sourceId);
                $stmtSourceBooks->execute();
                $res = $stmtSourceBooks->get_result();
                while ($row = $res->fetch_assoc()) {
                    $bookIds[(int) $row['libro_id']] = true;
                }
                $stmtSourceBooks->close();
            }

            $stmtMove = $this->db->prepare('UPDATE IGNORE libri_collane SET collana_id = ?, updated_at = NOW() WHERE collana_id = ?');
            if ($stmtMove) {
                $stmtMove->bind_param('ii', $targetId, $sourceId);
                $stmtMove->execute();
                $affected += max(0, $stmtMove->affected_rows);
                $stmtMove->close();
            }
            $stmtDeleteDupes = $this->db->prepare('DELETE FROM libri_collane WHERE collana_id = ?');
            if ($stmtDeleteDupes) {
                $stmtDeleteDupes->bind_param('i', $sourceId);
                $stmtDeleteDupes->execute();
                $stmtDeleteDupes->close();
            }

            foreach (array_keys($bookIds) as $bookId) {
                $this->markSeriesAsPrimary((int) $bookId, $targetId);
            }
        }

        if ($this->hasCollaneTable()) {
            $stmtDelete = $this->db->prepare('DELETE FROM collane WHERE nome = ?');
            if ($stmtDelete) {
                $stmtDelete->bind_param('s', $sourceName);
                $stmtDelete->execute();
                $stmtDelete->close();
            }
        }

        return $affected;
    }

    /** @return array<int, string> */
    public function splitNames(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $name = $this->cleanName((string) $part);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = str_replace([' ', '-', '/'], '_', $type);
        $map = [
            'series' => 'serie',
            'serie' => 'serie',
            'universe' => 'universo',
            'universo' => 'universo',
            'macroserie' => 'universo',
            'cycle' => 'ciclo',
            'ciclo' => 'ciclo',
            'season' => 'stagione',
            'stagione' => 'stagione',
            'spin_off' => 'spin_off',
            'spinoff' => 'spin_off',
            'arc' => 'arco',
            'arco' => 'arco',
            'publisher_collection' => 'collezione_editoriale',
            'collana_editoriale' => 'collezione_editoriale',
            'collezione_editoriale' => 'collezione_editoriale',
            'other' => 'altro',
            'altro' => 'altro',
        ];

        return $map[$type] ?? 'serie';
    }

    private function updateCollanaMetadata(int $id, string $nome, array $metadata): void
    {
        $sets = [];
        $types = '';
        $params = [];

        if ($this->hasColumn('collane', 'gruppo_serie') && array_key_exists('gruppo_serie', $metadata)) {
            $sets[] = 'gruppo_serie = ?';
            $types .= 's';
            $params[] = $metadata['gruppo_serie'];
        }
        if ($this->hasColumn('collane', 'ciclo') && array_key_exists('ciclo', $metadata)) {
            $sets[] = 'ciclo = ?';
            $types .= 's';
            $params[] = $metadata['ciclo'];
        }
        if ($this->hasColumn('collane', 'ordine_ciclo') && array_key_exists('ordine_ciclo', $metadata)) {
            $sets[] = 'ordine_ciclo = ?';
            $types .= 'i';
            $params[] = $metadata['ordine_ciclo'];
        }
        if ($this->hasColumn('collane', 'tipo') && array_key_exists('tipo', $metadata)) {
            $sets[] = 'tipo = ?';
            $types .= 's';
            $params[] = $this->normalizeType((string) $metadata['tipo']);
        }
        if ($this->hasColumn('collane', 'parent_id') && array_key_exists('parent_nome', $metadata)) {
            $parentId = null;
            $parentName = $this->cleanName((string) ($metadata['parent_nome'] ?? ''));
            if ($parentName !== '' && $parentName !== $nome) {
                $existingParentId = $this->findCollanaId($parentName);
                $parentId = $this->ensureCollana($parentName, ['tipo' => 'universo'], $existingParentId === null);
                if ($parentId !== null && $this->wouldCreateParentCycle($id, $parentId)) {
                    $parentId = null;
                }
            }
            $sets[] = 'parent_id = ?';
            $types .= 'i';
            $params[] = $parentId;
        }
        if ($this->hasColumn('collane', 'descrizione') && array_key_exists('descrizione', $metadata)) {
            $sets[] = 'descrizione = ?';
            $types .= 's';
            $params[] = $metadata['descrizione'];
        }

        if ($sets === []) {
            return;
        }

        $params[] = $id;
        $types .= 'i';
        $stmt = $this->db->prepare('UPDATE collane SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function promoteLegacySeries(int $bookId): void
    {
        if ($this->supportsMemberships()) {
            $stmt = $this->db->prepare("
                SELECT c.id, c.nome, lc.numero_serie
                  FROM libri_collane lc
                  JOIN collane c ON c.id = lc.collana_id
                 WHERE lc.libro_id = ?
                 ORDER BY lc.is_principale DESC, c.nome
                 LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $bookId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $collanaId = (int) $row['id'];
                    $collana = (string) $row['nome'];
                    $numero = $row['numero_serie'] !== null ? (string) $row['numero_serie'] : null;
                    $stmtPromote = $this->db->prepare('UPDATE libri_collane SET is_principale = CASE WHEN collana_id = ? THEN 1 ELSE 0 END, tipo_appartenenza = CASE WHEN collana_id = ? THEN "principale" ELSE "secondaria" END WHERE libro_id = ?');
                    if ($stmtPromote) {
                        $stmtPromote->bind_param('iii', $collanaId, $collanaId, $bookId);
                        $stmtPromote->execute();
                        $stmtPromote->close();
                    }
                    $stmtBook = $this->db->prepare('UPDATE libri SET collana = ?, numero_serie = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
                    if ($stmtBook) {
                        $stmtBook->bind_param('ssi', $collana, $numero, $bookId);
                        $stmtBook->execute();
                        $stmtBook->close();
                    }
                    return;
                }
            }
        }

        $stmtClear = $this->db->prepare('UPDATE libri SET collana = NULL, numero_serie = NULL, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        if ($stmtClear) {
            $stmtClear->bind_param('i', $bookId);
            $stmtClear->execute();
            $stmtClear->close();
        }
    }

    private function markSeriesAsPrimary(int $bookId, int $collanaId): void
    {
        if ($bookId <= 0 || $collanaId <= 0 || !$this->supportsMemberships()) {
            return;
        }

        // CRUD-6 (review): only promote when the book has NO other principal
        // membership. The previous unconditional CASE statement demoted the
        // principal of a third unrelated series whenever a merge ran.
        $stmtCheck = $this->db->prepare('SELECT 1 FROM libri_collane WHERE libro_id = ? AND collana_id <> ? AND is_principale = 1 LIMIT 1');
        $hasOtherPrincipal = false;
        if ($stmtCheck) {
            $stmtCheck->bind_param('ii', $bookId, $collanaId);
            $stmtCheck->execute();
            $hasOtherPrincipal = (bool) $stmtCheck->get_result()->fetch_assoc();
            $stmtCheck->close();
        }
        if ($hasOtherPrincipal) {
            // Just ensure the target row is recorded as 'secondaria' — do not
            // touch the existing principal.
            $stmt = $this->db->prepare('UPDATE libri_collane SET tipo_appartenenza = IF(tipo_appartenenza = "principale", "secondaria", tipo_appartenenza), is_principale = 0 WHERE libro_id = ? AND collana_id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $bookId, $collanaId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        $stmt = $this->db->prepare('UPDATE libri_collane SET is_principale = CASE WHEN collana_id = ? THEN 1 ELSE 0 END, tipo_appartenenza = CASE WHEN collana_id = ? THEN "principale" ELSE "secondaria" END WHERE libro_id = ?');
        if ($stmt) {
            $stmt->bind_param('iii', $collanaId, $collanaId, $bookId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function tableExists(string $table): bool
    {
        $allowed = ['collane', 'libri_collane'];
        if (!in_array($table, $allowed, true)) {
            return false;
        }
        $dbName = $this->currentDatabaseName();
        $key = $dbName . '.' . $table;
        if (!array_key_exists($key, self::$tableCache)) {
            $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            if (!$stmt) {
                self::$tableCache[$key] = false;
            } else {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                self::$tableCache[$key] = (int) ($row['cnt'] ?? 0) > 0;
            }
        }

        return self::$tableCache[$key];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $allowedTables = ['collane'];
        if (!in_array($table, $allowedTables, true)) {
            return false;
        }

        $dbName = $this->currentDatabaseName();
        $key = $dbName . '.' . $table;
        if (!isset(self::$columnCache[$key])) {
            self::$columnCache[$key] = [];
            $stmt = $this->db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            if ($stmt) {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    self::$columnCache[$key][(string) $row['COLUMN_NAME']] = true;
                }
                $stmt->close();
            }
        }

        return isset(self::$columnCache[$key][$column]);
    }

    private function currentDatabaseName(): string
    {
        if (self::$cachedDbName !== null) {
            return self::$cachedDbName;
        }
        $res = $this->db->query('SELECT DATABASE()');
        $name = $res ? (string) ($res->fetch_row()[0] ?? 'default') : 'default';
        if ($res) {
            $res->free();
        }
        self::$cachedDbName = $name;
        return $name;
    }

    private function wouldCreateParentCycle(int $childId, int $parentId): bool
    {
        if ($childId <= 0 || $parentId <= 0) {
            return false;
        }
        if ($childId === $parentId) {
            return true;
        }

        $seen = [];
        $cursor = $parentId;
        while ($cursor > 0 && !isset($seen[$cursor])) {
            if ($cursor === $childId) {
                return true;
            }
            $seen[$cursor] = true;
            $stmt = $this->db->prepare('SELECT parent_id FROM collane WHERE id = ? LIMIT 1');
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('i', $cursor);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $cursor = $row && $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableCycleOrder(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        return $validated === false ? null : (int) $validated;
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /** @return array<int, array<string, mixed>> */
    private function legacySeriesList(): array
    {
        $sql = "
            SELECT collana, NULL AS tipo, NULL AS parent_id, NULL AS parent_nome,
                   NULL AS gruppo_serie, NULL AS ciclo, NULL AS ordine_ciclo,
                   COUNT(*) AS book_count,
                   MIN(CASE WHEN TRIM(numero_serie) REGEXP '^[0-9]+$' THEN CAST(numero_serie AS UNSIGNED) END) AS min_num,
                   MAX(CASE WHEN TRIM(numero_serie) REGEXP '^[0-9]+$' THEN CAST(numero_serie AS UNSIGNED) END) AS max_num
              FROM libri
             WHERE collana IS NOT NULL AND collana != '' AND deleted_at IS NULL
             GROUP BY collana
             ORDER BY collana ASC
        ";

        return $this->fetchAll($sql);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAll(string $sql): array
    {
        $rows = [];
        $result = $this->db->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }

        return $rows;
    }

    private function seriesOrderClause(string $alias): string
    {
        if (!$this->supportsHierarchy()) {
            return "{$alias}.nome ASC";
        }

        return "COALESCE(NULLIF({$alias}.gruppo_serie, ''), parent_nome, {$alias}.nome) ASC,
                CASE WHEN {$alias}.parent_id IS NULL THEN 0 ELSE 1 END,
                CASE WHEN {$alias}.ordine_ciclo IS NULL THEN 1 ELSE 0 END,
                {$alias}.ordine_ciclo ASC,
                {$alias}.nome ASC";
    }
}
