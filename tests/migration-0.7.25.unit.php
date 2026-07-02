<?php
declare(strict_types=1);

/**
 * Behavioral suite — 10 checks that migrate_0.7.25-rc.1.sql upgrades an EXISTING
 * (pre-0.7.25) install correctly. This is the upgrade path that ships in the
 * 0.7.25 release candidate; a bad migration silently breaks every install that
 * updates through the admin UI.
 *
 * Strategy: build a sandbox table `zz_mig_libri` with the OLD `libri` schema
 * (tipo_acquisizione ENUM, stato ENUM without 'non_disponibile'), seed it, then
 * run the REAL migration file against it (only the table name is rewritten —
 * the information_schema guards and the ALTER statements are executed verbatim).
 * This catches a broken idempotency guard or a wrong target type, which a static
 * "the file contains X" check cannot.
 *
 * Runs against the LIVE local MySQL; touches only `zz_mig_libri`, dropped at
 * start, end, and on failure.
 *
 * Run:   php tests/migration-0.7.25.unit.php
 * Exit:  0 only if all 10 pass; prints "ALL 10 PASS".
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function migLoadEnv(string $path): array
{
    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) as $line) {
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

$env = migLoadEnv($root . '/.env');
// Socket/host configurabili (CI non ha il socket Homebrew di macOS):
// E2E_DB_SOCKET > .env DB_SOCKET > default macOS; se il socket non esiste,
// fallback TCP su DB_HOST/DB_PORT. DB irraggiungibile => SKIP, non FAIL.
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$name = $env['DB_NAME'] ?? '';
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

const SANDBOX = 'zz_mig_libri';

function migCleanup(mysqli $db): void
{
    $db->query('DROP TABLE IF EXISTS `' . SANDBOX . '`');
}

set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        migCleanup($db);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

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

/** Introspection helpers scoped to the sandbox table. */
$dataType = function (string $column) use ($db): string {
    $stmt = $db->prepare(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $t = SANDBOX;
    $stmt->bind_param('ss', $t, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string) $row[0] : '';
};
$columnType = function (string $column) use ($db): string {
    $stmt = $db->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $t = SANDBOX;
    $stmt->bind_param('ss', $t, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string) $row[0] : '';
};
$scalar = function (string $sql) use ($db) {
    $res = $db->query($sql);
    $row = $res ? $res->fetch_row() : null;
    return $row ? $row[0] : null;
};

/** Run the REAL migration file against the sandbox table (rename libri only). */
$applyMigration = function () use ($db, $root): void {
    $sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.25-rc.1.sql');
    // Strip -- comment lines so the split doesn't choke on ';' inside prose.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Point every `libri` reference at the sandbox (backtick form + quoted name).
    $sql = str_replace(['`libri`', "'libri'"], ['`' . SANDBOX . '`', "'" . SANDBOX . "'"], $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $db->query($stmt);
    }
};

/* -------- start clean, build the OLD-schema sandbox -------- */
migCleanup($db);
$db->query(
    'CREATE TABLE `' . SANDBOX . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titolo VARCHAR(255) NOT NULL,
        tipo_acquisizione ENUM(\'acquisto\',\'donazione\') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT \'acquisto\',
        stato ENUM(\'disponibile\',\'prestato\',\'prenotato\',\'perso\',\'danneggiato\') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT \'disponibile\'
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query("INSERT INTO `" . SANDBOX . "` (titolo, tipo_acquisizione, stato) VALUES
    ('Alpha', 'acquisto', 'disponibile'),
    ('Beta',  'donazione', 'prestato')");

/* ========================= the 10 checks ========================= */

// 1 — sandbox reproduces the OLD schema
check($dataType('tipo_acquisizione') === 'enum', '01 pre-migration: tipo_acquisizione is an ENUM (old schema)');

// 2 — old stato enum lacks the new value
check(!str_contains($columnType('stato'), 'non_disponibile'), '02 pre-migration: stato enum has NO non_disponibile');

// 3 — seed rows are present
check((int) $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "`") === 2, '03 pre-migration: 2 seed rows present');

// ── run the real migration ──
$applyMigration();

// 4 — tipo_acquisizione widened to VARCHAR
check($dataType('tipo_acquisizione') === 'varchar', '04 post-migration: tipo_acquisizione is now VARCHAR');

// 5 — exactly VARCHAR(50)
check(strtolower($columnType('tipo_acquisizione')) === 'varchar(50)', '05 post-migration: tipo_acquisizione is VARCHAR(50)');

// 6 — legacy enum labels preserved verbatim (widening copies the strings)
$preserved = (int) $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "` WHERE tipo_acquisizione IN ('acquisto','donazione')");
check($preserved === 2, '06 post-migration: existing acquisto/donazione rows preserved');

// 7 — free text is now storable (would have been coerced/blanked on the enum)
$db->query("INSERT INTO `" . SANDBOX . "` (titolo, tipo_acquisizione) VALUES ('Gamma', 'Deposito legale')");
check($scalar("SELECT tipo_acquisizione FROM `" . SANDBOX . "` WHERE titolo='Gamma'") === 'Deposito legale',
    '07 post-migration: free-text tipo_acquisizione stored verbatim');

// 8 — stato enum now carries non_disponibile, old values intact
$st = $columnType('stato');
check(str_contains($st, 'non_disponibile')
    && str_contains($st, 'disponibile') && str_contains($st, 'prestato')
    && str_contains($st, 'perso') && str_contains($st, 'danneggiato'),
    '08 post-migration: stato enum gained non_disponibile, kept the old values');

// 9 — a row can actually be set to the new state
$db->query("UPDATE `" . SANDBOX . "` SET stato='non_disponibile' WHERE titolo='Alpha'");
check($scalar("SELECT stato FROM `" . SANDBOX . "` WHERE titolo='Alpha'") === 'non_disponibile',
    '09 post-migration: a book can be set to non_disponibile');

// 10 — idempotent: re-running the migration is a guarded no-op (no error, types unchanged)
$applyMigration();
check(strtolower($columnType('tipo_acquisizione')) === 'varchar(50)'
    && str_contains($columnType('stato'), 'non_disponibile'),
    '10 migration is idempotent: a second run leaves the migrated schema unchanged');

/* -------- done -------- */
migCleanup($db);
$db->close();
printf("\nALL %d PASS\n", $TESTNO);
