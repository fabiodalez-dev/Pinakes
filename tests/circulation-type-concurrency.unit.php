<?php
declare(strict_types=1);

/**
 * Patron-type changes use their locked state, including a default change or
 * deletion committed by another administrator after the form action's first
 * read. Real controllers and a second DB connection; no timing-dependent sleep.
 * Run only on an isolated test database.
 */
use App\Controllers\CirculationSettingsController;
use App\Support\ConfigStore;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (getenv('PINAKES_ISOLATED_TEST_DB') !== '1' && !str_contains((string) getenv('E2E_DB_NAME'), 'test')) {
    fwrite(STDERR, "FAIL: select an isolated test database.\n");
    exit(1);
}

final class PatronTypeInterleavingDb extends mysqli
{
    /** @var (Closure(): void)|null */
    public ?Closure $beforeBegin = null;
    /** @var (Closure(): void)|null */
    public ?Closure $beforeUserWrite = null;

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->beforeUserWrite !== null
            && (str_starts_with($query, 'INSERT INTO utenti (') || str_starts_with($query, "UPDATE utenti SET\n"))) {
            $action = $this->beforeUserWrite;
            $this->beforeUserWrite = null;
            $action();
        }
        return parent::prepare($query);
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $action = $this->beforeBegin;
        $this->beforeBegin = null;
        if ($action !== null) {
            $action();
        }
        return parent::begin_transaction($flags, $name);
    }
}

