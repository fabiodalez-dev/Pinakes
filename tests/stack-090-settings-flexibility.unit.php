<?php
declare(strict_types=1);

/**
 * 37 independently named integration scenarios for the 0.9.0 settings:
 * 25 configuration scenarios and upgrade preservation of saved choices.
 * Real controllers, persisted settings, feature gates and domain services;
 * no Features::override(), no source-text assertions, no network requests.
 * Creates a fresh random sandbox database and drops it in finally. The
 * supplied account must have CREATE DATABASE permission, as for migrations.
 * Run: php tests/stack-090-settings-flexibility.unit.php
 */
use App\Controllers\Admin\LibraryCalendarController;
use App\Controllers\AttendanceController;
use App\Controllers\CirculationSettingsController;
use App\Controllers\InventoryController;
use App\Controllers\SettingsController;
use App\Middleware\FeatureGateMiddleware;
use App\Models\AttendanceRepository;
use App\Models\SettingsRepository;
use App\Models\SupplierRepository;
use App\Services\CirculationPolicy;
use App\Services\FineParameters;
use App\Services\PatronLedger;
use App\Services\StocktakeService;
use App\Support\CataloguingExtras;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\Holidays\HolidayPresets;
use App\Support\LibraryCalendar;
use App\Support\Marc\MarcBookData;
use App\Support\Marc\MarcBookImporter;
use App\Support\PatronCardSettings;
use App\Support\PatronTypeImport;
use App\Support\RdaTypes;
use App\Support\UserImport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
putenv('PINAKES_DISABLE_CLI_PURGE=1');

