<?php
declare(strict_types=1);

namespace App\Services;

use mysqli;

/** Read-only bridge between book holdings and the optional Emeroteca corpus. */
final class UnifiedCatalogService
{
    public function __construct(private mysqli $db) {}

    public function enabled(): bool
    {
        $result = $this->db->query("SELECT 1 FROM plugins WHERE name='emeroteca' AND is_active=1");
        if (!$result || $result->num_rows === 0) {
            return false;
        }
        // Older plugin versions must keep their existing book-only catalogue.
        $result = $this->db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emeroteca_contributi' AND COLUMN_NAME='sottotitolo'");
        return $result !== false && $result->num_rows > 0;
    }

    /** @return list<string> */
    public static function nameVariants(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') { return []; }
        $variants = [$name];
        if (str_contains($name, ',')) {
            [$family, $given] = array_map('trim', explode(',', $name, 2));
            $variants[] = "$given $family";
        } else {
            // Search both established cataloguing spellings; this is retrieval,
            // never an assertion of identity or a mutation of the authority file.
            $words = explode(' ', $name);
            if (count($words) > 1) {
                $family = array_pop($words);
                $variants[] = $family . ', ' . implode(' ', $words);
            }
        }
        return array_values(array_unique($variants));
    }

    /** @param list<string> $names */
    public static function authorPattern(array $names): string
    {
        $variants = [];
        foreach ($names as $name) {
            array_push($variants, ...self::nameVariants($name));
        }
        return '(^|;)[[:space:]]*(' . implode('|', array_map(static fn(string $s): string => preg_quote($s, '~'), array_unique($variants))) . ')[[:space:]]*(;|$)';
    }

