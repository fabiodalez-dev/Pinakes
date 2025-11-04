<?php
declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

// Definitions array for PHP-DI
$containerDefinitions = [
    'settings' => function () {
        return require __DIR__ . '/settings.php';
    },

    LoggerInterface::class => function () {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../storage/app.log', Level::Debug));
        return $logger;
    },

    // MySQLi service
    'db' => function () {
        $settings = require __DIR__ . '/settings.php';
        $cfg = $settings['db'];
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        $hostname = $cfg['hostname'];
        $socketPath = $cfg['socket'] ?? null;
        
        try {
            $connectionErrors = [];
            $mysqli = null;

            // Auto-detect socket when using localhost and no socket explicitly provided
            if (empty($socketPath) && $hostname === 'localhost') {
                $commonSocketPaths = [
                    '/tmp/mysql.sock',
                    '/var/run/mysqld/mysqld.sock',
                    '/var/lib/mysql/mysql.sock',
                    '/opt/homebrew/var/mysql/mysql.sock',
                    '/usr/local/var/mysql/mysql.sock',
                    '/Applications/MAMP/tmp/mysql/mysql.sock',
                    '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock',
                ];
                foreach ($commonSocketPaths as $path) {
                    if (file_exists($path)) {
                        $socketPath = $path;
                        break;
                    }
                }
            }

            // Build a list of connection strategies (ordered attempts)
            $attempts = [];

            // 1) If socket explicitly provided, try it first
            if (!empty($socketPath)) {
                $attempts[] = function () use ($cfg, $socketPath) {
                    return new mysqli(
                        'localhost',
                        $cfg['username'],
                        $cfg['password'],
                        $cfg['database'],
                        $cfg['port'],
                        $socketPath
                    );
                };
            }

            // 2) Build host candidates (prioritize TCP fallbacks)
            $hostCandidates = [];
            if ($hostname === 'localhost') {
                $hostCandidates = ['127.0.0.1', 'localhost'];
            } else {
                $hostCandidates = [$hostname];
            }

            foreach ($hostCandidates as $hostCandidate) {
                $attempts[] = function () use ($cfg, $hostCandidate) {
                    return new mysqli(
                        $hostCandidate,
                        $cfg['username'],
                        $cfg['password'],
                        $cfg['database'],
                        $cfg['port']
                    );
                };
            }

            // Execute attempts until one succeeds
            foreach ($attempts as $attempt) {
                try {
                    $mysqli = $attempt();
                    if ($mysqli instanceof mysqli) {
                        break;
                    }
                } catch (\mysqli_sql_exception $connectionException) {
                    $connectionErrors[] = $connectionException->getMessage();
                    $mysqli = null;
                    continue;
                }
            }

            if (!$mysqli instanceof mysqli) {
                $context = json_encode([
                    'hostname' => $hostname,
                    'port' => $cfg['port'],
                    'database' => $cfg['database'],
                    'socket' => $socketPath,
                    'errors' => $connectionErrors
                ]);
                throw new Exception("Unable to establish MySQL connection ({$context})");
            }

            // Verify connection
            if ($mysqli->connect_error) {
                throw new Exception($mysqli->connect_error, $mysqli->connect_errno);
            }
            
            $mysqli->set_charset($cfg['charset']);

            // Force UTF-8 encoding for all queries (prevents "PerchÃ©" encoding issues)
            $mysqli->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
            $mysqli->query("SET CHARACTER SET utf8mb4");

            // Test connection with a simple query
            $result = $mysqli->query("SELECT 1 as test");
            if (!$result || $result->fetch_assoc()['test'] != 1) {
                throw new Exception("Database connection test failed");
            }
            $result->free();
            
            return $mysqli;
            
        } catch (Exception $e) {
            $errorContext = [
                'hostname' => $hostname,
                'port' => $cfg['port'],
                'database' => $cfg['database'],
                'charset' => $cfg['charset'],
                'socket' => $socketPath,
            ];
            
            error_log(sprintf(
                "Database connection error: %s (Context: %s)",
                $e->getMessage(),
                json_encode($errorContext)
            ));
            
            $errorMessage = "Database connection failed. ";
            
            if (getenv('APP_DEBUG') === 'true' || $settings['displayErrorDetails']) {
                $errorMessage .= sprintf(
                    "Error: %s (Code: %d). Socket: %s",
                    $e->getMessage(),
                    $e->getCode(),
                    $socketPath ?: 'not found'
                );
            } else {
                $errorMessage .= "Please check your database configuration or contact support.";
            }
            
            throw new Exception($errorMessage);
        }
    },
];
