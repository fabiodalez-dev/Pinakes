<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, hardening follow-up — files, imports, updater,
 * backup, installer (group B).
 *
 *   A. BackupManager::resolveBackup refuses '.', and the mysql client sandbox
 *      option is only picked when the client lists it            (34, 36)
 *   B. Updater: release lookups refuse a non-version tag; deleteDirectory
 *      never descends into a symlink; the temp sweep waits while an update
 *      holds the lock; the patch/cleanup root checks are separator-exact;
 *      deleteBackup refuses '.'                                   (35, 37, 38)
 *   C. UpdateController::getLogs line clamp and no path in the 404 (32)
 *   D. DigitalAttachments: multi-encoded '..' is refused            (81)
 *   E. SettingsController::removableLogoFile containment            (80)
 *   F. manual-upgrade.php: empty/short password counts as unset     (26)
 *   G. Installer: no predictable key fallback, no -p<password>, installed
 *      site answers detect_socket/?reset with the installed page, no raw DB
 *      error, no createHtaccess() on an installed site, session ini   (18-23, 59, 61)
 *   H. Source guards: cover fetch through SsrfGuard, separator-exact cover
 *      unlinks, LibraryThing export formula prefix, staging ids, DataTables
 *      order dir, CMS NUL strip                         (5, 43, 44, 45, 46, 82)
 *   I. ProtectedUploadController: raw archive documents follow the unit's
 *      published/deleted state (needs a database: E2E_DB_*; skipped otherwise) (9, 77)
 *
 * Run: php tests/security-hardening-files-updater.unit.php
 */

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$tmp = sys_get_temp_dir() . '/pk_hardening_b_' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
$rm = static function (string $dir) use (&$rm): void {
    if (is_link($dir) || is_file($dir)) { @unlink($dir); return; } // nosemgrep -- test temp tree
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $rm($dir . '/' . $e);
    }
    @rmdir($dir);
};
/** Call a static method that the fix introduces; a missing method is a failed check, not a fatal. */
$static = static fn (string $class, string $name): callable => method_exists($class, $name)
    ? [$class, $name]
    : static fn (mixed ...$args): string => '__missing__';
$src = static fn (string $rel): string => (string) file_get_contents($ROOT . '/' . $rel);
/** Body of a method, from its signature to the next method signature. */
$method = static function (string $code, string $name): string {
    $start = strpos($code, 'function ' . $name . '(');
    if ($start === false) { return ''; }
    $next = preg_match('/\n    (?:public|private|protected)(?: static)? function /', $code, $m, PREG_OFFSET_CAPTURE, $start + 10);
    return $next === 1 ? substr($code, $start, $m[0][1] - $start) : substr($code, $start);
};

