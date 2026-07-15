<?php
declare(strict_types=1);

/**
 * Behavioral suite for the 0.7.36 contributor-roles migration (issue #237).
 *
 * Two pieces ship together:
 *   1. installer/database/migrations/migrate_0.7.36.sql — adds 'colorista' to
 *      libri_autori.ruolo (guarded, idempotent).
 *   2. App\Support\ContributorBackfill — guarded conversion of the legacy
 *      free-text columns into role rows, completed by the migration runner;
 *      MaintenanceService remains the retry safety net.
 *
 * Strategy (mirrors tests/migration-0.7.25.unit.php):
 *   A. Enum ALTER on a sandbox table `zz_mig_libri_autori` seeded with the OLD
 *      enum → run the MODIFY → assert 'colorista' present → re-run → idempotent.
 *   B. ContributorBackfill::splitNames() cases (comma / semicolon / & / e / and).
 *   C. Functional: inside a ROLLED-BACK transaction on the real tables, point an
 *      existing book's free-text illustratore at a two-name list (one author
 *      pre-created, one new), run the REAL ContributorBackfill::run(), and assert
 *      the split, find-or-create-reuse, the marker, and idempotency.
 *   D. Drift guards: schema.sql + migration carry 'colorista' and provenance,
 *      and both migration/maintenance paths wire the backfill.
 *
 * Runs against the LIVE local MySQL. Test C is fully transactional (ROLLBACK), so
 * no real data is changed; Test A touches only `zz_mig_libri_autori`.
 * Run:  php tests/migration-0.7.36.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\ContributorBackfill;
use App\Models\AuthorRepository;
use App\Models\SettingsRepository;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0; $failed = 0;
$check = function (bool $cond, string $label) use (&$passed, &$failed): void {
    if ($cond) { $passed++; echo "  OK  {$label}\n"; }
    else { $failed++; echo "  FAIL {$label}\n"; }
};

function migLoadEnv(string $path): array
{
    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) { $v = substr($v, 1, -1); }
        $env[$k] = $v;
    }
    return $env;
}

$env = migLoadEnv($root . '/.env');
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
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to DB: " . $e->getMessage() . "\n");
    exit(1);
}

// ── A. Enum ALTER via the REAL migration file (sandbox table) ───────────────
// Run the actual installer/database/migrations/migrate_0.7.36.sql — retargeted at
// a sandbox table — so the file's guarded dynamic-SQL (information_schema LOCATE +
// PREPARE/EXECUTE/DEALLOCATE) is the code actually exercised, not a hand-rolled
// ALTER (CLAUDE.md rule 6: the migration test must run the real file).
echo "A. Enum ALTER (real migrate_0.7.36.sql, sandbox)\n";
$db->query("DROP TABLE IF EXISTS zz_mig_libri_autori_import_sources");
$db->query("DROP TABLE IF EXISTS zz_mig_libri_autori");
$db->query("CREATE TABLE zz_mig_libri_autori (
    libro_id INT NOT NULL, autore_id INT NOT NULL,
    ruolo enum('principale','co-autore','traduttore','illustratore','curatore') NOT NULL,
    PRIMARY KEY (libro_id, autore_id, ruolo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$colType = function () use ($db): string {
    $r = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='zz_mig_libri_autori' AND COLUMN_NAME='ruolo'");
    return (string) ($r->fetch_row()[0] ?? '');
};
$check(strpos($colType(), 'colorista') === false, "old enum has no 'colorista'");

$migrationSql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.36.sql');
$check($migrationSql !== '' && str_contains($migrationSql, 'libri_autori'), "migration file loaded");
// Retarget every `libri_autori` reference (the ALTER + the information_schema guard)
// at the sandbox table, then run the multi-statement file for real.
$sandboxSql = str_replace('libri_autori', 'zz_mig_libri_autori', $migrationSql);
$runMigration = function () use ($db, $sandboxSql): void {
    if ($db->multi_query($sandboxSql)) {
        do {
            if ($res = $db->store_result()) { $res->free(); }
        } while ($db->more_results() && $db->next_result());
    }
};
$runMigration();
$check(strpos($colType(), 'colorista') !== false, "'colorista' present after running the REAL migration file");
$trackingExists = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='zz_mig_libri_autori_import_sources'")->fetch_column();
$check($trackingExists === 1, 'real migration creates importer provenance table');
$runMigration(); // idempotent — the file's LOCATE guard should no-op (ELSE SELECT note)
$check(strpos($colType(), 'colorista') !== false, "real migration idempotent on re-run");
$db->query("DROP TABLE IF EXISTS zz_mig_libri_autori_import_sources");
$db->query("DROP TABLE IF EXISTS zz_mig_libri_autori");

// ── B. splitNames() ─────────────────────────────────────────────────────────
echo "B. splitNames()\n";
$check(ContributorBackfill::splitNames('Mario Rossi') === ['Mario Rossi'], "single name");
$check(ContributorBackfill::splitNames('Mario Rossi, Gianni Verdi') === ['Mario Rossi', 'Gianni Verdi'], "comma split");
$check(ContributorBackfill::splitNames('A; B & C | D') === ['A', 'B', 'C', 'D'], "semicolon + ampersand + pipe");
$check(ContributorBackfill::splitNames('Tizio e Caio') === ['Tizio', 'Caio'], "italian ' e '");
$check(ContributorBackfill::splitNames('Foo and Bar') === ['Foo', 'Bar'], "english ' and '");
$check(ContributorBackfill::splitNames('  ,  ') === [], "empty fragments dropped");

// ── C. Functional backfill (transactional, rolled back) ─────────────────────
echo "C. ContributorBackfill::run() (transaction)\n";
$authors = new AuthorRepository($db);
$settings = new SettingsRepository($db);

$db->begin_transaction();
try {
    // Reset the marker within the txn so run() does real work.
    $settings->set('migrations', 'contributors_backfilled', '0');

    $token = bin2hex(random_bytes(4));
    $oneName = "ZZ Mig {$token} Illustrator One";
    $twoName = "ZZ Mig {$token} Illustrator Two";
    $pseudonym = "ZZMigPseudo{$token}";

    // Pre-create one illustrator (so we can prove find-or-create REUSE), leave
    // the second name to be created by the backfill.
    $existingId = $authors->create(['nome' => $oneName, 'pseudonimo' => $pseudonym]);

    // Create our own book fixture: CI databases are intentionally empty, so a
    // migration test must never depend on another suite's seed data.
    $stmt = $db->prepare('INSERT INTO libri (titolo) VALUES (?)');
    $title = "ZZ migration fixture {$token}";
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $check($bookId > 0, "created a hermetic book fixture");

    $stmt = $db->prepare("UPDATE libri SET illustratore=? WHERE id=?");
    $val = $oneName . ', ' . $twoName;
    $stmt->bind_param('si', $val, $bookId);
    $stmt->execute();
    $stmt->close();

    ContributorBackfill::run($db);

    // Marker set → the whole pass completed without error.
    $check($settings->get('migrations', 'contributors_backfilled', '0') === '1', "marker set after run");

    // The book now has two illustratore links.
    $cnt = (int) ($db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND ruolo='illustratore'")->fetch_row()[0] ?? 0);
    $check($cnt === 2, "two illustratore rows created (comma split), got {$cnt}");
    $provenanceCount = (int)$db->query("SELECT COUNT(*) FROM libri_autori_import_sources WHERE libro_id={$bookId} AND ruolo='illustratore' AND source='legacy-backfill'")->fetch_column();
    $check($provenanceCount === 2, 'legacy backfill records provenance for both created links');

    // The pre-existing author was REUSED (no duplicate row for that name).
    $reused = (int) ($db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$existingId} AND ruolo='illustratore'")->fetch_row()[0] ?? 0);
    $check($reused === 1, "pre-existing illustrator reused (not duplicated)");

    // The second name became a new author entity.
    $twoId = $authors->findByName($twoName);
    $check($twoId !== null, "second illustrator created as an entity");

    $index = (string) $db->query("SELECT search_index FROM libri WHERE id={$bookId}")->fetch_column();
    $check(str_contains($index, $pseudonym), "first post-upgrade pass rebuilds pseudonym search index");

    // Idempotent: a second run does nothing (marker gate).
    ContributorBackfill::run($db);
    $cnt2 = (int) ($db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND ruolo='illustratore'")->fetch_row()[0] ?? 0);
    $check($cnt2 === 2, "idempotent second run (still 2 rows), got {$cnt2}");
} finally {
    $db->rollback();
}

// ── D. Drift guards ─────────────────────────────────────────────────────────
echo "D. Drift guards\n";
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
$check(strpos($schema, "'principale','co-autore','traduttore','illustratore','curatore','colorista'") !== false, "schema.sql enum includes colorista");
$migSql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.36.sql');
$check(strpos($migSql, 'colorista') !== false && stripos($migSql, 'libri_autori') !== false, "migration file adds colorista to libri_autori");
$maint = (string) file_get_contents($root . '/app/Support/MaintenanceService.php');
$check(strpos($maint, 'ContributorBackfill::run') !== false, "MaintenanceService wires the backfill");
$updater = (string) file_get_contents($root . '/app/Support/Updater.php');
$check(strpos($updater, 'ContributorBackfill::run') !== false, "migration runner completes the backfill before returning success");
$src = (string) file_get_contents($root . '/app/Support/ContributorSync.php');
$check(strpos($src, 'syncImportedLegacyValues') !== false && strpos($src, 'libri_autori_import_sources') !== false, "shared contributor sync tracks importer provenance");

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} passed, {$failed} FAILED\n");
$db->close();
exit($failed === 0 ? 0 : 1);
