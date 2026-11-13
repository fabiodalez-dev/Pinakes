<?php
declare(strict_types=1);

/**
 * Security audit 2026-11
 *   - root-htaccess:installer-exemption-unanchored:repo-php-reachable-via-path-info
 *   - root-htaccess:storage-calendar-ics-bypasses-private-mode
 *
 * On a project-root document root the root .htaccess exempted any URI with an
 * "installer" segment (so /scripts/maintenance.php/installer ran the CLI batch
 * over HTTP) and served storage/calendar/*.ics statically (outside private mode).
 *
 *   A. A small evaluator of the exemption rules (RewriteCond on REQUEST_URI +
 *      per-directory RewriteRule pattern, which in .htaccess context includes
 *      PATH_INFO) run against the shipped root .htaccess and the block the
 *      installer writes: PATH_INFO and calendar requests fall through to public/.
 *   B. Installer::healLegacyRouting()/createHtaccess() rewrite both legacy forms
 *      and leave everything else alone.
 *   C. storage/calendar/.htaccess, scripts/.htaccess, CLI guards (run under php-cgi
 *      when available, so the web-SAPI refusal is exercised for real).
 *
 * No database needed. Run: php tests/security-audit-root-htaccess-anchored.unit.php
 */

$ROOT = dirname(__DIR__);

if (!function_exists('__')) {
    function __(string $key, mixed ...$args): string
    {
        return $args ? sprintf($key, ...$args) : $key;
    }
}

require_once $ROOT . '/installer/classes/Installer.php';

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

/**
 * Which pass-through ("-") rule, if any, exempts a request from the rewrite to
 * public/. Models mod_rewrite in .htaccess context: the RewriteRule pattern is
 * matched against the directory-relative path INCLUDING PATH_INFO, RewriteCond
 * %{REQUEST_URI} against the full URI; [NC] = case-insensitive, [OR] chains.
 */
$exemptingRule = static function (string $htaccess, string $uri): ?string {
    $perDir = ltrim($uri, '/');
    $conds = [];
    foreach (preg_split('/\r?\n/', $htaccess) ?: [] as $line) {
        $line = trim($line);
        if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?$/', $line, $m)) {
            $conds[] = ['var' => $m[1], 'pattern' => $m[2], 'flags' => strtoupper($m[3] ?? '')];
            continue;
        }
        if (!preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?$/', $line, $m)) {
            continue;
        }
        [$all, $pattern, $subst] = $m;
        $flags = strtoupper($m[3] ?? '');
        $ruleConds = $conds;
        $conds = [];
        $mod = str_contains($flags, 'NC') ? 'i' : '';
        if (preg_match('#' . str_replace('#', '\#', $pattern) . '#' . $mod, $perDir) !== 1) {
            continue;
        }
        $ok = true;
        $orChain = null;
        foreach ($ruleConds as $c) {
            $negate = str_starts_with($c['pattern'], '!');
            $p = $negate ? substr($c['pattern'], 1) : $c['pattern'];
            $subject = $c['var'] === '%{REQUEST_URI}' ? $uri : '';
            $hit = preg_match('#' . str_replace('#', '\#', $p) . '#' . (str_contains($c['flags'], 'NC') ? 'i' : ''), $subject) === 1;
            $hit = $negate ? !$hit : $hit;
            if (str_contains($c['flags'], 'OR')) {
                $orChain = ($orChain ?? false) || $hit;
                continue;
            }
            if ($orChain !== null) {
                $hit = $orChain || $hit;
                $orChain = null;
            }
            $ok = $ok && $hit;
        }
        if ($ok) {
            return $subst === '-' ? $all : null; // first matching rule decides
        }
    }
    return null;
};

$shipped = (string) file_get_contents($ROOT . '/.htaccess');
$written = Installer::ROUTING_BLOCK;

