<?php
declare(strict_types=1);

/**
 * Issue #140 (emeroteca review) — core entity lifecycle hooks + plugin audit events.
 *
 * The core merge/delete paths for publishers, genres and shelves now emit
 * hooks (publisher.merging / publisher.deleting / genre.merging /
 * shelf.can_delete / shelf.deleted) so plugins holding FK references to core
 * tables (e.g. emeroteca_testate.editore_id, ON DELETE SET NULL) can react
 * before the referenced row disappears. This test drives the REAL production
 * paths against the real database with a real HookManager:
 *
 *  1. publisher.merging receives (primaryId, duplicateIds) inside a real
 *     PublisherRepository::mergePublishers() run;
 *  2. a listener that throws does NOT abort the merge;
 *  3. a shelf.can_delete filter returning false blocks
 *     CollocazioneController::deleteMensola(); without the veto the delete
 *     proceeds and shelf.deleted fires;
 *  4. ActivityLog::recordEntityEvent() writes a log_modifiche row with a
 *     custom tabella, the new event labels resolve, and the book feed
 *     (tabella='libri') neither breaks nor leaks plugin rows.
 *
 * Test data uses zz_* names; cleanup is FK-safe and runs in finally.
 *
 * Run:  php tests/core-entity-hooks-140.unit.php
 */

use App\Controllers\CollocazioneController;
use App\Models\PublisherRepository;
use App\Support\ActivityLog;
use App\Support\HookManager;
use App\Support\Hooks;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? 'fabiodal_biblioteca_user');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? 'Zd10)uwziWlK'));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? 'fabiodal_biblioteca');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli(
            getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'),
            $dbUser,
            $dbPass,
            $dbName,
            (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306))
        );
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$TESTNO = 0;
$failed = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO, $failed;
    $TESTNO++;
    printf("[%02d] %s: %s\n", $TESTNO, $cond ? 'PASS' : 'FAIL', $desc);
    if (!$cond) {
        $failed++;
    }
}

$RUN = bin2hex(random_bytes(6));
$LOG_TABLE = 'zz_hooktest_entities'; // fictitious tabella for log_modifiche rows
$_SESSION = [];

// Real HookManager, but marked "runtime-loaded" so DB-registered plugin hooks
// are NOT pulled in: the test controls exactly which listeners exist.
$hookManager = new HookManager($db);
$hookManager->setPluginsLoadedRuntime();
Hooks::init($hookManager);
$resetHooks = static function () use ($hookManager): void {
    $hookManager->clearHooks();
    $hookManager->setPluginsLoadedRuntime();
};

$publisherIds = [];
$mensolaId = 0;
$scaffaleId = 0;

$insertPublisher = static function (string $suffix) use ($db, $RUN, &$publisherIds): int {
    $name = "zz_hooktest_{$RUN}_{$suffix}";
    $stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();
    $id = (int) $db->insert_id;
    $publisherIds[] = $id;
    return $id;
};

