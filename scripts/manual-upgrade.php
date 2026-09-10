<?php
/**
 * Pinakes Manual Upgrade Script
 *
 * Standalone script to upgrade from v0.4.7.2 (or any older version) to v0.4.9.2+
 * Upload this file to the scripts/ directory of your Pinakes installation.
 *
 * Usage:
 *   1. Upload this file to your server's scripts/ directory (same installation that contains .env)
 *   2. Open https://yoursite.com/scripts/manual-upgrade.php in your browser
 *   3. Enter the access password, upload the release ZIP, and click Upgrade
 *   4. DELETE this file after upgrading
 *
 * Safety:
 *   - Creates a full DB backup before any changes
 *   - Preserves: .env, storage/uploads, public/uploads, storage/plugins, covers, etc.
 *   - All migrations are idempotent (safe to run multiple times)
 */

// ============================================================
// CONFIGURATION — change the password before uploading!
// ============================================================
define('UPGRADE_PASSWORD', 'pinakes2026');
define('MAX_ZIP_SIZE', 512 * 1024 * 1024); // 512 MB (aligned with scripts/.user.ini)
// Coarse floor for an upgrade. One value, used by BOTH the disk_free_space()
// gate and the write probe that backs it: a probe smaller than the declared
// floor lets a quota between the two pass the check and fail on the first
// real write.
define('MIN_UPGRADE_FREE_BYTES', 200 * 1024 * 1024);

// ============================================================
// BOOTSTRAP
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('max_execution_time', '600');
ini_set('memory_limit', '512M');
// Note: upload_max_filesize and post_max_size are PHP_INI_PERDIR — set via scripts/.user.ini (512M)

// Auto-detect root: if script is in scripts/, use parent; if at root level, use __DIR__
if (is_file(dirname(__DIR__) . '/.env') || is_file(dirname(__DIR__) . '/version.json')) {
    $rootPath = dirname(__DIR__);
} elseif (is_file(__DIR__ . '/.env') || is_file(__DIR__ . '/version.json')) {
    $rootPath = __DIR__;
} else {
    die('<h2>Errore: impossibile trovare la directory di installazione Pinakes.</h2>'
        . '<p>Posiziona questo script nella cartella <code>scripts/</code> della tua installazione Pinakes.</p>');
}

session_start();

// ============================================================
// CLI MODE — run the upgrade from the shell AS THE FILE OWNER.
//
// When the web-server PHP user cannot write somewhere in the tree
// (e.g. it cannot create new directories under public/assets — issue
// #205), the web updater and this page hit the same permission wall.
// Running from the CLI executes as whoever owns the files, so the
// wall does not exist:
//
//     php scripts/manual-upgrade.php /path/to/pinakes-vX.Y.Z.zip
//
// Same flow as the web mode (DB backup -> extract -> copy -> guarded
// idempotent migrations); auth/CSRF are meaningless with shell access
// and are satisfied synthetically below.
// ============================================================
if (PHP_SAPI === 'cli') {
    $zipArg = $argv[1] ?? '';
    if ($zipArg === '--count-sql-statements') {
        $sqlFile = $argv[2] ?? '';
        if ($sqlFile === '' || !is_file($sqlFile)) {
            fwrite(STDERR, "ERROR: SQL file not found: {$sqlFile}\n");
            exit(1);
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            fwrite(STDERR, "ERROR: cannot read SQL file: {$sqlFile}\n");
            exit(1);
        }
        fwrite(STDOUT, (string) count(splitSqlStatements($sql)) . "\n");
        exit(0);
    }
    if ($zipArg === '' || in_array($zipArg, ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage: php scripts/manual-upgrade.php /path/to/pinakes-vX.Y.Z.zip\n"
            . "       php scripts/manual-upgrade.php --count-sql-statements /path/to/migration.sql\n");
        exit($zipArg === '' ? 1 : 0);
    }
    $zipReal = realpath($zipArg);
    if ($zipReal === false || !is_file($zipReal)) {
        fwrite(STDERR, "ERROR: ZIP not found: {$zipArg}\n");
        exit(1);
    }
    if (!str_ends_with(strtolower($zipReal), '.zip')) {
        fwrite(STDERR, "ERROR: the file must be a .zip release package\n");
        exit(1);
    }
    $cliToken = bin2hex(random_bytes(16));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['upgrade_auth'] = true;
    $_SESSION['upgrade_csrf'] = $cliToken;
    $_POST['csrf_token'] = $cliToken;
    $_FILES['zipfile'] = [
        'name' => basename($zipReal),
        'tmp_name' => $zipReal, // only ever opened by ZipArchive, never moved/unlinked
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($zipReal),
    ];
}

// ============================================================
// HELPERS
// ============================================================

function loadEnv(string $path): array
{
    $vars = [];
    if (!is_file($path)) {
        return $vars;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $vars;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Remove quotes and unescape
        $isDoubleQuoted = str_starts_with($value, '"') && str_ends_with($value, '"');
        $isSingleQuoted = str_starts_with($value, "'") && str_ends_with($value, "'");
        if ($isDoubleQuoted || $isSingleQuoted) {
            $value = substr($value, 1, -1);
            if ($isDoubleQuoted) {
                $value = preg_replace('/\\\\(["$\\\\])/', '$1', $value) ?? $value;
            }
        }
        $vars[$key] = $value;
    }
    return $vars;
}

