<?php
declare(strict_types=1);

/**
 * Behavioral suite — book field-type + derived-status behavior for Pinakes.
 *
 * Complements tests/book-field-types-static.spec.js (which only asserts the
 * source *contains* the fixes): this exercises the REAL runtime code paths and
 * asserts on their effects —
 *   - BookRepository::createBasic() → the free-text `tipo_acquisizione` column
 *     (VARCHAR since 0.7.25) round-trips instead of being coerced to an enum;
 *   - BookRepository::updateBasic() → the derived `libri.stato` is left
 *     untouched when the caller does not submit it (the loan engine owns it);
 *   - translate_book_status() → localizes every book state without ever
 *     leaking a raw `snake_case` key (the "Non_disponibile" bug).
 *
 * The availability-engine side of 0.7.25 (recalc → non_disponibile, repair
 * flow, soft-delete) is behaviorally covered by tests/loan-edge-cases.unit.php
 * and is deliberately NOT duplicated here.
 *
 * Runs against the LIVE local MySQL but only ever touches rows it creates,
 * marked by title `ZZ_BFT_%`. Cleanup is marker-scoped and runs at start, end,
 * and on any failure.
 *
 * Run:   php tests/book-field-types.unit.php
 * Exit:  0 only if all checks pass; prints "ALL <n> PASS".
 */

use App\Models\BookRepository;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* -------- .env (DB_NAME / DB_USER / DB_PASS|DB_PASSWORD) -------- */
function bftLoadEnv(string $path): array
{
    $env = [];
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("Cannot read .env at {$path}");
    }
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return $env;
}

$env    = bftLoadEnv($root . '/.env');
$dbName = $env['DB_NAME'] ?? '';
$dbUser = $env['DB_USER'] ?? '';
$dbPass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
// Socket/host configurabili (CI non ha il socket Homebrew di macOS):
// E2E_DB_SOCKET > .env DB_SOCKET > default macOS; se il socket non esiste,
// fallback TCP su DB_HOST/DB_PORT. DB irraggiungibile => SKIP, non FAIL.
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

/* -------- markers + cleanup -------- */
$TITLE_LIKE = 'ZZ_BFT_%';

function bftCleanup(mysqli $db): void
{
    // Nothing but libri rows are created; FK children (none here) would go first.
    $db->query("DELETE FROM libri WHERE titolo LIKE 'ZZ_BFT_%'");
}

set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        bftCleanup($db);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
});

bftCleanup($db);

/* -------- harness -------- */
$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}
function eq($expected, $actual, string $desc): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            "%s\n   expected: %s\n   actual:   %s",
            $desc,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
    pass($desc);
}

$repo = new BookRepository($db);

/** Insert a book via the real repository, return its id. */
$mk = function (array $data) use ($repo): int {
    $data['titolo'] = $data['titolo'] ?? ('ZZ_BFT_' . bin2hex(random_bytes(4)));
    return $repo->createBasic($data);
};
/** Read one column of a book row. */
$col = function (int $id, string $column) use ($db) {
    $stmt = $db->prepare("SELECT `$column` FROM libri WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? $row[0] : null;
};

/* =========================================================================
 * A. tipo_acquisizione is FREE TEXT (VARCHAR) — persists verbatim, not coerced
 * ======================================================================= */
$id = $mk(['tipo_acquisizione' => 'Deposito legale']);
eq('Deposito legale', $col($id, 'tipo_acquisizione'), 'A1 free-text acquisition persists verbatim');

$id = $mk(['tipo_acquisizione' => 'Scambio con altra biblioteca']);
eq('Scambio con altra biblioteca', $col($id, 'tipo_acquisizione'), 'A2 multi-word free-text acquisition persists');

$id = $mk(['tipo_acquisizione' => 'acquisto']);
eq('acquisto', $col($id, 'tipo_acquisizione'), 'A3 legacy enum value still persists');

$id = $mk(['tipo_acquisizione' => 'donazione']);
eq('donazione', $col($id, 'tipo_acquisizione'), 'A4 legacy "donazione" persists');

$long = str_repeat('x', 60);
$id = $mk(['tipo_acquisizione' => $long]);
eq(50, mb_strlen((string) $col($id, 'tipo_acquisizione')), 'A5 over-long acquisition truncated to 50 chars');

$id = $mk([]); // no tipo_acquisizione key at all
eq('acquisto', $col($id, 'tipo_acquisizione'), 'A6 missing acquisition falls back to default "acquisto"');

$id = $mk(['tipo_acquisizione' => '']);
eq('acquisto', $col($id, 'tipo_acquisizione'), 'A7 empty acquisition falls back to default');

$id = $mk(['tipo_acquisizione' => ['array', 'input']]); // hostile non-string input
eq('acquisto', $col($id, 'tipo_acquisizione'), 'A8 array input is neutralized to default (stringInput safety)');

$id = $mk(['tipo_acquisizione' => '  Fondo privato  ']); // surrounding whitespace
eq('Fondo privato', $col($id, 'tipo_acquisizione'), 'A9 free-text acquisition is trimmed');

/* =========================================================================
 * B. translate_book_status() — localized, never leaks a snake_case key
 * ======================================================================= */
check(function_exists('translate_book_status'), 'B0 translate_book_status() is loaded');

eq('Non Disponibile', translate_book_status('non_disponibile'), 'B1 non_disponibile -> "Non Disponibile" (space, not underscore)');
check(!str_contains(translate_book_status('non_disponibile'), '_'), 'B2 non_disponibile label carries no underscore');
eq('', translate_book_status(''), 'B3 empty status -> empty label');
eq('Disponibile', translate_book_status('DISPONIBILE'), 'B4 status is case-insensitive');
eq('Disponibile', translate_book_status('  disponibile  '), 'B5 status is trimmed');

foreach (['disponibile', 'prestato', 'prenotato', 'in_ritardo', 'danneggiato', 'perso', 'non_disponibile'] as $st) {
    $label = translate_book_status($st);
    check($label !== '' && !str_contains($label, '_'), "B6 known status '{$st}' -> non-empty, underscore-free label");
}

// An unknown / future status must degrade gracefully (title-cased, no underscore).
eq('In Trasferimento', translate_book_status('in_trasferimento'), 'B7 unknown status is humanized without underscores');

/* =========================================================================
 * C. updateBasic() leaves the DERIVED libri.stato alone when not submitted
 * ======================================================================= */
$id = $mk(['stato' => 'prestato']); // seed a concrete state
eq('prestato', $col($id, 'stato'), 'C1 seed: book created with stato=prestato');

$repo->updateBasic($id, ['titolo' => 'ZZ_BFT_renamed_' . bin2hex(random_bytes(3))]);
eq('prestato', $col($id, 'stato'), 'C2 updateBasic without a stato key leaves the derived stato untouched');

// Sanity: the update actually happened (title changed) — proves C2 isn't a no-op update.
check(str_starts_with((string) $col($id, 'titolo'), 'ZZ_BFT_renamed_'), 'C3 updateBasic did persist the other fields');

/* -------- done -------- */
bftCleanup($db);
$db->close();
printf("\nALL %d PASS\n", $TESTNO);
