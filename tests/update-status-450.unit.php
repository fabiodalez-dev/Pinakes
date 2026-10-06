<?php
declare(strict_types=1);

/**
 * Update status for a page whose install request a proxy dropped (#450).
 *
 * Behind a NAS's remote access the install request got a 502 after a minute
 * while PHP carried the update to its end; the page said it had failed. The
 * page now asks /admin/updates/status, so what that endpoint reads must be
 * right:
 *  - isUpdateRunning() sees the update lock held by another descriptor, and
 *    sees it free again, without waiting and without taking it;
 *  - lastUpdateAttempt() returns the newest attempt, skipping backup rows,
 *    with its status and error;
 *  - the install request releases the session before the update runs, or the
 *    page's polls (same session) would wait for the whole install.
 *
 * Run: php tests/update-status-450.unit.php
 */

use App\Support\Updater;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — Updater needs a connection: {$e->getMessage()}\n");
    exit(1);
}

$updater = new Updater($db);
$lockFile = $root . '/storage/cache/update.lock';

echo "A. the update lock, as another request holds it\n";
if (!is_dir(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0755, true);
}
$holder = fopen($lockFile, 'c');
$check($holder !== false, 'the lock file opens');
$check(flock($holder, LOCK_EX | LOCK_NB), 'this test takes the lock, as an update does');
$started = microtime(true);
$check($updater->isUpdateRunning() === true, 'a held lock reads as an update running');
$check(microtime(true) - $started < 1.0, 'the probe does not wait for the lock');
flock($holder, LOCK_UN);
$check($updater->isUpdateRunning() === false, 'a released lock reads as no update running');
// The probe never keeps the lock: the update can still take it at once.
$check(flock($holder, LOCK_EX | LOCK_NB), 'after the probe, the lock is free for the update');
flock($holder, LOCK_UN);
fclose($holder);

echo "B. the latest attempt in update_logs\n";
$marker = 'u450-' . bin2hex(random_bytes(3));
$ids = [];
try {
    // The table is created by the first logged update; make sure it is there.
    $updater->getUpdateHistory(1);
    $db->query("CREATE TABLE IF NOT EXISTS `update_logs` (
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
    $insert = static function (string $to, string $status, ?string $error) use ($db, &$ids): int {
        $stmt = $db->prepare("INSERT INTO update_logs (from_version, to_version, status, error_message) VALUES ('0.0.1', ?, ?, ?)");
        $stmt->bind_param('sss', $to, $status, $error);
        $stmt->execute();
        $ids[] = $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $failedId = $insert('9.9.' . random_int(10, 99), 'failed', 'copy failed ' . $marker);
    $last = $updater->lastUpdateAttempt();
    $check(($last['id'] ?? 0) === $failedId, 'the newest attempt is returned');
    $check(($last['status'] ?? '') === 'failed', 'with its status');
    $check(($last['error'] ?? '') === 'copy failed ' . $marker, 'and its error');
    // A backup is logged in the same table but is not an update attempt.
    $insert('backup', 'completed', null);
    $check(($updater->lastUpdateAttempt()['id'] ?? 0) === $failedId, 'a later backup row is skipped');
    $completedId = $insert('9.9.' . random_int(10, 99), 'completed', null);
    $last = $updater->lastUpdateAttempt();
    $check(($last['id'] ?? 0) === $completedId && $last['status'] === 'completed' && $last['error'] === '', 'a completed attempt reads as completed, with no error');
} finally {
    if ($ids !== []) {
        $db->query('DELETE FROM update_logs WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    }
}

echo "B2. the outcome of the latest run\n";
$outcomeFile = $root . '/storage/cache/update-outcome.json';
$saved = is_file($outcomeFile) ? file_get_contents($outcomeFile) : null;
try {
    $record = new ReflectionMethod(Updater::class, 'recordUpdateOutcome');
    $record->setAccessible(true);
    $record->invoke($updater, ['success' => false, 'error' => 'no space ' . $marker]);
    $outcome = $updater->lastUpdateOutcome();
    $check(($outcome['success'] ?? null) === false && ($outcome['error'] ?? '') === 'no space ' . $marker, 'a failed run is read back with its error');
    $first = (float) ($outcome['at'] ?? 0);
    usleep(10000);
    $record->invoke($updater, ['success' => true, 'error' => null]);
    $outcome = $updater->lastUpdateOutcome();
    $check(($outcome['success'] ?? null) === true && (float) $outcome['at'] > $first, 'a later run replaces it, with a later time');
    $check(($outcome['version'] ?? '') === $updater->getCurrentVersion(), 'it names the version installed when it ended');
    $check(glob($outcomeFile . '.*.tmp') === [], 'the write leaves no temp file behind');
    $check(($outcome['attempt'] ?? null) === '', 'a run started without an identifier records none');
    $attempt = bin2hex(random_bytes(16));
    $updater->setAttemptId($attempt);
    $record->invoke($updater, ['success' => true, 'error' => null]);
    $check(($updater->lastUpdateOutcome()['attempt'] ?? '') === $attempt, 'the outcome carries the identifier the page sent');
    $updater->setAttemptId('../../etc/passwd');
    $record->invoke($updater, ['success' => true, 'error' => null]);
    $check(($updater->lastUpdateOutcome()['attempt'] ?? null) === '', 'an identifier that is not 32 hex characters is dropped');
    $updater->setAttemptId('');
    file_put_contents($outcomeFile, 'not json');
    $check($updater->lastUpdateOutcome() === null, 'an unreadable file reads as no outcome');
    // Written before the lock is released, inside performUpdateFromFile().
    $src = (string) file_get_contents($root . '/app/Support/Updater.php');
    $body = substr($src, (int) strpos($src, 'public function performUpdateFromFile'));
    $recordAt = strpos($body, '$this->recordUpdateOutcome(');
    $unlockAt = strpos($body, 'flock($lockHandle, LOCK_UN)');
    $check($recordAt !== false && $unlockAt !== false && $recordAt < $unlockAt, 'the outcome is written while the lock is still held');
} finally {
    if ($saved !== null) {
        file_put_contents($outcomeFile, $saved);
    } else {
        @unlink($outcomeFile);
    }
}

echo "C. the install request lets the status polls through\n";
// The polls come from the same browser session, and PHP keeps a session
// locked until the request that opened it closes it.
$controller = (string) file_get_contents($root . '/app/Controllers/UpdateController.php');
$install = substr($controller, (int) strpos($controller, 'public function installManualUpdate'));
$install = substr($install, 0, (int) strpos($install, "\n    }\n") + 6);
$closeAt = strpos($install, 'session_write_close()');
$runAt = strpos($install, '->performUpdateFromFile(');
$check($closeAt !== false && $runAt !== false && $closeAt < $runAt, 'the session is released before the update runs');
$check(strpos($install, "unset(\$_SESSION['manual_update_path']") < $closeAt, 'after the pending package is cleared, so the clearing is saved');

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
