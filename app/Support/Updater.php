<?php
/**
 * Application Updater
 *
 * Handles version checking, downloading, and installing updates from GitHub releases.
 * Includes verbose logging to SecureLogger for troubleshooting update issues.
 *
 * Log output: storage/logs/app.log (filter with grep -i "updater")
 */

declare(strict_types=1);

namespace App\Support;

use mysqli;
use Exception;
use ZipArchive;

/**
 * Application Updater - DEBUG VERSION
 * Handles version checking, downloading, and installing updates from GitHub releases
 */
class Updater
{
    private mysqli $db;
    private string $repoOwner = 'fabiodalez-dev';
    private string $repoName = 'Pinakes';
    private string $rootPath;
    private string $backupPath;
    private string $tempPath;

    /** @var array<string> Files/directories to preserve during update */
    private array $preservePaths = [
        '.env',
        'storage/uploads',
        'storage/plugins',
        'storage/backups',
        'storage/cache',
        'storage/logs',
        'storage/calendar',
        'storage/tmp',
        'public/uploads',
        'public/.htaccess',
        'public/robots.txt',
        'public/favicon.ico',
        'public/sitemap.xml',
        'CLAUDE.md',
    ];

    /**
     * Directories to skip completely during update.
     * @var array<string>
     */
    private array $skipPaths = [
        '.git',
        'node_modules',
    ];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->rootPath = dirname(__DIR__, 2);
        $this->backupPath = $this->rootPath . '/storage/backups';
        $this->tempPath = sys_get_temp_dir() . '/pinakes_update_' . uniqid('', true);

        $this->debugLog('DEBUG', 'Updater inizializzato', [
            'rootPath' => $this->rootPath,
            'backupPath' => $this->backupPath,
            'tempPath' => $this->tempPath,
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'allow_url_fopen' => ini_get('allow_url_fopen'),
            'curl_available' => extension_loaded('curl'),
            'openssl_available' => extension_loaded('openssl'),
            'zip_available' => class_exists('ZipArchive'),
        ]);

