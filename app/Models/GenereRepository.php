<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;
use App\Support\ContentCache;

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

        ContentCache::deferBooksChanged();
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
            $parent_id = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;

            // Prevent self/descendant reparenting
            if ($parent_id !== null) {
                if ($parent_id === $id) {
                    throw new \InvalidArgumentException('Il genere non può essere figlio di sé stesso');
                }
                $ancestorId = $parent_id;
                $seen = [];
                $aStmt = $this->db->prepare('SELECT parent_id FROM generi WHERE id = ?');
                if (!$aStmt) {
                    throw new \RuntimeException('Errore nella preparazione della query di verifica gerarchia');
                }
                while ($ancestorId > 0) {
                    if (isset($seen[$ancestorId])) {
                        $aStmt->close();
                        throw new \RuntimeException('Ciclo rilevato nella gerarchia dei generi');
                    }
                    $seen[$ancestorId] = true;
                    if ($ancestorId === $id) {
                        $aStmt->close();
                        throw new \InvalidArgumentException('Impossibile spostare il genere sotto un suo discendente');
                    }
                    $aStmt->bind_param('i', $ancestorId);
                    $aStmt->execute();
                    $row = $aStmt->get_result()->fetch_assoc();
                    $ancestorId = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
                }
                $aStmt->close();
            }

            if ($parent_id === null) {
                $stmt = $this->db->prepare("UPDATE generi SET nome = ?, parent_id = NULL WHERE id = ?");
                $stmt->bind_param('si', $nome, $id);
            } else {
                $stmt = $this->db->prepare("UPDATE generi SET nome = ?, parent_id = ? WHERE id = ?");
                $stmt->bind_param('sii', $nome, $parent_id, $id);
            }
        } else {
            // Update name only
            $stmt = $this->db->prepare("UPDATE generi SET nome = ? WHERE id = ?");
            $stmt->bind_param('si', $nome, $id);
        }

        $result = $stmt->execute();
        if ($result) {
            ContentCache::deferBooksChanged();
        }
        return $result;
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

        // Only count non-deleted books. Soft-deleted rows are safe because both
        // libri_ibfk_3 (genere_id) and fk_libri_sottogenere (sottogenere_id) use
        // ON DELETE SET NULL — the DB automatically nullifies references when
        // a genre is deleted, so no FK violation can occur from soft-deleted rows.
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM libri WHERE (genere_id = ? OR sottogenere_id = ?) AND deleted_at IS NULL");
        $stmt->bind_param('ii', $id, $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];

        if ($count > 0) {
            throw new \RuntimeException('Impossibile eliminare: il genere è usato da libri esistenti');
        }

        // Physical shelf bindings must block a plain delete rather than silently
        // cascade. posizioni.genere_id is ON DELETE CASCADE: deleting the genre
        // would drop its posizioni and NULL libri.posizione_id even for books of
        // OTHER genres that happen to be shelved there (they are not caught by the
        // genere_id/sottogenere_id book check above). mensole.genere_id is RESTRICT,
        // so an assigned mensola would otherwise crash with FK error 1451.
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM posizioni WHERE genere_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        if ($count > 0) {
            throw new \RuntimeException('Impossibile eliminare: il genere ha posizioni fisiche assegnate');
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM mensole WHERE genere_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        if ($count > 0) {
            throw new \RuntimeException('Impossibile eliminare: il genere è assegnato a una mensola');
        }

        $stmt = $this->db->prepare("DELETE FROM generi WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        if ($result) {
            ContentCache::deferBooksChanged();
        }
        return $result;
    }

    public function cascadeDelete(int $id): bool
    {
        $ids = $this->collectSubtreeIds($id);
        if (empty($ids)) {
            return false;
        }

        $this->db->begin_transaction();

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $this->db->prepare("
                UPDATE libri
                SET genere_id = IF(genere_id IN ({$placeholders}), NULL, genere_id),
                    sottogenere_id = IF(sottogenere_id IN ({$placeholders}), NULL, sottogenere_id)
                WHERE genere_id IN ({$placeholders})
                   OR sottogenere_id IN ({$placeholders})
            ");
            $this->bindIntParams($stmt, array_merge($ids, $ids, $ids, $ids));
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nello scollegamento dei libri dai generi');
            }

            $stmt = $this->db->prepare("UPDATE mensole SET genere_id = NULL WHERE genere_id IN ({$placeholders})");
            $this->bindIntParams($stmt, $ids);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nello scollegamento delle mensole dai generi');
            }

            // Explicitly remove the subtree's posizioni inside the transaction rather
            // than letting the ON DELETE CASCADE fire silently when the genere rows go.
            // posizioni.genere_id is NOT NULL (cannot be unlinked like mensole), so
            // deleting the defining genres necessarily removes their positions; the
            // FK on libri.posizione_id (ON DELETE SET NULL) unshelves the affected
            // books, an inherent consequence of force-deleting the genre subtree.
            $stmt = $this->db->prepare("DELETE FROM posizioni WHERE genere_id IN ({$placeholders})");
            $this->bindIntParams($stmt, $ids);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nella rimozione delle posizioni dei generi');
            }

            $stmt = $this->db->prepare("DELETE FROM generi WHERE id = ?");
            $stmt->bind_param('i', $id);
            $result = $stmt->execute();

            $this->db->commit();
            ContentCache::booksChanged();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * @return array<int, int>
     */
    private function collectSubtreeIds(int $id): array
    {
        $ids = [];
        $queue = [$id];
        $stmt = $this->db->prepare("SELECT id FROM generi WHERE parent_id = ?");
        if (!$stmt) {
            throw new \RuntimeException('Errore nella preparazione della query dei sottogeneri');
        }

        while ($queue) {
            $currentId = array_shift($queue);
            if (isset($ids[$currentId])) {
                continue;
            }

            $genre = $this->getById($currentId);
            if (!$genre) {
                continue;
            }

            $ids[$currentId] = $currentId;
            $stmt->bind_param('i', $currentId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $queue[] = (int)$row['id'];
            }
        }

        $stmt->close();
        return array_values($ids);
    }

    /**
     * @param array<int, int> $values
     */
    private function bindIntParams(\mysqli_stmt $stmt, array $values): void
    {
        $types = str_repeat('i', count($values));
        $refs = [$types];
        foreach ($values as $key => $value) {
            $values[$key] = (int)$value;
            $refs[] = &$values[$key];
        }
        $stmt->bind_param(...$refs);
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

        if (!$result instanceof \mysqli_result) {
            return [];
        }

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
        $seen = [];
        $aStmt = $this->db->prepare('SELECT parent_id FROM generi WHERE id = ?');
        if (!$aStmt) {
            throw new \RuntimeException('Errore nella preparazione della query di verifica gerarchia');
        }
        while ($ancestorId > 0) {
            if (isset($seen[$ancestorId])) {
                $aStmt->close();
                throw new \RuntimeException('Ciclo rilevato nella gerarchia dei generi');
            }
            $seen[$ancestorId] = true;
            if ($ancestorId === $sourceId) {
                $aStmt->close();
                throw new \InvalidArgumentException('Impossibile unire un genere con un suo discendente');
            }
            $aStmt->bind_param('i', $ancestorId);
            $aStmt->execute();
            $row = $aStmt->get_result()->fetch_assoc();
            $ancestorId = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
        }
        $aStmt->close();

        $this->db->begin_transaction();

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
                    if (!$stmt->execute()) {
                        throw new \RuntimeException('Impossibile rinominare il sottogenere in conflitto: ' . $child['nome']);
                    }
                }
            }

            // Move children from source to target (exclude target itself to prevent self-referencing)
            $stmt = $this->db->prepare("UPDATE generi SET parent_id = ? WHERE parent_id = ? AND id != ?");
            $stmt->bind_param('iii', $targetId, $sourceId, $targetId);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nello spostamento dei sottogeneri');
            }
            $childrenMoved = $stmt->affected_rows;

            // If target was a child of source, reparent target to source's parent
            $sourceParent = $source['parent_id'] !== null ? (int)$source['parent_id'] : null;
            if ($sourceParent === null) {
                $stmt = $this->db->prepare("UPDATE generi SET parent_id = NULL WHERE id = ? AND parent_id = ?");
                $stmt->bind_param('ii', $targetId, $sourceId);
            } else {
                $stmt = $this->db->prepare("UPDATE generi SET parent_id = ? WHERE id = ? AND parent_id = ?");
                $stmt->bind_param('iii', $sourceParent, $targetId, $sourceId);
            }
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nel reparenting del genere di destinazione');
            }

            // Count distinct books referencing source (including soft-deleted, since we delete the genre row)
            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT id) as cnt FROM libri WHERE genere_id = ? OR sottogenere_id = ?");
            $stmt->bind_param('ii', $sourceId, $sourceId);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nel conteggio dei libri referenziati');
            }
            $booksUpdated = (int)$stmt->get_result()->fetch_assoc()['cnt'];

            // Update ALL books (including soft-deleted) to prevent dangling FK references
            $stmt = $this->db->prepare("UPDATE libri SET genere_id = ? WHERE genere_id = ?");
            $stmt->bind_param('ii', $targetId, $sourceId);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nell\'aggiornamento del genere dei libri');
            }

            $stmt = $this->db->prepare("UPDATE libri SET sottogenere_id = ? WHERE sottogenere_id = ?");
            $stmt->bind_param('ii', $targetId, $sourceId);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nell\'aggiornamento del sottogenere dei libri');
            }

            // Preserve physical shelf placement: posizioni.genere_id is ON DELETE
            // CASCADE and libri.posizione_id is ON DELETE SET NULL, so deleting the
            // source genre would drop its posizioni and strip the shelf from every
            // book placed there. Repoint the source genre's posizioni to the target
            // instead. posizioni has a UNIQUE (scaffale_id, mensola_id, genere_id):
            // when the target already owns a position on the same shelf+mensola,
            // move the books onto that existing row and drop the duplicate source
            // position rather than triggering a unique-key collision.
            $srcPosStmt = $this->db->prepare("SELECT id, scaffale_id, mensola_id FROM posizioni WHERE genere_id = ?");
            $srcPosStmt->bind_param('i', $sourceId);
            if (!$srcPosStmt->execute()) {
                throw new \RuntimeException('Errore nel recupero delle posizioni del genere di origine');
            }
            $positions = $srcPosStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (!empty($positions)) {
                $findTargetPos = $this->db->prepare("SELECT id FROM posizioni WHERE scaffale_id = ? AND mensola_id = ? AND genere_id = ? LIMIT 1");
                $moveBooksPos = $this->db->prepare("UPDATE libri SET posizione_id = ? WHERE posizione_id = ?");
                $dropPos = $this->db->prepare("DELETE FROM posizioni WHERE id = ?");
                $repointPos = $this->db->prepare("UPDATE posizioni SET genere_id = ? WHERE id = ?");

                foreach ($positions as $pos) {
                    $posId = (int)$pos['id'];
                    $posScaffale = (int)$pos['scaffale_id'];
                    $posMensola = (int)$pos['mensola_id'];

                    $findTargetPos->bind_param('iii', $posScaffale, $posMensola, $targetId);
                    if (!$findTargetPos->execute()) {
                        throw new \RuntimeException('Errore nella verifica delle posizioni di destinazione');
                    }
                    $existing = $findTargetPos->get_result()->fetch_assoc();

                    if ($existing) {
                        $existingId = (int)$existing['id'];
                        $moveBooksPos->bind_param('ii', $existingId, $posId);
                        if (!$moveBooksPos->execute()) {
                            throw new \RuntimeException('Errore nello spostamento dei libri sulla posizione di destinazione');
                        }
                        $dropPos->bind_param('i', $posId);
                        if (!$dropPos->execute()) {
                            throw new \RuntimeException('Errore nella rimozione della posizione duplicata');
                        }
                    } else {
                        $repointPos->bind_param('ii', $targetId, $posId);
                        if (!$repointPos->execute()) {
                            throw new \RuntimeException('Errore nel ripuntamento della posizione al genere di destinazione');
                        }
                    }
                }
            }

            // mensole.genere_id is a plain RESTRICT FK with no unique on genere_id,
            // so a straight repoint is safe (and prevents the FK 1451 that would
            // otherwise abort the merge whenever a mensola is assigned to the genre).
            $stmt = $this->db->prepare("UPDATE mensole SET genere_id = ? WHERE genere_id = ?");
            $stmt->bind_param('ii', $targetId, $sourceId);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nel ripuntamento delle mensole al genere di destinazione');
            }

            // Delete source genre
            $stmt = $this->db->prepare("DELETE FROM generi WHERE id = ?");
            $stmt->bind_param('i', $sourceId);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Errore nella cancellazione del genere di origine');
            }

            $this->db->commit();
            ContentCache::booksChanged();

            return ['children_moved' => $childrenMoved, 'books_updated' => $booksUpdated];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
?>