// ---------------------------------------------------------------------------
echo "A. Exemptions are anchored (shipped root .htaccess and installer block)\n";
// ---------------------------------------------------------------------------
foreach (['shipped .htaccess' => $shipped, 'installer block' => $written] as $name => $ht) {
    $check($exemptingRule($ht, '/scripts/maintenance.php/installer') === null, "$name: /scripts/maintenance.php/installer goes to public/");
    $check($exemptingRule($ht, '/scripts/generate-sitemap.php/installer/') === null, "$name: /scripts/generate-sitemap.php/installer/ goes to public/");
    $check($exemptingRule($ht, '/app/Routes/web.php/Installer') === null, "$name: case-varied PATH_INFO goes to public/");
    $check($exemptingRule($ht, '/scripts/manual-upgrade.php/installer') === null, "$name: manual-upgrade with PATH_INFO goes to public/");
    $check($exemptingRule($ht, '/storage/calendar/library-calendar.ics') === null, "$name: the legacy calendar URL goes to public/ (gated route)");
    $check($exemptingRule($ht, '/installer/') !== null && $exemptingRule($ht, '/installer') !== null
        && $exemptingRule($ht, '/installer/index.php') !== null && $exemptingRule($ht, '/installer/assets/style.css') !== null,
        "$name: the installer is still served directly");
    $check($exemptingRule($ht, '/scripts/manual-upgrade.php') !== null, "$name: manual-upgrade.php is still served directly");
    $check($exemptingRule($ht, '/installerx/') === null, "$name: a look-alike directory is not exempted");
    $check(str_contains($ht, 'RewriteRule ^(.*)$ public/$1 [L,QSA]'), "$name: catch-all rewrite to public/ kept");
}

// ---------------------------------------------------------------------------
echo "B. Legacy files are healed in place\n";
// ---------------------------------------------------------------------------
$legacyPackaged = <<<'HT'
RewriteEngine On

# Block access to sensitive files at root level
<FilesMatch "^(\.env|\.installed)$">
    Require all denied
</FilesMatch>

# Prevent directory browsing
Options -Indexes

# Exclude installer and scripts from rewrite (allow direct access)
RewriteCond %{REQUEST_URI} (^|/)installer(/|$) [NC,OR]
RewriteCond %{REQUEST_URI} (^|/)scripts/manual-upgrade\.php$ [NC]
RewriteRule ^ - [L]

# Allow direct access to calendar files (.ics) for subscription URLs
RewriteCond %{REQUEST_URI} ^/storage/calendar/.*\.ics$ [NC]
RewriteRule ^ - [L]

# Redirect everything else to /public/
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L,QSA]

HT;
$check($exemptingRule($legacyPackaged, '/scripts/maintenance.php/installer') !== null, 'evaluator sanity: the legacy file exempts the PATH_INFO request');
$healed = Installer::healLegacyRouting($legacyPackaged);
$check($exemptingRule($healed, '/scripts/maintenance.php/installer') === null && $exemptingRule($healed, '/storage/calendar/library-calendar.ics') === null,
    'packaged legacy file: both bypasses closed');
$check($exemptingRule($healed, '/installer/') !== null && $exemptingRule($healed, '/scripts/manual-upgrade.php') !== null,
    'packaged legacy file: installer and manual-upgrade still served');
$check(str_contains($healed, '<FilesMatch "^(\.env|\.installed)$">') && str_contains($healed, 'RewriteRule ^(.*)$ public/$1 [L,QSA]'),
    'packaged legacy file: the rest is kept');
$check(Installer::healLegacyRouting($healed) === $healed, 'healing is idempotent');
$crlf = str_replace("\n", "\r\n", $legacyPackaged);
$check($exemptingRule(Installer::healLegacyRouting($crlf), '/scripts/maintenance.php/installer') === null, 'CRLF legacy file is healed too');

$legacyBlock = "# BEGIN cPanel\nphp_flag log_errors On\n# END cPanel\n\n"
    . "# === Pinakes routing (auto-managed by the installer) ===\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
    . "    RewriteCond %{REQUEST_URI} (^|/)installer(/|$) [NC,OR]\n    RewriteCond %{REQUEST_URI} (^|/)scripts/manual-upgrade\\.php$ [NC]\n    RewriteRule ^ - [L]\n\n"
    . "    # Allow direct access to calendar .ics subscription files\n    RewriteCond %{REQUEST_URI} ^/storage/calendar/.*\\.ics$ [NC]\n    RewriteRule ^ - [L]\n\n"
    . "    RewriteCond %{REQUEST_URI} !^/public/\n    RewriteRule ^(.*)$ public/$1 [L,QSA]\n</IfModule>\n# === end Pinakes routing ===\n";