$env = Dotenv\Dotenv::parse((string) @file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$host = $socket !== '' ? 'localhost' : (getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'));
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$sandbox = 'pinakes_cfg25_' . bin2hex(random_bytes(6));
$db = null;
$admin = null;
$shared = new ReflectionProperty(ConfigStore::class, 'sharedConnection');
$previousConnection = $shared->getValue();
$previousSession = $_SESSION ?? [];
$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$request = static fn (string $path, array $body = []): ServerRequestInterface =>
    (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body);
$cases = [];
$test = static function (string $name, Closure $body) use (&$cases): void {
    $cases[] = [$name, $body];
};

try {
    $admin = new mysqli($host, $user, $pass, '', $port, $socket ?: null);
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysql = ['mysql', '--host=' . $host, '--port=' . $port, '--user=' . $user];
    if ($socket !== '') {
        $mysql[] = '--socket=' . $socket;
    }
    $mysql[] = $sandbox;
    foreach (['schema.sql', 'data_it_IT.sql', 'triggers.sql'] as $file) {
        $process = proc_open($mysql, [0 => ['file', $root . '/installer/database/' . $file, 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root, array_merge(getenv(), ['MYSQL_PWD' => $pass]));
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Sandbox import failed: ' . $file);
        }
    }
    $db = new mysqli($host, $user, $pass, $sandbox, $port, $socket ?: null);
    $db->set_charset('utf8mb4');
    $shared->setValue(null, $db);
    ConfigStore::clearCache();
    DateHelper::synchronizeDatabaseSession($db);
    $settings = new SettingsRepository($db);
    $baseline = $db->query('SELECT category, setting_key, setting_value FROM system_settings')->fetch_all(MYSQLI_ASSOC);
    $one = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0] ?? null;
    $set = static function (string $category, string $key, string $value) use ($settings): void {
        $settings->set($category, $key, $value);
        LibraryCalendar::reset();
    };
    $flag = static fn (string $key, bool $on) => $set('features', $key, $on ? '1' : '0');
    $serial = 0;
    $patron = static function (string $kind = 'standard') use ($db, &$serial): int {
        $n = ++$serial;
        $db->query("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata) VALUES ('ZZCFG{$n}', 'ZZ Config', 'Test', 'zz-cfg-{$n}@test.local', 'x', '{$kind}', 'attivo', 1, 1)");
        return (int) $db->insert_id;
    };
    $operator = $patron('admin');
    $asAdmin = static function () use ($operator): void {
        $_SESSION = ['user' => ['id' => $operator, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
    };
    $book = static function (string $media = 'libro') use ($db, &$serial): array {
        $n = ++$serial;
        $db->query("INSERT INTO libri (titolo, tipo_media, collocazione, copie_totali, copie_disponibili) VALUES ('ZZCFG book {$n}', '{$media}', 'ZZCFG.{$n}', 1, 1)");
        $id = (int) $db->insert_id;
        $db->query("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES ({$id}, 'ZZCFG-C{$n}', 'disponibile')");
        return ['id' => $id, 'copy' => (int) $db->insert_id, 'code' => "ZZCFG-C{$n}", 'title' => "ZZCFG book {$n}", 'location' => "ZZCFG.{$n}"];
    };
    $type = static function (array $fields = []) use ($db, &$serial): int {
        $n = ++$serial;
        $db->query("INSERT INTO tipologie_lettore (codice, nome) VALUES ('zzcfg{$n}', 'ZZ Config {$n}')");
        $id = (int) $db->insert_id;
        foreach ($fields as $column => $value) {
            $stmt = $db->prepare("UPDATE tipologie_lettore SET `{$column}` = ? WHERE id = ?");
            $stmt->bind_param('si', $value, $id);
            $stmt->execute();
            $stmt->close();
        }
        return $id;
    };
    $loan = static function (int $reader, array $title, string $due, string $returned) use ($db): int {
        $stmt = $db->prepare("INSERT INTO prestiti (utente_id, libro_id, copia_id, data_prestito, data_scadenza, data_restituzione, stato, attivo, origine) VALUES (?, ?, ?, '2026-01-01', ?, ?, 'restituito', 0, 'diretto')");
        $stmt->bind_param('iiiss', $reader, $title['id'], $title['copy'], $due, $returned);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $loansBody = static fn (array $extra = []): array => array_replace([
        'loan_duration_days' => '30', 'pickup_expiry_days' => '3', 'max_renewals' => '3',
        'max_loan_duration_days' => '3650', 'feature_circulation_rules' => '1',
        'feature_closures_calendar' => '1', 'feature_fines' => '1',
        'feature_automatic_fines' => '1', 'feature_debt_block' => '1', 'feature_loss_penalties' => '1',
    ], $extra);
    $saveLoans = static function (array $extra = []) use ($request, $db, $loansBody): void {
        (new SettingsController())->updateLoansSettings($request('/admin/settings/loans', $loansBody($extra)), new Response(), $db);
    };

    $test('01 Modules: a mixed selection persists and is read by the runtime', static function () use ($request, $db, $assert): void {
        (new SettingsController())->updateModules($request('/admin/settings/modules', ['feature_marc' => '1', 'feature_user_import' => '1', 'feature_patron_photos' => '0']), new Response(), $db);
        foreach (Features::MODULES_TAB as $key) {
            $assert(Features::enabled($key) === in_array($key, ['marc', 'user_import'], true), 'Saved switch is inert or wrong: ' . $key);
        }
    });
    $test('02 A Modules save cannot change switches owned by the Loans tab', static function () use ($request, $db, $flag, $assert): void {
        foreach (Features::LOANS_TAB as $key) { $flag($key, true); }
        (new SettingsController())->updateModules($request('/admin/settings/modules', ['feature_fines' => '0', 'feature_closures_calendar' => '0', 'feature_circulation_rules' => '0']), new Response(), $db);
        foreach (Features::LOANS_TAB as $key) { $assert(Features::stored($key), 'Foreign-tab switch changed: ' . $key); }
    });
    $test('03 Staff cannot change the optional modules', static function () use ($request, $db, $assert): void {
        $before = Features::snapshot();
        $_SESSION['user']['tipo_utente'] = 'staff';
        (new SettingsController())->updateModules($request('/admin/settings/modules', []), new Response(), $db);
        $assert(Features::snapshot() === $before, 'Staff changed a module');
    });
    $test('04 Loans switches are independent and preserve explicit zero values', static function () use ($saveLoans, $settings, $assert): void {
        $saveLoans(['feature_circulation_rules' => '0', 'feature_closures_calendar' => '0', 'daily_fine' => '0', 'debt_block_threshold' => '0', 'fine_grace_days' => '0', 'max_renewals' => '0']);
        $assert(Features::enabled('fines') && !Features::enabled('closures_calendar') && !Features::enabled('circulation_rules'), 'Loans switches are coupled');
        $assert($settings->get('loans', 'max_renewals') === '0' && $settings->get('loans', 'fine_grace_days') === '0', 'Zero was replaced by a default');
    });
    $test('05 Staff cannot switch circulation modules or rewrite money policy', static function () use ($saveLoans, $flag, $set, $settings, $assert): void {
        foreach (Features::LOANS_TAB as $key) { $flag($key, true); }
        $set('loans', 'daily_fine', '0.75');
        $_SESSION['user']['tipo_utente'] = 'staff';
        $saveLoans(['feature_circulation_rules' => '0', 'feature_closures_calendar' => '0', 'feature_fines' => '0', 'daily_fine' => '9.99']);
        foreach (Features::LOANS_TAB as $key) { $assert(Features::stored($key), 'Staff changed a circulation switch'); }
        $assert($settings->get('loans', 'daily_fine') === '0.75', 'Staff changed fine policy');
    });
    $test('06 Catalogue mode suspends circulation without erasing saved choices', static function () use ($flag, $set, $assert): void {
        foreach (Features::CIRCULATION as $key) { $flag($key, true); }
        $set('system', 'catalogue_mode', '1');
        foreach (Features::CIRCULATION as $key) { $assert(Features::stored($key) && !Features::enabled($key), 'Catalogue mode erased or ignored a saved choice'); }
        $set('system', 'catalogue_mode', '0');
        foreach (Features::CIRCULATION as $key) { $assert(Features::enabled($key), 'Leaving catalogue mode did not restore a module'); }
    });
    $test('07 A disabled module returns JSON without invoking the endpoint', static function () use ($flag, $assert): void {
        $flag('catalogue_cards', false);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { throw new RuntimeException('Disabled endpoint was invoked'); }
        };
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/library/admin/books/catalogue-cards')->withHeader('Accept', 'application/json');
        $res = (new FeatureGateMiddleware('catalogue_cards'))->process($req, $handler);
        $assert($res->getStatusCode() === 404 && json_decode((string) $res->getBody(), true)['code'] === 'feature_disabled', 'Disabled module returned an unusable API response');
    });
    $test('08 Weekly closures can change, but closing all seven days preserves the prior value', static function () use ($request, $db, $settings, $assert): void {
        $calendar = new LibraryCalendarController();
        $calendar->saveWeekdays($request('/admin/calendar/weekdays', ['closed_weekdays' => ['0', '3']]), new Response(), $db);
        $calendar->saveWeekdays($request('/admin/calendar/weekdays', ['closed_weekdays' => range(0, 6)]), new Response(), $db);
        $assert($settings->get('loans', 'closed_weekdays') === '0,3', 'Invalid weekly closure replaced the saved calendar');
    });
    $test('09 A global holiday and a branch closure produce different due dates per branch', static function () use ($db, $book, $assert): void {
        $db->query("INSERT INTO sedi (nome) VALUES ('ZZ Config A')"); $a = (int) $db->insert_id;
        $db->query("INSERT INTO sedi (nome) VALUES ('ZZ Config B')"); $b = (int) $db->insert_id;
        $x = $book(); $y = $book();
        $db->query("UPDATE copie SET sede_id={$a} WHERE id={$x['copy']}");
        $db->query("UPDATE copie SET sede_id={$b} WHERE id={$y['copy']}");
        HolidayPresets::apply($db, 'IT', '', ['IT:easter_monday'], '2026-10-29');
        $db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,sede_id) VALUES ('2029-04-03','2029-04-03','ZZ branch',{$a})");
        LibraryCalendar::reset();
        $assert(CirculationPolicy::dueDateFor('2029-04-02', 0, $db, $x['copy']) === '2029-04-04', 'Branch closure was omitted');
        $assert(CirculationPolicy::dueDateFor('2029-04-02', 0, $db, $y['copy']) === '2029-04-03', 'Branch closure leaked to another branch');
    });
    $test('10 Re-enabling a calendar immediately restores distant holidays without a maintenance run', static function () use ($db, $flag, $assert, $one): void {
        HolidayPresets::apply($db, 'US', '', ['US:new_year'], '2026-10-29');
        $flag('closures_calendar', false);
        $assert(LibraryCalendar::dueDate($db, '2038-12-31') === '2038-12-31', 'Disabled calendar still shifted dates');
        $flag('closures_calendar', true);
        $assert(LibraryCalendar::dueDate($db, '2038-12-31') === '2039-01-01', 'Re-enabled calendar needs maintenance to work');
        $assert((int) $one("SELECT COUNT(*) FROM calendario_chiusure WHERE data_inizio='2038-12-31'") === 0, 'Reading a far date unexpectedly writes rows');
    });
    $test('11 A regional holiday can be unticked without deleting an inactive manual closure', static function () use ($db, $assert, $one): void {
        $db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,ricorrente_annuale,attiva) VALUES ('2026-08-17','2026-08-17','ZZ manual off',1,0)");
        $id = (int) $db->insert_id;
        HolidayPresets::apply($db, 'DE', 'BY', ['DE:mariae_himmelfahrt'], '2026-10-29');
        $assert(!LibraryCalendar::forDb($db)->isOpen('2032-08-15'), 'Selected regional holiday is open');
        HolidayPresets::apply($db, 'DE', '', ['DE:mariae_himmelfahrt'], '2026-10-29');
        $assert(LibraryCalendar::forDb($db)->isOpen('2032-08-15'), 'Regional holiday survived removal of its region');
        $assert((string) $one("SELECT attiva FROM calendario_chiusure WHERE id={$id}") === '0', 'The inactive manual closure was changed');
    });
    $test('12 Holiday application joins an existing transaction and rolls back with its caller', static function () use ($db, $assert, $one): void {
        HolidayPresets::apply($db, 'IT', '', ['IT:christmas'], '2026-10-29');
        $before = (string) $one('SELECT GROUP_CONCAT(id ORDER BY id) FROM calendario_chiusure');
        $db->begin_transaction();
        try {
            HolidayPresets::apply($db, 'US', '', ['US:new_year'], '2026-10-29');
        } finally { $db->rollback(); LibraryCalendar::reset(); }
        $assert(HolidayPresets::selection($db)['country'] === 'IT', 'Nested holiday save committed its caller');
        $assert((string) $one('SELECT GROUP_CONCAT(id ORDER BY id) FROM calendario_chiusure') === $before, 'Rollback did not restore closure rows');
    });
    $test('13 A matrix cell disables renewals while its blank duration inherits the patron type', static function () use ($request, $db, $type, $book, $patron, $assert): void {
        $t = $type(['durata_prestito_giorni' => '17']); $p = $patron(); $b = $book('dvd');
        $db->query("UPDATE utenti SET tipologia_id={$t} WHERE id={$p}");
        (new CirculationSettingsController())->saveRules($request('/admin/settings/circulation/rules', ['rules' => [$t => ['dvd' => ['renewals' => '0', 'duration' => '']]]]), new Response(), $db);
        $policy = (new CirculationPolicy($db))->forLoan($p, $b['id']);
        $assert($policy->maxRenewals === 0 && $policy->durationDays === 17, 'Zero and inheritance were conflated');
    });
    $test('14 Disabling type rules preserves the independent consultation restriction', static function () use ($db, $type, $book, $patron, $flag, $set, $assert): void {
        $t = $type(['durata_prestito_giorni' => '9']); $p = $patron(); $b = $book();
        $db->query("UPDATE utenti SET tipologia_id={$t} WHERE id={$p}");
        $db->query("UPDATE copie SET solo_consultazione=1 WHERE id={$b['copy']}");
        $set('loans', 'loan_duration_days', '23'); $flag('circulation_rules', false);
        $off = (new CirculationPolicy($db))->forLoan($p, $b['id'], $b['copy']);
        $assert($off->durationDays === 23 && !$off->loanable, 'Off does not restore the global policy');
        $flag('circulation_rules', true);
        $on = (new CirculationPolicy($db))->forLoan($p, $b['id'], $b['copy']);
        $assert($on->durationDays === 9 && !$on->loanable, 'The saved type or consultation flag was lost');
    });
    $test('15 Debt blocking can work with automatic late fines disabled', static function () use ($saveLoans, $db, $patron, $book, $loan, $assert): void {
        $saveLoans(['daily_fine' => '0', 'debt_block_threshold' => '5']); $p = $patron();
        $ledger = new PatronLedger($db);
        $ledger->manualCharge($p, 600, 'ZZ manual debt', null);
        $id = $loan($p, $book(), '2026-10-01', '2026-10-04');
        $assert($ledger->chargeLateReturn($id) === 0 && PatronLedger::isDebtBlocked($db, $p), 'The fine rate and debt threshold cannot be configured independently');
    });
    $test('16 Late fines can work with debt blocking disabled', static function () use ($saveLoans, $db, $patron, $book, $loan, $assert): void {
        $saveLoans(['daily_fine' => '0,50', 'debt_block_threshold' => '0', 'fine_grace_days' => '0']); $p = $patron();
        $id = $loan($p, $book(), '2026-10-01', '2026-10-04'); $ledger = new PatronLedger($db);
        $assert($ledger->chargeLateReturn($id) === 150 && $ledger->balanceCents($p) === 150, 'The configured fine was not charged');
        $assert(!PatronLedger::isDebtBlocked($db, $p), 'A zero debt threshold still blocks');
    });
    $test('17 A patron type can opt out of global fines and debt blocking with explicit zeros', static function () use ($saveLoans, $db, $type, $patron, $book, $loan, $assert): void {
        $saveLoans(['daily_fine' => '1', 'debt_block_threshold' => '5']);
        $t = $type(['multa_giornaliera' => '0.00', 'soglia_blocco_debito' => '0.00']); $p = $patron();
        $db->query("UPDATE utenti SET tipologia_id={$t} WHERE id={$p}");
        $ledger = new PatronLedger($db); $ledger->manualCharge($p, 800, 'ZZ existing debt', null);
        $id = $loan($p, $book(), '2026-10-01', '2026-10-04');
        $assert($ledger->chargeLateReturn($id) === 0 && !PatronLedger::isDebtBlocked($db, $p), 'Explicit type zeros inherited the global amounts');
    });
    $test('18 A CSV importer stops assigning types while off and resumes on the same instance', static function () use ($db, $type, $patron, $flag, $one, $assert): void {
        $t = $type(); $p = $patron(); $code = (string) $one("SELECT codice FROM tipologie_lettore WHERE id={$t}");
        $importer = new PatronTypeImport($db);
        $assert($importer->available(), 'Patron types are not initially available');
        $flag('circulation_rules', false);
        $assert($importer->assign($p, $code) === 'skipped' && $one("SELECT tipologia_id FROM utenti WHERE id={$p}") === null, 'A stale importer wrote a disabled field');
        $flag('circulation_rules', true);
        $assert($importer->assign($p, $code) === 'assigned' && (int) $one("SELECT tipologia_id FROM utenti WHERE id={$p}") === $t, 'Re-enabled assignment did not resume');
    });
    $test('19 CSV code columns preserve the chosen type even when names are ambiguous', static function () use ($db, $type, $patron, $one, $assert): void {
        $a = $type(['nome' => 'ZZ Shared']); $b = $type(['nome' => 'ZZ Shared']); $p = $patron();
        $code = (string) $one("SELECT codice FROM tipologie_lettore WHERE id={$b}");
        $mapping = UserImport::guessMapping(['Tipologia lettore', 'Codice tipologia lettore', 'Email'], UserImport::targetFields([], true));
        $importer = new PatronTypeImport($db);
        $assert($mapping[0] === '' && $mapping[1] === 'tipologia', 'The export name took precedence over its code');
        $assert($importer->assign($p, 'ZZ Shared') === 'unknown' && $importer->assign($p, $code) === 'assigned', 'A shared name was guessed or the exact code failed');
        $assert((int) $one("SELECT tipologia_id FROM utenti WHERE id={$p}") === $b && $a !== $b, 'The CSV assigned the wrong patron type');
    });
    $test('20 MARC fill and overwrite respect different media choices and preserve price and format', static function () use ($db, $book, $one, $assert): void {
        $b = $book();
        $db->query("UPDATE libri SET isbn13='9780306406157', prezzo=17.25, formato='ZZ format', rda_carrier_type='nc' WHERE id={$b['id']}");
        $data = ['titolo' => $b['title'], 'isbn13' => '9780306406157', 'tipo_media' => 'dvd'] + MarcBookData::empty();
        $counts = []; $warnings = [];
        $filled = (new MarcBookImporter($db, MarcBookImporter::MODE_FILL, false))->import($data, $counts, $warnings);
        $assert($filled['action'] === 'updated' && $filled['id'] === $b['id'], 'Fill did not match the existing book');
        $assert($one("SELECT tipo_media FROM libri WHERE id={$b['id']}") === 'libro', 'Fill overwrote the selected material');
        $overwritten = (new MarcBookImporter($db, MarcBookImporter::MODE_OVERWRITE, false))->import($data, $counts, $warnings);
        $assert($overwritten['action'] === 'updated' && $overwritten['id'] === $b['id'], 'Overwrite did not match the existing book');
        $assert($one("SELECT CONCAT(tipo_media,'|',prezzo,'|',formato) FROM libri WHERE id={$b['id']}") === 'dvd|17.25|ZZ format', 'Overwrite lost unrelated cataloguing choices');
        $assert($one("SELECT rda_carrier_type FROM libri WHERE id={$b['id']}") === null, 'The old material carrier survived an overwrite');
    });
    $test('21 RDA can be suspended and restored without losing manual overrides', static function () use ($db, $book, $flag, $one, $assert): void {
        $b = $book(); $db->query("UPDATE libri SET rda_content_type='prm' WHERE id={$b['id']}");
        $flag('rda_types', false);
        CataloguingExtras::save($db, $b['id'], ['rda_content_type' => '']);
        $row = $db->query("SELECT * FROM libri WHERE id={$b['id']}")->fetch_assoc();
        $assert(RdaTypes::effective($row)['content'] !== 'prm' && $one("SELECT rda_content_type FROM libri WHERE id={$b['id']}") === 'prm', 'Disabling RDA deleted or applied an override');
        $flag('rda_types', true);
        $assert(RdaTypes::effective($row)['content'] === 'prm', 'Re-enabling RDA did not restore the override');
    });
    $test('22 Supplier choices survive suspension and deleting a supplier never deletes its book', static function () use ($db, $book, $flag, $one, $assert): void {
        $b = $book(); $repo = new SupplierRepository($db);
        $id = $repo->create(SupplierRepository::normalize(['nome' => 'ZZ supplier']));
        CataloguingExtras::assignSupplier($db, $b['id'], $id);
        $flag('suppliers', false); CataloguingExtras::save($db, $b['id'], ['fornitore_id' => '']);
        $assert((int) $one("SELECT fornitore_id FROM libri WHERE id={$b['id']}") === $id, 'A save while off erased the supplier');
        $flag('suppliers', true); $repo->delete($id);
        $assert($one("SELECT fornitore_id FROM libri WHERE id={$b['id']}") === null && $one("SELECT titolo FROM libri WHERE id={$b['id']}") === $b['title'], 'Deleting a supplier damaged its catalogue');
    });
    $test('23 Card presentation choices persist independently of module visibility', static function () use ($request, $db, $settings, $flag, $assert): void {
        (new SettingsController())->updateCards($request('/admin/settings/cards', ['card_layout' => 'portrait', 'card_code_type' => 'qr', 'card_format' => 'sheet', 'card_title' => 'ZZ tessera', 'card_back_text' => "Riga 1\nRiga 2"]), new Response(), $db);
        $flag('patron_cards', false); $flag('patron_cards', true);
        $card = PatronCardSettings::resolve($settings);
        $assert($card['layout'] === 'portrait' && $card['code_type'] === 'qr' && $card['format'] === 'sheet', 'Card layout choices were reset');
        $assert(!$card['show_photo'] && !$card['show_logo'] && !$card['show_expiry'] && $card['back_text'] === "Riga 1\nRiga 2", 'Optional card fields cannot be turned off independently');
    });
    $test('24 Visitor retention supports both no automatic purge and a custom retention window', static function () use ($request, $db, $settings, $flag, $one, $assert): void {
        $flag('visitor_log', true);
        $db->query("INSERT INTO presenze (tipo, nome_ospite, created_at) VALUES ('ospite','ZZ old', NOW() - INTERVAL 40 DAY), ('ospite','ZZ recent',NOW())");
        $controller = new AttendanceController(); $repo = new AttendanceRepository($db);
        $controller->saveSettings($request('/admin/attendance/settings', ['retention_days' => '0']), new Response(), $db);
        $assert($repo->purge(AttendanceRepository::retentionDays($settings)) === 0, 'Retention zero still purges visits');
        $controller->saveSettings($request('/admin/attendance/settings', ['retention_days' => '7']), new Response(), $db);
        $assert($repo->purge(AttendanceRepository::retentionDays($settings)) === 1 && $one('SELECT nome_ospite FROM presenze') === 'ZZ recent', 'The custom retention did not keep recent visits');
    });
    $test('25 Stocktake can close without marking missing copies lost and retains reports while disabled', static function () use ($db, $book, $request, $flag, $one, $assert): void {
        $b = $book(); $service = new StocktakeService($db);
        $id = $service->open('ZZ optional loss', null, ['collocazione_da' => $b['location'], 'collocazione_a' => $b['location']], null);
        $assert($service->close($id, false, null) === ['marked' => 0, 'failed' => 0], 'Closing without loss action changed copies');
        $before = $service->summary($id); $flag('stocktake', false);
        $off = (new InventoryController())->scan($request('/admin/inventory/' . $id . '/scan', ['codice' => $b['code']]), new Response(), $db, $id);
        $assert($off->getStatusCode() === 302, 'The disabled inventory endpoint was not gated');
        $flag('stocktake', true);
        $assert($service->summary($id) === $before && $before['mancanti'] === 1, 'Disabling stocktake damaged its frozen report');
        $assert($one("SELECT stato FROM copie WHERE id={$b['copy']}") === 'disponibile', 'Optional loss action was applied implicitly');
    });


    $test('27 Ledger-only mode ignores positive global and typed fine rates and debt thresholds', static function () use ($root, $saveLoans, $db, $patron, $book, $loan, $type, $flag, $request, $assert): void {
        $saveLoans(['daily_fine' => '2', 'debt_block_threshold' => '1', 'feature_automatic_fines' => '0', 'feature_debt_block' => '0']);
        $t = $type(['multa_giornaliera' => '3.00', 'soglia_blocco_debito' => '0.50']); $p = $patron();
        $db->query("UPDATE utenti SET tipologia_id={$t} WHERE id={$p}");
        $id = $loan($p, $book(), '2026-10-01', '2026-10-06'); $ledger = new PatronLedger($db);
        $ledger->manualCharge($p, 1000, 'ZZ manual only', null);
        $assert($ledger->chargeLateReturn($id) === 0 && !$ledger->previewLateFine($id)['enabled'], 'Disabled automatic fines still charge or preview');
        $assert(!PatronLedger::isDebtBlocked($db, $p) && \App\Support\LoanEligibility::debtBlockSql('u', $db) === '1 = 1', 'Debt still blocks a channel');
        $assert($ledger->balanceCents($p) === 1000 && Features::enabled('fines'), 'Manual ledger no longer works');
        require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';
        $mobileRequest = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/me/movements')->withAttribute('mobile_user', ['id' => $p]);
        $mobile = new \App\Plugins\MobileApi\Controllers\ActionsController($db);
        $read = static function () use ($mobile, $mobileRequest): array {
            $response = $mobile->myMovements($mobileRequest, new Response());
            return (array) (json_decode((string) $response->getBody(), true)['data'] ?? []);
        };
        $api = $read();
        $assert(($api['debt_blocked'] ?? null) === false && array_key_exists('debt_block_threshold_cents', $api) && $api['debt_block_threshold_cents'] === null, 'Mobile API reports disabled debt blocking');
        $page = (new \App\Controllers\UsersController())->details($request('/admin/users/details/' . $p), new Response(), $db, $p);
        $assert($page->getStatusCode() === 200 && str_contains((string) $page->getBody(), 'data-ledger-balance="1000"') && !str_contains((string) $page->getBody(), __('Prestiti bloccati: il saldo supera la soglia di %s.', FineParameters::format(50))), 'Reader record reports disabled debt blocking');
        $flag('debt_block', true); $api = $read();
        $assert(($api['debt_blocked'] ?? null) === true && $api['debt_block_threshold_cents'] === 50, 'Mobile API does not restore the saved typed threshold');

    });
    $test('28 Fine and debt switches work independently with positive retained amounts', static function () use ($saveLoans, $db, $patron, $book, $loan, $assert): void {
        $saveLoans(['daily_fine' => '0.50', 'debt_block_threshold' => '1', 'feature_automatic_fines' => '0', 'feature_debt_block' => '1']);
        $p = $patron(); $ledger = new PatronLedger($db); $ledger->manualCharge($p, 200, 'ZZ existing debt', null);
        $id = $loan($p, $book(), '2026-10-01', '2026-10-04');
        $assert($ledger->chargeLateReturn($id) === 0 && PatronLedger::isDebtBlocked($db, $p), 'Debt requires automatic fines');
        $saveLoans(['daily_fine' => '0.50', 'debt_block_threshold' => '1', 'feature_automatic_fines' => '1', 'feature_debt_block' => '0']);
        $assert($ledger->chargeLateReturn($id) === 150 && !PatronLedger::isDebtBlocked($db, $p), 'Fine generation requires a debt block');
    });
    $test('29 Loss mirroring and legacy backfill require their own switch and remain idempotent', static function () use ($saveLoans, $db, $patron, $book, $loan, $flag, $assert): void {
        $saveLoans(['feature_loss_penalties' => '0']); $p = $patron(); $id = $loan($p, $book(), '2026-10-01', '2026-10-04');
        $db->query("UPDATE prestiti SET stato='perso', sanzione=12.50 WHERE id={$id}"); $ledger = new PatronLedger($db);
        $assert($ledger->chargeLossPenalty($id) === 0 && $ledger->backfillLegacyPenalties() === null, 'Loss opt-out created ledger charges');
        $assert($ledger->balanceCents($p) === 0, 'A disabled loss mirror changed the balance');
        $flag('loss_penalties', true); $ledger->backfillLegacyPenalties();
        $assert($ledger->balanceCents($p) === 1250 && $ledger->chargeLossPenalty($id) === 0, 'Reactivation lost or doubled the assessed amount');
    });
    $test('30 Consultation restrictions survive type rules being off and can be independently suspended', static function () use ($db, $book, $patron, $flag, $assert): void {
        $b = $book(); $p = $patron(); $db->query("UPDATE copie SET solo_consultazione=1 WHERE id={$b['copy']}");
        $flag('circulation_rules', false); $flag('consultation_only', true); $policy = new CirculationPolicy($db);
        $assert(!$policy->forLoan($p, $b['id'], $b['copy'])->loanable && $policy->onlyConsultationCopies($b['id']), 'Consultation depends on type rules');
        $assert(str_contains($policy->lendableCopySql('c'), 'solo_consultazione'), 'SQL allocation ignores consultation');
        $flag('consultation_only', false); $policy = new CirculationPolicy($db);
        $assert($policy->forLoan($p, $b['id'], $b['copy'])->loanable && $policy->lendableCopySql() === '1 = 1', 'Consultation cannot be suspended');
    });
    $test('31 Photos are independent of card printing and disabled photos never enter a PDF', static function () use ($flag, $assert): void {
        $flag('patron_cards', false); $flag('patron_photos', true);
        $assert(Features::enabled('patron_photos') && !Features::enabled('patron_cards'), 'Photos require printing');
        $file = tempnam(sys_get_temp_dir(), 'pinakes-photo-');
        try {
            $im = imagecreatetruecolor(12, 12); imagejpeg($im, $file); imagedestroy($im);
            $card = ['name' => 'Photo test', 'code' => 'ZZPHOTO', 'expiry' => '', 'photo' => $file];
            $settings = PatronCardSettings::defaults();
            $visible = (new \App\Support\PatronCardPdf($settings, 'Library', null))->render([$card], 'single');
            $flag('patron_photos', false); $flag('patron_cards', true);
            $hidden = (new \App\Support\PatronCardPdf($settings, 'Library', null))->render([$card], 'single');
            $assert(str_contains($visible, '/Subtype /Image') && !str_contains($hidden, '/Subtype /Image'), 'Photo switch does not control PDF embedding');
        } finally { @unlink($file); }
    });
    $test('32 Retention can continue while collection is off, and zero days always prevents deletion', static function () use ($db, $flag, $set, $assert): void {
        $db->query("INSERT INTO presenze (tipo,nome_ospite,created_at) VALUES ('ospite','ZZ old visit','2020-01-01 10:00:00')");
        $id = (int) $db->insert_id; $flag('visitor_log', false); $flag('attendance_retention', false); $set('attendance', 'retention_days', '30');
        $maintenance = new \App\Support\MaintenanceService($db);
        $assert($maintenance->purgeAttendance() === 0, 'Suspended collection purges without consent');
        $flag('attendance_retention', true); $set('attendance', 'retention_days', '0');
        $assert($maintenance->purgeAttendance() === 0, 'Zero retention unexpectedly deletes');
        $set('attendance', 'retention_days', '30');
        $assert($maintenance->purgeAttendance() === 1, 'Retention cannot run with collection off');
        $assert((int) $db->query("SELECT COUNT(*) FROM presenze WHERE id={$id}")->fetch_row()[0] === 0, 'Expired attendance survived purge');
    });
    $test('33 Fine grace can be a threshold or waived days, using the same typed daily rate', static function () use ($saveLoans, $db, $type, $patron, $book, $loan, $assert): void {
        $saveLoans(['daily_fine' => '1', 'fine_grace_days' => '2', 'fine_grace_mode' => 'threshold']);
        $t = $type(['multa_giornaliera' => '0.50']); $p = $patron(); $db->query("UPDATE utenti SET tipologia_id={$t} WHERE id={$p}");
        $id = $loan($p, $book(), '2026-10-01', '2026-10-06'); $ledger = new PatronLedger($db);
        $assert($ledger->previewLateFine($id, '2026-10-06')['cents'] === 250, 'Historical threshold changed');
        $saveLoans(['daily_fine' => '1', 'fine_grace_days' => '2', 'fine_grace_mode' => 'deduct']);
        $assert($ledger->chargeLateReturn($id) === 150, 'Waived days were not deducted from the typed rate');
    });
    $test('34 Automatic card expiry can be disabled while manual renewal retains today or future-expiry choices', static function () use ($db, $type, $patron, $flag, $set, $assert): void {
        $t = $type(['durata_tessera_giorni' => '30']); $p = $patron();
        $future = (new DateTimeImmutable(DateHelper::today()))->modify('+100 days')->format('Y-m-d');
        $db->query("UPDATE utenti SET tipologia_id={$t}, data_scadenza_tessera='{$future}' WHERE id={$p}");
        $flag('automatic_card_expiry', false);
        $assert(\App\Support\PatronTypeAssignment::automaticCardExpiry($db, $t) === null, 'Automatic expiry ignores its switch');
        \App\Support\PatronTypeAssignment::applyCardDurationOnApproval($db, $p);
        $assert($db->query("SELECT data_scadenza_tessera FROM utenti WHERE id={$p}")->fetch_row()[0] === $future, 'Approval reset an existing expiry');
        $set('loans', 'card_renewal_base', 'expiry');
        $assert(\App\Support\PatronTypeAssignment::renewCard($db, $p) === (new DateTimeImmutable($future))->modify('+30 days')->format('Y-m-d'), 'Renewal erased remaining validity');
        $set('loans', 'card_renewal_base', 'today');
        $assert(\App\Support\PatronTypeAssignment::renewCard($db, $p) === \App\Support\PatronTypeAssignment::todayPlus(30), 'Today default changed');
        $set('loans', 'card_renewal_base', 'expiry');
        $db->query("UPDATE utenti SET data_scadenza_tessera='9999-12-31' WHERE id={$p}");
        $assert(\App\Support\PatronTypeAssignment::renewCard($db, $p) === '9999-12-31', 'A lifetime card overflowed the database date range');
    });
    $test('35 Administrators can prepare disabled rules and calendars without activating runtime behaviour', static function () use ($db, $type, $set, $flag, $assert): void {
        $t = $type(['durata_prestito_giorni' => '11']); $flag('circulation_rules', false); $flag('closures_calendar', false);
        $data = CirculationSettingsController::viewData($db);
        $assert(isset($data['types'][$t]) && $data['effective'][$t]->durationDays === 11 && (new CirculationPolicy($db))->types() === [], 'Configuration activated or hid disabled type rules');
        $set('loans', 'closed_weekdays', '0');
        $assert(!LibraryCalendar::fromDatabase($db)->hasRules() && LibraryCalendar::fromDatabase($db, configuration: true)->hasRules(), 'Draft calendar leaks into circulation');
    });
    $test('36 Old forms freeze absent granular preferences before enabling their former parent', static function () use ($request, $db, $settings, $flag, $assert): void {
        $db->query("DELETE FROM system_settings WHERE category='features' AND setting_key IN ('automatic_fines','debt_block','loss_penalties','patron_photos','consultation_only','automatic_card_expiry')");
        $flag('fines', false); $flag('patron_cards', false); $flag('circulation_rules', false);
        $body = ['feature_fines' => '1', 'feature_circulation_rules' => '1', 'feature_closures_calendar' => '1', 'daily_fine' => '2', 'debt_block_threshold' => '1'];
        (new SettingsController())->updateLoansSettings($request('/admin/settings/loans', $body), new Response(), $db);
        foreach (['automatic_fines', 'debt_block', 'loss_penalties', 'consultation_only', 'automatic_card_expiry'] as $key) {
            $assert($settings->get('features', $key) === '0' && !Features::enabled($key), 'A former parent silently enabled ' . $key);
        }
        (new SettingsController())->updateModules($request('/admin/settings/modules', ['feature_patron_cards' => '1']), new Response(), $db);
        $assert(Features::enabled('patron_cards') && !Features::enabled('patron_photos'), 'Printing unexpectedly resumed photo collection');
    });

    $test('37 Repeated upgrades never enable fines or convert penalties when the old ledger was off', static function () use ($root, $db, $flag, $set, $type, $patron, $book, $loan, $assert): void {
        $db->query("DELETE FROM system_settings WHERE category='features' AND setting_key IN ('automatic_fines','debt_block','loss_penalties','patron_photos','consultation_only','automatic_card_expiry')");
        $flag('fines', false); $flag('patron_cards', false); $flag('circulation_rules', false);
        $set('loans', 'daily_fine', '2.00'); $set('loans', 'debt_block_threshold', '1.00');
        $t = $type(['multa_giornaliera' => '3.00', 'soglia_blocco_debito' => '0.50']); $p = $patron();
        $db->query("UPDATE utenti SET tipologia_id={$t} WHERE id={$p}");
        $id = $loan($p, $book(), '2026-10-01', '2026-10-06');
        $db->query("UPDATE prestiti SET sanzione=12.50 WHERE id={$id}");
        $sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0.sql');
        $sql = implode("\n", array_filter(explode("\n", $sql), static fn (string $line): bool => !str_starts_with(trim($line), '--')));
        for ($pass = 0; $pass < 2; $pass++) {
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement) === '') { continue; }
                $result = $db->query($statement); if ($result instanceof mysqli_result) { $result->free(); }
            }
            ConfigStore::clearCache(); PatronLedger::resetSchemaCache();
            foreach (['fines','automatic_fines','debt_block','loss_penalties','patron_photos','consultation_only','automatic_card_expiry'] as $key) {
                $assert(!Features::stored($key), 'Upgrade silently enabled ' . $key);
            }
            $ledger = new PatronLedger($db);
            $assert($ledger->chargeLateReturn($id) === 0 && $ledger->balanceCents($p) === 0, 'Upgrade created an unplanned financial charge');
        }
        $flag('fines', true);
        $assert(!Features::enabled('automatic_fines') && !Features::enabled('debt_block') && !Features::enabled('loss_penalties'), 'Enabling the ledger implicitly enabled its automations');
    });

    $test('26 Repeated upgrades preserve saved settings and effective behaviour in every switch state', static function () use ($root, $db, $admin, $sandbox, $settings, $set, $flag, $book, $patron, $assert): void {
        $p = $patron(); $b = $book();
        $db->query("UPDATE libri SET rda_content_type='prm' WHERE id={$b['id']}");
        $db->query("UPDATE copie SET solo_consultazione=1 WHERE id={$b['copy']}");
        $db->query("INSERT INTO fornitori (nome) VALUES ('ZZ upgrade supplier')");
        $db->query("INSERT INTO presenze (tipo,nome_ospite) VALUES ('ospite','ZZ upgrade guest')");
        $db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,ricorrente_annuale,attiva) VALUES ('2026-08-20','2026-08-20','ZZ upgrade manual',1,0)");
        (new StocktakeService($db))->open('ZZ upgrade inventory', null, ['collocazione_da' => $b['location'], 'collocazione_a' => $b['location']], null);
        $flag('fines', true);
        (new PatronLedger($db))->manualCharge($p, 400, 'ZZ upgrade existing debt', null);
        // Existing data would activate the migration's RC defaults. An explicit
        // saved OFF must still win; a saved ON must survive a default of OFF.
        $sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0.sql');
        $sql = implode("\n", array_filter(explode("\n", $sql), static fn (string $line): bool => !str_starts_with(trim($line), '--')));
        $statements = array_filter(explode(';', $sql), static fn (string $statement): bool => trim($statement) !== '');
        $capture = static function () use ($db, $settings, $p, $b): array {
            ConfigStore::clearCache(); LibraryCalendar::reset();
            CirculationPolicy::resetSchemaCache(); PatronLedger::resetSchemaCache();
            $policy = (new CirculationPolicy($db))->forLoan($p, $b['id'], $b['copy']);
            $enabled = [];
            foreach (Features::keys() as $key) { $enabled[$key] = Features::enabled($key); }
            return [
                'settings' => $db->query('SELECT * FROM system_settings ORDER BY category, setting_key')->fetch_all(MYSQLI_ASSOC),
                'enabled' => $enabled,
                'stored' => Features::snapshot(),
                'policy' => [$policy->durationDays, $policy->maxRenewals, $policy->loanable, $policy->maxActiveLoans],
                'debt_blocked' => PatronLedger::isDebtBlocked($db, $p),
                'balance' => (new PatronLedger($db))->balanceCents($p),
                'due_date' => LibraryCalendar::dueDate($db, '2038-08-15', $b['copy']),
                'card' => PatronCardSettings::resolve($settings),
                'retention' => AttendanceRepository::retentionDays($settings),
                'book' => $db->query("SELECT * FROM libri WHERE id={$b['id']}")->fetch_assoc(),
                'copy' => $db->query("SELECT * FROM copie WHERE id={$b['copy']}")->fetch_assoc(),
                'closures' => $db->query('SELECT * FROM calendario_chiusure ORDER BY id')->fetch_all(MYSQLI_ASSOC),
            ];
        };
        foreach (['all off', 'all on', 'mixed in catalogue mode'] as $profile => $label) {
            foreach (Features::keys() as $index => $key) {
                $flag($key, $profile === 1 || ($profile === 2 && $index % 2 === 0));
            }
            foreach ([
                ['system', 'catalogue_mode', $profile === 2 ? '1' : '0'],
                ['app', 'timezone', 'America/New_York'], ['app', 'currency', 'USD'],
                ['loans', 'loan_duration_days', '17'], ['loans', 'pickup_expiry_days', '9'],
                ['loans', 'max_renewals', '0'], ['loans', 'max_active_loans_per_user', '0'],
                ['loans', 'daily_fine', $profile === 1 ? '0.37' : '0'],
                ['loans', 'debt_block_threshold', $profile === 1 ? '0' : '2.75'],
                ['loans', 'fine_grace_days', '0'], ['loans', 'closed_weekdays', '0,1'],
                ['cards', 'layout', 'portrait'], ['cards', 'code_type', 'qr'],
                ['cards', 'show_photo', '0'], ['cards', 'show_logo', '1'], ['cards', 'show_expiry', '0'],
                ['attendance', 'retention_days', '0'],
                ['calendar', 'holiday_country', 'DE'], ['calendar', 'holiday_region', 'BY'],
                ['calendar', 'holiday_rules', '["DE:mariae_himmelfahrt"]'],
                ['custom_library', 'operator_choice', 'preserve even an unknown preference'],
            ] as [$category, $key, $value]) { $set($category, $key, $value); }
            $before = $capture();
            for ($pass = 1; $pass <= 2; $pass++) {
                foreach ($statements as $statement) {
                    $result = $db->query($statement);
                    if ($result instanceof mysqli_result) { $result->free(); }
                }
                $assert($capture() === $before, "Upgrade changed saved choices or behaviour: {$label}, pass {$pass}");
            }
        }

        // Also exercise the real 0.8.9 -> 0.9.0 schema transition. A checked-in
        // release fixture makes this mandatory even in a shallow CI checkout.
        $legacySchema = (string) file_get_contents($root . '/tests/fixtures/upgrades/schema-0.8.9.sql');
        $assert(str_contains($legacySchema, 'CREATE TABLE `system_settings`'), 'The 0.8.9 release schema fixture is missing');
        foreach (['0', '1'] as $catalogueMode) {
            $admin->query("DROP DATABASE `{$sandbox}`");
            $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->select_db($sandbox);
            $db->multi_query($legacySchema);
            do {
                if ($result = $db->store_result()) { $result->free(); }
            } while ($db->more_results() && $db->next_result());
            ConfigStore::clearCache(); LibraryCalendar::reset();
            CirculationPolicy::resetSchemaCache(); PatronLedger::resetSchemaCache();
            foreach ([
                ['system', 'catalogue_mode', $catalogueMode], ['app', 'timezone', 'America/New_York'],
                ['app', 'currency', 'USD'], ['loans', 'loan_duration_days', '17'],
                ['loans', 'max_renewals', '0'], ['loans', 'max_active_loans_per_user', '0'],
                ['loans', 'pickup_expiry_days', '9'], ['loans', 'auto_approve_requests', '0'],
                ['label', 'width', '62'], ['label', 'height', '31'],
                ['custom_library', 'operator_choice', 'keep the existing choice'],
            ] as [$category, $key, $value]) { $set($category, $key, $value); }
            $legacyReader = $patron(); $legacyBook = $book();
            $captureLegacy = static function () use ($db, $legacyReader, $legacyBook): array {
                ConfigStore::clearCache(); LibraryCalendar::reset();
                CirculationPolicy::resetSchemaCache(); PatronLedger::resetSchemaCache();
                $policy = (new CirculationPolicy($db))->forLoan($legacyReader, $legacyBook['id'], $legacyBook['copy']);
                return [
                    'saved' => $db->query("SELECT * FROM system_settings WHERE category <> 'features' ORDER BY category, setting_key")->fetch_all(MYSQLI_ASSOC),
                    'mode' => ConfigStore::isCatalogueMode(),
                    'currency' => FineParameters::currency(),
                    'policy' => [$policy->durationDays, $policy->maxRenewals, $policy->maxActiveLoans, $policy->loanable],
                    'debt_blocked' => PatronLedger::isDebtBlocked($db, $legacyReader),
                    'due_date' => LibraryCalendar::dueDate($db, '2038-08-15', $legacyBook['copy']),
                    'reader' => $db->query("SELECT codice_tessera, data_scadenza_tessera, stato FROM utenti WHERE id={$legacyReader}")->fetch_assoc(),
                    'book' => $db->query("SELECT titolo, tipo_media, formato, prezzo FROM libri WHERE id={$legacyBook['id']}")->fetch_assoc(),
                    'copy' => $db->query("SELECT numero_inventario, stato FROM copie WHERE id={$legacyBook['copy']}")->fetch_assoc(),
                ];
            };
            $before = $captureLegacy();
            $assert($before['policy'] === [17, 0, 0, true] && !$before['debt_blocked'] && $before['due_date'] === '2038-08-15', 'The legacy reference behaviour is wrong');
            for ($pass = 1; $pass <= 2; $pass++) {
                foreach ($statements as $statement) {
                    $result = $db->query($statement);
                    if ($result instanceof mysqli_result) { $result->free(); }
                }
                $assert($captureLegacy() === $before, "0.8.9 upgrade changed saved choices or behaviour: catalogue mode {$catalogueMode}, pass {$pass}");
            }
        }
    });

    $assert(count($cases) === 37, 'This suite must register exactly 37 scenarios');
    foreach ($cases as [$name, $body]) {
        try {
            $db->rollback();
            $db->query('DELETE FROM calendario_chiusure');
            $db->query('DELETE FROM regole_prestito');
            $db->query('DELETE FROM presenze');
            $db->query("DELETE FROM tipologie_lettore WHERE codice <> 'standard'");
            $db->query('DELETE FROM system_settings');
            $restore = $db->prepare('INSERT INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?)');
            foreach ($baseline as $row) {
                $restore->bind_param('sss', $row['category'], $row['setting_key'], $row['setting_value']);
                $restore->execute();
            }
            $restore->close();
            Features::resetOverrides(); ConfigStore::clearCache(); LibraryCalendar::reset();
            CirculationPolicy::resetSchemaCache(); PatronLedger::resetSchemaCache();
            $asAdmin();
            $body();
            echo "PASS {$name}\n";
            $passed++;
        } catch (Throwable $e) {
            echo "FAIL {$name}: {$e->getMessage()}\n";
            $failed++;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL configuration suite setup: ' . $e->getMessage() . PHP_EOL);
    $failed++;
} finally {
    Features::resetOverrides(); LibraryCalendar::reset();
    $shared->setValue(null, $previousConnection); ConfigStore::clearCache();
    $_SESSION = $previousSession;
    if ($db instanceof mysqli) { $db->rollback(); $db->close(); }
    if ($admin instanceof mysqli) { $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`"); $admin->close(); }
}
echo "Settings flexibility: {$passed}/37 passed, {$failed} failed\n";
exit($passed === 37 && $failed === 0 ? 0 : 1);
