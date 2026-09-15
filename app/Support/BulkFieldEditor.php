<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BookRepository;
use App\Models\PublisherRepository;

/**
 * Set one field on many books at once (issue #380).
 *
 * After an import a whole shelf often needs the same correction: the
 * illustrator nobody filled in, a publisher spelled differently, a genre that
 * belongs to all of them. Doing it book by book is the reason people stop
 * correcting their catalogue.
 *
 * Two modes, because "change this field" means two different things:
 *
 *   ADD      adds the value without discarding what is there. For a list
 *            (contributors, publishers) it appends and never duplicates; for a
 *            single-valued field (genre) it fills only the books that have
 *            none, so a bulk fill can never silently overwrite a curated
 *            choice.
 *   REPLACE  makes the value the only one: the given names become that role's
 *            whole credit list, the given publisher the only publisher, the
 *            given genre the genre of every selected book.
 *
 * The relational writes go through {@see BookRepository::syncRelations()}, the
 * same code the single-book form uses, so a bulk edit inherits its role
 * ownership rules, its release of import provenance (a manual choice survives
 * the next re-import) and its refresh of the denormalized contributor columns
 * that CSV export and the public API still read.
 *
 * Everything happens in one transaction: either the whole selection is applied
 * or none of it is.
 */
final class BulkFieldEditor
{
    public const MODE_ADD = 'add';
    public const MODE_REPLACE = 'replace';

    /** The same ceiling as the other bulk actions: a selection, not a migration. */
    public const MAX_BOOKS = 500;

    /**
     * Editable fields and how each one is stored.
     *
     * `roles` lists the libri_autori roles the field owns — the creator picker
     * owns both principale and co-autore, exactly as the book form does.
     * `payload` is the key BookRepository expects for that picker.
     *
     * @var array<string, array{kind: string, roles?: list<string>, payload?: string}>
     */
    public const FIELDS = [
        'autori' => ['kind' => 'contributor', 'roles' => ['principale', 'co-autore'], 'payload' => 'autori_ids'],
        'illustratori' => ['kind' => 'contributor', 'roles' => ['illustratore'], 'payload' => 'illustratori_ids'],
        'traduttori' => ['kind' => 'contributor', 'roles' => ['traduttore'], 'payload' => 'traduttori_ids'],
        'curatori' => ['kind' => 'contributor', 'roles' => ['curatore'], 'payload' => 'curatori_ids'],
        'coloristi' => ['kind' => 'contributor', 'roles' => ['colorista'], 'payload' => 'coloristi_ids'],
        'editore' => ['kind' => 'publisher'],
        'genere' => ['kind' => 'genre'],
    ];

    private function __construct()
    {
    }

