<?php
declare(strict_types=1);

/**
 * Mobile API 1.10.0 (Pinakes 0.9.0): PUT /staff/users/{id}/patron-type
 * decides on the account and on the type as they are once their rows are
 * locked, not on what was read before the transaction.
 *
 *   A. The account becomes one the caller may not edit (digital access
 *      granted by another operator) right before the row lock: 403, the
 *      type is not changed.
 *   B. Another operator switches the requested type off while the change is
 *      being saved: the deactivation waits for the change (the type row is
 *      read under a shared lock), so no account is left on an inactive type.
 *   C. Without interference the type is saved.
 *   D. recompute_card_expiry with a type that sets a card duration stores
 *      the expiry the type gives.
 *   E. The account stops being a reader account (standard/premium) right
 *      before the row lock: the type is saved, the expiry is not recomputed
 *      (the account kind is read under the lock, not before it).
 *   F. The account is deleted right before the row lock: 404, as for an id
 *      that never existed.
 *
 * The interference runs on a second connection, triggered just before the
 * controller's connection prepares the matching statement.
 *
 * Runs against the REAL database of the checkout and only touches rows it
 * creates (emails / codes containing zzptl).
 *
 * Run: php tests/mobile-api-patron-type-lock-090.unit.php
 */

use App\Plugins\MobileApi\Staff090\Controllers\StaffPatronsController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Services\CirculationPolicy;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\PatronTypeAssignment;
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

/**
 * Runs $action once, just before the first statement matching $pattern is
 * prepared: the change lands between the controller's reads and its writes.
 */
final class InterferingDb extends mysqli
{
    private ?string $pattern = null;
    /** @var (callable(): void)|null */
    private $action = null;

    public function before(string $pattern, callable $action): void
    {
        $this->pattern = $pattern;
        $this->action = $action;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->pattern !== null && $this->action !== null && preg_match($this->pattern, $query) === 1) {
            $action = $this->action;
            $this->pattern = null;
            $this->action = null;
            $action();
        }
        return parent::prepare($query);
    }
}

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
        ? new InterferingDb(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new InterferingDb(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
    // "Another operator": a second connection, with a short lock wait.
    $other = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $other->query('SET SESSION innodb_lock_wait_timeout = 1');
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

$run = 'zzptl' . bin2hex(random_bytes(3));
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$exec = static function (string $sql, string $types, array $params) use ($db): int {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};
$typeOf = static function (int $userId) use ($db): ?int {
    $value = $db->query("SELECT tipologia_id FROM utenti WHERE id = {$userId}")->fetch_row()[0];
    return $value === null ? null : (int) $value;
};

$created = new stdClass();
$created->users = [];
$created->types = [];
register_shutdown_function(static function () use ($db, $created): void {
    Features::resetOverrides();
    foreach ((array) $created->users as $id) {
        $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
    }
    foreach ((array) $created->types as $id) {
        $db->query('DELETE FROM tipologie_lettore WHERE id = ' . (int) $id);
    }
});

$reader = static function (string $tag) use ($exec, $run, $created): int {
    $email = "{$run}-{$tag}@example.test";
    $card = "{$run}{$tag}";
    $id = $exec(
        "INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata) VALUES ('Zz', 'Lock', ?, 'x', ?, 'standard', 'attivo', 1)",
        'ss',
        [$email, $card]
    );
    $created->users[] = $id;
    return $id;
};
$type = static function (string $tag, ?int $cardDays = null) use ($exec, $run, $created): int {
    $id = $exec("INSERT INTO tipologie_lettore (codice, nome, ordinamento, attiva, durata_tessera_giorni) VALUES (?, ?, 9999, 1, ?)", 'ssi', ["{$run}-{$tag}", "Lock {$run} {$tag}", $cardDays]);
    $created->types[] = $id;
    return $id;
};
$put = static function (int $userId, int $typeId, bool $recompute = false) use ($db): array {
    $request = (new ServerRequestFactory())->createServerRequest('PUT', "/api/v1/staff/users/{$userId}/patron-type")
        ->withHeader('Content-Type', 'application/json')
        ->withBody((new StreamFactory())->createStream((string) json_encode(['patron_type_id' => $typeId, 'recompute_card_expiry' => $recompute])))
        ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => 0]);
    $response = (new StaffPatronsController($db))->updatePatronType($request, new Response(), $userId);
    $response->getBody()->rewind();
    return ['status' => $response->getStatusCode()] + (array) json_decode((string) $response->getBody(), true);
};

