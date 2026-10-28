<?php
declare(strict_types=1);

/**
 * Patron ledger behaviour (plan §7) against the slot database.
 *
 *  A. late return through LoanRepository::close(): one multa_ritardo row with
 *     key late:<id>; a repeated charge (retry) writes nothing more;
 *  B. undo of the return → storno, a later return charges again (late:<id>:2);
 *  C. payment / waiver / manual charge validation (amount, reason, balance);
 *  D. debt block: checkUser() and the SQL fragment agree, before and after the
 *     threshold; the balance subquery uses the utente_id index (EXPLAIN);
 *  E. zero config: with no fine settings a late return writes no row and the
 *     fragment admits the user.
 *
 * Run: php tests/patron-ledger.unit.php (exit code is the verdict)
 * Data markers: titles ZZ_LEDGER_%, emails zz-ledger%@test.local.
 */

use App\Models\LoanRepository;
use App\Services\FineParameters;
use App\Services\PatronLedger;
use App\Support\DateHelper;
use App\Support\LoanEligibility;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
// Socket when one is configured and present (local dev), TCP otherwise (CI).
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
    $pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
    $name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $pass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the behavioural checks are mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(4));
$settingKeys = ['daily_fine', 'fine_grace_days', 'fine_max_per_loan', 'debt_block_threshold'];
$savedSettings = [];
foreach ($settingKeys as $k) {
    $r = $db->query("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = '{$k}'")->fetch_row();
    $savedSettings[$k] = $r === null ? null : (string) $r[0];
}
$setSetting = static function (string $key, ?string $value) use ($db): void {
    if ($value === null) {
        $db->query("DELETE FROM system_settings WHERE category = 'loans' AND setting_key = '" . $db->real_escape_string($key) . "'");
        return;
    }
    $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
};
$cleanup = static function () use ($db): void {
    $db->query("DELETE m FROM movimenti_lettore m JOIN utenti u ON u.id = m.utente_id WHERE u.email LIKE 'zz-ledger%@test.local'");
    $db->query("DELETE p FROM prestiti p JOIN utenti u ON u.id = p.utente_id WHERE u.email LIKE 'zz-ledger%@test.local'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE 'ZZ_LEDGER_%'");
    $db->query("DELETE FROM libri WHERE titolo LIKE 'ZZ_LEDGER_%'");
    $db->query("DELETE FROM utenti WHERE email LIKE 'zz-ledger%@test.local'");
};

$today = DateHelper::today();
$daysAgo = static fn (int $n): string => (new DateTimeImmutable($today))->modify("-{$n} days")->format('Y-m-d');

$newUser = static function (string $tag) use ($db, $run): int {
    $email = "zz-ledger-{$tag}-{$run}@test.local";
    $card = 'ZL' . substr($run, 0, 6) . $tag;
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES (?, 'Zz', 'Ledger', ?, 'x', 'attivo', 'standard', 1, 1)");
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
};
$newLoan = static function (int $userId, string $start, string $due) use ($db, $run): int {
    $title = 'ZZ_LEDGER_' . $run . '_' . bin2hex(random_bytes(2));
    $db->query("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('" . $db->real_escape_string($title) . "', 1, 0, 'prestato')");
    $bookId = (int) $db->insert_id;
    $db->query("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES ({$bookId}, 'ZZL-" . $db->real_escape_string($title) . "', 'prestato')");
    $copyId = (int) $db->insert_id;
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (?, ?, ?, ?, ?, 'in_ritardo', 'diretto', 1)");
    $stmt->bind_param('iiiss', $bookId, $copyId, $userId, $start, $due);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
};
$count = static fn (string $where): int => (int) $db->query("SELECT COUNT(*) FROM movimenti_lettore WHERE {$where}")->fetch_row()[0];
$sqlEligible = static function (int $userId) use ($db, $today): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM utenti u WHERE u.id = ? AND ' . LoanEligibility::eligibleUserWhere('u'));
    $stmt->bind_param('is', $userId, $today);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n === 1;
};