    /**
     * @param list<int|string> $bookIds
     * @return array{changed: int, unchanged: int, missing: int, created: int, field: string, mode: string}
     * @throws \InvalidArgumentException on anything the operator can fix
     * @throws \RuntimeException on a database failure (nothing is written)
     */
    public static function apply(
        \mysqli $db,
        array $bookIds,
        string $field,
        string $value,
        string $mode,
        ?int $operatorId = null
    ): array {
        if (!isset(self::FIELDS[$field])) {
            throw new \InvalidArgumentException(__('Campo non modificabile in blocco.'));
        }
        if (!in_array($mode, [self::MODE_ADD, self::MODE_REPLACE], true)) {
            throw new \InvalidArgumentException(__('Scegli se aggiungere o sostituire il valore.'));
        }
        $value = trim($value);
        if ($value === '') {
            // Emptying a field across a whole selection is a different and far
            // more destructive operation: it is deliberately not offered here.
            throw new \InvalidArgumentException(__('Inserisci un valore da applicare.'));
        }

        $ids = [];
        foreach ($bookIds as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            throw new \InvalidArgumentException(__('Nessun libro selezionato'));
        }
        if (count($ids) > self::MAX_BOOKS) {
            throw new \InvalidArgumentException(sprintf(__('Puoi modificare al massimo %d libri per volta.'), self::MAX_BOOKS));
        }

        // Soft-deleted books are out of the catalogue: they must not be edited,
        // and the caller is told how many of its ids went nowhere.
        $existing = self::existingBookIds($db, $ids);
        $missing = count($ids) - count($existing);
        if ($existing === []) {
            return self::report(0, 0, $missing, 0, $field, $mode);
        }

        $kind = self::FIELDS[$field]['kind'];
        $created = 0;

        $wanted = [];
        /** @var array{genere_id: int, sottogenere_id: int|null} $genre */
        $genre = ['genere_id' => 0, 'sottogenere_id' => null];

        $repo = new BookRepository($db);
        $ownsTransaction = !self::hasActiveTransaction($db);
        if ($ownsTransaction && !$db->begin_transaction()) {
            throw new \RuntimeException('Bulk edit could not start a transaction');
        }

        $changed = [];
        try {
            // Resolving a name CREATES the author or publisher when it is new,
            // so it belongs inside the transaction: resolved before it, a later
            // failure would roll the books back and leave the new entity behind,
            // which is neither "one transaction" nor "nothing is written".
            // Still resolved once for the whole batch — a name typed for 300
            // books must create a single author, not one per book.
            if ($kind === 'contributor') {
                $resolved = ContributorSync::resolveNameIds($db, $value);
                $wanted = $resolved['ids'];
                $created = $resolved['created'];
                if ($wanted === []) {
                    throw new \InvalidArgumentException(__('Nessun nome valido da applicare.'));
                }
            } elseif ($kind === 'publisher') {
                $publishers = new PublisherRepository($db);
                $publisherId = $publishers->findByName($value);
                if ($publisherId === null) {
                    $publisherId = $publishers->create(['nome' => $value, 'sito_web' => '']);
                    $created = 1;
                }
                if ($publisherId <= 0) {
                    throw new \RuntimeException('Bulk edit could not resolve the publisher');
                }
                $wanted = [$publisherId];
            } else {
                $genre = self::resolveGenre($db, $value);
            }

            foreach ($existing as $bookId) {
                $before = ActivityLog::loadBookSnapshot($db, $bookId);
                $beforeExtra = [];
                $afterExtra = [];

                if ($kind === 'contributor') {
                    $beforeExtra[$field] = self::contributorNames($db, $bookId, $field);
                    $touched = self::applyContributor($db, $repo, $bookId, $field, $wanted, $mode);
                    $afterExtra[$field] = self::contributorNames($db, $bookId, $field);
                } elseif ($kind === 'publisher') {
                    $touched = self::applyPublisher($db, $repo, $bookId, $wanted[0], $mode);
                } else {
                    $touched = self::applyGenre($db, $bookId, $genre, $mode);
                }
                if (!$touched) {
                    continue;
                }

                $changed[] = $bookId;
                $after = ActivityLog::loadBookSnapshot($db, $bookId);
                ActivityLog::recordBookEvent(
                    $db,
                    $bookId,
                    'aggiornamento',
                    'edit',
                    'book.bulk_edited',
                    $before + $beforeExtra,
                    $after + $afterExtra,
                    $operatorId,
                    bookTitle: (string) ($after['titolo'] ?? $before['titolo'] ?? ''),
                    source: 'bulk-edit'
                );
            }

            if ($changed !== []) {
                // Rebuilds the denormalized FULLTEXT index and, through it, the
                // catalogue's author projection — inside the transaction, so a
                // committed book is never searchable under its old authors.
                SearchIndexBuilder::rebuildMany($db, $changed);
            }

            if ($ownsTransaction && !$db->commit()) {
                throw new \RuntimeException('Bulk edit could not commit');
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                // Also undoes an author or publisher created a moment ago: the
                // resolution above runs inside this transaction precisely so a
                // refused batch leaves no new entity behind.
                $db->rollback();
            }
            // A value the operator can fix is an answer, not an incident: only
            // real failures are worth a log line.
            if (!$e instanceof \InvalidArgumentException) {
                SecureLogger::error('[BulkFieldEditor] bulk edit failed', [
                    'field' => $field,
                    'mode' => $mode,
                    'books' => count($existing),
                    'error' => $e->getMessage(),
                ]);
            }
            throw $e;
        }

        if ($changed !== []) {
            ContentCache::booksChanged();
        }

        return self::report(count($changed), count($existing) - count($changed), $missing, $created, $field, $mode);
    }

