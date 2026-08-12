<?php
declare(strict_types=1);

/**
 * Behavioural and wiring coverage for issue #338 / migrate_0.7.59-rc.1.sql.
 * Runs the real migration against a sandbox table and verifies that the new
 * flag remains deliberately admin-only.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — migration test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$sandboxTable = 'zz_mig_collane_0758';
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.59-rc.1.sql');
$sandboxMigration = static fn(string $sql): string => str_replace(
    ['`collane`', "'collane'"],
    ["`{$sandboxTable}`", "'{$sandboxTable}'"],
    $sql
);
$runMigration = static function () use ($db, $migration, $sandboxMigration): void {
    $withoutComments = preg_replace('/^--.*$/m', '', $migration) ?? $migration;
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandboxMigration($withoutComments)))) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $sandboxTable): void {
    $db->query("DROP TABLE IF EXISTS `{$sandboxTable}`");
};

echo "A. Real migration on a sandbox collane table\n";
try {
    $cleanup();
    $db->query("CREATE TABLE `{$sandboxTable}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL,
        `ordine_ciclo` SMALLINT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $runMigration();
    $column = $db->query(
        "SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$sandboxTable}'
            AND COLUMN_NAME = 'is_completa'"
    )->fetch_assoc();
    $check($column !== null && $column['DATA_TYPE'] === 'tinyint', 'migration adds the tinyint completion flag');
    $check($column !== null && $column['IS_NULLABLE'] === 'NO' && (int) $column['COLUMN_DEFAULT'] === 0, 'flag is non-null with a safe false default');

    $db->query("INSERT INTO `{$sandboxTable}` (`nome`) VALUES ('Saga test')");
    $check((int) $db->query("SELECT is_completa FROM `{$sandboxTable}`")->fetch_row()[0] === 0, 'existing/default series remain incomplete');
    $db->query("UPDATE `{$sandboxTable}` SET is_completa = 1 WHERE nome = 'Saga test'");
    $runMigration();
    $check((int) $db->query("SELECT is_completa FROM `{$sandboxTable}`")->fetch_row()[0] === 1, 'migration is idempotent and preserves the admin choice');
} catch (Throwable $e) {
    $failed++;
    echo "  FAIL exception: {$e->getMessage()}\n";
} finally {
    $cleanup();
    $db->close();
}

echo "B. Admin wiring and localization\n";
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
$repo = (string) file_get_contents($root . '/app/Models/SeriesRepository.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CollaneController.php');
$detailView = (string) file_get_contents($root . '/app/Views/collane/dettaglio.php');
$indexView = (string) file_get_contents($root . '/app/Views/collane/index.php');
$frontend = (string) file_get_contents($root . '/app/Controllers/FrontendController.php')
    . (string) file_get_contents($root . '/app/Views/frontend/book-detail.php');

$check(str_contains($schema, '`is_completa` tinyint(1) NOT NULL DEFAULT'), 'fresh-install schema includes is_completa');
$check(str_contains($repo, 'supportsCompleteFlag()') && str_contains($repo, "array_key_exists('is_completa'"), 'repository detects and persists the flag');
$check(str_contains($controller, "is_scalar(\$rawSeriesComplete)") && str_contains($controller, "'is_completa' => \$seriesComplete"), 'controller normalizes checkbox input');
$check(str_contains($detailView, 'name="is_completa"') && str_contains($detailView, 'Serie completa'), 'series editor exposes the completion checkbox');
$check(str_contains($indexView, "\$c['is_completa']") && str_contains($indexView, 'fa-check-circle'), 'series list displays the compact completion marker');
$check(!str_contains($frontend, 'is_completa'), 'completion remains admin-only (no OPAC exposure)');

foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $translations = json_decode((string) file_get_contents($root . "/locale/{$locale}.json"), true);
    $check(is_array($translations) && isset($translations['Serie completa'], $translations['Tutti i volumi previsti sono presenti in catalogo.'], $translations['Metadati serie salvati']), "{$locale} contains all completion strings");
}

// Updater migration-selection contract (app/Support/Updater.php::runMigrations):
// a migration runs iff  version_compare(mig, from, '>') && version_compare(mig, to, '<=').
// This guards the exact risk of naming a migration after a prerelease — it must
// fire on every upgrade path that has to add the column, and never re-fire once
// the RC is already installed.
$mig = '0.7.59-rc.1';
$updaterRuns = static fn(string $from, string $to): bool =>
    version_compare($mig, $from, '>') && version_compare($mig, $to, '<=');
$check(version_compare('0.7.59-rc.1', '0.7.59', '<'), 'version_compare orders the RC below its stable (prerelease semantics)');
$check($updaterRuns('0.7.58', '0.7.59-rc.1'), 'Updater runs it on 0.7.58 -> 0.7.59-rc.1 (RC upgrade)');
$check($updaterRuns('0.7.58', '0.7.59'), 'Updater runs it on 0.7.58 -> 0.7.59 stable (RC never installed)');
$check(!$updaterRuns('0.7.59-rc.1', '0.7.59'), 'Updater does NOT re-run it on 0.7.59-rc.1 -> 0.7.59 stable (already applied; migration is idempotent as a backstop)');

// Drift guard: the predicate replicated above must stay in sync with the real
// selection logic in Updater::runMigrations(). If someone changes the condition
// there, this fails and forces the assertions above to be revisited.
$updaterSrc = (string) file_get_contents($root . '/app/Support/Updater.php');
$check(
    str_contains($updaterSrc, "version_compare(\$migrationVersion, \$fromVersion, '>')")
        && str_contains($updaterSrc, "version_compare(\$migrationVersion, \$toVersion, '<=')"),
    'Updater::runMigrations still gates on migrationVersion > from AND <= to (mirrored predicate is current)'
);

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
