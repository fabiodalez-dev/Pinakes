<?php
declare(strict_types=1);

/**
 * `libri.catalogued_at` must be stamped by every writer that CREATES a row in
 * the catalogue — not only by the plugin's activation backfill and not only by
 * BookRepository::create().
 *
 * Why this needs its own suite. The rule is already stated twice — the backfill
 * (`SET catalogued_at = NOW() WHERE is_desiderata = 0 AND catalogued_at IS
 * NULL`) and BookRepository::create() — and six other writers ignored it:
 * CsvImportController, LibraryThingImportController (twice), CollaneController
 * and the book-club Repo (twice). The backfill is also what HID the omission,
 * because it runs once at activation and repairs the entire history, so any
 * fixture created before it looks correct and only rows created afterwards stay
 * unstamped.
 *
 * What an unstamped row costs: everCatalogued() is false for it forever, so
 * when the book is later withdrawn OAI-PMH emits NO tombstone and ResourceSync
 * reports no de-listing. Harvesters keep serving a record the library has
 * retired, with nothing anywhere saying it went away. The failure is silent at
 * both ends — the import succeeds, the withdrawal succeeds, and only a remote
 * catalogue is wrong.
 *
 * Part A drives the real import path against a real database. Part C is the
 * half that matters for the future: a structural scan that fails when a SEVENTH
 * writer is added without the stamp. A behavioural test only covers the writers
 * someone thought to test; the scan covers the ones nobody did.
 *
 * Runs against a DISPOSABLE database of its own and refuses to touch the
 * installation's. FAILS HARD rather than skipping when that database is
 * unreachable — a skip here is indistinguishable from a pass.
 *
 * Run:  php tests/desiderata-catalogued-on-create.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

use App\Support\HookManager;
use App\Support\PluginManager;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

// Transport resolved exactly as tests/desiderata-catalogued-upgrade.unit.php
// does, and for the same reason: a hard-coded socket path bakes one machine
// into the suite and turns CI (which reaches MySQL over TCP) into a failure
// that says nothing about the code under test.
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
    throw new RuntimeException("invalid sandbox database name '{$sandboxName}'");
}
// The sandbox is refused if it names ANY real database this run could mean:
// the installation's (.env DB_NAME) and the E2E one (E2E_DB_NAME). Comparing
// against only one let DESIDERATA_SANDBOX_DB equal to the other slip through,
// and the setup below would then empty every table in it.
if (in_array(strtolower($sandboxName), array_map('strtolower', array_filter([(string) $dbName, (string) getenv('E2E_DB_NAME'), (string) ($env['DB_NAME'] ?? '')])), true)) {
    fwrite(STDERR, "FAIL: the sandbox name is the installation's own database. Name a disposable one.\n");
    exit(1);
}

$connect = static function (string $database) use ($socket, $dbUser, $dbPass, $dbHost, $dbPort): mysqli {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $database, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');

    return $db;
};

$admin = $connect('');
$admin->query("CREATE DATABASE IF NOT EXISTS `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->close();

$wipe = static function (mysqli $db): void {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $tables = [];
    $result = $db->query('SHOW TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    foreach ($tables as $table) {
        $db->query('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
};

$prep = $connect($sandboxName);
$wipe($prep);
$prep->close();

$useSocket = is_string($socket) && $socket !== '' && file_exists($socket);
$command = $useSocket
    ? sprintf('mysql --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg((string) $dbUser), escapeshellarg($sandboxName))
    : sprintf(
        'mysql -h %s -P %d --protocol=TCP -u %s %s',
        escapeshellarg((string) $dbHost),
        $dbPort,
        escapeshellarg((string) $dbUser),
        escapeshellarg($sandboxName)
    );
$command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
$previousPwd = getenv('MYSQL_PWD');
putenv('MYSQL_PWD=' . (string) $dbPass);
try {
    exec($command, $out, $rc);
} finally {
    $previousPwd === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previousPwd);
}
if ($rc !== 0) {
    throw new RuntimeException('could not load schema.sql: ' . implode("\n", $out));
}

$fatalError = null;

try {
    $db = $connect($sandboxName);

    // Activation through the production path, not ensureSchema() directly: a
    // plugin pinned to an app version it cannot satisfy ships registered and
    // permanently un-enableable, and a suite guarded on "is the column there?"
    // would then skip and exit 0.
    $manager = new PluginManager($db, new HookManager($db));
    $manager->autoRegisterBundledPlugins();
    $row = $db->query("SELECT id FROM plugins WHERE name = 'desiderata'")->fetch_assoc();
    if ($row === null) {
        throw new RuntimeException('the desiderata plugin did not register');
    }
    $manager->activatePlugin((int) $row['id']);

    $check(
        $db->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'")->num_rows === 1,
        'the plugin is active and libri.catalogued_at exists'
    );

    $insertBook = new ReflectionMethod(\App\Controllers\CsvImportController::class, 'insertBook');
    $insertBook->setAccessible(true);
    $importer = (new ReflectionClass(\App\Controllers\CsvImportController::class))->newInstanceWithoutConstructor();

    /** @return array{id:int, stamp:?string, wanted:int} */
    $import = static function (array $data) use ($insertBook, $importer, $db): array {
        $id = (int) $insertBook->invoke($importer, $db, $data, null, null);
        $stmt = $db->prepare('SELECT catalogued_at, is_desiderata FROM libri WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'id' => $id,
            'stamp' => $found['catalogued_at'] ?? null,
            'wanted' => (int) ($found['is_desiderata'] ?? 0),
        ];
    };

    echo "A. The real CSV import path stamps what it catalogues\n";

    $held = $import(['titolo' => 'Una copia posseduta', 'copie_totali' => 2]);
    $check($held['id'] > 0, 'a holding imports');
    $check($held['wanted'] === 0, 'and is not flagged as wanted');
    $check($held['stamp'] !== null, 'and carries catalogued_at — it is in the catalogue from birth');

    $wanted = $import(['titolo' => 'Un titolo desiderato', 'is_desiderata' => true, 'copie_totali' => 0]);
    $check($wanted['id'] > 0, 'a request imports');
    $check($wanted['wanted'] === 1, 'and IS flagged as wanted');
    $check($wanted['stamp'] === null, 'and carries NO stamp — it was never in the catalogue');

    echo "\nB. The stamp is what makes a later withdrawal announceable\n";

    // This is the consequence the stamp exists for. Withdraw both books the way
    // the application does and ask everCatalogued() — the predicate OAI-PMH and
    // ResourceSync use to decide whether a de-listing is a real withdrawal or a
    // wish that was never public.
    foreach ([$held['id'], $wanted['id']] as $id) {
        $db->query('UPDATE libri SET is_desiderata = 1 WHERE id = ' . (int) $id);
    }
    $everCatalogued = \App\Support\BookVisibility::everCatalogued($db, 'l');
    $result = $db->query('SELECT id FROM libri l WHERE l.deleted_at IS NULL AND ' . $everCatalogued);
    $tombstoneable = [];
    while ($r = $result->fetch_assoc()) {
        $tombstoneable[] = (int) $r['id'];
    }
    $check(in_array($held['id'], $tombstoneable, true),
        'the withdrawn holding produces a tombstone — harvesters are told it went away');
    $check(!in_array($wanted['id'], $tombstoneable, true),
        'the request does not — announcing it would publish a wish nobody was ever given');

    echo "\nC. Without the column nothing breaks and nothing is stamped\n";

    // An installation that never enabled the plugin must still import. The
    // fragments collapse to empty strings, so the statement has to stay valid.
    $db->query('ALTER TABLE libri DROP COLUMN catalogued_at');

    // A FRESH connection, deliberately. BookVisibility memoises the probe in a
    // WeakMap keyed by the handle, so reusing $db would answer from the cache
    // taken while the column still existed and this part would prove nothing.
    // A new handle is also what a real request gets.
    $bareDb = $connect($sandboxName);
    $bare = (int) $insertBook->invoke($importer, $bareDb, ['titolo' => 'Senza la colonna', 'copie_totali' => 1], null, null);
    $check($bare > 0, 'an import succeeds on an installation without the plugin');
    $check(\App\Support\BookVisibility::catalogueBirth($bareDb) === ['', ''],
        'and catalogueBirth() contributes nothing when the column is absent');
    $check($bareDb->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'")->num_rows === 0,
        'the column really is gone — the check above was not answered from a stale probe');

    $bareDb->close();
    $db->close();
} catch (\Throwable $e) {
    $fatalError = $e;
}

