<?php
declare(strict_types=1);

/**
 * Issue #299 — email templates double-prefixed after a first WYSIWYG edit.
 *
 * The TinyMCE editor (convert_urls default on) resolved placeholder URLs
 * against the admin page, so `href="{{login_url}}"` was saved as
 * `href="https://host/admin/{{login_url}}"`. Substituting the (already
 * absolute) URL then produced `https://host/admin/https://host/accedi`.
 *
 * The editors now set convert_urls:false so this never happens again, but
 * templates ALREADY saved on existing installs are still corrupt. EmailService
 * heals them at render time by stripping an absolute `…/admin/` prefix sitting
 * directly in front of a {{placeholder}} before substitution. This pins that.
 *
 * Run: php tests/email-template-url-prefix-299.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\EmailService;

$passed = 0;
$failed = 0;
$check = static function (bool $cond, string $label) use (&$passed, &$failed): void {
    if ($cond) { $passed++; echo "  OK  {$label}\n"; return; }
    $failed++; echo "  FAIL {$label}\n";
};

// replaceVariables() uses only static constants (no $this->db / mailer), so we
// can exercise it without touching the DB or PHPMailer.
$svc = (new ReflectionClass(EmailService::class))->newInstanceWithoutConstructor();
$render = static fn(string $body, array $vars): string => $svc->replaceVariables($body, $vars);

// 1) The bug: a corrupted href must render as the plain absolute URL, once.
$out = $render('<a href="https://lib.example.org/admin/{{login_url}}">Log in</a>',
    ['login_url' => 'https://lib.example.org/accedi']);
$check(strpos($out, 'https://lib.example.org/accedi') !== false, 'corrupted href resolves to the real URL');
$check(strpos($out, '/admin/https://') === false, 'no double /admin/ + absolute-URL prefix remains');
$check($out === '<a href="https://lib.example.org/accedi">Log in</a>', 'exact healed output');

// 2) A clean template is unchanged by the healing + substitution.
$out = $render('<a href="{{login_url}}">Log in</a>', ['login_url' => 'https://lib.example.org/accedi']);
$check($out === '<a href="https://lib.example.org/accedi">Log in</a>', 'clean placeholder still substitutes correctly');

// 3) http (not https) prefixes are healed too.
$out = $render('<a href="http://x.test/admin/{{reset_url}}">Reset</a>', ['reset_url' => 'http://x.test/reset?t=abc']);
$check($out === '<a href="http://x.test/reset?t=abc">Reset</a>', 'http:// prefix healed');

// 4) A deeper base path (subdir install) is healed.
$out = $render('<a href="https://x.test/lib/admin/{{book_url}}">Book</a>', ['book_url' => 'https://x.test/lib/libro/5']);
$check($out === '<a href="https://x.test/lib/libro/5">Book</a>', 'subdir base + /admin/ prefix healed');

// 5) A legitimate URL that merely CONTAINS /admin/ but is NOT followed by a
//    placeholder must be left untouched.
$out = $render('<a href="https://x.test/admin/users">Users</a>', []);
$check($out === '<a href="https://x.test/admin/users">Users</a>', 'real /admin/ URL without a token is untouched');

// 6) A non-URL placeholder is never affected by the healing.
$out = $render('Ciao {{nome}}', ['nome' => 'Mario']);
$check($out === 'Ciao Mario', 'non-URL placeholder substitutes normally');

// 7) Two corrupted links in one body both heal.
$out = $render(
    '<a href="https://x.test/admin/{{book_url}}">B</a> <a href="https://x.test/admin/{{wishlist_url}}">W</a>',
    ['book_url' => 'https://x.test/libro/1', 'wishlist_url' => 'https://x.test/wishlist']);
$check(strpos($out, '/admin/https://') === false && strpos($out, 'https://x.test/libro/1') !== false
    && strpos($out, 'https://x.test/wishlist') !== false, 'multiple corrupted links all heal');

// Fresh EN/FR/DE installer seeds historically use {{reason}} while the
// circulation service passes the canonical Italian key `motivo`.
$out = $render('<p>Reason: {{reason}}</p>', ['motivo' => 'Shelf maintenance']);
$check($out === '<p>Reason: Shelf maintenance</p>', '{{reason}} alias resolves from canonical motivo variable');

// The inverse direction is supported too for operators passing English keys
// to a template that uses the canonical token.
$out = $render('<p>Motivo: {{motivo}}</p>', ['reason' => 'Inventory check']);
$check($out === '<p>Motivo: Inventory check</p>', 'English reason variable resolves canonical {{motivo}} token');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
