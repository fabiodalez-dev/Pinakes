<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — installer request walk, steps 0 -> 7
 *
 * Runs the REAL installer/index.php (routing, ?force=1 gate, owner rule,
 * session handling) once per request, in a subprocess, against a temporary
 * copy of the site. Two seams are patched into that copy only, because the
 * walk has no database:
 *   - Installer::configuredDatabaseIsEmpty() reads storage/.test_db_empty
 *     ("1" empty, "0" populated);
 *   - the gate's re-validation of an existing admin session reads
 *     storage/.test_admin_ok (present = an active admin whose session row and
 *     password still check out; those checks are covered by
 *     tests/security-audit-installer-force-gate.unit.php sections D-F).
 * Step bodies are stubs with the steps' session effects (step 2 writes .env
 * through createEnvFile(), step 3 records the import and fills the database,
 * step 7 writes .installed and releases the binding).
 *
 *   A. a fresh install walks 0 -> 7, and nobody else gets in after step 2;
 *   B. a ?force=1 reinstall onto an empty database walks 0 -> 7, although its
 *      later requests carry no ?force and the new database has no admin;
 *   C. a reinstall whose configured database is populated by someone else is
 *      refused at step 3 once the admin is no longer valid, and goes on with
 *      a valid admin of that database.
 *
 * No database needed. Run: php tests/security-audit-installer-step-walk.unit.php
 */

// ---------------------------------------------------------------------------
// Subprocess mode: one installer request.
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--request') {
    [, , $site, $query, $sid] = $argv;
    parse_str($query, $_GET);
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/installer/index.php';
    $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    ini_set('session.use_strict_mode', '0');
    ini_set('session.cache_limiter', '');
    session_id($sid);
    // Report the session id the response ends with, as the Set-Cookie header
    // would: ?reset starts a new session and a browser follows the new id.
    register_shutdown_function(static function (): void { echo '[SID ' . session_id() . ']'; });
    require $site . '/installer/index.php';
    exit(0);
}

$ROOT = dirname(__DIR__);
$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

$tmpRoot = sys_get_temp_dir() . '/pk_secaudit_walk_' . bin2hex(random_bytes(5));
mkdir($tmpRoot, 0700, true);

$stubs = [
    0 => "<?php completeStep(0); echo '[STEP 0]';",
    1 => "<?php completeStep(1); echo '[STEP 1]';",
    2 => "<?php if (!\$installer->createEnvFile('127.0.0.1', 'u', 'p', 'newdb', 1)) { echo '[STEP 2 ENV REFUSED]'; return; } completeStep(2); echo '[STEP 2]';",
    3 => "<?php if (method_exists(\$installer, 'recordSchemaImportByOwner')) { \$installer->recordSchemaImportByOwner(); } file_put_contents(\$baseDir . '/storage/.test_db_empty', '0'); completeStep(3); echo '[STEP 3]';",
    4 => "<?php completeStep(4); echo '[STEP 4]';",
    5 => "<?php completeStep(5); echo '[STEP 5]';",
    6 => "<?php completeStep(6); echo '[STEP 6]';",
    7 => "<?php if (\$installer->createLockFile()) { \$installer->releaseInstallOwnership(); completeStep(7); echo '[STEP 7 INSTALLED]'; } else { echo '[STEP 7 NO LOCK]'; }",
];