function getDb(array $env): mysqli
{
    $host = $env['DB_HOST'] ?? 'localhost';
    $user = $env['DB_USER'] ?? '';
    $pass = $env['DB_PASS'] ?? '';
    $name = $env['DB_NAME'] ?? '';
    $port = (int) ($env['DB_PORT'] ?? 3306);
    $socket = $env['DB_SOCKET'] ?? null;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    if (!empty($socket)) {
        $db = new mysqli($host, $user, $pass, $name, $port, $socket);
    } else {
        $db = new mysqli($host, $user, $pass, $name, $port);
    }

    $db->set_charset('utf8mb4');
    return $db;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function formatBytes(float $bytes): string
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
 * Prove that $bytes can really be written by THIS account under storage/tmp.
 *
 * disk_free_space() sees the FILESYSTEM: on a cPanel account it happily reports
 * tens of gigabytes free while the account's own quota is exhausted, which is
 * how a healthy release came to look corrupt (issue #422). Only a real
 * allocation answers the question, so this writes real bytes — a sparse hole
 * consumes no quota, and the quota is precisely what is being measured.
 *
 * Bounded by construction: a request the filesystem provably cannot hold is
 * refused without writing anything, and above 16 MB the range is allocated by
 * touching one byte per 1 KiB block instead of streaming every byte (the same
 * blocks are charged, at a thousandth of the I/O). If free space is unreadable,
 * a large request returns unknown without allocating: neither an unbounded write
 * nor a successful 16 MB sample can safely establish the requested capacity.
 *
 * Deliberately duplicated from app/Support/Updater.php: this script must keep
 * working with no autoloader and no application classes, because it is the tool
 * used when the application itself will not boot.
 *
 * @return string '' when the space is there, 'nospace' when the write ran out
 *                of room, 'unavailable' when the probe could not be created,
 *                'unknown' when a large request cannot be bounded
 */
function probeWriteBytes(string $rootPath, int $bytes, ?string $directory = null): string
{
    if ($bytes <= 0) {
        return '';
    }
    $denseLimit = 16 * 1024 * 1024;
    $stride = 1024;

    $dir = $directory ?? rtrim(str_replace('\\', '/', $rootPath), '/') . '/storage/tmp';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return 'unavailable';
    }

    $free = @disk_free_space($dir);
    if (is_float($free) && $free < $bytes) {
        return 'nospace';
    }
    $strided = $bytes > $denseLimit;
    if ($strided && !is_float($free)) {
        return 'unknown';
    }

    $probe = $dir . '/.upgrade_probe_' . bin2hex(random_bytes(4));
    $handle = @fopen($probe, 'wb');
    if ($handle === false) {
        return 'unavailable';
    }
    $ok = true;
    try {
        if ($strided) {
            for ($offset = 0; $offset < $bytes; $offset += $stride) {
                if (@fseek($handle, $offset, SEEK_SET) !== 0) {
                    $ok = false;
                    break;
                }
                $result = @fwrite($handle, '0');
                if ($result === false || $result === 0) {
                    $ok = false;
                    break;
                }
            }
        } else {
            $chunk = str_repeat('0', 1024 * 1024);
            $written = 0;
            while ($written < $bytes) {
                $slice = $bytes - $written >= strlen($chunk) ? $chunk : substr($chunk, 0, $bytes - $written);
                $result = @fwrite($handle, $slice);
                if ($result === false || $result === 0) {
                    $ok = false;
                    break;
                }
                $written += $result;
            }
        }
        if ($ok && !@fflush($handle)) {
            $ok = false;
        }
    } catch (Throwable $e) {
        $ok = false;
    } finally {
        @fclose($handle);
        @unlink($probe);
    }
    return $ok ? '' : 'nospace';
}

/**
 * Verify simultaneous writes on each destination filesystem. No application
 * bootstrap is required: this script also repairs installations that cannot boot.
 * @param array<string, int> $requirements
 */
function verifyUpgradeSpace(string $rootPath, array $requirements): void
{
    $volumes = [];
    foreach ($requirements as $path => $bytes) {
        $dir = $path;
        while (!is_dir($dir) && !file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }
        $stat = @stat($dir);
        if (!is_dir($dir) || !is_writable($dir) || $stat === false) {
            throw new RuntimeException('Directory di aggiornamento non scrivibile: ' . $path);
        }
        $key = (string) $stat['dev'];
        if (!isset($volumes[$key])) {
            $volumes[$key] = ['path' => $dir, 'bytes' => 0];
        }
        $volumes[$key]['bytes'] += max(4096, $bytes);
    }
    foreach ($volumes as $volume) {
        $dir = $volume['path'];
        $required = $volume['bytes'];
        $verdict = probeWriteBytes($rootPath, $required, $dir);
        if ($verdict !== '') {
            $reason = match ($verdict) {
                'nospace' => "spazio su disco o quota dell'account insufficienti",
                'unknown' => 'spazio disponibile non verificabile: controlla la configurazione del filesystem',
                default => 'directory non scrivibile',
            };
            throw new RuntimeException('Preflight fallito in ' . $dir . ': ' . $reason
                . ' (richiesti ' . formatBytes($required) . ').');
        }
    }
}

/** @return array<string, int> Space needed for the actual copy destinations. */
function upgradeCopyRequirements(string $source, string $dest, array $preserve): array
{
    $requirements = [$dest => 4096];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }
        $relative = substr($item->getPathname(), strlen($source) + 1);
        foreach ($preserve as $skip) {
            if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                continue 2;
            }
        }
        $target = $dest . '/' . $relative;
        $dir = $item->isDir() ? $target : dirname($target);
        $bytes = $item->isDir() ? 4096 : max(4096, (int) ceil($item->getSize() / 4096) * 4096);
        $requirements[$dir] = ($requirements[$dir] ?? 0) + $bytes;
    }
    return $requirements;
}

