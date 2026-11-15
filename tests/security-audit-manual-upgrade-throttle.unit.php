<?php
/**
 * Security audit follow-up: scripts/manual-upgrade.php kept its login-throttle
 * state in sys_get_temp_dir()/pinakes_upgrade_auth_<sha256(ip)>.json — a
 * predictable name in a host-shared directory, written with file_put_contents()
 * (which follows symlinks). A co-tenant could lock the operator out, reset the
 * counter, or overwrite any file the PHP user can write with the JSON state.
 *
 * The state now lives in <install>/storage/cache/upgrade-auth (0700, owner
 * checked, per-install file names), is updated under flock with an inode check,
 * and a counter that cannot be kept means no password check (fail closed).
 * DB-free: the helper region of the script is loaded between its markers.
 */
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/scripts/manual-upgrade.php');
$start = strpos($source, '// BEGIN upgrade-auth-throttle');
$end = strpos($source, '// END upgrade-auth-throttle');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "FAIL: throttle helpers not found between their markers\n");
    exit(1);
}
$fixture = tempnam(sys_get_temp_dir(), 'upgrade_throttle_fixture_');
file_put_contents($fixture, "<?php\n" . substr($source, $start, $end - $start));
require $fixture;
unlink($fixture); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp file

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$tmp = sys_get_temp_dir() . '/upgrade_throttle_' . bin2hex(random_bytes(6));
$mkRoot = static function (string $name) use ($tmp): string {
    $root = $tmp . '/' . $name;
    mkdir($root . '/storage', 0775, true);
    return $root;
};
$rm = static function (string $dir) use (&$rm): void {
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . '/' . $e;
        if (is_link($p) || is_file($p)) { unlink($p); } // nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp tree
        else { $rm($p); }
    }
    rmdir($dir);
};

try {
    echo "A. Where the state lives\n";
    $rootA = $mkRoot('siteA');
    $fileA = upgradeAuthStateFile($rootA, '203.0.113.7');
    $check($fileA !== null && str_starts_with($fileA, $rootA . '/storage/cache/upgrade-auth/'), 'under the install, in storage/cache/upgrade-auth');
    $check($fileA !== null && !str_starts_with($fileA, sys_get_temp_dir() . '/pinakes_upgrade_auth_'), 'not the old predictable temp-dir name');
    $check((fileperms(dirname((string) $fileA)) & 0777) === 0700, 'state directory is 0700');
    $rootB = $mkRoot('siteB');
    $fileB = upgradeAuthStateFile($rootB, '203.0.113.7');
    $check($fileA !== $fileB && basename((string) $fileA) !== basename((string) $fileB), 'two installs never share a file name for the same address');
    $check(basename((string) $fileA) !== basename((string) upgradeAuthStateFile($rootA, '203.0.113.8')), 'different addresses, different files');

    echo "B. Counting and locking\n";
    $bump = static function (array $s): array {
        $s['failed']++;
        if ($s['failed'] >= 5) { $s['lock_until'] = time() + 300; $s['failed'] = 0; }
        return $s;
    };
    $state = null;
    for ($i = 1; $i <= 4; $i++) { $state = upgradeAuthUpdate((string) $fileA, $bump); }
    $check($state !== null && $state['failed'] === 4 && $state['lock_until'] === 0, 'four failures are counted, no lock yet');
    $state = upgradeAuthUpdate((string) $fileA, $bump);
    $check($state !== null && $state['lock_until'] > time() && $state['failed'] === 0, 'the fifth locks for five minutes');
    $read = upgradeAuthUpdate((string) $fileA, static fn (array $s): array => $s);
    $check($read !== null && $read['lock_until'] > time(), 'the lock persists across calls');
    upgradeAuthUpdate((string) $fileA, static fn (array $s): array => ['failed' => 0, 'lock_until' => 0]);
    $read = upgradeAuthUpdate((string) $fileA, static fn (array $s): array => $s);
    $check($read === ['failed' => 0, 'lock_until' => 0], 'a successful login resets it');
    file_put_contents((string) $fileA, 'not json');
    $read = upgradeAuthUpdate((string) $fileA, static fn (array $s): array => $s);
    $check($read === ['failed' => 0, 'lock_until' => 0], 'a corrupt state file reads as empty');

    echo "C. A planted symlink is never followed\n";
    $rootC = $mkRoot('siteC');
    $victim = $tmp . '/victim.txt';
    file_put_contents($victim, 'KEEP');
    $dirC = upgradeAuthStateDir($rootC);
    $evilName = $dirC . '/' . hash('sha256', (realpath($rootC) ?: $rootC) . "\0" . '198.51.100.1') . '.json';
    symlink($victim, $evilName);
    $fileC = upgradeAuthStateFile($rootC, '198.51.100.1');
    $check($fileC !== null && !is_link($fileC), 'a symlink at the state path is removed');
    upgradeAuthUpdate((string) $fileC, $bump);
    $check(file_get_contents($victim) === 'KEEP', 'the symlink target was never written');
    $check(is_file((string) $fileC) && !is_link((string) $fileC), 'a regular state file was created in its place');

    echo "D. An untrusted state directory fails closed\n";
    $rootD = $mkRoot('siteD');
    mkdir($rootD . '/storage/cache', 0775, true);
    mkdir($tmp . '/elsewhere');
    symlink($tmp . '/elsewhere', $rootD . '/storage/cache/upgrade-auth');
    $check(upgradeAuthStateDir($rootD) === null && upgradeAuthStateFile($rootD, '192.0.2.1') === null, 'a symlinked state directory is refused');
    $rootE = $mkRoot('siteE');
    file_put_contents($rootE . '/storage/cache', 'a file, not a directory');
    $check(upgradeAuthStateFile($rootE, '192.0.2.1') === null, 'a state directory that cannot be created yields no file');
    $check(upgradeAuthUpdate($tmp . '/no/such/dir/state.json', static fn (array $s): array => $s) === null, 'an unopenable state file yields null');
    $dirFile = $tmp . '/is-a-dir.json';
    mkdir($dirFile);
    $check(@upgradeAuthUpdate($dirFile, static fn (array $s): array => $s) === null, 'a directory in place of the state file yields null');

    echo "E. The page uses it and fails closed\n";
    $check(!str_contains($source, 'pinakes_upgrade_auth_') && !str_contains($source, "attemptFile = sys_get_temp_dir()"), 'the old temp-dir state file is gone from the script');
    $loginPos = strpos($source, "if (\$requestMethod === 'POST' && isset(\$_POST['password']))");
    $login = (string) substr($source, (int) $loginPos, 1800);
    $nullPos = strpos($login, '$attemptState === null');
    $passPos = strpos($login, 'hash_equals(UPGRADE_PASSWORD');
    $check($nullPos !== false && $passPos !== false && $nullPos < $passPos, 'without a usable counter the password is never checked');
    $check(str_contains($source, "tempnam(\$rootPath . '/storage/tmp', 'pinakes_dump_')"), 'the mysqldump credentials file is created inside the install, not the shared temp dir');
} finally {
    if (is_dir($tmp)) {
        $rm($tmp);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
