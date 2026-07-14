<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class PublisherRepository
{
    public function __construct(private mysqli $db) {}

    public function listBasic(int $limit = 200): array
    {
        $rows = [];
        $stmt = $this->db->prepare("SELECT id, nome, sito_web FROM editori ORDER BY nome LIMIT ?");
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
        $stmt = $this->db->prepare("SELECT * FROM editori WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $publisher = $res->fetch_assoc() ?: null;

        if ($publisher) {
            // Plugin hook: Extend publisher data
            $publisher = \App\Support\Hooks::apply('publisher.data.get', $publisher, [$id]);
        }

        return $publisher;
    }

    public function getBooksByPublisherId(int $publisherId): array
    {
        $hasJunction = \App\Support\SchemaInfo::hasLibriEditori($this->db);
        $exists = $hasJunction
            ? " OR EXISTS (SELECT 1 FROM libri_editori le WHERE le.libro_id = l.id AND le.editore_id = ?)"
            : "";
        $authorDisplay = \App\Support\AuthorName::displaySql('a');
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.ean, l.data_acquisizione, l.stato, l.copertina_url,
                       e.nome AS editore_nome,
                       (
                         SELECT GROUP_CONCAT({$authorDisplay} ORDER BY la.ordine_credito SEPARATOR ', ')
                         FROM libri_autori la
                         JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id
                           AND la.ruolo IN ('principale', 'co-autore')
                       ) AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                WHERE (l.editore_id = ?{$exists})
                      AND l.deleted_at IS NULL
                ORDER BY l.titolo ASC";
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

    public function getAuthorsByPublisherId(int $publisherId): array
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

    public function countBooks(int $publisherId): int
    {
        // Count books where the publisher is primary (libri.editore_id) OR a
        // secondary one in the multi-publisher junction (issue #143). This is
        // the guard EditorsController::delete() relies on (the bulk-delete in
        // EditoriApiController applies the same primary-OR-junction check
        // inline): a primary-only count would report 0 and let the editori
        // DELETE cascade silently wipe the book's libri_editori rows.
        $hasJunction = \App\Support\SchemaInfo::hasLibriEditori($this->db);
        $exists = $hasJunction
            ? " OR EXISTS (SELECT 1 FROM libri_editori le WHERE le.libro_id = l.id AND le.editore_id = ?)"
            : "";
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM libri l
             WHERE (l.editore_id = ?{$exists})
                   AND l.deleted_at IS NULL"
        );
        if ($hasJunction) {
            $stmt->bind_param('ii', $publisherId, $publisherId);
        } else {
            $stmt->bind_param('i', $publisherId);
        }
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        return (int)$count;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO editori (
                nome, sito_web, indirizzo, telefono, email,
                referente_nome, referente_telefono, referente_email, codice_fiscale,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        // Decode HTML entities to prevent double encoding
        $nome = \App\Support\HtmlHelper::decode($data['nome']);
        $sito_web = \App\Support\HtmlHelper::decode($data['sito_web'] ?? '');
        $indirizzo = \App\Support\HtmlHelper::decode($data['indirizzo'] ?? '');
        $telefono = \App\Support\HtmlHelper::decode($data['telefono'] ?? '');
        $email = \App\Support\HtmlHelper::decode($data['email'] ?? '');
        $referente_nome = \App\Support\HtmlHelper::decode($data['referente_nome'] ?? '');
        $referente_telefono = \App\Support\HtmlHelper::decode($data['referente_telefono'] ?? '');
        $referente_email = \App\Support\HtmlHelper::decode($data['referente_email'] ?? '');
        $codice_fiscale = \App\Support\HtmlHelper::decode($data['codice_fiscale'] ?? '');

        // Convert empty string to NULL for UNIQUE constraint compatibility
        // MySQL UNIQUE allows multiple NULLs but only one empty string
        if ($codice_fiscale === '') {
            $codice_fiscale = null;
        }

        $stmt->bind_param(
            'sssssssss',
            $nome, $sito_web, $indirizzo, $telefono, $email,
            $referente_nome, $referente_telefono, $referente_email, $codice_fiscale
        );
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE editori SET
                nome = ?, sito_web = ?, indirizzo = ?, telefono = ?, email = ?,
                referente_nome = ?, referente_telefono = ?, referente_email = ?, codice_fiscale = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        // Decode HTML entities to prevent double encoding
        $nome = \App\Support\HtmlHelper::decode($data['nome']);
        $sito_web = \App\Support\HtmlHelper::decode($data['sito_web'] ?? '');
        $indirizzo = \App\Support\HtmlHelper::decode($data['indirizzo'] ?? '');
        $telefono = \App\Support\HtmlHelper::decode($data['telefono'] ?? '');
        $email = \App\Support\HtmlHelper::decode($data['email'] ?? '');
        $referente_nome = \App\Support\HtmlHelper::decode($data['referente_nome'] ?? '');
        $referente_telefono = \App\Support\HtmlHelper::decode($data['referente_telefono'] ?? '');
        $referente_email = \App\Support\HtmlHelper::decode($data['referente_email'] ?? '');
        $codice_fiscale = \App\Support\HtmlHelper::decode($data['codice_fiscale'] ?? '');

        // Convert empty string to NULL for UNIQUE constraint compatibility
        if ($codice_fiscale === '') {
            $codice_fiscale = null;
        }

        $stmt->bind_param(
            'sssssssssi',
            $nome, $sito_web, $indirizzo, $telefono, $email,
            $referente_nome, $referente_telefono, $referente_email, $codice_fiscale,
            $id
        );
        $result = $stmt->execute();

        // The publisher name is embedded in every linked book's search_index
        // (primary editore_id OR the libri_editori junction) — rebuild them. The
        // links are unchanged by an edit, so querying the affected set is safe.
        if ($result) {
            \App\Support\SearchIndexBuilder::rebuildForPublisher($this->db, $id);
        }

        return $result;
    }

    public function delete(int $id): bool
    {
        // Snapshot the linked books BEFORE the FK is nulled / cascade fires so
        // their search_index (which embeds this publisher's name) can be rebuilt.
        $affectedBookIds = \App\Support\SearchIndexBuilder::bookIdsForPublisher($this->db, $id);

        $stmt = $this->db->prepare('UPDATE libri SET editore_id=NULL WHERE editore_id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM editori WHERE id=?');
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();

        if ($result) {
            \App\Support\SearchIndexBuilder::rebuildMany($this->db, $affectedBookIds);
        }

        return $result;
    }

    public function findByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;
        $stmt = $this->db->prepare('SELECT id FROM editori WHERE nome = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->bind_result($id);
        if ($stmt->fetch()) { return (int)$id; }
        return null;
    }

    /**
     * Merge duplicate publishers into one
     *
     * Keeps the specified primary publisher (or the one with lowest ID if not specified)
     * and reassigns all books from other publishers to the primary one.
     *
     * @param array $publisherIds Array of publisher IDs to merge
     * @param int|null $primaryId Optional specific ID to use as primary (must be in $publisherIds)
     * @return int|null The ID of the merged publisher, or null on error
     */
    public function mergePublishers(array $publisherIds, ?int $primaryId = null): ?int
    {
        // Normalize to integers and deduplicate to prevent type-safety issues
        // and deleting primary when duplicate IDs are passed
        $publisherIds = array_values(array_unique(array_filter(
            array_map('intval', $publisherIds),
            fn($id) => $id > 0
        )));

        if (count($publisherIds) < 2) {
            return null;
        }

        // Use specified primary ID or default to lowest ID
        if ($primaryId !== null && $primaryId > 0 && \in_array($primaryId, $publisherIds, true)) {
            // Create separate array of IDs to delete (excluding primary)
            $duplicateIds = array_values(array_filter($publisherIds, fn($id) => $id !== $primaryId));
        } else {
            // Sort to get the lowest ID as primary
            sort($publisherIds);
            $primaryId = array_shift($publisherIds);
            $duplicateIds = $publisherIds;
        }

        // Ensure we have duplicates to process
        if (empty($duplicateIds)) {
            return null;
        }

        // The multi-publisher junction (issue #143) has a FK ON DELETE CASCADE
        // to `editori`, so deleting a duplicate would silently wipe its
        // libri_editori rows. Detect the table once so we can repoint those
        // rows onto the primary BEFORE the cascade fires. Guarded for installs
        // predating the migration (table absent → fall back to editore_id only).
        $junctionRes = $this->db->query("SHOW TABLES LIKE 'libri_editori'");
        $hasJunction = ($junctionRes instanceof \mysqli_result && $junctionRes->num_rows > 0);

        // Snapshot the books linked to ANY merged publisher (primary FK or
        // junction) BEFORE the re-point / cascade moves the rows, so search_index
        // can be rebuilt after commit (the merged name affects all these books).
        $affectedBookIds = [];
        foreach (array_merge([$primaryId], $duplicateIds) as $mergedId) {
            foreach (\App\Support\SearchIndexBuilder::bookIdsForPublisher($this->db, (int) $mergedId) as $bid) {
                $affectedBookIds[$bid] = $bid;
            }
        }

        // Start transaction
        $this->db->begin_transaction();

        try {
            foreach ($duplicateIds as $duplicateId) {
                // Update books to point to primary publisher
                $stmt = $this->db->prepare("UPDATE libri SET editore_id = ? WHERE editore_id = ?");
                if ($stmt === false) {
                    throw new \Exception("Failed to prepare UPDATE libri: " . $this->db->error);
                }
                $stmt->bind_param('ii', $primaryId, $duplicateId);
                if (!$stmt->execute()) {
                    throw new \Exception("Failed to execute UPDATE libri: " . $stmt->error);
                }
                $stmt->close();

                // Repoint the junction rows onto the primary publisher BEFORE
                // the editori DELETE cascades them away. INSERT IGNORE keeps the
                // existing (libro, primary) row when a book already lists both;
                // the duplicate's leftover rows are then removed by the cascade.
                if ($hasJunction) {
                    $stmt = $this->db->prepare(
                        "INSERT IGNORE INTO libri_editori (libro_id, editore_id, ordine)
                         SELECT libro_id, ?, ordine FROM libri_editori WHERE editore_id = ?"
                    );
                    if ($stmt === false) {
                        throw new \Exception("Failed to prepare repoint libri_editori: " . $this->db->error);
                    }
                    $stmt->bind_param('ii', $primaryId, $duplicateId);
                    if (!$stmt->execute()) {
                        throw new \Exception("Failed to execute repoint libri_editori: " . $stmt->error);
                    }
                    $stmt->close();
                }

                // Delete the duplicate publisher
                $stmt = $this->db->prepare("DELETE FROM editori WHERE id = ?");
                if ($stmt === false) {
                    throw new \Exception("Failed to prepare DELETE editori: " . $this->db->error);
                }
                $stmt->bind_param('i', $duplicateId);
                if (!$stmt->execute()) {
                    throw new \Exception("Failed to execute DELETE editori: " . $stmt->error);
                }
                $stmt->close();
            }

            // The repoint uses INSERT IGNORE: when a book already lists the
            // primary as a secondary, the incoming row is skipped and the
            // primary keeps its existing (possibly non-zero) ordine, so the
            // book may be left with no ordine=0 row (primary marker lost).
            // Re-assert ordine=0 for the surviving primary wherever it is the
            // book's primary (libri.editore_id), keeping the junction's primary
            // marker consistent with libri.editore_id (issue #143).
            if ($hasJunction) {
                $stmt = $this->db->prepare(
                    "UPDATE libri_editori le
                     JOIN libri l ON l.id = le.libro_id
                     SET le.ordine = 0
                     WHERE le.editore_id = ? AND l.editore_id = le.editore_id
                           AND l.deleted_at IS NULL AND le.ordine <> 0"
                );
                // Treat a failure here like the earlier steps: throw so the
                // surrounding catch rolls the whole merge back instead of
                // committing a junction with an inconsistent primary marker.
                if ($stmt === false) {
                    throw new \Exception("Failed to prepare ordine re-assert: " . $this->db->error);
                }
                $stmt->bind_param('i', $primaryId);
                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new \Exception("Failed to execute ordine re-assert: " . $err);
                }
                $stmt->close();
            }

            $this->db->commit();

            // Rebuild search_index for the affected books now the surviving
            // links all point at the primary publisher.
            \App\Support\SearchIndexBuilder::rebuildMany($this->db, array_values($affectedBookIds));

            return $primaryId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log("[PublisherRepository] Merge failed: " . $e->getMessage());
            return null;
        }
    }
}