$seamsOk = true;
$mkSite = static function () use ($tmpRoot, $ROOT, $stubs, &$seamsOk): string {
    $site = $tmpRoot . '/site_' . bin2hex(random_bytes(4));
    mkdir($site . '/installer/classes', 0700, true);
    mkdir($site . '/installer/steps', 0700, true);
    mkdir($site . '/storage', 0700, true);

    $index = (string) file_get_contents($ROOT . '/installer/index.php');
    $index = str_replace(
        '$db = $openInstallerDb();',
        '$db = null; if (is_file($baseDir . \'/storage/.test_admin_ok\')) { $forceAuthenticated = true; }',
        $index,
        $n1
    );
    $installer = (string) file_get_contents($ROOT . '/installer/classes/Installer.php');
    $installer = str_replace(
        'protected function configuredDatabaseIsEmpty(): bool {',
        'protected function configuredDatabaseIsEmpty(): bool { $probe = $this->baseDir . \'/storage/.test_db_empty\'; if (is_file($probe)) { return trim((string) file_get_contents($probe)) === \'1\'; }',
        $installer,
        $n2
    );
    $seamsOk = $seamsOk && $n1 === 1 && $n2 === 1;
    file_put_contents($site . '/installer/index.php', $index);
    file_put_contents($site . '/installer/classes/Installer.php', $installer);
    copy($ROOT . '/installer/classes/Validator.php', $site . '/installer/classes/Validator.php');
    foreach ($stubs as $n => $code) {
        file_put_contents($site . "/installer/steps/step{$n}.php", $code);
    }
    return $site;
};

$request = static function (string $site, string $query, string $sid): string {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --request '
        . escapeshellarg($site) . ' ' . escapeshellarg($query) . ' ' . escapeshellarg($sid) . ' 2>&1';
    return (string) shell_exec($cmd);
};
$sid = static fn (): string => bin2hex(random_bytes(16));
$flag = static function (string $site, string $name, ?string $value): void {
    $path = $site . '/storage/' . $name;
    if ($value === null) {
        if (is_file($path)) {
            unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp file
        }
        return;
    }
    file_put_contents($path, $value);
};
$walk = static function (string $site, string $session, array $queries) use ($request): array {
    $out = [];
    foreach ($queries as $step => $query) {
        $out[$step] = $request($site, $query, $session);
    }
    return $out;
};

// ---------------------------------------------------------------------------
echo "A. Fresh install, steps 0 -> 7\n";
// ---------------------------------------------------------------------------
$site = $mkSite();
$check($seamsOk, 'test seams applied to the site copy (index.php and Installer.php shapes as expected)');
$flag($site, '.test_db_empty', '1');
$owner = $sid();
$out = $walk($site, $owner, [0 => 'step=0', 1 => 'step=1', 2 => 'step=2']);
foreach ([0, 1, 2] as $n) {
    $check(str_contains($out[$n], "[STEP $n]"), "fresh install: step $n");
}
$intruder = $request($site, 'step=0', $sid());
$check(!str_contains($intruder, '[STEP 0]'), 'fresh install: after step 2 another session does not get the wizard');
$out = $walk($site, $owner, [3 => 'step=3', 4 => 'step=4', 5 => 'step=5', 6 => 'step=6', 7 => 'step=7&force']);
foreach ([3, 4, 5, 6] as $n) {
    $check(str_contains($out[$n], "[STEP $n]"), "fresh install: step $n");
}
$check(str_contains($out[7], '[STEP 7 INSTALLED]') && is_file($site . '/.installed')
    && !is_file($site . '/storage/.install_in_progress'), 'fresh install: step 7 locks and releases the binding');

// ---------------------------------------------------------------------------
echo "B. ?force=1 reinstall onto an empty database, steps 0 -> 7\n";
// ---------------------------------------------------------------------------
$site = $mkSite();
file_put_contents($site . '/.env', "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_USER=u\nDB_PASS=p\nDB_NAME=old\n");
file_put_contents($site . '/.installed', "x\n");
$flag($site, '.test_db_empty', '0');   // the current database is populated
$flag($site, '.test_admin_ok', '1');   // and the operator is a valid admin of it
$admin = $sid();
$plain = $request($site, 'step=0', $admin);
$check(!str_contains($plain, '[STEP 0]')
    && (str_contains($plain, 'Applicazione già installata') || str_contains($plain, "Errore nella Verifica dell'Installazione")),
    'reinstall: without ?force the installed app shows the installed-state page');
