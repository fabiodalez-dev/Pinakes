<?php
declare(strict_types=1);

/**
 * Mobile API 1.10.0 (Pinakes 0.9.0): the id a create endpoint answers is the
 * row that request inserted, read from the core action, never guessed from
 * the table (MAX(id), or the rows that appeared between two reads, would also
 * name a row another operator added at the same moment).
 *
 *   A. LibraryCalendarController::store() / storeCustom() / importIcs()
 *      expose the rows they inserted (createdId() / createdIds()); a refused
 *      call exposes none.
 *   B. CirculationSettingsController::createType() exposes createdTypeId().
 *   C. The API create endpoints answer exactly those rows.
 *
 * Runs against the REAL database of the checkout and only touches rows it
 * creates (descriptions / names / codes containing zzcid).
 *
 * Run: php tests/mobile-api-created-ids-090.unit.php
 */

use App\Controllers\Admin\LibraryCalendarController;
use App\Controllers\CirculationSettingsController;
use App\Plugins\MobileApi\Staff090\Controllers\CirculationRulesController;
use App\Plugins\MobileApi\Staff090\Controllers\ClosuresCalendarController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Services\CirculationPolicy;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\Holidays\CustomHolidays;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

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
// DelegationOutcome lives in Delegation.php.
require_once $root . '/storage/plugins/mobile-api/src/Support/Delegation.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable: {$e->getMessage()}\n");
    exit(1);
}
if (!(new CirculationPolicy($db))->schemaReady() || !CustomHolidays::schemaReady($db)) {
    fwrite(STDERR, "FAIL: the 0.9.0 circulation / calendar schema is missing on this database\n");
    exit(1);
}
// The API create endpoints store their Idempotency-Key in the plugin's own
// table; a database where the plugin never ran (the CI schema jobs) lacks it.
require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';
$pluginSchema = (new \App\Plugins\MobileApi\MobileApiPlugin($db, new \App\Support\HookManager($db)))->ensureSchema();
if ($pluginSchema['failed'] !== []) {
    fwrite(STDERR, 'FAIL: Mobile API schema: ' . implode(', ', $pluginSchema['failed']) . "\n");
    exit(1);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}
// The operator: an administrator of this run's own, since the stored keys
// reference utenti(id) and a database built from schema.sql has no user 1.
$operatorTag = 'zzcidop' . bin2hex(random_bytes(3));
$opStmt = $db->prepare(
    "INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata) VALUES ('Op', 'Zz', ?, 'x', ?, 'admin', 'attivo', 1)"
);
$opEmail = $operatorTag . '@example.test';
$opStmt->bind_param('ss', $opEmail, $operatorTag);
$opStmt->execute();
$operatorId = (int) $db->insert_id;
$opStmt->close();
// The core actions are reserved to Permissions::mayConfigure().
$_SESSION['user'] = ['id' => $operatorId, 'tipo_utente' => 'admin', 'stato' => 'attivo'];
Features::override('closures_calendar', true);
Features::override('circulation_rules', true);

$run = 'zzcid' . bin2hex(random_bytes(3));
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$scalar = static function (string $sql, string $types, array $params) use ($db): ?int {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row === null ? null : (int) $row[0];
};
$ids = static function (string $sql, string $like) use ($db): array {
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $out = array_map(static fn (array $r): int => (int) $r[0], $stmt->get_result()->fetch_all());
    $stmt->close();
    sort($out);
    return $out;
};
$form = static fn (string $path, array $body) => (new ServerRequestFactory())->createServerRequest('POST', $path)
    ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
    ->withParsedBody($body);
$apiRequest = static fn (string $path, array $json, string $key) => (new ServerRequestFactory())->createServerRequest('POST', $path)
    ->withHeader('Content-Type', 'application/json')
    ->withHeader('Idempotency-Key', $key)
    ->withBody((new StreamFactory())->createStream((string) json_encode($json)))
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $operatorId]);
$payload = static function (\Psr\Http\Message\ResponseInterface $r): array {
    $r->getBody()->rewind();
    return (array) json_decode((string) $r->getBody(), true);
};

register_shutdown_function(static function () use ($db, $run, $operatorId): void {
    Features::resetOverrides();
    $like = "%{$run}%";
    foreach ([
        'DELETE FROM calendario_chiusure WHERE descrizione LIKE ?',
        'DELETE FROM festivita_personalizzate WHERE nome LIKE ?',
        'DELETE FROM tipologie_lettore WHERE codice LIKE ?',
        'DELETE FROM mobile_idempotency_keys WHERE idem_key LIKE ?',
    ] as $sql) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
            // Best effort: a table missing on this install has nothing to clean.
        }
    }
    try {
        $db->query('DELETE FROM utenti WHERE id = ' . $operatorId);
    } catch (Throwable) {
        // A row that still references the operator keeps it; it is tagged zzcidop.
    }
});

// ── A. Closures calendar ──────────────────────────────────────────────────────
echo "A. LibraryCalendarController created ids\n";
$controller = new LibraryCalendarController();
$controller->store($form('/admin/calendar/closures', ['data_inizio' => '2098-03-02', 'data_fine' => '2098-03-02', 'descrizione' => "Chiusura {$run}"]), new Response(), $db);
$closureId = $scalar('SELECT id FROM calendario_chiusure WHERE descrizione = ?', 's', ["Chiusura {$run}"]);
$check($closureId !== null && $controller->createdId() === $closureId, 'store(): createdId() is the inserted closure');

