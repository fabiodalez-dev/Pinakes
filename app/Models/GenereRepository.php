<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class GenereRepository
{
    public function __construct(private mysqli $db) {}

    public function findByName(string $nome, ?int $parent_id = null): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM generi WHERE nome = ? AND parent_id <=> ?");
        $stmt->bind_param('si', $nome, $parent_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }

    public function create(array $data): int
    {
        $nome = trim((string)($data['nome'] ?? ''));
        $parent_id = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;

        if (empty($nome)) {
            throw new \InvalidArgumentException('Nome genere richiesto');
        }
        
        // Decode HTML entities to prevent double encoding
        $nome = \App\Support\HtmlHelper::decode($nome);

        // Check if already exists
        $existing = $this->findByName($nome, $parent_id);
        if ($existing) {
            return $existing;
        }

        $stmt = $this->db->prepare("INSERT INTO generi (nome, parent_id, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param('si', $nome, $parent_id);

        if (!$stmt->execute()) {
            throw new \RuntimeException('Errore nella creazione del genere');
        }

        return $this->db->insert_id;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT g.*, p.nome AS parent_nome
            FROM generi g
            LEFT JOIN generi p ON g.parent_id = p.id
            WHERE g.id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row ?: null;
    }

    public function listAll(int $limit = 100): array
    {
        $stmt = $this->db->prepare("
            SELECT g.id, g.nome, g.parent_id,
                   p.nome AS parent_nome,
                   CASE WHEN g.parent_id IS NULL THEN 'genere' ELSE 'sottogenere' END AS tipo,
                   (SELECT COUNT(*) FROM generi child WHERE child.parent_id = g.id) AS children_count
            FROM generi g
            LEFT JOIN generi p ON g.parent_id = p.id
            ORDER BY g.parent_id IS NULL DESC, g.nome
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getChildren(int $parent_id): array
    {
        $stmt = $this->db->prepare("
            SELECT id, nome
            FROM generi
            WHERE parent_id = ?
            ORDER BY nome
        ");
        $stmt->bind_param('i', $parent_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}
?>