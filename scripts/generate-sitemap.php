#!/usr/bin/env php
<?php
declare(strict_types=1);

// CLI only. Under a web SAPI any anonymous visitor could run this tool (the
// unanchored installer exemption of the root .htaccess once let
// /scripts/<file>.php/installer through). scripts/.htaccess denies it as well.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Controllers\SeoController;
use App\Models\SettingsRepository;
use App\Support\ConfigStore;
use App\Support\SitemapGenerator;

$rootDir = dirname(__DIR__);
chdir($rootDir);

$envFile = $rootDir . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

require_once $rootDir . '/vendor/autoload.php';

$settings = require $rootDir . '/config/settings.php';
$cfg = $settings['db'] ?? [];

$hostname = $cfg['hostname'] ?? 'localhost';
$username = $cfg['username'] ?? '';
$password = $cfg['password'] ?? '';
$database = $cfg['database'] ?? '';
$port = (int)($cfg['port'] ?? 3306);
$charset = $cfg['charset'] ?? 'utf8mb4';

if ($database === '' || $username === '') {
    fwrite(STDERR, "Database configuration missing. Check config/settings.php\n");
    exit(1);
}

$socket = $cfg['socket'] ?? null;
if ($socket === null && $hostname === 'localhost') {
    $socketPaths = [
        '/tmp/mysql.sock',
        '/var/run/mysqld/mysqld.sock',
        '/usr/local/var/mysql/mysql.sock',
        '/opt/homebrew/var/mysql/mysql.sock',
    ];
    foreach ($socketPaths as $path) {
        if (file_exists($path)) {
            $socket = $path;
            break;
        }
    }
}

$mysqli = new mysqli(
    $hostname,
    $username,
    $password,
    $database,
    $port,
    $socket
);

if ($mysqli->connect_error) {
    fwrite(STDERR, "Failed to connect to database: {$mysqli->connect_error}\n");
    exit(1);
}

$mysqli->set_charset($charset);

// Private mode puts the catalogue behind the login wall, but public/sitemap.xml
// is served by the web server without running PHP (PrivateModeMiddleware never
// sees it). Do not publish it while private mode is on, and withdraw any copy
// published earlier, so /sitemap.xml keeps going through the gated route.
// The revision is read BEFORE private mode: enabling private mode bumps it, and
// saveTo() only compares against the value it reads at its own start, so a
// switch landing between this read and saveTo() would otherwise publish.
$revisionAtStart = \App\Support\SitemapCache::revision();
try {
    $privateMode = (new SettingsRepository($mysqli))->get('advanced', 'private_mode', '0') === '1'
        || (string) ConfigStore::get('advanced.private_mode', '0') === '1';
} catch (Throwable $exception) {
    fwrite(STDERR, 'Impossibile leggere la modalità privata: ' . $exception->getMessage() . "\n");
    $mysqli->close();
    exit(1);
}
if ($privateMode) {
    if (!\App\Support\SitemapCache::withdraw('private mode is on (scripts/generate-sitemap.php)')) {
        fwrite(STDERR, "Modalità privata attiva, ma public/sitemap.xml non può essere rimosso e resta accessibile: verificare i permessi di public/.\n");
        $mysqli->close();
        exit(1);
    }
    fwrite(STDOUT, "Modalità privata attiva: sitemap non pubblicata.\n");
    $mysqli->close();
    exit(0);
}

// Boot the plugin system the way public/index.php does. SitemapGenerator lets
// active plugins add their own pages through the `sitemap.entries` filter, and
// without a HookManager behind the Hooks facade — and the plugins loaded onto
// it — that filter was a silent no-op here: a sitemap regenerated from cron or
// the command line quietly lost every plugin page (periodicals, archive units)
// that the same sitemap built from the admin button contained.
$hookManager = new \App\Support\HookManager($mysqli);
\App\Support\Hooks::init($hookManager);
$GLOBALS['hookManager'] = $hookManager;
try {
    (new \App\Support\PluginManager($mysqli, $hookManager))->loadActivePlugins();
} catch (\Throwable $pluginError) {
    // A broken plugin must not stop the core sitemap from being written: log
    // it, say so, and generate what the core alone can.
    \App\Support\SecureLogger::error('generate-sitemap: plugins could not be loaded', ['error' => $pluginError->getMessage()]);
    fwrite(STDERR, "Warning: plugins could not be loaded; the sitemap will contain core pages only.\n");
}

try {
    $baseUrl = SeoController::resolveBaseUrl();
    $generator = new SitemapGenerator($mysqli, $baseUrl);
    $targetPath = \App\Support\SitemapCache::publishedPath();

    $generator->saveTo($targetPath);
    if (\App\Support\SitemapCache::revision() !== $revisionAtStart) {
        // Private mode or the plugin set changed since the check above: the
        // document may describe a site that is no longer public.
        if (!\App\Support\SitemapCache::withdraw('settings changed during generation (scripts/generate-sitemap.php)')) {
            fwrite(STDERR, "Impostazioni cambiate durante la generazione, ma public/sitemap.xml non può essere rimosso e resta accessibile: verificare i permessi di public/.\n");
            $mysqli->close();
            exit(1);
        }
        fwrite(STDOUT, "Impostazioni cambiate durante la generazione: sitemap non pubblicata. Rieseguire lo script.\n");
        $mysqli->close();
        exit(0);
    }
    $stats = $generator->getStats();

    $repository = new SettingsRepository($mysqli);
    $repository->ensureTables();
    $generatedAt = gmdate('c');
    $total = (int)($stats['total'] ?? 0);
    $repository->set('advanced', 'sitemap_last_generated_at', $generatedAt);
    $repository->set('advanced', 'sitemap_last_generated_total', (string)$total);
    ConfigStore::set('advanced.sitemap_last_generated_at', $generatedAt);
    ConfigStore::set('advanced.sitemap_last_generated_total', $total);

    $summary = sprintf(
        "Sitemap rigenerata con successo: %d URL (file: %s)\n",
        $total,
        $targetPath
    );
    fwrite(STDOUT, $summary);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Errore durante la generazione della sitemap: ' . $exception->getMessage() . "\n");
    $mysqli->close();
    exit(1);
}

$mysqli->close();
exit(0);
