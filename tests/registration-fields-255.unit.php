<?php
declare(strict_types=1);

/**
 * Unit contract for the RegistrationFields surface touched by the mobile-API
 * + profile custom-field work (issue #255): apiDefinitions(),
 * editableFieldsForUser(), hasStoredValues(), the validate() enforceRequired
 * matrix, and the saveValues()/valuesForUser() round-trip.
 *
 * DB-backed but self-contained: it seeds field definitions with a per-run
 * token, a throwaway user, exercises the contract, and cleans up in FK-safe
 * order. 25 checks.
 *
 * Run:  php tests/registration-fields-255.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\RegistrationFields;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// ── DB connect (mirror migration-0.7.37.unit.php) ───────────────────────────
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
    fwrite(STDERR, "FAIL: database unreachable — this suite is DB-backed: {$e->getMessage()}\n");
    exit(1);
}

$TOKEN = 'zzrf255' . substr((string) getmypid(), -4);
$cleanup = static function () use ($db, $TOKEN): void {
    $db->query("DELETE v FROM utenti_campi_valori v JOIN registrazione_campi c ON c.id = v.campo_id WHERE c.etichetta LIKE '{$TOKEN}%'");
    $db->query("DELETE FROM registrazione_campi WHERE etichetta LIKE '{$TOKEN}%'");
    $db->query("DELETE FROM utenti WHERE email LIKE '{$TOKEN}-%@example.test'");
};

try {
    $cleanup();

    // Seed: one active text field, one active required checkbox, one INACTIVE field.
    $db->query("INSERT INTO registrazione_campi (etichetta, tipo, obbligatorio, attivo, ordine) VALUES ('{$TOKEN} Telegram', 'text', 0, 1, 2)");
    $idText = (int) $db->insert_id;
    $db->query("INSERT INTO registrazione_campi (etichetta, tipo, obbligatorio, attivo, ordine) VALUES ('{$TOKEN} News', 'checkbox', 1, 1, 1)");
    $idCheck = (int) $db->insert_id;
    $db->query("INSERT INTO registrazione_campi (etichetta, tipo, obbligatorio, attivo, ordine) VALUES ('{$TOKEN} Dead', 'url', 0, 0, 3)");
    $idDead = (int) $db->insert_id;

    $db->query("INSERT INTO utenti (nome, cognome, email, password, codice_tessera) VALUES ('Rf', '', '{$TOKEN}-1@example.test', 'x', CONCAT('TRF', FLOOR(RAND()*1000000)))");
    $uid = (int) $db->insert_id;

    // ── apiDefinitions() ─────────────────────────────────────────────────────
    echo "A. apiDefinitions()\n";
    $api = RegistrationFields::apiDefinitions($db);
    // Filter to our token so a shared DB with other fields doesn't skew asserts.
    $mine = array_values(array_filter($api, static fn ($d) => str_contains((string) $d['label'], $TOKEN)));
    $check(count($mine) === 2, 'apiDefinitions returns only ACTIVE fields (2 of 3, dead excluded)');
    $check(array_keys($mine[0]) === ['id', 'label', 'type', 'required'], 'apiDefinitions shape is {id,label,type,required} (no italian keys)');
    $check($mine[0]['label'] === "{$TOKEN} News" && $mine[1]['label'] === "{$TOKEN} Telegram", 'apiDefinitions honours ordine (News before Telegram)');
    $check($mine[0]['required'] === true && $mine[1]['required'] === false, 'apiDefinitions maps obbligatorio → required bool');
    $check(!array_filter($api, static fn ($d) => ($d['label'] ?? '') === "{$TOKEN} Dead"), 'apiDefinitions excludes the inactive field');

    // ── hasStoredValues() ────────────────────────────────────────────────────
    echo "B. hasStoredValues()\n";
    $check(RegistrationFields::hasStoredValues($db, $idText) === false, 'hasStoredValues false before any value stored');
    $check(RegistrationFields::hasStoredValues($db, 0) === false, 'hasStoredValues false for id <= 0');
    $check(RegistrationFields::hasStoredValues($db, 999999999) === false, 'hasStoredValues false for unknown id');

    // ── validate() enforceRequired matrix ────────────────────────────────────
    echo "C. validate() enforceRequired matrix\n";
    $defs = RegistrationFields::definitions($db);
    $defsMine = array_values(array_filter($defs, static fn ($d) => str_contains((string) $d['etichetta'], $TOKEN) && $d['attivo']));

    // required checkbox unchecked → missing when enforced, ok when not.
    $r = RegistrationFields::validate($defsMine, ['custom_field' => [$idText => 'x']], true);
    $check($r['error'] !== null && $r['error_reason'] === 'missing', 'enforceRequired=true: unchecked required checkbox → missing');
    $r = RegistrationFields::validate($defsMine, ['custom_field' => [$idText => 'x']], false);
    $check($r['error'] === null, 'enforceRequired=false: unchecked required checkbox tolerated');

    // checkbox normalisation
    $r = RegistrationFields::validate($defsMine, ['custom_field' => [$idCheck => 'on', $idText => 'x']], true);
    $check($r['error'] === null && $r['values'][$idCheck] === '1', 'checkbox normalises truthy → "1"');
    $r = RegistrationFields::validate($defsMine, ['custom_field' => [$idCheck => '', $idText => 'x']], false);
    $check(($r['values'][$idCheck] ?? null) === '', 'checkbox normalises blank → ""');

    // non-scalar payload rejected as format
    $r = RegistrationFields::validate($defsMine, ['custom_field' => [$idText => ['x'], $idCheck => 'on']], false);
    $check($r['error'] !== null && $r['error_reason'] === 'format', 'non-scalar custom value rejected as format');

    // over-long value
    $r = RegistrationFields::validate($defsMine, ['custom_field' => [$idText => str_repeat('a', 1001), $idCheck => 'on']], false);
    $check($r['error'] !== null && $r['error_reason'] === 'format', 'over-long value rejected as format');

    // text field accepts a normal value; values map returned
    $r = RegistrationFields::validate($defsMine, ['custom_field' => [$idText => '@handle', $idCheck => 'on']], true);
    $check($r['error'] === null && $r['values'][$idText] === '@handle', 'valid text value passes and is returned');

    // ── saveValues() / valuesForUser() round-trip ────────────────────────────
    echo "D. saveValues() / valuesForUser() round-trip\n";
    RegistrationFields::saveValues($db, $uid, [$idText => '@handle', $idCheck => '1']);
    $vals = RegistrationFields::valuesForUser($db, $uid);
    $check(($vals[$idText] ?? null) === '@handle' && ($vals[$idCheck] ?? null) === '1', 'saveValues persists, valuesForUser reads back');
    $check(RegistrationFields::hasStoredValues($db, $idText) === true, 'hasStoredValues true after a value is stored');

    // empty string clears the row
    RegistrationFields::saveValues($db, $uid, [$idText => '']);
    $vals = RegistrationFields::valuesForUser($db, $uid);
    $check(!array_key_exists($idText, $vals), 'saveValues("") deletes the row (field cleared)');
    $check(($vals[$idCheck] ?? null) === '1', 'clearing one field leaves the others intact');

    // per-user isolation
    $db->query("INSERT INTO utenti (nome, cognome, email, password, codice_tessera) VALUES ('Rf2', '', '{$TOKEN}-2@example.test', 'x', CONCAT('TRG', FLOOR(RAND()*1000000)))");
    $uid2 = (int) $db->insert_id;
    $check(RegistrationFields::valuesForUser($db, $uid2) === [], 'valuesForUser is per-user (other user has none)');
    $check(RegistrationFields::valuesForUser($db, 0) === [], 'valuesForUser empty for id <= 0');

    // ── editableFieldsForUser() ──────────────────────────────────────────────
    echo "E. editableFieldsForUser()\n";
    $edit = RegistrationFields::editableFieldsForUser($db, $uid);
    $editMine = array_values(array_filter($edit, static fn ($d) => str_contains((string) $d['label'], $TOKEN)));
    $check(count($editMine) === 2, 'editableFieldsForUser returns the active fields');
    $check(array_key_exists('value', $editMine[0]), 'editableFieldsForUser includes a value key');
    $byLabel = [];
    foreach ($editMine as $e) { $byLabel[$e['label']] = $e; }
    $check($byLabel["{$TOKEN} News"]['value'] === '1', "editableFieldsForUser carries this user's stored value");
    $check($byLabel["{$TOKEN} Telegram"]['value'] === '', 'editableFieldsForUser returns "" for an unset field');

    // ── labelledValuesForUser() only surfaces non-empty ──────────────────────
    echo "F. labelledValuesForUser()\n";
    $labelled = RegistrationFields::labelledValuesForUser($db, $uid);
    $labelledMine = array_values(array_filter($labelled, static fn ($d) => str_contains((string) $d['etichetta'], $TOKEN)));
    $check(count($labelledMine) === 1 && $labelledMine[0]['etichetta'] === "{$TOKEN} News", 'labelledValuesForUser surfaces only fields with a stored value');
} finally {
    $cleanup();
    $db->close();
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