try {
    // ---------------------------------------------------------------
    // 1. publisher.merging receives the right ids during a real merge
    // ---------------------------------------------------------------
    $primary = $insertPublisher('primary');
    $dup = $insertPublisher('dup');

    $received = null;
    Hooks::add('publisher.merging', function (int $primaryId, array $duplicateIds) use (&$received): void {
        $received = ['primary' => $primaryId, 'duplicates' => $duplicateIds];
    });

    $repo = new PublisherRepository($db);
    $mergedId = $repo->mergePublishers([$primary, $dup], $primary);

    check($mergedId === $primary, 'mergePublishers keeps the requested primary');
    check(is_array($received), 'publisher.merging listener was invoked during the merge');
    check(($received['primary'] ?? null) === $primary, 'listener received the primary id');
    check(($received['duplicates'] ?? null) === [$dup], 'listener received the duplicate ids');

    $res = $db->query("SELECT id FROM editori WHERE id = {$dup}");
    check($res->num_rows === 0, 'duplicate publisher row was deleted by the merge');
    $res = $db->query("SELECT id FROM editori WHERE id = {$primary}");
    check($res->num_rows === 1, 'primary publisher row survived the merge');

    // ---------------------------------------------------------------
    // 2. a throwing listener must NOT abort the merge
    // ---------------------------------------------------------------
    $resetHooks();
    $primary2 = $insertPublisher('primary2');
    $dup2 = $insertPublisher('dup2');

    Hooks::add('publisher.merging', function (): void {
        throw new \RuntimeException('broken emeroteca listener');
    });

    $mergedId2 = $repo->mergePublishers([$primary2, $dup2], $primary2);
    check($mergedId2 === $primary2, 'merge succeeds even when a publisher.merging listener throws');
    $res = $db->query("SELECT id FROM editori WHERE id = {$dup2}");
    check($res->num_rows === 0, 'duplicate was still deleted despite the broken listener');

    // ---------------------------------------------------------------
    // 2b. publisher.deleting fires from PublisherRepository::delete()
    // ---------------------------------------------------------------
    $resetHooks();
    $victim = $insertPublisher('victim');
    $deletingSeen = null;
    Hooks::add('publisher.deleting', function (int $id) use (&$deletingSeen): void {
        $deletingSeen = $id;
    });
    check($repo->delete($victim) === true, 'PublisherRepository::delete succeeds');
    check($deletingSeen === $victim, 'publisher.deleting received the deleted publisher id');

    // ---------------------------------------------------------------
    // 3. shelf.can_delete=false blocks deleteMensola; shelf.deleted fires
    // ---------------------------------------------------------------
    $resetHooks();
    $db->query("INSERT INTO scaffali (codice, nome, lettera, descrizione) VALUES ('zzZ', 'zz_hooktest_{$RUN}', 'Z', 'zz_hooktest')");
    $scaffaleId = (int) $db->insert_id;
    // Level 990+: far above any real shelf, avoids the UNIQUE(scaffale_id, numero_livello).
    $db->query("INSERT INTO mensole (scaffale_id, numero_livello, descrizione) VALUES ({$scaffaleId}, 990, 'zz_hooktest_{$RUN}')");
    $mensolaId = (int) $db->insert_id;

    $vetoAsked = null;
    Hooks::add('shelf.can_delete', function (bool $value, int $id) use (&$vetoAsked): bool {
        $vetoAsked = $id;
        return false; // pretend the mensola holds emeroteca issues
    });

    $controller = new CollocazioneController();
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/placement/mensole/delete');
    $_SESSION = [];
    $resp = $controller->deleteMensola($request, (new ResponseFactory())->createResponse(), $db, $mensolaId);

    check($resp->getStatusCode() === 302, 'deleteMensola redirects (302) when vetoed');
    check($vetoAsked === $mensolaId, 'shelf.can_delete filter received the mensola id');
    check(isset($_SESSION['error_message']) && !isset($_SESSION['success_message']), 'veto path sets the error message, not success');
    $res = $db->query("SELECT id FROM mensole WHERE id = {$mensolaId}");
    check($res->num_rows === 1, 'vetoed mensola still exists');

    // Without the veto the delete proceeds and shelf.deleted fires.
    $resetHooks();
    $deletedSeen = null;
    Hooks::add('shelf.deleted', function (int $id) use (&$deletedSeen): void {
        $deletedSeen = $id;
    });
    $_SESSION = [];
    $resp = $controller->deleteMensola($request, (new ResponseFactory())->createResponse(), $db, $mensolaId);
    check($resp->getStatusCode() === 302 && isset($_SESSION['success_message']), 'delete proceeds without veto');
    $res = $db->query("SELECT id FROM mensole WHERE id = {$mensolaId}");
    check($res->num_rows === 0, 'mensola row is gone after the un-vetoed delete');
    check($deletedSeen === $mensolaId, 'shelf.deleted action received the mensola id');
    $mensolaId = 0; // already deleted, skip cleanup

    // ---------------------------------------------------------------
    // 4. recordEntityEvent — custom tabella, labels, feed safety
    // ---------------------------------------------------------------
    $resetHooks();
    $recordId = random_int(100000, 999999);
    $ok = ActivityLog::recordEntityEvent(
        $db,
        $LOG_TABLE,
        $recordId,
        'periodical.created',
        [],
        ['titolo' => "zz Testata {$RUN}", 'editore_id' => 7],
        'inserimento',
        'emeroteca'
    );
    check($ok === true, 'recordEntityEvent returns true for a valid plugin event');

    $stmt = $db->prepare('SELECT * FROM log_modifiche WHERE tabella = ? AND record_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('si', $LOG_TABLE, $recordId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    check(is_array($row), 'log_modifiche row exists with the custom tabella');
    check(($row['azione'] ?? '') === 'inserimento', 'azione stored as requested');
    $decoded = is_array($row) ? ActivityLog::decodeRow($row) : [];
    check(($decoded['type'] ?? '') === 'plugin', 'decoded row carries type=plugin');
    check(($decoded['event'] ?? '') === 'periodical.created', 'decoded row carries the plugin event');
    check(($decoded['meta']['source'] ?? '') === 'emeroteca', 'decoded row carries the source');
    check(
        in_array(['field' => 'titolo', 'before' => null, 'after' => "zz Testata {$RUN}"], $decoded['changes'] ?? [], true),
        'field diff includes the plugin entity title'
    );

    // All the announced emeroteca labels resolve.
    $labels = [
        'periodical.created' => 'Testata creata',
        'periodical.updated' => 'Testata aggiornata',
        'periodical.deleted' => 'Testata eliminata',
        'issue.created' => 'Fascicolo creato',
        'issue.updated' => 'Fascicolo aggiornato',
        'issue.deleted' => 'Fascicolo eliminato',
        'issue.pdf_visibility' => 'Visibilità PDF fascicolo cambiata',
        'issue.claimed' => 'Fascicolo sollecitato al fornitore',
        'subscription.created' => 'Abbonamento creato',
        'subscription.updated' => 'Abbonamento aggiornato',
        'subscription.deleted' => 'Abbonamento eliminato',
    ];
    $allResolve = true;
    foreach ($labels as $event => $expected) {
        if (ActivityLog::eventLabel($event) !== $expected) {
            $allResolve = false;
            fwrite(STDERR, "  label mismatch for {$event}: got '" . ActivityLog::eventLabel($event) . "'\n");
        }
    }
    check($allResolve, 'all 11 emeroteca event labels resolve via eventLabel()');
    check(ActivityLog::typeLabel('plugin') === 'Eventi plugin', "typeLabel('plugin') resolves");
    check(in_array('plugin', ActivityLog::TYPES, true), "'plugin' is a valid ActivityLog type");

    // Guard-rails: invalid input is refused without touching the table.
    check(ActivityLog::recordEntityEvent($db, $LOG_TABLE, 0, 'issue.created') === false, 'recordId <= 0 is refused');
    check(ActivityLog::recordEntityEvent($db, 'libri', $recordId, 'issue.created') === false, "tabella 'libri' is reserved and refused");
    check(ActivityLog::recordEntityEvent($db, 'bad-name; DROP', $recordId, 'issue.created') === false, 'malformed tabella is refused');
    check(ActivityLog::recordEntityEvent($db, $LOG_TABLE, $recordId, 'issue.created', [], [], 'nuke') === false, 'unknown azione is refused');

    // SYSTEM_OPERATOR must store a NULL operator.
    $_SESSION = ['user' => ['id' => 999999999]]; // would be picked up as fallback
    $sysRecord = $recordId + 1;
    check(
        ActivityLog::recordEntityEvent($db, $LOG_TABLE, $sysRecord, 'issue.claimed', [], [], 'aggiornamento', 'emeroteca', ActivityLog::SYSTEM_OPERATOR) === true,
        'system-operator event is recorded'
    );
    $_SESSION = [];
    $stmt = $db->prepare('SELECT utente_id FROM log_modifiche WHERE tabella = ? AND record_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('si', $LOG_TABLE, $sysRecord);
    $stmt->execute();
    $sysRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    check(is_array($sysRow) && $sysRow['utente_id'] === null, 'SYSTEM_OPERATOR is stored as NULL utente_id');

    // The book feed must neither break nor leak plugin rows.
    $feed = ActivityLog::recent($db, 1, 12);
    $leaked = false;
    foreach ($feed['items'] as $item) {
        if (($item['tabella'] ?? 'libri') !== 'libri') {
            $leaked = true;
        }
    }
    check(!$leaked, 'ActivityLog::recent() returns only tabella=libri rows (no plugin leak, no crash)');
    $forBook = ActivityLog::forBook($db, $recordId, 1, 5);
    $leaked = false;
    foreach ($forBook['items'] as $item) {
        if (($item['type'] ?? '') === 'plugin') {
            $leaked = true;
        }
    }
    check(!$leaked, 'ActivityLog::forBook() on a colliding record_id does not surface plugin rows');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    // FK-safe cleanup of this run's rows only.
    try {
        $resetHooks();
        $stmt = $db->prepare('DELETE FROM log_modifiche WHERE tabella = ?');
        $stmt->bind_param('s', $LOG_TABLE);
        $stmt->execute();
        $stmt->close();
        if (!empty($publisherIds)) {
            $ids = implode(',', array_map('intval', $publisherIds));
            $db->query("DELETE FROM editori WHERE id IN ({$ids})");
        }
        if ($mensolaId > 0) {
            $db->query("DELETE FROM mensole WHERE id = {$mensolaId}");
        }
        if ($scaffaleId > 0) {
            $db->query("DELETE FROM scaffali WHERE id = {$scaffaleId}");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'cleanup warning: ' . $e->getMessage() . "\n");
    }
}

printf("\n%s — %d/%d checks passed\n", $failed === 0 ? 'SUCCESS' : 'FAILURE', $TESTNO - $failed, $TESTNO);
exit($failed === 0 ? 0 : 1);
