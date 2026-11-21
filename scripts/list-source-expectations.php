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

/**
 * Print one source-derived expectation per line for shell/CI consumers.
 *
 * Usage:
 *   php scripts/list-source-expectations.php tables
 *   php scripts/list-source-expectations.php plugins
 */

$root = dirname(__DIR__);
$kind = $argv[1] ?? '';

try {
    if ($kind === 'tables') {
        require_once $root . '/installer/classes/Installer.php';
        $sql = file_get_contents($root . '/installer/database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Cannot read installer/database/schema.sql');
        }
        $values = Installer::parseCreateTableNames($sql);
    } elseif ($kind === 'plugins') {
        require_once $root . '/app/Support/BundledPlugins.php';
        $values = \App\Support\BundledPlugins::LIST;
    } else {
        throw new InvalidArgumentException('Expected one argument: tables or plugins');
    }

    if ($values === []) {
        throw new RuntimeException("No {$kind} found in the authoritative source");
    }
    foreach ($values as $value) {
        echo $value, PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
