<?php
declare(strict_types=1);

/**
 * An interrupted restore must not reopen the site on a half-replaced database.
 *
 * THE BUG: MySQL cannot roll back DDL, so once the import has executed its first
 * DROP TABLE the database is no longer the one the site was serving. restoreZip()
 * only sets $dbImported AFTER importDatabase() returns, so a failure in the
 * middle is indistinguishable, to the caller, from a restore that never started
 * — it is reported as a plain failure, which invites the operator to retry into
 * the wreckage. Worse, the maintenance flag is removed on the way out (in the
 * `finally`, and by the fatal-error shutdown handler), so the site comes back up
 * on the half-replaced database.
 *
 * This suite performs a REAL restore, with the real BackupManager, against a
 * REAL MySQL database, using an archive whose dump genuinely breaks partway. It
 * is destructive by construction, which is why it refuses to run anywhere except
 * a dedicated sandbox schema.
 *
 * Note on why the dump must be broken rather than merely interrupted: the
 * importer tries the mysql CLI first and falls back to the PHP parser on
 * failure, deliberately, because the dump is idempotent and a retry usually
 * heals a partial CLI run. The case that matters is the one the retry cannot
 * heal — and that is what is built here.
 *
 * Sandbox: PINAKES_AUDIT_DB, default <DB_NAME>_audit. Never the installation's
 * own database; the suite exits rather than take that chance.
 *
 * Run:  php tests/backup-partial-restore.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

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
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$liveDb  = (string) ($env['DB_NAME'] ?? '');
$sandbox = getenv('PINAKES_AUDIT_DB') ?: ($liveDb . '_audit');

// The whole suite DROPs and recreates tables. Getting this guard wrong once
// costs the developer their working database, so it refuses on every doubt.
if ($liveDb === '' || $sandbox === '' || $sandbox === $liveDb) {
    fwrite(STDERR, "FAIL: refusing to run — the sandbox database must differ from the installation's ({$liveDb}).\n");
    exit(1);
}
if (!str_ends_with($sandbox, '_audit')) {
    fwrite(STDERR, "FAIL: refusing to run — PINAKES_AUDIT_DB must end in _audit (got '{$sandbox}').\n");
    exit(1);
}

// BackupManager's importer opens its OWN connection from the environment, not
// from the handle it was given: without this the import would target the
// installation database while every assertion read the sandbox.
foreach (['DB_HOST','DB_USER','DB_PASS','DB_PORT','DB_SOCKET'] as $key) {
    if (isset($env[$key])) {
        $_ENV[$key] = $env[$key];
        putenv("{$key}={$env[$key]}");
    }
}
$_ENV['DB_NAME'] = $sandbox;
putenv("DB_NAME={$sandbox}");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket = (string) ($env['DB_SOCKET'] ?? '');

/**
 * Make sure the sandbox schema exists.
 *
 * On a developer machine the application user is granted only the databases it
 * was created with, so this is a one-off manual step and the message below says
 * so. On CI the run's MySQL user can create schemas, and the suite provisions
 * its own rather than depending on a step someone has to remember to add.
 */
$ensureSandbox = static function (array $env, string $socket, string $liveDb, string $sandbox): bool {
    try {
        $admin = $socket !== '' && file_exists($socket)
            ? new mysqli('localhost', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $liveDb, 0, $socket)
            : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $liveDb, (int) ($env['DB_PORT'] ?? 3306));
        $admin->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $sandbox) . '` '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $admin->close();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
};
$ensureSandbox($env, $socket, $liveDb, $sandbox);

try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli('localhost', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $sandbox, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $sandbox, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: sandbox '{$sandbox}' unreachable: {$e->getMessage()}\n"
        . "      Create it once:  CREATE DATABASE {$sandbox}; GRANT ALL ON {$sandbox}.* TO '" . ($env['DB_USER'] ?? '') . "'@'localhost';\n");
    exit(1);
}
$check($db->query('SELECT DATABASE()')->fetch_row()[0] === $sandbox,
    "connected to the sandbox '{$sandbox}', not to the installation database");

// A throwaway application root: the safety backup writes under it, and the
// staging/promotion steps must never reach the real tree.
$fakeRoot = (string) tempnam(sys_get_temp_dir(), 'pk_root_');
@unlink($fakeRoot);
mkdir($fakeRoot . '/storage/backups', 0775, true);
mkdir($fakeRoot . '/storage/cache', 0775, true);

