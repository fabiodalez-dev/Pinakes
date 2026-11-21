<?php
declare(strict_types=1);

/**
 * Review of PR #536 — PrestitiController::update() reassigning a loan locked the
 * CURRENT borrower's utenti row (the in-transaction scope re-check,
 * Permissions::mayActOnBorrower(..., true)) and then the NEW borrower's row.
 * Two custom-role operators reassigning loans in opposite directions (A->B on
 * one book, B->A on another) each held one row and waited for the other: an
 * AB/BA deadlock. The two rows are now locked in ascending id order.
 *
 * Deterministic, no timing: a mysqli subclass records the id of every locking
 * read on utenti in the order the controller runs them, and the test asserts
 * the invariant that makes the AB/BA cycle impossible: once a row is locked, no
 * row with a lower id is locked after it.
 *
 * Needs a database (E2E_DB_* / DB_* or .env). It creates its own tagged rows and
 * removes them. CI_STRICT_TESTS=1 turns "no database" into a failure.
 *
 * Run:  php tests/security-audit-loan-reassign-lock-order.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\PrestitiController;
use App\Support\Permissions as P;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** Hands out a recording statement for every locking read on utenti. */
final class LockOrderDb extends mysqli
{
    /** @var list<int> */
    public array $utentiLocks = [];
    public bool $recording = false;

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->recording && preg_match('/\bFROM\s+utenti\b.*\bFOR\s+UPDATE\b/is', $query) === 1) {
            return new LockOrderStmt($this, $query);
        }
        return parent::prepare($query);
    }
}

final class LockOrderStmt extends mysqli_stmt
{
    /** @var array<int, mixed> */
    private array $bound = [];

    public function __construct(private LockOrderDb $spy, string $query)
    {
        parent::__construct($spy, $query);
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->bound = [];
        foreach ($vars as $i => &$var) {
            $this->bound[$i] = &$var;
        }
        unset($var);
        return parent::bind_param($types, ...$vars);
    }

    public function execute(?array $params = null): bool
    {
        $this->spy->utentiLocks[] = (int) (($params ?? $this->bound)[0] ?? 0);
        return parent::execute($params);
    }
}

