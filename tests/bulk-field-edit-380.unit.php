<?php
declare(strict_types=1);

/**
 * Issue #380 — manual bulk edit of one field across a selection.
 *
 * Behavioural, against the real schema: what the operator is promised is that
 * "add" never loses what is already there, that "replace" really replaces, and
 * that a bulk edit leaves a book in exactly the state the single-book form
 * would have left it in. That last promise is the one a reimplementation
 * quietly breaks — the denormalized contributor columns that CSV export and
 * the public API still read, the import provenance that has to be released so
 * a manual choice survives the next re-import, the FULLTEXT index that would
 * otherwise keep finding the old authors.
 *
 * Run:  php tests/bulk-field-edit-380.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\BulkFieldEditor;
use App\Support\ContributorSync;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

try {
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    echo "SKIP: no database reachable: {$e->getMessage()}\n";
    exit(0);
}

$token = bin2hex(random_bytes(4));
$mark = static fn (string $what): string => "ZZ380 {$what} {$token}";

$bookIds = [];
$genreIds = [];

$col = static function (string $sql, array $params = [], string $types = '') use ($db) {
    $stmt = $db->prepare($sql);
    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row === null ? null : $row[0];
};
$all = static function (string $sql, array $params = [], string $types = '') use ($db): array {
    $stmt = $db->prepare($sql);
    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};
$newBook = static function (string $title) use ($db, &$bookIds): int {
    $stmt = $db->prepare('INSERT INTO libri (titolo) VALUES (?)');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $stmt->close();
    $id = (int) $db->insert_id;
    $bookIds[] = $id;
    return $id;
};
$newGenre = static function (string $name, ?int $parent = null) use ($db, &$genreIds): int {
    $stmt = $db->prepare('INSERT INTO generi (nome, parent_id) VALUES (?, ?)');
    $stmt->bind_param('si', $name, $parent);
    $stmt->execute();
    $stmt->close();
    $id = (int) $db->insert_id;
    $genreIds[] = $id;
    return $id;
};
/** @return list<string> */
$namesFor = static function (int $bookId, string $role) use ($all): array {
    $rows = $all(
        'SELECT a.nome FROM libri_autori la JOIN autori a ON a.id = la.autore_id '
        . 'WHERE la.libro_id = ? AND la.ruolo = ? ORDER BY a.nome',
        [$bookId, $role],
        'is'
    );
    return array_map(static fn (array $r): string => (string) $r['nome'], $rows);
};

