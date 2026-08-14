<?php
declare(strict_types=1);

/**
 * Behavioural proof for the atomic book+copies create (PR #356).
 *
 * LibriController::store() wraps BookRepository::createBasic() and
 * CopyRepository::createManyForBook() in ONE mysqli transaction, committed
 * BEFORE any plugin hook / series sync / availability recalc runs. These
 * tests drive the exact same sequence against the real database and prove:
 *
 *   1. commit persists book + exactly 1 copy together;
 *   2. 3 copies get uniform, ordered -C1/-C2/-C3 codes;
 *   3. a zero-holding record commits with 0 copie rows;
 *   4. DataIntegrity derives copie_totali/copie_disponibili post-commit;
 *   5. a REAL forced copy-insert failure (SIGNAL trigger) rolls back the
 *      book row — no orphan book, no orphan author links, no copies;
 *   6. createBasic() nests via SAVEPOINT inside the open transaction — an
 *      internal begin_transaction() would implicitly COMMIT the outer one
 *      (the mysqli nested-transaction trap) and this test would fail;
 *   7. source-order guard: in store() the commit happens BEFORE the
 *      book.save.after hook and the availability recalc, so plugin handlers
 *      that open their own transaction (book-club) can never destroy the
 *      atomicity by construction;
 *   8. two books sharing the same inventory base get collision-free codes.
 *
 * Run: php tests/atomic-book-create-356.unit.php   (exit 0 = pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli($host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — atomic-create proof is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
};

$prefix = 'U356_' . bin2hex(random_bytes(4));
$trigger = 'zz_u356_fail_copy';

/** Count libri rows by exact title, INCLUDING soft-deleted ones. */
$bookCount = static function (string $title) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM libri WHERE titolo = ?');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
};

/** Copy codes for a book, ordered by id. @return list<string> */
$copyCodes = static function (int $bookId) use ($db): array {
    $stmt = $db->prepare('SELECT numero_inventario FROM copie WHERE libro_id = ? ORDER BY id');
    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $codes = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as $row) {
        $codes[] = (string) $row[0];
    }
    $stmt->close();
    return $codes;
};

$scalar = static function (string $sql, string $types = '', array $params = []) use ($db) {
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row[0] ?? null;
};

$cleanup = static function () use ($db, $prefix, $trigger): void {
    try {
        $db->query("DROP TRIGGER IF EXISTS {$trigger}");
    } catch (Throwable) {
        // best-effort
    }
    try {
        // copie rows cascade with the book (FK ON DELETE CASCADE).
        $stmt = $db->prepare("DELETE FROM libri WHERE titolo LIKE CONCAT(?, '%')");
        $stmt->bind_param('s', $prefix);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable) {
        // best-effort
    }
};

$repo = new \App\Models\BookRepository($db);
$copyRepo = new \App\Models\CopyRepository($db);

