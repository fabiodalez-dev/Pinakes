<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class AuthorRepository
{
    public function __construct(private mysqli $db) {}

    /** @var array<string, array<string, bool>> column presence cache, keyed by DB name */
    private static array $columnCacheByDb = [];

    /**
     * Whether the `autori` table has the given column. Used to stay backward
     * compatible with installs whose DB has not yet applied a recent migration
     * (issue #163: foto, collegamenti). Cached per database name.
     */
    private function hasColumn(string $name): bool
    {
        $dbRes = $this->db->query('SELECT DATABASE()');
        $dbName = ($dbRes ? (string) ($dbRes->fetch_row()[0] ?? 'default') : 'default');
        if (!isset(self::$columnCacheByDb[$dbName])) {
            self::$columnCacheByDb[$dbName] = [];
            $res = $this->db->query('SHOW COLUMNS FROM autori');
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    self::$columnCacheByDb[$dbName][(string) $r['Field']] = true;
                }
            }
        }
        return isset(self::$columnCacheByDb[$dbName][$name]);
    }

    public function listBasic(int $limit = 100): array
    {
        $rows = [];
        $sql = "SELECT id, nome, COALESCE(pseudonimo,'') AS pseudonimo FROM autori ORDER BY nome LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM autori WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $author = $res->fetch_assoc() ?: null;

        if ($author) {
            // Plugin hook: Extend author data
            $author = \App\Support\Hooks::apply('author.data.get', $author, [$id]);
        }

        return $author;
    }

    public function getByPublisherId(int $publisherId): array
    {
        $hasJunction = \App\Support\SchemaInfo::hasLibriEditori($this->db);
        $exists = $hasJunction
            ? " OR EXISTS (SELECT 1 FROM libri_editori le WHERE le.libro_id = l.id AND le.editore_id = ?)"
            : "";
        $sql = "SELECT DISTINCT a.id, a.nome, a.pseudonimo
                FROM autori a
                INNER JOIN libri_autori la ON a.id = la.autore_id
                INNER JOIN libri l ON la.libro_id = l.id
                WHERE (l.editore_id = ?{$exists})
                      AND l.deleted_at IS NULL
                      AND la.ruolo IN ('principale', 'co-autore')
                ORDER BY a.nome ASC";
        $stmt = $this->db->prepare($sql);
        if ($hasJunction) {
            $stmt->bind_param('ii', $publisherId, $publisherId);
        } else {
            $stmt->bind_param('i', $publisherId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getBooksByAuthorId(int $authorId): array
    {
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.ean, l.data_acquisizione, l.stato, l.copertina_url,
                       e.nome AS editore_nome,
                       (
                         SELECT GROUP_CONCAT(" . \App\Support\AuthorName::displaySql('a') . " SEPARATOR ', ')
                         FROM libri_autori la
                         JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore')
                       ) AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                INNER JOIN libri_autori la ON l.id = la.libro_id
                WHERE la.autore_id = ? AND l.deleted_at IS NULL
                GROUP BY l.id
                ORDER BY l.titolo ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function countBooks(int $authorId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT la.libro_id) FROM libri_autori la JOIN libri l ON la.libro_id = l.id AND l.deleted_at IS NULL WHERE la.autore_id = ?');
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        return (int)$count;
    }

    public function create(array $data): int
    {
        // Handle empty dates by converting them to NULL
        $data_nascita = empty($data['data_nascita']) ? null : $data['data_nascita'];
        $data_morte = empty($data['data_morte']) ? null : $data['data_morte'];

        // Decode HTML entities to prevent double encoding
        $nome = \App\Support\HtmlHelper::decode($data['nome']);
        $pseudonimo = \App\Support\HtmlHelper::decode($data['pseudonimo'] ?? '');
        $nazionalita = \App\Support\HtmlHelper::decode($data['nazionalita'] ?? '');
        $biografia = \App\Support\HtmlHelper::decode($data['biografia'] ?? '');
        $sito_web = \App\Support\HtmlHelper::decode($data['sito_web'] ?? '');

        // Normalize author name to canonical format ("Name Surname")
        // This prevents duplicates from different sources (SBN: "Levi, Primo" vs Google: "Primo Levi")
        $nome = \App\Support\AuthorNormalizer::normalize($nome);

        // Base columns always present.
        $columns = ['nome', 'pseudonimo', 'data_nascita', 'data_morte', '`nazionalità`', 'biografia', 'sito_web'];
        $types = 'sssssss';
        $values = [$nome, $pseudonimo, $data_nascita, $data_morte, $nazionalita, $biografia, $sito_web];

        // issue #163 columns — guarded with hasColumn() for backward compat with
        // installs whose DB has not yet applied the 0.7.20 migration.
        // foto = stored path or URL; collegamenti = pre-encoded JSON string. Both
        // nullable — empty becomes NULL so the column reads cleanly.
        if ($this->hasColumn('foto')) {
            $columns[] = 'foto';
            $types .= 's';
            $values[] = (($data['foto'] ?? '') !== '') ? (string) $data['foto'] : null;
        }
        if ($this->hasColumn('collegamenti')) {
            $columns[] = 'collegamenti';
            $types .= 's';
            $values[] = (($data['collegamenti'] ?? '') !== '') ? (string) $data['collegamenti'] : null;
        }

        if (array_key_exists('gnd_id', $data)) {
            $gnd = \App\Support\GndIdentifier::normalize($data['gnd_id']);
            if ($this->hasColumn('gnd_id')) {
                $columns[] = 'gnd_id'; $types .= 's'; $values[] = $gnd;
            } elseif ($gnd !== null) { throw new \RuntimeException('GND schema unavailable'); }
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO autori (' . implode(', ', $columns) . ', created_at, updated_at)'
             . ' VALUES (' . $placeholders . ', NOW(), NOW())';
        $stmt = $this->db->prepare($sql);

        $refs = [$types];
        foreach ($values as $k => $v) {
            $refs[] = &$values[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        // Without MYSQLI_REPORT_STRICT a failed INSERT returns false and leaves
        // insert_id at 0 or at a previous row: never hand either back as a new author.
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Author creation failed: ' . $error);
        }
        $newId = (int)$this->db->insert_id;
        $stmt->close();
        \App\Support\ContentCache::deferBooksChanged();
        return $newId;
    }

    public function update(int $id, array $data): bool
    {
        // Plugin hook: Before author save
        \App\Support\Hooks::do('author.save.before', [$data, $id]);

        // Handle empty dates by converting them to NULL
        $data_nascita = empty($data['data_nascita']) ? null : $data['data_nascita'];
        $data_morte = empty($data['data_morte']) ? null : $data['data_morte'];

        // Decode HTML entities to prevent double encoding
        $nome = \App\Support\HtmlHelper::decode($data['nome']);
        $pseudonimo = \App\Support\HtmlHelper::decode($data['pseudonimo'] ?? '');
        $nazionalita = \App\Support\HtmlHelper::decode($data['nazionalita'] ?? '');
        $biografia = \App\Support\HtmlHelper::decode($data['biografia'] ?? '');
        $sito_web = \App\Support\HtmlHelper::decode($data['sito_web'] ?? '');

        // Normalize author name to canonical format ("Name Surname")
        // This ensures consistency when updating existing authors
        $nome = \App\Support\AuthorNormalizer::normalize($nome);

        // Base columns always present.
        $assignments = ['nome=?', 'pseudonimo=?', 'data_nascita=?', 'data_morte=?', '`nazionalità`=?', 'biografia=?', 'sito_web=?'];
        $types = 'sssssss';
        $values = [$nome, $pseudonimo, $data_nascita, $data_morte, $nazionalita, $biografia, $sito_web];

        // issue #163 columns — guarded with hasColumn() for backward compat with
        // installs whose DB has not yet applied the 0.7.20 migration.
        if ($this->hasColumn('foto')) {
            $assignments[] = 'foto=?';
            $types .= 's';
            $values[] = (($data['foto'] ?? '') !== '') ? (string) $data['foto'] : null;
        }
        if ($this->hasColumn('collegamenti')) {
            $assignments[] = 'collegamenti=?';
            $types .= 's';
            $values[] = (($data['collegamenti'] ?? '') !== '') ? (string) $data['collegamenti'] : null;
        }

        if (array_key_exists('gnd_id', $data)) {
            $gnd = \App\Support\GndIdentifier::normalize($data['gnd_id']);
            if ($this->hasColumn('gnd_id')) {
                $assignments[] = 'gnd_id=?'; $types .= 's'; $values[] = $gnd;
            } elseif ($gnd !== null) { throw new \RuntimeException('GND schema unavailable'); }
        }

        $types .= 'i';
        $values[] = $id;

        $sql = 'UPDATE autori SET ' . implode(', ', $assignments) . ', updated_at=NOW() WHERE id=?';
        $stmt = $this->db->prepare($sql);

        $refs = [$types];
        foreach ($values as $k => $v) {
            $refs[] = &$values[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $result = $stmt->execute();

        // The author name is embedded in every linked book's denormalized
        // search_index — rebuild them so the change is searchable. The links are
        // unchanged by an edit, so querying the affected set here is safe.
        if ($result) {
            \App\Support\SearchIndexBuilder::rebuildForAuthor($this->db, $id);
            \App\Support\ContentCache::deferBooksChanged();
        }

        // Plugin hook: After author save
        \App\Support\Hooks::do('author.save.after', [$id, $data]);

        return $result;
    }

    /**
     * Backward-compatible alias for canonical-name lookup.
     *
     * IMPORTANT: this method intentionally never searches `pseudonimo`.
     * Importers and authority providers (SBN, VIAF, Open Library, CSV,
     * LibraryThing, ISBN scrapers) supply canonical/real names. Matching those
     * values against a pseudonym could attach metadata to the wrong person.
     */
    public function findByName(string $name): ?int
    {
        return $this->findByCanonicalName($name);
    }

    /**
     * Find an author by canonical/real name, with normalization to prevent duplicates.
     *
     * Searches for author using both exact match and normalized variants
     * to handle different name formats (e.g., "Levi, Primo" vs "Primo Levi")
     *
     * @param string $name Author name in any format
     * @return int|null Author ID if found, null otherwise
     */
    public function findByCanonicalName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // Normalize the input name first (this is the key fix!)
        $normalizedInput = \App\Support\AuthorNormalizer::normalize($name);

        // First try exact match with normalized name
        $stmt = $this->db->prepare("SELECT id FROM autori WHERE nome = ? LIMIT 1");
        $stmt->bind_param('s', $normalizedInput);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        if ($row) {
            return (int)$row['id'];
        }

        // Also try the original name (in case DB has unnormalized entries)
        if ($normalizedInput !== $name) {
            $stmt = $this->db->prepare("SELECT id FROM autori WHERE nome = ? LIMIT 1");
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();

            if ($row) {
                return (int)$row['id'];
            }
        }

        // Try case-insensitive match with normalized name
        $lowerNormalized = mb_strtolower($normalizedInput, 'UTF-8');
        $stmt = $this->db->prepare("SELECT id FROM autori WHERE LOWER(nome) = ? LIMIT 1");
        $stmt->bind_param('s', $lowerNormalized);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        if ($row) {
            return (int)$row['id'];
        }

        // Try fuzzy match: use database LIKE to narrow candidates, then check in PHP
        // This catches authors with slight variations (accents, particles, etc.)
        $searchForm = \App\Support\AuthorNormalizer::toSearchForm($name);
        if ($searchForm !== '') {
            // Extract individual words for LIKE matching
            $words = explode(' ', $searchForm);
            // Build LIKE conditions for each word (case-insensitive via LOWER())
            $conditions = [];
            $params = [];
            foreach ($words as $word) {
                if (mb_strlen($word, 'UTF-8') >= 2) { // Skip very short words
                    $conditions[] = "LOWER(nome) LIKE ? ESCAPE '\\\\'";
                    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word);
                    $params[] = '%' . $escaped . '%';
                }
            }

            if (!empty($conditions)) {
                // Search for authors containing ALL of the words (AND condition)
                $sql = "SELECT id, nome FROM autori WHERE " . implode(' AND ', $conditions) . " LIMIT 50";
                $stmt = $this->db->prepare($sql);
                if ($stmt === false) {
                    return null;
                }
                $types = str_repeat('s', count($params));
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();

                while ($row = $res->fetch_assoc()) {
                    if (\App\Support\AuthorNormalizer::match($name, $row['nome'])) {
                        return (int)$row['id'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find duplicate authors (same name after normalization)
     *
     * @return array Array of arrays containing duplicate author groups
     */
    public function findDuplicates(): array
    {
        $stmt = $this->db->prepare("SELECT id, nome FROM autori ORDER BY nome");
        $stmt->execute();
        $res = $stmt->get_result();

        $normalized = [];
        while ($row = $res->fetch_assoc()) {
            $normalizedName = \App\Support\AuthorNormalizer::normalize($row['nome']);
            $key = mb_strtolower($normalizedName, 'UTF-8');

            if (!isset($normalized[$key])) {
                $normalized[$key] = [];
            }
            $normalized[$key][] = [
                'id' => (int)$row['id'],
                'nome' => $row['nome'],
                'normalized' => $normalizedName
            ];
        }

        // Return only groups with more than one author (duplicates)
        $duplicates = [];
        foreach ($normalized as $key => $group) {
            if (count($group) > 1) {
                $duplicates[] = $group;
            }
        }

        return $duplicates;
    }

    /**
     * Merge duplicate authors into one
     *
     * Keeps the specified primary author (or the one with lowest ID if not specified)
     * and reassigns all books from other authors to the primary one.
     *
     * @param array $authorIds Array of author IDs to merge
     * @param int|null $primaryId Optional specific ID to use as primary (must be in $authorIds)
     * @return int|null The ID of the merged author, or null on error
     */
    public function mergeAuthors(array $authorIds, ?int $primaryId = null): ?int
    {
        // Normalize to integers and deduplicate to prevent type-safety issues
        // and deleting primary when duplicate IDs are passed
        $authorIds = array_values(array_unique(array_filter(
            array_map('intval', $authorIds),
            fn($id) => $id > 0
        )));

        if (count($authorIds) < 2) {
            return null;
        }

        // Use specified primary ID or default to lowest ID
        if ($primaryId !== null && $primaryId > 0 && in_array($primaryId, $authorIds, true)) {
            // Create separate array of IDs to delete (excluding primary)
            $duplicateIds = array_values(array_filter($authorIds, fn($id) => $id !== $primaryId));
        } else {
            // Sort to get the lowest ID as primary
            sort($authorIds);
            $primaryId = array_shift($authorIds);
            $duplicateIds = $authorIds;
        }

        // Ensure we have duplicates to process
        if (empty($duplicateIds)) {
            return null;
        }

        // Snapshot the books linked to ANY merged author BEFORE the re-point /
        // DELETE removes the linking rows, so search_index can be rebuilt after
        // commit (the merged name change affects every one of these books).
        $affectedBookIds = [];
        foreach (array_merge([$primaryId], $duplicateIds) as $mergedId) {
            foreach (\App\Support\SearchIndexBuilder::bookIdsForAuthor($this->db, (int) $mergedId) as $bid) {
                $affectedBookIds[$bid] = $bid;
            }
        }

        // Join the caller's transaction through a savepoint instead of nesting
        // begin_transaction(), which would commit the caller's work implicitly.
        $ownsTransaction = !$this->hasActiveTransaction();
        if ($ownsTransaction) { $this->db->begin_transaction(); }
        $savepoint = 'author_merge_'.bin2hex(random_bytes(6));
        $this->db->query("SAVEPOINT $savepoint");

        try {
            // The archives plugin (optional) keeps an author↔authority identity
            // table with a FK ON DELETE CASCADE to autori. Detect it once so the
            // per-duplicate loop can repoint it when present; absent when the
            // plugin isn't installed.
            $hasAuthorityLink = false;
            $res = $this->db->query(
                "SELECT 1 FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'autori_authority_link' LIMIT 1"
            );
            if ($res instanceof \mysqli_result) {
                $hasAuthorityLink = $res->num_rows > 0;
                $res->free();
            }

            if ($this->hasColumn('gnd_id')) {
                $ids = array_merge([$primaryId], $duplicateIds);
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $gndStmt = $this->db->prepare("SELECT DISTINCT gnd_id FROM autori WHERE id IN ($marks) AND gnd_id IS NOT NULL FOR UPDATE");
                $gndStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
                $gndStmt->execute();
                $gnds = $gndStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $gndStmt->close();
                if (count($gnds) > 1) { throw new \RuntimeException('Cannot merge different GND identities'); }
                if ($gnds !== []) {
                    $gnd = $gnds[0]['gnd_id'];
                    $gndStmt = $this->db->prepare("UPDATE autori SET gnd_id=NULL WHERE id IN ($marks)");
                    $gndStmt->bind_param(str_repeat('i', count($ids)), ...$ids); $gndStmt->execute(); $gndStmt->close();
                    $gndStmt = $this->db->prepare('UPDATE autori SET gnd_id=? WHERE id=?');
                    $gndStmt->bind_param('si', $gnd, $primaryId); $gndStmt->execute(); $gndStmt->close();
                }
            }

            // Both ship via migrations, so a pre-migration schema may lack them.
            // Gate their repoint/delete on existence so a merge never fails on a
            // missing optional table.
            $hasAlternates = \App\Support\SchemaInfo::hasTable($this->db, 'author_authority_alternates');
            $hasImportSources = \App\Support\SchemaInfo::hasTable($this->db, 'libri_autori_import_sources');

            foreach ($duplicateIds as $duplicateId) {
                // Update book-author relationships to point to primary author
                // Use IGNORE to handle unique constraint violations (book already linked to primary)
                $stmt = $this->db->prepare(
                    "UPDATE IGNORE libri_autori SET autore_id = ? WHERE autore_id = ?"
                );
                if ($stmt === false) {
                    throw new \Exception("Failed to prepare UPDATE IGNORE: " . $this->db->error);
                }
                $stmt->bind_param('ii', $primaryId, $duplicateId);
                if (!$stmt->execute()) {
                    throw new \Exception("Failed to execute UPDATE IGNORE: " . $stmt->error);
                }
                $stmt->close();

                // Delete any remaining duplicate links (where book was already linked to primary)
                $stmt = $this->db->prepare("DELETE FROM libri_autori WHERE autore_id = ?");
                if ($stmt === false) {
                    throw new \Exception("Failed to prepare DELETE libri_autori: " . $this->db->error);
                }
                $stmt->bind_param('i', $duplicateId);
                if (!$stmt->execute()) {
                    throw new \Exception("Failed to execute DELETE libri_autori: " . $stmt->error);
                }
                $stmt->close();

                // Repoint authority alternates before the cascade drops them.
                // Surrogate PK only (no unique on autore_id + authority), so a
                // plain UPDATE cannot collide.
                if ($hasAlternates) {
                    $stmt = $this->db->prepare(
                        "UPDATE author_authority_alternates SET autore_id = ? WHERE autore_id = ?"
                    );
                    if ($stmt === false) {
                        throw new \Exception("Failed to prepare UPDATE author_authority_alternates: " . $this->db->error);
                    }
                    $stmt->bind_param('ii', $primaryId, $duplicateId);
                    if (!$stmt->execute()) {
                        throw new \Exception("Failed to execute UPDATE author_authority_alternates: " . $stmt->error);
                    }
                    $stmt->close();
                }

                // Repoint import-source provenance. Its composite PK
                // (libro_id, autore_id, ruolo, source) can collide when the
                // primary already holds the same tuple — IGNORE skips those,
                // then delete the leftovers still on the duplicate.
                if ($hasImportSources) {
                    $stmt = $this->db->prepare(
                        "UPDATE IGNORE libri_autori_import_sources SET autore_id = ? WHERE autore_id = ?"
                    );
                    if ($stmt === false) {
                        throw new \Exception("Failed to prepare UPDATE IGNORE libri_autori_import_sources: " . $this->db->error);
                    }
                    $stmt->bind_param('ii', $primaryId, $duplicateId);
                    if (!$stmt->execute()) {
                        throw new \Exception("Failed to execute UPDATE IGNORE libri_autori_import_sources: " . $stmt->error);
                    }
                    $stmt->close();

                    $stmt = $this->db->prepare("DELETE FROM libri_autori_import_sources WHERE autore_id = ?");
                    if ($stmt === false) {
                        throw new \Exception("Failed to prepare DELETE libri_autori_import_sources: " . $this->db->error);
                    }
                    $stmt->bind_param('i', $duplicateId);
                    if (!$stmt->execute()) {
                        throw new \Exception("Failed to execute DELETE libri_autori_import_sources: " . $stmt->error);
                    }
                    $stmt->close();
                }

                // Repoint the archives-plugin authority-identity link when the
                // table exists. Composite PK (autori_id, authority_id) can
                // collide — IGNORE skips those, then delete the leftovers.
                if ($hasAuthorityLink) {
                    $stmt = $this->db->prepare(
                        "UPDATE IGNORE autori_authority_link SET autori_id = ? WHERE autori_id = ?"
                    );
                    if ($stmt === false) {
                        throw new \Exception("Failed to prepare UPDATE IGNORE autori_authority_link: " . $this->db->error);
                    }
                    $stmt->bind_param('ii', $primaryId, $duplicateId);
                    if (!$stmt->execute()) {
                        throw new \Exception("Failed to execute UPDATE IGNORE autori_authority_link: " . $stmt->error);
                    }
                    $stmt->close();

                    $stmt = $this->db->prepare("DELETE FROM autori_authority_link WHERE autori_id = ?");
                    if ($stmt === false) {
                        throw new \Exception("Failed to prepare DELETE autori_authority_link: " . $this->db->error);
                    }
                    $stmt->bind_param('i', $duplicateId);
                    if (!$stmt->execute()) {
                        throw new \Exception("Failed to execute DELETE autori_authority_link: " . $stmt->error);
                    }
                    $stmt->close();
                }

                (new \App\Services\ArticleAuthorService($this->db))->merge((int)$primaryId, (int)$duplicateId);

                // Delete the duplicate author
                $stmt = $this->db->prepare("DELETE FROM autori WHERE id = ?");
                if ($stmt === false) {
                    throw new \Exception("Failed to prepare DELETE autori: " . $this->db->error);
                }
                $stmt->bind_param('i', $duplicateId);
                if (!$stmt->execute()) {
                    throw new \Exception("Failed to execute DELETE autori: " . $stmt->error);
                }
                $stmt->close();
            }

            $this->db->query("RELEASE SAVEPOINT $savepoint");
            if ($ownsTransaction) { $this->db->commit(); }

            // Rebuild search_index for the affected books now the surviving links
            // all point at the primary author.
            \App\Support\SearchIndexBuilder::rebuildMany($this->db, array_values($affectedBookIds));
            if ($ownsTransaction) { \App\Support\ContentCache::booksChanged(); }
            else { \App\Support\ContentCache::deferBooksChanged(); }

            return $primaryId;
        } catch (\Throwable $e) {
            if ($ownsTransaction) { $this->db->rollback(); }
            else { $this->db->query("ROLLBACK TO SAVEPOINT $savepoint"); $this->db->query("RELEASE SAVEPOINT $savepoint"); }
            error_log("[AuthorRepository] Merge failed: " . $e->getMessage());
            return null;
        }
    }

    private function hasActiveTransaction(): bool
    {
        $result = $this->db->query('SELECT @@autocommit AS ac');
        if ($result instanceof \mysqli_result && (int)($result->fetch_assoc()['ac'] ?? 1) === 0) { return true; }
        $probe = 'author_delete_probe_'.bin2hex(random_bytes(6));
        try {
            if (!$this->db->query("SAVEPOINT $probe") || !$this->db->query("ROLLBACK TO SAVEPOINT $probe")) { return false; }
            $this->db->query("RELEASE SAVEPOINT $probe");
            return true;
        } catch (\mysqli_sql_exception) { return false; }
    }

    public function delete(int $id): bool
    {
        $ownsTransaction = !$this->hasActiveTransaction();
        if ($ownsTransaction) { $this->db->begin_transaction(); }
        $savepoint = 'author_delete_'.bin2hex(random_bytes(6));
        $this->db->query("SAVEPOINT $savepoint");
        try {
            $affectedBookIds = \App\Support\SearchIndexBuilder::bookIdsForAuthor($this->db, $id);
            (new \App\Services\ArticleAuthorService($this->db))->beforeDelete($id);
            foreach (['libri_autori', 'autori'] as $table) {
                $column = $table === 'autori' ? 'id' : 'autore_id';
                $stmt = $this->db->prepare("DELETE FROM $table WHERE $column=?");
                try {
                    $stmt->bind_param('i', $id);
                    if (!$stmt->execute()) { throw new \RuntimeException('Author deletion failed'); }
                } finally { $stmt->close(); }
            }
            \App\Support\SearchIndexBuilder::rebuildMany($this->db, $affectedBookIds);
            $this->db->query("RELEASE SAVEPOINT $savepoint");
            if ($ownsTransaction) { $this->db->commit(); }
        } catch (\Throwable $e) {
            if ($ownsTransaction) { $this->db->rollback(); }
            else { $this->db->query("ROLLBACK TO SAVEPOINT $savepoint"); $this->db->query("RELEASE SAVEPOINT $savepoint"); }
            throw $e;
        }
        \App\Support\ContentCache::deferBooksChanged();
        return true;
    }
}
