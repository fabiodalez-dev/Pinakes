<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — installer .env reader vs quoteEnvValue()/phpdotenv
 *
 * quoteEnvValue() writes DB_PASS="p\$ss" (phpdotenv 5.6 reads p$ss), but the
 * installer's own readers only trimmed quotes and passed p\$ss to MySQL. The
 * empty-database probe of isInstalled() then failed to connect and classified
 * a Docker wizard's empty database as installed, the ?force=1 gate could not
 * open the database, and loadEnvConfig() (steps 3-7) broke the install.
 *
 *   A. Installer::parseEnvValue() decodes exactly what phpdotenv decodes, and
 *      rejects what phpdotenv rejects (compared live against vendor phpdotenv).
 *   B. createEnvFile() -> readEnvFile() round-trips a hostile password, and
 *      phpdotenv reads the same value.
 *   C. loadEnvConfig() and the isInstalled() database probe use the decoded value.
 *   D. installer/index.php's force gate uses the same reader.
 *
 * No database needed. Run: php tests/security-audit-installer-env-reader.unit.php
 */

$ROOT = dirname(__DIR__);

if (!function_exists('__')) {
    function __(string $key, mixed ...$args): string
    {
        return $args ? sprintf($key, ...$args) : $key;
    }
}

require_once $ROOT . '/installer/classes/Installer.php';
$hasDotenv = is_file($ROOT . '/vendor/autoload.php');
if ($hasDotenv) {
    require_once $ROOT . '/vendor/autoload.php';
    $hasDotenv = class_exists(\Dotenv\Dotenv::class);
}

// createEnvFile() needs a live session; start it before any output.
$tmpRoot = sys_get_temp_dir() . '/pk_secaudit_env_' . bin2hex(random_bytes(5));
mkdir($tmpRoot . '/sessions', 0700, true);
session_save_path($tmpRoot . '/sessions');
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_start();

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};
$mkSite = static function () use ($tmpRoot): string {
    $d = $tmpRoot . '/site_' . bin2hex(random_bytes(4));
    mkdir($d . '/storage', 0700, true);
    return $d;
};
$hasReader = method_exists(Installer::class, 'parseEnvValue') && method_exists(Installer::class, 'readEnvFile');
$check($hasReader, 'Installer::parseEnvValue() and Installer::readEnvFile() exist');

// ---------------------------------------------------------------------------
echo "A. Value decoding matches phpdotenv 5.6\n";
// ---------------------------------------------------------------------------
$cases = [
    '"p\\$ss"'              => 'p$ss',
    '"a\\"b"'               => 'a"b',
    '"back\\\\slash"'       => 'back\\slash',
    '"tab\\there"'          => "tab\there",
    '""'                    => '',
    ''                      => '',
    'plain'                 => 'plain',
    'plain # comment'       => 'plain',
    'plain#comment'         => 'plain',
    '"quoted" # comment'    => 'quoted',
    '"with # hash"'         => 'with # hash',
    "'lit\\\$eral \"x\"'"   => 'lit\\$eral "x"',
    '"bad\\q"'              => null,
    '"unterminated'         => null,
    'two words'             => null,
    '"x"y'                  => null,
];
foreach ($cases as $raw => $expected) {
    $raw = (string) $raw;
    $got = $hasReader ? Installer::parseEnvValue($raw) : '<missing>';
    $label = 'K=' . $raw . ' -> ' . var_export($expected, true);
    if ($hasDotenv) {
        try {
            // A rejected value throws; an unterminated quote drops the entry.
            $parsed = \Dotenv\Dotenv::parse('K=' . $raw . "\n");
            $dotenv = array_key_exists('K', $parsed) ? $parsed['K'] : null;
        } catch (\Throwable $e) {
            $dotenv = null;
        }
        $check($dotenv === $expected, 'phpdotenv agrees: ' . $label);
    }
    $check($got === $expected, 'parseEnvValue(): ' . $label);
}

// ---------------------------------------------------------------------------
echo "B. createEnvFile() round trip\n";
// ---------------------------------------------------------------------------
$password = 'p$ss "q" \\ back#=! \'s` $HOME';
$site = $mkSite();
$installer = new Installer($site);
$_SESSION = [];
$check($installer->createEnvFile('127.0.0.1', 'u ser', $password, 'db', 1) === true, 'createEnvFile() writes .env');
$raw = (string) file_get_contents($site . '/.env');
$check(str_contains($raw, 'DB_PASS="p\\$ss \\"q\\" \\\\ back#=! \'s` \\$HOME"'), 'DB_PASS is written double-quoted and escaped');
$env = $hasReader ? (Installer::readEnvFile($site . '/.env') ?? []) : [];
$check(($env['DB_PASS'] ?? null) === $password, 'readEnvFile() gives back the exact password');
$check(($env['DB_USER'] ?? null) === 'u ser' && ($env['DB_NAME'] ?? null) === 'db', 'other quoted values round-trip too');
if ($hasDotenv) {
    $check((\Dotenv\Dotenv::parse($raw)['DB_PASS'] ?? null) === $password, 'phpdotenv reads the same password');
}
$check($hasReader && Installer::readEnvFile($site . '/missing.env') === null, 'an unreadable .env is null, not an empty config');

// ---------------------------------------------------------------------------
echo "C. Consumers use the decoded value\n";
// ---------------------------------------------------------------------------
$installer->loadEnvConfig();
$config = (new ReflectionProperty(Installer::class, 'config'))->getValue($installer);
$check(($config['DB_PASS'] ?? null) === $password, 'loadEnvConfig() (steps 3-7) connects with the decoded password');

/** Installer whose probe connection records what it was given and refuses. */
final class CapturingProbe extends Installer
{
    /** @var array<string, string>|null */
    public ?array $seen = null;
    protected function openProbeConnection(array $env): \mysqli
    {
        $this->seen = $env;
        throw new \RuntimeException('no database in this test');
    }
}
$probe = new CapturingProbe($site);
$_SESSION = []; // not the owner: the probe runs
@unlink($site . '/storage/.install_in_progress'); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp file
$installedAnswer = $probe->isInstalled();
$check(($probe->seen['DB_PASS'] ?? null) === $password, 'the empty-database probe connects with the decoded password');
$check($installedAnswer === true, 'a probe that cannot connect still counts as installed (fail closed)');

// ---------------------------------------------------------------------------
echo "D. installer/index.php force gate uses the shared reader\n";
// ---------------------------------------------------------------------------
$src = (string) file_get_contents($ROOT . '/installer/index.php');
$gateStart = strpos($src, "if (isset(\$_GET['force']) && \$installer->isInstalled())");
$gateEnd = strpos($src, '// Check if already installed');
$gate = ($gateStart !== false && $gateEnd !== false) ? substr($src, $gateStart, $gateEnd - $gateStart) : '';
$check(str_contains($gate, "Installer::readEnvFile(\$baseDir . '/.env')"), 'the force gate reads .env with Installer::readEnvFile()');
$check(!str_contains($gate, 'trim($value, " \\t\\n\\r\\0\\x0B\\"\'")'), 'the quote-trimming loader is gone');

// ---------------------------------------------------------------------------
// Cleanup: only the private temp tree created above.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // nosemgrep
}
@rmdir($tmpRoot);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