/**
 * Why a write to this path failed, in words an operator can act on.
 *
 * Cheapest and most specific first, with error_get_last() captured before this
 * function performs any I/O of its own — it is process-global, so a diagnosis
 * that writes first ends up reporting its own error. Same wording as the
 * in-app updater, hardcoded in Italian like the rest of this script.
 */
function describeWriteFailure(string $rootPath, string $targetPath): string
{
    $last = error_get_last();
    $dir = dirname($targetPath);

    if (is_file($targetPath) && !is_writable($targetPath)) {
        return 'il file di destinazione esiste e non è scrivibile';
    }
    if (!is_dir($dir)) {
        return 'la directory di destinazione non esiste';
    }
    if (!is_writable($dir)) {
        return 'la directory di destinazione non è scrivibile';
    }
    if (probeWriteBytes($rootPath, 1024 * 1024, $dir) === 'nospace') {
        return 'spazio su disco o quota dell\'account esauriti';
    }

    $message = $last === null ? '' : trim($last['message']);
    return $message !== '' ? $message : 'causa sconosciuta';
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_link($path)) {
            @unlink($path);
            continue;
        }
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Split SQL into statements respecting quoted strings.
 * @return array<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        // SQL comments may legally contain apostrophes, quotes and semicolons.
        // Copy them verbatim without letting their contents alter quote state
        // or terminate a statement. This is load-bearing for the standalone
        // manual upgrade path, which splits before stripping leading comments.
        if (!$inSingle && !$inDouble && !$inBacktick) {
            $isDashComment = $char === '-' && $i + 1 < $length && $sql[$i + 1] === '-'
                && ($i + 2 >= $length || ctype_space($sql[$i + 2]));
            if ($isDashComment || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $current .= $sql[$i];
                    $i++;
                }
                if ($i < $length) {
                    $current .= "\n";
                }
                continue;
            }
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $current .= '/*';
                $i += 2;
                while ($i < $length) {
                    if ($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/') {
                        $current .= '*/';
                        $i++;
                        break;
                    }
                    $current .= $sql[$i];
                    $i++;
                }
                continue;
            }
        }

        // Handle backslash escapes inside quoted strings
        if (($inSingle || $inDouble) && $char === '\\' && $i + 1 < $length) {
            $current .= $char . $sql[$i + 1];
            $i++;
            continue;
        }

        // Single-quoted string
        if (!$inDouble && !$inBacktick && $char === "'") {
            if ($inSingle && $i + 1 < $length && $sql[$i + 1] === "'") {
                $current .= "''";
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
            $current .= $char;
            continue;
        }

        // Double-quoted string
        if (!$inSingle && !$inBacktick && $char === '"') {
            $inDouble = !$inDouble;
            $current .= $char;
            continue;
        }

        // Backtick-quoted identifier
        if (!$inSingle && !$inDouble && $char === '`') {
            $inBacktick = !$inBacktick;
            $current .= $char;
            continue;
        }

        // Statement delimiter
        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}

/**
 * Recursively copy $src to $dst, skipping relative paths listed in $skip
 * $skip paths are relative to $rootDst (the top-level destination)
 */
function copyTree(string $src, string $dst, string $rootDst, array $skipRelative): int
{
    $count = 0;
    if (!is_dir($src)) {
        return $count;
    }
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    $items = scandir($src);
    if ($items === false) {
        return $count;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;

        // Compute relative path from rootDst
        $relPath = substr($dstPath, strlen($rootDst) + 1);

        // Check if this path is in the skip list
        $shouldSkip = false;
        foreach ($skipRelative as $sp) {
            if ($relPath === $sp || str_starts_with($relPath, $sp . '/')) {
                $shouldSkip = true;
                break;
            }
        }
        if ($shouldSkip) {
            continue;
        }

        if (is_link($srcPath)) {
            throw new RuntimeException('Elemento simbolico non consentito nel pacchetto: ' . $srcPath);
        }

        if (is_dir($srcPath)) {
            $count += copyTree($srcPath, $dstPath, $rootDst, $skipRelative);
        } else {
            $dstDir = dirname($dstPath);
            if (is_link($dstPath) || is_link($dstDir)) {
                throw new RuntimeException('Percorso destinazione simbolico non consentito: ' . $dstPath);
            }
            if (!is_dir($dstDir) && !@mkdir($dstDir, 0755, true) && !is_dir($dstDir)) {
                throw new RuntimeException(
                    'Impossibile creare directory destinazione: ' . $dstDir
                    . ' — ' . describeWriteFailure($rootDst, $dstDir)
                );
            }
            $rootReal = realpath($rootDst);
            $dstDirReal = realpath($dstDir);
            if (
                $rootReal === false
                || $dstDirReal === false
                || !str_starts_with($dstDirReal . '/', rtrim($rootReal, '/') . '/')
            ) {
                throw new RuntimeException('Percorso destinazione non valido: ' . $dstPath);
            }
            // The file name alone is misleading: the loop stops at whatever
            // entry it had reached when the real problem (an exhausted quota, a
            // read-only target) occurred — that is how a healthy release came to
            // look corrupt. Say why, and here it matters more than in the app:
            // this script overwrites the live tree with no file rollback.
            if (!@copy($srcPath, $dstPath)) {
                throw new RuntimeException(
                    'Copia file fallita: ' . $srcPath . ' -> ' . $dstPath
                    . ' — ' . describeWriteFailure($rootDst, $dstPath)
                );
            }
            $count++;
        }
    }
    return $count;
}

