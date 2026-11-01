<?php
declare(strict_types=1);

/**
 * Mobile API 1.10.0 — pure logic of the front-desk areas (visitor log,
 * statistics, patron CSV import).
 *
 *   A. AttendancePayload: kiosk type names, counters and day rows in English
 *      keys; a card counts as active on the account status only.
 *   B. UserImportJob::map(): the job before start (suggested mapping aligned
 *      with the headers), after start (confirmed mapping), completed (the last
 *      chunk's counters, errors capped at 50, import_id, report) and
 *      cancelled before start (no report).
 *   C. UserImportJob::unlinkUpload() only removes a staged user_import_ file
 *      inside writable/uploads.
 *   D. LoanStatistics::finesPeriod(): the web page's default period, the
 *      swap of a reversed range, a malformed value ignored.
 *   E. Permissions: the app routes follow their web counterparts for custom
 *      roles (visitor log → loans.manage, patron import → users.manage,
 *      statistics unmapped = administrators and Staff only).
 *
 * Run: php tests/mobile-api-staff-090-desk.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$plugin = __DIR__ . '/../storage/plugins/mobile-api/src/Staff090/Support';
require_once $plugin . '/PatronPayload.php';
require_once $plugin . '/AttendancePayload.php';
require_once $plugin . '/UserImportJob.php';

use App\Plugins\MobileApi\Staff090\Support\AttendancePayload;
use App\Plugins\MobileApi\Staff090\Support\UserImportJob;
use App\Support\LoanStatistics;
use App\Support\Permissions;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

// ── A. Visitor log payloads ───────────────────────────────────────────────────
echo "A. AttendancePayload\n";
$check(AttendancePayload::type('socio') === 'member' && AttendancePayload::type('ospite') === 'guest', 'socio → member, ospite → guest');
$check(AttendancePayload::totals(['soci' => 3, 'ospiti' => 2, 'totale' => 5]) === ['members' => 3, 'guests' => 2, 'total' => 5], 'totals in English keys');
$check(AttendancePayload::day(['giorno' => '2026-11-01', 'soci' => 1, 'ospiti' => 0, 'totale' => 1]) === ['date' => '2026-11-01', 'members' => 1, 'guests' => 0, 'total' => 1], 'day row with its date first');
$check(AttendancePayload::cardActive('attivo') && !AttendancePayload::cardActive('sospeso') && !AttendancePayload::cardActive('scaduto'), 'only an attivo account is an active card (the kiosk rule)');

// ── B. Import job map ─────────────────────────────────────────────────────────
echo "B. UserImportJob::map\n";
$base = [
    'id' => '11111111-2222-4333-8444-555555555555', 'kind' => 'users', 'file_name' => 'soci.csv',
    'status' => 'ready', 'total_rows' => 12, 'processed' => 0, 'chunk_size' => 10,
    'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'expires_at' => '2026-11-02 10:00:00',
    'summary' => null,
    'state' => [
        'core' => ['preview_id' => 'abc', 'file' => '/x/writable/uploads/user_import_abc.csv', 'started' => false],
        'headers' => ['Nome', 'E-mail', 'Password'],
        'preview' => [['Anna', 'a@example.org', 'secret']],
        'fields' => ['nome' => 'Nome', 'cognome' => 'Cognome', 'email' => 'Email', 'custom:4' => 'Socio dal'],
        'suggested' => [0 => 'nome', 1 => 'email', 2 => ''],
        'notice' => null,
    ],
];
$ready = UserImportJob::map($base);
$check($ready['started'] === false && $ready['send_invites'] === null, 'before start: not started, send_invites null');
$check($ready['mapping'] === ['nome', 'email', ''], 'suggested mapping aligned with the headers');
$check(array_column(array_filter($ready['fields'], static fn (array $f): bool => $f['required']), 'key') === ['nome', 'email'], 'nome and email are the required fields');
$check(end($ready['fields']) === ['key' => 'custom:4', 'label' => 'Socio dal', 'required' => false], 'custom fields keep their key');
$check($ready['import_id'] === null && $ready['report_available'] === false && $ready['errors'] === [], 'no report before the run');

$started = $base;
$started['processed'] = 10;
$started['state']['started'] = true;
$started['state']['mapping'] = [0 => 'nome', 2 => 'email'];
$started['state']['send_invites'] = true;
$started['state']['core'] = ['preview_id' => 'abc', 'started' => true, 'created' => 7, 'duplicates' => 2, 'failed' => 1, 'invited' => 7, 'invite_failed' => 0,
    'errors' => [['line' => 3, 'title' => 'Bea', 'message' => 'Email già registrata', 'type' => 'duplicate']]];
$mid = UserImportJob::map($started);
$check($mid['started'] === true && $mid['send_invites'] === true, 'after start: started, invitations as confirmed');
$check($mid['mapping'] === ['nome', '', 'email'], 'confirmed mapping, gaps as ""');
$check($mid['created'] === 7 && $mid['duplicates'] === 2 && $mid['failed'] === 1 && $mid['processed'] === 10, 'counters from the running web state');
$check($mid['errors'] === [['line' => 3, 'title' => 'Bea', 'message' => 'Email già registrata', 'type' => 'duplicate']], 'errors from the running web state');

$done = $started;
$done['status'] = 'completed';
$done['processed'] = 12;
$done['state']['core'] = ['finished' => true, 'preview_id' => 'abc', 'result' => []];
$errors = [];
for ($i = 0; $i < 60; $i++) {
    $errors[] = ['line' => $i + 2, 'title' => 'R' . $i, 'message' => 'm', 'type' => 'validation'];
}
$done['summary'] = ['success' => true, 'processed' => 12, 'created' => 9, 'duplicates' => 2, 'failed' => 1, 'invited' => 8, 'invite_failed' => 1, 'done' => true, 'import_id' => 'imp-1', 'errors' => $errors];
$final = UserImportJob::map($done);
$check($final['complete'] === true && $final['created'] === 9 && $final['invite_failed'] === 1, 'completed: counters from the last chunk');
$check(count($final['errors']) === UserImportJob::MAX_ERRORS && $final['errors'][0]['line'] === 2, 'errors capped at 50');
$check($final['import_id'] === 'imp-1' && $final['report_available'] === true, 'import_id and report available');
$check($final['mapping'] === ['nome', '', 'email'] && $final['started'] === true, 'the mapping survives the web state being replaced');
$check(UserImportJob::importId($done) === 'imp-1' && UserImportJob::importId($base) === null, 'importId(): the summary id or null');

$cancelled = $base;
$cancelled['status'] = 'cancelled';
$cancelled['summary'] = ['import_id' => null];
$c = UserImportJob::map($cancelled);
$check($c['status'] === 'cancelled' && $c['report_available'] === false && $c['import_id'] === null, 'cancelled before start: no report');
$empty = $base;
$empty['summary'] = ['import_id' => ''];
$empty['status'] = 'completed';
$check(UserImportJob::map($empty)['import_id'] === null && UserImportJob::map($empty)['report_available'] === false, 'a history entry that could not be written is no report');

// ── C. Staged upload removal ──────────────────────────────────────────────────
echo "C. UserImportJob::unlinkUpload\n";
$uploads = __DIR__ . '/../writable/uploads';
if (!is_dir($uploads)) {
    @mkdir($uploads, 0755, true);
}
$inside = $uploads . '/user_import_unit' . bin2hex(random_bytes(4)) . '.csv';
file_put_contents($inside, "nome;email\n");
$outsideDir = sys_get_temp_dir() . '/pnk_unit_' . bin2hex(random_bytes(4)) . '/writable/uploads';
@mkdir($outsideDir, 0755, true);
$outside = $outsideDir . '/user_import_x.csv';
file_put_contents($outside, "x\n");
$other = $uploads . '/csv_unit' . bin2hex(random_bytes(4)) . '.csv';
file_put_contents($other, "x\n");
UserImportJob::unlinkUpload(['file' => $outside]);
UserImportJob::unlinkUpload(['file' => $other]);
UserImportJob::unlinkUpload(['file' => 123]);
$check(is_file($outside), 'a user_import_ file outside this installation is left alone');
$check(is_file($other), 'another upload in writable/uploads is left alone');
UserImportJob::unlinkUpload(['file' => $inside]);
$check(!is_file($inside), 'the staged user_import_ file is removed');
@unlink($outside);
@unlink($other);
@rmdir($outsideDir);
@rmdir(dirname($outsideDir));
@rmdir(dirname($outsideDir, 2));

// ── D. Fines period ───────────────────────────────────────────────────────────
echo "D. LoanStatistics::finesPeriod\n";
$check(LoanStatistics::finesPeriod(['fines_to' => '2026-10-15']) === ['2025-11-01', '2026-10-15'], 'default from: first day of the month, 11 months before');
$check(LoanStatistics::finesPeriod(['fines_from' => '2026-05-01', 'fines_to' => '2026-01-01']) === ['2026-01-01', '2026-05-01'], 'a reversed range is swapped');
[$from, $to] = LoanStatistics::finesPeriod(['fines_from' => 'yesterday', 'fines_to' => '2026-13-40']);
$check($to === \App\Support\DateHelper::today() && preg_match('/^\d{4}-\d{2}-01$/', $from) === 1, 'malformed values fall back to the defaults (the web page)');

// ── E. Custom-role mapping ───────────────────────────────────────────────────
echo "E. Permissions::permissionForPath\n";
$check(Permissions::permissionForPath('/api/v1/staff/attendance') === Permissions::permissionForPath('/admin/attendance'), 'visitor log: same permission as /admin/attendance');
$check(Permissions::permissionForPath('/api/v1/staff/attendance/check-ins') === 'loans.manage', 'check-ins → loans.manage');
$check(Permissions::permissionForPath('/api/v1/staff/user-imports/11111111-2222-4333-8444-555555555555/chunk') === 'users.manage', 'patron import → users.manage');
$check(Permissions::permissionForPath('/api/v1/staff/statistics') === null && Permissions::permissionForPath('/admin/statistics') === null, 'statistics unmapped on both sides');
$reader = ['id' => 1, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']];
$check(Permissions::allowsPath('/api/v1/staff/attendance/check-ins', $reader, 'POST') && !Permissions::allowsPath('/api/v1/staff/user-imports', $reader, 'POST') && !Permissions::allowsPath('/api/v1/staff/statistics', $reader), 'a loan-desk role reaches the visitor log only');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