try {
    $cleanup();
    $setSetting('daily_fine', '0.50');
    $setSetting('fine_grace_days', '2');
    $setSetting('fine_max_per_loan', null);
    $setSetting('debt_block_threshold', null);

    $ledger = new PatronLedger($db);

    echo "A. Late return → one late fine, idempotent\n";
    $u1 = $newUser('a');
    $loan1 = $newLoan($u1, $daysAgo(20), $daysAgo(5));
    $preview = $ledger->previewLateFine($loan1);
    $check($preview['days'] === 5 && $preview['cents'] === 250, 'preview before return: 5 days, 2.50');
    $check((new LoanRepository($db))->close($loan1) === true, 'loan closed through LoanRepository::close()');
    $check($count("prestito_id = {$loan1} AND tipo = 'multa_ritardo' AND chiave_idempotenza = 'late:{$loan1}' AND importo = 2.50") === 1, 'one multa_ritardo of 2.50 with key late:<id>');
    $check($ledger->chargeLateReturn($loan1) === 0, 'a repeated charge (retry of the same return) writes nothing');
    $check($count("prestito_id = {$loan1}") === 1, 'still exactly one row for the loan');
    $check((new LoanRepository($db))->close($loan1) === false && $count("prestito_id = {$loan1}") === 1, 'a second close() of the closed loan is refused and charges nothing');
    $check($ledger->balanceCents($u1) === 250, 'balance 2.50');
    $check($ledger->lateFineDays($loan1) === 5, 'days late stored with the fine (5)');

    echo "B. Undo → storno, re-return → new charge\n";
    $check($ledger->reverseLateFine($loan1, 'Restituzione annullata', null) === 250, 'reverseLateFine reverses 2.50');
    $check($count("prestito_id = {$loan1} AND tipo = 'storno' AND importo = -2.50 AND chiave_idempotenza = 'storno:late:{$loan1}:1'") === 1, 'storno row with its own key');
    $check($ledger->balanceCents($u1) === 0 && $ledger->lateFineNetCents($loan1) === 0, 'balance and net late fine back to 0');
    $check($ledger->reverseLateFine($loan1, 'again', null) === 0, 'nothing left to reverse: no second storno');
    $check($ledger->chargeLateReturn($loan1) === 250 && $count("chiave_idempotenza = 'late:{$loan1}:2'") === 1, 'the next return charges again under late:<id>:2');
    $check($ledger->chargeLateReturn($loan1) === 0, '...and that one is idempotent too');

    echo "C. Payment, waiver, manual charge\n";
    $threw = static function (callable $fn): string {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return '';
    };
    $check($threw(fn () => $ledger->recordPayment($u1, 300, 'contanti', null, null)) === 'amount_exceeds_balance', 'payment above the balance refused');
    $check($threw(fn () => $ledger->recordPayment($u1, 100, 'bitcoin', null, null)) === 'invalid_method', 'unknown payment method refused');
    $check($threw(fn () => $ledger->waive($u1, 100, '   ', null)) === 'reason_required', 'waiver without a reason refused');
    $check($threw(fn () => $ledger->manualCharge($u1, 0, 'x', null)) === 'invalid_amount', 'zero manual charge refused');
    $check($threw(fn () => $ledger->manualCharge($u1, 100, '', null)) === 'description_required', 'manual charge without description refused');
    $payId = $ledger->recordPayment($u1, 100, 'pos', 'acconto', null);
    $check($payId > 0 && $ledger->balanceCents($u1) === 150, 'payment of 1.00 → balance 1.50');
    $check($ledger->balanceAfterCents($u1, $payId) === 150, 'balance after the payment (receipt) 1.50');
    $ledger->waive($u1, 150, 'Prima volta', null);
    $check($ledger->balanceCents($u1) === 0, 'waiver of the rest → balance 0');
    $ledger->manualCharge($u1, 700, 'Tessera sostitutiva', null);
    $check($ledger->balanceCents($u1) === 700 && $count("utente_id = {$u1} AND tipo = 'addebito_manuale' AND importo = 7.00") === 1, 'manual charge 7.00');

    echo "C1. One desk form, one movement: a replayed request_key\n";
    $key = 'form:' . bin2hex(random_bytes(16));
    $first = $ledger->recordPayment($u1, 100, 'contanti', 'doppio invio', null, $key);
    $check($first > 0 && $ledger->recordPayment($u1, 100, 'contanti', 'doppio invio', null, $key) === $first && $ledger->balanceCents($u1) === 600, 'the same payment sent twice under one key is recorded once and answered with the same movement');
    $check($threw(fn () => $ledger->recordPayment($u1, 200, 'contanti', 'doppio invio', null, $key)) === 'duplicate_request', 'the same key with another amount is refused as duplicate_request');
    $check($threw(fn () => $ledger->recordPayment($u1, 100, 'pos', 'doppio invio', null, $key)) === 'duplicate_request', '...and with another payment method');
    $check($threw(fn () => $ledger->waive($u1, 100, 'doppio invio', null, null, $key)) === 'duplicate_request', '...and as a waiver instead of a payment');
    $check($threw(fn () => $ledger->manualCharge($u1, 100, 'doppio invio', null, $key)) === 'duplicate_request', '...and as a manual charge');
    $check($ledger->balanceCents($u1) === 600 && $count("utente_id = {$u1} AND chiave_idempotenza = '" . $db->real_escape_string($key) . "'") === 1, 'none of them wrote a row');
    $chargeKey = 'form:' . bin2hex(random_bytes(16));
    $chargeId = $ledger->manualCharge($u1, 100, 'Tessera', null, $chargeKey);
    $check($ledger->manualCharge($u1, 100, 'Tessera', null, $chargeKey) === $chargeId, 'a manual charge replayed as sent is answered with its movement');
    $check($threw(fn () => $ledger->manualCharge($u1, 100, 'Tessera nuova', null, $chargeKey)) === 'duplicate_request', 'a manual charge replayed with another description is refused');
    $check($ledger->balanceCents($u1) === 700, 'balance back to 7.00 (section D counts on it)');

    echo "C2. Inside the caller's transaction, and the desk waiver\n";
    $u4 = $newUser('tx');
    $db->begin_transaction();
    $ledger->manualCharge($u4, 400, 'Dentro la transazione', null);
    $ledger->waive($u4, 400, 'Condono in transazione', null);
    $db->rollback();
    $check($count("utente_id = {$u4}") === 0, 'a charge and a waiver written inside begin_transaction() roll back with the caller');
    $loan4 = $newLoan($u4, $daysAgo(20), $daysAgo(5));
    $check((new LoanRepository($db))->close($loan4) === true && $ledger->lateFineNetCents($loan4) === 250, 'late return: 2.50 standing on the loan');
    $ledger->waive($u4, 250, 'Condono al rientro', null, $loan4);
    $check($ledger->lateFineNetCents($loan4) === 0 && $ledger->balanceCents($u4) === 0, 'a waiver for the loan leaves no fine on it (return email, loan page)');
    $check($ledger->reverseLateFine($loan4, 'Restituzione annullata', null) === 250 && $ledger->balanceCents($u4) === 0, 'undoing a waived return reverses the fine and its waiver: the patron is not credited');
    $check($count("prestito_id = {$loan4} AND tipo = 'storno' AND importo = 2.50 AND chiave_idempotenza = 'storno:waiver:{$loan4}:1'") === 1, 'the waiver is reversed by its own storno row');
    $check($ledger->lateFineNetCents($loan4) === 0, 'nothing standing on the loan after the undo');
    $check($ledger->chargeLateReturn($loan4) === 250 && $ledger->lateFineNetCents($loan4) === 250 && $ledger->balanceCents($u4) === 250, 'the next return of the loan charges its fine again');

    echo "D. Debt block: PHP and SQL agree\n";
    $check(LoanEligibility::checkUser($db, $u1) === null && $sqlEligible($u1), 'no threshold: eligible in PHP and SQL');
    $setSetting('debt_block_threshold', '5,00');
    $check(LoanEligibility::checkUser($db, $u1) === 'debt_block', 'threshold 5, balance 7: checkUser → debt_block');
    $check(!$sqlEligible($u1), 'threshold 5, balance 7: eligibleUserWhere excludes the user');
    $setSetting('debt_block_threshold', '7.00');
    $check(LoanEligibility::checkUser($db, $u1) === null && $sqlEligible($u1), 'balance equal to the threshold is not over it: eligible');
    $setSetting('debt_block_threshold', '5');
    $ledger->recordPayment($u1, 200, 'contanti', null, null);
    $check(LoanEligibility::checkUser($db, $u1) === null && $sqlEligible($u1), 'after paying down to 5.00: eligible again');
    $check(str_contains(LoanEligibility::errorMessage('debt_block'), 'soglia') || LoanEligibility::errorMessage('debt_block') !== '', 'debt_block has its own message');

    // EXPLAIN output differs (MySQL 9 tree format, MariaDB/MySQL 8 table):
    // look for the index name anywhere in the plan.
    $plan = static function (string $sql) use ($db): string {
        $rows = $db->query('EXPLAIN ' . $sql)->fetch_all(MYSQLI_ASSOC);
        return implode(' ', array_map(static fn (array $r): string => implode(' ', array_map('strval', $r)), $rows));
    };
    $check(str_contains($plan("SELECT COALESCE(SUM(ml.importo), 0) FROM movimenti_lettore ml WHERE ml.utente_id = {$u1}"), 'idx_movimenti_utente'), 'balance subquery uses idx_movimenti_utente (EXPLAIN)');
    $check(
        // A non-constant user row: with u.id = <const> the optimizer folds the
        // whole predicate before execution and the plan shows no subquery.
        str_contains($plan("SELECT u.id FROM utenti u WHERE u.email LIKE 'zz-ledger%' AND " . str_replace('?', "'{$today}'", LoanEligibility::eligibleUserWhere('u'))), 'idx_movimenti_utente'),
        'inside eligibleUserWhere the ledger is read through idx_movimenti_utente (EXPLAIN)'
    );
    $check(substr_count(LoanEligibility::eligibleUserWhere('u'), '?') === 1, 'the fragment still needs exactly ONE placeholder');

    echo "E. Zero config\n";
    foreach ($settingKeys as $k) {
        $setSetting($k, null);
    }
    $u2 = $newUser('e');
    $loan2 = $newLoan($u2, $daysAgo(40), $daysAgo(30));
    $check((new LoanRepository($db))->close($loan2) === true && $count("utente_id = {$u2}") === 0, 'fines off: a 30-day late return writes no ledger row');
    $check(LoanEligibility::checkUser($db, $u2) === null && $sqlEligible($u2), 'fines off: user eligible in PHP and SQL');
    $check($ledger->accruedFines($u1) === [] && $ledger->previewLateFine($loan2)['enabled'] === false, 'fines off: nothing accrues, preview says disabled');

    echo "F. Debt threshold per patron type: PHP and SQL agree on every layer\n";
    $defaultType = (int) $db->query('SELECT id FROM tipologie_lettore WHERE predefinita = 1 ORDER BY ordinamento, nome, id LIMIT 1')->fetch_row()[0];
    $savedDefaultThreshold = $db->query("SELECT soglia_blocco_debito FROM tipologie_lettore WHERE id = {$defaultType}")->fetch_row()[0];
    $db->query("INSERT INTO tipologie_lettore (codice, nome, ordinamento) VALUES ('zz-ledger-{$run}', 'ZZ Ledger {$run}', 999)");
    $ownType = (int) $db->insert_id;
    $setType = static function (int $typeId, ?string $threshold) use ($db): void {
        $stmt = $db->prepare('UPDATE tipologie_lettore SET soglia_blocco_debito = ? WHERE id = ?');
        $stmt->bind_param('si', $threshold, $typeId);
        $stmt->execute();
        $stmt->close();
    };
    try {
        $u3 = $newUser('f');
        $ledger->manualCharge($u3, 700, 'Debito di prova', null);
        $cases = 0;
        foreach ([null, '5.00'] as $global) {
            foreach ([null, '3.00', '0.00', '8.00'] as $defaultThreshold) {
                foreach (['none', null, '6.00', '0.00'] as $own) {
                    $setSetting('debt_block_threshold', $global);
                    $setType($defaultType, $defaultThreshold);
                    $setType($ownType, $own === 'none' ? null : $own);
                    $db->query('UPDATE utenti SET tipologia_id = ' . ($own === 'none' ? 'NULL' : $ownType) . " WHERE id = {$u3}");
                    // Expected: first non-NULL of own type (when assigned), default type, global; > 0 and balance 7 over it.
                    $layers = $own === 'none' ? [$defaultThreshold, $global] : [$own, $global];
                    $threshold = null;
                    foreach ($layers as $layer) {
                        if ($layer !== null) {
                            $threshold = (float) $layer;
                            break;
                        }
                    }
                    $expectBlocked = $threshold !== null && $threshold > 0 && 7.0 > $threshold;
                    $tag = json_encode([$global, $defaultThreshold, $own]);
                    $php = LoanEligibility::checkUser($db, $u3) === 'debt_block';
                    $sql = !$sqlEligible($u3);
                    if ($php !== $expectBlocked || $sql !== $expectBlocked) {
                        $check(false, "debt threshold layers {$tag}: expected " . ($expectBlocked ? 'blocked' : 'eligible') . ', PHP ' . ($php ? 'blocked' : 'eligible') . ', SQL ' . ($sql ? 'blocked' : 'eligible'));
                    }
                    $cases++;
                }
            }
        }
        $check($cases === 32, 'PHP checkUser and SQL eligibleUserWhere agree with the layer rule on 32 combinations (global × default type × own type)');
        $setSetting('debt_block_threshold', '10');
        $setType($defaultType, null);
        $setType($ownType, '5');
        $db->query("UPDATE utenti SET tipologia_id = {$ownType} WHERE id = {$u3}");
        $check(LoanEligibility::checkUser($db, $u3) === 'debt_block' && FineParameters::forUser($db, $u3)->debtBlockThresholdCents === 500, 'the own type threshold (5) beats the global one (10)');
        $db->query("UPDATE utenti SET tipologia_id = NULL WHERE id = {$u3}");
        $check(LoanEligibility::checkUser($db, $u3) === null && FineParameters::forUser($db, $u3)->debtBlockThresholdCents === 1000, 'without a type the patron inherits the global threshold (10, balance 7: eligible)');
    } finally {
        $db->query("UPDATE utenti SET tipologia_id = NULL WHERE email LIKE 'zz-ledger%@test.local'");
        $db->query("DELETE FROM tipologie_lettore WHERE id = {$ownType}");
        $setType($defaultType, $savedDefaultThreshold === null ? null : (string) $savedDefaultThreshold);
    }
} finally {
    foreach ($savedSettings as $k => $v) {
        $setSetting($k, $v);
    }
    $cleanup();
}

echo PHP_EOL . "Result: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