    /**
     * SQL UNION paginates identities before loading at most one page of records.
     * No all-catalogue fetch, and overlapping IDs across corpora remain distinct.
     * @param list<mixed> $bookParams
     * @param array<string,mixed> $filters
     * @return array{rows:array,total:int,articles:int}|null
     */
    public function page(string $bookFrom, string $authorSelect, string $bookTypes, array $bookParams, array $filters, int $bookTotal, int $limit, int $offset): ?array
    {
        $query = $this->articleQuery($filters);
        if ($query === null) { return null; }
        [$articleFrom, $types, $params] = $query;
        $articleTotal = (int)$this->rows('SELECT COUNT(*) n' . $articleFrom, $types, $params)[0]['n'];
        if ($articleTotal === 0) { return null; }
        $bookSelect = "SELECT l.id, l.titolo, l.created_at, $authorSelect $bookFrom";
        $union = "SELECT id, 'book' AS kind, titolo COLLATE utf8mb4_unicode_ci AS title_sort,
                         created_at, autore_cognome COLLATE utf8mb4_unicode_ci AS author_sort FROM ($bookSelect) books
                  UNION ALL
                  SELECT c.id, 'article', c.titolo COLLATE utf8mb4_unicode_ci, c.created_at,
                         NULLIF(TRIM(CASE WHEN SUBSTRING_INDEX(c.autori, ';', 1) LIKE '%,%'
                           THEN SUBSTRING_INDEX(SUBSTRING_INDEX(c.autori, ';', 1), ',', 1)
                           ELSE SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(c.autori, ';', 1)), ' ', -1) END), '') COLLATE utf8mb4_unicode_ci
                  $articleFrom";
        $order = match ($filters['sort'] ?? 'newest') {
            'oldest' => 'created_at ASC',
            'title_asc' => 'title_sort ASC',
            'title_desc' => 'title_sort DESC',
            'author_asc' => 'author_sort IS NULL, author_sort ASC',
            'author_desc' => 'author_sort IS NULL, author_sort DESC',
            default => 'created_at DESC',
        };
        $keys = $this->rows("SELECT * FROM ($union) results ORDER BY $order, kind ASC, id ASC LIMIT ? OFFSET ?",
            $bookTypes . $types . 'ii', array_merge($bookParams, $params, [$limit, $offset]));
        $records = [];
        foreach (['book', 'article'] as $kind) {
            $ids = array_map(static fn(array $r): int => (int)$r['id'], array_values(array_filter($keys, static fn(array $r): bool => $r['kind'] === $kind)));
            if ($ids === []) { continue; }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $sql = $kind === 'book'
                ? "SELECT l.*, $authorSelect, e.nome editore, g.nome genere FROM libri l LEFT JOIN editori e ON e.id=l.editore_id LEFT JOIN generi g ON g.id=l.genere_id WHERE l.deleted_at IS NULL AND l.id IN ($marks)"
                : "SELECT c.*, c.autori autore, COALESCE(NULLIF(c.copertina_url,''),t.logo_url) copertina_url FROM emeroteca_contributi c LEFT JOIN emeroteca_testate t ON t.id=c.testata_id WHERE c.pubblico=1 AND c.id IN ($marks)";
            foreach ($this->rows($sql, str_repeat('i', count($ids)), $ids) as $row) {
                $row['_record_kind'] = $kind;
                $records[$kind . ':' . $row['id']] = $row;
            }
        }
        $rows = [];
        foreach ($keys as $key) {
            if (isset($records[$key['kind'] . ':' . $key['id']])) {
                $rows[] = $records[$key['kind'] . ':' . $key['id']];
            }
        }
        return ['rows' => $rows, 'total' => $bookTotal + $articleTotal, 'articles' => $articleTotal];
    }

    /** @param array<string,mixed> $filters */
    public function countArticles(array $filters): int
    {
        $query = $this->articleQuery($filters);
        if ($query === null) { return 0; }
        [$from, $types, $params] = $query;
        return (int)$this->rows('SELECT COUNT(*) n' . $from, $types, $params)[0]['n'];
    }

    /** @param array<string,mixed> $filters @return array{string,string,list<string>}|null */
    private function articleQuery(array $filters): ?array
    {
        if (!empty($filters['_books_only']) || !$this->enabled()) { return null; }
        // These facets describe books/loanable copies, not articles. Do not
        // silently mix unfiltered articles into a explicitly filtered result.
        foreach (['genere_id', 'editore', 'disponibilita', 'tipo_media'] as $facet) {
            if (!empty($filters[$facet])) { return null; }
        }
        $where = ['c.pubblico = 1'];
        $params = [];
        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            // Same word-wise semantics as the book index; punctuation in an
            // inverted personal name must not prevent a natural-order search.
            $words = preg_split('/[^\p{L}\p{N}_%]+/u', mb_substr($term, 0, 200), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                $where[] = "CONCAT_WS(' ', c.titolo, c.sottotitolo, c.autori, c.contenitore_titolo, c.keywords, c.abstract, c.issn) LIKE ? ESCAPE '='";
                $params[] = '%' . strtr($word, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            }
        }
        foreach (['anno_min' => '>=', 'anno_max' => '<='] as $key => $operator) {
            if (!empty($filters[$key])) {
                $where[] = "c.anno_pubblicazione $operator ?";
                $params[] = (string) $filters[$key];
            }
        }
        $names = [];
        if (!empty($filters['autore_id'])) {
            $authors = $this->rows('SELECT nome, pseudonimo FROM autori WHERE id=?', 'i', [(int)$filters['autore_id']]);
            if ($authors === []) { $where[] = '1=0'; }
            else {
                $names = array_values(array_filter([$authors[0]['nome'], $authors[0]['pseudonimo']], static fn($name): bool => is_string($name) && trim($name) !== ''));
                if ($names === []) { $where[] = '1=0'; }
            }
        } elseif (!empty($filters['autore'])) {
            $names = [(string)$filters['autore']];
        }
        if ($names !== []) {
            $where[] = 'c.autori REGEXP ?';
            $params[] = self::authorPattern($names);
        }
        $articleFrom = ' FROM emeroteca_contributi c WHERE ' . implode(' AND ', $where);
        $types = str_repeat('s', count($params));
        return [$articleFrom, $types, $params];
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