try {
    // -----------------------------------------------------------------------
    echo "A. BackupManager\n";
    // -----------------------------------------------------------------------
    $bmRef = new ReflectionClass(\App\Support\BackupManager::class);
    $bm = $bmRef->newInstanceWithoutConstructor();
    $backups = $tmp . '/storage/backups';
    mkdir($backups . '/legacy_dir', 0700, true);
    file_put_contents($backups . '/pinakes_backup_2026-01-01_000000.zip', 'x');
    mkdir($tmp . '/storage/backups-old', 0700, true);
    file_put_contents($tmp . '/storage/backups-old/x.zip', 'x');
    $bmRef->getProperty('backupPath')->setValue($bm, $backups);
    $bmRef->getProperty('rootPath')->setValue($bm, $tmp);
    $resolve = static fn (string $n): ?string => $bmRef->getMethod('resolveBackup')->invoke($bm, $n);
    $check($resolve('.') === null, "resolveBackup('.') is refused (it is the backups directory itself)");
    $check($resolve('') === null && $resolve('..') === null && $resolve('../backups-old/x.zip') === null, 'empty, .. and traversal still refused');
    $check($resolve('pinakes_backup_2026-01-01_000000.zip') !== null, 'a real backup still resolves');
    $check($resolve('legacy_dir') !== null, 'a legacy backup directory still resolves');
    $res = $bm->deleteBackup('.');
    $check($res['success'] === false && is_file($backups . '/pinakes_backup_2026-01-01_000000.zip'), "deleteBackup('.') deletes nothing");

    $mariaHelp = "  --sandbox           Disallow commands that access the file system (except \\P\n  --binary-mode       Binary mode";
    $mysqlHelp = "  --system-command    Enable or disable (by default) the 'system' mysql command.\n";
    $sandboxOpt = $static(\App\Support\BackupManager::class, 'clientSandboxOption');
    $check($sandboxOpt($mariaHelp) === '--sandbox', 'MariaDB client: --sandbox');
    $check($sandboxOpt($mysqlHelp) === '--system-command=OFF', 'MySQL 8.4.3+/9.1+ client: --system-command=OFF');
    $check($sandboxOpt("  --binary-mode  x\n  --silent  y\n") === '', 'older client: no unknown option is passed');
    $check($sandboxOpt("  --skip-sandbox-mention\n Use --sandbox to ...") === '', 'only an option line counts, not a mention in prose');
    $cli = $method($src('app/Support/BackupManager.php'), 'importViaCli');
    $check(str_contains($cli, 'clientSandboxOption(') && strpos($cli, '--defaults-extra-file=') < strpos($cli, '$sandbox !== \'\''), 'importViaCli passes the sandbox option after --defaults-extra-file');

    // -----------------------------------------------------------------------
    echo "B. Updater\n";
    // -----------------------------------------------------------------------
    $upRef = new ReflectionClass(\App\Support\Updater::class);
    $up = $upRef->newInstanceWithoutConstructor();
    $site = $tmp . '/site';
    mkdir($site . '/storage/tmp', 0700, true);
    mkdir($site . '/storage/cache', 0700, true);
    mkdir($site . '/storage/backups', 0700, true);
    $upRef->getProperty('rootPath')->setValue($up, $site);
    $upRef->getProperty('backupPath')->setValue($up, $site . '/storage/backups');
    $getRelease = $upRef->getMethod('getReleaseByVersion');
    foreach (['1.0.0/../../../users/x', '../latest', 'v1.0.0?x=1', 'manual-2026-01-01-000000', ''] as $bad) {
        $check($getRelease->invoke($up, $bad) === null, 'getReleaseByVersion refuses ' . var_export($bad, true) . ' (no API call)');
    }
    foreach (['0.9.5', 'v0.9.5', '0.9.0-rc.3'] as $good) {
        $check(defined(\App\Support\Updater::class . '::RELEASE_VERSION_PATTERN') && preg_match(constant(\App\Support\Updater::class . '::RELEASE_VERSION_PATTERN'), $good) === 1, "release version pattern accepts {$good}");
    }

    // deleteDirectory and symlinks
    $outside = $tmp . '/outside';
    mkdir($outside, 0700, true);
    file_put_contents($outside . '/keep.txt', 'keep');
    $victim = $site . '/storage/tmp/pinakes_update_x';
    mkdir($victim . '/sub', 0700, true);
    file_put_contents($victim . '/sub/a.txt', 'a');
    symlink($outside, $victim . '/link');
    $upRef->getMethod('deleteDirectory')->invoke($up, $victim);
    $check(!file_exists($victim) && !is_link($victim . '/link'), 'deleteDirectory removes the tree and the link');
    $check(is_file($outside . '/keep.txt'), 'deleteDirectory never empties a symlinked directory');
    symlink($outside, $site . '/storage/tmp/toplink');
    $upRef->getMethod('deleteDirectory')->invoke($up, $site . '/storage/tmp/toplink');
    $check(!is_link($site . '/storage/tmp/toplink') && is_file($outside . '/keep.txt'), 'a symlink passed as the root is unlinked, its target kept');

    // cleanupOldTempDirs waits while the update lock is held
    $old = $site . '/storage/tmp/pinakes_app_backup_2026';
    mkdir($old, 0700, true);
    file_put_contents($old . '/f', 'x');
    touch($old, time() - 7200);
    $lock = fopen($site . '/storage/cache/update.lock', 'c');
    flock($lock, LOCK_EX);
    $upRef->getMethod('cleanupOldTempDirs')->invoke($up, $site . '/storage/tmp');
    $check(is_dir($old), 'an old rollback copy survives the sweep while an update holds the lock');
    flock($lock, LOCK_UN);
    fclose($lock);
    touch($old, time() - 7200);
    $upRef->getMethod('cleanupOldTempDirs')->invoke($up, $site . '/storage/tmp');
    $check(!is_dir($old), 'and is swept once no update runs');

    // separator-exact root checks
    $sibling = $tmp . '/site-sibling';
    mkdir($sibling, 0700, true);
    file_put_contents($sibling . '/f.php', '<?php // a');
    file_put_contents($site . '/inside.php', '<?php // a');
    $apply = $upRef->getMethod('applySinglePatch');
    $r = $apply->invoke($up, ['file' => '../site-sibling/f.php', 'search' => 'a', 'replace' => 'b']);
    $check(($r['success'] ?? true) === false && str_contains((string) file_get_contents($sibling . '/f.php'), '// a'), 'applySinglePatch refuses a sibling directory sharing the root prefix');
    $r = $apply->invoke($up, ['file' => 'inside.php', 'search' => '// a', 'replace' => '// b']);
    $check(($r['success'] ?? false) === true && str_contains((string) file_get_contents($site . '/inside.php'), '// b'), 'applySinglePatch still patches a file inside the root');
    $cleanup = $upRef->getMethod('cleanupFile');
    $r = $cleanup->invoke($up, '../site-sibling/f.php');
    $check(($r['success'] ?? true) === false && is_file($sibling . '/f.php'), 'cleanupFile refuses the sibling directory');
    $r = $cleanup->invoke($up, '.');
    $check(($r['success'] ?? true) === false && is_dir($site), 'cleanupFile refuses the root itself');
    mkdir($site . '/storage/backups/b1', 0700, true);
    $r = $up->deleteBackup('.');
    $check($r['success'] === false && is_dir($site . '/storage/backups/b1'), "Updater::deleteBackup('.') deletes nothing");

    // -----------------------------------------------------------------------
    echo "C. UpdateController::getLogs\n";
    // -----------------------------------------------------------------------
    $clamp = $static(\App\Controllers\UpdateController::class, 'clampLogLines');
    $check($clamp('-50') === 1 && $clamp(-1) === 1 && $clamp('0') === 1, 'a negative or zero line count becomes 1 (not "all but the first N")');
    $check($clamp(null) === 200 && $clamp(['x']) === 200 && $clamp('abc') === 200, 'missing, array or non-numeric falls back to 200');
    $check($clamp('300') === 300 && $clamp('999999') === 5000, 'a normal value is kept, a huge one capped');
    $logs = $method($src('app/Controllers/UpdateController.php'), 'getLogs');
    $check(!str_contains($logs, "'path' =>"), 'the 404 body no longer carries the log path');

    // -----------------------------------------------------------------------
    echo "D. DigitalAttachments\n";
    // -----------------------------------------------------------------------
    $rejects = static function (string $url): bool {
        try {
            \App\Support\DigitalAttachments::normalize([['url' => $url, 'kind' => 'ebook']]);
            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    };
    $check($rejects('/uploads/digital/%252e%252e/x.pdf'), "double-encoded '..' is refused");
    $check($rejects('/uploads/digital/%25252e%25252e/x.pdf'), "triple-encoded '..' is refused");
    $check($rejects('/uploads/digital/%2e%2e/x.pdf') && $rejects('/uploads/digital/../x.pdf'), 'single-encoded and plain .. still refused');
    $check(!$rejects('/uploads/digital/50%25%20off.pdf') && !$rejects('/uploads/digital/libro.pdf'), 'a legitimate percent-encoded name still passes');

    // -----------------------------------------------------------------------
    echo "E. SettingsController::removableLogoFile\n";
    // -----------------------------------------------------------------------
    $pub = $tmp . '/public';
    mkdir($pub . '/uploads/settings', 0700, true);
    mkdir($pub . '/assets', 0700, true);
    mkdir($pub . '/uploads/settings-x', 0700, true);
    file_put_contents($pub . '/uploads/settings/logo_1.png', 'x');
    file_put_contents($pub . '/assets/logo_ab12.png', 'x');
    file_put_contents($pub . '/assets/main.css', 'x');
    file_put_contents($pub . '/uploads/settings-x/a.png', 'x');
    file_put_contents($pub . '/index.php', 'x');
    $rl = $static(\App\Controllers\SettingsController::class, 'removableLogoFile');
    $check($rl($pub, '/uploads/settings/logo_1.png') === realpath($pub . '/uploads/settings/logo_1.png'), 'an uploaded settings logo may be removed');
    $check($rl($pub, '/assets/logo_ab12.png') === realpath($pub . '/assets/logo_ab12.png'), 'the installer logo in public/assets may be removed');
    $check($rl($pub, '/assets/main.css') === null, 'any other public/assets file may not');
    $check($rl($pub, '/uploads/settings/../../index.php') === null && $rl($pub, '/index.php') === null, 'traversal out of the logo folders refused');
    $check($rl($pub, '/uploads/settings-x/a.png') === null, 'sibling directory sharing the prefix refused');

    // -----------------------------------------------------------------------
    echo "F. manual-upgrade.php password\n";
    // -----------------------------------------------------------------------
    $mu = $src('scripts/manual-upgrade.php');
    $a = strpos($mu, "define('UPGRADE_PASSWORD_MIN_LENGTH'");
    $b = $a === false ? false : strpos($mu, "\n}\n", $a);
    $check($a !== false && $b !== false, 'password helper present');
    if ($a !== false && $b !== false) {
        $fixture = $tmp . '/mu_fixture.php';
        file_put_contents($fixture, "<?php\n" . substr($mu, $a, $b + 3 - $a));
        require $fixture;
        $fn = new ReflectionFunction('upgradePasswordConfigured');
        $configured = static fn (string $p): bool => (bool) $fn->invoke($p);
        $check(!$configured(''), 'an empty password is "not configured" (it accepted an empty password)');
        $check(!$configured('   ') && !$configured('abc123'), 'blank and short passwords are not configured');
        $check(!$configured('pinakes2026'), 'the shipped default is not configured');
        $check($configured('a-long-operator-secret'), 'a real password is accepted');
        // The login handler (upgradeAuthHandlePasswordPost) is run for real by
        // tests/security-audit-manual-upgrade-throttle.unit.php, with the
        // default and a short configured password; this pins the wiring.
        $check(str_contains($mu, '!upgradePasswordConfigured($configured)'), 'the login refuses while it is not configured');
    }

    // -----------------------------------------------------------------------
    echo "G. Installer\n";
    // -----------------------------------------------------------------------
    $inst = $src('installer/classes/Installer.php');
    require_once $ROOT . '/installer/classes/Installer.php';
    $instRef = new ReflectionClass(\Installer::class);

    // generateEncryptionKey(): the key it returns is 32 CSPRNG bytes, fresh
    // on every call.
    $installer = $instRef->newInstanceWithoutConstructor();
    $genKey = $instRef->getMethod('generateEncryptionKey');
    $k1 = (string) $genKey->invoke($installer);
    $k2 = (string) $genKey->invoke($installer);
    $raw = str_starts_with($k1, 'base64:') ? base64_decode(substr($k1, 7), true) : false;
    $check(is_string($raw) && strlen($raw) === 32 && $k1 !== $k2, 'generateEncryptionKey returns 32 fresh random bytes (base64:)');
    // Its failure branch (random_bytes() unavailable) cannot be triggered
    // from a test: random_bytes is a core function and Installer lives in
    // the global namespace, so it cannot be shadowed. That branch is checked
    // on the method's token stream instead — robust to formatting and
    // variable names: the only entropy source called is random_bytes(), no
    // predictable source (uniqid/mt_rand/rand/microtime/time/hash/md5/sha1)
    // is called anywhere in the method, and every catch block throws and
    // never returns.
    $methodTokens = static function (string $code, string $name): array {
        $tokens = token_get_all($code);
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_FUNCTION) { continue; }
            $j = $i + 1;
            while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
            if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) { continue; }
            while ($tokens[$j] !== '{') { $j++; }
            $depth = 0;
            $body = [];
            for (; $j < count($tokens); $j++) {
                $tk = $tokens[$j];
                $s = is_array($tk) ? $tk[1] : $tk;
                if ($s === '{' || (is_array($tk) && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
                if ($s === '}') { $depth--; }
                $body[] = $tk;
                if ($depth === 0) { return $body; }
            }
        }
        return [];
    };
    $significant = static fn (array $tokens): array => array_values(array_filter($tokens, static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $keyBody = $significant($methodTokens($inst, 'generateEncryptionKey'));
    $calls = [];
    $catchBlocks = [];
    foreach ($keyBody as $i => $t) {
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ($keyBody[$i + 1] ?? null) === '(') {
            $calls[] = strtolower(ltrim($t[1], '\\'));
        }
        if (is_array($t) && $t[0] === T_CATCH) {
            $k = $i;
            while (($keyBody[$k] ?? '{') !== '{') { $k++; }
            $depth = 0;
            $block = [];
            for (; $k < count($keyBody); $k++) {
                $s = is_array($keyBody[$k]) ? $keyBody[$k][1] : $keyBody[$k];
                $depth += $s === '{' ? 1 : ($s === '}' ? -1 : 0);
                $block[] = $keyBody[$k];
                if ($depth === 0) { break; }
            }
            $catchBlocks[] = array_map(static fn ($tk): int => is_array($tk) ? $tk[0] : 0, $block);
        }
    }
    $check($keyBody !== [] && in_array('random_bytes', $calls, true)
        && array_intersect($calls, ['uniqid', 'mt_rand', 'rand', 'microtime', 'time', 'hash', 'md5', 'sha1', 'lcg_value', 'getmypid']) === [],
        'generateEncryptionKey: random_bytes is its only entropy source (calls: ' . implode(', ', array_unique($calls)) . ')');
    $check($catchBlocks !== [] && array_filter($catchBlocks, static fn (array $kinds): bool => !in_array(T_THROW, $kinds, true) || in_array(T_RETURN, $kinds, true)) === [],
        'generateEncryptionKey: a CSPRNG failure throws instead of returning a fallback key');
    $check(!preg_match("/' -p' \\. escapeshellarg/", $inst), 'no -p<password> on the mysql command line');

    // runMysqlClientImport(): run it for real against a stand-in `mysql`
    // first on PATH, which records its argv, the credentials file it was
    // pointed at (content + mode, read while the client runs) and stdin.
    $fakeBin = $tmp . '/fakebin';
    mkdir($fakeBin, 0700, true);
    $trace = $tmp . '/mysql-trace.json';
    file_put_contents($fakeBin . '/mysql', '#!' . PHP_BINARY . "\n<?php\n"
        . '$cnf = null; foreach ($argv as $a) { if (str_starts_with($a, "--defaults-extra-file=")) { $cnf = substr($a, 22); } }' . "\n"
        . 'file_put_contents(' . var_export($trace, true) . ', json_encode(["argv" => array_slice($argv, 1), "cnf" => $cnf,'
        . ' "cnf_body" => $cnf !== null ? @file_get_contents($cnf) : null, "cnf_mode" => $cnf !== null ? (fileperms($cnf) & 0777) : null,'
        . ' "stdin" => stream_get_contents(STDIN)]));' . "\n"
        . 'fwrite(STDOUT, "fake-client-ran\n"); exit(3);' . "\n");
    chmod($fakeBin . '/mysql', 0700);
    $sqlFile = $tmp . '/import.sql';
    file_put_contents($sqlFile, "SELECT 'hb-import';\n");
    $secret = 'Pw-' . bin2hex(random_bytes(6)) . '"q\\';
    $runInst = $instRef->newInstanceWithoutConstructor();
    $instRef->getProperty('config')->setValue($runInst, ['DB_HOST' => 'db.invalid', 'DB_PORT' => '3307', 'DB_NAME' => 'pk_inst_db', 'DB_USER' => 'pk_user', 'DB_PASS' => $secret]);
    $oldPath = (string) getenv('PATH');
    putenv('PATH=' . $fakeBin . PATH_SEPARATOR . $oldPath);
    try {
        [$exitCode, $output] = $instRef->hasMethod('runMysqlClientImport')
            ? $instRef->getMethod('runMysqlClientImport')->invoke($runInst, $sqlFile)
            : [-1, ['runMysqlClientImport() is missing']];
    } finally {
        putenv('PATH=' . $oldPath);
    }
    $seen = json_decode((string) @file_get_contents($trace), true) ?: [];
    $argvSeen = implode(' ', (array) ($seen['argv'] ?? []));
    $check($exitCode === 3 && in_array('fake-client-ran', (array) $output, true), 'runMysqlClientImport ran the client and returned its exit code and output');
    $check($argvSeen !== '' && !str_contains($argvSeen, substr($secret, 0, 15)) && !preg_match('/(?:^|\s)-p\S/', $argvSeen),
        'the password never appears on the client command line');
    $check(str_starts_with((string) ($seen['argv'][0] ?? ''), '--defaults-extra-file='), '--defaults-extra-file is the first option');
    $check(($seen['cnf_mode'] ?? null) === 0600, 'the credentials file is 0600 while the client runs');
    $check(str_contains((string) ($seen['cnf_body'] ?? ''), 'password="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $secret) . '"')
        && str_contains((string) ($seen['cnf_body'] ?? ''), 'user="pk_user"'), 'the credentials file carries the (escaped) user and password');
    $check(($seen['cnf'] ?? null) !== null && !file_exists((string) $seen['cnf']), 'the credentials file is removed once the client exits');
    $check(($seen['stdin'] ?? null) === "SELECT 'hb-import';\n" && str_contains($argvSeen, 'pk_inst_db'), 'the SQL file is fed on stdin to the named database');

    // A real installer request against an installed site whose DB is unreachable.
    $isite = $tmp . '/isite';
    mkdir($isite . '/installer/classes', 0700, true);
    mkdir($isite . '/storage/sessions', 0700, true);
    copy($ROOT . '/installer/index.php', $isite . '/installer/index.php');
    copy($ROOT . '/installer/classes/Installer.php', $isite . '/installer/classes/Installer.php');
    copy($ROOT . '/installer/classes/Validator.php', $isite . '/installer/classes/Validator.php');
    file_put_contents($isite . '/.env', "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_USER=leak_user_zz\nDB_PASS=x\nDB_NAME=leak_db_zz\n");
    file_put_contents($isite . '/.installed', date('c'));
    $runner = $tmp . '/installer_request.php';
    file_put_contents($runner, <<<'PHP'
<?php
[, $site, $query, $sid, $seedUser] = $argv;
parse_str($query, $_GET);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/installer/index.php';
$_SERVER['REMOTE_ADDR'] = '198.51.100.30';
ini_set('session.cache_limiter', '');
ini_set('error_log', $site . '/php-error.log');
if ($sid !== '') { session_id($sid); }
if ($seedUser === '1') {
    ini_set('session.save_path', $site . '/storage/sessions');
    session_start();
    $_SESSION['user'] = ['id' => 7, 'tipo_utente' => 'admin'];
    session_write_close();
    echo '[SEEDED]';
    exit(0);
}
require $site . '/installer/index.php';
PHP);
    $req = static function (string $query, string $sid = '', string $seed = '0') use ($runner, $isite): string {
        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($isite) . ' '
            . escapeshellarg($query) . ' ' . escapeshellarg($sid) . ' ' . escapeshellarg($seed) . ' 2>&1');
    };
    $out = $req('action=detect_socket');
    $check(!str_contains($out, '{"socket"'), 'installed site: detect_socket answers the installed-state page, not the socket probe');
    $check(!str_contains($out, 'leak_user_zz') && !str_contains($out, 'leak_db_zz') && !str_contains($out, 'SQLSTATE') && !str_contains($out, 'Connection refused'), 'installed site: the DB error is not rendered');
    $check(str_contains($out, 'installer-container'), 'installed site: a page is still rendered');
    $check(str_contains((string) @file_get_contents($isite . '/php-error.log'), 'installation check failed'), 'installed site: the DB error is logged instead');
    $check(!is_file($isite . '/.htaccess'), 'installed site: no root .htaccess written by an anonymous request');

    $sid = 'pkhardening' . bin2hex(random_bytes(8));
    $req('', $sid, '1');
    $sessFile = $isite . '/storage/sessions/sess_' . $sid;
    $check(is_file($sessFile) && str_contains((string) file_get_contents($sessFile), 'tipo_utente'), 'setup: a main-app session exists');
    $req('reset=1', $sid);
    clearstatcache();
    $check(is_file($sessFile) && str_contains((string) file_get_contents($sessFile), 'tipo_utente'), 'installed site: ?reset no longer destroys the shared main-app session');

    $index = $src('installer/index.php');
    $check(str_contains($index, "ini_set('session.gc_maxlifetime', (string) (1440 * 60))") && str_contains($index, "ini_set('session.use_strict_mode', '1')"), 'installer mirrors the session GC lifetime ceiling and strict mode');
    $check(!str_contains($src('installer/.htaccess'), 'RewriteCond %{DOCUMENT_ROOT}/.installed') && !preg_match('/RewriteCond %\{(?:REQUEST_URI|QUERY_STRING)\} !force/', $src('installer/.htaccess')), 'installer/.htaccess: misleading .installed/force rules removed');

    // -----------------------------------------------------------------------
    echo "H. Source guards\n";
    // -----------------------------------------------------------------------
    $libri = $src('app/Controllers/LibriController.php');
    $cover = $method($libri, 'handleCoverUrl');
    $check(str_contains($cover, 'SsrfGuard::fetchImage(') && !str_contains($cover, 'gethostbyname($host)') && !str_contains($cover, 'curl_init('), 'handleCoverUrl fetches through SsrfGuard::fetchImage only');
    $check(str_contains($cover, 'isValidImageMimeType($img)') && str_contains($cover, '2 * 1024 * 1024'), 'same 2 MB cap and image-type check');
    $check(!str_contains($libri, 'strpos($safePath, $baseDir) === 0') && substr_count($libri, 'str_starts_with($safePath, $baseDir . DIRECTORY_SEPARATOR)') === 2, 'both cover unlinks compare with a trailing separator');
    $check(str_contains($libri, '$formulaPrefix = "\'";') && !str_contains($libri, "\$format === 'librarything' ? null"), 'LibraryThing export uses the same formula prefix as the dedicated export');
    $check(!str_contains($src('app/Controllers/CsvImportController.php'), "'/csv_import_' . session_id()"), 'CSV staging file name carries no session id');
    $check(!str_contains($src('app/Controllers/MarcImportController.php'), "session_id()) . '_'"), 'MARC staging file name carries no session id');
    foreach (['Autori', 'Utenti', 'Editori', 'Prestiti'] as $c) {
        $code = $src("app/Controllers/{$c}ApiController.php");
        $check(str_contains($code, "is_string(\$q['order'][0]['dir']) && strtoupper("), "{$c}ApiController: order dir is_string guard");
    }
    $check(str_contains($src('app/Controllers/Admin/CmsAdminController.php'), 'str_replace("\\0", \'\', $newFilename)'), 'CmsAdminController strips a real NUL byte');

    // -----------------------------------------------------------------------
    echo "I. ProtectedUploadController archive documents\n";
    // -----------------------------------------------------------------------
    // Credentials: E2E_DB_* first (CI), then the worktree .env (local gate).
    // The sandbox is <DB_NAME>_hardb, so a developer user granted on its own
    // database prefix (e.g. fabiodal_biblioteca_wt%) can create and drop it.
    $dotenv = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents(dirname(__DIR__) . '/.env')) as $line) {
        if (str_contains($line, '=') && !str_starts_with(trim($line), '#')) {
            [$k, $v] = explode('=', $line, 2);
            $dotenv[trim($k)] = trim(trim($v), "\"'");
        }
    }
    $cfg = static fn (string $e2e, string $plain): string => (string) (getenv($e2e) ?: ($dotenv[$plain] ?? ''));
    $dbUser = $cfg('E2E_DB_USER', 'DB_USER');
    $dbPass = $cfg('E2E_DB_PASS', 'DB_PASS');
    $dbHost = $cfg('E2E_DB_HOST', 'DB_HOST');
    $dbPort = (int) ($cfg('E2E_DB_PORT', 'DB_PORT') ?: 3306);
    $socket = $cfg('E2E_DB_SOCKET', 'DB_SOCKET');
    $baseDb = $cfg('E2E_DB_NAME', 'DB_NAME');
    $db = null;
    if ($dbUser !== '' && $baseDb !== '') {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = $socket !== '' && file_exists($socket)
                ? new mysqli('localhost', $dbUser, $dbPass, '', 0, $socket)
                : new mysqli($dbHost !== '' ? $dbHost : '127.0.0.1', $dbUser, $dbPass, '', $dbPort);
        } catch (\Throwable $e) {
            $db = null;
        }
    }
    if (!$db instanceof mysqli) {
        echo "SKIP: no reachable database configured (E2E_DB_* or .env)\n";
    } else {
        $sandbox = preg_replace('/[^A-Za-z0-9_]/', '', $baseDb) . '_hardb';
        $db->query("CREATE DATABASE IF NOT EXISTS `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $db->select_db($sandbox);
            $ctlRef = new ReflectionClass(\App\Controllers\ProtectedUploadController::class);
            $visible = static function (string $path) use ($ctlRef, $db): bool {
                $ctl = new \App\Controllers\ProtectedUploadController($db);
                if (!$ctlRef->hasMethod('archiveDocumentVisible')) { return true; }
                return (bool) $ctlRef->getMethod('archiveDocumentVisible')->invoke($ctl, $path);
            };
            $_SESSION = [];
            $check($visible('/uploads/archives/documents/1-a.pdf'), 'no archives tables: unchanged behaviour (served)');
            $db->query('CREATE TABLE archival_units (id INT PRIMARY KEY, published TINYINT(1) NOT NULL DEFAULT 1, deleted_at DATETIME NULL, document_path VARCHAR(500) NULL)');
            $db->query('CREATE TABLE archival_unit_files (id INT AUTO_INCREMENT PRIMARY KEY, unit_id INT NOT NULL, file_path VARCHAR(500) NOT NULL)');
            $db->query("INSERT INTO archival_units VALUES (1, 1, NULL, '/uploads/archives/documents/legacy-1.pdf'), (2, 0, NULL, NULL), (3, 1, NOW(), NULL), (4, 1, NULL, NULL)");
            $db->query("INSERT INTO archival_unit_files (unit_id, file_path) VALUES (4, '/uploads/archives/documents/4-pub.pdf'), (2, '/uploads/archives/documents/2-unpub.pdf'), (3, '/uploads/archives/documents/3-deleted.pdf')");
            $check($visible('/uploads/archives/documents/4-pub.pdf'), 'file of a published unit is served');
            $check($visible('/uploads/archives/documents/legacy-1.pdf'), 'legacy document_path of a published unit is served');
            $check(!$visible('/uploads/archives/documents/2-unpub.pdf'), 'file of an unpublished unit: refused');
            $check(!$visible('/uploads/archives/documents/3-deleted.pdf'), 'file of a soft-deleted unit: refused');
            $check(!$visible('/uploads/archives/documents/orphan.pdf'), 'file belonging to no unit: refused');
            $db->query('CREATE TABLE utenti (id INT PRIMARY KEY, tipo_utente VARCHAR(16), stato VARCHAR(16), ruolo_id INT NULL)');
            $db->query('CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(50), permessi TEXT)');
            $db->query("INSERT INTO ruoli VALUES (1, 'Cataloguer', '[\"backend.access\",\"catalog.manage\"]')");
            $db->query("INSERT INTO utenti VALUES (1, 'staff', 'attivo', NULL), (2, 'standard', 'attivo', NULL), (3, 'standard', 'attivo', 1)");
            $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'staff']];
            $check($visible('/uploads/archives/documents/2-unpub.pdf'), 'staff still opens an unpublished unit file (back-office links the raw path)');
            $_SESSION = ['user' => ['id' => 2, 'tipo_utente' => 'standard']];
            $check(!$visible('/uploads/archives/documents/2-unpub.pdf'), 'a signed-in reader does not');
            $_SESSION = ['user' => ['id' => 3, 'tipo_utente' => 'standard']];
            $check($visible('/uploads/archives/documents/2-unpub.pdf'), 'a custom cataloguer can follow a back-office link to an unpublished file');
            $db->query('UPDATE utenti SET ruolo_id = NULL WHERE id = 3');
            \App\Support\SessionRoleRevalidator::reset();
            $check(!$visible('/uploads/archives/documents/2-unpub.pdf'), 'a stale session loses access when the custom role is removed');
            $_SESSION = [];
        } finally {
            $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
            $db->close();
        }
    }
} finally {
    $rm($tmp);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