try {
    // ── Contributors ────────────────────────────────────────────────────────
    echo "A. Contributors\n";

    $bookA = $newBook($mark('A'));
    $bookB = $newBook($mark('B'));
    // bookA already credits one author: "add" must not cost it.
    $stmt = $db->prepare('INSERT INTO autori (nome) VALUES (?)');
    $existingAuthor = $mark('Autore Uno');
    $stmt->bind_param('s', $existingAuthor);
    $stmt->execute();
    $stmt->close();
    $existingAuthorId = (int) $db->insert_id;
    $db->query("INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES ({$bookA}, {$existingAuthorId}, 'principale', 0)");

    $res = BulkFieldEditor::apply($db, [$bookA, $bookB], 'autori', $mark('Autore Due'), BulkFieldEditor::MODE_ADD);
    $check($res['changed'] === 2, 'add credits both selected books');
    $check($res['created'] === 1, 'a name typed once creates exactly one author for the whole batch');
    $check($namesFor($bookA, 'principale') === [$mark('Autore Due'), $mark('Autore Uno')], 'add keeps the existing author and appends the new one');
    $check($namesFor($bookB, 'principale') === [$mark('Autore Due')], 'a book with no authors gets the new one');

    $again = BulkFieldEditor::apply($db, [$bookA, $bookB], 'autori', $mark('Autore Due'), BulkFieldEditor::MODE_ADD);
    $check($again['changed'] === 0 && $again['unchanged'] === 2, 'applying the same value twice changes nothing and says so');
    $check($again['created'] === 0, 'the second run reuses the author instead of creating a twin');

    $res = BulkFieldEditor::apply($db, [$bookA], 'autori', $mark('Autore Tre'), BulkFieldEditor::MODE_REPLACE);
    $check($res['changed'] === 1 && $namesFor($bookA, 'principale') === [$mark('Autore Tre')], 'replace leaves only the given author');

    // Several names in one go, with the separator the modal documents.
    $res = BulkFieldEditor::apply($db, [$bookB], 'illustratori', $mark('Ill Uno') . '; ' . $mark('Ill Due'), BulkFieldEditor::MODE_REPLACE);
    $check($res['changed'] === 1 && count($namesFor($bookB, 'illustratore')) === 2, 'a semicolon-separated value credits every name');

    // The trap: libri.illustratore is a denormalized copy that CSV export and
    // the public API still read. A bulk edit that only wrote the junction would
    // leave it stale, and the book would export without its illustrators.
    $cached = (string) $col('SELECT COALESCE(illustratore, "") FROM libri WHERE id = ?', [$bookB], 'i');
    $check(str_contains($cached, $mark('Ill Uno')) && str_contains($cached, $mark('Ill Due')), 'the denormalized illustratore column is refreshed too');

    // Provenance: an imported link that the operator confirms by hand must stop
    // belonging to the importer, or the next re-import deletes it as stale.
    $bookC = $newBook($mark('C'));
    ContributorSync::syncImportedLegacyValues($db, $bookC, ['traduttore' => $mark('Trad Imp')], 'csv');
    $ownedBefore = (int) $col('SELECT COUNT(*) FROM libri_autori_import_sources WHERE libro_id = ? AND ruolo = ?', [$bookC, 'traduttore'], 'is');
    BulkFieldEditor::apply($db, [$bookC], 'traduttori', $mark('Trad Manuale'), BulkFieldEditor::MODE_ADD);
    $ownedAfter = (int) $col('SELECT COUNT(*) FROM libri_autori_import_sources WHERE libro_id = ? AND ruolo = ?', [$bookC, 'traduttore'], 'is');
    $check($ownedBefore === 1 && $ownedAfter === 0, 'a manual bulk edit releases the importer ownership of that role');
    $check(count($namesFor($bookC, 'traduttore')) === 2, 'and keeps the imported translator alongside the new one');

    // The catalogue must find the book by its new author, not its old one.
    $indexed = (string) $col('SELECT COALESCE(search_index, "") FROM libri WHERE id = ?', [$bookA], 'i');
    $check(str_contains($indexed, $mark('Autore Tre')) && !str_contains($indexed, $mark('Autore Uno')), 'the FULLTEXT index is rebuilt on the books that changed');

    // ── Publishers ──────────────────────────────────────────────────────────
    echo "B. Publishers\n";

    $stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
    $firstPublisher = $mark('Editore Primo');
    $stmt->bind_param('s', $firstPublisher);
    $stmt->execute();
    $stmt->close();
    $firstPublisherId = (int) $db->insert_id;
    $db->query('UPDATE libri SET editore_id = ' . $firstPublisherId . ' WHERE id = ' . $bookA);
    $db->query('INSERT INTO libri_editori (libro_id, editore_id, ordine) VALUES (' . $bookA . ', ' . $firstPublisherId . ', 0)');

    $res = BulkFieldEditor::apply($db, [$bookA], 'editore', $mark('Editore Secondo'), BulkFieldEditor::MODE_ADD);
    $linked = array_map(static fn (array $r): int => (int) $r['editore_id'], $all('SELECT editore_id FROM libri_editori WHERE libro_id = ? ORDER BY ordine', [$bookA], 'i'));
    $check($res['changed'] === 1 && count($linked) === 2, 'add links a co-publisher');
    $check((int) $col('SELECT editore_id FROM libri WHERE id = ?', [$bookA], 'i') === $firstPublisherId, 'add never promotes the new publisher over the existing primary');

    $res = BulkFieldEditor::apply($db, [$bookA], 'editore', $mark('Editore Terzo'), BulkFieldEditor::MODE_REPLACE);
    $linked = array_map(static fn (array $r): int => (int) $r['editore_id'], $all('SELECT editore_id FROM libri_editori WHERE libro_id = ?', [$bookA], 'i'));
    $primaryName = (string) $col('SELECT e.nome FROM libri l JOIN editori e ON e.id = l.editore_id WHERE l.id = ?', [$bookA], 'i');
    $check($res['changed'] === 1 && count($linked) === 1 && $primaryName === $mark('Editore Terzo'), 'replace leaves one publisher and repoints the primary at it');

    // ── Genre ───────────────────────────────────────────────────────────────
    echo "C. Genre\n";

    $parentGenre = $newGenre($mark('Genere Padre'));
    $childGenre = $newGenre($mark('Genere Figlio'), $parentGenre);
    $otherGenre = $newGenre($mark('Genere Altro'));

    $bookD = $newBook($mark('D'));
    $db->query('UPDATE libri SET genere_id = ' . $otherGenre . ' WHERE id = ' . $bookD);
    $bookE = $newBook($mark('E'));

    $res = BulkFieldEditor::apply($db, [$bookD, $bookE], 'genere', (string) $parentGenre, BulkFieldEditor::MODE_ADD);
    $check($res['changed'] === 1 && $res['unchanged'] === 1, 'add fills only the book that had no genre');
    $check((int) $col('SELECT genere_id FROM libri WHERE id = ?', [$bookD], 'i') === $otherGenre, 'a genre already chosen is never overwritten by add');
    $check((int) $col('SELECT genere_id FROM libri WHERE id = ?', [$bookE], 'i') === $parentGenre, 'the empty book is classified');

    // A leftover sub-genre belongs to the previous branch.
    $db->query('UPDATE libri SET sottogenere_id = ' . $childGenre . ' WHERE id = ' . $bookD);
    $res = BulkFieldEditor::apply($db, [$bookD], 'genere', (string) $parentGenre, BulkFieldEditor::MODE_REPLACE);
    $check(
        (int) $col('SELECT genere_id FROM libri WHERE id = ?', [$bookD], 'i') === $parentGenre
        && $col('SELECT sottogenere_id FROM libri WHERE id = ?', [$bookD], 'i') === null,
        'replacing the genre clears a sub-genre that belonged to the old one'
    );

    // Picking a sub-genre behaves as in the book form: parent up, child down.
    $res = BulkFieldEditor::apply($db, [$bookE], 'genere', (string) $childGenre, BulkFieldEditor::MODE_REPLACE);
    $check(
        (int) $col('SELECT genere_id FROM libri WHERE id = ?', [$bookE], 'i') === $parentGenre
        && (int) $col('SELECT sottogenere_id FROM libri WHERE id = ?', [$bookE], 'i') === $childGenre,
        'a sub-genre lands in sottogenere_id with its parent as the genre'
    );

    $refused = false;
    try {
        BulkFieldEditor::apply($db, [$bookE], 'genere', $mark('Genere Inesistente'), BulkFieldEditor::MODE_REPLACE);
    } catch (\InvalidArgumentException) {
        $refused = true;
    }
    $check($refused && (int) $col('SELECT genere_id FROM libri WHERE id = ?', [$bookE], 'i') === $parentGenre, 'an unknown genre is refused instead of invented, and nothing is written');

    // ── Guards ──────────────────────────────────────────────────────────────
    echo "D. Guards and scope\n";

    $refusals = [
        'unknown field' => static fn () => BulkFieldEditor::apply($db, [$bookE], 'titolo', 'x', BulkFieldEditor::MODE_ADD),
        'unknown mode' => static fn () => BulkFieldEditor::apply($db, [$bookE], 'autori', 'x', 'clear'),
        'empty value' => static fn () => BulkFieldEditor::apply($db, [$bookE], 'autori', '   ', BulkFieldEditor::MODE_ADD),
        'no selection' => static fn () => BulkFieldEditor::apply($db, [], 'autori', 'x', BulkFieldEditor::MODE_ADD),
        'over the ceiling' => static fn () => BulkFieldEditor::apply($db, range(1, BulkFieldEditor::MAX_BOOKS + 1), 'autori', 'x', BulkFieldEditor::MODE_ADD),
    ];
    foreach ($refusals as $label => $call) {
        $threw = false;
        try {
            $call();
        } catch (\InvalidArgumentException) {
            $threw = true;
        }
        $check($threw, "refused: {$label}");
    }

    // A soft-deleted book is out of the catalogue: it is reported, not edited.
    $deleted = $newBook($mark('Cancellato'));
    $db->query('UPDATE libri SET deleted_at = NOW() WHERE id = ' . $deleted);
    $res = BulkFieldEditor::apply($db, [$deleted, $bookE], 'curatori', $mark('Curatore'), BulkFieldEditor::MODE_ADD);
    $check($res['missing'] === 1 && $res['changed'] === 1, 'a soft-deleted book is counted as missing, not edited');
    $check($namesFor($deleted, 'curatore') === [], 'and nothing was written to it');

    // ── One transaction ─────────────────────────────────────────────────────
    echo "E. Atomicity\n";

    // Called inside a caller's transaction, the whole edit must live or die
    // with it: an inner begin_transaction() would have committed it early and
    // the rollback below would find the change still there.
    $bookF = $newBook($mark('F'));
    $db->begin_transaction();
    BulkFieldEditor::apply($db, [$bookF], 'autori', $mark('Autore Transitorio'), BulkFieldEditor::MODE_ADD);
    $db->rollback();
    $check($namesFor($bookF, 'principale') === [], 'the edit joins the caller transaction and disappears with its rollback');

    // ── Audit trail ─────────────────────────────────────────────────────────
    echo "F. Audit trail\n";

    $events = (int) $col(
        "SELECT COUNT(*) FROM log_modifiche WHERE tabella = 'libri' AND record_id = ? AND dati_nuovi LIKE '%book.bulk_edited%'",
        [$bookB],
        'i'
    );
    $check($events > 0, 'every edited book leaves a bulk-edit entry in the activity log');
    $logged = (string) $col(
        "SELECT dati_nuovi FROM log_modifiche WHERE tabella = 'libri' AND record_id = ? AND dati_nuovi LIKE '%book.bulk_edited%' ORDER BY id DESC LIMIT 1",
        [$bookB],
        'i'
    );
    $check(str_contains($logged, $mark('Ill Uno')), 'the entry records the credit list, which lives outside the libri row');
} finally {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach (array_reverse($genreIds) as $id) {
        $db->query("DELETE FROM generi WHERE id = {$id}");
    }
    $prefix = "ZZ380 %{$token}";
    foreach (['autori', 'editori'] as $table) {
        $stmt = $db->prepare("DELETE FROM {$table} WHERE nome LIKE ?");
        $stmt->bind_param('s', $prefix);
        $stmt->execute();
        $stmt->close();
    }
    $db->close();
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