    /**
     * @return array{changed: int, unchanged: int, missing: int, created: int, field: string, mode: string}
     */
    private static function report(int $changed, int $unchanged, int $missing, int $created, string $field, string $mode): array
    {
        return [
            'changed' => $changed,
            'unchanged' => $unchanged,
            'missing' => $missing,
            'created' => $created,
            'field' => $field,
            'mode' => $mode,
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function existingBookIds(\mysqli $db, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::rows(
            $db,
            "SELECT id FROM libri WHERE id IN ($placeholders) AND deleted_at IS NULL ORDER BY id",
            str_repeat('i', count($ids)),
            $ids
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * True when the book's credit list for this role actually changed.
     *
     * @param list<int> $wanted
     */
    private static function applyContributor(
        \mysqli $db,
        BookRepository $repo,
        int $bookId,
        string $field,
        array $wanted,
        string $mode
    ): bool {
        $current = self::contributorIds($db, $bookId, $field);
        $target = $mode === self::MODE_REPLACE
            ? $wanted
            : array_values(array_unique([...$current, ...$wanted]));

        $before = $current;
        $after = $target;
        sort($before);
        sort($after);
        if ($before === $after) {
            return false;
        }

        $repo->syncRelations($bookId, [(string) self::FIELDS[$field]['payload'] => $target]);
        self::touch($db, $bookId);
        return true;
    }

    private static function applyPublisher(\mysqli $db, BookRepository $repo, int $bookId, int $publisherId, string $mode): bool
    {
        $current = self::publisherIds($db, $bookId);
        // ADD keeps the existing publishers and their order, so the first one —
        // the primary — stays primary: a co-publisher is added, not promoted.
        $target = $mode === self::MODE_REPLACE
            ? [$publisherId]
            : array_values(array_unique([...$current, $publisherId]));
        if ($current === $target) {
            return false;
        }

        // Before the multi-publisher migration there is nowhere to put a second
        // publisher: syncPublishers() returns without writing. Adding one to a
        // book that already has its primary would then change nothing at all,
        // and reporting it as changed would log an audit event and rebuild the
        // index over a write that never happened.
        if ($mode === self::MODE_ADD
            && !self::tableExists($db, 'libri_editori')
            && $current !== []
        ) {
            return false;
        }

        $repo->syncRelations($bookId, ['editori_ids' => $target]);
        // libri.editore_id is the primary publisher; syncPublishers only owns
        // the junction, so the caller keeps the two in step (as updateBasic does).
        $primary = $target[0];
        self::run($db, 'UPDATE libri SET editore_id = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL', 'ii', [$primary, $bookId]);
        return true;
    }

    /** @param array{genere_id: int, sottogenere_id: int|null} $genre */
    private static function applyGenre(\mysqli $db, int $bookId, array $genre, string $mode): bool
    {
        $rows = self::rows($db, 'SELECT genere_id, sottogenere_id FROM libri WHERE id = ? AND deleted_at IS NULL', 'i', [$bookId]);
        if ($rows === []) {
            // Soft-deleted between the selection and this loop: report it as
            // untouched rather than reading a row that is not there and then
            // claiming a change the UPDATE could not make.
            return false;
        }
        $current = (int) ($rows[0]['genere_id'] ?? 0);
        $currentSub = $rows[0]['sottogenere_id'] === null ? null : (int) $rows[0]['sottogenere_id'];

        // ADD fills a gap; it never overrules a classification already chosen.
        if ($mode === self::MODE_ADD && $current > 0) {
            return false;
        }
        if ($current === $genre['genere_id'] && $currentSub === $genre['sottogenere_id']) {
            return false;
        }

        // The stored sub-genre hangs off the previous genre: replacing the genre
        // without clearing it would leave the book filed under a branch it no
        // longer belongs to. NULL unless the operator picked a sub-genre.
        $sub = $genre['sottogenere_id'];
        self::run(
            $db,
            'UPDATE libri SET genere_id = ?, sottogenere_id = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL',
            'iii',
            [$genre['genere_id'], $sub, $bookId]
        );
        return true;
    }

    /**
     * Resolve the genre the operator picked, by id or by exact name.
     *
     * Genres are a curated taxonomy, so an unknown name is refused rather than
     * created: a typo would otherwise become a genre applied to the whole
     * selection.
     *
     * Picking a sub-genre is allowed and behaves exactly as it does in the book
     * form: the child goes to `sottogenere_id` and its parent becomes the genre,
     * instead of a child id landing in `genere_id` and breaking the hierarchy.
     *
     * @return array{genere_id: int, sottogenere_id: int|null}
     */
    private static function resolveGenre(\mysqli $db, string $value): array
    {
        $row = null;
        if (ctype_digit($value)) {
            $byId = self::rows($db, 'SELECT id, parent_id FROM generi WHERE id = ?', 'i', [(int) $value]);
            $row = $byId[0] ?? null;
        }
        if ($row === null) {
            $byName = self::rows($db, 'SELECT id, parent_id FROM generi WHERE nome = ? ORDER BY id LIMIT 1', 's', [$value]);
            $row = $byName[0] ?? null;
        }
        if ($row === null) {
            throw new \InvalidArgumentException(sprintf(__('Genere non trovato: %s'), $value));
        }

        $id = (int) $row['id'];
        $parent = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        return $parent > 0
            ? ['genere_id' => $parent, 'sottogenere_id' => $id]
            : ['genere_id' => $id, 'sottogenere_id' => null];
    }

    /** @return list<int> */
    private static function contributorIds(\mysqli $db, int $bookId, string $field): array
    {
        $roles = self::FIELDS[$field]['roles'] ?? [];
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $rows = self::rows(
            $db,
            "SELECT DISTINCT autore_id FROM libri_autori WHERE libro_id = ? AND ruolo IN ($placeholders) ORDER BY autore_id",
            'i' . str_repeat('s', count($roles)),
            [$bookId, ...$roles]
        );
        return array_map(static fn (array $row): int => (int) $row['autore_id'], $rows);
    }

    /** Names of the current credit list, for the audit trail. */
    private static function contributorNames(\mysqli $db, int $bookId, string $field): string
    {
        $roles = self::FIELDS[$field]['roles'] ?? [];
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $rows = self::rows(
            $db,
            'SELECT DISTINCT a.nome FROM libri_autori la JOIN autori a ON a.id = la.autore_id '
            . "WHERE la.libro_id = ? AND la.ruolo IN ($placeholders) ORDER BY a.nome",
            'i' . str_repeat('s', count($roles)),
            [$bookId, ...$roles]
        );
        return implode('; ', array_map(static fn (array $row): string => (string) $row['nome'], $rows));
    }

    /**
     * Current publishers in their stored order. Falls back to the primary
     * publisher on installs that predate the multi-publisher junction, so an
     * ADD there appends instead of silently dropping the existing one.
     *
     * @return list<int>
     */
    private static function publisherIds(\mysqli $db, int $bookId): array
    {
        $primary = (int) (self::column($db, 'SELECT COALESCE(editore_id, 0) FROM libri WHERE id = ? AND deleted_at IS NULL', 'i', [$bookId]) ?? 0);
        if (!self::tableExists($db, 'libri_editori')) {
            return $primary > 0 ? [$primary] : [];
        }
        $rows = self::rows(
            $db,
            'SELECT editore_id FROM libri_editori WHERE libro_id = ? ORDER BY COALESCE(ordine, 0), editore_id',
            'i',
            [$bookId]
        );
        $ids = array_map(static fn (array $row): int => (int) $row['editore_id'], $rows);
        if ($ids === [] && $primary > 0) {
            return [$primary];
        }
        return $ids;
    }

    private static function touch(\mysqli $db, int $bookId): void
    {
        self::run($db, 'UPDATE libri SET updated_at = NOW() WHERE id = ? AND deleted_at IS NULL', 'i', [$bookId]);
    }

    /** @param list<mixed> $params */
    private static function run(\mysqli $db, string $sql, string $types, array $params): void
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Bulk edit statement failed to prepare: ' . $db->error);
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Bulk edit statement failed: ' . $error);
        }
        $stmt->close();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private static function rows(\mysqli $db, string $sql, string $types, array $params): array
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Bulk edit query failed to prepare: ' . $db->error);
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Bulk edit query failed: ' . $error);
        }
        $result = $stmt->get_result();
        $out = $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        /** @var list<array<string, mixed>> $out */
        return $out;
    }

    /** @param list<mixed> $params */
    private static function column(\mysqli $db, string $sql, string $types, array $params): mixed
    {
        $rows = self::rows($db, $sql, $types, $params);
        if ($rows === []) {
            return null;
        }
        return array_values($rows[0])[0] ?? null;
    }

    private static function tableExists(\mysqli $db, string $table): bool
    {
        $found = self::column(
            $db,
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            's',
            [$table]
        );
        return $found !== null;
    }

    /**
     * Detect both autocommit(false) and an explicit begin_transaction(): the
     * latter leaves @@autocommit at 1, and nesting begin_transaction() would
     * implicitly commit the caller's work. Outside a transaction SAVEPOINT is
     * accepted but discarded immediately, so ROLLBACK TO cannot find it.
     */
    private static function hasActiveTransaction(\mysqli $db): bool
    {
        $result = $db->query('SELECT @@autocommit AS ac');
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ((int) ($row['ac'] ?? 1) === 0) {
                return true;
            }
        }

        $probe = 'pinakes_bulk_probe_' . bin2hex(random_bytes(6));
        try {
            if (!$db->query("SAVEPOINT {$probe}")) {
                return false;
            }
            if (!$db->query("ROLLBACK TO SAVEPOINT {$probe}")) {
                return false;
            }
            $db->query("RELEASE SAVEPOINT {$probe}");
            return true;
        } catch (\mysqli_sql_exception) {
            return false;
        }
    }
}
