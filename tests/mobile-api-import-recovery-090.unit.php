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
        $response = $controller->chunk($request->withBody($streams->createStream('{}')), new Response(), $id);
        $result = json_decode((string) $response->getBody(), true);
        $check($response->getStatusCode() === 200 && ($result['data']['complete'] ?? false) === true, "checkpoint {$failure}: retry completes the job");
        $check(($result['data']['imported'] ?? null) === 1 && ($result['data']['failed'] ?? null) === 0, "checkpoint {$failure}: counters count the record once");
        $rows = $db->query("SELECT id, luogo_pubblicazione FROM libri WHERE titolo = '{$title}' AND deleted_at IS NULL")->fetch_all(MYSQLI_ASSOC);
        $check(count($rows) === 1 && $rows[0]['luogo_pubblicazione'] === 'Recovery City', "checkpoint {$failure}: one book with complete MARC metadata");
        $check((int) $db->query("SELECT COUNT(*) FROM import_logs WHERE user_id = {$userId} AND import_type = 'marc' AND file_name = 'recovery.xml'")->fetch_row()[0] === $failure, "checkpoint {$failure}: one history entry per job");
        $check(!is_file($staged), "checkpoint {$failure}: completed job removes its source");
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
