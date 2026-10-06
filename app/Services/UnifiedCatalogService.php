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
        $bookSelect = "SELECT l.id, l.titolo, l.created_at, l.anno_pubblicazione, $authorSelect $bookFrom";
        // One author sort key for the whole mixed catalogue (issue #412):
        // - a credit linked to an author identity sorts exactly like that author's
        //   books (CatalogAuthorProjection: last word of the preferred name,
        //   principal author first), so one person never lands under two letters;
        // - a free-text credit has no identity, so it keeps the text rule:
        //   "Surname, Forename" sorts by the part before the comma, a
        //   direct-order name by its last word.
        $textSort = static fn(string $credit): string => "NULLIF(TRIM(CASE WHEN SUBSTRING_INDEX($credit, ';', 1) LIKE '%,%'
                           THEN SUBSTRING_INDEX(SUBSTRING_INDEX($credit, ';', 1), ',', 1)
                           ELSE SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX($credit, ';', 1)), ' ', -1) END), '')";
        $articleSort = $textSort('c.autori');
        if ((new ArticleAuthorService($this->db))->available()) {
            $linkedSort = "NULLIF(SUBSTRING_INDEX(" . \App\Support\AuthorName::preferredSql('a') . ", ' ', -1), '')";
            $articleSort = "COALESCE((SELECT CASE WHEN a.id IS NOT NULL THEN $linkedSort ELSE " . $textSort('ca.nome_credito') . " END
                             FROM emeroteca_contributi_autori ca LEFT JOIN autori a ON a.id=ca.autore_id
                             WHERE ca.contributo_id=c.id
                             ORDER BY (ca.ruolo = 'principale') DESC, ca.ordine_credito LIMIT 1), " . $textSort('c.autori') . ")";
        }
        $union = "SELECT id, 'book' AS kind, titolo COLLATE utf8mb4_unicode_ci AS title_sort,
                         created_at, anno_pubblicazione AS publication_sort, autore_cognome COLLATE utf8mb4_unicode_ci AS author_sort FROM ($bookSelect) books
                  UNION ALL
                  SELECT c.id, 'article', c.titolo COLLATE utf8mb4_unicode_ci, c.created_at, c.anno_pubblicazione,
                         ($articleSort) COLLATE utf8mb4_unicode_ci
                  $articleFrom";
        $order = match ($filters['sort'] ?? 'newest') {
            'oldest' => 'created_at ASC',
            'publication_desc' => 'publication_sort DESC, title_sort ASC',
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
                : "SELECT c.*, c.autori autore, t.titolo testata_titolo, COALESCE(NULLIF(c.copertina_url,''),NULLIF(f.copertina_url,''),t.logo_url) copertina_url FROM emeroteca_contributi c LEFT JOIN emeroteca_testate t ON t.id=c.testata_id LEFT JOIN emeroteca_fascicoli f ON f.id=c.fascicolo_id WHERE c.pubblico=1 AND c.id IN ($marks)";
            $pageRows = $this->rows($sql, str_repeat('i', count($ids)), $ids);
            if ($kind === 'article') { $pageRows = (new ArticleAuthorService($this->db))->hydrate($pageRows); }
            foreach ($pageRows as $row) {
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

    private ?bool $articlesHaveGenre = null;

    private function articlesHaveGenre(): bool
    {
        if ($this->articlesHaveGenre === null) {
            $result = $this->db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emeroteca_contributi' AND COLUMN_NAME='genere_id'");
            $this->articlesHaveGenre = $result !== false && $result->num_rows > 0;
        }
        return $this->articlesHaveGenre;
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
        foreach (['editore', 'disponibilita', 'tipo_media'] as $facet) {
            if (!empty($filters[$facet])) { return null; }
        }
        $linkedAuthors = (new ArticleAuthorService($this->db))->available();
        $where = ['c.pubblico = 1'];
        $params = [];
        // Articles carry a genre from emeroteca 1.12.0 (#455). The same match
        // as the books': the genre itself, or a child or grandchild of it. An
        // older plugin without the column keeps articles out of a genre filter.
        if (!empty($filters['genere_id'])) {
            if (!$this->articlesHaveGenre()) { return null; }
            $genreId = (string) (int) $filters['genere_id'];
            $where[] = 'c.genere_id IN (SELECT gx.id FROM generi gx LEFT JOIN generi gxp ON gxp.id = gx.parent_id WHERE gx.id = ? OR gx.parent_id = ? OR gxp.parent_id = ?)';
            array_push($params, $genreId, $genreId, $genreId);
        }
        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            // Same word-wise semantics as the book index; punctuation in an
            // inverted personal name must not prevent a natural-order search.
            $words = preg_split('/[^\p{L}\p{N}_%]+/u', mb_substr($term, 0, 200), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_slice($words, 0, 20) as $word) {
                $textMatch = "CONCAT_WS(' ', c.titolo, c.sottotitolo, c.autori, c.contenitore_titolo, c.keywords, c.abstract, c.issn) LIKE ? ESCAPE '='";
                $pattern = '%' . strtr($word, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
                $params[] = $pattern;
                if ($linkedAuthors) {
                    $textMatch = "($textMatch OR EXISTS (SELECT 1 FROM emeroteca_contributi_autori ca JOIN autori a ON a.id=ca.autore_id WHERE ca.contributo_id=c.id AND CONCAT_WS(' ',a.nome,a.pseudonimo) LIKE ? ESCAPE '='))";
                    $params[] = $pattern;
                }
                $where[] = $textMatch;
            }
        }
        foreach (['anno_min' => '>=', 'anno_max' => '<='] as $key => $operator) {
            if (!empty($filters[$key])) {
                $where[] = "c.anno_pubblicazione $operator ?";
                $params[] = (string) $filters[$key];
            }
        }
        $names = [];
        // The author archive passes every same-named identity (`autore_ids`,
        // archive-only); the catalogue filter passes a single `autore_id`.
        $authorIds = [];
        if (!empty($filters['autore_ids']) && is_array($filters['autore_ids'])) {
            $authorIds = array_values(array_unique(array_filter(array_map('intval', $filters['autore_ids']), static fn(int $id): bool => $id > 0)));
        } elseif (!empty($filters['autore_id'])) {
            $authorIds = [(int)$filters['autore_id']];
        }
        if ($authorIds !== [] && $linkedAuthors) {
            $idMarks = implode(',', array_fill(0, count($authorIds), '?'));
            $where[] = "EXISTS (SELECT 1 FROM emeroteca_contributi_autori ca WHERE ca.contributo_id=c.id AND ca.autore_id IN ($idMarks))";
            foreach ($authorIds as $authorIdValue) { $params[] = (string)$authorIdValue; }
        } elseif ($authorIds !== []) {
            $idMarks = implode(',', array_fill(0, count($authorIds), '?'));
            $authors = $this->rows("SELECT nome, pseudonimo FROM autori WHERE id IN ($idMarks)", str_repeat('i', count($authorIds)), $authorIds);
            foreach ($authors as $authorRow) {
                foreach ([$authorRow['nome'] ?? null, $authorRow['pseudonimo'] ?? null] as $name) {
                    if (is_string($name) && trim($name) !== '') { $names[] = $name; }
                }
            }
            $names = array_values(array_unique($names));
            if ($names === []) { $where[] = '1=0'; }
        } elseif (!empty($filters['autore'])) {
            $names = [(string)$filters['autore']];
        }
        if ($names !== []) {
            $pattern = self::authorPattern($names);
            $match = 'c.autori REGEXP ?';
            $params[] = $pattern;
            if ($linkedAuthors) {
                $match = "($match OR EXISTS (SELECT 1 FROM emeroteca_contributi_autori ca JOIN autori a ON a.id=ca.autore_id WHERE ca.contributo_id=c.id AND (a.nome REGEXP ? OR a.pseudonimo REGEXP ?)))";
                array_push($params, $pattern, $pattern);
            }
            $where[] = $match;
        }
        $articleFrom = ' FROM emeroteca_contributi c WHERE ' . implode(' AND ', $where);
        $types = str_repeat('s', count($params));
        return [$articleFrom, $types, $params];
    }

    /** Confirmed article authors for the shared catalogue's remove-self facet.
     * @param array<string,mixed> $filters @return list<array<string,mixed>>
     */
    public function authorFacets(array $filters): array
    {
        if (!(new ArticleAuthorService($this->db))->available()) { return []; }
        $filters['autore_id'] = 0;
        $query = $this->articleQuery($filters);
        if ($query === null) { return []; }
        [$from, $types, $params] = $query;
        $from = str_replace(' FROM emeroteca_contributi c WHERE ', ' FROM emeroteca_contributi c JOIN emeroteca_contributi_autori credited ON credited.contributo_id=c.id JOIN autori identity_author ON identity_author.id=credited.autore_id WHERE ', $from);
        $display = \App\Support\AuthorName::displaySql('identity_author');
        return $this->rows("SELECT identity_author.id, $display nome, COUNT(DISTINCT c.id) cnt $from GROUP BY identity_author.id,identity_author.nome,identity_author.pseudonimo ORDER BY nome LIMIT 100", $types, $params);
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Unified catalogue prepare failed');
        }
        try {
            if ($types !== '' && !$stmt->bind_param($types, ...$params)) {
                throw new \RuntimeException('Unified catalogue parameter binding failed');
            }
            if (!$stmt->execute()) {
                throw new \RuntimeException('Unified catalogue execute failed');
            }
            $result = $stmt->get_result();
            if ($result === false) {
                throw new \RuntimeException('Unified catalogue result unavailable');
            }
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $stmt->close();
        }
    }
}
