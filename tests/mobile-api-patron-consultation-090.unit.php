<?php
declare(strict_types=1);

/**
 * Mobile API 1.9.0 (Pinakes 0.9.0): what the Android app reads and the
 * server did not send.
 *
 *   A. GET /me carries patron_type (id, code, name): the reader's own type,
 *      else the library default; null with circulation_rules off.
 *   B. Staff copies carry consultation_only, and PUT /staff/copies/{id}
 *      sets it through CopyController::toggleConsultation(): the same
 *      refusal for a copy held by a loan, 422 for a non-boolean, 404
 *      feature_disabled with features.consultation_only off (the flag then
 *      reads false), and status/notes still applied in the same call.
 *
 * Runs against the REAL database of the checkout and only touches rows it
 * creates (titles ZZ_MAPC_%, emails zzmapc+%@test.local, types zzmapc-%).
 *
 * Run: php tests/mobile-api-patron-consultation-090.unit.php
 */

use App\Plugins\MobileApi\Controllers\ActionsController;
use App\Plugins\MobileApi\Controllers\StaffCopiesController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\StaffCopyReader;
use App\Services\CirculationPolicy;
use App\Support\DateHelper;
use App\Support\Features;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

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
if (!(new CirculationPolicy($db))->schemaReady()) {
    fwrite(STDERR, "FAIL: the 0.9.0 circulation schema is missing on this database\n");
    exit(1);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}

$run = bin2hex(random_bytes(4));
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$insert = static function (string $sql, string $types, array $params) use ($db): int {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

// Rows this run created, removed at shutdown even when a check throws.
$created = new stdClass();
$created->book = 0;
$created->users = [];
$created->type = 0;
$cleanup = static function () use ($db, $created): void {
    Features::resetOverrides();
    $bookId = (int) $created->book;
    if ($bookId > 0) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$bookId}");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM libri WHERE id = {$bookId}");
    }
    foreach ((array) $created->users as $id) {
        $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
    }
    $typeId = (int) $created->type;
    if ($typeId > 0) {
        $db->query("DELETE FROM tipologie_lettore WHERE id = {$typeId}");
    }
};
register_shutdown_function($cleanup);

$request = static function (string $method, string $path, ?array $json = null, int $userId = 0) {
    $req = (new ServerRequestFactory())->createServerRequest($method, $path);
    if ($json !== null) {
        $req = $req->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream((string) json_encode($json)));
    }
    return $userId > 0 ? $req->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $userId]) : $req;
};
$payload = static function (\Psr\Http\Message\ResponseInterface $r): array {
    $r->getBody()->rewind();
    return (array) json_decode((string) $r->getBody(), true);
};

// ── A. patron_type in GET /me ─────────────────────────────────────────────────
echo "A. GET /me patron_type\n";
$typeId = $insert(
    'INSERT INTO tipologie_lettore (codice, nome, prenotazioni_abilitate, attiva) VALUES (?, ?, 1, 1)',
    'ss',
    ["zzmapc-{$run}", "Studente &amp; ricercatore {$run}"]
);
$created->type = $typeId;
$typed = $insert(
    "INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata, tipologia_id) VALUES ('Ada', 'Zz', ?, 'x', ?, 'standard', 'attivo', 1, ?)",
    'ssi',
    ["zzmapc+typed{$run}@test.local", "ZZMAPC-T-{$run}", $typeId]
);
$created->users = [$typed];
$untyped = $insert(
    "INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata) VALUES ('Bea', 'Zz', ?, 'x', ?, 'standard', 'attivo', 1)",
    'ss',
    ["zzmapc+plain{$run}@test.local", "ZZMAPC-P-{$run}"]
);
$created->users = [$typed, $untyped];

$me = static fn (int $id): array => $payload((new ActionsController($db))->getProfile($request('GET', '/api/v1/me', null, $id), new Response()));

Features::override('circulation_rules', true);
$data = $me($typed)['data'] ?? [];
$check(($data['patron_type']['id'] ?? null) === $typeId, "the reader's own patron type is sent");
$check(($data['patron_type']['code'] ?? null) === "zzmapc-{$run}", 'with its code');
$check(($data['patron_type']['name'] ?? null) === "Studente & ricercatore {$run}", 'and its name, decoded');

