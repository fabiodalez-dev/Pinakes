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

use App\Support\SecureLogger;
use mysqli;
use Exception;
use ZipArchive;

/**
 * Application Updater
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
    private string $githubToken = '';

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

        // ALWAYS use storage/tmp - system temp is unreliable on shared hosting
        $storageTmp = $this->rootPath . '/storage/tmp';

        // Ensure all required directories exist with correct permissions
        $requiredDirs = [
            $storageTmp,
            $this->backupPath,
            $this->rootPath . '/storage/logs',
            $this->rootPath . '/storage/cache',
        ];

        foreach ($requiredDirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            // Try to fix permissions if directory exists but isn't writable
            if (is_dir($dir) && !is_writable($dir)) {
                @chmod($dir, 0775);
            }
        }

        // Clean up old update temp directories to free space
        $this->cleanupOldTempDirs($storageTmp);

        // Always use storage/tmp for maximum compatibility
        $this->tempPath = $storageTmp . '/pinakes_update_' . uniqid('', true);

        // Pre-flight checks
        $issues = [];

        if (!is_writable($storageTmp)) {
            $issues[] = "storage/tmp non scrivibile";
        }
        if (!is_writable($this->backupPath)) {
            $issues[] = "storage/backups non scrivibile";
        }
        if (!class_exists('ZipArchive')) {
            $issues[] = "Estensione ZipArchive non disponibile";
        }
        if (!extension_loaded('curl') && !ini_get('allow_url_fopen')) {
            $issues[] = "Né cURL né allow_url_fopen disponibili";
        }

        // Check disk space (need at least 200MB free)
        $freeSpace = @disk_free_space($this->rootPath);
        if ($freeSpace !== false && $freeSpace < 200 * 1024 * 1024) {
            $issues[] = sprintf("Spazio disco insufficiente: %s disponibili, servono almeno 200MB",
                $this->formatBytes((float)$freeSpace));
        }

        // Load GitHub API token from settings (if configured)
        $this->loadGitHubToken();

        $this->debugLog('DEBUG', 'Updater inizializzato', [
            'rootPath' => $this->rootPath,
            'backupPath' => $this->backupPath,
            'tempPath' => $this->tempPath,
            'storageTmp_writable' => is_writable($storageTmp),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'allow_url_fopen' => ini_get('allow_url_fopen'),
            'curl_available' => extension_loaded('curl'),
            'openssl_available' => extension_loaded('openssl'),
            'zip_available' => class_exists('ZipArchive'),
            'free_space_mb' => $freeSpace !== false ? round($freeSpace / 1024 / 1024) : 'unknown',
            'pre_flight_issues' => $issues ?: 'none'
        ]);

        if (!empty($issues)) {
            $this->debugLog('ERROR', 'Pre-flight check fallito', ['issues' => $issues]);
            throw new \RuntimeException(
                __('Controllo pre-aggiornamento fallito') . ': ' . implode(', ', $issues)
            );
        }
    }

    /**
     * Clean up old temporary update directories to free disk space
     */
    private function cleanupOldTempDirs(string $tmpDir): void
    {
        if (!is_dir($tmpDir)) {
            return;
        }

        $dirs = @glob($tmpDir . '/pinakes_update_*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return;
        }

        $now = time();
        $maxAge = 3600; // 1 hour

        foreach ($dirs as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime !== false && ($now - $mtime) > $maxAge) {
                $this->debugLog('DEBUG', 'Pulizia vecchia directory temporanea', ['path' => $dir]);
                $this->deleteDirectory($dir);
            }
        }

        // Also clean up old app backup directories
        $appBackups = @glob($tmpDir . '/pinakes_app_backup_*', GLOB_ONLYDIR);
        if ($appBackups !== false) {
            foreach ($appBackups as $dir) {
                $mtime = @filemtime($dir);
                if ($mtime !== false && ($now - $mtime) > $maxAge) {
                    $this->debugLog('DEBUG', 'Pulizia vecchio backup app', ['path' => $dir]);
                    $this->deleteDirectory($dir);
                }
            }
        }
    }

    /**
     * Load GitHub token from system_settings
     */
    private function loadGitHubToken(): void
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM system_settings WHERE category = ? AND setting_key = ? LIMIT 1');
        if ($stmt === false) {
            return;
        }
        $cat = 'updater';
        $key = 'github_token';
        $stmt->bind_param('ss', $cat, $key);
        if (!$stmt->execute()) {
            $stmt->close();
            return;
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return;
        }
        $value = $result->fetch_column();
        $stmt->close();

        if (!is_string($value) || $value === '') {
            return;
        }

        $token = trim($this->decryptValue($value));
        if ($token === '' || preg_match('/[[:cntrl:]]/u', $token)) {
            SecureLogger::warning('[Updater] Ignoring invalid GitHub token loaded from settings');
            return;
        }

        $this->githubToken = $token;
    }

    /**
     * Encrypt a value for storage (AES-256-GCM)
     */
    private function encryptValue(string $plain): string
    {
        $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY']
            ?? (getenv('PLUGIN_ENCRYPTION_KEY') ?: null)
            ?? $_ENV['APP_KEY']
            ?? (getenv('APP_KEY') ?: null);

        if ($plain === '') {
            return '';
        }
        if (!$rawKey) {
            throw new Exception(__('Chiave di cifratura non configurata (PLUGIN_ENCRYPTION_KEY o APP_KEY)'));
        }

        try {
            $key = hash('sha256', (string) $rawKey, true);
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($ciphertext === false) {
                throw new Exception(__('Impossibile cifrare il token GitHub'));
            }

            return 'ENC:' . base64_encode($iv . $tag . $ciphertext);
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            SecureLogger::error('[Updater] Token encryption failed: ' . $e->getMessage());
            throw new Exception(__('Impossibile cifrare il token GitHub'));
        }
    }

    /**
     * Decrypt a stored value (backward-compatible with plaintext)
     */
    private function decryptValue(string $stored): string
    {
        if (!str_starts_with($stored, 'ENC:')) {
            return $stored; // plaintext (legacy) — returned as-is
        }

        $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY']
            ?? (getenv('PLUGIN_ENCRYPTION_KEY') ?: null)
            ?? $_ENV['APP_KEY']
            ?? (getenv('APP_KEY') ?: null);

        if (!$rawKey) {
            SecureLogger::error('[Updater] Encryption key not available, cannot decrypt token');
            return '';
        }

        $payload = base64_decode(substr($stored, 4), true);
        if ($payload === false || strlen($payload) <= 28) {
            return '';
        }

        try {
            $key = hash('sha256', (string) $rawKey, true);
            $iv = substr($payload, 0, 12);
            $tag = substr($payload, 12, 16);
            $ciphertext = substr($payload, 28);

            $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain !== false ? $plain : '';
        } catch (\Throwable $e) {
            SecureLogger::error('[Updater] Token decryption failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get GitHub API headers, optionally with Authorization
     * @return array<string>
     */
    private function getGitHubHeaders(string $accept = 'application/vnd.github.v3+json'): array
    {
        $headers = [
            'User-Agent: Pinakes-Updater/1.0',
            'Accept: ' . $accept,
        ];

        if ($this->githubToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->githubToken;
        }

        return $headers;
    }

    /**
     * Save GitHub API token to system_settings
     */
    public function saveGitHubToken(string $token): void
    {
        $token = trim($token);
        if ($token !== '' && preg_match('/[[:cntrl:]]/u', $token)) {
            throw new Exception(__('Token GitHub non valido: contiene caratteri di controllo'));
        }

        $stmt = $this->db->prepare(
            'INSERT INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        if ($stmt === false) {
            throw new Exception(__('Errore nel salvataggio del token'));
        }
        $cat = 'updater';
        $key = 'github_token';
        $encrypted = $token !== '' ? $this->encryptValue($token) : '';
        if ($token !== '' && !str_starts_with($encrypted, 'ENC:')) {
            throw new Exception(__('Impossibile cifrare il token GitHub: salvataggio annullato'));
        }
        $stmt->bind_param('sss', $cat, $key, $encrypted);
        if (!$stmt->execute()) {
            $error = $this->db->error;
            $stmt->close();
            throw new Exception(__('Errore nel salvataggio del token') . ': ' . $error);
        }
        $stmt->close();

        $this->githubToken = $token;
    }

    /**
     * Get the currently configured GitHub token (masked for display)
     */
    public function getGitHubTokenMasked(): string
    {
        if ($this->githubToken === '') {
            return '';
        }

        $len = strlen($this->githubToken);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($this->githubToken, 0, 4) . str_repeat('*', $len - 8) . substr($this->githubToken, -4);
    }

    /**
     * Check if a GitHub token is configured
     */
    public function hasGitHubToken(): bool
    {
        return $this->githubToken !== '';
    }

    /**
     * Debug logging helper - logs to both SecureLogger and error_log
     */
    private function debugLog(string $level, string $message, array $context = []): void
    {
        $fullMessage = "[Updater DEBUG] [{$level}] {$message}";

        // Only log to error_log in debug mode to avoid noise in production
        if (getenv('APP_DEBUG') === 'true') {
            error_log($fullMessage . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

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

        } catch (\Throwable $e) {
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

        $headers = $this->getGitHubHeaders();

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
            'headers_count' => count($headers),
            'authenticated' => $this->githubToken !== '',
            'timeout' => 30
        ]);

        // Capture response headers
        $responseHeaders = [];
        $response = @file_get_contents($url, false, $context);

        // Get response headers from $http_response_header (magic variable set by file_get_contents)
        /** @var array<int, string> $http_response_header */
        if (!empty($http_response_header)) {
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
                CURLOPT_HTTPHEADER => $this->getGitHubHeaders(),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $curlResult = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->debugLog('DEBUG', 'cURL test result', [
                'success' => $curlResult !== false,
                'http_code' => is_array($curlInfo) ? $curlInfo['http_code'] : 0,
                'total_time' => is_array($curlInfo) ? $curlInfo['total_time'] : 0,
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

        $response = null;

        // Try cURL first (consistent with other API methods)
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Pinakes-Updater/1.0',
                CURLOPT_HTTPHEADER => $this->getGitHubHeaders(),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $curlResult = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlResult !== false && $httpCode >= 200 && $httpCode < 400) {
                $response = $curlResult;
            }
        }

        // Fallback to file_get_contents
        if ($response === null) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $this->getGitHubHeaders(),
                    'timeout' => 30,
                    'ignore_errors' => true
                ]
            ]);

            $response = @file_get_contents($url, false, $context);
        }

        if (!is_string($response)) {
            $this->debugLog('ERROR', 'Impossibile recuperare releases', [
                'error' => error_get_last()
            ]);
            return [];
        }

        $releases = json_decode($response, true);

        if (!is_array($releases)) {
            $this->debugLog('ERROR', 'Risposta releases non valida', [
                'json_error' => json_last_error_msg()
            ]);
            return [];
        }

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

            // Download the file - try cURL first (more reliable), fallback to file_get_contents
            $this->debugLog('INFO', 'Inizio download file...', ['url' => $downloadUrl]);

            $startTime = microtime(true);
            $fileContent = false;

            // Try cURL first (more reliable on shared hosting)
            if (extension_loaded('curl')) {
                $this->debugLog('DEBUG', 'Tentativo download con cURL');

                $ch = curl_init($downloadUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_USERAGENT => 'Pinakes-Updater/1.0',
                    CURLOPT_HTTPHEADER => $this->getGitHubHeaders('application/octet-stream'),
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_BUFFERSIZE => 1024 * 1024, // 1MB buffer
                ]);

                $fileContent = curl_exec($ch);
                $curlInfo = curl_getinfo($ch);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);

                $httpCode = is_array($curlInfo) ? $curlInfo['http_code'] : 0;
                $this->debugLog('DEBUG', 'Risultato cURL', [
                    'http_code' => $httpCode,
                    'size_download' => is_array($curlInfo) ? $curlInfo['size_download'] : 0,
                    'total_time' => is_array($curlInfo) ? $curlInfo['total_time'] : 0,
                    'error' => $curlError ?: 'none',
                    'errno' => $curlErrno
                ]);

                if ($curlErrno !== 0 || $httpCode >= 400) {
                    $this->debugLog('WARNING', 'cURL fallito, tentativo con file_get_contents', [
                        'error' => $curlError,
                        'http_code' => $httpCode
                    ]);
                    $fileContent = false;
                }
            }

            // Fallback to file_get_contents
            if ($fileContent === false) {
                $this->debugLog('DEBUG', 'Tentativo download con file_get_contents');

                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => $this->getGitHubHeaders('application/octet-stream'),
                        'timeout' => 300,
                        'follow_location' => true,
                        'ignore_errors' => true
                    ]
                ]);

                $fileContent = @file_get_contents($downloadUrl, false, $context);

                // Log response headers (magic variable set by file_get_contents)
                /** @var array<int, string> $http_response_header */
                if (!empty($http_response_header)) {
                    $this->debugLog('DEBUG', 'Response headers download', [
                        'headers' => $http_response_header
                    ]);
                }
            }

            $downloadTime = round(microtime(true) - $startTime, 2);

            if ($fileContent === false) {
                $error = error_get_last();
                $this->debugLog('ERROR', 'Download fallito con entrambi i metodi', [
                    'url' => $downloadUrl,
                    'error' => $error,
                    'download_time' => $downloadTime,
                    'curl_available' => extension_loaded('curl')
                ]);
                throw new Exception(__('Download fallito') . ': ' . ($error['message'] ?? 'Impossibile scaricare il file'));
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

            // Extract to temp directory - with fallback retry
            $extractPath = $this->tempPath . '/extracted';
            $this->debugLog('DEBUG', 'Estrazione ZIP', ['destination' => $extractPath]);

            // Create extraction directory
            if (!is_dir($extractPath)) {
                @mkdir($extractPath, 0775, true);
            }

            $extractionSuccess = $zip->extractTo($extractPath);

            // If extraction failed, try fallback to storage/tmp
            if (!$extractionSuccess) {
                $this->debugLog('WARNING', 'Estrazione fallita, tentativo con storage/tmp', [
                    'original_path' => $extractPath,
                    'zip_status' => $zip->status,
                    'last_error' => error_get_last()
                ]);

                // Clean up failed attempt
                if (is_dir($extractPath)) {
                    $this->deleteDirectory($extractPath);
                }

                // Try with storage/tmp instead
                $storageTmp = $this->rootPath . '/storage/tmp';
                if (!is_dir($storageTmp)) {
                    @mkdir($storageTmp, 0775, true);
                }

                $fallbackTempPath = $storageTmp . '/pinakes_update_' . uniqid('', true);
                @mkdir($fallbackTempPath, 0775, true);
                $extractPath = $fallbackTempPath . '/extracted';
                @mkdir($extractPath, 0775, true);

                // Update tempPath for cleanup later
                $this->tempPath = $fallbackTempPath;

                $this->debugLog('DEBUG', 'Retry estrazione con storage/tmp', [
                    'new_temp_path' => $fallbackTempPath,
                    'new_extract_path' => $extractPath
                ]);

                // Move ZIP to new location
                $newZipPath = $fallbackTempPath . '/update.zip';
                if (!rename($zipPath, $newZipPath)) {
                    copy($zipPath, $newZipPath);
                    @unlink($zipPath);
                }
                $zipPath = $newZipPath;

                // Re-open ZIP and extract
                $zip->close();
                $zip = new ZipArchive();
                if ($zip->open($zipPath) !== true) {
                    throw new Exception(__('Impossibile riaprire il file ZIP'));
                }

                $extractionSuccess = $zip->extractTo($extractPath);
            }

            if (!$extractionSuccess) {
                $zip->close();
                $this->debugLog('ERROR', 'Estrazione fallita definitivamente', [
                    'destination' => $extractPath,
                    'zip_status' => $zip->status,
                    'last_error' => error_get_last()
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
            $dirs = glob($extractPath . '/*', GLOB_ONLYDIR) ?: [];
            $this->debugLog('DEBUG', 'Directory estratte', ['dirs' => $dirs]);

            $contentPath = $this->findSourceDirectory($extractPath);
            if ($contentPath === null) {
                $this->debugLog('ERROR', 'Struttura pacchetto non valida: impossibile trovare directory sorgente', [
                    'extract_path' => $extractPath,
                    'dirs_found' => $dirs
                ]);
                throw new Exception(__('Struttura pacchetto non valida: directory sorgente non trovata'));
            }

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

        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
            $this->debugLog('ERROR', 'Errore recupero release per versione', [
                'version' => $version,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Save uploaded update package to temp directory
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function saveUploadedPackage(\Psr\Http\Message\UploadedFileInterface $uploadedFile): array
    {
        $this->debugLog('INFO', '=== SALVATAGGIO PACCHETTO MANUALE ===');

        try {
            // Create temp directory for uploaded package
            $uploadTempPath = $this->rootPath . '/storage/tmp/manual_update_' . bin2hex(random_bytes(16));

            if (!mkdir($uploadTempPath, 0755, true)) {
                throw new Exception(__('Impossibile creare directory temporanea per upload'));
            }

            $this->debugLog('DEBUG', 'Directory temporanea creata', ['path' => $uploadTempPath]);

            // Save uploaded file
            $zipPath = $uploadTempPath . '/update.zip';
            $uploadedFile->moveTo($zipPath);

            $this->debugLog('INFO', 'File caricato salvato', [
                'path' => $zipPath,
                'size' => filesize($zipPath)
            ]);

            // Verify it's a valid ZIP
            $zip = new ZipArchive();
            $zipOpenResult = $zip->open($zipPath);

            if ($zipOpenResult !== true) {
                $this->debugLog('ERROR', 'File ZIP non valido', ['error_code' => $zipOpenResult]);
                $this->deleteDirectory($uploadTempPath);
                throw new Exception(__('Il file caricato non è un archivio ZIP valido'));
            }

            $fileCount = $zip->numFiles;
            $zip->close();

            $this->debugLog('INFO', 'Verifica ZIP completata', [
                'file_count' => $fileCount
            ]);

            if ($fileCount === 0) {
                $this->deleteDirectory($uploadTempPath);
                throw new Exception(__('L\'archivio ZIP è vuoto'));
            }

            return [
                'success' => true,
                'path' => $uploadTempPath,
                'error' => null
            ];

        } catch (\Throwable $e) {
            $this->debugLog('ERROR', 'Errore salvataggio pacchetto', [
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Perform update from manually uploaded package
     * This method bypasses GitHub API and uses a local ZIP file
     * @return array{success: bool, error: string|null, backup_path: string|null}
     */
    public function performUpdateFromFile(string $uploadTempPath): array
    {
        $lockFile = $this->rootPath . '/storage/cache/update.lock';
        $lockHandle = null;

        // Validate and canonicalize upload path to prevent path traversal
        $expectedRoot = realpath($this->rootPath . '/storage/tmp');
        $realUploadPath = realpath($uploadTempPath);

        if ($expectedRoot === false || $realUploadPath === false ||
            strpos($realUploadPath, $expectedRoot . DIRECTORY_SEPARATOR) !== 0 ||
            !str_starts_with(basename($realUploadPath), 'manual_update_')) {
            $this->debugLog('ERROR', 'Percorso upload non valido', ['path' => $uploadTempPath]);
            return [
                'success' => false,
                'error' => __('Percorso upload non valido'),
                'backup_path' => null
            ];
        }
        $uploadTempPath = $realUploadPath;

        $this->debugLog('INFO', '========================================');
        $this->debugLog('INFO', '=== PERFORM UPDATE FROM FILE - INIZIO ===');
        $this->debugLog('INFO', '========================================', [
            'current_version' => $this->getCurrentVersion(),
            'upload_temp_path' => $uploadTempPath,
            'php_version' => PHP_VERSION
        ]);

        $maintenanceFile = $this->rootPath . '/storage/.maintenance';
        register_shutdown_function(function () use ($maintenanceFile, $lockFile) {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                error_log("[Updater DEBUG] FATAL ERROR during manual update: " . json_encode($error));

                if (file_exists($maintenanceFile)) {
                    @unlink($maintenanceFile);
                }

                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
            }
        });

        set_time_limit(0);
        ignore_user_abort(true); // Prevent interruption if user closes browser

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

            // Step 2: Extract uploaded ZIP
            $this->debugLog('INFO', '>>> STEP 2: Estrazione pacchetto caricato <<<');
            $zipPath = $uploadTempPath . '/update.zip';

            if (!file_exists($zipPath)) {
                throw new Exception(__('Pacchetto caricato non trovato'));
            }

            $extractPath = $uploadTempPath . '/extracted';
            if (!mkdir($extractPath, 0755, true)) {
                throw new Exception(__('Impossibile creare directory di estrazione'));
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new Exception(__('Impossibile aprire il pacchetto ZIP'));
            }

            // Validate ZIP entries to prevent Zip Slip vulnerability
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false ||
                    str_contains($entry, '..') ||
                    str_starts_with($entry, '/') ||
                    str_starts_with($entry, '\\') ||
                    preg_match('/^[A-Za-z]:[\\\\\\/]/', $entry)) {
                    $zip->close();
                    throw new Exception(__('Percorso non valido nel pacchetto'));
                }
            }

            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                throw new Exception(__('Estrazione del pacchetto fallita'));
            }
            $zip->close();

            $this->debugLog('INFO', 'Estrazione completata', ['path' => $extractPath]);

            // Find the actual source directory (handle GitHub ZIP structure)
            $sourcePath = $this->findSourceDirectory($extractPath);
            if ($sourcePath === null) {
                throw new Exception(__('Struttura pacchetto non valida'));
            }

            // Detect version from package
            $targetVersion = $this->detectVersionFromPackage($sourcePath);
            $this->debugLog('INFO', 'Versione rilevata dal pacchetto', ['version' => $targetVersion]);

            // Step 2.5: Apply pre-update patch (if available)
            $this->debugLog('INFO', '>>> STEP 2.5: Pre-update patch check <<<');
            $patchResult = $this->applyPreUpdatePatch($targetVersion);
            if ($patchResult['applied']) {
                $this->debugLog('INFO', 'Pre-update patch applicato', [
                    'patches' => $patchResult['patches']
                ]);
            }

            // Step 3: Install
            $this->debugLog('INFO', '>>> STEP 3: Installazione aggiornamento <<<');
            $installResult = $this->installUpdate($sourcePath, $targetVersion);
            if (!$installResult['success']) {
                throw new Exception(__('Installazione fallita') . ': ' . $installResult['error']);
            }
            $this->debugLog('INFO', 'Installazione completata');

            // Step 4: Apply post-install patch (if available)
            $this->debugLog('INFO', '>>> STEP 4: Post-install patch check <<<');
            $postPatchResult = $this->applyPostInstallPatch($targetVersion);
            if ($postPatchResult['applied']) {
                $this->debugLog('INFO', 'Post-install patch applicato', [
                    'patches' => $postPatchResult['patches']
                ]);
            }

            // Cleanup uploaded files
            $this->deleteDirectory($uploadTempPath);

            $result = [
                'success' => true,
                'error' => null,
                'backup_path' => $backupResult['path']
            ];

            $this->debugLog('INFO', '========================================');
            $this->debugLog('INFO', '=== AGGIORNAMENTO MANUALE COMPLETATO ===');
            $this->debugLog('INFO', '========================================');

        } catch (\Throwable $e) {
            $this->debugLog('ERROR', 'AGGIORNAMENTO MANUALE FALLITO', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // Cleanup on failure
            if (file_exists($uploadTempPath)) {
                $this->deleteDirectory($uploadTempPath);
            }

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_path' => $backupResult['path'] ?? null
            ];
        } finally {
            $this->cleanup();

            if (\is_resource($lockHandle)) {
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
     * Find the source directory in extracted package (handles GitHub ZIP structure)
     */
    private function findSourceDirectory(string $extractPath): ?string
    {
        // Check if root directory already has the expected structure (flat package)
        if (is_dir($extractPath . '/app') && is_dir($extractPath . '/public')) {
            $this->debugLog('DEBUG', 'Source directory trovata (root)', ['path' => $extractPath]);
            return $extractPath;
        }

        // GitHub releases create a top-level directory like "Pinakes-0.4.8" or "fabiodalez-dev-Pinakes-abc123"
        // We need to find it
        $items = @scandir($extractPath);
        if ($items === false) {
            return null;
        }

        $items = array_diff($items, ['.', '..']);

        // If there's only one directory, that's our source
        if (count($items) === 1) {
            $firstItem = $extractPath . '/' . reset($items);
            if (is_dir($firstItem)) {
                $this->debugLog('DEBUG', 'Source directory trovata (single dir)', ['path' => $firstItem]);
                return $firstItem;
            }
        }

        // Look for directory with common patterns
        foreach ($items as $item) {
            $itemPath = $extractPath . '/' . $item;
            if (is_dir($itemPath)) {
                // Check if it looks like a Pinakes directory (has app/, public/, etc.)
                if (is_dir($itemPath . '/app') && is_dir($itemPath . '/public')) {
                    $this->debugLog('DEBUG', 'Source directory trovata (pattern match)', ['path' => $itemPath]);
                    return $itemPath;
                }
            }
        }

        $this->debugLog('ERROR', 'Source directory non trovata', [
            'extract_path' => $extractPath,
            'items' => $items
        ]);
        return null;
    }

    /**
     * Detect version from package by reading version.json, version.txt or composer.json
     */
    private function detectVersionFromPackage(string $sourcePath): string
    {
        // Try version.json first
        $versionJsonFile = $sourcePath . '/version.json';
        if (file_exists($versionJsonFile)) {
            $versionData = json_decode((string)file_get_contents($versionJsonFile), true);
            if (isset($versionData['version']) && !empty($versionData['version'])) {
                return ltrim($versionData['version'], 'v');
            }
        }

        // Try version.txt
        $versionFile = $sourcePath . '/version.txt';
        if (file_exists($versionFile)) {
            $version = trim((string)file_get_contents($versionFile));
            if (!empty($version)) {
                return $version;
            }
        }

        // Try composer.json
        $composerFile = $sourcePath . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode((string)file_get_contents($composerFile), true);
            if (isset($composer['version'])) {
                return ltrim($composer['version'], 'v');
            }
        }

        // Default to "manual" if can't detect
        return 'manual-' . date('Y-m-d-His');
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

        } catch (\Throwable $e) {
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

        $dirs = glob($this->backupPath . '/update_*', GLOB_ONLYDIR) ?: [];

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
        } catch (\Throwable $e) {
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
                // Validate table name (alphanumeric and underscore only)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                    $this->debugLog('WARNING', 'Skipping table with invalid name', ['table' => $table]);
                    continue;
                }

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

        } catch (\Throwable $e) {
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

            // Cleanup app backup (temp files cleanup handled by caller's finally block)
            if ($appBackupPath !== '' && is_dir($appBackupPath)) {
                $this->deleteDirectory($appBackupPath);
            }

            $this->debugLog('INFO', '=== INSTALLAZIONE COMPLETATA CON SUCCESSO ===');

            return [
                'success' => true,
                'error' => null
            ];

        } catch (\Throwable $e) {
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
                } catch (\Throwable $rollbackError) {
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

        // ALWAYS use storage/tmp for app backup to avoid shared hosting issues
        $storageTmp = $this->rootPath . '/storage/tmp';
        if (!is_dir($storageTmp)) {
            @mkdir($storageTmp, 0775, true);
        }

        $backupPath = $storageTmp . '/pinakes_app_backup_' . $timestamp;

        $this->debugLog('DEBUG', 'Creazione backup app files', ['path' => $backupPath]);

        if (!mkdir($backupPath, 0755, true) && !is_dir($backupPath)) {
            $this->debugLog('ERROR', 'Impossibile creare directory backup app', [
                'path' => $backupPath,
                'error' => error_get_last()
            ]);
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

            $files = glob($migrationsPath . '/migrate_*.sql') ?: [];
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
                            // Remove comment lines (starting with --)
                            $sqlLines = explode("\n", $sql);
                            $sqlLines = array_filter($sqlLines, fn($line) => !preg_match('/^\s*--/', $line));
                            $sql = implode("\n", $sqlLines);

                            // Split statements respecting quoted strings (handles CSS semicolons)
                            $statements = $this->splitSqlStatements($sql);

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
                                        // Ignorable MySQL errors for idempotent migrations:
                                        // 1060: Duplicate column name (ADD COLUMN when column exists)
                                        // 1061: Duplicate key name (ADD INDEX when index exists)
                                        // 1050: Table already exists (CREATE TABLE)
                                        // 1091: Can't DROP (column/key doesn't exist)
                                        // 1068: Multiple primary key defined
                                        // 1022: Duplicate key (constraint already exists)
                                        // 1826: Duplicate foreign key constraint
                                        // 1146: Table doesn't exist (DROP TABLE IF NOT EXISTS workaround)
                                        $ignorableErrors = [1060, 1061, 1050, 1091, 1068, 1022, 1826, 1146];
                                        if (!in_array($this->db->errno, $ignorableErrors, true)) {
                                            $this->debugLog('ERROR', 'Errore SQL critico', [
                                                'errno' => $this->db->errno,
                                                'error' => $this->db->error,
                                                'statement' => $statement
                                            ]);
                                            throw new Exception(
                                                sprintf(__('Errore SQL durante migrazione %s: %s'), $filename, $this->db->error)
                                            );
                                        }
                                        $this->debugLog('DEBUG', 'Errore SQL ignorabile (oggetto già esistente)', [
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

        } catch (\Throwable $e) {
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
     * Split SQL content into individual statements, respecting quoted strings.
     *
     * This method correctly handles semicolons inside quoted strings (e.g., CSS inline styles
     * like style="padding: 20px; margin: 10px") by only splitting on semicolons that are
     * outside of single-quoted strings.
     *
     * @param string $sql The SQL content to split
     * @return array<string> Array of individual SQL statements
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // Handle single quotes (SQL string delimiter)
            if ($char === "'") {
                // Check for escaped quote ('')
                if ($inString && $i + 1 < $length && $sql[$i + 1] === "'") {
                    // Escaped quote - add both and skip next
                    $current .= "''";
                    $i++;
                    continue;
                }
                // Toggle string state
                $inString = !$inString;
                $current .= $char;
                continue;
            }

            // Handle semicolon - only split if outside string
            if ($char === ';' && !$inString) {
                $trimmed = trim($current);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        // Add final statement if any
        $trimmed = trim($current);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
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
        // Check if update_logs table exists
        $tableCheck = $this->db->query("SHOW TABLES LIKE 'update_logs'");
        if ($tableCheck === false || $tableCheck->num_rows === 0) {
            return [];
        }
        $tableCheck->free();

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
        ignore_user_abort(true); // Prevent interruption if user closes browser

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
            // Step 0: Apply pre-update patch (if available)
            $this->debugLog('INFO', '>>> STEP 0: Pre-update patch check <<<');
            $patchResult = $this->applyPreUpdatePatch($targetVersion);
            if ($patchResult['applied']) {
                $this->debugLog('INFO', 'Pre-update patch applicato', [
                    'patches' => $patchResult['patches']
                ]);
            }

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

            // Step 4: Apply post-install patch (if available)
            $this->debugLog('INFO', '>>> STEP 4: Post-install patch check <<<');
            $postPatchResult = $this->applyPostInstallPatch($targetVersion);
            if ($postPatchResult['applied']) {
                $this->debugLog('INFO', 'Post-install patch applicato', [
                    'patches' => $postPatchResult['patches'],
                    'cleanup' => $postPatchResult['cleanup'],
                    'sql' => $postPatchResult['sql']
                ]);
            } else {
                $this->debugLog('INFO', 'Nessun post-install-patch disponibile (OK, normale)');
            }

            $result = [
                'success' => true,
                'error' => null,
                'backup_path' => $backupResult['path']
            ];

            $this->debugLog('INFO', '========================================');
            $this->debugLog('INFO', '=== AGGIORNAMENTO COMPLETATO CON SUCCESSO ===');
            $this->debugLog('INFO', '========================================');

        } catch (\Throwable $e) {
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

            if (\is_resource($lockHandle)) {
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
        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }
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
     * Apply pre-update patch from GitHub if available
     *
     * This method downloads and executes a patch file from GitHub releases
     * before the main update process. It allows fixing Updater bugs remotely
     * without requiring users to manually update files.
     *
     * @param string $targetVersion The version being updated to
     * @return array{success: bool, applied: bool, error: string|null, patches: array}
     */
    public function applyPreUpdatePatch(string $targetVersion): array
    {
        $this->debugLog('INFO', '=== PRE-UPDATE PATCH CHECK ===', [
            'current_version' => $this->getCurrentVersion(),
            'target_version' => $targetVersion
        ]);

        $result = [
            'success' => true,
            'applied' => false,
            'error' => null,
            'patches' => []
        ];

        try {
            // Build URL for pre-update-patch.php from GitHub release
            $tag = strpos($targetVersion, 'v') === 0 ? $targetVersion : 'v' . $targetVersion;
            $baseUrl = "https://github.com/{$this->repoOwner}/{$this->repoName}/releases/download/{$tag}";
            $patchUrl = $baseUrl . '/pre-update-patch.php';
            $checksumUrl = $baseUrl . '/pre-update-patch.php.sha256';

            $this->debugLog('DEBUG', 'Tentativo download pre-update-patch', [
                'patch_url' => $patchUrl,
                'checksum_url' => $checksumUrl
            ]);

            // Download patch file
            $patchContent = $this->downloadPatchFile($patchUrl);

            if ($patchContent === null) {
                // 404 or download failed - this is OK, no patch needed
                $this->debugLog('INFO', 'Nessun pre-update-patch disponibile (OK, normale)', [
                    'reason' => 'File not found or download failed'
                ]);
                return $result;
            }

            $this->debugLog('INFO', 'Pre-update-patch scaricato', [
                'size' => strlen($patchContent)
            ]);

            // Download checksum file
            $checksumContent = $this->downloadPatchFile($checksumUrl);

            if ($checksumContent === null) {
                $this->debugLog('WARNING', 'Checksum file non trovato, patch ignorata per sicurezza');
                return $result;
            }

            // Verify checksum
            $expectedChecksum = trim(explode(' ', trim($checksumContent))[0]);
            $actualChecksum = hash('sha256', $patchContent);

            if ($expectedChecksum !== $actualChecksum) {
                $this->debugLog('ERROR', 'Checksum pre-update-patch non valido', [
                    'expected' => $expectedChecksum,
                    'actual' => $actualChecksum
                ]);
                // Don't fail the update, just skip the patch
                return $result;
            }

            $this->debugLog('INFO', 'Checksum verificato', ['checksum' => $actualChecksum]);

            // Save patch to temp file and evaluate
            $tempPatchFile = $this->rootPath . '/storage/tmp/pre-update-patch-' . uniqid() . '.php';
            if (!is_dir(dirname($tempPatchFile))) {
                @mkdir(dirname($tempPatchFile), 0775, true);
            }

            if (file_put_contents($tempPatchFile, $patchContent) === false) {
                $this->debugLog('ERROR', 'Impossibile salvare patch temporanea');
                return $result;
            }

            // Execute patch file to get patch definition
            $patchDefinition = require $tempPatchFile;
            @unlink($tempPatchFile);

            if (!is_array($patchDefinition)) {
                $this->debugLog('WARNING', 'Patch definition non valida (non è un array)');
                return $result;
            }

            // Check if current version is in target versions
            $currentVersion = $this->getCurrentVersion();
            $targetVersions = $patchDefinition['target_versions'] ?? [];

            $this->debugLog('DEBUG', 'Verifica versione target', [
                'current' => $currentVersion,
                'targets' => $targetVersions
            ]);

            if (!in_array($currentVersion, $targetVersions, true)) {
                $this->debugLog('INFO', 'Versione corrente non richiede patch', [
                    'current' => $currentVersion,
                    'targets' => $targetVersions
                ]);
                return $result;
            }

            // Apply patches
            $patches = $patchDefinition['patches'] ?? [];
            $appliedPatches = [];

            foreach ($patches as $patch) {
                $patchResult = $this->applySinglePatch($patch);
                if ($patchResult['success']) {
                    $appliedPatches[] = [
                        'file' => $patch['file'] ?? 'unknown',
                        'description' => $patch['description'] ?? 'No description'
                    ];
                    $this->debugLog('INFO', 'Patch applicata', [
                        'file' => $patch['file'] ?? 'unknown',
                        'description' => $patch['description'] ?? ''
                    ]);
                } else {
                    $this->debugLog('WARNING', 'Patch fallita', [
                        'file' => $patch['file'] ?? 'unknown',
                        'error' => $patchResult['error']
                    ]);
                }
            }

            $result['applied'] = !empty($appliedPatches);
            $result['patches'] = $appliedPatches;

            $this->debugLog('INFO', '=== PRE-UPDATE PATCH COMPLETATO ===', [
                'patches_applied' => count($appliedPatches)
            ]);

        } catch (\Throwable $e) {
            // Don't fail the entire update if patch fails
            $this->debugLog('ERROR', 'Errore durante pre-update-patch', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            // Still return success=true to allow update to continue
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Download patch file from URL, returns null on 404 or error
     */
    private function downloadPatchFile(string $url): ?string
    {
        // Try cURL first
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Pinakes-Updater/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 404) {
                // File not found - this is normal (no patch needed)
                return null;
            }

            if ($httpCode === 403) {
                // Access denied - log for diagnosis (rate limit or permissions issue)
                $this->debugLog('WARNING', 'Download patch negato (403)', ['url' => $url]);
                return null;
            }

            if ($httpCode >= 200 && $httpCode < 300 && $content !== false) {
                return $content;
            }

            $this->debugLog('DEBUG', 'cURL download fallito', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error
            ]);
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => ['User-Agent: Pinakes-Updater/1.0'],
                'timeout' => 30,
                'follow_location' => true,
                'ignore_errors' => true
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        // Check HTTP status from response headers (magic variable set by file_get_contents)
        /** @var array<int, string> $http_response_header */
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int) $matches[1];
                    if ($httpCode === 404) {
                        return null;
                    }
                    if ($httpCode === 403) {
                        $this->debugLog('WARNING', 'Download patch negato (403)', ['url' => $url]);
                        return null;
                    }
                    break;
                }
            }
        }

        return $content !== false ? $content : null;
    }

    /**
     * Apply a single file patch
     *
     * @param array{file?: string, search?: string, replace?: string, description?: string} $patch
     * @return array{success: bool, error: string|null}
     */
    private function applySinglePatch(array $patch): array
    {
        if (!isset($patch['file'], $patch['search'], $patch['replace'])) {
            return ['success' => false, 'error' => 'Invalid patch definition'];
        }

        $filePath = $this->rootPath . '/' . $patch['file'];

        // Security: ensure path is within root
        $realPath = realpath($filePath);
        $realRoot = realpath($this->rootPath);

        if ($realPath === false) {
            return ['success' => false, 'error' => 'File not found: ' . $patch['file']];
        }

        if (strpos($realPath, $realRoot) !== 0) {
            return ['success' => false, 'error' => 'Invalid file path (outside root)'];
        }

        // Read file content
        $content = file_get_contents($realPath);
        if ($content === false) {
            return ['success' => false, 'error' => 'Cannot read file'];
        }

        // Check if search string exists
        $occurrences = substr_count($content, $patch['search']);
        if ($occurrences === 0) {
            // Already patched or different version
            return ['success' => false, 'error' => 'Search string not found (possibly already patched)'];
        }
        if ($occurrences > 1) {
            // Ambiguous patch - search string is not unique
            return ['success' => false, 'error' => 'Search string is not unique (' . $occurrences . ' occurrences)'];
        }

        // Apply replacement
        $newContent = str_replace($patch['search'], $patch['replace'], $content);

        if ($newContent === $content) {
            return ['success' => false, 'error' => 'No changes made'];
        }

        // Write back
        if (file_put_contents($realPath, $newContent) === false) {
            return ['success' => false, 'error' => 'Cannot write file'];
        }

        // Clear opcache for this file
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($realPath, true);
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Apply post-install patch from GitHub if available
     *
     * This method downloads and executes a patch file from GitHub releases
     * after the update completes. It allows applying hotfixes, cleanup tasks,
     * and SQL queries to the newly installed version.
     *
     * @param string $targetVersion The version that was just installed
     * @return array{success: bool, applied: bool, error: string|null, patches: array, cleanup: array, sql: array}
     */
    public function applyPostInstallPatch(string $targetVersion): array
    {
        $this->debugLog('INFO', '=== POST-INSTALL PATCH CHECK ===', [
            'installed_version' => $targetVersion
        ]);

        $result = [
            'success' => true,
            'applied' => false,
            'error' => null,
            'patches' => [],
            'cleanup' => [],
            'sql' => []
        ];

        try {
            // Build URL for post-install-patch.php from GitHub release
            $tag = strpos($targetVersion, 'v') === 0 ? $targetVersion : 'v' . $targetVersion;
            $baseUrl = "https://github.com/{$this->repoOwner}/{$this->repoName}/releases/download/{$tag}";
            $patchUrl = $baseUrl . '/post-install-patch.php';
            $checksumUrl = $baseUrl . '/post-install-patch.php.sha256';

            $this->debugLog('DEBUG', 'Tentativo download post-install-patch', [
                'patch_url' => $patchUrl,
                'checksum_url' => $checksumUrl
            ]);

            // Download patch file
            $patchContent = $this->downloadPatchFile($patchUrl);

            if ($patchContent === null) {
                // 404 or download failed - this is OK, no patch needed
                $this->debugLog('INFO', 'Nessun post-install-patch disponibile (OK, normale)', [
                    'reason' => 'File not found or download failed'
                ]);
                return $result;
            }

            $this->debugLog('INFO', 'Post-install-patch scaricato', [
                'size' => strlen($patchContent)
            ]);

            // Download checksum file
            $checksumContent = $this->downloadPatchFile($checksumUrl);

            if ($checksumContent === null) {
                $this->debugLog('WARNING', 'Checksum file non trovato, patch ignorata per sicurezza');
                return $result;
            }

            // Verify checksum
            $expectedChecksum = trim(explode(' ', trim($checksumContent))[0]);
            $actualChecksum = hash('sha256', $patchContent);

            if ($expectedChecksum !== $actualChecksum) {
                $this->debugLog('ERROR', 'Checksum post-install-patch non valido', [
                    'expected' => $expectedChecksum,
                    'actual' => $actualChecksum
                ]);
                return $result;
            }

            $this->debugLog('INFO', 'Checksum verificato', ['checksum' => $actualChecksum]);

            // Save patch to temp file and evaluate
            $tempPatchFile = $this->rootPath . '/storage/tmp/post-install-patch-' . uniqid() . '.php';
            if (!is_dir(dirname($tempPatchFile))) {
                @mkdir(dirname($tempPatchFile), 0775, true);
            }

            if (file_put_contents($tempPatchFile, $patchContent) === false) {
                $this->debugLog('ERROR', 'Impossibile salvare patch temporanea');
                return $result;
            }

            // Execute patch file to get patch definition
            $patchDefinition = require $tempPatchFile;
            @unlink($tempPatchFile);

            if (!is_array($patchDefinition)) {
                $this->debugLog('WARNING', 'Patch definition non valida (non è un array)');
                return $result;
            }

            $anyApplied = false;

            // 1. Apply file patches
            $patches = $patchDefinition['patches'] ?? [];
            $appliedPatches = [];

            foreach ($patches as $patch) {
                $patchResult = $this->applySinglePatch($patch);
                if ($patchResult['success']) {
                    $appliedPatches[] = [
                        'file' => $patch['file'] ?? 'unknown',
                        'description' => $patch['description'] ?? 'No description'
                    ];
                    $this->debugLog('INFO', 'Patch applicata', [
                        'file' => $patch['file'] ?? 'unknown',
                        'description' => $patch['description'] ?? ''
                    ]);
                    $anyApplied = true;
                } else {
                    $this->debugLog('WARNING', 'Patch fallita', [
                        'file' => $patch['file'] ?? 'unknown',
                        'error' => $patchResult['error']
                    ]);
                }
            }
            $result['patches'] = $appliedPatches;

            // 2. Apply cleanup (file deletion)
            $cleanup = $patchDefinition['cleanup'] ?? [];
            $cleanedFiles = [];

            foreach ($cleanup as $fileToDelete) {
                $cleanupResult = $this->cleanupFile($fileToDelete);
                if ($cleanupResult['success']) {
                    $cleanedFiles[] = $fileToDelete;
                    $this->debugLog('INFO', 'File eliminato', ['file' => $fileToDelete]);
                    $anyApplied = true;
                } else {
                    $this->debugLog('WARNING', 'Cleanup fallito', [
                        'file' => $fileToDelete,
                        'error' => $cleanupResult['error']
                    ]);
                }
            }
            $result['cleanup'] = $cleanedFiles;

            // 3. Apply SQL queries
            $sqlQueries = $patchDefinition['sql'] ?? [];
            $executedSql = [];

            foreach ($sqlQueries as $sql) {
                $sqlResult = $this->executePostInstallSql($sql);
                if ($sqlResult['success']) {
                    $executedSql[] = substr($sql, 0, 100) . (strlen($sql) > 100 ? '...' : '');
                    $this->debugLog('INFO', 'SQL eseguito', ['sql' => substr($sql, 0, 100)]);
                    $anyApplied = true;
                } else {
                    $this->debugLog('WARNING', 'SQL fallito', [
                        'sql' => substr($sql, 0, 100),
                        'error' => $sqlResult['error']
                    ]);
                }
            }
            $result['sql'] = $executedSql;

            $result['applied'] = $anyApplied;

            $this->debugLog('INFO', '=== POST-INSTALL PATCH COMPLETATO ===', [
                'patches_applied' => count($appliedPatches),
                'files_cleaned' => count($cleanedFiles),
                'sql_executed' => count($executedSql)
            ]);

        } catch (\Throwable $e) {
            // Don't fail if post-install patch fails - update is already done
            $this->debugLog('ERROR', 'Errore durante post-install-patch', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Delete a file as part of cleanup
     *
     * @param string $relativePath Path relative to root
     * @return array{success: bool, error: string|null}
     */
    private function cleanupFile(string $relativePath): array
    {
        $filePath = $this->rootPath . '/' . $relativePath;

        // Security: ensure path is within root
        $realPath = realpath($filePath);
        $realRoot = realpath($this->rootPath);

        if ($realPath === false) {
            // File doesn't exist - consider this success (already cleaned)
            return ['success' => true, 'error' => null];
        }

        if (strpos($realPath, $realRoot) !== 0) {
            return ['success' => false, 'error' => 'Invalid file path (outside root)'];
        }

        // Don't allow deleting critical files
        // Protect: exact match, files in protected subdirectories,
        // and files with protected basename in any subdirectory (e.g., subdir/.env)
        $protectedPaths = ['.env', 'version.json', 'public/index.php', 'composer.json'];
        $basename = basename($relativePath);
        foreach ($protectedPaths as $protected) {
            $protectedBasename = basename($protected);
            if ($relativePath === $protected ||
                strpos($relativePath, $protected . '/') === 0 ||
                $basename === $protectedBasename) {
                return ['success' => false, 'error' => 'Cannot delete protected file'];
            }
        }

        // Delete file or directory
        if (is_dir($realPath)) {
            $this->deleteDirectory($realPath);
        } else {
            @unlink($realPath);
        }

        return ['success' => !file_exists($realPath), 'error' => null];
    }

    /**
     * Execute a post-install SQL query
     *
     * @param string $sql SQL query to execute
     * @return array{success: bool, error: string|null}
     */
    private function executePostInstallSql(string $sql): array
    {
        $sql = trim($sql);
        if (empty($sql)) {
            return ['success' => true, 'error' => null];
        }

        // Security: block dangerous operations
        $dangerousPatterns = [
            '/\bDROP\s+DATABASE\b/i',
            '/\bTRUNCATE\s+TABLE\s+utenti\b/i',
            '/\bDELETE\s+FROM\s+utenti\s+WHERE\s+1/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return ['success' => false, 'error' => 'Dangerous SQL blocked'];
            }
        }

        $result = $this->db->query($sql);

        if ($result === false) {
            // Allow certain ignorable errors (same as migrations)
            $ignorableErrors = [1060, 1061, 1050, 1091, 1068, 1022, 1826, 1146];
            if (in_array($this->db->errno, $ignorableErrors, true)) {
                return ['success' => true, 'error' => null];
            }
            return ['success' => false, 'error' => $this->db->error];
        }

        return ['success' => true, 'error' => null];
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