        // Ensure backup directory exists
        if (!is_dir($this->backupPath)) {
            if (!mkdir($this->backupPath, 0755, true) && !is_dir($this->backupPath)) {
                $this->debugLog('ERROR', 'Impossibile creare directory di backup', [
                    'path' => $this->backupPath,
                    'error' => error_get_last()
                ]);
                throw new \RuntimeException(sprintf(__('Impossibile creare directory di backup: %s'), $this->backupPath));
            }
        }
    }

    /**
     * Debug logging helper - logs to both SecureLogger and error_log
     */
    private function debugLog(string $level, string $message, array $context = []): void
    {
        $fullMessage = "[Updater DEBUG] [{$level}] {$message}";

        // Always log to error_log for immediate visibility
        error_log($fullMessage . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Also log to SecureLogger if available
        if (class_exists(SecureLogger::class)) {
            $method = strtolower($level);
            if (method_exists(SecureLogger::class, $method)) {
                SecureLogger::$method($fullMessage, $context);
            } else {
                SecureLogger::info($fullMessage, $context);
            }
        }
    }

    /**
     * Get current installed version
     */
    public function getCurrentVersion(): string
    {
        $versionFile = $this->rootPath . '/version.json';

        $this->debugLog('DEBUG', 'Lettura versione corrente', ['file' => $versionFile]);

        if (!file_exists($versionFile)) {
            $this->debugLog('WARNING', 'File version.json non trovato', ['path' => $versionFile]);
            return '0.0.0';
        }

        $content = file_get_contents($versionFile);
        if ($content === false) {
            $this->debugLog('ERROR', 'Impossibile leggere version.json', [
                'path' => $versionFile,
                'error' => error_get_last()
            ]);
            return '0.0.0';
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['version'])) {
            $this->debugLog('ERROR', 'version.json non valido', [
                'content' => $content,
                'json_error' => json_last_error_msg()
            ]);
            return '0.0.0';
        }

        $this->debugLog('INFO', 'Versione corrente rilevata', ['version' => $data['version']]);
        return $data['version'];
    }

    /**
     * Check for available updates from GitHub
     * @return array{available: bool, current: string, latest: string, release: array|null, error: string|null}
     */
    public function checkForUpdates(): array
    {
        $currentVersion = $this->getCurrentVersion();

        $this->debugLog('INFO', 'Controllo aggiornamenti in corso', [
            'current_version' => $currentVersion
        ]);

        try {
            $release = $this->getLatestRelease();

            if ($release === null) {
                $this->debugLog('WARNING', 'Nessuna release trovata su GitHub');
                return [
                    'available' => false,
                    'current' => $currentVersion,
                    'latest' => $currentVersion,
                    'release' => null,
                    'error' => __('Impossibile recuperare informazioni sulla release')
                ];
            }

            $latestVersion = ltrim($release['tag_name'], 'v');
            $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

            $this->debugLog('INFO', 'Controllo completato', [
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'update_available' => $updateAvailable,
                'release_name' => $release['name'] ?? 'N/A',
                'published_at' => $release['published_at'] ?? 'N/A'
            ]);

            return [
                'available' => $updateAvailable,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'release' => $release,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Errore durante controllo aggiornamenti', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'available' => false,
                'current' => $currentVersion,
                'latest' => $currentVersion,
                'release' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get latest release from GitHub API
     */
    private function getLatestRelease(): ?array
    {
        $url = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases/latest";

        $this->debugLog('INFO', 'Richiesta GitHub API - latest release', [
            'url' => $url,
            'repo' => "{$this->repoOwner}/{$this->repoName}"
        ]);

        return $this->makeGitHubRequest($url);
    }

    /**
     * Make HTTP request to GitHub API with detailed logging
     */
    private function makeGitHubRequest(string $url): ?array
    {
        $this->debugLog('DEBUG', 'Preparazione richiesta HTTP', [
            'url' => $url,
            'method' => 'GET'
        ]);

        $headers = [
            'User-Agent: Pinakes-Updater/1.0',
            'Accept: application/vnd.github.v3+json'
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => 30,
                'ignore_errors' => true // Questo ci permette di leggere anche risposte di errore
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        $this->debugLog('DEBUG', 'Context HTTP creato', [
            'headers' => $headers,
            'timeout' => 30
        ]);

        // Capture response headers
        $responseHeaders = [];
        $response = @file_get_contents($url, false, $context);

        // Get response headers from $http_response_header (magic variable)
        if (isset($http_response_header)) {
            $responseHeaders = $http_response_header;
        }

        $this->debugLog('DEBUG', 'Risposta HTTP ricevuta', [
            'response_length' => $response !== false ? strlen($response) : 0,
            'response_headers' => $responseHeaders,
            'response_preview' => $response !== false ? substr($response, 0, 500) : 'FALSE'
        ]);

        if ($response === false) {
            $error = error_get_last();
            $this->debugLog('ERROR', 'Richiesta HTTP fallita', [
                'url' => $url,
                'error' => $error,
                'response_headers' => $responseHeaders
            ]);

            // Prova a capire il problema
            $this->diagnoseConnectionProblem($url);

            throw new Exception(__('Impossibile connettersi a GitHub') . ': ' . ($error['message'] ?? 'Unknown error'));
        }

        // Parse status code from headers
        $statusCode = 0;
        if (!empty($responseHeaders[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $responseHeaders[0], $matches);
            $statusCode = (int)($matches[1] ?? 0);
        }

        $this->debugLog('INFO', 'Status code HTTP', ['status' => $statusCode]);

        if ($statusCode >= 400) {
            $this->debugLog('ERROR', 'GitHub API ha restituito errore', [
                'status_code' => $statusCode,
                'response' => $response,
                'url' => $url
            ]);

            // Decodifica errore GitHub
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['message'] ?? 'Unknown GitHub error';

            throw new Exception("GitHub API error ({$statusCode}): {$errorMessage}");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->debugLog('ERROR', 'Errore parsing JSON', [
                'json_error' => json_last_error_msg(),
                'response_preview' => substr($response, 0, 1000)
            ]);
            return null;
        }

        if (!is_array($data) || !isset($data['tag_name'])) {
            $this->debugLog('WARNING', 'Risposta GitHub non contiene tag_name', [
                'keys' => is_array($data) ? array_keys($data) : 'not_array',
                'data_preview' => is_array($data) ? json_encode(array_slice($data, 0, 5)) : 'N/A'
            ]);
            return null;
        }

        $this->debugLog('INFO', 'Release trovata', [
            'tag_name' => $data['tag_name'],
            'name' => $data['name'] ?? 'N/A',
            'assets_count' => count($data['assets'] ?? []),
            'zipball_url' => $data['zipball_url'] ?? 'N/A'
        ]);

        return $data;
    }

    /**
     * Diagnose connection problems
     */
    private function diagnoseConnectionProblem(string $url): void
    {
        $this->debugLog('INFO', '=== DIAGNOSI CONNESSIONE ===');

        // Test DNS
        $host = parse_url($url, PHP_URL_HOST);
        $ip = @gethostbyname($host);
        $this->debugLog('DEBUG', 'DNS lookup', [
            'host' => $host,
            'resolved_ip' => $ip,
            'dns_ok' => ($ip !== $host)
        ]);

        // Test SSL
        if (extension_loaded('openssl')) {
            $sslContext = stream_context_create(['ssl' => ['capture_peer_cert' => true]]);
            $socket = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $sslContext
            );

            if ($socket) {
                $this->debugLog('DEBUG', 'SSL connection OK', ['host' => $host]);
                fclose($socket);
            } else {
                $this->debugLog('ERROR', 'SSL connection FAILED', [
                    'host' => $host,
                    'errno' => $errno,
                    'errstr' => $errstr
                ]);
            }
        } else {
            $this->debugLog('WARNING', 'OpenSSL extension non disponibile');
        }

        // Check if allow_url_fopen is enabled
        $this->debugLog('DEBUG', 'PHP config check', [
            'allow_url_fopen' => ini_get('allow_url_fopen'),
            'default_socket_timeout' => ini_get('default_socket_timeout'),
            'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'N/A'
        ]);

        // Test with cURL if available
        if (extension_loaded('curl')) {
            $this->debugLog('DEBUG', 'Testing with cURL...');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['User-Agent: Pinakes-Updater/1.0'],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $curlResult = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->debugLog('DEBUG', 'cURL test result', [
                'success' => $curlResult !== false,
                'http_code' => $curlInfo['http_code'] ?? 0,
                'total_time' => $curlInfo['total_time'] ?? 0,
                'error' => $curlError ?: 'none'
            ]);
        }
    }

    /**
     * Get all releases for display
     * @return array<array>
     */
    public function getAllReleases(int $limit = 10): array
    {
        $url = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases?per_page={$limit}";

        $this->debugLog('INFO', 'Recupero tutte le releases', ['url' => $url, 'limit' => $limit]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Pinakes-Updater/1.0',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->debugLog('ERROR', 'Impossibile recuperare releases', [
                'error' => error_get_last()
            ]);
            return [];
        }

        $releases = json_decode($response, true) ?? [];

        $this->debugLog('INFO', 'Releases recuperate', [
            'count' => count($releases),
            'versions' => array_map(fn($r) => $r['tag_name'] ?? 'unknown', $releases)
        ]);

        return $releases;
    }

    /**
     * Download and extract update package
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function downloadUpdate(string $version): array
    {
        $this->debugLog('INFO', '=== INIZIO DOWNLOAD UPDATE ===', ['target_version' => $version]);

        try {
            // Get release info
            $this->debugLog('DEBUG', 'Recupero info release per versione', ['version' => $version]);
            $release = $this->getReleaseByVersion($version);

            if ($release === null) {
                $this->debugLog('ERROR', 'Release non trovata', ['version' => $version]);
                throw new Exception(__('Versione non trovata'));
            }

            $this->debugLog('INFO', 'Release trovata', [
                'tag' => $release['tag_name'],
                'name' => $release['name'] ?? 'N/A',
                'assets' => array_map(fn($a) => $a['name'], $release['assets'] ?? [])
            ]);

            // Find the source code zip asset or use zipball_url
            $downloadUrl = $release['zipball_url'] ?? null;

            // Check for custom asset named pinakes-vX.X.X.zip first
            foreach ($release['assets'] ?? [] as $asset) {
                $this->debugLog('DEBUG', 'Controllo asset', [
                    'name' => $asset['name'],
                    'size' => $asset['size'] ?? 0,
                    'download_url' => $asset['browser_download_url'] ?? 'N/A'
                ]);

                if (preg_match('/pinakes.*\.zip$/i', $asset['name'])) {
                    $downloadUrl = $asset['browser_download_url'];
                    $this->debugLog('INFO', 'Trovato asset personalizzato', [
                        'name' => $asset['name'],
                        'url' => $downloadUrl
                    ]);
                    break;
                }
            }

            if (!$downloadUrl) {
                $this->debugLog('ERROR', 'URL di download non trovato', [
                    'release' => $release['tag_name']
                ]);
                throw new Exception(__('URL di download non trovato'));
            }

            $this->debugLog('INFO', 'URL download selezionato', ['url' => $downloadUrl]);

            // Create temp directory
            if (!is_dir($this->tempPath)) {
                $this->debugLog('DEBUG', 'Creazione directory temporanea', ['path' => $this->tempPath]);
                if (!mkdir($this->tempPath, 0755, true) && !is_dir($this->tempPath)) {
                    $this->debugLog('ERROR', 'Impossibile creare directory temporanea', [
                        'path' => $this->tempPath,
                        'error' => error_get_last()
                    ]);
                    throw new Exception(__('Impossibile creare directory temporanea'));
                }
            }

            $zipPath = $this->tempPath . '/update.zip';
            $this->debugLog('DEBUG', 'Path file ZIP', ['path' => $zipPath]);

            // Download the file
            $this->debugLog('INFO', 'Inizio download file...', ['url' => $downloadUrl]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Pinakes-Updater/1.0',
                        'Accept: application/octet-stream'
                    ],
                    'timeout' => 300,
                    'follow_location' => true,
                    'ignore_errors' => true
                ]
            ]);

            $startTime = microtime(true);
            $fileContent = @file_get_contents($downloadUrl, false, $context);
            $downloadTime = round(microtime(true) - $startTime, 2);

            // Log response headers
            if (isset($http_response_header)) {
                $this->debugLog('DEBUG', 'Response headers download', [
                    'headers' => $http_response_header
                ]);
            }

            if ($fileContent === false) {
                $error = error_get_last();
                $this->debugLog('ERROR', 'Download fallito', [
                    'url' => $downloadUrl,
                    'error' => $error,
                    'download_time' => $downloadTime
                ]);
                throw new Exception(__('Download fallito') . ': ' . ($error['message'] ?? 'Unknown error'));
            }

            $fileSize = strlen($fileContent);
            $this->debugLog('INFO', 'Download completato', [
                'size_bytes' => $fileSize,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
                'time_seconds' => $downloadTime
            ]);

            if ($fileSize < 1000) {
                $this->debugLog('ERROR', 'File scaricato troppo piccolo - probabilmente errore', [
                    'content_preview' => substr($fileContent, 0, 500)
                ]);
                throw new Exception(__('File di aggiornamento non valido (troppo piccolo)'));
            }

            // Save file
            $this->debugLog('DEBUG', 'Salvataggio file ZIP', ['path' => $zipPath]);
            $bytesWritten = file_put_contents($zipPath, $fileContent);

            if ($bytesWritten === false) {
                $this->debugLog('ERROR', 'Impossibile salvare file', [
                    'path' => $zipPath,
                    'error' => error_get_last()
                ]);
                throw new Exception(__('Impossibile salvare il file di aggiornamento'));
            }

            $this->debugLog('INFO', 'File salvato', [
                'path' => $zipPath,
                'bytes_written' => $bytesWritten
            ]);

            // Verify it's a valid zip
            $this->debugLog('DEBUG', 'Verifica integrità ZIP');
            $zip = new ZipArchive();
            $zipOpenResult = $zip->open($zipPath);

            if ($zipOpenResult !== true) {
                $zipErrors = [
                    ZipArchive::ER_EXISTS => 'File already exists',
                    ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                    ZipArchive::ER_INVAL => 'Invalid argument',
                    ZipArchive::ER_MEMORY => 'Malloc failure',
                    ZipArchive::ER_NOENT => 'No such file',
                    ZipArchive::ER_NOZIP => 'Not a zip archive',
                    ZipArchive::ER_OPEN => 'Can\'t open file',
                    ZipArchive::ER_READ => 'Read error',
                    ZipArchive::ER_SEEK => 'Seek error',
                ];

                $this->debugLog('ERROR', 'File ZIP non valido', [
                    'error_code' => $zipOpenResult,
                    'error_message' => $zipErrors[$zipOpenResult] ?? 'Unknown error',
                    'file_size' => filesize($zipPath),
                    'file_first_bytes' => bin2hex(substr(file_get_contents($zipPath), 0, 20))
                ]);
                throw new Exception(__('File di aggiornamento non valido'));
            }

            $this->debugLog('INFO', 'ZIP valido', [
                'num_files' => $zip->numFiles,
                'status' => $zip->status,
                'comment' => $zip->comment ?: 'none'
            ]);

            // List first 10 files in ZIP for debugging
            $zipContents = [];
            for ($i = 0; $i < min(10, $zip->numFiles); $i++) {
                $zipContents[] = $zip->getNameIndex($i);
            }
            $this->debugLog('DEBUG', 'Contenuto ZIP (primi 10 file)', ['files' => $zipContents]);

            // Extract to temp directory
            $extractPath = $this->tempPath . '/extracted';
            $this->debugLog('DEBUG', 'Estrazione ZIP', ['destination' => $extractPath]);

            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                $this->debugLog('ERROR', 'Estrazione fallita', [
                    'destination' => $extractPath,
                    'zip_status' => $zip->status
                ]);
                // Clean up
                if (is_dir($extractPath)) {
                    $this->deleteDirectory($extractPath);
                }
                @unlink($zipPath);
                throw new Exception(__('Estrazione del pacchetto fallita'));
            }
            $zip->close();

            $this->debugLog('INFO', 'Estrazione completata', ['path' => $extractPath]);

            // Find the actual content directory (GitHub adds a prefix)
            $dirs = glob($extractPath . '/*', GLOB_ONLYDIR);
            $this->debugLog('DEBUG', 'Directory estratte', ['dirs' => $dirs]);

            $contentPath = count($dirs) === 1 ? $dirs[0] : $extractPath;

            // Verify package structure
            $this->debugLog('DEBUG', 'Verifica struttura pacchetto', ['path' => $contentPath]);
            $requiredFiles = ['version.json', 'app', 'public', 'installer'];
            $foundFiles = [];
            $missingFiles = [];

            foreach ($requiredFiles as $required) {
                if (file_exists($contentPath . '/' . $required)) {
                    $foundFiles[] = $required;
                } else {
                    $missingFiles[] = $required;
                }
            }

            $this->debugLog('INFO', 'Verifica struttura', [
                'found' => $foundFiles,
                'missing' => $missingFiles
            ]);

            if (!empty($missingFiles)) {
                $this->debugLog('ERROR', 'Pacchetto incompleto', ['missing' => $missingFiles]);
            }

            return [
                'success' => true,
                'path' => $contentPath,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Errore download/estrazione', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get release by version tag
     */
    private function getReleaseByVersion(string $version): ?array
    {
        $tag = strpos($version, 'v') === 0 ? $version : 'v' . $version;
        $url = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases/tags/{$tag}";

        $this->debugLog('INFO', 'Recupero release per tag', [
            'version' => $version,
            'tag' => $tag,
            'url' => $url
        ]);

        try {
            return $this->makeGitHubRequest($url);
        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Errore recupero release per versione', [
                'version' => $version,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create backup before update
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function createBackup(): array
    {
        $logId = null;

        $this->debugLog('INFO', '=== INIZIO BACKUP ===');

        try {
            $timestamp = date('Y-m-d_His');
            $backupDir = $this->backupPath . '/update_' . $timestamp;

            $this->debugLog('DEBUG', 'Creazione directory backup', ['path' => $backupDir]);

            if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                $this->debugLog('ERROR', 'Impossibile creare directory backup', [
                    'path' => $backupDir,
                    'error' => error_get_last()
                ]);
                throw new Exception(__('Impossibile creare directory di backup'));
            }

            // Log the backup start
            $logId = $this->logUpdateStart($this->getCurrentVersion(), 'backup', $backupDir);

            // Backup database
            $this->debugLog('INFO', 'Inizio backup database');
            $dbBackupResult = $this->backupDatabase($backupDir . '/database.sql');

            if (!$dbBackupResult['success']) {
                $this->debugLog('ERROR', 'Backup database fallito', [
                    'error' => $dbBackupResult['error']
                ]);
                throw new Exception($dbBackupResult['error']);
            }

            // Mark backup as complete
            $this->logUpdateComplete($logId, true);

            $this->debugLog('INFO', 'Backup completato con successo', [
                'path' => $backupDir,
                'db_file' => $backupDir . '/database.sql'
            ]);

            return [
                'success' => true,
                'path' => $backupDir,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Errore durante backup', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);

            if ($logId !== null) {
                $this->logUpdateComplete($logId, false, $e->getMessage());
            }

            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get list of available backups
     * @return array<array{name: string, path: string, size: int, date: string}>
     */
    public function getBackupList(): array
    {
        $backups = [];

        if (!is_dir($this->backupPath)) {
            return $backups;
        }

        $dirs = glob($this->backupPath . '/update_*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $dbFile = $dir . '/database.sql';
            $size = file_exists($dbFile) ? filesize($dbFile) : 0;

            $dateStr = str_replace('update_', '', $name);
            $dateStr = str_replace('_', ' ', $dateStr);

            $backups[] = [
                'name' => $name,
                'path' => $dir,
                'size' => $size,
                'date' => $dateStr,
                'created_at' => filemtime($dir)
            ];
        }

        usort($backups, fn($a, $b) => $b['created_at'] - $a['created_at']);

        return $backups;
    }

    /**
     * Delete a backup
     * @return array{success: bool, error: string|null}
     */
    public function deleteBackup(string $backupName): array
    {
        if (preg_match('/[\/\\\\]|\.\./', $backupName)) {
            return ['success' => false, 'error' => __('Nome backup non valido')];
        }

        $backupPath = $this->backupPath . '/' . $backupName;

        if (!is_dir($backupPath)) {
            return ['success' => false, 'error' => __('Backup non trovato')];
        }

        $realBackupPath = realpath($backupPath);
        $realBackupDir = realpath($this->backupPath);

        if ($realBackupPath === false || $realBackupDir === false ||
            strpos($realBackupPath, $realBackupDir) !== 0) {
            return ['success' => false, 'error' => __('Percorso backup non valido')];
        }

        try {
            $this->deleteDirectory($backupPath);
            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get backup file path for download
     * @return array{success: bool, path: string|null, filename: string|null, error: string|null}
     */
    public function getBackupDownloadPath(string $backupName): array
    {
        if (preg_match('/[\/\\\\]|\.\./', $backupName)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => __('Nome backup non valido')];
        }

        $backupPath = $this->backupPath . '/' . $backupName;
        $dbFile = $backupPath . '/database.sql';

        if (!file_exists($dbFile)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => __('File backup non trovato')];
        }

        $realDbFile = realpath($dbFile);
        $realBackupDir = realpath($this->backupPath);

        if ($realDbFile === false || $realBackupDir === false ||
            strpos($realDbFile, $realBackupDir) !== 0) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => __('Percorso backup non valido')];
        }

        return [
            'success' => true,
            'path' => $realDbFile,
            'filename' => $backupName . '.sql',
            'error' => null
        ];
    }

    /**
     * Backup database to file using streaming
     * @return array{success: bool, error: string|null}
     */
    private function backupDatabase(string $filepath): array
    {
        $handle = null;

        try {
            $this->debugLog('INFO', 'Avvio backup database', ['filepath' => $filepath]);

            $handle = fopen($filepath, 'w');
            if ($handle === false) {
                throw new Exception(__('Impossibile aprire file di backup per scrittura'));
            }

            // Get list of tables
            $tables = [];
            $result = $this->db->query("SHOW TABLES");
            if ($result === false) {
                throw new Exception(__('Errore nel recupero delle tabelle') . ': ' . $this->db->error);
            }

            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }
            $result->free();

            $this->debugLog('DEBUG', 'Tabelle trovate', ['count' => count($tables), 'tables' => $tables]);

            // Write header
            fwrite($handle, "-- Pinakes Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Version: " . $this->getCurrentVersion() . "\n");
            fwrite($handle, "-- Tables: " . count($tables) . "\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $this->debugLog('DEBUG', 'Backup tabella', ['table' => $table]);

                // Get create table statement
                $createResult = $this->db->query("SHOW CREATE TABLE `{$table}`");
                if ($createResult === false) {
                    throw new Exception(sprintf(__('Errore nel recupero struttura tabella %s'), $table) . ': ' . $this->db->error);
                }
                $createRow = $createResult->fetch_row();
                $createResult->free();

                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $createRow[1] . ";\n\n");

                // Get data with unbuffered query
                $this->db->real_query("SELECT * FROM `{$table}`");
                $dataResult = $this->db->use_result();

                if ($dataResult === false) {
                    throw new Exception(sprintf(__('Errore nel recupero dati tabella %s'), $table) . ': ' . $this->db->error);
                }

                $rowCount = 0;
                while ($row = $dataResult->fetch_assoc()) {
                    $values = array_map(function ($value) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return "'" . $this->db->real_escape_string($value) . "'";
                    }, $row);

                    fwrite($handle, "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n");
                    $rowCount++;
                }
                $dataResult->free();

                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);
            $handle = null;

            $fileSize = filesize($filepath);
            $this->debugLog('INFO', 'Backup database completato', [
                'filepath' => $filepath,
                'size' => $this->formatBytes((float)$fileSize),
                'tables' => count($tables)
            ]);

            return ['success' => true, 'error' => null];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Errore backup database', ['error' => $e->getMessage()]);

            if ($handle !== null && is_resource($handle)) {
                fclose($handle);
            }

            if (file_exists($filepath)) {
                @unlink($filepath);
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Install update from extracted path
     * @return array{success: bool, error: string|null}
     */
    public function installUpdate(string $sourcePath, string $targetVersion): array
    {
        $appBackupPath = null;
        $logId = null;

        $this->debugLog('INFO', '=== INIZIO INSTALLAZIONE UPDATE ===', [
            'source' => $sourcePath,
            'target_version' => $targetVersion
        ]);

        try {
            $currentVersion = $this->getCurrentVersion();

            // Verify source exists
            if (!is_dir($sourcePath)) {
                $this->debugLog('ERROR', 'Directory sorgente non trovata', ['path' => $sourcePath]);
                throw new Exception(__('Directory sorgente non trovata'));
            }

            // Verify it's a valid Pinakes package
            $requiredPaths = ['version.json', 'app', 'public', 'installer'];
            foreach ($requiredPaths as $required) {
                if (!file_exists($sourcePath . '/' . $required)) {
                    $this->debugLog('ERROR', 'File/directory mancante nel pacchetto', [
                        'missing' => $required,
                        'source' => $sourcePath
                    ]);
                    throw new Exception(sprintf(__('Pacchetto di aggiornamento non valido: manca %s'), $required));
                }
            }

            // Log update start
            $logId = $this->logUpdateStart($currentVersion, $targetVersion, null);

            // Backup current app files
            $this->debugLog('INFO', 'Backup file applicazione per rollback');
            $appBackupPath = $this->backupAppFiles();

            // Copy files
            $this->debugLog('INFO', 'Copia file aggiornamento');
            $this->copyDirectory($sourcePath, $this->rootPath);

            // Clean up orphan files
            $this->debugLog('INFO', 'Pulizia file orfani');
            $this->cleanupOrphanFiles($sourcePath);

            // Run database migrations
            $this->debugLog('INFO', 'Esecuzione migrazioni database', [
                'from' => $currentVersion,
                'to' => $targetVersion
            ]);
            $migrationResult = $this->runMigrations($currentVersion, $targetVersion);

            if (!$migrationResult['success']) {
                $this->debugLog('ERROR', 'Migrazione fallita', [
                    'error' => $migrationResult['error'],
                    'executed' => $migrationResult['executed']
                ]);
                throw new Exception($migrationResult['error']);
            }

            $this->debugLog('INFO', 'Migrazioni completate', [
                'executed' => $migrationResult['executed']
            ]);

            // Fix file permissions
            $this->debugLog('INFO', 'Fix permessi file');
            $this->fixPermissions();

            // Mark update as complete
            $this->logUpdateComplete($logId, true);

            // Cleanup
            $this->cleanup();
            if ($appBackupPath !== null && is_dir($appBackupPath)) {
                $this->deleteDirectory($appBackupPath);
            }

            $this->debugLog('INFO', '=== INSTALLAZIONE COMPLETATA CON SUCCESSO ===');

            return [
                'success' => true,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Errore durante installazione', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // Attempt rollback
            if ($appBackupPath !== null && is_dir($appBackupPath)) {
                try {
                    $this->debugLog('WARNING', 'Tentativo rollback', ['backup' => $appBackupPath]);
                    $this->restoreAppFiles($appBackupPath);
                    $this->debugLog('INFO', 'Rollback completato');
                } catch (Exception $rollbackError) {
                    $this->debugLog('ERROR', 'Rollback fallito', [
                        'error' => $rollbackError->getMessage()
                    ]);
                }
            }

            if ($logId !== null) {
                $this->logUpdateComplete($logId, false, $e->getMessage());
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backup application files for atomic rollback
     */
    private function backupAppFiles(): string
    {
        $timestamp = date('Y-m-d_His');
        $backupPath = sys_get_temp_dir() . '/pinakes_app_backup_' . $timestamp;

        if (!mkdir($backupPath, 0755, true) && !is_dir($backupPath)) {
            throw new Exception(__('Impossibile creare directory di backup applicazione'));
        }

        $dirsToBackup = ['app', 'config', 'locale', 'public/assets', 'installer', 'vendor'];

        foreach ($dirsToBackup as $dir) {
            $sourcePath = $this->rootPath . '/' . $dir;
            $destPath = $backupPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        $versionFile = $this->rootPath . '/version.json';
        if (file_exists($versionFile)) {
            copy($versionFile, $backupPath . '/version.json');
        }

        return $backupPath;
    }

    /**
     * Restore application files from backup
     */
    private function restoreAppFiles(string $backupPath): void
    {
        $dirsToRestore = ['app', 'config', 'locale', 'public/assets', 'installer', 'vendor'];

        foreach ($dirsToRestore as $dir) {
            $sourcePath = $backupPath . '/' . $dir;
            $destPath = $this->rootPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                if (is_dir($destPath)) {
                    $this->deleteDirectory($destPath);
                }
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        $backupVersion = $backupPath . '/version.json';
        if (file_exists($backupVersion)) {
            copy($backupVersion, $this->rootPath . '/version.json');
        }
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectoryRecursive(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($source . '/', '', $item->getPathname());
            $targetPath = $dest . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Clean up orphan files
     */
    private function cleanupOrphanFiles(string $newSourcePath): void
    {
        $dirsToCheck = ['app', 'config', 'locale', 'installer', 'public/assets'];

        foreach ($dirsToCheck as $dir) {
            $currentDir = $this->rootPath . '/' . $dir;
            $newDir = $newSourcePath . '/' . $dir;

            if (!is_dir($currentDir) || !is_dir($newDir)) {
                continue;
            }

            $this->removeOrphansInDirectory($currentDir, $newDir, $dir);
        }
    }

    /**
     * Remove files in current directory that don't exist in new directory
     */
    private function removeOrphansInDirectory(string $currentDir, string $newDir, string $basePath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($currentDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($currentDir . '/', '', $item->getPathname());
            $newPath = $newDir . '/' . $relativePath;
            $fullRelativePath = $basePath . '/' . $relativePath;

            foreach ($this->preservePaths as $preservePath) {
                if (strpos($fullRelativePath, $preservePath) === 0) {
                    continue 2;
                }
            }

            if (!file_exists($newPath)) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                    $this->debugLog('DEBUG', 'Rimosso file orfano', ['path' => $fullRelativePath]);
                }
            }
        }
    }

    /**
     * Copy directory contents, respecting preserve and skip lists
     */
    private function copyDirectory(string $source, string $dest): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($source . '/', '', $item->getPathname());

            if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
                throw new Exception(sprintf(__('Percorso non valido nel pacchetto: %s'), $relativePath));
            }

            if ($item->isLink()) {
                continue;
            }

            $targetPath = $dest . '/' . $relativePath;

            $realDest = realpath($dest);
            $parentTarget = realpath(dirname($targetPath));
            if ($parentTarget !== false && $realDest !== false && strpos($parentTarget, $realDest) !== 0) {
                throw new Exception(sprintf(__('Percorso non valido nel pacchetto: %s'), $relativePath));
            }

            foreach ($this->skipPaths as $skipPath) {
                if (strpos($relativePath, $skipPath) === 0) {
                    continue 2;
                }
            }

            foreach ($this->preservePaths as $preservePath) {
                if (strpos($relativePath, $preservePath) === 0 && file_exists($targetPath)) {
                    continue 2;
                }
            }

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (!mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                        throw new Exception(sprintf(__('Impossibile creare directory: %s'), $relativePath));
                    }
                }
            } else {
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    if (!mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                        throw new Exception(sprintf(__('Impossibile creare directory: %s'), dirname($relativePath)));
                    }
                }
                if (!copy($item->getPathname(), $targetPath)) {
                    throw new Exception(sprintf(__('Errore nella copia del file: %s'), $relativePath));
                }
            }
        }
    }

    /**
     * Run database migrations between versions
     * @return array{success: bool, executed: array<string>, error: string|null}
     */
    public function runMigrations(string $fromVersion, string $toVersion): array
    {
        $executed = [];

        $this->debugLog('INFO', 'Inizio migrazioni', [
            'from' => $fromVersion,
            'to' => $toVersion
        ]);

        try {
            $migrationsPath = $this->rootPath . '/installer/database/migrations';

            if (!is_dir($migrationsPath)) {
                $this->debugLog('WARNING', 'Directory migrazioni non trovata', ['path' => $migrationsPath]);
                return ['success' => true, 'executed' => [], 'error' => null];
            }

            $files = glob($migrationsPath . '/migrate_*.sql');
            sort($files);

            $this->debugLog('DEBUG', 'File migrazioni trovati', [
                'count' => count($files),
                'files' => array_map('basename', $files)
            ]);

            foreach ($files as $file) {
                $filename = basename($file);

                if (preg_match('/migrate_(.+)\.sql$/', $filename, $matches)) {
                    $migrationVersion = $matches[1];

                    $this->debugLog('DEBUG', 'Valutazione migrazione', [
                        'file' => $filename,
                        'migration_version' => $migrationVersion,
                        'from_version' => $fromVersion,
                        'to_version' => $toVersion,
                        'is_newer_than_from' => version_compare($migrationVersion, $fromVersion, '>'),
                        'is_lte_to' => version_compare($migrationVersion, $toVersion, '<=')
                    ]);

                    if (version_compare($migrationVersion, $fromVersion, '>') &&
                        version_compare($migrationVersion, $toVersion, '<=')) {

                        if ($this->isMigrationExecuted($migrationVersion)) {
                            $this->debugLog('DEBUG', 'Migrazione già eseguita, skip', ['version' => $migrationVersion]);
                            continue;
                        }

                        $this->debugLog('INFO', 'Esecuzione migrazione', ['file' => $filename]);

                        $sql = file_get_contents($file);

                        if ($sql !== false && trim($sql) !== '') {
                            $sqlLines = explode("\n", $sql);
                            $sqlLines = array_filter($sqlLines, fn($line) => !preg_match('/^\s*--/', $line));
                            $sql = implode("\n", $sqlLines);

                            $statements = array_filter(
                                array_map('trim', explode(';', $sql)),
                                fn($s) => !empty($s)
                            );

                            $this->debugLog('DEBUG', 'Statement da eseguire', [
                                'count' => count($statements)
                            ]);

                            foreach ($statements as $idx => $statement) {
                                if (!empty(trim($statement))) {
                                    $this->debugLog('DEBUG', 'Esecuzione statement', [
                                        'index' => $idx,
                                        'sql_preview' => substr($statement, 0, 100)
                                    ]);

                                    $result = $this->db->query($statement);
                                    if ($result === false) {
                                        $ignorableErrors = [1060, 1061, 1050];
                                        if (!in_array($this->db->errno, $ignorableErrors, true)) {
                                            $this->debugLog('ERROR', 'Errore SQL', [
                                                'errno' => $this->db->errno,
                                                'error' => $this->db->error,
                                                'statement' => $statement
                                            ]);
                                            throw new Exception(
                                                sprintf(__('Errore SQL durante migrazione %s: %s'), $filename, $this->db->error)
                                            );
                                        }
                                        $this->debugLog('WARNING', 'Errore SQL ignorabile', [
                                            'errno' => $this->db->errno,
                                            'error' => $this->db->error
                                        ]);
                                    }
                                }
                            }
                        }

                        $this->recordMigration($migrationVersion, $filename);
                        $executed[] = $filename;
                        $this->debugLog('INFO', 'Migrazione completata', ['file' => $filename]);
                    }
                }
            }

            return [
                'success' => true,
                'executed' => $executed,
                'error' => null
            ];

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'Errore durante migrazioni', [
                'error' => $e->getMessage(),
                'executed_so_far' => $executed
            ]);
            return [
                'success' => false,
                'executed' => $executed,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check if migrations table exists
     */
    private function migrationsTableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'migrations'");
        if ($result === false) {
            return false;
        }
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }

    /**
     * Check if migration was already executed
     */
    private function isMigrationExecuted(string $version): bool
    {
        if (!$this->migrationsTableExists()) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT id FROM migrations WHERE version = ?");
        if ($stmt === false) {
            throw new Exception(__('Errore preparazione query migrazioni') . ': ' . $this->db->error);
        }
        $stmt->bind_param('s', $version);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new Exception(__('Errore recupero risultati migrazioni') . ': ' . $this->db->error);
        }
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    /**
     * Record migration as executed
     */
    private function recordMigration(string $version, string $filename): void
    {
        if (!$this->migrationsTableExists()) {
            $this->createMigrationsTable();
        }

        $result = $this->db->query("SELECT MAX(batch) as max_batch FROM migrations");
        if ($result === false) {
            throw new Exception(__('Errore recupero batch migrazioni') . ': ' . $this->db->error);
        }
        $row = $result->fetch_assoc();
        $batch = ($row['max_batch'] ?? 0) + 1;
        $result->free();

        $stmt = $this->db->prepare("INSERT INTO migrations (version, filename, batch) VALUES (?, ?, ?)");
        if ($stmt === false) {
            throw new Exception(__('Errore preparazione insert migrazione') . ': ' . $this->db->error);
        }
        $stmt->bind_param('ssi', $version, $filename, $batch);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Create migrations table if it doesn't exist
     */
    private function createMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `migrations` (
            `id` int NOT NULL AUTO_INCREMENT,
            `version` varchar(20) NOT NULL,
            `filename` varchar(255) NOT NULL,
            `batch` int NOT NULL DEFAULT '1',
            `executed_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_version` (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $result = $this->db->query($sql);
        if ($result === false) {
            throw new Exception(__('Errore creazione tabella migrazioni') . ': ' . $this->db->error);
        }
    }

    /**
     * Log update start
     */
    private function logUpdateStart(string $fromVersion, string $toVersion, ?string $backupPath): int
    {
        try {
            $this->db->query("CREATE TABLE IF NOT EXISTS `update_logs` (
                `id` int NOT NULL AUTO_INCREMENT,
                `from_version` varchar(20) NOT NULL,
                `to_version` varchar(20) NOT NULL,
                `status` enum('started','completed','failed','rolled_back') NOT NULL DEFAULT 'started',
                `backup_path` varchar(500) DEFAULT NULL,
                `error_message` text,
                `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `completed_at` datetime DEFAULT NULL,
                `executed_by` int DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $userId = (isset($_SESSION) && isset($_SESSION['user']['id']))
                ? (int) $_SESSION['user']['id']
                : null;

            $stmt = $this->db->prepare("
                INSERT INTO update_logs (from_version, to_version, status, backup_path, executed_by)
                VALUES (?, ?, 'started', ?, ?)
            ");
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('sssi', $fromVersion, $toVersion, $backupPath, $userId);
            $stmt->execute();
            $id = $this->db->insert_id;
            $stmt->close();

            return $id;
        } catch (\Throwable $e) {
            $this->debugLog('WARNING', 'Log update start fallito', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Log update completion
     */
    private function logUpdateComplete(int $logId, bool $success, ?string $error = null): void
    {
        if ($logId <= 0) {
            return;
        }

        try {
            $status = $success ? 'completed' : 'failed';
            $stmt = $this->db->prepare("
                UPDATE update_logs
                SET status = ?, error_message = ?, completed_at = NOW()
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('ssi', $status, $error, $logId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            $this->debugLog('WARNING', 'Log update complete fallito', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get update history
     * @return array<array>
     */
    public function getUpdateHistory(int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT ul.*, CONCAT(u.nome, ' ', u.cognome) as executed_by_name
            FROM update_logs ul
            LEFT JOIN utenti u ON ul.executed_by = u.id
            ORDER BY ul.started_at DESC
            LIMIT ?
        ");
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $history = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
        }

        $stmt->close();
        return $history;
    }

    /**
     * Cleanup temporary files
     */
    public function cleanup(): void
    {
        if (is_dir($this->tempPath)) {
            $this->deleteDirectory($this->tempPath);
        }

        $this->disableMaintenanceMode();

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Enable maintenance mode
     */
    private function enableMaintenanceMode(): void
    {
        $maintenanceFile = $this->rootPath . '/storage/.maintenance';
        file_put_contents($maintenanceFile, json_encode([
            'time' => time(),
            'message' => __('Aggiornamento in corso. Riprova tra qualche minuto.')
        ]));
    }

    /**
     * Disable maintenance mode
     */
    private function disableMaintenanceMode(): void
    {
        $maintenanceFile = $this->rootPath . '/storage/.maintenance';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = @scandir($dir);
        if ($files === false) {
            return;
        }
        $files = array_diff($files, ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Fix file and directory permissions
     */
    private function fixPermissions(): void
    {
        $writableDirs = [
            'storage',
            'storage/backups',
            'storage/cache',
            'storage/logs',
            'storage/plugins',
            'storage/uploads',
            'public/uploads',
        ];

        foreach ($writableDirs as $dir) {
            $fullPath = $this->rootPath . '/' . $dir;
            if (is_dir($fullPath)) {
                chmod($fullPath, 0755);

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        @chmod($item->getPathname(), 0755);
                    } else {
                        @chmod($item->getPathname(), 0644);
                    }
                }
            }
        }

        $envFile = $this->rootPath . '/.env';
        if (file_exists($envFile)) {
            chmod($envFile, 0600);
        }

        $indexFile = $this->rootPath . '/public/index.php';
        if (file_exists($indexFile)) {
            chmod($indexFile, 0644);
        }

        $appDirs = ['app', 'config', 'installer', 'locale', 'vendor'];
        foreach ($appDirs as $dir) {
            $fullPath = $this->rootPath . '/' . $dir;
            if (is_dir($fullPath)) {
                $this->setReadOnlyPermissions($fullPath);
            }
        }
    }

    /**
     * Set read-only permissions recursively
     */
    private function setReadOnlyPermissions(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        chmod($dir, 0755);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @chmod($item->getPathname(), 0755);
            } else {
                @chmod($item->getPathname(), 0644);
            }
        }
    }

    /**
     * Check system requirements
     * @return array{met: bool, requirements: array<array>}
     */
    public function checkRequirements(): array
    {
        $requirements = [];
        $allMet = true;

        $phpVersion = PHP_VERSION;
        $phpMet = version_compare($phpVersion, '8.1.0', '>=');
        $requirements[] = [
            'name' => 'PHP',
            'required' => '8.1+',
            'current' => $phpVersion,
            'met' => $phpMet
        ];
        if (!$phpMet) $allMet = false;

        $zipMet = class_exists('ZipArchive');
        $requirements[] = [
            'name' => 'ZipArchive',
            'required' => __('Richiesto'),
            'current' => $zipMet ? __('Installato') : __('Non installato'),
            'met' => $zipMet
        ];
        if (!$zipMet) $allMet = false;

        $writablePaths = [
            $this->rootPath,
            $this->backupPath,
            $this->rootPath . '/storage',
        ];

        foreach ($writablePaths as $path) {
            $writable = is_writable($path);
            $requirements[] = [
                'name' => __('Scrittura') . ': ' . basename($path),
                'required' => __('Scrivibile'),
                'current' => $writable ? __('Scrivibile') : __('Non scrivibile'),
                'met' => $writable
            ];
            if (!$writable) $allMet = false;
        }

        $freeSpace = disk_free_space($this->rootPath);
        if ($freeSpace === false) {
            $freeSpace = 0;
        }
        $minSpace = 100 * 1024 * 1024;
        $spaceMet = $freeSpace >= $minSpace;
        $requirements[] = [
            'name' => __('Spazio libero'),
            'required' => '100MB',
            'current' => $freeSpace > 0 ? $this->formatBytes($freeSpace) : __('Non disponibile'),
            'met' => $spaceMet
        ];
        if (!$spaceMet) $allMet = false;

        return [
            'met' => $allMet,
            'requirements' => $requirements
        ];
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get changelog between versions
     */
    public function getChangelog(string $fromVersion): array
    {
        $changelog = [];
        $releases = $this->getAllReleases(20);

        foreach ($releases as $release) {
            $releaseVersion = ltrim($release['tag_name'], 'v');

            if (version_compare($releaseVersion, $fromVersion, '>')) {
                $changelog[] = [
                    'version' => $releaseVersion,
                    'name' => $release['name'] ?? $release['tag_name'],
                    'body' => $release['body'] ?? '',
                    'published_at' => $release['published_at'] ?? null,
                    'prerelease' => $release['prerelease'] ?? false
                ];
            }
        }

        return $changelog;
    }

    /**
     * Perform full update process
     * @return array{success: bool, error: string|null, backup_path: string|null}
     */
    public function performUpdate(string $targetVersion): array
    {
        $lockFile = $this->rootPath . '/storage/cache/update.lock';
        $lockHandle = null;

        $this->debugLog('INFO', '========================================');
        $this->debugLog('INFO', '=== PERFORM UPDATE - INIZIO PROCESSO ===');
        $this->debugLog('INFO', '========================================', [
            'current_version' => $this->getCurrentVersion(),
            'target_version' => $targetVersion,
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown'
        ]);

        $maintenanceFile = $this->rootPath . '/storage/.maintenance';
        register_shutdown_function(function () use ($maintenanceFile, $lockFile) {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                error_log("[Updater DEBUG] FATAL ERROR during update: " . json_encode($error));

                if (file_exists($maintenanceFile)) {
                    @unlink($maintenanceFile);
                }

                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
            }
        });

        set_time_limit(0);

        $currentMemory = ini_get('memory_limit');
        if ($currentMemory !== '-1') {
            $memoryBytes = $this->parseMemoryLimit($currentMemory);
            $minMemory = 256 * 1024 * 1024;
            if ($memoryBytes < $minMemory) {
                @ini_set('memory_limit', '256M');
                $this->debugLog('INFO', 'Memory limit aumentato', [
                    'from' => $currentMemory,
                    'to' => '256M'
                ]);
            }
        }

        // Acquire lock
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        $lockHandle = @fopen($lockFile, 'c');
        if (!$lockHandle) {
            $this->debugLog('ERROR', 'Impossibile creare lock file', ['path' => $lockFile]);
            return [
                'success' => false,
                'error' => __('Impossibile creare il file di lock per l\'aggiornamento'),
                'backup_path' => null
            ];
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            $this->debugLog('WARNING', 'Aggiornamento già in corso');
            return [
                'success' => false,
                'error' => __('Un altro aggiornamento è già in corso. Riprova più tardi.'),
                'backup_path' => null
            ];
        }

        ftruncate($lockHandle, 0);
        fwrite($lockHandle, (string)getmypid());
        fflush($lockHandle);

        $this->enableMaintenanceMode();

        $backupResult = ['path' => null, 'success' => false, 'error' => null];
        $result = null;

        try {
            // Step 1: Backup
            $this->debugLog('INFO', '>>> STEP 1: Creazione backup <<<');
            $backupResult = $this->createBackup();
            if (!$backupResult['success']) {
                throw new Exception(__('Backup fallito') . ': ' . $backupResult['error']);
            }
            $this->debugLog('INFO', 'Backup completato', ['path' => $backupResult['path']]);

            // Step 2: Download
            $this->debugLog('INFO', '>>> STEP 2: Download aggiornamento <<<');
            $downloadResult = $this->downloadUpdate($targetVersion);
            if (!$downloadResult['success']) {
                throw new Exception(__('Download fallito') . ': ' . $downloadResult['error']);
            }
            $this->debugLog('INFO', 'Download completato', ['path' => $downloadResult['path']]);

            // Step 3: Install
            $this->debugLog('INFO', '>>> STEP 3: Installazione aggiornamento <<<');
            $installResult = $this->installUpdate($downloadResult['path'], $targetVersion);
            if (!$installResult['success']) {
                throw new Exception(__('Installazione fallita') . ': ' . $installResult['error']);
            }
            $this->debugLog('INFO', 'Installazione completata');

            $result = [
                'success' => true,
                'error' => null,
                'backup_path' => $backupResult['path']
            ];

            $this->debugLog('INFO', '========================================');
            $this->debugLog('INFO', '=== AGGIORNAMENTO COMPLETATO CON SUCCESSO ===');
            $this->debugLog('INFO', '========================================');

        } catch (Exception $e) {
            $this->debugLog('ERROR', 'AGGIORNAMENTO FALLITO', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_path' => $backupResult['path'] ?? null
            ];
        } finally {
            $this->cleanup();

            if ($lockHandle !== null && \is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }

            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }

        return $result;
    }

    /**
     * Parse PHP memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Check and remove stale maintenance file
     */
    public static function checkStaleMaintenanceMode(): void
    {
        $maintenanceFile = dirname(__DIR__, 2) . '/storage/.maintenance';

        if (!file_exists($maintenanceFile)) {
            return;
        }

        $content = @file_get_contents($maintenanceFile);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['time'])) {
            return;
        }

        $maxAge = 30 * 60;
        if ((time() - $data['time']) > $maxAge) {
            @unlink($maintenanceFile);
            if (class_exists(SecureLogger::class)) {
                SecureLogger::warning(__('Modalità manutenzione rimossa automaticamente (scaduta)'), [
                    'started' => date('Y-m-d H:i:s', $data['time']),
                    'age_minutes' => round((time() - $data['time']) / 60)
                ]);
            }
        }
    }
}
