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

    public function update(int $id, array $data): bool
    {
        $nome = trim((string)($data['nome'] ?? ''));
        if (empty($nome)) {
            throw new \InvalidArgumentException('Nome genere richiesto');
        }

        $nome = \App\Support\HtmlHelper::decode($nome);

        if (array_key_exists('parent_id', $data)) {
            // Update name and parent (parent can be null for top-level)
            $parent_id = $data['parent_id'];
            $stmt = $this->db->prepare("UPDATE generi SET nome = ?, parent_id = ? WHERE id = ?");
            $stmt->bind_param('sii', $nome, $parent_id, $id);
        } else {
            // Update name only
            $stmt = $this->db->prepare("UPDATE generi SET nome = ? WHERE id = ?");
            $stmt->bind_param('si', $nome, $id);
        }

        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        // Check if genre has children
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM generi WHERE parent_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];

        if ($count > 0) {
            throw new \RuntimeException('Impossibile eliminare: il genere ha sottogeneri');
        }

        // Check if genre is used by any book
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM libri WHERE (genere_id = ? OR sottogenere_id = ?) AND deleted_at IS NULL");
        $stmt->bind_param('ii', $id, $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];

        if ($count > 0) {
            throw new \RuntimeException('Impossibile eliminare: il genere è usato da libri esistenti');
        }

        $stmt = $this->db->prepare("DELETE FROM generi WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
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

    /**
     * @return array<int, array{id: int, nome: string, parent_id: ?int, parent_nome: ?string}>
     */
    public function getAllFlat(): array
    {
        $result = $this->db->query("
            SELECT g.id, g.nome, g.parent_id, p.nome AS parent_nome
            FROM generi g
            LEFT JOIN generi p ON g.parent_id = p.id
            ORDER BY COALESCE(p.nome, g.nome), g.parent_id IS NOT NULL, g.nome
        ");

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @return array{children_moved: int, books_updated: int}
     */
    public function merge(int $sourceId, int $targetId): array
    {
        if ($sourceId === $targetId) {
            throw new \InvalidArgumentException('Non è possibile unire un genere con sé stesso');
        }

        $source = $this->getById($sourceId);
        if (!$source) {
            throw new \InvalidArgumentException('Genere di origine non trovato');
        }

        $target = $this->getById($targetId);
        if (!$target) {
            throw new \InvalidArgumentException('Genere di destinazione non trovato');
        }

        // Prevent merging into a descendant (would create cycles)
        $ancestorId = $targetId;
        $depth = 20;
        $aStmt = $this->db->prepare('SELECT parent_id FROM generi WHERE id = ?');
        while ($ancestorId > 0 && $depth-- > 0) {
            if ($ancestorId === $sourceId) {
                $aStmt->close();
                throw new \InvalidArgumentException('Impossibile unire un genere con un suo discendente');
            }
            $aStmt->bind_param('i', $ancestorId);
            $aStmt->execute();
            $row = $aStmt->get_result()->fetch_assoc();
            $ancestorId = $row ? (int)($row['parent_id'] ?? 0) : 0;
        }
        $aStmt->close();

        // Transaction safety: detect if already inside a transaction
        $acResult = $this->db->query("SELECT @@autocommit as ac");
        $wasInTransaction = ((int)$acResult->fetch_assoc()['ac'] === 0);

        if (!$wasInTransaction) {
            $this->db->begin_transaction();
        }

        try {
            // Rename conflicting children before moving
            $sourceChildren = $this->getChildren($sourceId);
            $targetChildren = $this->getChildren($targetId);
            $targetChildNames = array_column($targetChildren, 'nome');

            foreach ($sourceChildren as $child) {
                if (in_array($child['nome'], $targetChildNames, true)) {
                    $newName = $child['nome'] . ' (ex ' . $source['nome'] . ')';
                    // If the renamed version also collides, add a counter
                    $counter = 2;
                    while (in_array($newName, $targetChildNames, true)) {
                        $newName = $child['nome'] . ' (ex ' . $source['nome'] . ' ' . $counter . ')';
                        $counter++;
                    }
                    $targetChildNames[] = $newName;
                    $stmt = $this->db->prepare("UPDATE generi SET nome = ? WHERE id = ?");
                    $stmt->bind_param('si', $newName, $child['id']);
                    $stmt->execute();
                }
            }

            // Move children from source to target (exclude target itself to prevent self-referencing)
            $stmt = $this->db->prepare("UPDATE generi SET parent_id = ? WHERE parent_id = ? AND id != ?");
            $stmt->bind_param('iii', $targetId, $sourceId, $targetId);
            $stmt->execute();
            $childrenMoved = $stmt->affected_rows;

            // If target was a child of source, reparent target to source's parent
            $sourceParent = $source['parent_id'] !== null ? (int)$source['parent_id'] : null;
            $stmt = $this->db->prepare("UPDATE generi SET parent_id = ? WHERE id = ? AND parent_id = ?");
            $stmt->bind_param('iii', $sourceParent, $targetId, $sourceId);
            $stmt->execute();

            // Count distinct books referencing source (including soft-deleted, since we delete the genre row)
            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT id) as cnt FROM libri WHERE genere_id = ? OR sottogenere_id = ?");
            $stmt->bind_param('ii', $sourceId, $sourceId);
            $stmt->execute();
            $booksUpdated = (int)$stmt->get_result()->fetch_assoc()['cnt'];

            // Update ALL books (including soft-deleted) to prevent dangling FK references
            $stmt = $this->db->prepare("UPDATE libri SET genere_id = ? WHERE genere_id = ?");
            $stmt->bind_param('ii', $targetId, $sourceId);
            $stmt->execute();

            $stmt = $this->db->prepare("UPDATE libri SET sottogenere_id = ? WHERE sottogenere_id = ?");
            $stmt->bind_param('ii', $targetId, $sourceId);
            $stmt->execute();

            // Delete source genre
            $stmt = $this->db->prepare("DELETE FROM generi WHERE id = ?");
            $stmt->bind_param('i', $sourceId);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nella cancellazione del genere di origine');
            }

            if (!$wasInTransaction) {
                $this->db->commit();
            }

            return ['children_moved' => $childrenMoved, 'books_updated' => $booksUpdated];
        } catch (\Throwable $e) {
            if (!$wasInTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }
    }
}
?>