<?php
declare(strict_types=1);

/**
 * Behavioral test for App\Support\UniqueViolation — the parser that maps a mysqli
 * 1062 duplicate-key exception to the offending `utenti` field so the registration /
 * user-creation flows show a precise message instead of always "email".
 *
 * It asserts against the REAL mysqli_sql_exception message (which differs between
 * MySQL 5.7 "for key 'email'" and 8.0 "for key 'utenti.email'"), by triggering an
 * actual duplicate on a sandbox table whose UNIQUE indexes are named exactly like
 * the ones on `utenti`. A static string would not catch a driver-format change.
 *
 * Run:  php tests/unique-violation.unit.php   Exit 0 on "ALL <n> PASS".
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/app/Support/UniqueViolation.php';

use App\Support\UniqueViolation;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function uvEnv(string $path): array
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

$env = uvEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $pass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}

const SANDBOX = 'zz_uv_probe';
$cleanup = static function (mysqli $db): void {
    $db->query('DROP TABLE IF EXISTS `' . SANDBOX . '`');
};
set_exception_handler(static function (\Throwable $e) use ($db, $cleanup): void {
    try {
        $cleanup($db);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
});

$TESTNO = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO;
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}

$cleanup($db);
// UNIQUE index names match utenti (email / codice_tessera / cod_fiscale).
$db->query(
    "CREATE TABLE `" . SANDBOX . "` (
        id INT NOT NULL AUTO_INCREMENT,
        email VARCHAR(255) NULL,
        codice_tessera VARCHAR(20) NULL,
        cod_fiscale VARCHAR(16) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY email (email),
        UNIQUE KEY codice_tessera (codice_tessera),
        UNIQUE KEY cod_fiscale (cod_fiscale)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Seed one row per column, then insert a colliding value and capture the exception.
$captureDup = function (string $col, string $val) use ($db): \mysqli_sql_exception {
    $db->query("INSERT INTO `" . SANDBOX . "` (`$col`) VALUES ('$val')");
    try {
        $db->query("INSERT INTO `" . SANDBOX . "` (`$col`) VALUES ('$val')");
    } catch (\mysqli_sql_exception $e) {
        return $e;
    }
    throw new \RuntimeException("expected a 1062 on $col but none thrown");
};

$eEmail = $captureDup('email', 'dup@example.test');
check($eEmail->getCode() === 1062, "email duplicate raises errno 1062");
check(UniqueViolation::fieldFor($eEmail) === 'email', "fieldFor() detects 'email' from the real driver message");
check(UniqueViolation::errorCode($eEmail) === 'email_exists', "errorCode() maps email -> email_exists");

$eTessera = $captureDup('codice_tessera', 'BLTESS001');
check(UniqueViolation::fieldFor($eTessera) === 'codice_tessera', "fieldFor() detects 'codice_tessera'");
check(UniqueViolation::errorCode($eTessera) === 'tessera_exists', "errorCode() maps codice_tessera -> tessera_exists");

$eCf = $captureDup('cod_fiscale', 'RSSMRA80A01H501U');
check(UniqueViolation::fieldFor($eCf) === 'cod_fiscale', "fieldFor() detects 'cod_fiscale'");
check(UniqueViolation::errorCode($eCf) === 'cf_exists', "errorCode() maps cod_fiscale -> cf_exists");

// A non-1062 error must fall back to 'other' / 'db_error'.
$eOther = null;
try {
    $db->query("INSERT INTO `" . SANDBOX . "` (nonexistent_col) VALUES ('x')");
} catch (\mysqli_sql_exception $e) {
    $eOther = $e;
}
check($eOther !== null && $eOther->getCode() !== 1062, "a non-duplicate DB error has a code != 1062");
check(UniqueViolation::fieldFor($eOther) === 'other', "fieldFor() returns 'other' for a non-1062 error");
check(UniqueViolation::errorCode($eOther) === 'db_error', "errorCode() falls back to db_error for 'other'");

$cleanup($db);
printf("\nALL %d PASS\n", $TESTNO);
exit(0);
