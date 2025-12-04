<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class AuthorRepository
{
    public function __construct(private mysqli $db) {}

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

    public function getBooksByAuthorId(int $authorId): array
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
                INNER JOIN libri_autori la ON l.id = la.libro_id
                WHERE la.autore_id = ?
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
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM libri_autori WHERE autore_id = ?');
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        return (int)$count;
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO autori (nome, pseudonimo, data_nascita, data_morte, `nazionalità`, biografia, sito_web, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $stmt = $this->db->prepare($sql);

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

        $stmt->bind_param(
            'sssssss',
            $nome,
            $pseudonimo,
            $data_nascita,
            $data_morte,
            $nazionalita,
            $biografia,
            $sito_web
        );
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        // Plugin hook: Before author save
        \App\Support\Hooks::do('author.save.before', [$data, $id]);

        $sql = "UPDATE autori SET nome=?, pseudonimo=?, data_nascita=?, data_morte=?, `nazionalità`=?, biografia=?, sito_web=?, updated_at=NOW() WHERE id=?";
        $stmt = $this->db->prepare($sql);

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

        $stmt->bind_param(
            'sssssssi',
            $nome,
            $pseudonimo,
            $data_nascita,
            $data_morte,
            $nazionalita,
            $biografia,
            $sito_web,
            $id
        );

        $result = $stmt->execute();

        // Plugin hook: After author save
        \App\Support\Hooks::do('author.save.after', [$id, $data]);

        return $result;
    }

    /**
     * Find an author by name, with normalization to prevent duplicates
     *
     * Searches for author using both exact match and normalized variants
     * to handle different name formats (e.g., "Levi, Primo" vs "Primo Levi")
     *
     * @param string $name Author name in any format
     * @return int|null Author ID if found, null otherwise
     */
    public function findByName(string $name): ?int
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
            if (!empty($words)) {
                // Build LIKE conditions for each word (case-insensitive via collation)
                $conditions = [];
                $params = [];
                foreach ($words as $word) {
                    if (strlen($word) >= 2) { // Skip very short words
                        $conditions[] = "LOWER(nome) LIKE ?";
                        $params[] = '%' . $word . '%';
                    }
                }

                if (!empty($conditions)) {
                    // Search for authors containing ALL of the words (AND condition)
                    $sql = "SELECT id, nome FROM autori WHERE " . implode(' AND ', $conditions) . " LIMIT 50";
                    $stmt = $this->db->prepare($sql);
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
        // Deduplicate IDs to prevent deleting primary when duplicate IDs are passed
        $authorIds = array_values(array_unique($authorIds));

        if (count($authorIds) < 2) {
            return null;
        }

        // Use specified primary ID or default to lowest ID
        if ($primaryId !== null && in_array($primaryId, $authorIds, true)) {
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

        // Start transaction
        $this->db->begin_transaction();

        try {
            foreach ($duplicateIds as $duplicateId) {
                // Update book-author relationships to point to primary author
                // Use IGNORE to handle unique constraint violations (book already linked to primary)
                $stmt = $this->db->prepare(
                    "UPDATE IGNORE libri_autori SET autore_id = ? WHERE autore_id = ?"
                );
                $stmt->bind_param('ii', $primaryId, $duplicateId);
                $stmt->execute();

                // Delete any remaining duplicate links (where book was already linked to primary)
                $stmt = $this->db->prepare("DELETE FROM libri_autori WHERE autore_id = ?");
                $stmt->bind_param('i', $duplicateId);
                $stmt->execute();

                // Delete the duplicate author
                $stmt = $this->db->prepare("DELETE FROM autori WHERE id = ?");
                $stmt->bind_param('i', $duplicateId);
                $stmt->execute();
            }

            $this->db->commit();
            return $primaryId;
        } catch (\Exception $e) {
            $this->db->rollback();
            error_log("[AuthorRepository] Merge failed: " . $e->getMessage());
            return null;
        }
    }

    public function delete(int $id): bool
    {
        // Optionally handle cascade in DB; here, remove links then author
        $stmt = $this->db->prepare('DELETE FROM libri_autori WHERE autore_id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM autori WHERE id=?');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }
}
