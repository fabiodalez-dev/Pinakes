<?php
/**
 * Simple PSR-4 compatible autoloader
 */
spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/';

    // List of possible file locations
    $possibleFiles = [
        // Root level (Config.php, Router.php, etc.)
        $baseDir . $class . '.php',

        // Middleware
        $baseDir . 'Middleware/' . $class . '.php',

        // Controllers
        $baseDir . 'Controllers/' . $class . '.php',

        // Services
        $baseDir . 'Services/' . $class . '.php',

        // Scraping
        $baseDir . 'Scraping/' . $class . '.php',

        // Scrapers
        $baseDir . 'Scraping/Scrapers/' . $class . '.php',
    ];

    foreach ($possibleFiles as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
