<?php
declare(strict_types=1);

namespace App\Support\Marc;

use App\Controllers\CsvImportController;

/**
 * Writes the book data of a MARC record (bulk MARC import, copy cataloguing)
 * into the catalogue through the CSV import's own row pipeline
 * (CsvImportController::importParsedRow()): same publisher, author and
 * contributor resolution — the #501 lookups of AuthorRepository and
 * PublisherRepository, so an exact spelling on file wins — same copies,
 * activity log, hooks and search index.
 *
 * A record that matches a book already in the catalogue — by ISBN-13,
 * ISBN-10 (either form of the same ISBN) or EAN, among books not deleted
 * (a deleted book has those columns emptied); by title and first author when
 * the record has no identifier — is handled by the mode:
 *
 *   skip       the book is left as it is
 *   fill       only the fields the book has empty are filled from the record
 *              (BookDataMerger decides what is empty); a field the book has
 *              is never overwritten
 *   overwrite  every field the record carries replaces the book's; a field
 *              the record does not carry is kept
 *
 * The format and the price are never read from a record. The media type
 * (leader/06) is: in overwrite mode it replaces the book's, in fill mode it
 * is left, since a book always has one (libri.tipo_media is NOT NULL with
 * the default 'libro').
 *
 * The columns the CSV pipeline does not write (place of publication,
 * dimensions, LC classification, ISSN, the book's own RDA codes) and the
 * cover (856) follow the same mode.
 */
final class MarcBookImporter
{
    public const MODE_SKIP = 'skip';
    public const MODE_FILL = 'fill';
    public const MODE_OVERWRITE = 'overwrite';
    public const MODES = [self::MODE_SKIP, self::MODE_FILL, self::MODE_OVERWRITE];

    /** CSV update family → the libri columns that tell whether it is empty. */
    private const FAMILY_COLUMNS = [
        'isbn' => ['isbn13', 'isbn10'],
        'ean' => ['ean'],
        'titolo' => ['titolo'],
        'sottotitolo' => ['sottotitolo'],
        'anno' => ['anno_pubblicazione'],
        'lingua' => ['lingua'],
        'edizione' => ['edizione'],
        'pagine' => ['numero_pagine'],
        'collana' => ['collana', 'numero_serie'],
        'dewey' => ['classificazione_dewey'],
        'keywords' => ['parole_chiave'],
        'description' => ['descrizione'],
        'publisher' => ['editore_id'],
        'genre' => ['genere_id'],
        'contributors' => ['traduttore', 'illustratore', 'curatore'],
    ];

    /** CSV update family → the book data keys that carry it. */
    private const FAMILY_DATA = [
        'isbn' => ['isbn13', 'isbn10'],
        'ean' => ['ean'],
        'titolo' => ['titolo'],
        'sottotitolo' => ['sottotitolo'],
        'anno' => ['anno_pubblicazione'],
        'lingua' => ['lingua'],
        'edizione' => ['edizione'],
        'pagine' => ['numero_pagine'],
        // A record with only the number in the series (225/490 $v) still
        // carries the family: the book's series name is kept, its number filled.
        'collana' => ['collana', 'numero_serie'],
        'dewey' => ['classificazione_dewey'],
        'keywords' => ['parole_chiave'],
        'description' => ['descrizione'],
        'publisher' => ['editori'],
        'genre' => ['genere'],
        'authors' => ['autori'],
        'contributors' => ['traduttore', 'illustratore', 'curatore', 'colorista'],
    ];

    /**
     * Columns written here, not by the CSV pipeline, each only when the
     * libri table has it (the RDA columns arrive with a later migration).
     */
    private const EXTRA_COLUMNS = [
        'luogo_pubblicazione' => 255,
        'dimensioni' => 50,
        'lc_classification' => 100,
        'issn' => 20,
        'rda_content_type' => 10,
        'rda_media_type' => 10,
        'rda_carrier_type' => 10,
    ];

    private CsvImportController $pipeline;

    /** @var array<string, bool>|null */
    private ?array $columns = null;