$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
$args = [
    getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost'),
    getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''),
    getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null),
];
$db = new PatronTypeInterleavingDb(...$args);
$other = new mysqli(...$args);
$db->set_charset('utf8mb4');
$other->set_charset('utf8mb4');
$sessionBefore = $_SESSION ?? [];
$shared = new ReflectionProperty(ConfigStore::class, 'sharedConnection');
$connectionBefore = $shared->getValue();
$shared->setValue(null, $db);
ConfigStore::clearCache();
$defaultsBefore = $db->query('SELECT id FROM tipologie_lettore WHERE predefinita = 1')->fetch_all(MYSQLI_NUM);
$types = [];
$users = [];
$run = bin2hex(random_bytes(5));
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$request = static fn (array $body = []) => (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings/circulation')->withParsedBody($body);
$controller = new CirculationSettingsController();
$createType = static function (string $suffix) use ($db, $run, &$types): int {
    $code = "zztype-{$run}-{$suffix}";
    $db->query("INSERT INTO tipologie_lettore (codice,nome,attiva,predefinita) VALUES ('{$code}','{$code}',1,0)");
    $types[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
$makeUser = static function (string $suffix, string $kind = 'standard', ?int $type = null) use ($db, $run, &$users): int {
    $stmt = $db->prepare("INSERT INTO utenti (nome,cognome,email,password,codice_tessera,tipo_utente,stato,tipologia_id) VALUES ('Type','Review',?,'x',?,?,'attivo',?)");
    $mail = "type-{$run}-{$suffix}@example.invalid";
    $card = "TC{$run}{$suffix}";
    $stmt->bind_param('sssi', $mail, $card, $kind, $type);
    $stmt->execute();
    $users[] = (int) $db->insert_id;
    $stmt->close();
    return (int) $db->insert_id;
};
$resetMessages = static function (): void { unset($_SESSION['error_message'], $_SESSION['success_message']); };

try {
    $operator = $makeUser('op', 'admin');
    $_SESSION = ['user' => ['id' => $operator, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
    $baseline = $createType('base');
    $controller->setDefault($request(), new Response(), $db, $baseline);

    // The first delete read says "not default"; the other administrator makes
    // it default before the deletion transaction starts.
    $target = $createType('protected');
    $reader = $makeUser('reader', type: $target);
    $db->query("INSERT INTO regole_prestito (tipologia_id,tipo_media,durata_prestito_giorni) VALUES ({$target},'libro',7)");
    $db->beforeBegin = static function () use ($controller, $request, $other, $target): void {
        $controller->setDefault($request(), new Response(), $other, $target);
        unset($_SESSION['success_message'], $_SESSION['error_message']);
    };
    $controller->deleteType($request(), new Response(), $db, $target);
    $check((int) $db->query("SELECT tipologia_id FROM utenti WHERE id={$reader}")->fetch_row()[0] === $target, 'a concurrently selected default keeps its readers');
    $check((int) ($db->query("SELECT durata_prestito_giorni FROM regole_prestito WHERE tipologia_id={$target} AND tipo_media='libro'")->fetch_row()[0] ?? 0) === 7, 'a concurrently selected default keeps its material rules');
    $check(isset($_SESSION['error_message']) && !isset($_SESSION['success_message']), 'deleting a newly selected default reports refusal');

    // The target of set-default disappears after its initial read. Clearing
    // the old default first must never leave the library with no default.
    $missing = $createType('missing');
    $db->beforeBegin = static function () use ($controller, $request, $other, $missing): void {
        $controller->deleteType($request(), new Response(), $other, $missing);
        unset($_SESSION['success_message'], $_SESSION['error_message']);
    };
    $controller->setDefault($request(), new Response(), $db, $missing);
    $check((int) $db->query('SELECT COUNT(*) FROM tipologie_lettore WHERE predefinita=1')->fetch_row()[0] === 1, 'a deleted set-default target does not clear the existing default');
    $check((int) $db->query("SELECT predefinita FROM tipologie_lettore WHERE id={$target}")->fetch_row()[0] === 1, 'the previous default is retained after the refused change');
    $check(isset($_SESSION['error_message']) && !isset($_SESSION['success_message']), 'setting a vanished type as default reports failure');

    $edited = $createType('edited');
    $db->beforeBegin = static function () use ($controller, $request, $other, $edited): void {
        $controller->setDefault($request(), new Response(), $other, $edited);
    };
    $resetMessages();
    $form = ['nome' => 'Edited type', 'codice' => "zztype-{$run}-edited", 'attiva' => '0', 'prenotazioni_abilitate' => '1'];
    $controller->updateType($request($form), new Response(), $db, $edited);
    $check($db->query("SELECT CONCAT(predefinita, '|', attiva) FROM tipologie_lettore WHERE id={$edited}")->fetch_row()[0] === '1|1', 'editing a newly selected default cannot deactivate it');

    $vanished = $createType('vanished');
    $db->beforeBegin = static function () use ($controller, $request, $other, $vanished): void {
        $controller->deleteType($request(), new Response(), $other, $vanished);
        unset($_SESSION['success_message'], $_SESSION['error_message']);
    };
    $resetMessages();
    $controller->updateType($request(['nome' => 'Vanished', 'codice' => "zztype-{$run}-vanished"]), new Response(), $db, $vanished);
    $check(isset($_SESSION['error_message']) && !isset($_SESSION['success_message']), 'editing a vanished type never claims success');

    $controller->setDefault($request(), new Response(), $db, $baseline);
    $resetMessages();
    $controller->deleteType($request(), new Response(), $db, $target);
    $check($db->query("SELECT tipologia_id FROM utenti WHERE id={$reader}")->fetch_row()[0] === null, 'ordinary deletion moves the reader to the default type');
    $check((int) $db->query("SELECT COUNT(*) FROM regole_prestito WHERE tipologia_id={$target}")->fetch_row()[0] === 0, 'ordinary deletion removes the deleted type material rules');
    $check((int) $db->query('SELECT COUNT(*) FROM tipologie_lettore WHERE predefinita=1 AND attiva=1')->fetch_row()[0] === 1, 'ordinary changes retain exactly one active default');

    // Assigning the type is part of saving the account. A type removed between
    // validation and writing must not leave a half-created/half-updated reader.
    $creatingType = $createType('create-user');
    $db->beforeUserWrite = static function () use ($controller, $request, $other, $creatingType): void {
        $controller->deleteType($request(), new Response(), $other, $creatingType);
    };
    $mail = "type-{$run}-created@example.invalid";
    $form = ['nome' => 'Created', 'cognome' => 'Reader', 'email' => $mail,
        'telefono' => '000', 'indirizzo' => 'Via Test 1', 'password' => 'ReviewType2026!',
        'tipo_utente' => 'standard', 'tipologia_id' => (string) $creatingType, 'codice_tessera' => "TN{$run}"];
    $response = (new \App\Controllers\UsersController())->store($request($form), new Response(), $db);
    $createdId = (int) ($db->query("SELECT id FROM utenti WHERE email='{$mail}'")->fetch_row()[0] ?? 0);
    if ($createdId > 0) { $users[] = $createdId; }
    $check(str_contains($response->getHeaderLine('Location'), 'error='), 'a vanished type makes account creation report failure');
    $check($createdId === 0, 'a failed patron-type assignment leaves no half-created account');

    $editedReader = $makeUser('atomic', type: $baseline);
    $updatingType = $createType('update-user');
    $db->beforeUserWrite = static function () use ($controller, $request, $other, $updatingType): void {
        $controller->deleteType($request(), new Response(), $other, $updatingType);
    };
    $form['email'] = "type-{$run}-atomic@example.invalid";
    $form['nome'] = 'Changed';
    $form['codice_tessera'] = "TC{$run}atomic";
    $form['tipologia_id'] = (string) $updatingType;
    unset($form['password']);
    $response = (new \App\Controllers\UsersController())->update($request($form), new Response(), $db, $editedReader);
    $row = $db->query("SELECT nome,tipologia_id FROM utenti WHERE id={$editedReader}")->fetch_assoc();
    $check(str_contains($response->getHeaderLine('Location'), 'error='), 'a vanished type makes account editing report failure');
    $check($row['nome'] === 'Type' && (int) $row['tipologia_id'] === $baseline, 'a failed type change preserves the original account and type together');
} finally {
    $db->beforeBegin = null;
    $db->beforeUserWrite = null;
    $db->rollback();
    $db->query('UPDATE tipologie_lettore SET predefinita=0 WHERE predefinita=1');
    foreach ($defaultsBefore as [$id]) { $db->query('UPDATE tipologie_lettore SET predefinita=1 WHERE id=' . (int) $id); }
    if ($users !== []) { $db->query('DELETE FROM utenti WHERE id IN (' . implode(',', $users) . ')'); }
    if ($types !== []) {
        $list = implode(',', $types);
        $db->query("DELETE FROM regole_prestito WHERE tipologia_id IN ({$list})");
        $db->query("DELETE FROM tipologie_lettore WHERE id IN ({$list})");
    }
    $_SESSION = $sessionBefore;
    $shared->setValue(null, $connectionBefore);
    ConfigStore::clearCache();
    $other->close();
    $db->close();
}
echo "Patron-type concurrency: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
