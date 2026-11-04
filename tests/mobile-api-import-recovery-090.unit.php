<?php
declare(strict_types=1);

/** Real-DB fault injection: MARC checkpoints and mobile patron-upload retention. */
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\Plugins\\MobileApi\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/storage/plugins/mobile-api/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
require_once $root . '/storage/plugins/mobile-api/src/Support/Delegation.php';

use App\Plugins\MobileApi\Staff090\Controllers\StaffMarcController;
use App\Plugins\MobileApi\Staff090\Controllers\StaffUserImportController;
use App\Support\HookManager;
use App\Support\Hooks;
use App\Support\Marc\MarcBookImporter;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\ImportJobStore;
use App\Support\Features;
use App\Support\UserImport;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

final class ImportCheckpointFaultDb extends mysqli
{
    private int $remaining = 0;

    public function failCheckpoint(int $number): void
    {
        $this->remaining = $number;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->remaining > 0 && str_starts_with($query, 'UPDATE mobile_import_jobs SET state = ?') && --$this->remaining === 0) {
            throw new RuntimeException('Injected checkpoint failure');
        }
        return parent::prepare($query);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$db = new ImportCheckpointFaultDb($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};
$run = bin2hex(random_bytes(5));
$email = "zz-import-recovery-{$run}@example.invalid";
$files = [];
$jobs = [];
$userId = 0;
$priorSession = $_SESSION ?? null;
$requests = new ServerRequestFactory();
$streams = new StreamFactory();
$store = new ImportJobStore($db);
// book.save.after reaches a counting listener only, never the installed plugins.
$hooksProperty = new ReflectionProperty(Hooks::class, 'instance');
$hooksBefore = $hooksProperty->getValue();
$hookCalls = [];
$hooks = new HookManager($db);
(new ReflectionProperty($hooks, 'loadedHooks'))->setValue($hooks, true);
$hooks->addHook('book.save.after', static function ($bookId) use (&$hookCalls): void {
    $hookCalls[] = (int) $bookId;
});
Hooks::init($hooks);
try {
    // Missing schema is a failed mandatory gate, not a skipped test.
    $db->query('SELECT id FROM mobile_import_jobs LIMIT 0');
    $db->query("INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, codice_tessera) VALUES ('Recovery', 'Test', '{$email}', 'x', 'admin', 'attivo', 1, 1, 'RC{$run}')");
    $userId = (int) $db->insert_id;
    $_SESSION = ['user' => ['id' => $userId, 'tipo_utente' => 'admin']];
    $base = static fn (string $path) => $requests->createServerRequest('POST', $path)->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $userId, 'tipo_utente' => 'admin']);
    Features::override('marc', true);
    Features::override('suppliers', false);
    $controller = new StaffMarcController($db);

    // Fail before the book commit; after the book commit; while saving the
    // history; and after the history commit but before the completed job save.
    foreach ([1, 2, 3, 4] as $failure) {
        $title = "ZZ import recovery {$run} {$failure}";
        $xml = '<?xml version="1.0" encoding="UTF-8"?><collection xmlns="http://www.loc.gov/MARC21/slim"><record><leader>00000nam a2200000 a 4500</leader><datafield tag="245" ind1="0" ind2="0"><subfield code="a">' . $title . '</subfield></datafield><datafield tag="264" ind1=" " ind2="1"><subfield code="a">Recovery City</subfield></datafield></record></collection>';
        $file = tempnam(sys_get_temp_dir(), 'marc_recovery_');
        $files[] = $file;
        file_put_contents($file, $xml);
        $request = $base('/api/v1/staff/marc/imports')->withParsedBody(['download_covers' => false])->withUploadedFiles([
            'file' => new UploadedFile($file, 'recovery.xml', 'application/xml', strlen($xml), UPLOAD_ERR_OK),
        ]);
        $response = $controller->create($request, new Response());
        $upload = json_decode((string) $response->getBody(), true);
        $check($response->getStatusCode() === 201, "checkpoint {$failure}: upload accepted");
        $id = $upload['data']['id'];
        $jobs[] = $id;
        $job = $store->find($id, $userId, true);
        $staged = $job['state']['file_path'];
        $files[] = $staged;
        $request = $base('/api/v1/staff/marc/imports/' . $id . '/chunk')->withHeader('Content-Type', 'application/json')->withBody($streams->createStream('{}'));
        $db->failCheckpoint($failure);
        $response = $controller->chunk($request, new Response(), $id);
        $check($response->getStatusCode() === 500, "checkpoint {$failure}: failed save is reported");
        $count = (int) $db->query("SELECT COUNT(*) FROM libri WHERE titolo = '{$title}' AND deleted_at IS NULL")->fetch_row()[0];
        $check($count === ($failure === 1 ? 0 : 1), "checkpoint {$failure}: book and checkpoint commit together");
        $check(is_file($staged), "checkpoint {$failure}: source survives the failed request, including the final row");
        $hookCalls = [];
        $response = $controller->chunk($request->withBody($streams->createStream('{}')), new Response(), $id);
        $result = json_decode((string) $response->getBody(), true);
        $check($response->getStatusCode() === 200 && ($result['data']['complete'] ?? false) === true, "checkpoint {$failure}: retry completes the job");
        $check(($result['data']['imported'] ?? null) === 1 && ($result['data']['failed'] ?? null) === 0, "checkpoint {$failure}: counters count the record once");
        $rows = $db->query("SELECT id, luogo_pubblicazione FROM libri WHERE titolo = '{$title}' AND deleted_at IS NULL")->fetch_all(MYSQLI_ASSOC);
        $check(count($rows) === 1 && $rows[0]['luogo_pubblicazione'] === 'Recovery City', "checkpoint {$failure}: one book with complete MARC metadata");
        $check((int) $db->query("SELECT COUNT(*) FROM import_logs WHERE user_id = {$userId} AND import_type = 'marc' AND file_name = 'recovery.xml'")->fetch_row()[0] === $failure, "checkpoint {$failure}: one history entry per job");
        $check(!is_file($staged), "checkpoint {$failure}: completed job removes its source");
        // The retry that completes the book (a new insert, or the resume of
        // the committed one) runs book.save.after; a retry past it does not.
        $check(count($hookCalls) === ($failure <= 2 ? 1 : 0) && ($hookCalls === [] || $hookCalls[0] === (int) $rows[0]['id']), "checkpoint {$failure}: the retry runs book.save.after only when it completes the book");
    }

    // A resumed record whose cover the first attempt already attached: fill
    // reads the book as it is now and skips, instead of fetching (and
    // orphaning the first file) because of the snapshot taken before.
    $coverTitle = "ZZ import recovery {$run} cover";
    $db->query("INSERT INTO libri (titolo, copertina_url) VALUES ('{$coverTitle}', '/uploads/copertine/zz-recovery-{$run}.jpg')");
    $coverBook = (int) $db->insert_id;
    $snapshot = $db->query("SELECT * FROM libri WHERE id = {$coverBook}")->fetch_assoc();
    $snapshot['copertina_url'] = '';
    $coverCounters = ['covers' => 0];
    $coverWarnings = [];
    (new MarcBookImporter($db, MarcBookImporter::MODE_FILL, true))->resume(
        ['titolo' => $coverTitle, 'copertina_url' => 'not-a-cover-url'],
        ['result' => ['id' => $coverBook, 'action' => 'updated', 'title' => $coverTitle], 'existing' => $snapshot],
        $coverCounters,
        $coverWarnings
    );
    $check($coverWarnings === [] && $coverCounters['covers'] === 0 && $db->query("SELECT copertina_url FROM libri WHERE id = {$coverBook}")->fetch_row()[0] === "/uploads/copertine/zz-recovery-{$run}.jpg",
        'a resumed fill keeps the cover the first attempt attached and fetches none');

    // Patron import: every job save of a one-row run fails once in turn. The
    // retry ends with one account, one history entry and no staged file.
    Features::override('user_import', true);
    $users = new StaffUserImportController($db);
    $injected = 0;
    for ($failure = 1; $failure <= 10; $failure++) {
        $patronEmail = "zz-import-recovery-{$run}-p{$failure}@example.invalid";
        $csv = "nome;cognome;email\nPatron;Recovery;{$patronEmail}\n";
        $file = tempnam(sys_get_temp_dir(), 'patron_recovery_');
        $files[] = $file;
        file_put_contents($file, $csv);
        $name = "patron-recovery-{$run}-{$failure}.csv";
        $response = $users->create($base('/api/v1/staff/user-imports')->withParsedBody(['delimiter' => ';'])->withUploadedFiles([
            'file' => new UploadedFile($file, $name, 'text/csv', strlen($csv), UPLOAD_ERR_OK),
        ]), new Response());
        $upload = json_decode((string) $response->getBody(), true);
        $check($response->getStatusCode() === 201, "patron save {$failure}: upload accepted");
        $id = (string) $upload['data']['id'];
        $jobs[] = $id;
        $json = static fn (array $body) => $base('/api/v1/staff/user-imports/' . $id)->withHeader('Content-Type', 'application/json')->withBody($streams->createStream((string) json_encode($body)));
        $response = $users->start($json(['mapping' => ['nome', 'cognome', 'email'], 'send_invites' => false]), new Response(), $id);
        $check($response->getStatusCode() === 200, "patron save {$failure}: run started");
        $patronStaged = (string) ($store->find($id, $userId, true)['state']['core']['file'] ?? '');
        $files[] = $patronStaged;
        $db->failCheckpoint($failure);
        $response = $users->chunk($json([]), new Response(), $id);
        $first = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() === 200 && ($first['data']['complete'] ?? false) === true) {
            // No save left to fail: the run went through at the first try.
            $db->failCheckpoint(0);
            $check($injected >= 3, 'patron save: the insert, the final history and the completed job each were a failure point');
            break;
        }
        $injected++;
        $db->failCheckpoint(0);
        $check($response->getStatusCode() !== 200 && is_file($patronStaged), "patron save {$failure}: failed save is reported and the source is kept");
        $response = $users->chunk($json([]), new Response(), $id);
        $retry = json_decode((string) $response->getBody(), true);
        $accounts = (int) $db->query("SELECT COUNT(*) FROM utenti WHERE email = '{$patronEmail}'")->fetch_row()[0];
        $logs = (int) $db->query("SELECT COUNT(*) FROM import_logs WHERE import_type = 'users' AND file_name = '{$name}'")->fetch_row()[0];
        $check($response->getStatusCode() === 200 && ($retry['data']['complete'] ?? false) === true && ($retry['data']['created'] ?? null) === 1, "patron save {$failure}: retry completes the job with the account counted once");
        $check($accounts === 1 && $logs === 1 && !is_file($patronStaged), "patron save {$failure}: one account, one history entry, source removed");
    }

    // A repeated, identical running checkpoint within one second is success.
    $id = $store->create($userId, null, 'users', 'retention.csv', 1, 1, false, []);
    $jobs[] = $id;
    $store->setStatus($id, 'running');
    $check($store->saveChunk($id, [], 0, 'running', []) && $store->saveChunk($id, [], 0, 'running', []), 'identical running checkpoints remain successful');

    $dir = $root . '/writable/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $protected = $dir . '/user_import_recovery_' . $run . '.csv';
    $abandoned = $dir . '/user_import_abandoned_' . $run . '.csv';
    $files[] = $protected;
    $files[] = $abandoned;
    foreach ([$protected, $abandoned] as $path) {
        file_put_contents($path, "nome;email\nTest;{$email}\n");
        touch($path, time() - 7200);
    }
    $store->saveChunk($id, ['core' => ['file' => $protected]], 0, 'ready', []);
    UserImport::sweepStaleUploads($dir, db: $db);
    $check(is_file($protected) && !is_file($abandoned), 'web sweep retains a two-hour-old mobile upload and removes an abandoned one');
    $db->query("UPDATE mobile_import_jobs SET expires_at = NOW() - INTERVAL 2 HOUR WHERE id = '{$id}'");
    $store->purgeExpired();
    $check(!is_file($protected), 'expired patron jobs remove their nested core.file upload');

    $cancelFile = $dir . '/user_import_cancel_' . $run . '.csv';
    $files[] = $cancelFile;
    file_put_contents($cancelFile, 'test');
    $id = $store->create($userId, null, 'users', 'cancel.csv', 1, 1, false, ['core' => ['file' => $cancelFile]]);
    $jobs[] = $id;
    $store->cancel($store->find($id, $userId, true));
    $check(!is_file($cancelFile), 'cancel also removes a patron upload');
    $check(!$store->saveChunk($id, [], 0, 'running', []), 'a cancelled job is never mistaken for an identical running checkpoint');
    echo "SUCCESS {$checks} import recovery checks\n";
} finally {
    $db->failCheckpoint(0);
    foreach ($jobs as $id) {
        $db->query("DELETE FROM mobile_import_jobs WHERE id = '" . $db->real_escape_string($id) . "'");
    }
    // The user's history rows cascade or are deleted before the scratch user.
    if ($userId > 0) {
        $db->query("DELETE FROM import_logs WHERE user_id = {$userId}");
    }
    $rows = $db->query("SELECT id FROM libri WHERE titolo LIKE 'ZZ import recovery {$run} %'")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    if ($userId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$userId}");
    }
    $db->query("DELETE FROM import_logs WHERE import_type = 'users' AND file_name LIKE 'patron-recovery-{$run}-%'");
    $db->query("DELETE FROM utenti WHERE email LIKE 'zz-import-recovery-{$run}-p%'");
    $hooksProperty->setValue(null, $hooksBefore);
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    Features::resetOverrides();
    if ($priorSession === null) {
        unset($_SESSION);
    } else {
        $_SESSION = $priorSession;
    }
}