    public function __construct(
        private \mysqli $db,
        private string $mode = self::MODE_SKIP,
        private bool $downloadCovers = true,
        private ?MarcCoverFetcher $coverFetcher = null
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown duplicate mode: ' . $mode);
        }
        $this->pipeline = new CsvImportController();
        $this->pipeline->setImportSource('marc');
        $this->coverFetcher ??= new MarcCoverFetcher();
    }

    /**
     * Import one record's book data. $counters is advanced (imported,
     * updated, skipped, authors_created, publishers_created, covers); a
     * cover that cannot be fetched is reported in $warnings, not as a
     * failure. Throws when the book itself cannot be written; nothing of it
     * is then left in the catalogue. Once the book is committed, a failure
     * of the extra MARC columns, the cover or the search index is a warning.
     *
     * @param array<string, string|list<string>> $data book data (MarcBookData shape)
     * @param array<string, mixed> $counters
     * @param list<string> $warnings
     * @return array{action: string, id: int|null, title: string}
     */
    public function import(array $data, array &$counters, array &$warnings = []): array
    {
        foreach (['imported', 'updated', 'skipped', 'authors_created', 'publishers_created', 'covers'] as $key) {
            $counters[$key] = (int) ($counters[$key] ?? 0);
        }
        $parsed = $this->pipeline->normalizeImportRow(MarcBookData::toImportRow($data));
        $title = (string) ($parsed['titolo'] ?? '');
        $existingId = $this->findExisting($parsed);

        if ($existingId !== null && $this->mode === self::MODE_SKIP) {
            $counters['skipped']++;
            return ['action' => 'skipped', 'id' => $existingId, 'title' => $title];
        }
        if ($existingId === null && $title === '') {
            throw new \RuntimeException(__('Titolo mancante'));
        }

        $existing = $existingId !== null ? $this->loadBook($existingId) : null;
        if ($existingId !== null && $existing === null) {
            throw new \RuntimeException('Unable to read the existing book');
        }
        $families = [];
        if ($existing !== null) {
            [$families, $parsed] = self::plan($this->mode, $data, $parsed, $existing);
        }

        $result = $this->pipeline->importParsedRow(
            $this->db,
            $parsed,
            $families,
            $counters,
            $existingId,
            false
        );
        $bookId = $result['id'];

        // The book is committed and counted at this point: what follows only
        // completes it, so a failure here is a warning on an imported record,
        // never a failed record (that would count it twice and contradict the
        // catalogue, where the book already is).
        try {
            $this->writeExtraColumns($bookId, $data, $existing);
        } catch (\Throwable $e) {
            $warnings[] = __('Campi MARC aggiuntivi non salvati') . ': ' . $e->getMessage();
            \App\Support\SecureLogger::warning('[MARC import] extra columns not written', ['libro_id' => $bookId, 'error' => $e->getMessage()]);
        }
        $this->writeCover($bookId, (string) ($data['copertina_url'] ?? ''), $existing, $counters, $warnings);
        try {
            \App\Support\SearchIndexBuilder::rebuild($this->db, $bookId);
        } catch (\Throwable $e) {
            $warnings[] = __('Indice di ricerca non aggiornato') . ': ' . $e->getMessage();
            \App\Support\SecureLogger::warning('[MARC import] search index not rebuilt', ['libro_id' => $bookId, 'error' => $e->getMessage()]);
        }

        return ['action' => $result['action'], 'id' => $bookId, 'title' => $title];
    }

    /**
     * The book (not deleted) a record matches: by any form of its ISBN or its
     * EAN; by exact title and first author when it has neither.
     *
     * @param array<string, mixed> $parsed a row as normalizeImportRow() returns it
     */
    public function findExisting(array $parsed): ?int
    {
        $thirteen = [];
        $ten = [];
        foreach (['isbn13', 'isbn10'] as $key) {
            $isbn = (string) ($parsed[$key] ?? '');
            if ($isbn === '') {
                continue;
            }
            $variants = \App\Support\IsbnFormatter::getAllVariants($isbn);
            if (isset($variants['isbn13'])) {
                $thirteen[] = $variants['isbn13'];
            }
            if (isset($variants['isbn10'])) {
                $ten[] = $variants['isbn10'];
            }
        }
        $ean = (string) ($parsed['ean'] ?? '');
        $codes13 = array_values(array_unique(array_filter(array_merge($thirteen, [$ean]))));
        $ten = array_values(array_unique($ten));
        if ($codes13 !== [] || $ten !== []) {
            $conditions = [];
            $params = [];
            if ($codes13 !== []) {
                $in = implode(',', array_fill(0, count($codes13), '?'));
                $conditions[] = "isbn13 IN ({$in})";
                $conditions[] = "ean IN ({$in})";
                $params = array_merge($params, $codes13, $codes13);
            }
            if ($ten !== []) {
                $conditions[] = 'isbn10 IN (' . implode(',', array_fill(0, count($ten), '?')) . ')';
                $params = array_merge($params, $ten);
            }
            $stmt = $this->db->prepare('SELECT id FROM libri WHERE deleted_at IS NULL AND (' . implode(' OR ', $conditions) . ') ORDER BY id LIMIT 1');
            if ($stmt === false) {
                throw new \RuntimeException('Unable to prepare the duplicate lookup');
            }
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ? (int) $row['id'] : null;
        }

        $title = trim((string) ($parsed['titolo'] ?? ''));
        $authors = array_values(array_filter(array_map('trim', explode(';', (string) ($parsed['autori'] ?? '')))));
        if ($title === '' || $authors === []) {
            return null;
        }
        // The first author is resolved exactly as the import will link it
        // (CsvImportController::getOrCreateAuthor() -> findByCanonicalName():
        // quotes mean exact spelling, otherwise accents are folded but the
        // exact spelling wins when both are on file). Comparing names here with
        // another rule made a re-import create the same book again. The title
        // compares with accents distinct (#501), so a record never overwrites
        // a different book whose title only differs by an accent.
        $authorId = (new \App\Models\AuthorRepository($this->db))->findByCanonicalName($authors[0]);
        if ($authorId === null) {
            return null;
        }
        $titleCond = \App\Support\ExactSearch::equalsCondition('l.titolo', $title);
        $stmt = $this->db->prepare(
            'SELECT l.id FROM libri l
               JOIN libri_autori la ON la.libro_id = l.id
              WHERE l.deleted_at IS NULL AND la.autore_id = ? AND ' . $titleCond['sql'] . '
              ORDER BY l.id LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare the duplicate lookup');
        }
        $params = [$authorId, ...$titleCond['params']];
        $stmt->bind_param('i' . $titleCond['types'], ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * What an existing book gets from a record: the CSV update families the
     * record may write, and the parsed row as the pipeline must receive it.
     *
     * A family writes several columns at once (ISBN-13 and ISBN-10, series
     * and number, the legacy contributor columns). In every mode a column
     * the record leaves empty keeps the book's value. In fill mode a family
     * is written as soon as one of its columns is empty, and every column
     * the book already has is handed back to the pipeline unchanged, so a
     * non-empty field is never overwritten: a book with an ISBN-13 and no
     * ISBN-10 gains the ISBN-10 and keeps its ISBN-13 even when the record
     * names another.
     *
     * @param array<string, string|list<string>> $data book data (MarcBookData shape)
     * @param array<string, mixed> $parsed the row as normalizeImportRow() returns it
     * @param array<string, mixed> $existing the libri row (with _principal_authors)
     * @return array{0: array<string, bool>, 1: array<string, mixed>}
     */
    public static function plan(string $mode, array $data, array $parsed, array $existing): array
    {
        $carries = static function (string $family) use ($data): bool {
            foreach (self::FAMILY_DATA[$family] ?? [] as $key) {
                $value = $data[$key] ?? '';
                if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                    return true;
                }
            }
            return false;
        };
        $empty = array_flip(\App\Support\BookDataMerger::getMissingFields($existing, array_keys($existing)));
        $families = [];
        foreach (array_keys(self::FAMILY_DATA) as $family) {
            if (!$carries($family)) {
                $families[$family] = false;
                continue;
            }
            if ($mode === self::MODE_OVERWRITE) {
                $families[$family] = true;
                continue;
            }
            // fill: a family with at least one column the book has empty
            if ($family === 'authors') {
                $families[$family] = (int) ($existing['_principal_authors'] ?? 0) === 0;
                continue;
            }
            $families[$family] = array_intersect_key(array_flip(self::FAMILY_COLUMNS[$family]), $empty) !== [];
        }
        // Never from a MARC record: the format and the price. The media type
        // is its own family of the pipeline, apart from the format (which the
        // CSV import derives from the row): in overwrite mode a record that
        // carries one sets it; in fill mode it is left, because a book always
        // has a media type (libri.tipo_media is NOT NULL, default 'libro'),
        // so the family never has an empty column to fill.
        foreach (['formato', 'prezzo'] as $family) {
            $families[$family] = false;
        }
        $families['tipo_media'] = $mode === self::MODE_OVERWRITE
            && trim((string) ($data['tipo_media'] ?? '')) !== '';
        foreach (self::FAMILY_COLUMNS as $family => $columns) {
            foreach ($columns as $column) {
                if ($column === 'editore_id' || $column === 'genere_id') {
                    continue; // the pipeline resolves the publisher and the genre by name
                }
                if (isset($empty[$column]) || !array_key_exists($column, $existing)) {
                    continue;
                }
                $value = $parsed[$column] ?? null;
                $recordEmpty = $value === null || (is_string($value) && trim($value) === '');
                if ($recordEmpty || ($mode === self::MODE_FILL && $families[$family])) {
                    $parsed[$column] = $existing[$column];
                }
            }
        }
        return [$families, $parsed];
    }

    /** @return array<string, mixed>|null */
    private function loadBook(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row === null) {
            return null;
        }
        $count = $this->db->prepare("SELECT COUNT(*) FROM libri_autori WHERE libro_id = ? AND ruolo = 'principale'");
        if ($count !== false) {
            $count->bind_param('i', $id);
            $count->execute();
            $row['_principal_authors'] = (int) ($count->get_result()->fetch_row()[0] ?? 0);
            $count->close();
        }
        return $row;
    }

    /**
     * The extra MARC columns to write for one record, column => value (NULL
     * clears it). A record writes the columns it carries (in fill mode only
     * where the book has them empty). When an overwrite changes the book's
     * media type, the RDA overrides the record does not carry are cleared:
     * they described the old material (a printed book's carrier "nb" on what
     * is now a DVD), and without them the new media type implies its own
     * RDA codes.
     *
     * @param array<string, string|list<string>> $data
     * @param array<string, mixed>|null $existing
     * @param callable(string): bool $hasColumn
     * @return array<string, string|null>
     */
    public static function extraColumnUpdates(string $mode, array $data, ?array $existing, callable $hasColumn): array
    {
        $newType = trim((string) ($data['tipo_media'] ?? ''));
        $typeChanged = $existing !== null
            && $mode === self::MODE_OVERWRITE
            && $newType !== ''
            && $newType !== trim((string) ($existing['tipo_media'] ?? ''));
        $updates = [];
        // RDA types module off: imports neither set nor clear the overrides.
        $rdaOn = \App\Support\Features::enabled('rda_types');
        foreach (self::EXTRA_COLUMNS as $column => $length) {
            if (!$hasColumn($column) || (!$rdaOn && str_starts_with($column, 'rda_'))) {
                continue;
            }
            $value = trim((string) ($data[$column] ?? ''));
            if ($value === '') {
                if ($typeChanged && str_starts_with($column, 'rda_') && trim((string) ($existing[$column] ?? '')) !== '') {
                    $updates[$column] = null;
                }
                continue;
            }
            if ($existing !== null && $mode === self::MODE_FILL && trim((string) ($existing[$column] ?? '')) !== '') {
                continue;
            }
            $updates[$column] = mb_substr($value, 0, $length);
        }
        return $updates;
    }

    /**
     * @param array<string, string|list<string>> $data
     * @param array<string, mixed>|null $existing
     */
    private function writeExtraColumns(int $bookId, array $data, ?array $existing): void
    {
        $updates = self::extraColumnUpdates($this->mode, $data, $existing, fn (string $column): bool => $this->hasColumn($column));
        if ($updates === []) {
            return;
        }
        $sets = [];
        $params = [];
        foreach ($updates as $column => $value) {
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }
        $params[] = $bookId;
        $stmt = $this->db->prepare('UPDATE libri SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare the MARC column update');
        }
        $stmt->bind_param(str_repeat('s', count($params) - 1) . 'i', ...$params);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $counters
     * @param list<string> $warnings
     */
    private function writeCover(int $bookId, string $url, ?array $existing, array &$counters, array &$warnings): void
    {
        if (!$this->downloadCovers || $url === '') {
            return;
        }
        if ($existing !== null && $this->mode === self::MODE_FILL && trim((string) ($existing['copertina_url'] ?? '')) !== '') {
            return;
        }
        try {
            $path = $this->coverFetcher?->fetch($url) ?? '';
        } catch (\Throwable $e) {
            $warnings[] = __('Copertina non scaricata') . ': ' . $e->getMessage();
            return;
        }
        if ($path === '') {
            return;
        }
        // The file name is unique: if no book row ends up pointing at it,
        // nothing ever will, so it is removed instead of left on disk.
        $attached = false;
        try {
            $stmt = $this->db->prepare('UPDATE libri SET copertina_url = ? WHERE id = ? AND deleted_at IS NULL');
            if ($stmt !== false) {
                $stmt->bind_param('si', $path, $bookId);
                $stmt->execute();
                $attached = $stmt->affected_rows > 0;
                $stmt->close();
            }
        } catch (\Throwable $e) {
            if (!$attached) {
                MarcCoverFetcher::discard($path);
            }
            $warnings[] = __('Copertina non scaricata') . ': ' . $e->getMessage();
            return;
        }
        if (!$attached) {
            MarcCoverFetcher::discard($path);
            return;
        }
        $counters['covers']++;
    }

    private function hasColumn(string $column): bool
    {
        if ($this->columns === null) {
            $this->columns = [];
            $res = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri'");
            if ($res instanceof \mysqli_result) {
                while ($r = $res->fetch_row()) {
                    $this->columns[(string) $r[0]] = true;
                }
                $res->free();
            }
        }
        return isset($this->columns[$column]);
    }
}
