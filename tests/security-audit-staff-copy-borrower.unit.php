<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11, mobile-api/staff-copy-reader/on-loan-borrower-name-unredacted).
 *
 * GET/PUT /api/v1/staff/copies/{id} follow catalog.manage (Permissions::permissionForPath),
 * and both return StaffCopyReader's read model. Its on_loan block named the
 * current borrower to any caller, so a cataloguing-only custom role could walk
 * copy ids and learn who has which book. Borrower identity is loan-desk data:
 * StaffCopyReader::map() must emit on_loan.user_name only when the viewer holds
 * loans.manage, and keep loan_id/due_at for everybody.
 *
 * DB-free: map() is a pure function of the row and of the session identity that
 * AppAuthMiddleware mirrors into $_SESSION['user'].
 *
 * Run:  php tests/security-audit-staff-copy-borrower.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/StaffLoanReader.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/StaffCopyReader.php';

use App\Plugins\MobileApi\Support\StaffCopyReader;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// map() never touches the connection: build the reader without one.
$reader = (new ReflectionClass(StaffCopyReader::class))->newInstanceWithoutConstructor();

$row = [
    'id' => 5, 'libro_id' => 9, 'numero_inventario' => 'INV-1', 'stato' => 'prestato',
    'note' => null, 'created_at' => null, 'updated_at' => null, 'solo_consultazione' => 0,
    'titolo' => 'Audit Book', 'copertina_url' => null, 'held' => 1, 'has_history' => 1,
    'on_loan' => '42|2026-12-13|Audit Reader',
];

$asViewer = static function (array $user) use ($reader, $row): array {
    $_SESSION['user'] = $user;
    try {
        return $reader->map($row);
    } finally {
        unset($_SESSION['user']);
    }
};

echo "A. Cataloguing-only custom role (backend.access + catalog.manage)\n";
$cataloguer = ['id' => 7, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'catalog.manage']];
$out = $asViewer($cataloguer);
$check(is_array($out['on_loan']), 'on_loan block is still present (the copy is out)');
$check(($out['on_loan']['loan_id'] ?? null) === 42, 'loan_id kept');
$check(($out['on_loan']['due_at'] ?? null) === '2026-12-13', 'due_at kept');
$check(array_key_exists('user_name', $out['on_loan']) && $out['on_loan']['user_name'] === null, 'user_name redacted to null');
$check(!str_contains((string) json_encode($out), 'Audit Reader'), 'borrower name absent from the whole payload');

echo "B. Viewers allowed to see the borrower\n";
$loanDesk = ['id' => 8, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']];
$check(($asViewer($loanDesk)['on_loan']['user_name'] ?? null) === 'Audit Reader', 'custom role with loans.manage sees the borrower');
$check(($asViewer(['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo'])['on_loan']['user_name'] ?? null) === 'Audit Reader', 'admin sees the borrower');
$check(($asViewer(['id' => 2, 'tipo_utente' => 'staff', 'stato' => 'attivo'])['on_loan']['user_name'] ?? null) === 'Audit Reader', 'staff sees the borrower');

echo "C. No identity and suspended accounts fail closed\n";
$_SESSION = [];
$check($reader->map($row)['on_loan']['user_name'] === null, 'no session identity: redacted');
$suspended = $asViewer(['id' => 8, 'tipo_utente' => 'standard', 'stato' => 'sospeso', 'permissions' => ['loans.manage']]);
$check(array_key_exists('user_name', $suspended['on_loan']) && $suspended['on_loan']['user_name'] === null, 'suspended loans.manage account: redacted');

echo "D. A copy that is not on loan is unchanged\n";
$free = $row;
$free['on_loan'] = null;
$_SESSION['user'] = $cataloguer;
$check($reader->map($free)['on_loan'] === null, 'on_loan stays null for a copy on the shelf');
unset($_SESSION['user']);

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
