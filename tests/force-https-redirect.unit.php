<?php
declare(strict_types=1);

/**
 * Security guard for the force-HTTPS bootstrap redirect in public/index.php.
 *
 * When `advanced.force_https` (or APP_ENV=production + FORCE_HTTPS) is on and a
 * request arrives over HTTP, public/index.php issues a 301 to the HTTPS URL —
 * but ONLY to an operator-configured trusted host, never the raw Host header:
 *
 *     $target = HtmlHelper::forceHttpsRedirectTarget();   // null = don't redirect
 *     if ($target !== null) { header('Location: ' . $target, true, 301); exit; }
 *
 * forceHttpsRedirectTarget() derives the host from APP_CANONICAL_URL (else the
 * first APP_TRUSTED_HOSTS entry) and returns null when neither is set, so the
 * bootstrap fails safe instead of redirecting to an attacker-chosen domain on a
 * catch-all vhost (CodeRabbit). The installer always writes APP_CANONICAL_URL,
 * so real installs still get the HTTPS upgrade.
 *
 * These tests pin those properties. Each scenario runs in a FRESH subprocess
 * (`php <this-file> --child`) so env / $_SERVER state never bleeds across cases.
 * No DB — the resolver reads only env + $_SERVER.
 *
 * Run:  php tests/force-https-redirect.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);

// ── Child mode: apply one scenario, print the resolved target (or "NULL") ─────
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
    $target = \App\Support\HtmlHelper::forceHttpsRedirectTarget();
    echo $target === null ? 'NULL' : $target;
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
 * Resolve the redirect target for a scenario in an isolated subprocess.
 * Returns the literal string "NULL" when the resolver declines to redirect.
 *
 * @param array<string,string> $server $_SERVER overrides
 * @param array<string,string> $env    env overrides (APP_CANONICAL_URL, APP_TRUSTED_HOSTS)
 */
$target = static function (array $server, array $env = []) use ($self): string {
    // json_encode escapes raw CR/LF as \r\n TEXT, so the env var carries no
    // control bytes; the child's json_decode restores the real bytes.
    $payload = (string) json_encode(['server' => $server, 'env' => $env]);
    $cmd = 'RT_SCENARIO=' . escapeshellarg($payload)
        . ' ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($self) . ' --child';
    return trim((string) shell_exec($cmd));
};

echo "A. Configured canonical host — correct, Host-independent upgrade\n";

$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => '/admin/dashboard'],
    ['APP_CANONICAL_URL' => 'https://mysite.com']);
$check($out === 'https://mysite.com/admin/dashboard', "canonical host + request path (got: {$out})");

$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => '/catalogo?page=3&q=rossi'],
    ['APP_CANONICAL_URL' => 'https://mysite.com']);
$check($out === 'https://mysite.com/catalogo?page=3&q=rossi', "path + query preserved (got: {$out})");

$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => '/x'],
    ['APP_CANONICAL_URL' => 'https://mysite.com:8443']);
$check($out === 'https://mysite.com:8443/x', "canonical port preserved (got: {$out})");

echo "B. Host-header attacks — target is the trusted host, never the attacker\n";

// THE core fix: an attacker Host is ignored entirely — the target is the canonical host.
$out = $target(['HTTP_HOST' => 'evil.tld', 'REQUEST_URI' => '/x'],
    ['APP_CANONICAL_URL' => 'https://mysite.com']);
$check($out === 'https://mysite.com/x' && !str_contains($out, 'evil.tld'),
    "spoofed Host ignored; redirect goes to the canonical host (got: {$out})");

// With no canonical but a whitelist, the first whitelisted host is used (not the Host).
$out = $target(['HTTP_HOST' => 'evil.tld', 'REQUEST_URI' => '/x'],
    ['APP_TRUSTED_HOSTS' => 'good.example, other.example']);
$check($out === 'https://good.example/x' && !str_contains($out, 'evil.tld'),
    "no canonical → first APP_TRUSTED_HOSTS entry used, Host ignored (got: {$out})");

echo "C. Fail-safe when no trusted host is configured (the reported vector)\n";

// The vulnerability CodeRabbit flagged: zero config + attacker Host must NOT redirect.
$out = $target(['HTTP_HOST' => 'evil.tld', 'REQUEST_URI' => '/x'], []);
$check($out === 'NULL', "no trusted config + attacker Host → NO redirect (fail safe) (got: {$out})");

// Even a plausible-looking Host is refused without configuration.
$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => '/x'], []);
$check($out === 'NULL', "no trusted config → NO redirect even for a benign Host (got: {$out})");

// A malformed APP_CANONICAL_URL (no host) must not fall back to the Host header.
$out = $target(['HTTP_HOST' => 'evil.tld', 'REQUEST_URI' => '/x'],
    ['APP_CANONICAL_URL' => 'not-a-valid-url']);
$check($out === 'NULL', "malformed APP_CANONICAL_URL → NO redirect, not the Host (got: {$out})");

echo "D. Request-path hardening\n";

// Raw CR/LF in the request URI is stripped so the Location stays a single header.
$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => "/x\r\nSet-Cookie: pwned=1"],
    ['APP_CANONICAL_URL' => 'https://mysite.com']);
$noCrlf = !str_contains($out, "\r") && !str_contains($out, "\n");
$hostIntact = str_starts_with($out, 'https://mysite.com/');
$check($noCrlf && $hostIntact,
    "raw CRLF in REQUEST_URI stripped → single header, canonical host intact (got: "
    . str_replace(["\r", "\n"], ['\\r', '\\n'], $out) . ")");

// An empty / non-leading-slash REQUEST_URI is normalised to a rooted path.
$out = $target(['HTTP_HOST' => 'mysite.com', 'REQUEST_URI' => ''],
    ['APP_CANONICAL_URL' => 'https://mysite.com']);
$check($out === 'https://mysite.com/', "empty REQUEST_URI normalised to '/' (got: {$out})");

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
