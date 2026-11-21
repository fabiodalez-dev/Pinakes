<?php
declare(strict_types=1);

/**
 * Mobile API: the answer of a staff loan WRITE carries the same late_fine as
 * GET /staff/loans/{id} (review of PR #538). The app replaces its loan state
 * with the write's answer, so after an edit, a renewal or a pickup a missing
 * late_fine dropped the fine preview of the return sheet and the hint that a
 * waive_reason is required.
 *
 * Drives the real StaffCirculationController (update, renew, pickup), which
 * delegates to the web controllers, and compares each answer with detail().
 * The fines module is switched on for the run (Features::override), so
 * late_fine is the full block, not the null of a library without fines.
 *
 * Needs a database (E2E_DB_* / DB_* or .env). It creates its own tagged rows
 * and removes them. CI_STRICT_TESTS=1 turns "no database" into a failure.
 *
 * Run:  php tests/mobile-api-staff-loan-mutation-late-fine.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';

use App\Plugins\MobileApi\Controllers\StaffCirculationController;
use App\Support\Features;
use App\Support\Permissions as P;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

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
    $candidate = new mysqli();
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

$jsonRequest = static function (string $method, string $path, array $body = []) {
    return (new ServerRequestFactory())->createServerRequest($method, 'http://library.test/api/v1' . $path)
        ->withHeader('Content-Type', 'application/json')
        ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
};
$decode = static fn (\Psr\Http\Message\ResponseInterface $r): array => ['status' => $r->getStatusCode(), 'body' => json_decode((string) $r->getBody(), true) ?: []];

$tag = 'mlf-' . bin2hex(random_bytes(3));
$users = [];
$bookId = 0;
try {
    Features::override('fines', true);
    $check(\App\Services\PatronLedger::active($db), 'the fines module is on for the run (ledger table present)');

    $insertUser = static function (string $type) use ($db, $tag, &$users): int {
        $code = substr($tag . count($users), 0, 20);
        $email = $code . '@example.test';
        $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata) VALUES ('Late', 'Fine', ?, 'unused', ?, ?, 'attivo', 1)");
        $stmt->bind_param('sss', $email, $code, $type);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $users[] = $id;
    };
    $operator = $insertUser('admin');
    $borrower = $insertUser('standard');

    $title = $tag . ' book';
    $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 4, 4)');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $stmt->insert_id;
    $stmt->close();
    $copies = [];
    for ($i = 1; $i <= 4; $i++) {
        $inv = $tag . '-C' . $i;
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copies[] = (int) $stmt->insert_id;
        $stmt->close();
    }
    $today = \App\Support\DateHelper::today();
    $due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
    $insertLoan = static function (int $copyId, string $stato) use ($db, $bookId, $borrower, $today, $due): int {
        $stmt = $db->prepare('INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $stmt->bind_param('iiisss', $bookId, $copyId, $borrower, $today, $due, $stato);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    };

    \App\Support\SessionRoleRevalidator::reset();
    $_SESSION = ['user' => P::viewer($operator, $db)];
    $controller = new StaffCirculationController($db);
    $detailFine = static function (int $id) use ($controller, $jsonRequest, $decode): mixed {
        $d = $decode($controller->detail($jsonRequest('GET', '/staff/loans/' . $id), new Response(), $id));
        return $d['body']['data']['late_fine'] ?? 'missing';
    };
    $assertSameShape = static function (string $what, array $answer, int $id) use ($check, $detailFine): void {
        $data = $answer['body']['data'] ?? [];
        $check($answer['status'] === 200, "{$what}: answered 200 (got {$answer['status']}: " . json_encode($answer['body']['error'] ?? null) . ')');
        $check(is_array($data) && array_key_exists('late_fine', $data), "{$what}: the answer carries late_fine");
        $check(is_array($data['late_fine'] ?? null) && isset($data['late_fine']['charged_cents'], $data['late_fine']['accruing_cents'], $data['late_fine']['currency']),
            "{$what}: late_fine is the full block while fines are on");
        $check(($data['late_fine'] ?? null) === $detailFine($id), "{$what}: late_fine equals the one GET /staff/loans/{id} returns");
    };

    echo "PUT /staff/loans/{id}\n";
    $edited = $insertLoan($copies[0], 'in_corso');
    $later = (new DateTimeImmutable($due))->modify('+5 days')->format('Y-m-d');
    $answer = $decode($controller->update($jsonRequest('PUT', '/staff/loans/' . $edited, ['due_at' => $later]), new Response(), $edited));
    $assertSameShape('edit', $answer, $edited);
    $check(($answer['body']['data']['due_at'] ?? null) === $later, 'edit: the write really happened (new due date in the answer)');

    echo "POST /staff/loans/{id}/renew\n";
    $renewed = $insertLoan($copies[1], 'in_corso');
    $answer = $decode($controller->renew($jsonRequest('POST', '/staff/loans/' . $renewed . '/renew'), new Response(), $renewed));
    $assertSameShape('renew', $answer, $renewed);

    echo "POST /staff/loans/{id}/pickup\n";
    $pickup = $insertLoan($copies[2], 'da_ritirare');
    $answer = $decode($controller->pickup($jsonRequest('POST', '/staff/loans/' . $pickup . '/pickup'), new Response(), $pickup));
    $assertSameShape('pickup', $answer, $pickup);
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Features::resetOverrides();
    $_SESSION = [];
    if ($users !== []) {
        $in = implode(',', array_map('intval', $users));
        foreach (["DELETE FROM movimenti_lettore WHERE utente_id IN ({$in})", "DELETE FROM prenotazioni WHERE utente_id IN ({$in})", "DELETE FROM prestiti WHERE utente_id IN ({$in})"] as $sql) {
            try { $db->query($sql); } catch (\Throwable) { /* table absent on an older schema */ }
        }
    }
    if ($bookId > 0) {
        foreach (['DELETE FROM prenotazioni WHERE libro_id = ', 'DELETE FROM prestiti WHERE libro_id = ', 'DELETE FROM copie WHERE libro_id = ', 'DELETE FROM libri WHERE id = '] as $sql) {
            try { $db->query($sql . $bookId); } catch (\Throwable) {}
        }
    }
    if ($users !== []) {
        try { $db->query('DELETE FROM utenti WHERE id IN (' . implode(',', array_map('intval', $users)) . ')'); } catch (\Throwable $e) { echo '  cleanup: ' . $e->getMessage() . PHP_EOL; }
    }
}

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
