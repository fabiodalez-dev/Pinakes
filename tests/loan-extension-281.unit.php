<?php
declare(strict_types=1);

/**
 * Behavioural test for issue #281 — loan status recompute on extension.
 *
 * The bug: prestiti.stato is a stored enum, transitioned in_corso -> in_ritardo
 * one-way by the maintenance jobs and never reverted; extending an overdue
 * loan's due date left stato='in_ritardo' stale. PrestitiController::update()
 * (single, Edit-Loan) and PrestitiController::bulkExtend() (many) now recompute
 * stato against the new due date.
 *
 * This exercises the exact SQL both paths use, against a sandbox prestiti table,
 * asserting the transitions, the reminder-flag reset, and the state scoping
 * (prenotato / da_ritirare / returned loans are never touched).
 *
 * Run:  php tests/loan-extension-281.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else { $fail++; echo "  FAIL {$label}\n"; }
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
try {
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$SB = 'zz_loan281_prestiti';
$cleanup = static function () use ($db, $SB): void { $db->query("DROP TABLE IF EXISTS {$SB}"); };

// Sandbox with just the columns the two recompute statements read/write.
$cleanup();
$db->query("CREATE TABLE {$SB} (
    id int NOT NULL AUTO_INCREMENT,
    attivo tinyint(1) NOT NULL DEFAULT 1,
    stato varchar(20) NOT NULL,
    data_scadenza date NOT NULL,
    warning_sent tinyint(1) NOT NULL DEFAULT 0,
    overdue_notification_sent tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$past  = (new DateTimeImmutable('today -10 days'))->format('Y-m-d');
$future = (new DateTimeImmutable('today +20 days'))->format('Y-m-d');

// Helper: read a loan row.
$row = static function (int $id) use ($db, $SB): array {
    return $db->query("SELECT stato, data_scadenza, warning_sent, overdue_notification_sent FROM {$SB} WHERE id={$id}")->fetch_assoc() ?: [];
};

// ── Area 1: single-loan recompute (mirrors PrestitiController::update) ───────
echo "A. Single-loan status recompute on due-date change\n";

// Overdue loan whose due date is pushed to the future -> should become in_corso, reminders reset.
$db->query("INSERT INTO {$SB} (attivo, stato, data_scadenza, warning_sent, overdue_notification_sent)
            VALUES (1, 'in_ritardo', '{$future}', 1, 1)");
$id1 = (int) $db->insert_id;
$recalc = static function (int $id) use ($db, $SB, $today): void {
    $stmt = $db->prepare(
        "UPDATE {$SB}
            SET stato = CASE WHEN data_scadenza < ? THEN 'in_ritardo' ELSE 'in_corso' END,
                warning_sent = CASE WHEN data_scadenza < ? THEN warning_sent ELSE 0 END,
                overdue_notification_sent = CASE WHEN data_scadenza < ? THEN overdue_notification_sent ELSE 0 END
          WHERE id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo')"
    );
    $stmt->bind_param('sssi', $today, $today, $today, $id);
    $stmt->execute();
    $stmt->close();
};
$recalc($id1);
$r = $row($id1);
$check($r['stato'] === 'in_corso', 'overdue loan extended into the future -> in_corso');
$check((int) $r['warning_sent'] === 0 && (int) $r['overdue_notification_sent'] === 0, 'reminder flags reset on revert to in_corso');

// In-corso loan whose due date is moved to the past -> in_ritardo (symmetric).
$db->query("INSERT INTO {$SB} (attivo, stato, data_scadenza) VALUES (1, 'in_corso', '{$past}')");
$id2 = (int) $db->insert_id;
$recalc($id2);
$check($row($id2)['stato'] === 'in_ritardo', 'in_corso loan moved to the past -> in_ritardo');

// A picked-up-but-not-out state must never be touched by the recompute.
$db->query("INSERT INTO {$SB} (attivo, stato, data_scadenza) VALUES (1, 'da_ritirare', '{$past}')");
$id3 = (int) $db->insert_id;
$recalc($id3);
$check($row($id3)['stato'] === 'da_ritirare', 'da_ritirare loan untouched by recompute');

// ── Area 3: bulk extend (mirrors PrestitiController::bulkExtend) ─────────────
echo "B. Bulk extend by N days with scoping\n";
$db->query("TRUNCATE TABLE {$SB}");
// Seed a mix: overdue, in_corso(past->still due soon), prenotato, da_ritirare, returned.
$db->query("INSERT INTO {$SB} (attivo, stato, data_scadenza, warning_sent, overdue_notification_sent) VALUES
    (1, 'in_ritardo', '{$past}', 1, 1),
    (1, 'in_corso',   '{$past}', 0, 0),
    (1, 'prenotato',  '{$past}', 0, 0),
    (1, 'da_ritirare','{$past}', 0, 0),
    (0, 'restituito', '{$past}', 0, 0)");
$ids = [];
$res = $db->query("SELECT id, stato FROM {$SB} ORDER BY id");
while ($x = $res->fetch_assoc()) { $ids[$x['stato']] = (int) $x['id']; }

// Extend ALL five ids by 30 days; only the two physically-out loans must change.
$allIds = array_values($ids);
$days = 30;
$placeholders = implode(',', array_fill(0, count($allIds), '?'));
$sql = "UPDATE {$SB}
           SET data_scadenza = DATE_ADD(data_scadenza, INTERVAL ? DAY),
               stato = CASE WHEN data_scadenza < ? THEN 'in_ritardo' ELSE 'in_corso' END,
               warning_sent = CASE WHEN data_scadenza < ? THEN warning_sent ELSE 0 END,
               overdue_notification_sent = CASE WHEN data_scadenza < ? THEN overdue_notification_sent ELSE 0 END
         WHERE id IN ($placeholders) AND attivo = 1 AND stato IN ('in_corso','in_ritardo')";
$stmt = $db->prepare($sql);
$types = 'isss' . str_repeat('i', count($allIds));
$params = array_merge([$days, $today, $today, $today], $allIds);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

$check($affected === 2, 'bulk extend touches exactly the two out-loans (in_corso + in_ritardo)');
// past (-10) + 30 days = +20 -> future -> both become in_corso.
$check($row($ids['in_ritardo'])['stato'] === 'in_corso', 'overdue loan extended +30d -> in_corso');
$check($row($ids['in_ritardo'])['data_scadenza'] === (new DateTimeImmutable($past . ' +30 days'))->format('Y-m-d'), 'due date advanced by 30 days');
$check((int) $row($ids['in_ritardo'])['warning_sent'] === 0, 'reminder flag reset on the extended overdue loan');
$check($row($ids['in_corso'])['stato'] === 'in_corso', 'in_corso loan stays in_corso');
$check($row($ids['prenotato'])['stato'] === 'prenotato' && $row($ids['prenotato'])['data_scadenza'] === $past, 'prenotato loan untouched (state + date)');
$check($row($ids['da_ritirare'])['stato'] === 'da_ritirare' && $row($ids['da_ritirare'])['data_scadenza'] === $past, 'da_ritirare loan untouched');
$check($row($ids['restituito'])['data_scadenza'] === $past, 'returned (attivo=0) loan untouched');

$cleanup();

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