$cleanup = static function () use ($db, $fakeRoot): void {
    foreach (['zz_alpha', 'zz_beta'] as $t) {
        try { $db->query("DROP TABLE IF EXISTS `{$t}`"); } catch (\Throwable $e) { /* shutting down */ }
    }
    $rm = static function (string $dir) use (&$rm): void {
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $rm($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    if (is_dir($fakeRoot)) { $rm($fakeRoot); }
};
register_shutdown_function($cleanup);

try {
    echo "A. A database that is serving the site\n";

    $db->query('DROP TABLE IF EXISTS `zz_alpha`');
    $db->query('DROP TABLE IF EXISTS `zz_beta`');
    $db->query('CREATE TABLE `zz_alpha` (id INT PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE `zz_beta`  (id INT PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $db->query("INSERT INTO `zz_alpha` VALUES (1,'before')");
    $db->query("INSERT INTO `zz_beta`  VALUES (1,'before')");

    $valueOf = static function (string $table) use ($db): string {
        try {
            $row = $db->query("SELECT v FROM `{$table}` WHERE id=1")->fetch_row();
            return $row === null ? '(no row)' : (string) $row[0];
        } catch (\Throwable $e) {
            return '(no table)';
        }
    };
    $check($valueOf('zz_alpha') === 'before' && $valueOf('zz_beta') === 'before',
        'both tables hold their pre-restore contents');

    echo "\nB. A restore whose dump breaks after the first table\n";

    // Valid for zz_alpha, then a statement no parser can accept, then zz_beta.
    // The first table is therefore genuinely replaced before the failure.
    $sql = <<<SQL
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `zz_alpha`;
CREATE TABLE `zz_alpha` (id INT PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB;
INSERT INTO `zz_alpha` VALUES (1,'after');

THIS IS NOT A STATEMENT AND NO PARSER WILL ACCEPT IT;

DROP TABLE IF EXISTS `zz_beta`;
CREATE TABLE `zz_beta` (id INT PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB;
INSERT INTO `zz_beta` VALUES (1,'after');
SQL;

    $zipPath = $fakeRoot . '/broken-backup.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('database.sql', $sql);
    $zip->addFromString('manifest.json', (string) json_encode([
        'app' => 'Pinakes',
        'version' => '0.0.0-test',
        'created_at' => date('c'),
        'scope' => 'db',
        'origin' => 'upload',
        'tables' => 2,
        'files' => 0,
        'database_sha256' => hash('sha256', $sql),
    ]));
    $zip->close();

    $maintenanceFile = $fakeRoot . '/storage/.maintenance';
    $manager = new \App\Support\BackupManager($db, $fakeRoot);

    $upload = $fakeRoot . '/upload.zip';
    copy($zipPath, $upload);
    $result = $manager->restoreFromUploadedZip($upload, (int) filesize($upload));

    $check(($result['success'] ?? null) === false, 'the restore reports failure');

    echo "\nC. What the database looks like afterwards\n";

    $alpha = $valueOf('zz_alpha');
    $beta  = $valueOf('zz_beta');
    echo "     zz_alpha = {$alpha} · zz_beta = {$beta}\n";

    $destructivePhaseHappened = $alpha !== 'before';
    $check($destructivePhaseHappened,
        'the import really did replace part of the database before failing — this is not a no-op');
    $check($beta === 'before' || $beta === '(no table)',
        'and did not finish: the second table never reached its restored contents');

    echo "\nD. What the operator is told, and whether the site reopens\n";

    $check(($result['partial'] ?? false) === true,
        'the outcome is reported as PARTIAL, not as an ordinary failure '
            . '(got: ' . json_encode(['partial' => $result['partial'] ?? null, 'restored_phase' => $result['restored_phase'] ?? null]) . ')');
    $check(is_file($maintenanceFile),
        'maintenance stays ON: the site must not reopen on a half-replaced database');
    $check(str_contains((string) ($result['error'] ?? ''), 'manutenzione'),
        'and the operator is told what to do rather than handed the SQL error alone');

    echo "\nE. A restore that succeeds still reopens the site\n";

    // The guard above must not turn into "every restore locks the library out".
    // Same machinery, an archive that imports cleanly.
    @unlink($maintenanceFile);
    $goodSql = <<<SQL
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `zz_alpha`;
CREATE TABLE `zz_alpha` (id INT PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB;
INSERT INTO `zz_alpha` (`id`, `v`) VALUES (1,'restored');

DROP TABLE IF EXISTS `zz_beta`;
CREATE TABLE `zz_beta` (id INT PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB;
INSERT INTO `zz_beta` (`id`, `v`) VALUES (1,'restored');
SQL;

    $goodZip = $fakeRoot . '/good-backup.zip';
    $zip = new ZipArchive();
    $zip->open($goodZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('database.sql', $goodSql);
    $zip->addFromString('manifest.json', (string) json_encode([
        'app' => 'Pinakes',
        'version' => '0.0.0-test',
        'created_at' => date('c'),
        'scope' => 'db',
        'origin' => 'upload',
        'tables' => 2,
        'files' => 0,
        'database_sha256' => hash('sha256', $goodSql),
    ]));
    $zip->close();

    // A fresh manager: the destructive-window flag belongs to one restore.
    $manager2 = new \App\Support\BackupManager($db, $fakeRoot);
    $upload2 = $fakeRoot . '/upload2.zip';
    copy($goodZip, $upload2);
    $ok = $manager2->restoreFromUploadedZip($upload2, (int) filesize($upload2));

    $check(($ok['success'] ?? false) === true, 'a valid archive restores successfully');
    $check($valueOf('zz_alpha') === 'restored' && $valueOf('zz_beta') === 'restored',
        'both tables carry the restored contents');
    $check(!is_file($maintenanceFile),
        'and maintenance is lifted — the guard must not lock out every successful restore');
} finally {
    $cleanup();
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
