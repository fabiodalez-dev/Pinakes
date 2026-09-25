<?php
declare(strict_types=1);

/**
 * A Content-Security-Policy left behind in .htaccess by an older install.
 *
 * Reported from a live library: a Google Maps embed, saved through a form that
 * accepts Google Maps, was refused by the browser with
 *
 *   Framing 'https://www.google.com/' violates the following Content Security
 *   Policy directive: "frame-src 'self' data: blob: about:
 *   https://www.openstreetmap.org"
 *
 * That policy is not the application's — it is the static line .htaccess
 * carried before the policy moved into the application, and `public/.htaccess`
 * is on the updater's preserved list, so it survived every upgrade. The map was
 * the visible symptom; the same line also pins the site to `script-src
 * 'unsafe-inline'` with no nonce, which is the policy the move replaced.
 *
 *   php tests/stale-csp-header.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\StaleCspHeader;

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

// The block exactly as it shipped, taken from the commit that removed it.
$legacyCsp = "Header always set Content-Security-Policy \"default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
    . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
    . "img-src 'self' data: https: http: blob:; connect-src 'self'; "
    . "frame-src 'self' data: blob: about: https://www.openstreetmap.org; "
    . "child-src 'self' data: blob: about:; frame-ancestors 'self';\"";

$legacyFile = <<<HTACCESS
RewriteEngine On
RewriteRule ^index\\.php\$ - [L]

<IfModule mod_headers.c>
    # Enable XSS protection
    Header always set X-XSS-Protection "1; mode=block"

    # Prevent clickjacking
    Header always set X-Frame-Options "SAMEORIGIN"

    # Content Security Policy - restrictive but allows inline styles/scripts for compatibility
    # Allows Google Fonts for Inter font family
    {$legacyCsp}

    # Referrer policy
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# An operator's own rule, which must survive untouched
<Files "wp-config.php">
    Require all denied
</Files>
HTACCESS;

// -------------------------------------------------------------------------
// A. The file that caused the report
// -------------------------------------------------------------------------
echo "\nA. The shipped legacy block\n";

$check(StaleCspHeader::isPresent($legacyFile), 'the stale policy is detected');

$cleaned = StaleCspHeader::strip($legacyFile);

$check(!StaleCspHeader::isPresent($cleaned), 'and is gone after stripping');
$check(strpos($cleaned, 'openstreetmap.org') === false,
    'so nothing pins frame-src to one provider any more');
$check(strpos($cleaned, 'Content Security Policy - restrictive') === false,
    'the comment that introduced it goes with it');
$check(strpos($cleaned, 'Allows Google Fonts for Inter font family') === false,
    'including its continuation line');

echo "\nB. Everything else is left alone\n";

foreach ([
    'Header always set X-XSS-Protection "1; mode=block"' => 'the XSS header',
    'Header always set X-Frame-Options "SAMEORIGIN"' => 'the clickjacking header',
    'Header always set Referrer-Policy "strict-origin-when-cross-origin"' => 'the referrer policy',
    '<IfModule mod_headers.c>' => 'the module guard',
    'RewriteRule ^index\.php$ - [L]' => 'the rewrite rules',
    'Require all denied' => "the operator's own rule",
] as $needle => $label) {
    $check(strpos($cleaned, $needle) !== false, "{$label} survives");
}

$check(substr_count($cleaned, 'Header always set') === 3,
    'exactly one Header line was removed, not the block around it');

echo "\nC. Idempotent, and silent on files that need nothing\n";

$check(StaleCspHeader::strip($cleaned) === $cleaned,
    'a second pass changes nothing');

$modern = "<IfModule mod_headers.c>\n    # The application emits a per-response nonce-based Content-Security-Policy.\n    Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n</IfModule>\n";
$check(!StaleCspHeader::isPresent($modern), 'a current file carries no stale policy');
$check(StaleCspHeader::strip($modern) === $modern, 'and is returned untouched');

echo "\nD. The spellings Apache accepts\n";

$variants = [
    'Header set Content-Security-Policy "default-src \'self\';"' => 'Header set, without always',
    '  Header  always  set  Content-Security-Policy  "default-src \'self\';"' => 'extra whitespace',
    'header always set content-security-policy "default-src \'self\';"' => 'lower case',
    'Header always set "Content-Security-Policy" "default-src \'self\';"' => 'quoted header name',
];
foreach ($variants as $line => $label) {
    $file = "<IfModule mod_headers.c>\n{$line}\nHeader always set X-Frame-Options \"SAMEORIGIN\"\n</IfModule>\n";
    $out = StaleCspHeader::strip($file);
    $check(!StaleCspHeader::isPresent($out) && strpos($out, 'X-Frame-Options') !== false,
        "removed: {$label}");
}

// Report-Only is a different header and is the operator's business.
$reportOnly = "Header always set Content-Security-Policy-Report-Only \"default-src 'self';\"\n";
$check(StaleCspHeader::strip($reportOnly) === $reportOnly,
    'Content-Security-Policy-Report-Only is left alone');

// -------------------------------------------------------------------------
// E. Healing a real file on disk
// -------------------------------------------------------------------------
echo "\nE. On disk\n";

$dir = sys_get_temp_dir() . '/pinakes-csp-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
$path = $dir . '/.htaccess';

try {
    file_put_contents($path, $legacyFile);
    $check(StaleCspHeader::heal($path), 'heal() reports success');
    $onDisk = (string) file_get_contents($path);
    $check(!StaleCspHeader::isPresent($onDisk), 'the file on disk no longer sets a policy');
    $check(strpos($onDisk, 'Require all denied') !== false, "the operator's rule is still there");

    $backups = glob($dir . '/.htaccess.bak-csp-*') ?: [];
    $check(count($backups) === 1, 'a backup of the original was kept');
    $check($backups !== [] && (string) file_get_contents($backups[0]) === $legacyFile,
        'and it is the original, byte for byte');

    $check(StaleCspHeader::heal($path), 'a second heal is a no-op that still reports success');
    $check((string) file_get_contents($path) === $onDisk, 'and leaves the file unchanged');
    $check(count(glob($dir . '/.htaccess.bak-csp-*') ?: []) === 1,
        'without piling up another backup');

    $check(StaleCspHeader::heal($dir . '/does-not-exist'),
        'a missing .htaccess is not a failure: there is no stale header in it');

    // A file that cannot be read must not be reported as healed.
    $unreadable = $dir . '/unreadable';
    file_put_contents($unreadable, $legacyFile);
    chmod($unreadable, 0000);
    if (!is_readable($unreadable)) {
        $check(!StaleCspHeader::heal($unreadable),
            'an unreadable file is reported as not healed, never as clean');
    } else {
        echo "  --  skipped: running as a user that can read a 0000 file\n";
    }
    @chmod($unreadable, 0644);
} finally {
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($dir . '/.*') ?: [] as $f) {
        if (!in_array(basename($f), ['.', '..'], true)) {
            @unlink($f);
        }
    }
    @rmdir($dir);
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