// The caller: a custom role managing readers (not admin, not staff), so the
// editable-account rule of UserRoleAssignment::canEdit() applies.
$_SESSION['user'] = ['id' => 0, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'users.manage']];

echo "A. The account changes before the row lock\n";
$userA = $reader('a');
$typeA = $type('a');
$db->before('/FROM utenti WHERE id = \? FOR UPDATE/', static function () use ($other, $userA): void {
    $other->query("UPDATE utenti SET accesso_digitale = 1 WHERE id = {$userA}");
});
$a = $put($userA, $typeA);
$check($a['status'] === 403, 'an account that became non-editable before the lock is refused (403)');
$check($typeOf($userA) === null, 'and its type is not changed');

echo "B. The type is switched off while the change is being saved\n";
$userB = $reader('b');
$typeB = $type('b');
// Right before the type is written, another operator switches it off. The
// type row was read under a shared lock: that deactivation has to wait for
// this transaction (here it gives up after one second) instead of landing
// between the check and the save and leaving the account on an inactive type.
$deactivation = 'not attempted';
$db->before('/UPDATE utenti SET tipologia_id/', static function () use ($other, $typeB, &$deactivation): void {
    try {
        $other->query("UPDATE tipologie_lettore SET attiva = 0 WHERE id = {$typeB}");
        $deactivation = 'applied';
    } catch (mysqli_sql_exception $e) {
        $deactivation = $e->getCode() === 1205 ? 'blocked' : 'error ' . $e->getMessage();
    }
});
$b = $put($userB, $typeB);
$check($deactivation === 'blocked', "a concurrent deactivation of the type waits for the change (got: {$deactivation})");
$active = (int) $db->query("SELECT attiva FROM tipologie_lettore WHERE id = {$typeB}")->fetch_row()[0];
$check($b['status'] === 200 && $typeOf($userB) === $typeB && $active === 1, 'the account ends on an active type');

echo "C. No interference\n";
$userC = $reader('c');
$typeC = $type('c');
$c = $put($userC, $typeC);
$check($c['status'] === 200 && $typeOf($userC) === $typeC, 'the type is saved');

$expiryOf = static function (int $userId) use ($db): ?string {
    $value = $db->query("SELECT data_scadenza_tessera FROM utenti WHERE id = {$userId}")->fetch_row()[0];
    return $value === null ? null : (string) $value;
};
Features::override('automatic_card_expiry', true);

echo "D. Card expiry recomputed from the type\n";
$userD = $reader('d');
$typeD = $type('d', 200);
$d = $put($userD, $typeD, true);
$check($d['status'] === 200 && $typeOf($userD) === $typeD, 'the type is saved');
$check(($d['meta']['card_expiry_recomputed'] ?? null) === true && $expiryOf($userD) === PatronTypeAssignment::todayPlus(200), 'and the card expires after the type\'s 200 days (' . var_export($expiryOf($userD), true) . ')');

echo "E. The account kind changes before the row lock\n";
// An administrator edits any account, so the change below decides only
// whether the expiry is recomputed (reader accounts only), not access.
$_SESSION['user'] = ['id' => 0, 'tipo_utente' => 'admin', 'stato' => 'attivo'];
$userE = $reader('e');
$typeE = $type('e', 200);
$db->before('/FROM utenti WHERE id = \? FOR UPDATE/', static function () use ($other, $userE): void {
    $other->query("UPDATE utenti SET tipo_utente = 'staff' WHERE id = {$userE}");
});
$e = $put($userE, $typeE, true);
$check($e['status'] === 200 && $typeOf($userE) === $typeE, 'the type is saved');
$check(($e['meta']['card_expiry_recomputed'] ?? null) === false && $expiryOf($userE) === null, 'the expiry is not recomputed for an account that is no longer a reader account');

echo "F. The account is deleted before the row lock\n";
$userF = $reader('f');
$typeF = $type('f');
$db->before('/FROM utenti WHERE id = \? FOR UPDATE/', static function () use ($other, $userF): void {
    $other->query("DELETE FROM utenti WHERE id = {$userF}");
});
$f = $put($userF, $typeF);
$check($f['status'] === 404 && ($f['error']['code'] ?? null) === 'not_found', 'an account deleted before the lock answers 404 not_found (got ' . $f['status'] . ')');

echo PHP_EOL . ($failed === 0 ? "ALL {$passed} PASS" : "{$failed} FAIL, {$passed} PASS") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
