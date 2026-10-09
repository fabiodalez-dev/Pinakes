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
        $authorDisplay = \App\Support\AuthorName::displaySql('a');
        $sql = "SELECT o.*, {$authorDisplay} AS autore_nome,
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
        $authorDisplay = \App\Support\AuthorName::displaySql('a');
        $sql = "SELECT o.*, {$authorDisplay} AS autore_nome
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

    /**
     * The opera (Work) currently attached to a given book, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getForBook(int $bookId): ?array
    {
        $authorDisplay = \App\Support\AuthorName::displaySql('a');
        $sql = "SELECT o.id, o.titolo_uniforme, o.slug, {$authorDisplay} AS autore_nome,
                       l.espressione_id
                FROM libri l
                INNER JOIN opere o ON l.opera_id = o.id AND o.deleted_at IS NULL
                LEFT JOIN autori a ON o.autore_principale_id = a.id
                WHERE l.id = ? AND l.deleted_at IS NULL
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** True if a non-deleted opera with this id exists. */
    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM opere WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    }

    /** @return array<string, mixed>|null */
    public function getBySlug(string $slug): ?array
    {
        $authorDisplay = \App\Support\AuthorName::displaySql('a');
        $sql = "SELECT o.*, {$authorDisplay} AS autore_nome
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
     * The method serves both the admin opera page and the anonymous
     * /opera/{slug} page, so the catalogue filter is a PARAMETER and defaults
     * to permissive: an operator must keep seeing every manifestation attached
     * to an opera, including the ones the library is still looking for.
     * Filtering unconditionally here would hide them from the admin too.
     *
     * @param bool $catalogueOnly true only from the public entry point: drops
     *        wanted titles, which the public book page answers 404 for.
     * @return array<int, array<string, mixed>>
     */
    public function editionsForOpera(int $operaId, bool $catalogueOnly = false): array
    {
        $visible = $catalogueOnly
            ? ' AND ' . \App\Support\BookVisibility::catalogue($this->db, 'l')
            : '';
        $sql = "SELECT l.id, l.titolo, l.sottotitolo, l.anno_pubblicazione, l.copertina_url,
                       l.isbn13, l.isbn10, l.espressione_id, e.nome AS editore
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                WHERE l.opera_id = ? AND l.deleted_at IS NULL{$visible}
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
        $titolo = trim((string) ($data['titolo_uniforme'] ?? ''));
        $originale = self::optString($data, 'titolo_originale');
        $autoreId = self::optInt($data, 'autore_principale_id');
        $da = self::optInt($data, 'data_creazione_da', true);
        $a = self::optInt($data, 'data_creazione_a', true);
        $lingua = self::optString($data, 'lingua_originale');
        $viaf = self::optString($data, 'viaf_work_id');
        $wikidata = self::optString($data, 'wikidata_id');
        $note = self::optString($data, 'note');
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
        $titolo = trim((string) ($data['titolo_uniforme'] ?? ''));
        $originale = self::optString($data, 'titolo_originale');
        $autoreId = self::optInt($data, 'autore_principale_id');
        $da = self::optInt($data, 'data_creazione_da', true);
        $a = self::optInt($data, 'data_creazione_a', true);
        $lingua = self::optString($data, 'lingua_originale');
        $viaf = self::optString($data, 'viaf_work_id');
        $wikidata = self::optString($data, 'wikidata_id');
        $note = self::optString($data, 'note');
        $stmt->bind_param('ssiiissssi', $titolo, $originale, $autoreId, $da, $a, $lingua, $viaf, $wikidata, $note, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Soft-delete an opera. Books detach via ON DELETE SET NULL only on hard
     * delete, so we NULL them explicitly — the Expression link too, since an
     * Expression cannot outlive the Work it realises.
     */
    public function softDelete(int $id): bool
    {
        $this->db->query("UPDATE libri SET opera_id = NULL, espressione_id = NULL WHERE opera_id = " . (int) $id);
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
        // Escape the LIKE metacharacters so a literal `%`, `_` or `\\` in the
        // query matches itself instead of acting as a wildcard.
        $like = '%' . addcslashes($q, '%_\\') . '%';
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

    /**
     * Generate a slug unique within `opere`. Non-ASCII titles are
     * transliterated first (Война и мир → vojna-i-mir, Les Misérables →
     * les-miserables) so Cyrillic/Greek/accented works do not all collapse to
     * `opera`, `opera-2`, …
     */
    private function uniqueSlug(string $title): string
    {
        $base = self::slugBase($title);
        if ($base === '') {
            $base = 'opera';
        }
        $base = rtrim(substr($base, 0, 200), '-');
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

    /** ASCII slug body for a title, via the core slug helper when loaded. */
    public static function slugBase(string $title): string
    {
        if (function_exists('slugify_text')) {
            return slugify_text($title);
        }
        $ascii = $title;
        if (class_exists('Transliterator')) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            $out = $t !== null ? $t->transliterate($title) : false;
            if ($out !== false) {
                $ascii = $out;
            }
        } else {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT', $title);
            if ($out !== false) {
                $ascii = $out;
            }
        }
        $ascii = preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '';
        return trim($ascii, '-');
    }

    /**
     * Trimmed string value for an optional form key, or null when the key is
     * absent or blank.
     *
     * @param array<string, mixed> $data
     */
    private static function optString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Integer value for an optional form key, or null when the key is absent,
     * blank or not numeric. Foreign keys ($allowZero = false) treat 0 as unset.
     *
     * @param array<string, mixed> $data
     */
    private static function optInt(array $data, string $key, bool $allowZero = false): ?int
    {
        $value = self::optString($data, $key);
        if ($value === null || !preg_match('/^-?[0-9]+$/', $value)) {
            return null;
        }
        $int = (int) $value;
        return ($int === 0 && !$allowZero) ? null : $int;
    }
}
