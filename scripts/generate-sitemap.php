#!/usr/bin/env php
<?php
declare(strict_types=1);

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

try {
    $baseUrl = SeoController::resolveBaseUrl();
    $generator = new SitemapGenerator($mysqli, $baseUrl);
    $targetPath = $rootDir . '/public/sitemap.xml';

    $generator->saveTo($targetPath);
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