$exitCode = 1;
try {
    /* ---------------------------------------------------------------------
     * 1. Atomic commit: book + exactly 1 copy persist together
     * ------------------------------------------------------------------- */
    $titleA = "{$prefix}_one";
    $baseA = "{$prefix}-A";
    $db->begin_transaction();
    $idA = $repo->createBasic(['titolo' => $titleA, 'copie_totali' => 1, 'copie_disponibili' => 1]);
    $createdA = $copyRepo->createManyForBook($idA, $baseA, 1, 'disponibile', 'Copy %d of %d');
    $db->commit();
    $check($createdA === 1 && $bookCount($titleA) === 1, '1. committed create persists the book row');
    $check($copyCodes($idA) === ["{$baseA}-C1"], '1. exactly one copie row with the uniform -C1 code');

    /* ---------------------------------------------------------------------
     * 2. Three copies: uniform -C1/-C2/-C3 codes
     * ------------------------------------------------------------------- */
    $titleB = "{$prefix}_three";
    $baseB = "{$prefix}-B";
    $db->begin_transaction();
    $idB = $repo->createBasic(['titolo' => $titleB, 'copie_totali' => 3, 'copie_disponibili' => 3]);
    $createdB = $copyRepo->createManyForBook($idB, $baseB, 3, 'disponibile', 'Copy %d of %d');
    $db->commit();
    $check(
        $createdB === 3 && $copyCodes($idB) === ["{$baseB}-C1", "{$baseB}-C2", "{$baseB}-C3"],
        '2. three copies committed with uniform -C1/-C2/-C3 codes'
    );

    /* ---------------------------------------------------------------------
     * 3. Zero copies: valid zero-holding record
     * ------------------------------------------------------------------- */
    $titleC = "{$prefix}_zero";
    $db->begin_transaction();
    $idC = $repo->createBasic(['titolo' => $titleC, 'copie_totali' => 0, 'copie_disponibili' => 0]);
    $createdC = $copyRepo->createManyForBook($idC, "{$prefix}-C0", 0, 'disponibile', null);
    $db->commit();
    $check(
        $createdC === 0 && $bookCount($titleC) === 1 && $copyCodes($idC) === [],
        '3. zero-copy create commits the book with 0 copie rows'
    );

    /* ---------------------------------------------------------------------
     * 4. DataIntegrity derives the counters from the committed copies
     * ------------------------------------------------------------------- */
    (new \App\Support\DataIntegrity($db))->recalculateBookAvailability($idB);
    $derived = $scalar(
        "SELECT CONCAT(copie_totali, ':', copie_disponibili, ':', stato) FROM libri WHERE id = ? AND deleted_at IS NULL",
        'i',
        [$idB]
    );
    $check($derived === '3:3:disponibile', "4. recalc derives copie_totali/disponibili/stato from copies (got: {$derived})");

    /* ---------------------------------------------------------------------
     * 5. ROLLBACK PROOF — a real forced mysqli failure during copy creation
     *    leaves NO orphan book. The SIGNAL trigger makes the multi-row copy
     *    INSERT fail exactly like a production-side insert error would.
     * ------------------------------------------------------------------- */
    $titleF = "{$prefix}_fail";
    $failBase = "{$prefix}-FAIL";
    $db->query("DROP TRIGGER IF EXISTS {$trigger}");
    // DDL implicitly commits, so the trigger is installed BEFORE the tx opens.
    $db->query(
        "CREATE TRIGGER {$trigger} BEFORE INSERT ON copie FOR EACH ROW " .
        "BEGIN IF NEW.numero_inventario LIKE '{$failBase}%' THEN " .
        "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced copy failure (test)'; " .
        "END IF; END"
    );
    $db->begin_transaction();
    $idF = $repo->createBasic(['titolo' => $titleF, 'copie_totali' => 2, 'copie_disponibili' => 2]);
    $threw = false;
    try {
        $copyRepo->createManyForBook($idF, $failBase, 2, 'disponibile', null);
    } catch (Throwable) {
        $threw = true;
    }
    // Mirror the controller's error path: rollback, then the book must be gone.
    try {
        $db->rollback();
    } catch (Throwable) {
        // best-effort
    }
    $db->query("DROP TRIGGER IF EXISTS {$trigger}");
    $check($threw, '5. forced copy-insert failure actually throws (test can fail)');
    $check($bookCount($titleF) === 0, '5. rollback removes the book row — no orphan book');
    $check(
        (int) $scalar('SELECT COUNT(*) FROM libri_autori WHERE libro_id = ?', 'i', [$idF]) === 0,
        '5. rollback leaves no orphan author links'
    );
    $check(
        (int) $scalar("SELECT COUNT(*) FROM copie WHERE numero_inventario LIKE CONCAT(?, '%')", 's', [$failBase]) === 0,
        '5. rollback leaves no partial copie rows'
    );

    /* ---------------------------------------------------------------------
     * 6. NESTED-TRANSACTION TRAP PROOF — createBasic() must nest via
     *    SAVEPOINT. If it called begin_transaction() internally, mysqli
     *    would implicitly COMMIT the outer transaction and the row would
     *    survive the outer rollback below.
     * ------------------------------------------------------------------- */
    $titleT = "{$prefix}_trap";
    $db->begin_transaction();
    $idT = $repo->createBasic(['titolo' => $titleT, 'copie_totali' => 1, 'copie_disponibili' => 1]);
    $visibleInsideTx = $bookCount($titleT) === 1;
    $db->rollback();
    $check(
        $visibleInsideTx && $bookCount($titleT) === 0,
        '6. createBasic nests via SAVEPOINT: outer rollback removes the row (no implicit commit)'
    );

    /* ---------------------------------------------------------------------
     * 7. SOURCE-ORDER GUARD — in store() the transaction must close BEFORE
     *    the book.save.after hook (whose handlers, e.g. book-club, open
     *    their own transaction) and before the availability recalc.
     * ------------------------------------------------------------------- */
    $controller = (string) file_get_contents($root . '/app/Controllers/LibriController.php');
    $storeStart = strpos($controller, 'public function store(');
    $storeEnd = strpos($controller, 'public function editForm(');
    $store = ($storeStart !== false && $storeEnd !== false && $storeEnd > $storeStart)
        ? substr($controller, $storeStart, $storeEnd - $storeStart)
        : '';
    $posBegin = strpos($store, '$db->begin_transaction()');
    $posCreate = strpos($store, 'createBasic($fields)');
    $posCopies = strpos($store, '$copyRepo->createManyForBook(');
    $posCommit = strpos($store, '$db->commit()');
    $posHook = strpos($store, "Hooks::do('book.save.after'");
    $posRecalc = strpos($store, 'recalculateBookAvailability(');
    $ordered = $posBegin !== false && $posCreate !== false && $posCopies !== false
        && $posCommit !== false && $posHook !== false && $posRecalc !== false
        && $posBegin < $posCreate && $posCreate < $posCopies && $posCopies < $posCommit
        && $posCommit < $posHook && $posCommit < $posRecalc;
    $check($ordered, '7. store(): begin < createBasic < createManyForBook < commit < book.save.after hook & recalc');

    /* ---------------------------------------------------------------------
     * 8. Shared inventory base across two books: collision-free codes
     * ------------------------------------------------------------------- */
    $titleS1 = "{$prefix}_share1";
    $titleS2 = "{$prefix}_share2";
    $baseS = "{$prefix}-S";
    $db->begin_transaction();
    $idS1 = $repo->createBasic(['titolo' => $titleS1, 'copie_totali' => 2, 'copie_disponibili' => 2]);
    $copyRepo->createManyForBook($idS1, $baseS, 2, 'disponibile', null);
    $db->commit();
    $db->begin_transaction();
    $idS2 = $repo->createBasic(['titolo' => $titleS2, 'copie_totali' => 2, 'copie_disponibili' => 2]);
    $copyRepo->createManyForBook($idS2, $baseS, 2, 'disponibile', null);
    $db->commit();
    $allCodes = array_merge($copyCodes($idS1), $copyCodes($idS2));
    $check(
        count($allCodes) === 4 && count(array_unique($allCodes)) === 4
            && $copyCodes($idS1) === ["{$baseS}-C1", "{$baseS}-C2"]
            && $copyCodes($idS2) === ["{$baseS}-C3", "{$baseS}-C4"],
        '8. two books on the same base get collision-free -C1..-C4 codes'
    );

    $exitCode = $failed === 0 ? 0 : 1;
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL  unexpected exception: ' . $e->getMessage() . "\n";
    $exitCode = 1;
} finally {
    $cleanup();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($exitCode);
