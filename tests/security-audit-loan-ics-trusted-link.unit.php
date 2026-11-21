<?php
/**
 * Security audit follow-up (PR review): the per-loan "add to calendar" .ics
 * link mailed with the loan confirmation carries an HMAC token, like the
 * activation link. It was built with absoluteUrl(), which takes the host from
 * the request Host header when APP_TRUSTED_HOSTS is unset, so a request with a
 * forged Host could make the mail point the token at somebody else's domain.
 *
 * It is now built with TrustedLink (APP_CANONICAL_URL / APP_TRUSTED_HOSTS).
 * With no configured host the .ics button is left out of the email and the
 * Google Calendar button (no token) stays.
 *
 * Real LoanCalendarLinks against a real database (for the per-install
 * secret), inside a transaction that is always rolled back. Needs E2E_DB_* or
 * a .env (CI_STRICT_TESTS=1 turns a missing database into a failure).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\LoanCalendarLinks;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$skip = static function (string $why): never {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: {$why} (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP: {$why}\n";
    exit(0);
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    $env[$k] = trim($v, "\"'");
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
if ($name === '' || $user === '') {
    $skip('no database configured (E2E_DB_NAME/E2E_DB_USER or .env)');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $dbPass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    $skip('database not reachable (' . $e->getMessage() . ')');
}

$setEnv = static function (string $n, ?string $v): void {
    if ($v === null) {
        putenv($n);
        unset($_ENV[$n], $_SERVER[$n]);
        return;
    }
    putenv("{$n}={$v}");
    $_ENV[$n] = $v;
};
$setEnv('APP_CANONICAL_URL', null);
$setEnv('APP_TRUSTED_HOSTS', null);
// The request that triggers the loan email names a host nobody configured.
$_SERVER['HTTP_HOST'] = 'attacker.example';
$_SERVER['SERVER_NAME'] = 'attacker.example';

$db->begin_transaction();
try {
    $links = new LoanCalendarLinks($db);
    $loanId = 424242;

    echo "No configured host\n";
    $url = $links->icsUrl($loanId);
    $check($url === null, 'no tokenized .ics link is built from the request Host (' . var_export($url, true) . ')');
    $section = $links->emailSection($loanId, 'Il nome della rosa', '2026-11-20', '2026-12-04');
    $check(!str_contains($section, 'attacker.example'), 'the email section never names the request host');
    $check(!str_contains($section, '.ics?token='), 'and leaves the .ics button out');
    $check(str_contains($section, 'https://calendar.google.com/calendar/render?'), 'the Google Calendar button (no token) stays');

    echo "APP_TRUSTED_HOSTS configured\n";
    $setEnv('APP_TRUSTED_HOSTS', 'library.example');
    $url = (string) $links->icsUrl($loanId);
    $check(str_starts_with($url, 'https://library.example/calendar/loan/' . $loanId . '.ics?token='), 'the link uses the trusted host (' . $url . ')');
    $section = $links->emailSection($loanId, 'Il nome della rosa', '2026-11-20', '2026-12-04');
    $check(str_contains($section, htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) && !str_contains($section, 'attacker.example'),
        'the email carries that link and not the request host');
    $setEnv('APP_TRUSTED_HOSTS', null);

    echo "APP_CANONICAL_URL configured (sub-directory install)\n";
    $setEnv('APP_CANONICAL_URL', 'https://library.example/pinakes');
    $url = (string) $links->icsUrl($loanId);
    $check(str_starts_with($url, 'https://library.example/pinakes/calendar/loan/' . $loanId . '.ics?token='), 'the link keeps the canonical base path (' . $url . ')');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $check($links->isValidToken($loanId, (string) ($query['token'] ?? '')), 'and its token is the valid one for the loan');
    $setEnv('APP_CANONICAL_URL', null);
} catch (\Throwable $e) {
    $fail++;
    echo '  FAIL unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    $db->rollback();
    $db->close();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
