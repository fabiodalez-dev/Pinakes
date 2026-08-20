<?php
declare(strict_types=1);

/**
 * Behavioural regression for issue #366 follow-up comment #5357382538.
 *
 * Replays the canonical prestiti BEFORE UPDATE trigger on isolated sandbox
 * tables containing the inconsistent pair an older release could leave behind:
 * an overdue physical loan plus its successor already marked da_ritirare on the
 * same copy. The successor must remain editable so staff/maintenance can repair
 * it, while a genuinely new overlap and the copy/book invariant still fail.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
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
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(5));
$loans = "zz366trg_loans_{$suffix}";
$copies = "zz366trg_copies_{$suffix}";
$trigger = "zz366trg_update_{$suffix}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$cleanup = static function () use ($db, $loans, $copies, $trigger): void {
    $db->query("DROP TRIGGER IF EXISTS `{$trigger}`");
    $db->query("DROP TABLE IF EXISTS `{$loans}`");
    $db->query("DROP TABLE IF EXISTS `{$copies}`");
};

try {
    $cleanup();
    $db->query("CREATE TABLE `{$copies}` (
        id INT NOT NULL AUTO_INCREMENT,
        libro_id INT NOT NULL,
        stato VARCHAR(32) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE `{$loans}` (
        id INT NOT NULL AUTO_INCREMENT,
        libro_id INT NOT NULL,
        copia_id INT NULL,
        data_prestito DATE NOT NULL,
        data_scadenza DATE NOT NULL,
        stato VARCHAR(32) NOT NULL,
        attivo TINYINT(1) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_copy (copia_id)
    ) ENGINE=InnoDB");

    // Seed legacy state before installing today's trigger, exactly as an
    // upgraded database presents it to the new application code.
    $db->query("INSERT INTO `{$copies}` (libro_id, stato) VALUES
        (1, 'prestato'), (1, 'disponibile'), (1, 'prestato'), (2, 'disponibile')");
    $db->query("INSERT INTO `{$loans}`
        (libro_id, copia_id, data_prestito, data_scadenza, stato, attivo) VALUES
        (1, 1, '2026-07-27', '2026-08-18', 'in_ritardo', 1),
        (1, 1, '2026-08-19', '2026-08-31', 'da_ritirare', 1),
        (1, 2, '2026-08-01', '2026-08-10', 'in_corso', 1),
        (1, 2, '2026-08-12', '2026-08-20', 'prenotato', 1),
        (1, 3, '2026-07-27', '2026-08-18', 'in_corso', 1),
        (1, 3, '2026-08-19', '2026-08-31', 'prenotato', 1)");

    $source = (string) file_get_contents($root . '/installer/database/triggers.sql');
    if (!preg_match('/CREATE TRIGGER `trg_check_active_prestito_before_update`.*?END\$\$/s', $source, $match)) {
        throw new RuntimeException('Cannot extract canonical prestiti update trigger');
    }
    $ddl = substr($match[0], 0, -2); // mysqli receives CREATE TRIGGER without the client-side $$ delimiter.
    $ddl = str_replace(
        [
            '`trg_check_active_prestito_before_update`',
            'ON `prestiti`',
            'FROM prestiti p',
            'FROM copie c',
            'FROM copie WHERE',
        ],
        [
            "`{$trigger}`",
            "ON `{$loans}`",
            "FROM `{$loans}` p",
            "FROM `{$copies}` c",
            "FROM `{$copies}` WHERE",
        ],
        $ddl
    );
    $db->query($ddl);

    // The exact follow-up: moving the already-conflicting Ready for Pickup row
    // to tomorrow is corrective and must not be frozen by the inherited overlap.
    $correctiveSucceeded = true;
    try {
        $db->query("UPDATE `{$loans}` SET data_prestito = '2026-08-20' WHERE id = 2");
    } catch (mysqli_sql_exception) {
        $correctiveSucceeded = false;
    }
    $check($correctiveSucceeded, 'legacy da_ritirare can move to tomorrow without loan_update_failed');

    // Moving a clean future hold onto a different loan's occupied dates creates
    // a conflict that OLD did not have: the defence-in-depth trigger still blocks it.
    $newConflictBlocked = false;
    try {
        $db->query("UPDATE `{$loans}` SET data_prestito = '2026-08-10' WHERE id = 4");
    } catch (mysqli_sql_exception $e) {
        $newConflictBlocked = str_contains($e->getMessage(), 'Esiste già un prestito attivo');
    }
    $check($newConflictBlocked, 'a genuinely new overlap on the assigned copy is still rejected');

    // The physical loan becoming overdue is an observed lifecycle transition,
    // not a new booking. It must succeed even though its successor already exists.
    $overdueSucceeded = true;
    try {
        $db->query("UPDATE `{$loans}` SET stato = 'in_ritardo' WHERE id = 5");
    } catch (mysqli_sql_exception) {
        $overdueSucceeded = false;
    }
    $check($overdueSucceeded, 'in_corso -> in_ritardo is not blocked by the scheduled successor');

    $bookCopyMismatchBlocked = false;
    try {
        $db->query("UPDATE `{$loans}` SET libro_id = 2 WHERE id = 2");
    } catch (mysqli_sql_exception $e) {
        $bookCopyMismatchBlocked = str_contains($e->getMessage(), 'non appartiene al libro');
    }
    $check($bookCopyMismatchBlocked, 'copy/book integrity remains enforced on every update');
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL exception: ' . $e->getMessage() . PHP_EOL;
} finally {
    $cleanup();
    $db->close();
}

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