$healedBlock = Installer::healLegacyRouting($legacyBlock);
$check(str_contains($healedBlock, Installer::ROUTING_BLOCK) && str_starts_with($healedBlock, "# BEGIN cPanel\nphp_flag log_errors On\n# END cPanel\n\n"),
    'installer-written legacy block: replaced, surrounding cPanel directives kept');
$check(substr_count($healedBlock, '=== Pinakes routing') === 1, 'installer-written legacy block: no duplication');

$custom = "RewriteEngine On\nRewriteRule ^(.*)$ public/$1 [L,QSA]\n";
$check(Installer::healLegacyRouting($custom) === $custom, 'a hand-written routing file is left untouched');

$tmp = sys_get_temp_dir() . '/pk_secaudit_ht_' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
file_put_contents($tmp . '/.htaccess', $legacyBlock);
(new Installer($tmp))->createHtaccess();
$onDisk = (string) file_get_contents($tmp . '/.htaccess');
$check($exemptingRule($onDisk, '/scripts/maintenance.php/installer') === null, 'createHtaccess() heals an existing legacy block on disk');
(new Installer($tmp))->createHtaccess();
$check((string) file_get_contents($tmp . '/.htaccess') === $onDisk, 'createHtaccess() is idempotent after healing');
@unlink($tmp . '/.htaccess'); // nosemgrep
@rmdir($tmp);

// ---------------------------------------------------------------------------
echo "C. Directory rules and CLI-only scripts\n";
// ---------------------------------------------------------------------------
$cal = (string) file_get_contents($ROOT . '/storage/calendar/.htaccess');
$check(preg_match('/<IfModule mod_rewrite\.c>\s*<FilesMatch "\\\\\.ics\$">\s*Require all granted/s', $cal) === 1,
    'storage/calendar grant only exists where the root rewrite can route the request to the app');

$scripts = (string) @file_get_contents($ROOT . '/scripts/.htaccess');
$check(preg_match('/^\s*Require all denied/m', $scripts) === 1
    && preg_match('/<Files "manual-upgrade\.php">\s*Require all granted/s', $scripts) === 1,
    'scripts/.htaccess denies everything except manual-upgrade.php');

$cgi = '';
foreach ([dirname(PHP_BINARY) . '/php-cgi', '/opt/homebrew/bin/php-cgi', '/usr/bin/php-cgi', '/usr/local/bin/php-cgi'] as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) { $cgi = $candidate; break; }
}
foreach (['scripts/maintenance.php', 'scripts/generate-sitemap.php', 'scripts/list-source-expectations.php'] as $script) {
    $code = (string) file_get_contents($ROOT . '/' . $script);
    $guardPos = strpos($code, "if (PHP_SAPI !== 'cli')");
    $firstWork = min(array_filter([
        strpos($code, '$lockFile'), strpos($code, 'require'), strpos($code, '$rootDir'), strpos($code, 'function '),
    ], static fn($p) => $p !== false) ?: [PHP_INT_MAX]);
    $check($guardPos !== false && $guardPos < $firstWork, "$script: CLI guard before any work");
    if ($cgi !== '') {
        // Headers on, no PATH_INFO tricks needed: the web SAPI itself is refused.
        $out = (string) shell_exec(escapeshellarg($cgi) . ' ' . escapeshellarg($ROOT . '/' . $script) . ' 2>&1');
        [$head, $body] = array_pad(preg_split("/\r?\n\r?\n/", $out, 2) ?: [], 2, '');
        $check(str_starts_with($head, 'Status: 404') && trim((string) $body) === '',
            "$script: refuses to run under php-cgi with 404 and no output (got: " . substr(str_replace("\n", ' | ', trim($out)), 0, 80) . ')');
    }
}
if ($cgi === '') {
    echo "  SKIP php-cgi not available: web-SAPI refusal checked statically only\n";
}

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
