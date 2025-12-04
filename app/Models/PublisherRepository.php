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
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato, l.copertina_url,
                       e.nome AS editore_nome,
                       (
                         SELECT GROUP_CONCAT(a.nome SEPARATOR ', ')
                         FROM libri_autori la
                         JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id
                       ) AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                WHERE l.editore_id = ?
                ORDER BY l.titolo ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $publisherId);
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
        $sql = "SELECT DISTINCT a.id, a.nome, a.pseudonimo
                FROM autori a
                INNER JOIN libri_autori la ON a.id = la.autore_id
                INNER JOIN libri l ON la.libro_id = l.id
                WHERE l.editore_id = ?
                ORDER BY a.nome ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $publisherId);
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
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM libri WHERE editore_id = ?');
        $stmt->bind_param('i', $publisherId);
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
        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE libri SET editore_id=NULL WHERE editore_id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM editori WHERE id=?');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
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
        if (count($publisherIds) < 2) {
            return null;
        }

        // Use specified primary ID or default to lowest ID
        if ($primaryId !== null && \in_array($primaryId, $publisherIds, true)) {
            $publisherIds = array_values(array_filter($publisherIds, fn($id) => $id !== $primaryId));
        } else {
            // Sort to get the lowest ID as primary
            sort($publisherIds);
            $primaryId = array_shift($publisherIds);
        }

        // Start transaction
        $this->db->begin_transaction();

        try {
            foreach ($publisherIds as $duplicateId) {
                // Update books to point to primary publisher
                $stmt = $this->db->prepare("UPDATE libri SET editore_id = ? WHERE editore_id = ?");
                $stmt->bind_param('ii', $primaryId, $duplicateId);
                $stmt->execute();

                // Delete the duplicate publisher
                $stmt = $this->db->prepare("DELETE FROM editori WHERE id = ?");
                $stmt->bind_param('i', $duplicateId);
                $stmt->execute();
            }

            $this->db->commit();
            return $primaryId;
        } catch (\Exception $e) {
            $this->db->rollback();
            error_log("[PublisherRepository] Merge failed: " . $e->getMessage());
            return null;
        }
    }
}
