<?php

declare(strict_types=1);

namespace App\Plugins\FrbrLrm;

use mysqli;

/**
 * Data layer for FRBR/LRM Work (opere) records.
 *
 * All queries are soft-delete aware (`deleted_at IS NULL`) — mirroring the
 * project-wide rule applied to `libri`. Prepared statements throughout,
 * except softDelete()'s integer-cast UPDATE.
 */
class OpereRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * List non-deleted opere with edition counts, ordered alphabetically by uniform title.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 100): array
    {
        $sql = "SELECT o.*, a.nome AS autore_nome,
                       (SELECT COUNT(*) FROM libri l WHERE l.opera_id = o.id AND l.deleted_at IS NULL) AS num_edizioni
                FROM opere o
                LEFT JOIN autori a ON o.autore_principale_id = a.id
                WHERE o.deleted_at IS NULL
                ORDER BY o.titolo_uniforme ASC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
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
        $sql = "SELECT o.*, a.nome AS autore_nome
                FROM opere o
                LEFT JOIN autori a ON o.autore_principale_id = a.id
                WHERE o.id = ? AND o.deleted_at IS NULL
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function getBySlug(string $slug): ?array
    {
        $sql = "SELECT o.*, a.nome AS autore_nome
                FROM opere o
                LEFT JOIN autori a ON o.autore_principale_id = a.id
                WHERE o.slug = ? AND o.deleted_at IS NULL
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * All non-deleted manifestations (libri) attached to an opera.
     *
     * @return array<int, array<string, mixed>>
     */
    public function editionsForOpera(int $operaId): array
    {
        $sql = "SELECT l.id, l.titolo, l.sottotitolo, l.anno_pubblicazione, l.copertina_url,
                       l.isbn13, l.isbn10, e.nome AS editore
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                WHERE l.opera_id = ? AND l.deleted_at IS NULL
                ORDER BY l.anno_pubblicazione ASC, l.titolo ASC";
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

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $slug = $this->uniqueSlug((string) ($data['titolo_uniforme'] ?? 'opera'));
        $sql = "INSERT INTO opere
                  (titolo_uniforme, titolo_originale, autore_principale_id, data_creazione_da,
                   data_creazione_a, lingua_originale, viaf_work_id, wikidata_id, slug, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $titolo = (string) ($data['titolo_uniforme'] ?? '');
        $originale = $data['titolo_originale'] !== '' ? ($data['titolo_originale'] ?? null) : null;
        $autoreId = !empty($data['autore_principale_id']) ? (int) $data['autore_principale_id'] : null;
        $da = $data['data_creazione_da'] !== '' ? (int) ($data['data_creazione_da'] ?? 0) : null;
        $a = $data['data_creazione_a'] !== '' ? (int) ($data['data_creazione_a'] ?? 0) : null;
        $lingua = $data['lingua_originale'] !== '' ? ($data['lingua_originale'] ?? null) : null;
        $viaf = $data['viaf_work_id'] !== '' ? ($data['viaf_work_id'] ?? null) : null;
        $wikidata = $data['wikidata_id'] !== '' ? ($data['wikidata_id'] ?? null) : null;
        $note = $data['note'] !== '' ? ($data['note'] ?? null) : null;
        // 10 params: titolo(s) originale(s) autoreId(i) da(i) a(i) lingua(s) viaf(s) wikidata(s) slug(s) note(s)
        $stmt->bind_param('ssiiisssss', $titolo, $originale, $autoreId, $da, $a, $lingua, $viaf, $wikidata, $slug, $note);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE opere SET
                  titolo_uniforme = ?, titolo_originale = ?, autore_principale_id = ?,
                  data_creazione_da = ?, data_creazione_a = ?, lingua_originale = ?,
                  viaf_work_id = ?, wikidata_id = ?, note = ?, updated_at = NOW()
                WHERE id = ? AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $titolo = (string) ($data['titolo_uniforme'] ?? '');
        $originale = $data['titolo_originale'] !== '' ? ($data['titolo_originale'] ?? null) : null;
        $autoreId = !empty($data['autore_principale_id']) ? (int) $data['autore_principale_id'] : null;
        $da = $data['data_creazione_da'] !== '' ? (int) ($data['data_creazione_da'] ?? 0) : null;
        $a = $data['data_creazione_a'] !== '' ? (int) ($data['data_creazione_a'] ?? 0) : null;
        $lingua = $data['lingua_originale'] !== '' ? ($data['lingua_originale'] ?? null) : null;
        $viaf = $data['viaf_work_id'] !== '' ? ($data['viaf_work_id'] ?? null) : null;
        $wikidata = $data['wikidata_id'] !== '' ? ($data['wikidata_id'] ?? null) : null;
        $note = $data['note'] !== '' ? ($data['note'] ?? null) : null;
        $stmt->bind_param('ssiiissssi', $titolo, $originale, $autoreId, $da, $a, $lingua, $viaf, $wikidata, $note, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** Soft-delete an opera. Books detach via ON DELETE SET NULL only on hard delete, so we NULL them explicitly. */
    public function softDelete(int $id): bool
    {
        $this->db->query("UPDATE libri SET opera_id = NULL WHERE opera_id = " . (int) $id);
        $stmt = $this->db->prepare("UPDATE opere SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Autocomplete search by uniform title / original title.
     *
     * @return array<int, array{id:int, label:string}>
     */
    public function search(string $q, int $limit = 10): array
    {
        $like = '%' . $q . '%';
        $sql = "SELECT id, titolo_uniforme AS label
                FROM opere
                WHERE deleted_at IS NULL AND (titolo_uniforme LIKE ? OR titolo_originale LIKE ?)
                ORDER BY titolo_uniforme ASC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
        }
        $stmt->close();
        return $out;
    }

    /** Generate a slug unique within `opere`. */
    private function uniqueSlug(string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/u', '-', $base) ?? 'opera';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'opera';
        }
        $base = substr($base, 0, 200);
        $slug = $base;
        $n = 1;
        while (true) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM opere WHERE slug = ?");
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $cnt = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            if ($cnt === 0) {
                return $slug;
            }
            $slug = $base . '-' . (++$n);
        }
    }
}
