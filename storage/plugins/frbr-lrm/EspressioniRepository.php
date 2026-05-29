<?php

declare(strict_types=1);

namespace App\Plugins\FrbrLrm;

use mysqli;

/**
 * Data layer for FRBR/LRM Expression (espressioni) records.
 * Soft-delete aware. Prepared statements throughout,
 * except softDelete()'s integer-cast UPDATE.
 */
class EspressioniRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listForOpera(int $operaId): array
    {
        $sql = "SELECT e.*, t.nome AS traduttore_nome, c.nome AS curatore_nome
                FROM espressioni e
                LEFT JOIN autori t ON e.traduttore_autore_id = t.id
                LEFT JOIN autori c ON e.curatore_autore_id = c.id
                WHERE e.opera_id = ? AND e.deleted_at IS NULL
                ORDER BY e.anno_espressione ASC, e.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $operaId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM espressioni WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $sql = "INSERT INTO espressioni
                  (opera_id, lingua, tipo_espressione, traduttore_autore_id, curatore_autore_id,
                   revisore_autore_id, titolo_espressione, anno_espressione, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $operaId = (int) ($data['opera_id'] ?? 0);
        $lingua = $data['lingua'] !== '' ? ($data['lingua'] ?? null) : null;
        $tipo = (string) ($data['tipo_espressione'] ?? 'testo');
        $trad = !empty($data['traduttore_autore_id']) ? (int) $data['traduttore_autore_id'] : null;
        $cur = !empty($data['curatore_autore_id']) ? (int) $data['curatore_autore_id'] : null;
        $rev = !empty($data['revisore_autore_id']) ? (int) $data['revisore_autore_id'] : null;
        $titolo = $data['titolo_espressione'] !== '' ? ($data['titolo_espressione'] ?? null) : null;
        $anno = $data['anno_espressione'] !== '' ? (int) ($data['anno_espressione'] ?? 0) : null;
        $note = $data['note'] !== '' ? ($data['note'] ?? null) : null;
        // 9 params: opera_id(i) lingua(s) tipo(s) trad(i) cur(i) rev(i) titolo(s) anno(i) note(s)
        $stmt->bind_param('issiiisis', $operaId, $lingua, $tipo, $trad, $cur, $rev, $titolo, $anno, $note);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function softDelete(int $id): bool
    {
        $this->db->query("UPDATE libri SET espressione_id = NULL WHERE espressione_id = " . (int) $id);
        $stmt = $this->db->prepare("UPDATE espressioni SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