$envFile = is_file($root . '/.env') ? Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env')) : [];
$setting = static function (string $key, string $default = '') use ($envFile): string {
    foreach (['E2E_' . $key, $key] as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') { return $value; }
    }
    return (string) ($envFile[$key] ?? $default);
};
$db = null;
if ($setting('DB_NAME') !== '' && $setting('DB_USER') !== '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $socket = $setting('DB_SOCKET');
    $candidate = new LockOrderDb();
    $ok = @$candidate->real_connect($setting('DB_HOST', '127.0.0.1'), $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), (int) $setting('DB_PORT', '3306'), $socket !== '' && file_exists($socket) ? $socket : null);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($ok) {
        $candidate->set_charset('utf8mb4');
        $db = $candidate;
    }
}
if ($db === null) {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: no database (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "  SKIP no database configured\n";
    exit(0);
}
\App\Support\DateHelper::synchronizeDatabaseSession($db);

$post = static fn (array $body) => (new ServerRequestFactory())->createServerRequest('POST', 'http://library.test/')->withParsedBody($body);

/** True when the sequence never goes back to a lower id once a higher one is held. */
$ascending = static function (array $ids): bool {
    $held = [];
    foreach ($ids as $id) {
        if (in_array($id, $held, true)) {
            continue; // re-entering a lock this transaction already holds
        }
        if ($held !== [] && $id < max($held)) {
            return false;
        }
        $held[] = $id;
    }
    return true;
};

$tag = 'secLO-' . bin2hex(random_bytes(3));
$users = [];
$bookIds = [];
$roleId = 0;
try {
    $insertUser = static function (string $type, ?int $role = null) use ($db, $tag, &$users): int {
        $code = substr($tag . count($users), 0, 20);
        $email = $code . '@example.test';
        $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata, ruolo_id) VALUES ('Audit', 'LockOrder', ?, 'unused', ?, ?, 'attivo', 1, ?)");
        $stmt->bind_param('sssi', $email, $code, $type, $role);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $users[] = $id;
    };
    $roleName = $tag . '-desk';
    $permissions = json_encode(['backend.access', 'loans.manage']);
    $stmt = $db->prepare('INSERT INTO ruoli (nome, permessi) VALUES (?, ?)');
    $stmt->bind_param('ss', $roleName, $permissions);
    $stmt->execute();
    $roleId = (int) $stmt->insert_id;
    $stmt->close();

    $readerLow = $insertUser('standard');
    $readerHigh = $insertUser('standard');
    $operator = $insertUser('premium', $roleId);

    $today = \App\Support\DateHelper::today();
    $due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
    $newLoan = static function (int $userId) use ($db, $tag, $today, $due, &$bookIds): int {
        $title = $tag . ' book ' . count($bookIds);
        $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 1, 1)');
        $stmt->bind_param('s', $title);
        $stmt->execute();
        $bookId = $bookIds[] = (int) $stmt->insert_id;
        $stmt->close();
        $inv = $title . '-C1';
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'prestato')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copyId = (int) $stmt->insert_id;
        $stmt->close();
        $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, 'in_corso', 1)");
        $stmt->bind_param('iiiss', $bookId, $copyId, $userId, $today, $due);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    };
    $ownerOf = static fn (int $loanId): int => (int) $db->query("SELECT utente_id FROM prestiti WHERE id = {$loanId}")->fetch_row()[0];
    $actAsOperator = static function () use ($db, $operator): void {
        \App\Support\SessionRoleRevalidator::reset();
        $_SESSION = ['user' => P::viewer($operator, $db)];
    };
    $reassign = static function (int $loanId, int $to) use ($db, $post, $today, $due, $actAsOperator): string {
        $actAsOperator();
        $db->utentiLocks = [];
        $db->recording = true;
        try {
            $r = (new PrestitiController())->update(
                $post(['utente_id' => (string) $to, 'data_prestito' => $today, 'data_scadenza' => $due]),
                new Response(),
                $db,
                $loanId
            );
        } finally {
            $db->recording = false;
        }
        return $r->getHeaderLine('Location');
    };

    echo "A custom-role operator reassigns a loan from the higher id to the lower id\n";
    $loanDown = $newLoan($readerHigh);
    $location = $reassign($loanDown, $readerLow);
    $locks = $db->utentiLocks;
    $check(in_array($readerLow, $locks, true) && in_array($readerHigh, $locks, true),
        'both borrowers\' rows are locked (sequence: ' . implode(',', $locks) . ')');
    $check($ascending($locks), 'the utenti rows are locked in ascending id order (no AB/BA cycle with the opposite reassignment)');
    $check(!str_contains($location, 'error=') && $ownerOf($loanDown) === $readerLow, 'the reassignment goes through (' . $location . ')');

    echo "The opposite direction (control)\n";
    $loanUp = $newLoan($readerLow);
    $location = $reassign($loanUp, $readerHigh);
    $locks = $db->utentiLocks;
    $check($ascending($locks) && in_array($readerHigh, $locks, true), 'lower to higher: ascending as well (sequence: ' . implode(',', $locks) . ')');
    $check(!str_contains($location, 'error=') && $ownerOf($loanUp) === $readerHigh, 'the reassignment goes through (' . $location . ')');
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $_SESSION = [];
    $db->recording = false;
    if ($users !== []) {
        $in = implode(',', array_map('intval', $users));
        foreach (["DELETE FROM movimenti_lettore WHERE utente_id IN ({$in})", "DELETE FROM prenotazioni WHERE utente_id IN ({$in})", "DELETE FROM prestiti WHERE utente_id IN ({$in})"] as $sql) {
            try { $db->query($sql); } catch (\Throwable) { /* table absent on an older schema */ }
        }
    }
    foreach ($bookIds as $bookId) {
        foreach (['DELETE FROM prenotazioni WHERE libro_id = ', 'DELETE FROM prestiti WHERE libro_id = ', 'DELETE FROM copie WHERE libro_id = ', 'DELETE FROM libri WHERE id = '] as $sql) {
            try { $db->query($sql . (int) $bookId); } catch (\Throwable) {}
        }
    }
    if ($users !== []) {
        try { $db->query('DELETE FROM utenti WHERE id IN (' . implode(',', array_map('intval', $users)) . ')'); } catch (\Throwable $e) { echo '  cleanup: ' . $e->getMessage() . PHP_EOL; }
    }
    if ($roleId > 0) {
        try { $db->query('DELETE FROM ruoli WHERE id = ' . $roleId); } catch (\Throwable) {}
    }
}

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
