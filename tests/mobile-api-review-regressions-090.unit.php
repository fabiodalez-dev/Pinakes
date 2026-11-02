<?php
declare(strict_types=1);

/** Real-DB regressions for the CodeRabbit follow-ups on PR #518.
 * Uses a dedicated test DB: module rollback tests temporarily edit settings.
 * Run with E2E_DB_NAME=pinakes_test (or PINAKES_ISOLATED_TEST_DB=1).
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (getenv('PINAKES_ISOLATED_TEST_DB') !== '1' && !str_contains((string) getenv('E2E_DB_NAME'), 'test')) {
    fwrite(STDERR, "FAIL: select a dedicated test database before running this suite.\n");
    exit(1);
}
Dotenv\Dotenv::createImmutable($root)->safeLoad();
require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';

use App\Plugins\MobileApi\Controllers\StaffCopiesController;
use App\Plugins\MobileApi\Staff090\Controllers\CirculationRulesController;
use App\Plugins\MobileApi\Staff090\Controllers\LibrarySettingsController;
use App\Plugins\MobileApi\Staff090\Controllers\StaffCopyCatalogingController;
use App\Plugins\MobileApi\Staff090\Controllers\StaffSuppliersController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\ImportJobStore;
use App\Plugins\MobileApi\Support\StaffLoanReader;
use App\Support\ConfigStore;
use App\Support\Features;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

final class ReviewInterleavedDb extends mysqli
{
    public ?Closure $beforeSupplier = null;
    public ?Closure $beforeBegin = null;
    public ?string $failPrefix = null;
    public int $settingsWrites = 0;
    public int $failSettingsWrite = 0;
    public int $statements = 0;

    public function prepare(string $query): mysqli_stmt|false
    {
        $this->statements++;
        if (str_starts_with($query, 'UPDATE fornitori SET ') && $this->beforeSupplier !== null) {
            $work = $this->beforeSupplier;
            $this->beforeSupplier = null;
            $work();
        }
        if ($this->failPrefix !== null && str_starts_with($query, $this->failPrefix)) {
            $this->failPrefix = null;
            throw new RuntimeException('Injected copy write failure');
        }
        if (str_starts_with($query, 'INSERT INTO system_settings')) {
            $this->settingsWrites++;
            if ($this->settingsWrites === $this->failSettingsWrite) {
                throw new RuntimeException('Injected modules write failure');
            }
        }
        return parent::prepare($query);
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->statements++;
        return parent::query($query, $result_mode);
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        if ($this->beforeBegin !== null) {
            $work = $this->beforeBegin;
            $this->beforeBegin = null;
            $work();
        }
        return parent::begin_transaction($flags, $name);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$args = [getenv('E2E_DB_HOST') ?: $env['DB_HOST'], getenv('E2E_DB_USER') ?: $env['DB_USER'], getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), getenv('E2E_DB_NAME') ?: $env['DB_NAME'], (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null)];
$db = new ReviewInterleavedDb(...$args);
$other = new mysqli(...$args);
$db->set_charset('utf8mb4');
$other->set_charset('utf8mb4');
\App\Support\DateHelper::synchronizeDatabaseSession($db);
$plugin = new \App\Plugins\MobileApi\MobileApiPlugin($db, new \App\Support\HookManager($db));
$schema = $plugin->ensureSchema();
if ($schema['failed'] !== []) {
    throw new RuntimeException('Mobile API schema not ready');
}
$run = bin2hex(random_bytes(5));
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};
$insert = static function (string $sql) use ($db): int {
    $db->query($sql);
    return (int) $db->insert_id;
};
$priorSession = $_SESSION ?? [];
$settings = $db->query('SELECT category, setting_key, setting_value FROM system_settings ORDER BY category, setting_key')->fetch_all(MYSQLI_ASSOC);
$restoreSettings = static function () use ($db, $settings): void {
    $db->query('DELETE FROM system_settings');
    $stmt = $db->prepare('INSERT INTO system_settings(category,setting_key,setting_value) VALUES(?,?,?)');
    foreach ($settings as $row) {
        $stmt->bind_param('sss', $row['category'], $row['setting_key'], $row['setting_value']);
        $stmt->execute();
    }
    $stmt->close();
    ConfigStore::clearCache();
};
$pluginRow = $db->query("SELECT id,is_active FROM plugins WHERE name='z39-server'")->fetch_assoc();
$users = [];
$book = $supplier = $type = 0;
$jobId = null;
try {
    $operator = $insert("INSERT INTO utenti(nome,cognome,email,password,tipo_utente,stato,email_verificata,codice_tessera) VALUES('Review','Test','zzreview-{$run}@example.invalid','x','admin','attivo',1,'R{$run}')");
    $users[] = $operator;
    $_SESSION = ['user' => ['id' => $operator, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
    $request = static function (string $path, array $body, ?string $key = null) use ($operator) {
        $req = (new ServerRequestFactory())->createServerRequest('PUT', $path)
            ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $operator, 'tipo_utente' => 'admin'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
        return $key === null ? $req : $req->withHeader('Idempotency-Key', $key)->withMethod('POST');
    };
    Features::override('suppliers', true);
    Features::override('circulation_rules', true);
    Features::override('consultation_only', true);
    $supplier = $insert("INSERT INTO fornitori(nome,telefono) VALUES('ZZ review {$run}','111')");
    $db->beforeSupplier = static function () use ($other, $supplier): void {
        $other->query("UPDATE fornitori SET telefono='222' WHERE id={$supplier}");
    };
    $r = (new StaffSuppliersController($db))->update($request('/suppliers', ['name' => "ZZ renamed {$run}"]), new Response(), $supplier);
    $row = $db->query("SELECT nome,telefono FROM fornitori WHERE id={$supplier}")->fetch_assoc();
    $check($r->getStatusCode() === 200 && $row['telefono'] === '222' && $row['nome'] === "ZZ renamed {$run}", 'partial supplier update preserves a concurrent omitted field');
    $r = (new StaffSuppliersController($db))->update($request('/suppliers', ['phone' => null]), new Response(), $supplier);
    $check($r->getStatusCode() === 200 && $db->query("SELECT telefono FROM fornitori WHERE id={$supplier}")->fetch_row()[0] === null, 'explicit supplier null still clears a field');

    $type = $insert("INSERT INTO tipologie_lettore(codice,nome,attiva) VALUES('zzreview-{$run}','ZZ review {$run}',1)");
    $rules = new CirculationRulesController($db);
    $db->beforeBegin = static function () use ($other, $type): void {
        $other->query("INSERT INTO regole_prestito(tipologia_id,tipo_media,durata_prestito_giorni) VALUES({$type},'dvd',21)");
    };
    $r = $rules->updateRules($request('/rules', ['cells' => [['type_id' => $type, 'media' => 'libro', 'loan_duration_days' => 14]]]), new Response());
    $check($r->getStatusCode() === 200 && (int) $db->query("SELECT durata_prestito_giorni FROM regole_prestito WHERE tipologia_id={$type} AND tipo_media='dvd'")->fetch_row()[0] === 21, 'partial rules update preserves a concurrently inserted different cell');
    $db->beforeBegin = static function () use ($other, $type): void {
        $other->query("UPDATE regole_prestito SET max_rinnovi=7 WHERE tipologia_id={$type} AND tipo_media='libro'");
    };
    $r = $rules->updateRules($request('/rules', ['cells' => [['type_id' => $type, 'media' => 'libro', 'loan_duration_days' => 17, 'daily_fine_cents' => 125]]]), new Response());
    $row = $db->query("SELECT * FROM regole_prestito WHERE tipologia_id={$type} AND tipo_media='libro'")->fetch_assoc();
    $check($r->getStatusCode() === 200 && (int) $row['max_rinnovi'] === 7 && (int) $row['durata_prestito_giorni'] === 17 && $row['multa_giornaliera'] === '1.25', 'same-cell concurrent fields and cents survive the patch');
    $r = $rules->updateRules($request('/rules', ['cells' => [['type_id' => $type, 'media' => 'libro', 'loan_duration_days' => null, 'max_renewals' => null, 'daily_fine_cents' => null]]]), new Response());
    $check($r->getStatusCode() === 200 && $db->query("SELECT id FROM regole_prestito WHERE tipologia_id={$type} AND tipo_media='libro'")->num_rows === 0, 'explicit nulls delete only the now-empty rule');

    $book = $insert("INSERT INTO libri(titolo) VALUES('ZZ combined {$run}')");
    $copy = $insert("INSERT INTO copie(libro_id,numero_inventario,stato,solo_consultazione) VALUES({$book},'ZZR{$run}','disponibile',0)");
    $copies = new StaffCopiesController($db);
    $db->failPrefix = 'UPDATE copie SET stato = ?';
    $r = $copies->update($request('/copies', ['status' => 'manutenzione', 'consultation_only' => true]), new Response(), $copy);
    $row = $db->query("SELECT stato,solo_consultazione FROM copie WHERE id={$copy}")->fetch_assoc();
    $check($r->getStatusCode() === 409 && $row['stato'] === 'disponibile' && (int) $row['solo_consultazione'] === 0, 'failed combined copy write rolls back the consultation flag too');
    $r = $copies->update($request('/copies', ['status' => 'manutenzione', 'consultation_only' => true]), new Response(), $copy);
    $row = $db->query("SELECT stato,solo_consultazione FROM copie WHERE id={$copy}")->fetch_assoc();
    $check($r->getStatusCode() === 200 && $row['stato'] === 'manutenzione' && (int) $row['solo_consultazione'] === 1, 'successful combined copy write saves both fields');
    // The flag reaches the book history, as the web toggle records it.
    $flagLog = static function () use ($db, $book): array {
        // The latest entry that carries the flag: a return logs the loan too.
        $entry = $db->query("SELECT dati_precedenti, dati_nuovi FROM log_modifiche WHERE tabella='libri' AND record_id={$book} AND dati_nuovi LIKE '%solo_consultazione%' ORDER BY id DESC LIMIT 1")->fetch_assoc() ?: [];
        $before = json_decode((string) ($entry['dati_precedenti'] ?? ''), true);
        $after = json_decode((string) ($entry['dati_nuovi'] ?? ''), true);
        return [$before['solo_consultazione'] ?? null, $after['solo_consultazione'] ?? null];
    };
    $check($flagLog() === [0, 1], 'the combined write records the consultation flag in the book history');
    $flagEntries = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id={$book} AND dati_nuovi LIKE '%solo_consultazione%'")->fetch_row()[0];
    $r = $copies->update($request('/copies', ['status' => 'disponibile', 'consultation_only' => false]), new Response(), $copy);
    $check($r->getStatusCode() === 200 && (int) $db->query("SELECT solo_consultazione FROM copie WHERE id={$copy}")->fetch_row()[0] === 0, 'combined lendable transition succeeds');

    // The physical-return branch owns a different transaction; its flag
    // must roll back too if closing the loan fails.
    $loan = $insert("INSERT INTO prestiti(libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,attivo) VALUES({$book},{$copy},{$operator},CURDATE(),DATE_ADD(CURDATE(),INTERVAL 14 DAY),'in_corso',1)");
    $db->query("UPDATE copie SET stato='prestato',solo_consultazione=1 WHERE id={$copy}");
    $entriesBefore = $flagEntries();
    $db->failPrefix = 'UPDATE prestiti SET stato = ?';
    $r = $copies->update($request('/copies', ['status' => 'disponibile', 'consultation_only' => false]), new Response(), $copy);
    $row = $db->query("SELECT stato,solo_consultazione FROM copie WHERE id={$copy}")->fetch_assoc();
    $check($r->getStatusCode() === 409 && $row['stato'] === 'prestato' && (int) $row['solo_consultazione'] === 1 && (int) $db->query("SELECT attivo FROM prestiti WHERE id={$loan}")->fetch_row()[0] === 1, 'failed return rolls back the flag and keeps the loan open');
    $r = $copies->update($request('/copies', ['status' => 'disponibile', 'consultation_only' => false]), new Response(), $copy);
    $row = $db->query("SELECT stato,solo_consultazione FROM copie WHERE id={$copy}")->fetch_assoc();
    $check($r->getStatusCode() === 200 && (int) $row['solo_consultazione'] === 0 && (int) $db->query("SELECT attivo FROM prestiti WHERE id={$loan}")->fetch_row()[0] === 0 && $flagEntries() === $entriesBefore + 1 && $flagLog() === [1, 0],
        'a return that clears the flag closes the loan and records the flag in the book history');
    $db->query("DELETE FROM prestiti WHERE id={$loan}");
    $db->query("UPDATE copie SET stato='disponibile',solo_consultazione=0 WHERE id={$copy}");

    if (!$pluginRow) {
        $db->query("INSERT INTO plugins(name,display_name,version,path,main_file,is_active) VALUES('z39-server','Review Z39','test','z39-server','Z39ServerPlugin.php',1)");
    } else {
        $db->query("UPDATE plugins SET is_active=1 WHERE id=" . (int) $pluginRow['id']);
    }
    Features::override('marc', true);
    Features::override('suppliers', false);
    $stored = [];
    for ($n = 1; $n <= 21; $n++) {
        $stored[str_pad(dechex($n), 16, '0', STR_PAD_LEFT)] = ['server' => 'local', 'data' => ['titolo' => "ZZ replay {$run} {$n}"]];
    }
    $store = new ImportJobStore($db);
    $jobId = $store->create($operator, null, 'copy_cataloging', 'review regression', 21, 1, false, ['results' => $stored, 'imports' => []]);
    $cataloguing = new StaffCopyCatalogingController($db);
    $call = static fn (int $n, string $mode = 'skip') => $cataloguing->import($request('/copy-cataloging/import', ['search_id' => $jobId, 'ids' => [str_pad(dechex($n), 16, '0', STR_PAD_LEFT)], 'duplicates' => $mode, 'covers' => false], "review-{$run}-{$n}"), new Response());
    $first = null;
    for ($n = 1; $n <= 21; $n++) {
        $r = $call($n);
        $check($r->getStatusCode() === 200, "copy cataloguing import {$n} accepted");
        if ($n === 1) {
            $first = json_decode((string) $r->getBody(), true)['data'];
        }
    }
    $r = $call(1);
    $body = json_decode((string) $r->getBody(), true);
    $check($r->getStatusCode() === 200 && ($body['meta']['replayed'] ?? false) === true && $body['data'] === $first, 'first key still replays the original result after 21 imports');
    $check((int) $db->query("SELECT COUNT(*) FROM libri WHERE titolo='ZZ replay {$run} 1'")->fetch_row()[0] === 1, 'replay creates no duplicate book without ISBN/EAN/author');
    $check($call(1, 'overwrite')->getStatusCode() === 422, 'old key with changed payload still conflicts');

    // Batch payload parity and bounded statement count with fines and debt on.
    Features::override('fines', true);
    Features::override('debt_block', true);
    Features::override('loss_penalties', false);
    $db->query("UPDATE tipologie_lettore SET soglia_blocco_debito='2.00' WHERE id={$type}");
    for ($n = 0; $n < 25; $n++) {
        $uid = $insert("INSERT INTO utenti(nome,cognome,email,password,tipo_utente,stato,email_verificata,tipologia_id,codice_tessera) VALUES('Batch','ZZ{$run}','zzbatch-{$run}-{$n}@example.invalid','x','standard','attivo',1,{$type},'RB{$run}{$n}')");
        $users[] = $uid;
        if ($n === 0) {
            $db->query("INSERT INTO movimenti_lettore(utente_id,tipo,importo,descrizione) VALUES({$uid},'addebito_manuale',3.00,'review')");
        }
    }
    $reader = new StaffLoanReader($db);
    $start = $db->statements;
    $one = $reader->users("zzbatch-{$run}-0@", 1);
    $smallCount = $db->statements - $start;
    $start = $db->statements;
    $many = $reader->users("zzbatch-{$run}-", 50);
    $largeCount = $db->statements - $start;
    $check(count($many) === 25 && $largeCount <= $smallCount + 3, '25 users do not add per-user ledger/eligibility queries');
    foreach ($many as $row) {
        $check($row === (new StaffLoanReader($db))->user($row['id']), 'batch ledger and eligibility match the authoritative single-user payload');
    }
    Features::override('fines', false);
    $withoutFines = (new StaffLoanReader($db))->users("zzbatch-{$run}-", 50);
    $check(count($withoutFines) === 25 && $withoutFines[0]['ledger'] === null, 'batch ledger stays absent with fines disabled');

    // A late failure in the second settings tab must undo the first one.
    Features::resetOverrides();
    $modules = new LibrarySettingsController($db);
    $before = Features::snapshot();
    $body = ['features' => ['fines' => !$before['fines'], 'patron_photos' => !$before['patron_photos']]];
    $db->settingsWrites = 0;
    $r = $modules->updateModules($request('/modules', $body), new Response());
    $check($r->getStatusCode() === 200, 'combined modules update succeeds');
    $writes = $db->settingsWrites;
    $restoreSettings();
    $db->settingsWrites = 0;
    $db->failSettingsWrite = $writes;
    $r = $modules->updateModules($request('/modules', $body), new Response());
    $db->failSettingsWrite = 0;
    $check($r->getStatusCode() === 500, 'late second-tab write failure is reported');
    $actual = $db->query('SELECT category,setting_key,setting_value FROM system_settings ORDER BY category,setting_key')->fetch_all(MYSQLI_ASSOC);
    $check($actual === $settings, 'failure restores every setting from both tabs, including previously absent keys');
    $check(Features::snapshot() === $before, 'rolled-back switches do not remain in the settings cache');

    // Twenty-five additional release scenarios, beyond the fault/concurrency
    // regressions above. Each case calls real controllers and checks stored data.
    $additional = [];
    $sameSupplier = static fn (): array => $db->query("SELECT nome,email,telefono FROM fornitori WHERE id={$supplier}")->fetch_assoc();
    $suppliers = new StaffSuppliersController($db);
    $additional['invalid supplier email cannot partly rename the supplier'] = static function () use ($suppliers, $request, $supplier, $sameSupplier, $check): void {
        $before = $sameSupplier();
        $r = $suppliers->update($request('/suppliers', ['name' => 'Wrong partial name', 'email' => 'bad-address']), new Response(), $supplier);
        $check($r->getStatusCode() === 422 && $sameSupplier() === $before, 'invalid email leaves the supplier unchanged');
    };
    $additional['blank supplier name cannot partly change the phone'] = static function () use ($suppliers, $request, $supplier, $sameSupplier, $check): void {
        $before = $sameSupplier();
        $r = $suppliers->update($request('/suppliers', ['name' => '', 'phone' => '999']), new Response(), $supplier);
        $check($r->getStatusCode() === 422 && $sameSupplier() === $before, 'blank name leaves the supplier unchanged');
    };
    $additional['empty supplier patch is a successful no-op'] = static function () use ($suppliers, $request, $supplier, $sameSupplier, $check): void {
        $before = $sameSupplier();
        $r = $suppliers->update($request('/suppliers', []), new Response(), $supplier);
        $check($r->getStatusCode() === 200 && $sameSupplier() === $before, 'empty supplier patch preserves all fields');
    };
    $ruleRow = static fn (): ?array => $db->query("SELECT * FROM regole_prestito WHERE tipologia_id={$type} AND tipo_media='libro'")->fetch_assoc();
    $patchRules = static fn (array $cells) => $rules->updateRules($request('/rules', ['cells' => $cells]), new Response());
    $additional['one invalid rules cell prevents every cell from being saved'] = static function () use ($patchRules, $type, $ruleRow, $check): void {
        $before = $ruleRow();
        $r = $patchRules([['type_id' => $type, 'media' => 'libro', 'loan_duration_days' => 31], ['type_id' => $type, 'media' => 'dvd', 'max_renewals' => -1]]);
        $check($r->getStatusCode() === 422 && $ruleRow() === $before, 'multi-cell validation is all-or-nothing');
    };
    $additional['clearing one rules field preserves the other fields'] = static function () use ($patchRules, $type, $ruleRow, $check): void {
        $patchRules([['type_id' => $type, 'media' => 'libro', 'loan_duration_days' => 21, 'max_renewals' => 4]]);
        $r = $patchRules([['type_id' => $type, 'media' => 'libro', 'loan_duration_days' => null]]);
        $row = $ruleRow();
        $check($r->getStatusCode() === 200 && $row['durata_prestito_giorni'] === null && (int) $row['max_rinnovi'] === 4, 'null clears only the named field');
    };
    $additional['false and zero rule overrides do not become inherited nulls'] = static function () use ($patchRules, $type, $ruleRow, $check): void {
        $r = $patchRules([['type_id' => $type, 'media' => 'libro', 'loanable' => false, 'max_renewals' => 0, 'daily_fine_cents' => 0]]);
        $row = $ruleRow();
        $check($r->getStatusCode() === 200 && $row['prestabile'] === '0' && $row['max_rinnovi'] === '0' && $row['multa_giornaliera'] === '0.00', 'explicit false and zero are stored');
    };
    $additional['repeated cells in one patch merge their distinct fields'] = static function () use ($patchRules, $type, $ruleRow, $check): void {
        $r = $patchRules([['type_id' => $type, 'media' => 'libro', 'loan_duration_days' => 18], ['type_id' => $type, 'media' => 'libro', 'max_renewals' => 3]]);
        $row = $ruleRow();
        $check($r->getStatusCode() === 200 && (int) $row['durata_prestito_giorni'] === 18 && (int) $row['max_rinnovi'] === 3, 'duplicate cell entries retain both changes');
    };
    $additional['a cell containing only its coordinates is a no-op'] = static function () use ($patchRules, $type, $ruleRow, $check): void {
        $before = $ruleRow();
        $r = $patchRules([['type_id' => $type, 'media' => 'libro']]);
        $check($r->getStatusCode() === 200 && $ruleRow() === $before, 'coordinate-only cell is unchanged');
    };
    $additional['editing inactive rules does not switch the feature on'] = static function () use ($patchRules, $type, $ruleRow, $check): void {
        $before = $ruleRow();
        Features::override('circulation_rules', false);
        try {
            $r = $patchRules([['type_id' => $type, 'media' => 'libro', 'max_renewals' => 9]]);
            $check($r->getStatusCode() === 200 && (int) $ruleRow()['max_rinnovi'] === 9 && !Features::enabled('circulation_rules'), 'configuration remains editable while rules stay inactive');
        } finally {
            Features::override('circulation_rules', true);
        }
    };
    $allSettings = static fn (): array => $db->query('SELECT category,setting_key,setting_value FROM system_settings ORDER BY category,setting_key')->fetch_all(MYSQLI_ASSOC);
    $additional['features null is rejected without touching settings'] = static function () use ($modules, $request, $allSettings, $check): void {
        $before = $allSettings();
        $r = $modules->updateModules($request('/modules', ['features' => null]), new Response());
        $check($r->getStatusCode() === 422 && $allSettings() === $before, 'null features writes nothing');
    };
    $additional['an unknown module prevents valid switches in the same request'] = static function () use ($modules, $request, $allSettings, $check): void {
        $before = $allSettings();
        $r = $modules->updateModules($request('/modules', ['features' => ['patron_photos' => false, 'unknown_module' => true]]), new Response());
        $check($r->getStatusCode() === 422 && $allSettings() === $before, 'unknown switch rejects the whole request');
    };
    $additional['invalid retention cannot partly change a module switch'] = static function () use ($modules, $request, $allSettings, $check): void {
        $before = $allSettings();
        $r = $modules->updateModules($request('/modules', ['features' => ['patron_photos' => false], 'attendance_retention_days' => -1]), new Response());
        $check($r->getStatusCode() === 422 && $allSettings() === $before, 'negative retention writes nothing');
    };
    $additional['changing one module preserves every omitted switch'] = static function () use ($modules, $request, $check, $restoreSettings): void {
        Features::resetOverrides();
        $before = Features::snapshot();
        try {
            $r = $modules->updateModules($request('/modules', ['features' => ['patron_photos' => !$before['patron_photos']]]), new Response());
            $after = Features::snapshot();
            $expected = $before;
            $expected['patron_photos'] = !$before['patron_photos'];
            $check($r->getStatusCode() === 200 && $after === $expected, 'one switch changes and omitted switches stay unchanged');
        } finally {
            $restoreSettings();
        }
    };
    $additional['zero retention keeps all module switches unchanged'] = static function () use ($modules, $request, $db, $check, $restoreSettings): void {
        $before = Features::snapshot();
        try {
            $r = $modules->updateModules($request('/modules', ['attendance_retention_days' => 0]), new Response());
            $days = (new \App\Models\SettingsRepository($db))->get('attendance', 'retention_days');
            $check($r->getStatusCode() === 200 && $days === '0' && Features::snapshot() === $before, 'zero retention means no automatic purge, with switches preserved');
        } finally {
            $restoreSettings();
        }
    };
    $additional['turning fines off retains a stored child switch but disables it effectively'] = static function () use ($modules, $request, $check, $restoreSettings): void {
        try {
            $r = $modules->updateModules($request('/modules', ['features' => ['fines' => false, 'debt_block' => true]]), new Response());
            $check($r->getStatusCode() === 200 && Features::stored('debt_block') && !Features::enabled('debt_block'), 'parent off disables debt blocking without erasing its preference');
        } finally {
            $restoreSettings();
        }
    };
    $borrower = $users[1];
    $ledger = new \App\Plugins\MobileApi\Staff090\Controllers\StaffLedgerController($db);
    $ledgerCount = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id={$borrower}")->fetch_row()[0];
    $additional['negative manual charge cannot create a ledger movement'] = static function () use ($ledger, $borrower, $request, $ledgerCount, $check, $run): void {
        Features::override('fines', true);
        $before = $ledgerCount();
        $r = $ledger->charge($request('/ledger', ['amount_cents' => -1, 'description' => 'Invalid'], "negative-{$run}"), new Response(), $borrower);
        $check($r->getStatusCode() === 422 && $ledgerCount() === $before, 'negative charge leaves the ledger unchanged');
    };
    $chargeBody = ['amount_cents' => 125, 'description' => 'Release regression'];
    $additional['replayed manual charge creates exactly one movement'] = static function () use ($ledger, $borrower, $request, $ledgerCount, $check, $run, $chargeBody): void {
        $before = $ledgerCount();
        $a = $ledger->charge($request('/ledger', $chargeBody, "charge-{$run}"), new Response(), $borrower);
        $b = $ledger->charge($request('/ledger', $chargeBody, "charge-{$run}"), new Response(), $borrower);
        $check($a->getStatusCode() === 201 && $b->getStatusCode() === 200 && $ledgerCount() === $before + 1, 'manual charge retry is idempotent');
    };
    $additional['reusing a charge key for another amount cannot change the balance'] = static function () use ($ledger, $borrower, $request, $ledgerCount, $check, $run): void {
        $before = $ledgerCount();
        $r = $ledger->charge($request('/ledger', ['amount_cents' => 250, 'description' => 'Release regression'], "charge-{$run}"), new Response(), $borrower);
        $check($r->getStatusCode() === 422 && $ledgerCount() === $before, 'conflicting retry writes nothing');
    };
    $additional['payment replay after another movement keeps the original payment id'] = static function () use ($ledger, $borrower, $request, $ledgerCount, $check, $run): void {
        $before = $ledgerCount();
        $body = ['amount_cents' => 25, 'method' => 'contanti'];
        $a = $ledger->payment($request('/ledger', $body, "payment-{$run}"), new Response(), $borrower);
        $ledger->charge($request('/ledger', ['amount_cents' => 50, 'description' => 'Later charge'], "later-{$run}"), new Response(), $borrower);
        $b = $ledger->payment($request('/ledger', $body, "payment-{$run}"), new Response(), $borrower);
        $first = json_decode((string) $a->getBody(), true);
        $last = json_decode((string) $b->getBody(), true);
        $check($a->getStatusCode() === 201 && $b->getStatusCode() === 200 && isset($first['data']['movement']['id']) && $first['data']['movement']['id'] === ($last['data']['movement']['id'] ?? null) && $ledgerCount() === $before + 2, 'later movements cannot change the replayed payment identity');
    };
    $additional['invalid patron type input preserves assignment and card expiry'] = static function () use ($db, $borrower, $request, $check): void {
        $read = static fn () => $db->query("SELECT tipologia_id,data_scadenza_tessera FROM utenti WHERE id={$borrower}")->fetch_assoc();
        $before = $read();
        $r = (new \App\Plugins\MobileApi\Staff090\Controllers\StaffPatronsController($db))->updatePatronType($request('/patron', ['patron_type_id' => true, 'recompute_card_expiry' => true]), new Response(), $borrower);
        $check($r->getStatusCode() === 422 && $read() === $before, 'invalid type cannot change the card');
    };
    $additional['malformed MARC supplier input cannot create an import job'] = static function () use ($db, $operator, $request, $check): void {
        Features::override('suppliers', true);
        $before = (int) $db->query("SELECT COUNT(*) FROM mobile_import_jobs WHERE user_id={$operator}")->fetch_row()[0];
        $xml = '<collection xmlns="http://www.loc.gov/MARC21/slim"></collection>';
        $stream = (new StreamFactory())->createStream($xml);
        $upload = new \Slim\Psr7\UploadedFile($stream, 'test.xml', 'application/xml', strlen($xml), UPLOAD_ERR_OK);
        $r = (new \App\Plugins\MobileApi\Staff090\Controllers\StaffMarcController($db))->create($request('/marc', [])->withParsedBody(['supplier_id' => ['bad']])->withUploadedFiles(['file' => $upload]), new Response());
        $check($r->getStatusCode() === 422 && (int) $db->query("SELECT COUNT(*) FROM mobile_import_jobs WHERE user_id={$operator}")->fetch_row()[0] === $before, 'supplier array creates no MARC job');
    };
    $additionalJobs = [];
    $additionalFiles = [];
    $uploadUsers = static function (string $text, string $name, string $delimiter) use ($db, $request, $operator, &$additionalJobs, &$additionalFiles): array {
        $file = tempnam(sys_get_temp_dir(), 'release090_');
        file_put_contents($file, $text);
        $additionalFiles[] = $file;
        $upload = new \Slim\Psr7\UploadedFile($file, $name, 'text/plain', strlen($text), UPLOAD_ERR_OK);
        $r = (new \App\Plugins\MobileApi\Staff090\Controllers\StaffUserImportController($db))->create($request('/users/import', [])->withParsedBody(['delimiter' => $delimiter])->withUploadedFiles(['file' => $upload]), new Response());
        $payload = json_decode((string) $r->getBody(), true);
        if (isset($payload['data']['id'])) {
            $id = $payload['data']['id'];
            $additionalJobs[] = $id;
            $job = (new ImportJobStore($db))->find($id, $operator, true);
            $additionalFiles[] = $job['state']['core']['file'] ?? null;
        }
        return [$r->getStatusCode(), $payload];
    };
    $additional['CSV upload redacts a camel-case password column from stored preview'] = static function () use ($uploadUsers, $check, $run): void {
        [$status, $body] = $uploadUsers("nome;cognome;email;passwordHash\nAda;Rossi;csv-{$run}@example.invalid;NEVER_EXPOSE_THIS\n", 'release.csv', ';');
        $check($status === 201 && !str_contains(json_encode($body['data']['preview'] ?? []), 'NEVER_EXPOSE_THIS') && ($body['data']['total_rows'] ?? 0) === 1, 'CSV preview redacts password-like headers');
    };
    $additional['TSV upload preserves quoted tabs, newlines and UTF-8 in a single record'] = static function () use ($uploadUsers, $check, $run): void {
        [$status, $body] = $uploadUsers("nome\tcognome\temail\n\"Città\tNuova\"\t\"Rossi\nBianchi\"\ttsv-{$run}@example.invalid\n", 'release.tsv', 'tab');
        $preview = $body['data']['preview'][0] ?? [];
        $check($status === 201 && ($body['data']['total_rows'] ?? 0) === 1 && ($preview[0] ?? null) === "Città\tNuova" && ($preview[1] ?? null) === "Rossi\nBianchi", 'TSV quoted multiline row is parsed once without changing its cells');
    };
    $additional['nonexistent stocktake branch creates no inventory session'] = static function () use ($db, $request, $check): void {
        $before = (int) $db->query('SELECT COUNT(*) FROM inventario_sessioni')->fetch_row()[0];
        $r = (new \App\Plugins\MobileApi\Staff090\Controllers\StaffStocktakeController($db))->create($request('/stocktake', ['name' => 'Invalid branch', 'branch_id' => 2147483647]), new Response());
        $check($r->getStatusCode() === 422 && (int) $db->query('SELECT COUNT(*) FROM inventario_sessioni')->fetch_row()[0] === $before, 'invalid branch creates no stocktake');
    };
    $additional['unknown visitor card records no attendance'] = static function () use ($db, $request, $check, $run): void {
        $before = (int) $db->query('SELECT COUNT(*) FROM presenze')->fetch_row()[0];
        $r = (new \App\Plugins\MobileApi\Staff090\Controllers\StaffAttendanceController($db))->checkIn($request('/attendance', ['card_code' => "ABSENT-{$run}"]), new Response());
        $check($r->getStatusCode() === 404 && (int) $db->query('SELECT COUNT(*) FROM presenze')->fetch_row()[0] === $before, 'unknown card creates no visit');
    };
    $check(count($additional) === 25, 'exactly 25 additional release scenarios are registered');
    Features::override('circulation_rules', true);
    Features::override('suppliers', true);
    try {
        $number = 0;
        foreach ($additional as $name => $test) {
            $test();
            echo 'TEST ' . (++$number) . '/25 PASS: ' . $name . "\n";
        }
    } finally {
        foreach ($additionalFiles as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        foreach ($additionalJobs as $id) {
            $db->query("DELETE FROM mobile_import_jobs WHERE id='" . $db->real_escape_string($id) . "'");
        }
    }

} finally {
    $db->beforeBegin = $db->beforeSupplier = null;
    $db->failPrefix = null;
    $db->failSettingsWrite = 0;
    if (\App\Support\DbAtomic::inTransaction($db)) {
        $db->rollback();
    }
    $restoreSettings();
    if ($pluginRow) {
        $db->query('UPDATE plugins SET is_active=' . (int) $pluginRow['is_active'] . ' WHERE id=' . (int) $pluginRow['id']);
    }
    if (!$pluginRow) {
        $db->query("DELETE FROM plugins WHERE name='z39-server' AND version='test'");
    }
    $bookIds = array_column($db->query("SELECT id FROM libri WHERE titolo LIKE 'ZZ replay {$run} %'")->fetch_all(MYSQLI_ASSOC), 'id');
    if ($book > 0) {
        $bookIds[] = $book;
    }
    foreach ($bookIds as $id) {
        $id = (int) $id;
        $db->query("DELETE FROM log_modifiche WHERE tabella='libri' AND record_id={$id}");
        $db->query("DELETE FROM prestiti WHERE libro_id={$id}");
        $db->query("DELETE FROM copie WHERE libro_id={$id}");
        $db->query("DELETE FROM libri WHERE id={$id}");
    }
    if ($jobId !== null) {
        $db->query("DELETE FROM mobile_import_jobs WHERE id='" . $db->real_escape_string($jobId) . "'");
    }
    foreach ($users as $id) {
        $db->query("DELETE FROM movimenti_lettore WHERE utente_id={$id}");
        $db->query("DELETE FROM utenti WHERE id={$id}");
    }
    if ($supplier > 0) {
        $db->query("DELETE FROM fornitori WHERE id={$supplier}");
    }
    if ($type > 0) {
        $db->query("DELETE FROM regole_prestito WHERE tipologia_id={$type}");
        $db->query("DELETE FROM tipologie_lettore WHERE id={$type}");
    }
    Features::resetOverrides();
    $_SESSION = $priorSession;
}
echo "{$checks} checks passed\n";
