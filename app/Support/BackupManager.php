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

            // A random suffix avoids collisions when two backups land in the
            // same second (e.g. a manual backup + the pre-restore safety backup).
            $timestamp = date('Y-m-d_His');
            $name = 'backup_' . $timestamp . '_' . bin2hex(random_bytes(3)) . '.zip';
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
        // Same naming scheme as createBackup() so the uploaded archive is listed
        // and deletable like any other backup; the random suffix avoids the
        // same-second collision a plain timestamp would allow.
        $dest = $this->backupPath . '/backup_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
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
        $stagingDir = null;
        $safetyName = null;
        try {
            // 1. Safety backup of the current state (always full) — the rollback
            //    path, since MySQL DDL can't run inside a transaction.
            $safety = $this->createBackup('full');
            if (!$safety['success']) {
                throw new \RuntimeException(__('Impossibile creare il backup di sicurezza pre-ripristino') . ': ' . (string) $safety['error']);
            }
            $safetyName = $safety['name'];

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException(__('Impossibile aprire l\'archivio di backup'));
            }

            try {
                // Validate the manifest before touching anything.
                $manifestRaw = $zip->getFromName('manifest.json');
                $manifest = $manifestRaw !== false ? json_decode($manifestRaw, true) : null;
                if (!is_array($manifest) || ($manifest['app'] ?? '') !== 'Pinakes') {
                    throw new \RuntimeException(__('Archivio di backup non valido'));
                }
                $expectedSha = is_string($manifest['database_sha256'] ?? null) ? $manifest['database_sha256'] : '';

                // 2. Stream database.sql to a temp file (never the whole dump in
                //    memory) so we can hash-verify it before importing.
                $sqlTmp = (string) tempnam(sys_get_temp_dir(), 'pk_restore_');
                $this->streamEntryToFile($zip, 'database.sql', $sqlTmp);

                // 3. Extract the uploaded files to STAGING first. This is the
                //    failure-prone step (decompression + I/O per file); doing it
                //    BEFORE the irreversible DB import shrinks the inconsistency
                //    window to the fast staging→live promotion at the end.
                $stagingDir = $this->makeStagingDir();
                $this->extractFilesSafely($zip, $stagingDir);
            } finally {
                $zip->close();
            }

            // 4. Import the DB (hash-verified inside). After this point the DB is
            //    the restored one; files are already safely staged.
            $this->importDatabase($sqlTmp, $expectedSha);
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam()-generated temp dump path, not user input
            @unlink($sqlTmp);
            $sqlTmp = null;

            // 5. Promote staged files over the live upload roots (fast moves).
            $this->promoteStagedFiles($stagingDir);
            $this->removeDir($stagingDir);
            $stagingDir = null;

            return ['success' => true, 'safety_backup' => $safetyName, 'error' => null];
        } catch (\Throwable $e) {
            if ($sqlTmp !== null && is_file($sqlTmp)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam()-generated temp path, not user input
                @unlink($sqlTmp);
            }
            if ($stagingDir !== null && is_dir($stagingDir)) {
                $this->removeDir($stagingDir);
            }
            return ['success' => false, 'safety_backup' => $safetyName, 'error' => $e->getMessage()];
        }
    }

    /**
     * Stream a single ZIP entry to a file without loading it into memory.
     */
    private function streamEntryToFile(ZipArchive $zip, string $entry, string $dest): void
    {
        $in = $zip->getStream($entry);
        if ($in === false) {
            throw new \RuntimeException(__('Database mancante nel backup'));
        }
        $out = fopen($dest, 'w');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException(__('Impossibile aprire file di backup per scrittura'));
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    private function makeStagingDir(): string
    {
        $dir = sys_get_temp_dir() . '/pk_stage_' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(__('Impossibile creare directory di backup'));
        }
        return $dir;
    }

    /**
     * Move every staged upload file over its live counterpart. Merge-overwrite:
     * files present in the backup replace live ones; live files absent from the
     * backup are left untouched.
     */
    private function promoteStagedFiles(string $stagingDir): void
    {
        foreach (self::FILE_ROOTS as $rel) {
            $stageBase = $stagingDir . '/' . $rel;
            if (!is_dir($stageBase)) {
                continue;
            }
            $liveBase = $this->rootPath . '/' . $rel;
            if (!is_dir($liveBase) && !@mkdir($liveBase, 0755, true) && !is_dir($liveBase)) {
                throw new \RuntimeException(__('Errore durante il ripristino dei file') . ': ' . $rel);
            }
            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($stageBase, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                $rl = $it->getSubPathName();
                $target = $liveBase . '/' . $rl;
                if ($item->isDir()) {
                    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                        throw new \RuntimeException(__('Errore durante il ripristino dei file') . ': ' . $rl);
                    }
                    continue;
                }
                if (is_link($target)) {
                    continue;
                }
                if (!@rename($item->getPathname(), $target) && !@copy($item->getPathname(), $target)) {
                    throw new \RuntimeException(__('Errore durante il ripristino dei file') . ': ' . $rl);
                }
                @chmod($target, 0644);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- staging temp dir under sys_get_temp_dir(), not user input
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
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

        // Consistent point-in-time snapshot (InnoDB): every table and trigger is
        // read from one transaction, so concurrent writes can't produce a dump
        // with inconsistent relations between loans, copies and users.
        $this->db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $snapshotOpen = true;

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

            // Triggers — emitted AFTER the tables (DROP TABLE drops their triggers)
            // so loan/expiry invariants survive a restore. DELIMITER markers let the
            // mysql CLI import recreate the multi-statement BEGIN..END body; the PHP
            // fallback parses the same markers.
            $this->dumpTriggers($handle);

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            $this->db->commit();
            $snapshotOpen = false;
            return count($tables);
        } finally {
            if ($snapshotOpen) {
                @$this->db->rollback();
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param resource $handle
     */
    private function dumpTriggers($handle): void
    {
        $res = $this->db->query('SHOW TRIGGERS');
        if (!$res instanceof \mysqli_result) {
            return;
        }
        $names = [];
        while ($row = $res->fetch_assoc()) {
            $names[] = (string) ($row['Trigger'] ?? '');
        }
        $res->free();
        if ($names === []) {
            return;
        }

        fwrite($handle, "-- PINAKES_TRIGGERS\n");
        foreach ($names as $trg) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $trg)) {
                continue;
            }
            $ct = $this->db->query("SHOW CREATE TRIGGER `{$trg}`");
            if (!$ct instanceof \mysqli_result) {
                continue;
            }
            $row = $ct->fetch_assoc();
            $ct->free();
            $stmt = (string) ($row['SQL Original Statement'] ?? '');
            if ($stmt === '') {
                continue;
            }
            // Strip the DEFINER clause so the trigger is recreated owned by the
            // user performing the restore. Keeping the original DEFINER would
            // require SUPER/SET_USER_ID when restoring onto a different DB user
            // (e.g. a server migration) and abort the whole import.
            $stmt = (string) preg_replace('/^CREATE\s+DEFINER\s*=\s*(?:`[^`]*`|\S+?)@(?:`[^`]*`|\S+?)\s+TRIGGER/i', 'CREATE TRIGGER', $stmt, 1);
            fwrite($handle, "DROP TRIGGER IF EXISTS `{$trg}`;\n");
            fwrite($handle, "DELIMITER \$\$\n");
            fwrite($handle, $stmt . "\$\$\n");
            fwrite($handle, "DELIMITER ;\n\n");
        }
    }

    /**
     * Import a database.sql dump. Verifies the dump's SHA-256 against the
     * manifest first (a corrupt dump must never be imported, since DDL +
     * multi-statement execution can leave the DB half-destroyed). Imports via
     * the mysql CLI (streams from disk — no whole-dump-in-memory — and honours
     * DELIMITER so triggers are recreated); falls back to a PHP importer when
     * exec() is unavailable.
     */
    private function importDatabase(string $sqlPath, string $expectedSha = ''): void
    {
        if (!is_file($sqlPath) || filesize($sqlPath) === 0) {
            throw new \RuntimeException(__('Dump del database vuoto o illeggibile'));
        }
        if ($expectedSha !== '') {
            $actual = hash_file('sha256', $sqlPath);
            if ($actual === false || !hash_equals($expectedSha, $actual)) {
                throw new \RuntimeException(__('Il backup è corrotto (checksum del database non valido)'));
            }
        }

        if (!$this->importViaCli($sqlPath)) {
            $this->importViaPhp($sqlPath);
        }
    }

    private function execEnabled(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    /**
     * Import via the mysql CLI (`mysql … < dump`) — streams from disk (no
     * whole-dump-in-memory) and honours DELIMITER so triggers are recreated.
     * Returns false (so the caller falls back to the PHP importer) when exec()
     * is disabled, the mysql binary can't be located, or the CLI run fails for
     * any reason; the PHP fallback then re-imports cleanly (DROP TABLE IF EXISTS
     * makes a retry idempotent).
     */
    private function importViaCli(string $sqlPath): bool
    {
        if (!$this->execEnabled()) {
            return false;
        }
        $bin = $this->locateMysqlBinary();
        if ($bin === null) {
            return false;
        }
        $cfg = $this->dbConfig();
        $cnf = (string) tempnam(sys_get_temp_dir(), 'pk_cnf_');
        $lines = "[client]\nuser=\"" . addslashes($cfg['user']) . "\"\npassword=\"" . addslashes($cfg['pass']) . "\"\n";
        if ($cfg['socket'] !== '') {
            $lines .= "socket=\"" . addslashes($cfg['socket']) . "\"\n";
        } else {
            $lines .= "host=\"" . addslashes($cfg['host']) . "\"\nport=" . $cfg['port'] . "\n";
        }
        file_put_contents($cnf, $lines);
        @chmod($cnf, 0600);

        $cmd = escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf)
            . ' --default-character-set=utf8mb4 ' . escapeshellarg($cfg['name'])
            . ' < ' . escapeshellarg($sqlPath) . ' 2>&1';
        $out = [];
        $code = 0;
        // nosemgrep: php.lang.security.exec-use.exec-use -- every interpolated value passes through escapeshellarg(); the .cnf holds the password so it never appears on the command line
        exec($cmd, $out, $code);
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam()-generated temp .cnf, not user input
        @unlink($cnf);

        return $code === 0;
    }

    /**
     * Resolve an absolute path to the mysql client. PHP-FPM often runs with a
     * minimal PATH that omits the binary's directory, so checking known install
     * locations is more reliable than relying on exec('mysql') resolving.
     */
    private function locateMysqlBinary(): ?string
    {
        $candidates = [];
        $out = [];
        $code = 0;
        // nosemgrep: php.lang.security.exec-use.exec-use -- constant command, no external input
        @exec('command -v mysql 2>/dev/null', $out, $code);
        if ($code === 0 && isset($out[0]) && $out[0] !== '') {
            $candidates[] = $out[0];
        }
        $candidates = array_merge($candidates, [
            '/opt/homebrew/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/bin/mysql',
            '/usr/local/mysql/bin/mysql',
            '/bin/mysql',
        ]);
        foreach ($candidates as $bin) {
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }
        return null;
    }

    /**
     * Fallback importer (no exec): table/data statements via multi_query on a
     * dedicated connection, then each trigger as a single query() (DELIMITER is
     * a CLI construct mysqli can't use, but a whole trigger body is one query).
     */
    private function importViaPhp(string $sqlPath): void
    {
        $sql = file_get_contents($sqlPath);
        if ($sql === false || $sql === '') {
            throw new \RuntimeException(__('Dump del database vuoto o illeggibile'));
        }
        $split = explode("-- PINAKES_TRIGGERS\n", $sql, 2);
        $main = $split[0];
        $triggersBlock = $split[1] ?? '';

        $conn = $this->openImportConnection();
        try {
            $conn->autocommit(true);
            if (!$conn->multi_query($main)) {
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

            foreach ($this->parseTriggerStatements($triggersBlock) as $stmt) {
                if (!$conn->query($stmt)) {
                    throw new \RuntimeException(__('Errore durante il ripristino del database') . ': ' . $conn->error);
                }
            }
            $conn->commit();
        } finally {
            $conn->close();
        }
    }

    /**
     * Parse the DELIMITER-delimited trigger block into individual statements
     * (DROP TRIGGER … / CREATE TRIGGER … END) runnable via mysqli::query().
     *
     * @return array<int, string>
     */
    private function parseTriggerStatements(string $block): array
    {
        if (trim($block) === '') {
            return [];
        }
        $statements = [];
        $lines = explode("\n", $block);
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);
            if ($trimmed === '' || stripos($trimmed, 'DELIMITER') === 0) {
                $i++;
                continue;
            }
            if (stripos($trimmed, 'DROP TRIGGER') === 0) {
                $statements[] = rtrim($trimmed, ';');
                $i++;
                continue;
            }
            // CREATE TRIGGER … accumulate until a line ending with the $$ marker.
            $stmt = '';
            while ($i < $count) {
                $l = $lines[$i];
                $i++;
                if (preg_match('/\$\$\s*$/', $l)) {
                    $stmt .= preg_replace('/\$\$\s*$/', '', $l);
                    break;
                }
                $stmt .= $l . "\n";
            }
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
        }
        return $statements;
    }

    /**
     * @return array{host: string, user: string, pass: string, name: string, port: int, socket: string}
     */
    private function dbConfig(): array
    {
        return [
            'host' => (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost'),
            'user' => (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: ''),
            'pass' => (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ''),
            'name' => (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: ''),
            'port' => (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
            'socket' => (string) ($_ENV['DB_SOCKET'] ?? getenv('DB_SOCKET') ?: ''),
        ];
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
    private function extractFilesSafely(ZipArchive $zip, string $destRoot): void
    {
        $allowed = [];
        foreach (self::FILE_ROOTS as $rel) {
            $base = $destRoot . '/' . $rel;
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
            $target = $destRoot . '/' . $relative;
            // Never follow a pre-existing symlink at the target: fopen('w') would
            // write through it to an arbitrary path outside the allowed roots.
            if (is_link($target)) {
                continue;
            }
            $parent = dirname($target);
            // Real I/O failures (mkdir/realpath/getStream/fopen) must FAIL the
            // restore, not be silently skipped — otherwise a partial filesystem
            // restore would still report success. Security skips (out-of-root,
            // symlink) intentionally `continue`.
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new \RuntimeException(__('Errore durante il ripristino dei file') . ': ' . $relative);
            }
            $realParent = realpath($parent);
            if ($realParent === false) {
                throw new \RuntimeException(__('Errore durante il ripristino dei file') . ': ' . $relative);
            }
            $realParent = str_replace('\\', '/', $realParent);
            // The resolved parent must live inside one of the allowed upload roots.
            $inAllowedRoot = false;
            foreach ($allowed as $root) {
                if ($realParent === $root || str_starts_with($realParent, $root . '/')) {
                    $inAllowedRoot = true;
                    break;
                }
            }
            if (!$inAllowedRoot) {
                continue; // security: entry resolves outside the allowed roots
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new \RuntimeException(__('Errore durante il ripristino dei file') . ': ' . $relative);
            }
            $out = fopen($target, 'w');
            if ($out === false) {
                fclose($stream);
                throw new \RuntimeException(__('Errore durante il ripristino dei file') . ': ' . $relative);
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
