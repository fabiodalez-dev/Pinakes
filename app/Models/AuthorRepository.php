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

    public function findByName(string $name): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM autori WHERE nome = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        return $row ? (int)$row['id'] : null;
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