// ============================================================
// AUTH CHECK
// ============================================================

$authenticated = false;
$error = '';
$success = '';
$log = [];

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$maxAttempts = 5;
$lockSeconds = 300;

// File-based throttling (not session-scoped, so rotating cookies cannot bypass it)
$clientKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$attemptFile = sys_get_temp_dir() . '/pinakes_upgrade_auth_' . $clientKey . '.json';
$attemptState = ['failed' => 0, 'lock_until' => 0];
if (is_file($attemptFile)) {
    $raw = file_get_contents($attemptFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $attemptState['failed'] = (int) ($decoded['failed'] ?? 0);
        $attemptState['lock_until'] = (int) ($decoded['lock_until'] ?? 0);
    }
}

if ($requestMethod === 'POST' && isset($_POST['password'])) {
    if (time() < $attemptState['lock_until']) {
        $error = 'Troppi tentativi. Riprova tra qualche minuto.';
    } elseif (hash_equals('pinakes2026', UPGRADE_PASSWORD)) {
        $error = 'SICUREZZA: cambia la password nel file prima di procedere.';
    } elseif (hash_equals(UPGRADE_PASSWORD, (string) $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['upgrade_auth'] = true;
        @unlink($attemptFile);
    } else {
        $attemptState['failed']++;
        if ($attemptState['failed'] >= $maxAttempts) {
            $attemptState['lock_until'] = time() + $lockSeconds;
            $attemptState['failed'] = 0;
        }
        file_put_contents($attemptFile, json_encode($attemptState), LOCK_EX);
        $error = 'Password errata.';
    }
}

$authenticated = !empty($_SESSION['upgrade_auth']);

// Generate CSRF token for the upgrade form
if ($authenticated && empty($_SESSION['upgrade_csrf'])) {
    $_SESSION['upgrade_csrf'] = bin2hex(random_bytes(32));
}

// ============================================================
// PERFORM UPGRADE
// ============================================================

if ($authenticated && $requestMethod === 'POST' && isset($_FILES['zipfile'])) {
    // CSRF validation
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['upgrade_csrf'] ?? '');
    if (!hash_equals($sessionToken, $submittedToken)) {
        $error = 'Token CSRF non valido. Ricarica la pagina e riprova.';
        goto render;
    }
    // Regenerate token after use
    $_SESSION['upgrade_csrf'] = bin2hex(random_bytes(32));
    $log[] = '=== Pinakes Manual Upgrade — ' . date('Y-m-d H:i:s') . ' ===';

    try {
        // 1. Validate upload
        $file = $_FILES['zipfile'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error code: ' . $file['error']);
        }
        if ($file['size'] > MAX_ZIP_SIZE) {
            throw new RuntimeException('File troppo grande: ' . formatBytes($file['size']));
        }
        if (!str_ends_with(strtolower($file['name']), '.zip')) {
            throw new RuntimeException('Il file deve essere un .zip');
        }
        $log[] = '[OK] File caricato: ' . $file['name'] . ' (' . formatBytes($file['size']) . ')';

        // 1b. Pre-flight checks: permissions, disk space, ZipArchive
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione ZipArchive non disponibile. Contatta il provider hosting.');
        }

        verifyUpgradeSpace($rootPath, [
            $rootPath => 4096,
            $rootPath . '/storage/tmp' => MIN_UPGRADE_FREE_BYTES,
            $rootPath . '/storage/backups' => 4096,
        ]);

        $writableDirs = ['storage', 'storage/tmp', 'storage/backups', 'app', 'public', 'installer'];
        foreach ($writableDirs as $dir) {
            $dirPath = $rootPath . '/' . $dir;
            if (is_dir($dirPath) && !is_writable($dirPath)) {
                throw new RuntimeException('Directory non scrivibile: ' . $dir . '. Correggi i permessi prima di continuare.');
            }
        }
        $log[] = '[OK] Pre-flight: permessi, spazio disco, ZipArchive OK';

        // 2. Load .env and connect to DB
        $env = loadEnv($rootPath . '/.env');
        if (empty($env['DB_NAME'])) {
            throw new RuntimeException('.env non trovato o DB_NAME mancante');
        }
        $db = getDb($env);
        $log[] = '[OK] Connessione DB riuscita: ' . $env['DB_NAME'];

        // 3. Read current version
        $versionFile = $rootPath . '/version.json';
        $currentVersion = '0.0.0';
        if (is_file($versionFile)) {
            $vj = json_decode(file_get_contents($versionFile), true);
            $currentVersion = $vj['version'] ?? '0.0.0';
        }
        $log[] = '[INFO] Versione attuale: ' . $currentVersion;

        // 4. Create DB backup
        $backupDir = $rootPath . '/storage/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }
        $backupFile = $backupDir . '/pre_upgrade_' . str_replace('.', '_', $currentVersion) . '_' . date('Ymd_His') . '.sql';

        // The dump is redirected straight to disk with no size limit of its own,
        // so a database larger than the coarse pre-flight floor can still exhaust
        // the quota mid-write — and this script has no file rollback. Estimate it
        // from information_schema and prove that much really fits first. The
        // estimate is deliberately generous: an SQL dump is text, so it runs
        // larger than the stored byte count it is derived from.
        $dumpEstimate = 0;
        $sizeRow = $db->query(
            "SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
               FROM information_schema.tables
              WHERE table_schema = DATABASE()"
        );
        if ($sizeRow instanceof mysqli_result) {
            $row = $sizeRow->fetch_assoc();
            $sizeRow->free();
            $dumpEstimate = (int) (((float) ($row['bytes'] ?? 0)) * 1.5);
        }
        $criticalBytes = 0;
        foreach (['.env', 'config.local.php', 'version.json'] as $criticalFile) {
            $criticalPath = $rootPath . '/' . $criticalFile;
            if (is_file($criticalPath)) {
                $criticalBytes += (int) filesize($criticalPath);
            }
        }
        verifyUpgradeSpace($rootPath, [
            $rootPath => 4096,
            $rootPath . '/storage/tmp' => MIN_UPGRADE_FREE_BYTES,
            $backupDir => max(0, $dumpEstimate) + $criticalBytes,
        ]);
        $log[] = '[OK] Spazio cumulativo per dump, backup critici e riserva verificato';

        $mysqldumpBin = null;
        foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/homebrew/bin/mysqldump'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $mysqldumpBin = $candidate;
                break;
            }
        }

        if ($mysqldumpBin) {
            // Use defaults-extra-file to avoid exposing password in process list
            $defaultsFile = tempnam(sys_get_temp_dir(), 'pinakes_dump_');
            if ($defaultsFile === false) {
                throw new RuntimeException('Impossibile creare file temporaneo per credenziali mysqldump');
            }
            $written = file_put_contents($defaultsFile,
                "[client]\n"
                . "host=" . ($env['DB_HOST'] ?? 'localhost') . "\n"
                . "user=" . ($env['DB_USER'] ?? '') . "\n"
                . "password=" . ($env['DB_PASS'] ?? '') . "\n"
                . "port=" . (int) ($env['DB_PORT'] ?? 3306) . "\n"
            );
            if ($written === false || $written === 0) {
                @unlink($defaultsFile);
                throw new RuntimeException('Impossibile scrivere il file temporaneo credenziali mysqldump');
            }
            @chmod($defaultsFile, 0600);

            $args = [
                $mysqldumpBin,
                '--defaults-extra-file=' . $defaultsFile,
            ];
            if (!empty($env['DB_SOCKET'])) {
                $args[] = '--socket=' . $env['DB_SOCKET'];
            }
            $args[] = '--single-transaction';
            $args[] = '--routines';
            $args[] = '--triggers';
            $args[] = $env['DB_NAME'];

            $safeCmd = implode(' ', array_map('escapeshellarg', $args)) . ' > ' . escapeshellarg($backupFile) . ' 2>&1';
            $cmdOutput = [];
            $exitCode = 0;
            try {
                exec($safeCmd, $cmdOutput, $exitCode);
            } finally {
                @unlink($defaultsFile);
            }

            if ($exitCode === 0 && is_file($backupFile) && filesize($backupFile) > 100) {
                $log[] = '[OK] Backup DB creato: ' . basename($backupFile) . ' (' . formatBytes(filesize($backupFile)) . ')';
            } else {
                @unlink($backupFile);
                throw new RuntimeException('Backup DB fallito (mysqldump exit ' . $exitCode . '). Upgrade interrotto per sicurezza.');
            }
        } else {
            throw new RuntimeException('mysqldump non trovato. Upgrade interrotto per sicurezza (backup obbligatorio).');
        }

        // 5. Extract ZIP to temp directory
        $tempDir = $rootPath . '/storage/tmp/manual_upgrade_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zip = new ZipArchive();
        $res = $zip->open($file['tmp_name']);
        if ($res !== true) {
            throw new RuntimeException('Impossibile aprire il ZIP (errore: ' . $res . ')');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                throw new RuntimeException('Archivio ZIP non valido');
            }
            $entry = str_replace('\\', '/', $entry);
            if (preg_match('#(^/|^\\\\|^[A-Za-z]:/|(^|/)\.\.(/|$))#', $entry)) {
                throw new RuntimeException('ZIP non valido: contiene percorsi pericolosi');
            }
        }
        // Check uncompressed size vs available disk space (re-query: value from pre-flight may be stale)
        $uncompressedBytes = 0;
        for ($j = 0; $j < $zip->numFiles; $j++) {
            $st = $zip->statIndex($j);
            if ($st !== false) {
                $uncompressedBytes += $st['size'];
            }
        }
        $requiredBytes = $uncompressedBytes + (100 * 1024 * 1024); // 100 MB safety margin
        try {
            verifyUpgradeSpace($rootPath, [$tempDir => $requiredBytes]);
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }

        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            throw new RuntimeException('Estrazione ZIP fallita in ' . basename($tempDir));
        }
        $zip->close();
        $log[] = '[OK] ZIP estratto in directory temporanea';

        // 6. Detect the inner folder (release ZIPs have a top-level folder)
        $extractedRoot = $tempDir;
        $items = @scandir($tempDir);
        if ($items !== false) {
            $dirs = array_filter(
                $items,
                fn($i) => $i !== '.' && $i !== '..' && is_dir($tempDir . '/' . $i) && !is_link($tempDir . '/' . $i)
            );
            if (count($dirs) === 1) {
                $candidate = reset($dirs);
                $candidatePath = $tempDir . '/' . $candidate;
                $tempReal = realpath($tempDir);
                $candidateReal = realpath($candidatePath);
                if (
                    $tempReal !== false
                    && $candidateReal !== false
                    && str_starts_with($candidateReal . '/', rtrim($tempReal, '/') . '/')
                    && is_file($candidateReal . '/version.json')
                ) {
                    $extractedRoot = $candidateReal;
                    $log[] = '[INFO] Rilevata cartella interna: ' . $candidate;
                }
            }
        }

        // 7. Read target version
        $targetVersionFile = $extractedRoot . '/version.json';
        $targetVersion = '0.0.0';
        if (is_file($targetVersionFile)) {
            $tvj = json_decode(file_get_contents($targetVersionFile), true);
            $targetVersion = $tvj['version'] ?? '0.0.0';
        }
        $log[] = '[INFO] Versione target: ' . $targetVersion;

        if (version_compare($targetVersion, $currentVersion, '<=')) {
            throw new RuntimeException(
                'Versione target non valida: ' . $targetVersion . ' (attuale: ' . $currentVersion . '). '
                . 'Il pacchetto deve essere più recente della versione installata.'
            );
        }

        // 8. Paths to preserve (DO NOT overwrite these)
        $preservePaths = [
            '.env',
            '.env.backup',
            'storage/uploads',
            'storage/plugins',
            'storage/backups',
            'storage/cache',
            'storage/logs',
            'storage/calendar',
            'storage/tmp',
            'public/uploads',
            'public/.htaccess',
            'public/.user.ini',
            'public/robots.txt',
            'public/favicon.ico',
            'public/sitemap.xml',
            'config.local.php',
        ];

        // Recheck after extraction, using the actual incoming files and mounts.
        $copyRequirements = upgradeCopyRequirements($extractedRoot, $rootPath, $preservePaths);
        $backupTarget = $rootPath . '/storage/backups';
        $copyRequirements[$backupTarget] = ($copyRequirements[$backupTarget] ?? 0) + $criticalBytes;
        verifyUpgradeSpace($rootPath, $copyRequirements);

        // 8b. Backup critical files before overwriting
        $fileBackupDir = $rootPath . '/storage/backups/pre_upgrade_files_' . date('Ymd_His');
        if (!is_dir($fileBackupDir)) {
            mkdir($fileBackupDir, 0755, true);
        }
        $criticalFiles = ['.env', 'config.local.php', 'version.json'];
        foreach ($criticalFiles as $cf) {
            if (is_file($rootPath . '/' . $cf)) {
                if (!@copy($rootPath . '/' . $cf, $fileBackupDir . '/' . $cf)) {
                    throw new RuntimeException(
                        'Backup file critico fallito: ' . $cf
                        . ' — ' . describeWriteFailure($rootPath, $fileBackupDir . '/' . $cf)
                    );
                }
            }
        }
        $log[] = '[OK] Backup file critici in ' . basename($fileBackupDir);

        // 9. Copy new files over existing installation
        $log[] = '[INFO] Copia file in corso (preservando dati utente)...';
        $filesCopied = copyTree($extractedRoot, $rootPath, $rootPath, $preservePaths);
        $log[] = '[OK] ' . $filesCopied . ' file copiati';

        // 10. Run database migrations
        $migrationsPath = $rootPath . '/installer/database/migrations';
        $migrationsRun = [];

        if (is_dir($migrationsPath)) {
            $migrationFiles = glob($migrationsPath . '/migrate_*.sql') ?: [];
            usort($migrationFiles, static function (string $a, string $b): int {
                preg_match('/migrate_(.+)\.sql$/', basename($a), $ma);
                preg_match('/migrate_(.+)\.sql$/', basename($b), $mb);
                return version_compare($ma[1] ?? '0.0.0', $mb[1] ?? '0.0.0');
            });

            // Ensure migrations table exists
            $db->query("CREATE TABLE IF NOT EXISTS `migrations` (
                id INT PRIMARY KEY AUTO_INCREMENT,
                version VARCHAR(50) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                batch INT NOT NULL DEFAULT 1,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_version (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Get max batch
            $batchResult = $db->query("SELECT COALESCE(MAX(batch), 0) + 1 as next_batch FROM migrations");
            $batchRow = $batchResult->fetch_assoc();
            $nextBatch = (int) ($batchRow['next_batch'] ?? 1);

            foreach ($migrationFiles as $migFile) {
                $filename = basename($migFile);
                if (!preg_match('/migrate_(.+)\.sql$/', $filename, $matches)) {
                    continue;
                }
                $migVersion = $matches[1];

                // Only run migrations newer than current version
                if (version_compare($migVersion, $currentVersion, '<=')) {
                    continue;
                }
                if (version_compare($migVersion, $targetVersion, '>')) {
                    continue;
                }

                // Check if already executed
                $checkStmt = $db->prepare("SELECT id FROM migrations WHERE version = ?");
                $checkStmt->bind_param('s', $migVersion);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $checkStmt->close();

                if ($checkResult->num_rows > 0) {
                    $log[] = '[SKIP] Migrazione ' . $migVersion . ' gia\' eseguita';
                    continue;
                }

                // Read and execute migration
                $sql = file_get_contents($migFile);
                if ($sql === false) {
                    throw new RuntimeException('Impossibile leggere la migrazione: ' . $filename);
                }

                $log[] = '[INFO] Esecuzione migrazione ' . $migVersion . '...';

                // Execute SQL statements individually (quote-aware split)
                $statements = splitSqlStatements($sql);
                $migrationFailed = false;
                // 1060=Duplicate column, 1061=Duplicate key, 1062=Duplicate entry,
                // 1050=Table exists, 1068=Multiple primary, 1091=Can't DROP,
                // 1022=Duplicate key (alt), 1826=Duplicate FK, 1146=Table doesn't exist
                $ignorableErrors = [1060, 1061, 1062, 1050, 1068, 1091, 1022, 1826, 1146];
                $lastError = '';
                $lastErrno = 0;

                foreach ($statements as $stmtSql) {
                    // Strip only leading SQL comment block, preserve inline comments
                    $stmtSql = preg_replace('/\A(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $stmtSql);
                    $stmtSql = trim((string) $stmtSql);
                    if ($stmtSql === '') {
                        continue;
                    }
                    try {
                        $db->query($stmtSql);
                    } catch (\mysqli_sql_exception $ex) {
                        $lastErrno = (int) $ex->getCode();
                        $lastError = $ex->getMessage();
                        if (in_array($lastErrno, $ignorableErrors, true)) {
                            continue;
                        }
                        $migrationFailed = true;
                        break;
                    }
                }

                if ($migrationFailed) {
                    try {
                        $db->rollback();
                    } catch (\Throwable $rollbackError) {
                        // Preserve the SQL error that aborted the migration.
                    }
                    throw new RuntimeException('Migrazione ' . $migVersion . ' fallita: [' . $lastErrno . '] ' . $lastError);
                } else {
                    // Record migration
                    $recStmt = $db->prepare("INSERT IGNORE INTO migrations (version, filename, batch) VALUES (?, ?, ?)");
                    $recStmt->bind_param('ssi', $migVersion, $filename, $nextBatch);
                    $recStmt->execute();
                    $recStmt->close();

                    $migrationsRun[] = $migVersion;
                    $log[] = '[OK] Migrazione ' . $migVersion . ' completata';
                }
            }
        } else {
            $log[] = '[WARN] Directory migrazioni non trovata: ' . $migrationsPath;
        }

        if (empty($migrationsRun)) {
            $log[] = '[INFO] Nessuna nuova migrazione da eseguire';
        } else {
            $log[] = '[OK] ' . count($migrationsRun) . ' migrazioni eseguite: ' . implode(', ', $migrationsRun);
        }

        // Keep the standalone upgrader equivalent to the in-app and Docker
        // migration paths: the 0.7.36 contributor backfill must finish before
        // the upgrade is reported as complete.
        if (version_compare($targetVersion, '0.7.36-rc.1', '>=')) {
            $autoload = $rootPath . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                throw new RuntimeException('Autoloader non trovato per il backfill contributor');
            }
            require_once $autoload;
            if (!\App\Support\ContributorBackfill::run($db)) {
                throw new RuntimeException('Backfill contributor non completato');
            }
            $log[] = '[OK] Backfill contributor completato';
        }

        // Match Updater::installUpdate(): migrations may materialise or repair
        // physical-copy rows, so refresh the derived book/copy projection before
        // reporting the manual upgrade complete. Keep this non-fatal just like
        // the web updater; the migration itself is already committed and the
        // idempotent maintenance pass can retry later.
        try {
            $autoload = $rootPath . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                throw new RuntimeException('Autoloader non trovato per il ricalcolo disponibilità');
            }
            require_once $autoload;
            $availability = (new \App\Support\DataIntegrity($db))->recalculateAllBookAvailability();
            if (!empty($availability['errors'])) {
                throw new RuntimeException(implode('; ', $availability['errors']));
            }
            $log[] = '[OK] Disponibilità libri ricalcolata dalle copie fisiche';
        } catch (Throwable $availabilityError) {
            $log[] = '[WARN] Ricalcolo disponibilità rinviato: ' . $availabilityError->getMessage();
        }

        // 11. Clear cache
        $cacheDir = $rootPath . '/storage/cache';
        if (is_dir($cacheDir)) {
            $cacheFiles = glob($cacheDir . '/*.php') ?: [];
            foreach ($cacheFiles as $cf) {
                @unlink($cf);
            }
            $log[] = '[OK] Cache svuotata (' . count($cacheFiles) . ' file)';
        }

        // 12. Cleanup temp
        deleteDirectory($tempDir);
        $log[] = '[OK] Directory temporanea rimossa';

        // 13. Done
        $finalVersionFile = $rootPath . '/version.json';
        $finalVersion = '?';
        if (is_file($finalVersionFile)) {
            $fvj = json_decode(file_get_contents($finalVersionFile), true);
            $finalVersion = $fvj['version'] ?? '?';
        }

        $log[] = '';
        $log[] = '=== UPGRADE COMPLETATO ===';
        $log[] = 'Versione precedente: ' . $currentVersion;
        $log[] = 'Versione attuale:    ' . $finalVersion;
        $log[] = '';
        $log[] = 'IMPORTANTE: Elimina questo file (scripts/manual-upgrade.php) dal server!';

        $success = 'Upgrade completato! ' . $currentVersion . ' -> ' . $finalVersion;

    } catch (Throwable $e) {
        $error = $e->getMessage();
        $log[] = '[FATAL] ' . $e->getMessage();
        error_log('[manual-upgrade] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        // Try to cleanup temp dir
        if (isset($tempDir) && is_dir($tempDir)) {
            deleteDirectory($tempDir);
        }
    }
}

