<?php
declare(strict_types=1);

/**
 * The two legacy CLI scripts behave like the cron entry points.
 *
 *  A. scripts/maintenance.php purged completed/cancelled reservations by
 *     created_at. A reservation that waited in the queue for more than 30
 *     days was deleted on the very run after its promotion, before its
 *     "book available" e-mail (retried while notifica_inviata = 0) went out.
 *     The purge now counts from the day the reservation was closed. That
 *     alone keeps every row the notification retry can still read (it gives
 *     up seven days after the promotion); one left unsent for good goes with
 *     the others, 30 days after it was closed.
 *  B. Neither script loaded the installed languages, so their e-mails fell
 *     back to it_IT whatever the installation locale: both now bootstrap
 *     I18n from the database as cron/full-maintenance.php does.
 *
 * Each script runs for real, as a CLI process, from a scratch tree (its own
 * .env, lock and marker files) that points at the database in E2E_DB_* (or
 * the worktree .env). Only rows this run creates are asserted and removed.
 *
 * Run: php tests/legacy-maintenance-scripts.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$useSocket = $socket !== '' && file_exists($socket);
$dbHost = $useSocket ? 'localhost' : (getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'));
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
try {
    $db = $useSocket
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$title = 'ZZ_LEGACYCRON_' . $run;
$email = 'zzlegacycron+' . $run . '@legacycron.test.local';
$tree = sys_get_temp_dir() . '/pinakes-legacycron-' . $run;

$removeTree = static function (string $dir) use (&$removeTree): void {
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($dir . '/' . $entry);
        }
    }
    @rmdir($dir);
};
$cleanup = static function () use ($db, $title, $email, $tree, $removeTree): void {
    $removeTree($tree);
    foreach ([
        ['DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo = ?', $title],
        ['DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo = ?', $title],
        ['DELETE FROM libri WHERE titolo = ?', $title],
        ['DELETE FROM utenti WHERE email = ?', $email],
    ] as [$sql, $value]) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $stmt->close();
    }
};

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$fatal = null;
try {
    $cleanup();
    // ── Scratch tree: the scripts resolve everything from their own folder ──
    foreach (['/scripts', '/config', '/storage/cache', '/storage/logs'] as $dir) {
        mkdir($tree . $dir, 0700, true);
    }
    symlink($root . '/vendor', $tree . '/vendor');
    foreach (['scripts/maintenance.php', 'scripts/check-expired-reservations.php', 'scripts/_db_bootstrap.php', 'config/settings.php'] as $file) {
        copy($root . '/' . $file, $tree . '/' . $file);
    }
    // maintenance.php reads .env with its own parser (raw values), the other
    // script through phpdotenv (quoted values): one file per reader.
    $envLines = static function (bool $quoted) use ($dbHost, $dbPort, $dbUser, $dbPass, $dbName, $useSocket, $socket): string {
        $pairs = ['DB_HOST' => $dbHost, 'DB_PORT' => (string) $dbPort, 'DB_USER' => $dbUser, 'DB_PASS' => $dbPass, 'DB_NAME' => $dbName];
        if ($useSocket) {
            $pairs['DB_SOCKET'] = $socket;
        }
        $out = '';
        foreach ($pairs as $key => $value) {
            $out .= $key . '=' . ($quoted ? '"' . addcslashes($value, "\\\"\$") . '"' : $value) . "\n";
        }
        return $out;
    };
    // After the script: did it load the installed languages, and which locale
    // would its e-mails fall back to.
    $probe = 'register_shutdown_function(static function (): void {'
        . ' if (!class_exists(\App\Support\I18n::class, false)) { echo "\nPROBE languages=0 locale=\n"; return; }'
        . ' $loaded = (new ReflectionProperty(\App\Support\I18n::class, "languagesLoadedFromDb"))->getValue();'
        . ' echo "\nPROBE languages=" . ($loaded ? 1 : 0) . " locale=" . \App\Support\I18n::getInstallationLocale() . "\n";'
        . ' }); require $argv[1];';
    $runScript = static function (string $script, bool $quotedEnv) use ($tree, $envLines, $probe): array {
        file_put_contents($tree . '/.env', $envLines($quotedEnv));
        // DB_* must come from the scratch .env alone.
        $cleanEnv = array_filter(getenv(), static fn (string $name): bool => !str_starts_with($name, 'DB_'), ARRAY_FILTER_USE_KEY);
        $proc = proc_open([PHP_BINARY, '-r', $probe, $tree . '/' . $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tree, $cleanEnv);
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => ''];
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($proc), 'out' => (string) $out];
    };

    // ── Fixtures ────────────────────────────────────────────────────────────
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 1, 1)");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $card = 'ZZLC' . strtoupper($run);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Legacy', 'Cron', ?, '', 'standard', 'attivo')");
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();

    /** A closed reservation: queued $createdDaysAgo days ago, closed $closedDaysAgo days ago. */
    $reservation = static function (string $state, int $createdDaysAgo, int $closedDaysAgo, int $notified) use ($db, $bookId, $userId): int {
        $stmt = $db->prepare(
            "INSERT INTO prenotazioni (libro_id, utente_id, stato, notifica_inviata, data_prenotazione, created_at)
             VALUES (?, ?, ?, ?, NOW() - INTERVAL ? DAY, NOW() - INTERVAL ? DAY)"
        );
        $stmt->bind_param('iisiii', $bookId, $userId, $state, $notified, $createdDaysAgo, $createdDaysAgo);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        $db->query("UPDATE prenotazioni SET updated_at = NOW() - INTERVAL {$closedDaysAgo} DAY WHERE id = {$id}");
        return $id;
    };
    $exists = static fn (int $id): bool => $db->query("SELECT 1 FROM prenotazioni WHERE id = {$id}")->num_rows === 1;

    $justPromoted = $reservation('completata', 40, 0, 0);
    $closedRecently = $reservation('completata', 40, 5, 1);
    $unsentInRetryWindow = $reservation('completata', 40, 6, 0);
    $unsentForLong = $reservation('completata', 80, 40, 0);
    $oldNotified = $reservation('completata', 80, 40, 1);
    $oldCancelled = $reservation('annullata', 80, 40, 0);
    $defaultLocale = (string) ($db->query('SELECT code FROM languages WHERE is_default = 1 AND is_active = 1 LIMIT 1')->fetch_row()[0] ?? '');

    // ── A. scripts/maintenance.php ──────────────────────────────────────────
    echo "A. scripts/maintenance.php\n";
    $maintenance = $runScript('scripts/maintenance.php', quotedEnv: false);
    $check($maintenance['code'] === 0 && str_contains($maintenance['out'], 'MAINTENANCE COMPLETED'),
        'A1 the script runs to its end (exit ' . $maintenance['code'] . ')');
    if ($maintenance['code'] !== 0) {
        echo $maintenance['out'] . "\n";
    }
    $check($exists($justPromoted), 'A2 a reservation promoted today after 40 days in the queue is kept: its notification is still to go');
    $check($exists($closedRecently), 'A3 a reservation closed five days ago is kept, however long ago it was made');
    $check($exists($unsentInRetryWindow), 'A3b a reservation promoted six days ago is kept while its notification can still be retried');
    $check(!$exists($unsentForLong), 'A4 a completed reservation closed 40 days ago is purged even though its notification never went out: the retry gave up long before');
    $check(!$exists($oldNotified), 'A5 a completed, notified reservation closed 40 days ago is purged');
    $check(!$exists($oldCancelled), 'A6 a reservation cancelled 40 days ago is purged');
    $check(str_contains($maintenance['out'], 'PROBE languages=1'), 'A7 the installed languages are loaded before any e-mail is written');
    $check($defaultLocale === '' || str_contains($maintenance['out'], 'locale=' . $defaultLocale . "\n"),
        "A8 the script's e-mails use the installation locale ({$defaultLocale})");

    // ── B. scripts/check-expired-reservations.php ───────────────────────────
    echo "B. scripts/check-expired-reservations.php\n";
    $expired = $runScript('scripts/check-expired-reservations.php', quotedEnv: true);
    $check($expired['code'] === 0 && str_contains($expired['out'], 'Completed.'),
        'B1 the script runs to its end (exit ' . $expired['code'] . ')');
    if ($expired['code'] !== 0) {
        echo $expired['out'] . "\n";
    }
    $check(str_contains($expired['out'], 'PROBE languages=1'), 'B2 the installed languages are loaded before any e-mail is written');
    $check($defaultLocale === '' || str_contains($expired['out'], 'locale=' . $defaultLocale . "\n"),
        "B3 the script's e-mails use the installation locale ({$defaultLocale})");
} catch (Throwable $e) {
    $fatal = $e;
} finally {
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "cleanup failed: {$cleanupError->getMessage()}\n");
    }
    $db->close();
}

if ($fatal !== null) {
    fwrite(STDERR, 'FAIL: ' . $fatal->getMessage() . ' @ ' . basename($fatal->getFile()) . ':' . $fatal->getLine() . "\n");
    exit(1);
}
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
