<?php
declare(strict_types=1);

/**
 * Security guard for RefererGuard::localPath() — the single audited
 * implementation now reused by AutoriController::delete and
 * CopyController::safeReferer for "bounce back to the referring page".
 *
 * It derives the redirect from the referer's PATH only (scheme/host always
 * discarded), so it is immune to the open-redirect vectors that the earlier
 * inline host-comparison guard was vulnerable to (adamsreview F001/F003 +
 * CodeRabbit): non-standard-port blind spot, host-null bypass
 * ('https:evil.tld/…'), backslash trick ('/\evil.tld/…'), and Host-header
 * spoofing — while preserving the local path + query (pagination/filters).
 *
 * Run:  php tests/referer-guard.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\RefererGuard;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$DEFAULT = '/admin/authors';
$fallback = url($DEFAULT);

// ── A. Open-redirect vectors are neutralised (never leave the origin) ────────
echo "A. Bypass vectors → safe local fallback (or local path)\n";

// scheme-relative-without-slashes: parse_url gives a host-less, non-'/' path.
$check(RefererGuard::localPath('https:evil.tld/admin/authors', $DEFAULT, '/admin/authors') === $fallback,
    "scheme-relative 'https:evil.tld/…' rejected → fallback");

// backslash trick: browsers normalise '/\' into '//' (protocol-relative).
$check(RefererGuard::localPath('/\\evil.tld/admin/authors', $DEFAULT, '/admin/authors') === $fallback,
    "backslash '/\\evil.tld/…' rejected → fallback");

// CRLF header injection.
$check(RefererGuard::localPath("https://host/admin/authors\r\nSet-Cookie: x=1", $DEFAULT, '/admin/authors') === $fallback,
    "CRLF referer rejected → fallback");

// Cross-host absolute referer: only its LOCAL path is reused — never evil.tld.
$out = RefererGuard::localPath('https://evil.tld/admin/authors?page=9', $DEFAULT, '/admin/authors');
$check($out === '/admin/authors?page=9',
    "cross-host absolute referer reuses only the local path (got: {$out})");
$check(!str_contains($out, 'evil.tld'), "result never contains the attacker host");

// protocol-relative referer: the clean path is extracted, host dropped.
$out = RefererGuard::localPath('//evil.tld/admin/authors?x=1', $DEFAULT, '/admin/authors');
$check($out === '/admin/authors?x=1' && !str_contains($out, 'evil.tld'),
    "protocol-relative '//evil.tld/…' → local path only (got: {$out})");

// empty referer.
$check(RefererGuard::localPath('', $DEFAULT, '/admin/authors') === $fallback, "empty referer → fallback");

// ── B. Legitimate same-origin referers preserve path + query (F001) ──────────
echo "B. Legit referers preserve path + query (port-agnostic)\n";

// Non-standard port (this project's :8081): the old host-vs-HTTP_HOST check
// bounced these; path-only preserves the referring page + its query.
$out = RefererGuard::localPath('http://localhost:8081/admin/authors?page=2&q=rossi', $DEFAULT, '/admin/authors');
$check($out === '/admin/authors?page=2&q=rossi',
    "non-standard-port referer preserves path+query (got: {$out})");

// relative internal referer with query.
$out = RefererGuard::localPath('/admin/authors?letter=B', $DEFAULT, '/admin/authors');
$check($out === '/admin/authors?letter=B', "relative referer preserves query (got: {$out})");

// ── C. mustContain gate ──────────────────────────────────────────────────────
echo "C. mustContain gate\n";

$check(RefererGuard::localPath('https://myhost/admin/books/5', $DEFAULT, '/admin/authors') === $fallback,
    "same-app but wrong section (/admin/books) → fallback when gated on /admin/authors");

// Without a gate (CopyController usage) any local path is accepted.
$out = RefererGuard::localPath('https://myhost/admin/books?page=3', '/admin/books');
$check($out === '/admin/books?page=3', "no mustContain → any local path reused (got: {$out})");

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