// ============================================================
// UI
// ============================================================

$currentVersion = '?';
$versionFile = $rootPath . '/version.json';
if (is_file($versionFile)) {
    $vj = json_decode(file_get_contents($versionFile), true);
    $currentVersion = $vj['version'] ?? '?';
}

render:
// CLI: plain-text report on stdout/stderr, exit code for scripting — no HTML.
if (PHP_SAPI === 'cli') {
    foreach ($log as $logLine) {
        fwrite(STDOUT, $logLine . "\n");
    }
    if ($error !== '') {
        fwrite(STDERR, "ERROR: " . $error . "\n");
        exit(1);
    }
    if ($success !== '') {
        fwrite(STDOUT, $success . "\n");
    }
    exit(0);
}
?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pinakes Manual Upgrade</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; min-height: 100vh; padding: 2rem 1rem; }
        .container { max-width: 700px; margin: 0 auto; }
        .card { background: white; border-radius: 1rem; box-shadow: 0 4px 24px rgba(0,0,0,0.08); padding: 2rem; margin-bottom: 1.5rem; }
        h1 { font-size: 1.5rem; color: #111; margin-bottom: 0.25rem; }
        .subtitle { color: #6b7280; font-size: 0.9rem; margin-bottom: 1.5rem; }
        .version-badge { display: inline-block; background: #e5e7eb; color: #374151; padding: 0.25rem 0.75rem; border-radius: 0.5rem; font-size: 0.85rem; font-weight: 600; font-family: monospace; }
        label { display: block; font-weight: 600; margin-bottom: 0.5rem; color: #374151; font-size: 0.9rem; }
        input[type="password"], input[type="file"] { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 0.5rem; font-size: 0.95rem; margin-bottom: 1rem; }
        input[type="file"] { background: #f9fafb; cursor: pointer; }
        button { padding: 0.75rem 1.5rem; background: #16a34a; color: white; border: none; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; width: 100%; }
        button:hover { background: #15803d; }
        button.login-btn { background: #111827; }
        button.login-btn:hover { background: #000; }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .log { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 0.5rem; font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.8rem; line-height: 1.6; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; }
        .log .ok { color: #4ade80; }
        .log .err { color: #f87171; }
        .log .warn { color: #fbbf24; }
        .log .info { color: #60a5fa; }
        .log .head { color: #c084fc; font-weight: bold; }
        .checklist { list-style: none; padding: 0; font-size: 0.85rem; color: #6b7280; }
        .checklist li { padding: 0.3rem 0; padding-left: 1.5rem; position: relative; }
        .checklist li::before { content: '\2713'; position: absolute; left: 0; color: #16a34a; font-weight: bold; }
        footer { text-align: center; color: #9ca3af; font-size: 0.8rem; margin-top: 2rem; }
    </style>
</head>
<body>
<div class="container">

    <div class="card">
        <h1>Pinakes Manual Upgrade</h1>
        <p class="subtitle">
            Versione installata: <span class="version-badge">v<?= h($currentVersion) ?></span>
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if (!$authenticated): ?>
            <!-- Login Form -->
            <form method="post">
                <label for="password">Password di accesso</label>
                <input type="password" name="password" id="password" placeholder="Inserisci la password" required autofocus>
                <button type="submit" class="login-btn">Accedi</button>
            </form>

        <?php elseif (empty($log)): ?>
            <!-- Upload Form -->
            <div class="alert alert-warning">
                <strong>Prima di procedere:</strong> assicurati di avere un backup del database e dei file.
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['upgrade_csrf'] ?? '') ?>">
                <label for="zipfile">Pacchetto di aggiornamento (.zip)</label>
                <input type="file" name="zipfile" id="zipfile" accept=".zip" required>

                <ul class="checklist" style="margin-bottom: 1.5rem;">
                    <li>Backup automatico del database prima dell'upgrade</li>
                    <li>Preserva: copertine, uploads, .env, plugin, configurazioni</li>
                    <li>Esegue le migrazioni DB mancanti automaticamente</li>
                    <li>Le migrazioni sono idempotenti (sicure da rieseguire)</li>
                </ul>

                <button type="submit">Avvia Upgrade</button>
            </form>

        <?php endif; ?>
    </div>

    <?php if (!empty($log)): ?>
    <div class="card">
        <label>Log di upgrade</label>
        <div class="log"><?php
            foreach ($log as $line) {
                if (str_starts_with($line, '===')) {
                    echo '<span class="head">' . h($line) . '</span>' . "\n";
                } elseif (str_starts_with($line, '[OK]')) {
                    echo '<span class="ok">' . h($line) . '</span>' . "\n";
                } elseif (str_starts_with($line, '[ERROR]') || str_starts_with($line, '[FATAL]')) {
                    echo '<span class="err">' . h($line) . '</span>' . "\n";
                } elseif (str_starts_with($line, '[WARN]')) {
                    echo '<span class="warn">' . h($line) . '</span>' . "\n";
                } elseif (str_starts_with($line, '[INFO]') || str_starts_with($line, '[SKIP]')) {
                    echo '<span class="info">' . h($line) . '</span>' . "\n";
                } else {
                    echo h($line) . "\n";
                }
            }
        ?></div>
    </div>

    <?php if ($success): ?>
    <div class="card">
        <div class="alert alert-warning" style="margin-bottom:0;">
            <strong>Elimina questo file!</strong><br>
            Per sicurezza, elimina <code>scripts/manual-upgrade.php</code> dal server dopo l'aggiornamento.
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <footer>
        Pinakes Manual Upgrade Script
    </footer>

</div>
</body>
</html>
