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
        'storage/logs',
        'public/uploads',
        'public/.htaccess',
        'public/robots.txt',
        'public/favicon.ico',
        'public/sitemap.xml',
        'CLAUDE.md',
    ];

    /**
     * Directories to skip completely during update.
     * NOTE: 'vendor' is NOT in this list - release packages MUST include
     * a production-ready vendor folder with all Composer dependencies.
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

        $content = file_get_contents($versionFile);
        if ($content === false) {
            error_log("[Updater] Impossibile leggere version.json");
            return '0.0.0';
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['version'])) {
            error_log("[Updater] version.json non valido o corrotto");
            return '0.0.0';
        }

        return $data['version'];
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
        $logId = null;

        try {
            $timestamp = date('Y-m-d_His');
            $backupDir = $this->backupPath . '/update_' . $timestamp;

            if (!mkdir($backupDir, 0755, true)) {
                throw new Exception(__('Impossibile creare directory di backup'));
            }

            // Log the backup start
            $logId = $this->logUpdateStart($this->getCurrentVersion(), 'backup', $backupDir);

            // Backup database
            $dbBackupResult = $this->backupDatabase($backupDir . '/database.sql');
            if (!$dbBackupResult['success']) {
                throw new Exception($dbBackupResult['error']);
            }

            // Mark backup as complete
            $this->logUpdateComplete($logId, true);

            return [
                'success' => true,
                'path' => $backupDir,
                'error' => null
            ];

        } catch (Exception $e) {
            error_log("[Updater] Backup error: " . $e->getMessage());

            // Mark backup as failed if log was started
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

            // Extract date from directory name (update_2025-12-05_143000)
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

        // Sort by created_at descending (newest first)
        usort($backups, fn($a, $b) => $b['created_at'] - $a['created_at']);

        return $backups;
    }

    /**
     * Delete a backup
     * @return array{success: bool, error: string|null}
     */
    public function deleteBackup(string $backupName): array
    {
        // Validate backup name to prevent directory traversal
        if (preg_match('/[\/\\\\]|\.\./', $backupName)) {
            return ['success' => false, 'error' => __('Nome backup non valido')];
        }

        $backupPath = $this->backupPath . '/' . $backupName;

        // Verify it exists and is within backup directory
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
        // Validate backup name
        if (preg_match('/[\/\\\\]|\.\./', $backupName)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => __('Nome backup non valido')];
        }

        $backupPath = $this->backupPath . '/' . $backupName;
        $dbFile = $backupPath . '/database.sql';

        if (!file_exists($dbFile)) {
            return ['success' => false, 'path' => null, 'filename' => null, 'error' => __('File backup non trovato')];
        }

        // Verify path is within backup directory
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
     * Backup database to file
     * @return array{success: bool, error: string|null}
     */
    private function backupDatabase(string $filepath): array
    {
        try {
            $tables = [];
            $result = $this->db->query("SHOW TABLES");
            if ($result === false) {
                throw new Exception(__('Errore nel recupero delle tabelle') . ': ' . $this->db->error);
            }

            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }
            $result->free();

            $sql = "-- Pinakes Database Backup\n";
            $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Version: " . $this->getCurrentVersion() . "\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                // Get create table statement
                $createResult = $this->db->query("SHOW CREATE TABLE `{$table}`");
                if ($createResult === false) {
                    throw new Exception(sprintf(__('Errore nel recupero struttura tabella %s'), $table) . ': ' . $this->db->error);
                }
                $createRow = $createResult->fetch_row();
                $createResult->free();
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $createRow[1] . ";\n\n";

                // Get data
                $dataResult = $this->db->query("SELECT * FROM `{$table}`");
                if ($dataResult === false) {
                    throw new Exception(sprintf(__('Errore nel recupero dati tabella %s'), $table) . ': ' . $this->db->error);
                }

                while ($row = $dataResult->fetch_assoc()) {
                    $values = array_map(function ($value) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return "'" . $this->db->real_escape_string($value) . "'";
                    }, $row);

                    $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
                }
                $dataResult->free();

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
        $appBackupPath = null;
        $logId = null;

        try {
            $currentVersion = $this->getCurrentVersion();

            // Verify source exists
            if (!is_dir($sourcePath)) {
                throw new Exception(__('Directory sorgente non trovata'));
            }

            // Verify it's a valid Pinakes package
            $requiredPaths = ['version.json', 'app', 'public', 'installer'];
            foreach ($requiredPaths as $required) {
                if (!file_exists($sourcePath . '/' . $required)) {
                    throw new Exception(sprintf(__('Pacchetto di aggiornamento non valido: manca %s'), $required));
                }
            }

            // Log update start
            $logId = $this->logUpdateStart($currentVersion, $targetVersion, null);

            // Backup current app files for atomic rollback
            $appBackupPath = $this->backupAppFiles();

            // Copy files, preserving protected paths
            $this->copyDirectory($sourcePath, $this->rootPath);

            // Clean up orphan files (files in old version but not in new)
            $this->cleanupOrphanFiles($sourcePath);

            // Run database migrations
            $migrationResult = $this->runMigrations($currentVersion, $targetVersion);

            if (!$migrationResult['success']) {
                throw new Exception($migrationResult['error']);
            }

            // Fix file permissions
            $this->fixPermissions();

            // Mark update as complete
            $this->logUpdateComplete($logId, true);

            // Cleanup temp files and app backup (success, no rollback needed)
            $this->cleanup();
            if ($appBackupPath !== null && is_dir($appBackupPath)) {
                $this->deleteDirectory($appBackupPath);
            }

            return [
                'success' => true,
                'error' => null
            ];

        } catch (Exception $e) {
            error_log("[Updater] Install error: " . $e->getMessage());

            // Attempt to restore from backup if available
            if ($appBackupPath !== null && is_dir($appBackupPath)) {
                try {
                    error_log("[Updater] Attempting rollback from: " . $appBackupPath);
                    $this->restoreAppFiles($appBackupPath);
                    error_log("[Updater] Rollback completed successfully");
                } catch (Exception $rollbackError) {
                    error_log("[Updater] Rollback failed: " . $rollbackError->getMessage());
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
     * @return string Path to backup directory
     */
    private function backupAppFiles(): string
    {
        $timestamp = date('Y-m-d_His');
        $backupPath = sys_get_temp_dir() . '/pinakes_app_backup_' . $timestamp;

        if (!mkdir($backupPath, 0755, true)) {
            throw new Exception(__('Impossibile creare directory di backup applicazione'));
        }

        // Directories to backup for rollback
        $dirsToBackup = ['app', 'config', 'locale', 'public/assets', 'installer'];

        foreach ($dirsToBackup as $dir) {
            $sourcePath = $this->rootPath . '/' . $dir;
            $destPath = $backupPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        // Also backup version.json
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
        $dirsToRestore = ['app', 'config', 'locale', 'public/assets', 'installer'];

        foreach ($dirsToRestore as $dir) {
            $sourcePath = $backupPath . '/' . $dir;
            $destPath = $this->rootPath . '/' . $dir;

            if (is_dir($sourcePath)) {
                // Delete current and restore from backup
                if (is_dir($destPath)) {
                    $this->deleteDirectory($destPath);
                }
                $this->copyDirectoryRecursive($sourcePath, $destPath);
            }
        }

        // Restore version.json
        $backupVersion = $backupPath . '/version.json';
        if (file_exists($backupVersion)) {
            copy($backupVersion, $this->rootPath . '/version.json');
        }
    }

    /**
     * Copy directory recursively (simple copy without preserve/skip logic)
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
     * Clean up orphan files that exist in old version but not in new
     */
    private function cleanupOrphanFiles(string $newSourcePath): void
    {
        // Directories to check for orphan files
        $dirsToCheck = ['app', 'config', 'locale', 'installer'];

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

            // Skip preserved paths
            foreach ($this->preservePaths as $preservePath) {
                if (strpos($fullRelativePath, $preservePath) === 0) {
                    continue 2;
                }
            }

            // If file/dir doesn't exist in new version, remove it
            if (!file_exists($newPath)) {
                if ($item->isDir()) {
                    // Only remove empty directories
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                    error_log("[Updater] Removed orphan file: " . $fullRelativePath);
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

            // Path traversal protection - reject paths with .. or null bytes
            if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
                throw new Exception(sprintf(__('Percorso non valido nel pacchetto: %s'), $relativePath));
            }

            // Skip symlinks for security
            if ($item->isLink()) {
                continue;
            }

            $targetPath = $dest . '/' . $relativePath;

            // Verify target path is still within destination (double-check)
            $realDest = realpath($dest);
            $parentTarget = realpath(dirname($targetPath));
            if ($parentTarget !== false && $realDest !== false && strpos($parentTarget, $realDest) !== 0) {
                throw new Exception(sprintf(__('Percorso non valido nel pacchetto: %s'), $relativePath));
            }

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
                    if (!mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                        throw new Exception(sprintf(__('Impossibile creare directory: %s'), $relativePath));
                    }
                }
            } else {
                // Ensure parent directory exists
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
                            // NOTE: Migration files must contain simple SQL statements only.
                            // Not supported: stored procedures, triggers, strings containing ';',
                            // or multi-line comments containing ';'. For complex migrations,
                            // use multiple simple statements.
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
        if ($stmt === false) {
            throw new Exception(__('Errore preparazione log aggiornamento') . ': ' . $this->db->error);
        }
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
        if ($stmt === false) {
            throw new Exception(__('Errore preparazione completamento log') . ': ' . $this->db->error);
        }
        $stmt->bind_param('ssi', $status, $error, $logId);
        if (!$stmt->execute()) {
            $stmtError = $stmt->error;
            $stmt->close();
            throw new Exception(__('Errore aggiornamento log') . ': ' . $stmtError);
        }
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
        if ($stmt === false) {
            error_log("[Updater] Errore preparazione query storico: " . $this->db->error);
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
     * Cleanup temporary files and reset caches
     */
    public function cleanup(): void
    {
        if (is_dir($this->tempPath)) {
            $this->deleteDirectory($this->tempPath);
        }

        // Disable maintenance mode
        $this->disableMaintenanceMode();

        // Reset OpCache to prevent serving stale compiled PHP
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Enable maintenance mode to prevent user access during update
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
     * Disable maintenance mode after update
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

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Fix file and directory permissions after update
     */
    private function fixPermissions(): void
    {
        // Directories that need to be writable
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
                // Set directory to 755
                chmod($fullPath, 0755);

                // Recursively fix permissions for contents
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

        // Ensure .env is not world-readable
        $envFile = $this->rootPath . '/.env';
        if (file_exists($envFile)) {
            chmod($envFile, 0600);
        }

        // Ensure public/index.php is executable for some servers
        $indexFile = $this->rootPath . '/public/index.php';
        if (file_exists($indexFile)) {
            chmod($indexFile, 0644);
        }

        // Fix app directory permissions (read-only for most)
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
        if ($freeSpace === false) {
            $freeSpace = 0; // Assume no space if check fails
        }
        $minSpace = 100 * 1024 * 1024; // 100MB
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
        // Prevent timeout on slow connections or large updates
        set_time_limit(0);

        // Enable maintenance mode to prevent user access during update
        $this->enableMaintenanceMode();

        $backupResult = ['path' => null, 'success' => false, 'error' => null];
        $result = null;

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

            $result = [
                'success' => true,
                'error' => null,
                'backup_path' => $backupResult['path']
            ];

        } catch (Exception $e) {
            error_log("[Updater] Update failed: " . $e->getMessage());
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_path' => $backupResult['path'] ?? null
            ];
        } finally {
            // Always cleanup temporary files, regardless of success or failure
            $this->cleanup();
        }

        return $result;
    }
}