// The real steps' forms and redirects carry no ?force after the first request.
$out = $walk($site, $admin, [0 => 'force=1', 1 => 'step=1', 2 => 'step=2']);
foreach ([0, 1, 2] as $n) {
    $check(str_contains($out[$n], "[STEP $n]"), "reinstall: step $n" . ($n > 0 ? ' (no ?force in the URL)' : ''));
}
// Step 2 pointed .env at a new, empty database: no admin exists there.
$flag($site, '.test_db_empty', '1');
$flag($site, '.test_admin_ok', null);
$out = $walk($site, $admin, [3 => 'step=3', 4 => 'step=4', 5 => 'step=5', 6 => 'step=6', 7 => 'step=7&force']);
foreach ([3, 4, 5, 6] as $n) {
    $check(str_contains($out[$n], "[STEP $n]"), "reinstall: step $n (owner, no admin in the new database)");
}
$check(str_contains($out[7], '[STEP 7 INSTALLED]') && !is_file($site . '/storage/.install_in_progress'),
    'reinstall: step 7 locks and releases the binding');
$after = $request($site, 'step=0', $admin);
$check(!str_contains($after, '[STEP 0]') && !str_contains($after, 'Autenticazione Admin Richiesta'),
    'reinstall: once finished, the routing hint is gone (plain installed-state page)');
$check(!str_contains($request($site, 'step=1', $sid()), '[STEP 1]'), 'reinstall: another session never got in');

// ---------------------------------------------------------------------------
echo "C. Owner over a populated database it did not fill\n";
// ---------------------------------------------------------------------------
$site = $mkSite();
file_put_contents($site . '/.env', "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_USER=u\nDB_PASS=p\nDB_NAME=old\n");
file_put_contents($site . '/.installed', "x\n");
$flag($site, '.test_db_empty', '0');
$flag($site, '.test_admin_ok', '1');
$admin = $sid();
$out = $walk($site, $admin, [0 => 'force=1', 1 => 'step=1&force=1', 2 => 'step=2&force=1']);
$check(str_contains($out[2], '[STEP 2]'), 'setup: the admin reaches step 2 and writes .env');
// The configured database is populated (not by this session) and the admin
// has since been suspended / signed out everywhere.
$flag($site, '.test_admin_ok', null);
$refused = $request($site, 'step=3&force=1', $admin);
$check(!str_contains($refused, '[STEP 3]') && str_contains($refused, 'Autenticazione Admin Richiesta'),
    'the owner is refused before importing the schema: admin login required');
$check(!str_contains($request($site, 'step=3', $admin), '[STEP 3]'), '...also without ?force in the URL');
$flag($site, '.test_admin_ok', '1');
$check(str_contains($request($site, 'step=3&force=1', $admin), '[STEP 3]'),
    'with a valid admin of the configured database the reinstall goes on');

// ---------------------------------------------------------------------------
echo "D. ?reset after step 2 keeps the owner's binding\n";
// ---------------------------------------------------------------------------
$site = $mkSite();
$flag($site, '.test_db_empty', '1');
$owner = $sid();
$out = $walk($site, $owner, [0 => 'step=0', 1 => 'step=1', 2 => 'step=2']);
$check(str_contains($out[2], '[STEP 2]') && is_file($site . '/storage/.install_in_progress'), 'setup: the owner wrote .env and holds the claim');
$afterReset = $request($site, 'reset=1', $owner);
$check(str_contains($afterReset, '[STEP 0]'), 'after ?reset the owner gets the wizard again (not the installed-state page)');
// The browser follows the new session id the reset issued.
$owner = preg_match('/\[SID ([A-Za-z0-9,-]+)\]/', $afterReset, $m) === 1 ? $m[1] : $owner;
$out = $walk($site, $owner, [1 => 'step=1', 2 => 'step=2']);
$check(str_contains($out[1], '[STEP 1]') && str_contains($out[2], '[STEP 2]'), 'and can walk the steps again after the reset');
$check(!str_contains($request($site, 'step=0', $sid()), '[STEP 0]'), 'another session is still shut out after the reset');

// ---------------------------------------------------------------------------
// Cleanup: only the private temp tree created above.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // nosemgrep
}
@rmdir($tmpRoot);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