$default = (new CirculationPolicy($db))->defaultTypeId();
$plain = $me($untyped)['data'] ?? [];
$check(array_key_exists('patron_type', $plain), 'patron_type is always in the payload');
$check(($plain['patron_type']['id'] ?? null) === $default, 'a reader without a type gets the library default (or null when none exists)');

Features::override('circulation_rules', false);
$off = $me($typed)['data'] ?? [];
$check(array_key_exists('patron_type', $off) && $off['patron_type'] === null, 'with circulation_rules off patron_type is null');
Features::resetOverrides();

// ── B. consultation_only on staff copies ──────────────────────────────────────
echo "B. Staff copies consultation_only\n";
$bookId = $insert("INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 2, 2)", 's', ["ZZ_MAPC_{$run}"]);
$created->book = $bookId;
$free = $insert("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')", 'is', [$bookId, "ZZMAPC-F-{$run}"]);
$lent = $insert("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'prestato')", 'is', [$bookId, "ZZMAPC-L-{$run}"]);
$insert(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1)",
    'iii',
    [$bookId, $lent, $typed]
);
$column = static function (int $copyId) use ($db): int {
    return (int) $db->query("SELECT solo_consultazione FROM copie WHERE id = {$copyId}")->fetch_row()[0];
};
$put = static fn (int $copyId, array $body): array => (function () use ($db, $request, $payload, $copyId, $body): array {
    $r = (new StaffCopiesController($db))->update($request('PUT', "/api/v1/staff/copies/{$copyId}", $body), new Response(), $copyId);
    return ['status' => $r->getStatusCode()] + $payload($r);
})();

Features::override('consultation_only', true);
$check(((new StaffCopyReader($db))->copy($free)['consultation_only'] ?? null) === false, 'a lendable copy reads consultation_only false');

$set = $put($free, ['consultation_only' => true]);
$check($set['status'] === 200 && ($set['data']['consultation_only'] ?? null) === true, 'PUT consultation_only true answers the copy with the flag on');
$check($column($free) === 1, 'and stores it');
$listed = array_column((new StaffCopyReader($db))->forBook($bookId), 'consultation_only', 'id');
$check(($listed[$free] ?? null) === true && ($listed[$lent] ?? null) === false, 'the book copy list carries the flag of each copy');

$back = $put($free, ['consultation_only' => false, 'notes' => "nota {$run}"]);
$check($back['status'] === 200 && ($back['data']['consultation_only'] ?? null) === false && $column($free) === 0, 'PUT consultation_only false makes it lendable again');
$check(($back['data']['notes'] ?? null) === "nota {$run}", 'notes sent in the same call are applied too');

$held = $put($lent, ['consultation_only' => true]);
$check($held['status'] === 409 && ($held['error']['code'] ?? null) === 'conflict' && $column($lent) === 0, 'a copy out on loan cannot become consultation only (409, nothing stored)');

$bad = $put($free, ['consultation_only' => 'yes']);
$check($bad['status'] === 422 && ($bad['error']['code'] ?? null) === 'validation_error', 'a non-boolean consultation_only is a 422');

Features::override('consultation_only', false);
$db->query("UPDATE copie SET solo_consultazione = 1 WHERE id = {$free}");
$check(((new StaffCopyReader($db))->copy($free)['consultation_only'] ?? null) === false, 'with the module off every copy reads consultation_only false');
$disabled = $put($free, ['consultation_only' => false]);
$check($disabled['status'] === 404 && ($disabled['error']['code'] ?? null) === 'feature_disabled' && $column($free) === 1, 'with the module off the flag cannot be changed (404 feature_disabled)');
$notes = $put($free, ['notes' => "solo note {$run}"]);
$check($notes['status'] === 200 && ($notes['data']['notes'] ?? null) === "solo note {$run}", 'status and notes still work with the module off');
Features::resetOverrides();

echo PHP_EOL . ($failed === 0 ? "ALL {$passed} PASS" : "{$failed} FAIL, {$passed} PASS") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
