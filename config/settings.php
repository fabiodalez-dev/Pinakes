<?php
declare(strict_types=1);

// Basic settings; expand as needed
$settings = [
    'displayErrorDetails' => isset($_ENV['APP_DEBUG']) ? filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOL) : false,
    'canonicalUrl' => $_ENV['APP_CANONICAL_URL'] ?? null,
    'db' => [
        'hostname' => $_ENV['DB_HOST'] ?? 'localhost',
        'username' => $_ENV['DB_USER'] ?? null,
        'password' => $_ENV['DB_PASS'] ?? null,
        'database' => $_ENV['DB_NAME'] ?? null,
        'port'     => (int)($_ENV['DB_PORT'] ?? 3306),
        'charset'  => 'utf8mb4',
        'socket'   => $_ENV['DB_SOCKET'] ?? null, // Optional socket path
    ],
];

// Allow override via env
if (isset($_ENV['APP_DEBUG'])) {
    $settings['displayErrorDetails'] = filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOL);
}

// Configure PHP error display based on DISPLAY_ERRORS env variable
if (isset($_ENV['DISPLAY_ERRORS'])) {
    $displayErrors = filter_var($_ENV['DISPLAY_ERRORS'], FILTER_VALIDATE_BOOL);
    ini_set('display_errors', $displayErrors ? '1' : '0');
    ini_set('display_startup_errors', $displayErrors ? '1' : '0');
} elseif (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'production') {
    // Force disable error display in production
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// Local override file (optional): config.local.php
// Supports: $db_config array and other PHP ini tweaks.
$local = __DIR__ . '/../config.local.php';
if (is_file($local)) {
    /** @var array|null $db_config */
    $db_config = null;
    include $local;
    if (is_array($db_config)) {
        $settings['db'] = array_merge($settings['db'], $db_config);
    }
}

return $settings;