echo "\nD. No writer may create a catalogue row without the stamp\n";

// The structural half. A behavioural test only covers the writers someone
// thought to drive; this one observes the source and fails when a new
// `INSERT INTO libri (...)` appears anywhere without the stamp — which is
// exactly how the six omissions accumulated in the first place.
//
// Junction tables (libri_autori, libri_editori, libri_collane, …) are NOT
// catalogue rows and are excluded by requiring the open paren to follow
// `libri` directly.
$sources = [];
foreach ([$root . '/app', $root . '/storage/plugins'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[] = $file->getPathname();
        }
    }
}
sort($sources);

// Sites that legitimately carry no literal stamp, each with the reason. A new
// entry here is a deliberate decision someone has to write down.
$exempt = [
    // Builds its column list dynamically and appends 'catalogued_at' through
    // $fields/$placeholders rather than in the literal SQL.
    'app/Models/BookRepository.php',
];

$offenders = [];
foreach ($sources as $path) {
    $relative = ltrim(str_replace($root, '', $path), '/');
    if (in_array($relative, $exempt, true)) {
        continue;
    }
    $code = (string) file_get_contents($path);
    if (!preg_match_all('/INSERT\s+INTO\s+libri\s*\(/i', $code, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($m[0] as $hit) {
        // The column list ends at the first ')' that closes it; take a generous
        // window so a multi-line statement is covered whole.
        $window = substr($code, $hit[1], 2000);
        if (!str_contains($window, 'catalogued_at') && !str_contains($window, 'cataloguedCol')) {
            $line = substr_count(substr($code, 0, $hit[1]), "\n") + 1;
            $offenders[] = $relative . ':' . $line;
        }
    }
}

$check(
    $offenders === [],
    'every INSERT INTO libri carries the stamp'
        . ($offenders === [] ? '' : ' — missing at ' . implode(', ', $offenders))
);
$check(count($sources) > 100, 'the scan actually read the tree (' . count($sources) . ' files)');

if ($fatalError !== null) {
    echo "\nFATAL: " . $fatalError->getMessage() . "\n";
    $fail++;
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
