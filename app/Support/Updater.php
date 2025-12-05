<?php
declare(strict_types=1);

namespace App\Support;

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

    /** @var array<string> Files/directories to preserve during update */
    private array $preservePaths = [
        '.env',
        'storage/uploads',
        'storage/plugins',
        'storage/backups',
        'storage/cache',
        'public/uploads',
        'CLAUDE.md',
    ];

    /** @var array<string> Directories to skip completely */
    private array $skipPaths = [
        '.git',
        'node_modules',
        'vendor',
    ];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->rootPath = dirname(__DIR__, 2);
        $this->backupPath = $this->rootPath . '/storage/backups';
        $this->tempPath = sys_get_temp_dir() . '/pinakes_update_' . uniqid('', true);

        // Ensure backup directory exists
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * Get current installed version
     */
    public function getCurrentVersion(): string
    {
        $versionFile = $this->rootPath . '/version.json';

        if (!file_exists($versionFile)) {
            return '0.0.0';
        }

        $data = json_decode(file_get_contents($versionFile), true);
        return $data['version'] ?? '0.0.0';
    }

    /**
     * Check for available updates from GitHub
     * @return array{available: bool, current: string, latest: string, release: array|null, error: string|null}
     */
    public function checkForUpdates(): array
    {
        $currentVersion = $this->getCurrentVersion();

        try {
            $release = $this->getLatestRelease();

            if ($release === null) {
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

            return [
                'available' => $updateAvailable,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'release' => $release,
                'error' => null
            ];

        } catch (Exception $e) {
            error_log("[Updater] Error checking for updates: " . $e->getMessage());
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

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Pinakes-Updater/1.0',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception(__('Impossibile connettersi a GitHub'));
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['tag_name'])) {
            return null;
        }

        return $data;
    }

    /**
     * Get all releases for display
     * @return array<array>
     */
    public function getAllReleases(int $limit = 10): array
    {
        $url = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases?per_page={$limit}";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Pinakes-Updater/1.0',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [];
        }

        return json_decode($response, true) ?? [];
    }

    /**
     * Download and extract update package
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function downloadUpdate(string $version): array
    {
        try {
            // Get release info
            $release = $this->getReleaseByVersion($version);

            if ($release === null) {
                throw new Exception(__('Versione non trovata'));
            }

            // Find the source code zip asset or use zipball_url
            $downloadUrl = $release['zipball_url'] ?? null;

            // Check for custom asset named pinakes-vX.X.X.zip first
            foreach ($release['assets'] ?? [] as $asset) {
                if (preg_match('/pinakes.*\.zip$/i', $asset['name'])) {
                    $downloadUrl = $asset['browser_download_url'];
                    break;
                }
            }

            if (!$downloadUrl) {
                throw new Exception(__('URL di download non trovato'));
            }

            // Create temp directory
            if (!is_dir($this->tempPath)) {
                mkdir($this->tempPath, 0755, true);
            }

            $zipPath = $this->tempPath . '/update.zip';

            // Download the file
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Pinakes-Updater/1.0',
                        'Accept: application/octet-stream'
                    ],
                    'timeout' => 300,
                    'follow_location' => true
                ]
            ]);

            $fileContent = @file_get_contents($downloadUrl, false, $context);

            if ($fileContent === false) {
                throw new Exception(__('Download fallito'));
            }

            file_put_contents($zipPath, $fileContent);

            // Verify it's a valid zip
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new Exception(__('File di aggiornamento non valido'));
            }

            // Extract to temp directory
            $extractPath = $this->tempPath . '/extracted';
            $zip->extractTo($extractPath);
            $zip->close();

            // Find the actual content directory (GitHub adds a prefix)
            $dirs = glob($extractPath . '/*', GLOB_ONLYDIR);
            $contentPath = count($dirs) === 1 ? $dirs[0] : $extractPath;

            return [
                'success' => true,
                'path' => $contentPath,
                'error' => null
            ];

        } catch (Exception $e) {
            error_log("[Updater] Download error: " . $e->getMessage());
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

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Pinakes-Updater/1.0',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Create backup before update
     * @return array{success: bool, path: string|null, error: string|null}
     */
    public function createBackup(): array
    {
        try {
            $timestamp = date('Y-m-d_His');
            $backupDir = $this->backupPath . '/update_' . $timestamp;

            if (!mkdir($backupDir, 0755, true)) {
                throw new Exception(__('Impossibile creare directory di backup'));
            }

            // Backup database
            $dbBackupResult = $this->backupDatabase($backupDir . '/database.sql');
            if (!$dbBackupResult['success']) {
                throw new Exception($dbBackupResult['error']);
            }

            // Log the backup
            $this->logUpdateStart($this->getCurrentVersion(), 'backup', $backupDir);

            return [
                'success' => true,
                'path' => $backupDir,
                'error' => null
            ];

        } catch (Exception $e) {
            error_log("[Updater] Backup error: " . $e->getMessage());
            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backup database to file
     * @return array{success: bool, error: string|null}
     */
    private function backupDatabase(string $filepath): array
    {
        try {
            $tables = [];
            $result = $this->db->query("SHOW TABLES");

            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }

            $sql = "-- Pinakes Database Backup\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Version: " . $this->getCurrentVersion() . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                // Get create table statement
                $createResult = $this->db->query("SHOW CREATE TABLE `{$table}`");
                $createRow = $createResult->fetch_row();
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $createRow[1] . ";\n\n";

                // Get data
                $dataResult = $this->db->query("SELECT * FROM `{$table}`");

                while ($row = $dataResult->fetch_assoc()) {
                    $values = array_map(function ($value) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return "'" . $this->db->real_escape_string($value) . "'";
                    }, $row);

                    $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
                }

                $sql .= "\n";
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            if (file_put_contents($filepath, $sql) === false) {
                throw new Exception(__('Impossibile scrivere file di backup'));
            }

            return ['success' => true, 'error' => null];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Install update from extracted path
     * @return array{success: bool, error: string|null}
     */
    public function installUpdate(string $sourcePath, string $targetVersion): array
    {
        try {
            $currentVersion = $this->getCurrentVersion();

            // Verify source exists
            if (!is_dir($sourcePath)) {
                throw new Exception(__('Directory sorgente non trovata'));
            }

            // Log update start
            $logId = $this->logUpdateStart($currentVersion, $targetVersion, null);

            // Copy files, preserving protected paths
            $this->copyDirectory($sourcePath, $this->rootPath);

            // Run database migrations
            $migrationResult = $this->runMigrations($currentVersion, $targetVersion);

            if (!$migrationResult['success']) {
                throw new Exception($migrationResult['error']);
            }

            // Mark update as complete
            $this->logUpdateComplete($logId, true);

            // Cleanup temp files
            $this->cleanup();

            return [
                'success' => true,
                'error' => null
            ];

        } catch (Exception $e) {
            error_log("[Updater] Install error: " . $e->getMessage());

            if (isset($logId)) {
                $this->logUpdateComplete($logId, false, $e->getMessage());
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
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
            $targetPath = $dest . '/' . $relativePath;

            // Check if path should be skipped
            foreach ($this->skipPaths as $skipPath) {
                if (strpos($relativePath, $skipPath) === 0) {
                    continue 2;
                }
            }

            // Check if path should be preserved (don't overwrite)
            foreach ($this->preservePaths as $preservePath) {
                if (strpos($relativePath, $preservePath) === 0 && file_exists($targetPath)) {
                    continue 2;
                }
            }

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                // Ensure parent directory exists
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
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

        try {
            $migrationsPath = $this->rootPath . '/installer/database/migrations';

            if (!is_dir($migrationsPath)) {
                return ['success' => true, 'executed' => [], 'error' => null];
            }

            // Get all migration files
            $files = glob($migrationsPath . '/migrate_*.sql');
            sort($files);

            foreach ($files as $file) {
                $filename = basename($file);

                // Extract version from filename (migrate_0.3.0.sql -> 0.3.0)
                if (preg_match('/migrate_(.+)\.sql$/', $filename, $matches)) {
                    $migrationVersion = $matches[1];

                    // Only run migrations that are newer than current and <= target
                    if (version_compare($migrationVersion, $fromVersion, '>') &&
                        version_compare($migrationVersion, $toVersion, '<=')) {

                        // Check if already executed
                        if ($this->isMigrationExecuted($migrationVersion)) {
                            continue;
                        }

                        // Execute migration
                        $sql = file_get_contents($file);

                        if ($sql !== false && trim($sql) !== '') {
                            // Split into individual statements
                            $statements = array_filter(
                                array_map('trim', explode(';', $sql)),
                                fn($s) => !empty($s) && !preg_match('/^--/', $s)
                            );

                            foreach ($statements as $statement) {
                                if (!empty(trim($statement))) {
                                    $result = $this->db->query($statement);
                                    if ($result === false) {
                                        throw new Exception(
                                            sprintf(__('Errore SQL durante migrazione %s: %s'), $filename, $this->db->error)
                                        );
                                    }
                                }
                            }
                        }

                        // Record migration
                        $this->recordMigration($migrationVersion, $filename);
                        $executed[] = $filename;
                    }
                }
            }

            return [
                'success' => true,
                'executed' => $executed,
                'error' => null
            ];

        } catch (Exception $e) {
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
        // If migrations table doesn't exist yet, no migrations have been executed
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
        // Ensure migrations table exists
        if (!$this->migrationsTableExists()) {
            $this->createMigrationsTable();
        }

        // Get current batch number
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
        $userId = (isset($_SESSION) && isset($_SESSION['user']['id']))
            ? (int) $_SESSION['user']['id']
            : null;

        $stmt = $this->db->prepare("
            INSERT INTO update_logs (from_version, to_version, status, backup_path, executed_by)
            VALUES (?, ?, 'started', ?, ?)
        ");
        $stmt->bind_param('sssi', $fromVersion, $toVersion, $backupPath, $userId);
        $stmt->execute();
        $id = $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Log update completion
     */
    private function logUpdateComplete(int $logId, bool $success, ?string $error = null): void
    {
        $status = $success ? 'completed' : 'failed';

        $stmt = $this->db->prepare("
            UPDATE update_logs
            SET status = ?, error_message = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $status, $error, $logId);
        $stmt->execute();
        $stmt->close();
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
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
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
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Check system requirements for update
     * @return array{met: bool, requirements: array<array>}
     */
    public function checkRequirements(): array
    {
        $requirements = [];
        $allMet = true;

        // PHP version
        $phpVersion = PHP_VERSION;
        $phpMet = version_compare($phpVersion, '8.1.0', '>=');
        $requirements[] = [
            'name' => 'PHP',
            'required' => '8.1+',
            'current' => $phpVersion,
            'met' => $phpMet
        ];
        if (!$phpMet) $allMet = false;

        // ZipArchive extension
        $zipMet = class_exists('ZipArchive');
        $requirements[] = [
            'name' => 'ZipArchive',
            'required' => __('Richiesto'),
            'current' => $zipMet ? __('Installato') : __('Non installato'),
            'met' => $zipMet
        ];
        if (!$zipMet) $allMet = false;

        // Write permissions
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

        // Disk space (need at least 100MB free)
        $freeSpace = disk_free_space($this->rootPath);
        $minSpace = 100 * 1024 * 1024; // 100MB
        $spaceMet = $freeSpace >= $minSpace;
        $requirements[] = [
            'name' => __('Spazio libero'),
            'required' => '100MB',
            'current' => $this->formatBytes($freeSpace),
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
        $backupResult = ['path' => null, 'success' => false, 'error' => null];

        try {
            // Step 1: Create backup
            $backupResult = $this->createBackup();
            if (!$backupResult['success']) {
                throw new Exception(__('Backup fallito') . ': ' . $backupResult['error']);
            }

            // Step 2: Download update
            $downloadResult = $this->downloadUpdate($targetVersion);
            if (!$downloadResult['success']) {
                throw new Exception(__('Download fallito') . ': ' . $downloadResult['error']);
            }

            // Step 3: Install update
            $installResult = $this->installUpdate($downloadResult['path'], $targetVersion);
            if (!$installResult['success']) {
                throw new Exception(__('Installazione fallita') . ': ' . $installResult['error']);
            }

            return [
                'success' => true,
                'error' => null,
                'backup_path' => $backupResult['path']
            ];

        } catch (Exception $e) {
            error_log("[Updater] Update failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_path' => $backupResult['path'] ?? null
            ];
        }
    }
}
