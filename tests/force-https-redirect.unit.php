<?php
declare(strict_types=1);

/**
 * Security guard for the force-HTTPS bootstrap redirect in public/index.php.
 *
 * When `advanced.force_https` (or APP_ENV=production + FORCE_HTTPS) is on and a
 * request arrives over HTTP, public/index.php issues a 301 to the HTTPS URL.
 * The redirect target is built via the app's single audited host resolver:
 *
 *     $target = HtmlHelper::getCurrentUrl();
 *     $target = preg_replace('#^http://#i', 'https://', $target, 1);
 *
 * This replaced an earlier hand-rolled block that trusted the raw Host header,
 * which was an open-redirect on catch-all vhosts (CodeRabbit). These tests pin
 * the security-relevant properties of that resolution so a future refactor
 * can't silently reintroduce Host-header trust.
 *
 * Each scenario runs in a FRESH subprocess (`php <this-file> --child`) so
 * per-request $_SERVER / $_ENV state and getBasePath()'s static cache never
 * bleed across cases. No DB — getCurrentUrl() reads only env + $_SERVER.
 *
 * Run:  php tests/force-https-redirect.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);

// ── Child mode: apply one scenario, print the exact index.php redirect target ─
if (($argv[1] ?? '') === '--child') {
    require $root . '/vendor/autoload.php';
    /** @var array{server?:array<string,string>,env?:array<string,string>} $s */
    $s = json_decode((string) getenv('RT_SCENARIO'), true) ?: [];
    foreach (($s['env'] ?? []) as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
    foreach (($s['server'] ?? []) as $k => $v) {
        $_SERVER[$k] = $v;
    }
    // The exact expression public/index.php uses for the force-HTTPS upgrade.
    $target = \App\Support\HtmlHelper::getCurrentUrl();
    $target = preg_replace('#^http://#i', 'https://', $target, 1) ?? $target;
    echo $target;
    exit(0);
}

// ── Parent mode: drive the scenarios ─────────────────────────────────────────
$self = __FILE__;
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/**
 * Compute the redirect target for a scenario in an isolated subprocess.
 *
 * @param array<string,string> $server $_SERVER overrides (HTTP_HOST, REQUEST_URI, …)
 * @param array<string,string> $env    env overrides (APP_CANONICAL_URL, APP_TRUSTED_HOSTS, TRUSTED_PROXIES)
 */
$target = static function (array $server, array $env = []) use ($self): string {
    // json_encode escapes any raw CR/LF as \r\n TEXT, so the env var carries no
    // control bytes; the child's json_decode restores the real bytes.
    $payload = (string) json_encode(['server' => $server, 'env' => $env]);
    $cmd = 'RT_SCENARIO=' . escapeshellarg($payload)
        . ' ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($self) . ' --child';
    return trim((string) shell_exec($cmd));
};

echo "A. Legit host — correct HTTPS upgrade\n";

$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => '/admin/dashboard']);
$check($out === 'https://mysite.com/admin/dashboard', "legit host preserves host + path (got: {$out})");

$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => '/x']);
$check(str_starts_with($out, 'https://'), "scheme is forced to https (got: {$out})");

$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => '/catalogo?page=3&q=rossi']);
$check($out === 'https://mysite.com/catalogo?page=3&q=rossi', "path + query preserved (got: {$out})");

$out = $target(['HTTP_HOST' => 'mysite.com:8080', 'REQUEST_URI' => '/x']);
$check($out === 'https://mysite.com:8080/x', "non-standard port preserved (got: {$out})");

echo "B. Host-header attacks — never redirect off-site\n";

$out = $target(['HTTP_HOST' => 'evil.tld', 'REQUEST_URI' => '/x'], ['APP_TRUSTED_HOSTS' => 'mysite.com']);
$check($out === 'https://mysite.com/x' && !str_contains($out, 'evil.tld'),
    "spoofed Host neutralised by APP_TRUSTED_HOSTS whitelist (got: {$out})");

$out = $target(['HTTP_HOST' => 'evil.tld', 'REQUEST_URI' => '/x'], ['APP_CANONICAL_URL' => 'https://mysite.com']);
$check($out === 'https://mysite.com/x' && !str_contains($out, 'evil.tld'),
    "spoofed Host overridden by APP_CANONICAL_URL (got: {$out})");

$out = $target(['HTTP_HOST' => 'e<vil>.tld', 'REQUEST_URI' => '/x']);
$check(str_starts_with($out, 'https://localhost') && !str_contains($out, 'vil'),
    "malformed Host falls back to localhost, never the attacker (got: {$out})");

$out = $target(
    ['HTTP_HOST' => 'mysite.com', 'HTTP_X_FORWARDED_HOST' => 'evil.tld', 'REMOTE_ADDR' => '203.0.113.9', 'REQUEST_URI' => '/x'],
    [] // no TRUSTED_PROXIES → X-Forwarded-Host must be ignored
);
$check($out === 'https://mysite.com/x' && !str_contains($out, 'evil.tld'),
    "X-Forwarded-Host ignored when the client is not a trusted proxy (got: {$out})");

echo "C. Whitelist + header-injection hardening\n";

// A non-first whitelisted host must be accepted as-is (not forced to entry 0).
$out = $target(['HTTP_HOST' => 'second.example', 'REQUEST_URI' => '/x'],
    ['APP_TRUSTED_HOSTS' => 'first.example, second.example']);
$check($out === 'https://second.example/x', "a matching non-first whitelisted host is accepted (got: {$out})");

// Raw CR/LF in the request URI must be stripped so the Location stays a SINGLE
// header line and the host is not hijacked. The injected payload survives only
// as inert text glued to the path (no control bytes → header() cannot split it).
$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => "/x\r\nSet-Cookie: pwned=1"]);
$noCrlf = !str_contains($out, "\r") && !str_contains($out, "\n");
$hostIntact = preg_match('#^https://mysite\.com/#', $out) === 1;
$check($noCrlf && $hostIntact,
    "raw CRLF in REQUEST_URI stripped → single header, host intact (got: "
    . str_replace(["\r", "\n"], ['\\r', '\\n'], $out) . ")");

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