$refused = new LibraryCalendarController();
$refused->store($form('/admin/calendar/closures', ['data_inizio' => 'not-a-date', 'descrizione' => "Rifiutata {$run}"]), new Response(), $db);
$check($refused->createdId() === null && $refused->createdIds() === [], 'a refused store() exposes no id');

$custom = new LibraryCalendarController();
$custom->storeCustom($form('/admin/calendar/custom-holidays', ['nome' => "Patrono {$run}", 'tipo' => 'fixed', 'fixed_mese' => '6', 'fixed_giorno' => '15', 'durata_giorni' => '1', 'attiva' => '1']), new Response(), $db);
$customId = $scalar('SELECT id FROM festivita_personalizzate WHERE nome = ?', 's', ["Patrono {$run}"]);
$check($customId !== null && $custom->createdId() === $customId, 'storeCustom(): createdId() is the inserted holiday');

$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20980405\r\nSUMMARY:Ics uno {$run}\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20980406\r\nSUMMARY:Ics due {$run}\r\nEND:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$upload = static function (string $body): UploadedFile {
    $stream = (new StreamFactory())->createStream($body);
    return new UploadedFile($stream, 'closures.ics', 'text/calendar', strlen($body), UPLOAD_ERR_OK);
};
$import = new LibraryCalendarController();
$import->importIcs($form('/admin/calendar/import-ics', ['sede_id' => ''])->withUploadedFiles(['ics_file' => $upload($ics)]), new Response(), $db);
$icsIds = $ids('SELECT id FROM calendario_chiusure WHERE descrizione LIKE ?', "Ics % {$run}");
$created = $import->createdIds();
sort($created);
$check(count($icsIds) === 2 && $created === $icsIds, 'importIcs(): createdIds() are exactly the rows of the file');
$again = new LibraryCalendarController();
$again->importIcs($form('/admin/calendar/import-ics', ['sede_id' => ''])->withUploadedFiles(['ics_file' => $upload($ics)]), new Response(), $db);
$check($again->createdIds() === [], 'importing the same file again inserts, and names, nothing');

// ── B. Patron types ───────────────────────────────────────────────────────────
echo "B. CirculationSettingsController::createdTypeId()\n";
$types = new CirculationSettingsController();
$types->createType($form('/admin/settings/circulation/types', ['nome' => "Tipo {$run}", 'codice' => "{$run}-a", 'attiva' => '1']), new Response(), $db);
$typeId = $scalar('SELECT id FROM tipologie_lettore WHERE codice = ?', 's', ["{$run}-a"]);
$check($typeId !== null && $types->createdTypeId() === $typeId, 'createType(): createdTypeId() is the inserted type');
$dup = new CirculationSettingsController();
$dup->createType($form('/admin/settings/circulation/types', ['nome' => "Tipo {$run}", 'codice' => "{$run}-a"]), new Response(), $db);
$check($dup->createdTypeId() === null, 'a refused createType() (code taken) exposes no id');

// ── C. API create endpoints ───────────────────────────────────────────────────
echo "C. API answers the row it inserted\n";
$closure = (new ClosuresCalendarController($db))->createClosure(
    $apiRequest('/api/v1/staff/calendar/closures', ['start_date' => '2098-05-04', 'description' => "Api {$run}"], "{$run}-closure"),
    new Response()
);
$body = $payload($closure);
$apiClosure = $scalar('SELECT id FROM calendario_chiusure WHERE descrizione = ?', 's', ["Api {$run}"]);
$check($closure->getStatusCode() === 201 && ($body['data']['id'] ?? null) === $apiClosure && ($body['data']['description'] ?? null) === "Api {$run}", 'POST /staff/calendar/closures answers its own closure' . ($closure->getStatusCode() === 201 ? '' : ' (HTTP ' . $closure->getStatusCode() . ': ' . substr((string) $closure->getBody(), 0, 200) . ')'));

$holiday = (new ClosuresCalendarController($db))->createCustom(
    $apiRequest('/api/v1/staff/calendar/custom-holidays', ['name' => "Api patrono {$run}", 'kind' => 'fixed', 'month' => 7, 'day' => 1], "{$run}-custom"),
    new Response()
);
$body = $payload($holiday);
$apiHoliday = $scalar('SELECT id FROM festivita_personalizzate WHERE nome = ?', 's', ["Api patrono {$run}"]);
$check($holiday->getStatusCode() === 201 && ($body['data']['id'] ?? null) === $apiHoliday, 'POST /staff/calendar/custom-holidays answers its own holiday');

$type = (new CirculationRulesController($db))->createType(
    $apiRequest('/api/v1/staff/circulation/types', ['name' => "Api tipo {$run}", 'code' => "{$run}-b"], "{$run}-type"),
    new Response()
);
$body = $payload($type);
$apiType = $scalar('SELECT id FROM tipologie_lettore WHERE codice = ?', 's', ["{$run}-b"]);
$check($type->getStatusCode() === 201 && ($body['data']['id'] ?? null) === $apiType && ($body['data']['code'] ?? null) === "{$run}-b", 'POST /staff/circulation/types answers its own type');

echo PHP_EOL . ($failed === 0 ? "ALL {$passed} PASS" : "{$failed} FAIL, {$passed} PASS") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
