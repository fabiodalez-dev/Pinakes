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
$dumpStart = strpos($source, '// BEGIN upgrade-dump-credentials');
$dumpEnd = strpos($source, '// END upgrade-dump-credentials');
if ($dumpStart === false || $dumpEnd === false || $dumpEnd <= $dumpStart) {
    fwrite(STDERR, "FAIL: mysqldump credentials helper not found between its markers\n");
    exit(1);
}
$fixture = tempnam(sys_get_temp_dir(), 'upgrade_throttle_fixture_');
file_put_contents($fixture, "<?php\n" . substr($source, $start, $end - $start) . "\n" . substr($source, $dumpStart, $dumpEnd - $dumpStart));
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
// An install whose state directory is already there, as the web server user
// leaves it: lets these cases run under a root CI too, where the script
// refuses to create it (the explicit $euid = 0 case below covers that).
$mkStateRoot = static function (string $name) use ($mkRoot): string {
    $root = $mkRoot($name);
    mkdir($root . '/storage/cache/upgrade-auth', 0700, true);
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
    $rootA = $mkStateRoot('siteA');
    $fileA = upgradeAuthStateFile($rootA, '203.0.113.7');
    $check($fileA !== null && str_starts_with($fileA, $rootA . '/storage/cache/upgrade-auth/'), 'under the install, in storage/cache/upgrade-auth');
    $check($fileA !== null && !str_starts_with($fileA, sys_get_temp_dir() . '/pinakes_upgrade_auth_'), 'not the old predictable temp-dir name');
    $check((fileperms(dirname((string) $fileA)) & 0777) === 0700, 'state directory is 0700');
    $rootB = $mkStateRoot('siteB');
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
    $check($read !== null && $read['failed'] >= 5, 'a corrupt state file counts as attempts used up, never as a reset counter');
    $r = upgradeAuthReserveAttempt((string) $fileA, 5, 300);
    $check($r !== null && $r['allowed'] === false && upgradeAuthUpdate((string) $fileA, static fn (array $s): array => $s)['lock_until'] > time(),
        'so the next attempt locks without comparing the password');
    file_put_contents((string) $fileA, '');
    $check(upgradeAuthUpdate((string) $fileA, static fn (array $s): array => $s) === ['failed' => 0, 'lock_until' => 0],
        'an empty file, as created, starts from zero');
    upgradeAuthUpdate((string) $fileA, static fn (array $s): array => ['failed' => 12345, 'lock_until' => 0]);
    upgradeAuthUpdate((string) $fileA, static fn (array $s): array => ['failed' => 1, 'lock_until' => 0]);
    $check(file_get_contents((string) $fileA) === '{"failed":1,"lock_until":0}', 'a shorter state overwrites a longer one without leftovers');
    // The state is replaced whole (new file renamed over the old one), never
    // rewritten in place: an interrupted write cannot leave a valid-looking
    // lower count behind.
    $inodeBefore = fileinode((string) $fileA);
    upgradeAuthUpdate((string) $fileA, static fn (array $s): array => ['failed' => 2, 'lock_until' => 0]);
    clearstatcache();
    $check(fileinode((string) $fileA) !== $inodeBefore, 'each update replaces the state file atomically (new inode)');
    $check(glob(dirname((string) $fileA) . '/*.tmp') === [], 'and leaves no temporary file behind');
    $check(is_file((string) $fileA . '.lock'), 'the lock is held on a separate, stable file');

    echo "B2. The attempt is reserved before the password is checked\n";
    $rootR = $mkStateRoot('siteR');
    $fileR = (string) upgradeAuthStateFile($rootR, '203.0.113.50');
    $allowed = 0;
    for ($i = 0; $i < 8; $i++) {
        $r = upgradeAuthReserveAttempt($fileR, 5, 300);
        $allowed += ($r !== null && $r['allowed']) ? 1 : 0;
    }
    $check($allowed === 5, "sequentially, exactly 5 of 8 attempts are allowed (got {$allowed})");
    $check((upgradeAuthReserveAttempt($fileR, 5, 300)['allowed'] ?? true) === false, 'and the sixth is refused while locked');
    upgradeAuthUpdate($fileR, static fn (array $s): array => ['failed' => 0, 'lock_until' => 0]);
    $check((upgradeAuthReserveAttempt($fileR, 5, 300)['allowed'] ?? false) === true, 'a cleared counter allows attempts again');
    $check(upgradeAuthReserveAttempt($tmp . '/no/such/dir/state.json', 5, 300) === null, 'an unusable state file yields null (fail closed)');

    // Concurrency: 20 processes reserve at the same moment. Check-then-record
    // would let every one of them through; reservation allows exactly 5.
    $rootP = $mkStateRoot('siteP');
    $fileP = (string) upgradeAuthStateFile($rootP, '203.0.113.60');
    $helpers = $tmp . '/helpers.php';
    file_put_contents($helpers, "<?php\n" . substr($source, $start, $end - $start));
    $gate = $tmp . '/go';
    $children = [];
    for ($i = 0; $i < 20; $i++) {
        $code = 'require ' . var_export($helpers, true) . ';'
            . 'while (!is_file(' . var_export($gate, true) . ')) { usleep(1000); }'
            . '$r = upgradeAuthReserveAttempt(' . var_export($fileP, true) . ', 5, 300); echo ($r !== null && $r["allowed"]) ? "1" : "0";';
        $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $children[] = [$p, $pipes];
    }
    usleep(300000); // let every child reach the gate
    file_put_contents($gate, '1');
    $granted = 0;
    foreach ($children as [$p, $pipes]) {
        $granted += (int) trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        proc_close($p);
    }
    $check($granted === 5, "20 simultaneous attempts: exactly 5 may try the password (got {$granted})");

    echo "C. A planted symlink is never followed\n";
    $rootC = $mkStateRoot('siteC');
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

    echo "D2. A directory owned by someone else gets its own message\n";
    $rootO = $mkRoot('siteO');
    $foreignUid = (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()) + 4242;
    $problem = null;
    $check(upgradeAuthStateDir($rootO, $problem, $foreignUid) === null && $problem === 'foreign_owner',
        'a state directory owned by another user is refused as foreign_owner');
    $msg = upgradeAuthUnavailableMessage($problem, $rootO);
    $check(str_contains($msg, 'storage/cache/upgrade-auth') && str_contains($msg, 'chown') && str_contains($msg, 'Eliminala'),
        'the message names the folder to delete or chown');
    // The message is shown on the login form before anyone is authenticated.
    $check(!str_contains($msg, $rootO) && !str_contains($msg, $tmp),
        'it gives the folder relative to the install, never the absolute install path');
    $check(!str_contains($msg, 'rendi scrivibile'), 'it no longer blames a non-writable storage/cache');
    $problem = null;
    upgradeAuthStateDir($rootE, $problem);
    $check($problem === 'unavailable' && str_contains(upgradeAuthUnavailableMessage($problem, $rootE), 'rendi scrivibile'),
        'a directory that cannot be created keeps the writable-storage message');
    $check(str_contains(substr($source, $start, $end - $start), "if (\$euid === null) {"),
        'an unknown process user (no posix, no probe) fails closed instead of skipping the owner check');
    echo "D3. Without posix the process user is probed inside the install\n";
    $me = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $probeRoot = $mkRoot('siteProbe');
    mkdir($probeRoot . '/storage/cache', 0500);
    $owner = upgradeProbeFileOwner([$tmp . '/no/such/dir', $probeRoot . '/storage/cache', $probeRoot . '/storage']);
    $check($owner === $me, 'an unusable directory is skipped and the next one inside the install answers (open_basedir-safe)');
    $check(glob($probeRoot . '/storage/.pinakes_uid_*') === [] && glob($probeRoot . '/storage/cache/.pinakes_uid_*') === [], 'and the probe file is removed');
    chmod($probeRoot . '/storage/cache', 0700);
    $check(upgradeProbeFileOwner([$tmp . '/no/such/dir']) === null, 'no usable directory: the user stays unknown');
    $msg = upgradeAuthUnavailableMessage('uid_unknown', $probeRoot);
    $check(str_contains($msg, 'posix') && !str_contains($msg, $probeRoot), 'an unknown user gets its own message, naming the posix extension');
    $rootRoot = $mkRoot('siteRoot');
    $problem = null;
    $check(upgradeAuthStateDir($rootRoot, $problem, 0) === null && !file_exists($rootRoot . '/storage/cache'),
        'a run as root never creates storage/cache or the state directory');

    echo "E. The real password POST handler\n";
    $check(!str_contains($source, 'pinakes_upgrade_auth_') && !str_contains($source, "attemptFile = sys_get_temp_dir()"), 'the old temp-dir state file is gone from the script');
    $rootL = $mkStateRoot('siteL');
    $addr = '203.0.113.90';
    $fileL = (string) upgradeAuthStateFile($rootL, $addr);
    $readL = static fn (): array => (array) upgradeAuthUpdate($fileL, static fn (array $s): array => $s);
    $pw = 'correct horse battery';
    $calls = 0;
    $err = upgradeAuthHandlePasswordPost($rootL, $addr, 'wrong', $pw, 5, 300, static function () use (&$calls): void { $calls++; });
    $check($err === 'Password errata.' && $calls === 0 && $readL()['failed'] === 1, 'a wrong password is refused and counted');
    $seenDuringSuccess = null;
    $err = upgradeAuthHandlePasswordPost($rootL, $addr, $pw, $pw, 5, 300, static function () use (&$calls, &$seenDuringSuccess, $readL): void {
        $calls++;
        $seenDuringSuccess = $readL()['failed'];
    });
    $check($err === '' && $calls === 1, 'the right password succeeds and runs the success callback once');
    $check($seenDuringSuccess === 2, 'the attempt was already reserved when the password matched (counter 2 inside the callback, got ' . var_export($seenDuringSuccess, true) . ')');
    $check($readL() === ['failed' => 0, 'lock_until' => 0], 'and the counter is reset after the success');
    for ($i = 0; $i < 5; $i++) {
        upgradeAuthHandlePasswordPost($rootL, $addr, 'wrong', $pw, 5, 300, static function (): void {});
    }
    $calls = 0;
    $err = upgradeAuthHandlePasswordPost($rootL, $addr, $pw, $pw, 5, 300, static function () use (&$calls): void { $calls++; });
    $check(str_starts_with($err, 'Troppi tentativi') && $calls === 0, 'once locked, even the right password is refused without being compared');
    $rootDef = $mkStateRoot('siteDef');
    $err = upgradeAuthHandlePasswordPost($rootDef, $addr, 'pinakes2026', 'pinakes2026', 5, 300, static function () use (&$calls): void { $calls++; });
    $check(str_starts_with($err, 'SICUREZZA') && $calls === 0, 'the shipped default password never logs in');
    $err = upgradeAuthHandlePasswordPost($rootO, $addr, $pw, $pw, 5, 300, static function () use (&$calls): void { $calls++; }, $foreignUid);
    $check(str_contains($err, 'chown') && $calls === 0, 'a foreign-owned state directory fails closed with its own message');
    $err = upgradeAuthHandlePasswordPost($rootE, $addr, $pw, $pw, 5, 300, static function () use (&$calls): void { $calls++; });
    $check(str_contains($err, 'rendi scrivibile') && $calls === 0, 'no usable counter: no password check');

    echo "F. The CLI run never touches the throttle state\n";
    $install = $tmp . '/cli-install';
    mkdir($install . '/scripts', 0775, true);
    mkdir($install . '/storage', 0775, true);
    copy(dirname(__DIR__) . '/scripts/manual-upgrade.php', $install . '/scripts/manual-upgrade.php');
    file_put_contents($install . '/version.json', '{"version":"0.0.1"}');
    file_put_contents($install . '/package.zip', '');
    $p = proc_open([PHP_BINARY, $install . '/scripts/manual-upgrade.php', $install . '/package.zip'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    proc_close($p);
    $check(str_contains($out, '.env non trovato'), 'the CLI run reached the upgrade body (stops on the missing .env)');
    $check(!file_exists($install . '/storage/cache'), 'and created neither storage/cache nor the state directory');

    echo "G. Dump credentials travel only on the child's descriptor\n";
    $dump = $tmp . '/dump.sql';
    $reader = $tmp . '/dump-client';
    file_put_contents($reader, "#!/bin/sh\n" . <<<'SH'
test "$1" = '--defaults-extra-file=/dev/fd/3' || exit 1
cat <&3
echo child-warning >&2
SH
    );
    chmod($reader, 0700);
    $result = upgradeRunDump($reader, ['db'], ['DB_USER' => 'reader', 'DB_PASS' => 'test"#pw'], $dump);
    $options = file_get_contents($dump);
    $check($result === 0 && str_contains($options, 'password="test\\"#pw"'), 'the child reads quoted option values from its own pipe');
    $check(!str_contains($options, 'child-warning'), 'diagnostics stay out of the SQL dump');
    $check(upgradeRunDump($reader, ['db'], ['DB_USER' => 'reader', 'DB_PASS' => 'x'], $dump, $diag) === 0 && $diag === 'child-warning',
        'a successful dump still reports what the client printed on stderr');
    file_put_contents($reader, "#!/bin/sh\ncat <&3 >/dev/null\nexit 3\n");
    $check(upgradeRunDump($reader, [], [], $dump) === 3, 'a failed child aborts the backup');

    echo "H. A failed backup says why (Pullfrog review of PR #537)\n";
    file_put_contents($reader, "#!/bin/sh\ncat <&3 >/dev/null\n"
        . "printf 'mysqldump: Got error: 1045: Access denied for user \\047reader\\047@\\047localhost\\047 (using password: YES) when trying to connect\\n' >&2\n"
        . "exit 2\n");
    $code = upgradeRunDump($reader, ['db'], ['DB_USER' => 'reader', 'DB_PASS' => 'secret-pw'], $dump, $diag);
    $check($code === 2 && str_contains($diag, 'Access denied for user') && str_contains($diag, '1045'),
        'the client error reaches the caller instead of being discarded');
    $check(!str_contains($diag, 'secret-pw'), 'the diagnostics never carry the password');
    file_put_contents($reader, "#!/bin/sh\ncat <&3 >/dev/null\n"
        . "i=0; while [ \$i -lt 500 ]; do echo \"noise line \$i\" >&2; i=\$((i+1)); done\n"
        . "printf 'bad \\377\\376 bytes\\033[31m\\nlast: unknown database\\n' >&2\nexit 2\n");
    upgradeRunDump($reader, [], [], $dump, $diag);
    $check(strlen($diag) <= UPGRADE_DUMP_DIAGNOSTICS_BYTES + 400 && str_ends_with($diag, 'last: unknown database'),
        'a chatty client is cut to its last lines, which carry the cause');
    $check(preg_match('//u', $diag) === 1 && !preg_match('/[\x00-\x1F\x7F]/', $diag) && htmlspecialchars($diag, ENT_QUOTES, 'UTF-8') !== '',
        'non-UTF-8 bytes and control characters cannot blank the escaped error page');

} finally {
    if (is_dir($tmp)) {
        $rm($tmp);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
