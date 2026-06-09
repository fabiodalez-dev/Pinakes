<?php

declare(strict_types=1);

namespace App\Support;

use mysqli;
use ZipArchive;

/**
 * Complete backup + restore: bundles the database dump together with the
 * uploaded files into a single ZIP, and restores both. Used by the admin
 * "Backup" UI and by the updater's pre-update hook.
 *
 * A backup ZIP (storage/backups/backup_<ts>.zip) contains:
 *   - database.sql   : full DROP/CREATE/INSERT dump (same format as the updater)
 *   - manifest.json  : {app, version, created_at, scope, tables, files, database_sha256}
 *   - files/public/uploads/...           (scope=full)
 *   - files/storage/uploads/plugins/...  (scope=full)
 *
 * .env is NEVER included and NEVER written by a restore.
 */
class BackupManager
{
    private mysqli $db;
    private string $rootPath;
    private string $backupPath;

    /** Upload trees included in a full backup, relative to the project root. */
    private const FILE_ROOTS = [
        'public/uploads',
        'storage/uploads/plugins',
    ];

    /** Hard cap for an uploaded restore archive (2 GB). */
    private const MAX_UPLOAD_BYTES = 2 * 1024 * 1024 * 1024;

    public function __construct(mysqli $db, string $rootPath)
    {
        $this->db = $db;
        $this->rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $this->backupPath = $this->rootPath . '/storage/backups';
    }

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

    /**
     * Create a backup ZIP.
     *
     * @param string $scope 'full' (DB + files) or 'db' (database only)
     * @return array{success: bool, name: string|null, path: string|null, size: int, error: string|null}
     */
    public function createBackup(string $scope = 'full'): array
    {
        $scope = $scope === 'db' ? 'db' : 'full';
        $sqlTmp = null;

        try {
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException(__('Estensione ZipArchive non disponibile'));
            }
            if (!is_dir($this->backupPath) && !@mkdir($this->backupPath, 0755, true) && !is_dir($this->backupPath)) {
                throw new \RuntimeException(__('Impossibile creare directory di backup'));
            }

            $timestamp = date('Y-m-d_His');
            $name = 'backup_' . $timestamp . '.zip';
            $zipPath = $this->backupPath . '/' . $name;

            // 1. Dump the database to a temp file.
            $sqlTmp = (string) tempnam(sys_get_temp_dir(), 'pk_dump_');
            $tableCount = $this->dumpDatabaseTo($sqlTmp);

            // 2. Open the ZIP.
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException(__('Impossibile creare il file di backup'));
            }

            $zip->addFile($sqlTmp, 'database.sql');

            // 3. Add the uploaded files (full scope only).
            $fileCount = 0;
            if ($scope === 'full') {
                foreach (self::FILE_ROOTS as $rel) {
                    $src = $this->rootPath . '/' . $rel;
                    if (is_dir($src)) {
                        $fileCount += $this->addDirToZip($zip, $src, 'files/' . $rel);
                    }
                }
            }

            // 4. Manifest.
            $manifest = [
                'app' => 'Pinakes',
                'version' => $this->getCurrentVersion(),
                'created_at' => date('c'),
                'scope' => $scope,
                'tables' => $tableCount,
                'files' => $fileCount,
                'database_sha256' => hash_file('sha256', $sqlTmp) ?: '',
            ];
            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (!$zip->close()) {
                throw new \RuntimeException(__('Errore nella scrittura del backup'));
            }

            // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam()-generated temp dump path, not user input
            @unlink($sqlTmp);
            $sqlTmp = null;

            $size = is_file($zipPath) ? (int) filesize($zipPath) : 0;

            return ['success' => true, 'name' => $name, 'path' => $zipPath, 'size' => $size, 'error' => null];
        } catch (\Throwable $e) {
            if ($sqlTmp !== null && is_file($sqlTmp)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam()-generated temp path, not user input
                @unlink($sqlTmp);
            }
            return ['success' => false, 'name' => null, 'path' => null, 'size' => 0, 'error' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // List / delete / download
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array{name: string, path: string, size: int, date: string, contents: string, created_at: int}>
     */
    public function listBackups(): array
    {
        $backups = [];
        if (!is_dir($this->backupPath)) {
            return $backups;
        }

        // New ZIP backups.
        foreach (glob($this->backupPath . '/backup_*.zip') ?: [] as $file) {
            $name = basename($file);
            $manifest = $this->readManifestFromZip($file);
            $backups[] = [
                'name' => $name,
                'path' => $file,
                'size' => (int) filesize($file),
                'date' => str_replace(['backup_', '_'], ['', ' '], pathinfo($name, PATHINFO_FILENAME)),
                'contents' => (string) ($manifest['scope'] ?? 'full'),
                'created_at' => (int) filemtime($file),
            ];
        }

        // Legacy directory backups (DB only).
        foreach (glob($this->backupPath . '/update_*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $dbFile = $dir . '/database.sql';
            $backups[] = [
                'name' => $name,
                'path' => $dir,
                'size' => is_file($dbFile) ? (int) filesize($dbFile) : 0,
                'date' => str_replace(['update_', '_'], ['', ' '], $name),
                'contents' => 'db',
                'created_at' => (int) filemtime($dir),
            ];
        }

        usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        return $backups;
    }

    /**
     * @return array{success: bool, error: string|null}
     */
    public function deleteBackup(string $name): array
    {
        $target = $this->resolveBackup($name);
        if ($target === null) {
            return ['success' => false, 'error' => __('Backup non trovato')];
        }
        try {
            if (is_dir($target)) {
                $this->deleteDirectory($target);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- $target validated by resolveBackup() (no traversal, realpath under storage/backups)
                @unlink($target);
            }
            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, path: string|null, filename: string|null, mime: string|null, error: string|null}
     */
    public function getDownloadPath(string $name): array
    {
        $target = $this->resolveBackup($name);
        if ($target === null) {
            return ['success' => false, 'path' => null, 'filename' => null, 'mime' => null, 'error' => __('File backup non trovato')];
        }
        if (is_dir($target)) {
            // Legacy backup: serve the raw database.sql.
            $sql = $target . '/database.sql';
            if (!is_file($sql)) {
                return ['success' => false, 'path' => null, 'filename' => null, 'mime' => null, 'error' => __('File backup non trovato')];
            }
            return ['success' => true, 'path' => $sql, 'filename' => $name . '.sql', 'mime' => 'application/sql', 'error' => null];
        }
        return ['success' => true, 'path' => $target, 'filename' => $name, 'mime' => 'application/zip', 'error' => null];
    }

    // ---------------------------------------------------------------------
    // Restore
    // ---------------------------------------------------------------------

    /**
     * Restore a stored backup (DB + files). Creates a safety backup first.
     *
     * @return array{success: bool, safety_backup: string|null, error: string|null}
     */
    public function restoreFromBackup(string $name): array
    {
        $target = $this->resolveBackup($name);
        if ($target === null || is_dir($target)) {
            // Legacy DB-only dirs are not restorable through this path.
            return ['success' => false, 'safety_backup' => null, 'error' => __('Backup non valido per il ripristino')];
        }
        return $this->restoreZip($target);
    }

    /**
     * Validate and restore an already-moved uploaded backup ZIP at $tmpPath.
     * The caller (controller) is responsible for moving the HTTP upload to
     * $tmpPath (e.g. Slim's UploadedFile::moveTo) before calling this.
     *
     * @return array{success: bool, safety_backup: string|null, error: string|null}
     */
    public function restoreFromUploadedZip(string $tmpPath, int $size): array
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['success' => false, 'safety_backup' => null, 'error' => __('Nessun file caricato')];
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            return ['success' => false, 'safety_backup' => null, 'error' => __('File di backup troppo grande')];
        }

        // Must be a readable ZIP carrying a manifest + database.sql.
        $probe = new ZipArchive();
        if ($probe->open($tmpPath) !== true || $probe->locateName('manifest.json') === false || $probe->locateName('database.sql') === false) {
            if ($probe->filename !== '') {
                $probe->close();
            }
            return ['success' => false, 'safety_backup' => null, 'error' => __('Archivio di backup non valido')];
        }
        $probe->close();

        // Move the upload into the backups dir so restoreZip works on a stable path.
        if (!is_dir($this->backupPath) && !@mkdir($this->backupPath, 0755, true) && !is_dir($this->backupPath)) {
            return ['success' => false, 'safety_backup' => null, 'error' => __('Impossibile creare directory di backup')];
        }
        $dest = $this->backupPath . '/uploaded_' . date('Y-m-d_His') . '.zip';
        if (!@rename($tmpPath, $dest) && !@copy($tmpPath, $dest)) {
            return ['success' => false, 'safety_backup' => null, 'error' => __('Impossibile salvare il file caricato')];
        }
        return $this->restoreZip($dest);
    }

    /**
     * @return array{success: bool, safety_backup: string|null, error: string|null}
     */
    private function restoreZip(string $zipPath): array
    {
        $sqlTmp = null;
        $safetyName = null;
        try {
            // 1. Safety backup of the current state (always full).
            $safety = $this->createBackup('full');
            if (!$safety['success']) {
                throw new \RuntimeException(__('Impossibile creare il backup di sicurezza pre-ripristino') . ': ' . (string) $safety['error']);
            }
            $safetyName = $safety['name'];

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException(__('Impossibile aprire l\'archivio di backup'));
            }

            // 2. Restore the database from database.sql.
            $sql = $zip->getFromName('database.sql');
            if ($sql === false) {
                $zip->close();
                throw new \RuntimeException(__('Database mancante nel backup'));
            }
            $sqlTmp = (string) tempnam(sys_get_temp_dir(), 'pk_restore_');
            file_put_contents($sqlTmp, $sql);
            $this->importDatabase($sqlTmp);
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam()-generated temp dump path, not user input
            @unlink($sqlTmp);
            $sqlTmp = null;

            // 3. Restore the uploaded files (zip-slip guarded).
            $this->extractFilesSafely($zip);
            $zip->close();

            return ['success' => true, 'safety_backup' => $safetyName, 'error' => null];
        } catch (\Throwable $e) {
            if ($sqlTmp !== null && is_file($sqlTmp)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam()-generated temp path, not user input
                @unlink($sqlTmp);
            }
            return ['success' => false, 'safety_backup' => $safetyName, 'error' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Dump every table to $filepath (DROP/CREATE/INSERT). Returns the table count.
     */
    private function dumpDatabaseTo(string $filepath): int
    {
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new \RuntimeException(__('Impossibile aprire file di backup per scrittura'));
        }
        try {
            $tables = [];
            $result = $this->db->query('SHOW TABLES');
            if ($result === false) {
                throw new \RuntimeException(__('Errore nel recupero delle tabelle') . ': ' . $this->db->error);
            }
            while ($row = $result->fetch_row()) {
                $tables[] = (string) $row[0];
            }
            $result->free();

            fwrite($handle, "-- Pinakes Database Backup\n");
            fwrite($handle, '-- Generated: ' . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, '-- Version: ' . $this->getCurrentVersion() . "\n");
            fwrite($handle, '-- Tables: ' . count($tables) . "\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                    continue;
                }
                $createResult = $this->db->query("SHOW CREATE TABLE `{$table}`");
                if ($createResult === false) {
                    throw new \RuntimeException(sprintf(__('Errore nel recupero struttura tabella %s'), $table) . ': ' . $this->db->error);
                }
                $createRow = $createResult->fetch_row();
                $createResult->free();

                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, ((string) ($createRow[1] ?? '')) . ";\n\n");

                $this->db->real_query("SELECT * FROM `{$table}`");
                $dataResult = $this->db->use_result();
                if ($dataResult === false) {
                    throw new \RuntimeException(sprintf(__('Errore nel recupero dati tabella %s'), $table) . ': ' . $this->db->error);
                }
                while ($row = $dataResult->fetch_assoc()) {
                    $values = array_map(function ($value): string {
                        return $value === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $value) . "'";
                    }, $row);
                    fwrite($handle, "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n");
                }
                $dataResult->free();
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            return count($tables);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Import a database.sql dump via multi_query. Runs on a DEDICATED
     * connection: the request connection may have just produced the unbuffered
     * pre-restore dump, after which a multi_query on the same handle can report
     * success yet silently not persist. A fresh connection avoids that.
     */
    private function importDatabase(string $sqlPath): void
    {
        $sql = file_get_contents($sqlPath);
        if ($sql === false || $sql === '') {
            throw new \RuntimeException(__('Dump del database vuoto o illeggibile'));
        }

        $conn = $this->openImportConnection();
        try {
            $conn->autocommit(true);
            if (!$conn->multi_query($sql)) {
                throw new \RuntimeException(__('Errore durante il ripristino del database') . ': ' . $conn->error);
            }
            do {
                $res = $conn->store_result();
                if ($res instanceof \mysqli_result) {
                    $res->free();
                }
                if (!$conn->more_results()) {
                    break;
                }
            } while ($conn->next_result());

            if ($conn->errno !== 0) {
                throw new \RuntimeException(__('Errore durante il ripristino del database') . ': ' . $conn->error);
            }
            $conn->commit();
        } finally {
            $conn->close();
        }
    }

    /**
     * Open a fresh mysqli connection to the same database (from env config).
     */
    private function openImportConnection(): mysqli
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
        $user = (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: '');
        $pass = (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '');
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '');
        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $socket = (string) ($_ENV['DB_SOCKET'] ?? getenv('DB_SOCKET') ?: '');

        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = $socket !== ''
            ? @mysqli_connect(null, $user, $pass, $name, 0, $socket)
            : @mysqli_connect($host, $user, $pass, $name, $port);
        if (!$conn instanceof mysqli) {
            throw new \RuntimeException(__('Errore durante il ripristino del database') . ': ' . __('connessione al database non riuscita'));
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    }

    /**
     * Add a directory tree to the ZIP under $zipPrefix. Returns the file count.
     * Skips symlinks and path-traversal entries.
     */
    private function addDirToZip(ZipArchive $zip, string $source, string $zipPrefix): int
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        $zipPrefix = rtrim($zipPrefix, '/');
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->isLink()) {
                continue;
            }
            $path = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(str_replace($source, '', $path), '/');
            if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
                continue;
            }
            $entry = $zipPrefix . '/' . $relative;
            if ($item->isDir()) {
                $zip->addEmptyDir($entry);
            } elseif ($item->isFile()) {
                $zip->addFile($path, $entry);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Extract files/* entries back over the upload roots, rejecting zip-slip.
     */
    private function extractFilesSafely(ZipArchive $zip): void
    {
        $allowed = [];
        foreach (self::FILE_ROOTS as $rel) {
            $base = $this->rootPath . '/' . $rel;
            if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
                continue;
            }
            $real = realpath($base);
            if ($real !== false) {
                $allowed[] = str_replace('\\', '/', $real);
            }
        }
        if ($allowed === []) {
            return;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || !str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen('files/')); // e.g. public/uploads/copertine/x.jpg
            if (str_contains($relative, '..') || str_contains($relative, "\0") || str_starts_with($relative, '/')) {
                continue;
            }
            $target = $this->rootPath . '/' . $relative;
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                continue;
            }
            $realParent = realpath($parent);
            if ($realParent === false) {
                continue;
            }
            $realParent = str_replace('\\', '/', $realParent);
            // The resolved parent must live inside one of the allowed upload roots.
            $ok = false;
            foreach ($allowed as $root) {
                if ($realParent === $root || str_starts_with($realParent, $root . '/')) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($target, 'w');
            if ($out === false) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            @chmod($target, 0644);
        }
    }

    /**
     * Resolve a backup name to an existing path under storage/backups, or null.
     * Rejects traversal and prefix-collision.
     */
    private function resolveBackup(string $name): ?string
    {
        if ($name === '' || preg_match('/[\/\\\\]|\.\./', $name)) {
            return null;
        }
        $path = $this->backupPath . '/' . $name;
        $real = realpath($path);
        $realBase = realpath($this->backupPath);
        if ($real === false || $realBase === false) {
            return null;
        }
        $real = str_replace('\\', '/', $real);
        $realBase = str_replace('\\', '/', $realBase);
        if ($real !== $realBase && !str_starts_with($real, $realBase . '/')) {
            return null;
        }
        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifestFromZip(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }
        $raw = $zip->getFromName('manifest.json');
        $zip->close();
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = @scandir($dir);
        if ($files === false) {
            return;
        }
        foreach (array_diff($files, ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- recursive delete within an already-validated backup directory
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function getCurrentVersion(): string
    {
        $versionFile = $this->rootPath . '/version.json';
        if (is_file($versionFile)) {
            $data = json_decode((string) file_get_contents($versionFile), true);
            if (is_array($data) && isset($data['version'])) {
                return (string) $data['version'];
            }
        }
        return '0.0.0';
    }
}
