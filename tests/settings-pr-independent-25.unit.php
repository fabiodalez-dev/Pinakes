<?php
declare(strict_types=1);

/**
 * Independent acceptance review of the settings touched by PR #519.
 *
 * Exactly 25 scenarios, each with a fresh fixture. Expected dates, amounts
 * and capacity are calculated from the stated business examples below, never
 * with LibraryCalendar, CirculationPolicy or FineCalculator as the oracle.
 * Real form controllers, two real MySQL sessions and production DB triggers.
 * The only injection schedules a competing write immediately before a lock;
 * it does not replace a query result or a domain decision.
 *
 * Requires CREATE DATABASE on a prefix derived from E2E_DB_NAME/DB_NAME.
 * Creates and drops its own randomly named database; never writes to the source DB.
 * Run: php tests/settings-pr-independent-25.unit.php
 * Optional: REVIEW25_CASE=01 (or a comma-separated list) selects scenarios.
 * Known defects are ordinary failing assertions, not expected failures.
 */

use App\Controllers\Admin\LibraryCalendarController;
use App\Controllers\CirculationSettingsController;
use App\Controllers\PrestitiController;
use App\Controllers\SettingsController;
use App\Models\SettingsRepository;
use App\Plugins\MobileApi\Controllers\ActionsController;
use App\Services\PatronLedger;
use App\Support\ConfigStore;
use App\Support\LibraryCalendar;
use App\Support\MaintenanceService;
use App\Support\OneTimeFormToken;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/storage/plugins/mobile-api/MobileApiPlugin.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
putenv('PINAKES_DISABLE_CLI_PURGE=1');

final class Review25Db extends mysqli
{
    public ?Closure $beforeBookLock = null;
    public ?Closure $beforeCalendarLock = null;

    public function prepare(string $query): mysqli_stmt|false
    {
        foreach ([
            'beforeBookLock' => str_starts_with($query, 'SELECT id FROM libri WHERE id = ? FOR UPDATE'),
            'beforeCalendarLock' => str_contains($query, 'GET_LOCK('),
        ] as $property => $matches) {
            if ($matches && $this->$property !== null) {
                $work = $this->$property;
                $this->$property = null;
                $work();
            }
        }
        return parent::prepare($query);
    }
}

final class SettingsReview25
{
    public Review25Db $db;
    public mysqli $other;
    public mysqli $admin;
    public string $sandbox = '';
    public bool $created = false;
    public string $today;
    public int $operator;
    public int $reader;
    public int $secondReader;
    public int $branchA;
    public int $branchB;
    private int $serial = 0;

    public function __construct()
    {
        $root = dirname(__DIR__);
        $env = Dotenv\Dotenv::parse((string) @file_get_contents($root . '/.env'));
        $source = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
        $user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
        if ($source === '' || $user === '') {
            throw new RuntimeException('Configure E2E_DB_NAME and E2E_DB_USER (or .env); this suite never silently skips.');
        }
        $host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
        $port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
        $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
        $pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
        $this->admin = new mysqli($host, $user, $pass, '', $port, $socket ?: null);
        $prefix = substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $source), 0, 38);
        $this->sandbox = $prefix . '_review25_' . bin2hex(random_bytes(5));
        try {
            $this->admin->query("CREATE DATABASE `{$this->sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->created = true;
            $command = ['mysql', '--host=' . $host, '--port=' . $port, '--user=' . $user];
            if ($socket !== '') {
                $command[] = '--socket=' . $socket;
            }
            $command[] = $this->sandbox;
            foreach (['schema.sql', 'data_it_IT.sql', 'triggers.sql'] as $file) {
                $process = proc_open($command, [
                    0 => ['file', $root . '/installer/database/' . $file, 'r'],
                    1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, $root, array_merge(getenv(), ['MYSQL_PWD' => $pass]));
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot start schema import: ' . $file);
                }
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($process) !== 0) {
                    throw new RuntimeException('Schema import failed: ' . $file . ': ' . $output . $error);
                }
            }
            $args = [$host, $user, $pass, $this->sandbox, $port, $socket ?: null];
            $this->db = new Review25Db(...$args);
            $this->other = new mysqli(...$args);
            foreach ([$this->db, $this->other] as $connection) {
                $connection->set_charset('utf8mb4');
                $connection->query("SET time_zone = '+00:00'");
                $connection->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $connection->query('SET SESSION innodb_lock_wait_timeout = 1');
            }
            $this->today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        } catch (Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    public function close(): void
    {
        foreach (['other', 'db'] as $key) {
            if (isset($this->$key)) {
                try { $this->$key->rollback(); $this->$key->close(); } catch (Throwable) { /* preserve original failure */ }
            }
        }
        if ($this->created) {
            $this->admin->query("DROP DATABASE `{$this->sandbox}`");
            $this->created = false;
        }
        if (isset($this->admin)) { $this->admin->close(); }
    }

    public function reset(): void
    {
        $this->db->beforeBookLock = $this->db->beforeCalendarLock = null;
        foreach ([$this->db, $this->other] as $connection) {
            $connection->rollback();
            $connection->query('DO RELEASE_ALL_LOCKS()');
        }
        $this->db->query('DROP TRIGGER IF EXISTS review25_refuse_due_move');
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (['log_modifiche', 'admin_notifications', 'movimenti_lettore', 'prestiti', 'prenotazioni', 'copie', 'libri',
                'utenti', 'calendario_chiusure', 'festivita_personalizzate', 'regole_prestito', 'system_settings', 'sedi'] as $table) {
                $this->db->query("DELETE FROM `{$table}`");
            }
            $this->db->query('DELETE FROM tipologie_lettore WHERE predefinita = 0');
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->serial = 0;
        $this->operator = $this->patron('admin');
        $this->reader = $this->patron();
        $this->secondReader = $this->patron();
        $this->asAdmin();
        $this->db->query("INSERT INTO sedi (nome) VALUES ('Review branch A')");
        $this->branchA = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO sedi (nome) VALUES ('Review branch B')");
        $this->branchB = (int) $this->db->insert_id;
        $this->set('app', 'timezone', 'UTC');
        $this->set('system', 'catalogue_mode', '0');
        $this->set('loans', 'closed_weekdays', '');
        // An unreachable local transport prevents accidental external mail delivery.
        foreach (['type' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_security' => ''] as $key => $value) {
            $this->set('email', $key, $value);
        }
        $this->saveLoans();
    }

    public static function eq(mixed $actual, mixed $expected, string $why): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException($why . '; expected ' . json_encode($expected) . ', got ' . json_encode($actual));
        }
    }

    public static function check(bool $condition, string $why): void
    {
        if (!$condition) { throw new RuntimeException($why); }
    }

    public function day(int $offset, ?string $from = null): string
    {
        return (new DateTimeImmutable($from ?? $this->today, new DateTimeZone('UTC')))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    public function scalar(string $sql): mixed
    {
        return $this->db->query($sql)->fetch_row()[0] ?? null;
    }

    public function request(string $path, array $body = []): Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body);
    }

    public function set(string $category, string $key, string $value): void
    {
        (new SettingsRepository($this->db))->set($category, $key, $value);
        ConfigStore::clearCache(); LibraryCalendar::reset();
    }

    public function saveLoans(array $extra = []): Response
    {
        $body = array_replace([
            'loan_duration_days' => '14', 'pickup_expiry_days' => '3', 'max_renewals' => '3',
            'max_loan_duration_days' => '3650', 'max_active_loans_per_user' => '0',
            'feature_circulation_rules' => '1', 'feature_closures_calendar' => '1',
            'feature_fines' => '1', 'feature_automatic_fines' => '1', 'feature_debt_block' => '1',
            'feature_loss_penalties' => '1', 'feature_consultation_only' => '1',
            'daily_fine' => '0.50', 'fine_grace_days' => '0', 'fine_grace_mode' => 'threshold',
            'debt_block_threshold' => '', 'fine_max_per_loan' => '', 'app_timezone' => 'UTC',
        ], $extra);
        return (new SettingsController())->updateLoansSettings($this->request('/admin/settings/loans', $body), new Response(), $this->db, false);
    }

    public function asAdmin(): void
    {
        $_SESSION = ['user' => ['id' => $this->operator, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
    }

    public function patron(string $role = 'standard', ?int $type = null): int
    {
        $n = ++$this->serial;
        $this->db->query("INSERT INTO utenti (codice_tessera,nome,cognome,email,password,stato,tipo_utente,email_verificata,privacy_accettata,tipologia_id)
            VALUES ('RV25-{$n}','Review','Reader {$n}','review25-{$n}@example.invalid','x','attivo','{$role}',1,1," . ($type ?? 'NULL') . ')');
        return (int) $this->db->insert_id;
    }

    public function book(?int $branch = null, string $media = 'libro', int $copies = 1): array
    {
        $n = ++$this->serial;
        $this->db->query("INSERT INTO libri (titolo,tipo_media,copie_totali,copie_disponibili,stato) VALUES ('Review25 book {$n}','{$media}',{$copies},{$copies},'disponibile')");
        $id = (int) $this->db->insert_id;
        $ids = []; $codes = [];
        for ($i = 0; $i < $copies; $i++) {
            $code = "RV25-{$n}-{$i}";
            $this->db->query("INSERT INTO copie (libro_id,numero_inventario,stato,sede_id) VALUES ({$id},'{$code}','disponibile'," . ($branch ?? 'NULL') . ')');
            $ids[] = (int) $this->db->insert_id; $codes[] = $code;
        }
        return ['id' => $id, 'copies' => $ids, 'codes' => $codes];
    }

    public function loan(array $book, string $due, array $options = []): int
    {
        $reader = $options['reader'] ?? $this->reader;
        $copy = $options['copy'] ?? $book['copies'][0];
        $start = $options['start'] ?? $this->day(-30);
        $status = $options['status'] ?? 'in_corso';
        $active = $status === 'restituito' ? 0 : 1;
        $returned = isset($options['returned']) ? "'{$options['returned']}'" : 'NULL';
        $this->db->query("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo,data_restituzione)
            VALUES ({$book['id']},{$copy},{$reader},'{$start}','{$due}','{$status}','diretto',{$active},{$returned})");
        $id = (int) $this->db->insert_id;
        if (in_array($status, ['in_corso', 'in_ritardo', 'da_ritirare'], true)) {
            $copyState = $status === 'da_ritirare' ? 'prenotato' : 'prestato';
            $this->db->query("UPDATE copie SET stato='{$copyState}' WHERE id={$copy}");
            $this->db->query("UPDATE libri SET stato='prestato', copie_disponibili=(SELECT COUNT(*) FROM copie WHERE libro_id={$book['id']} AND stato='disponibile') WHERE id={$book['id']}");
        }
        return $id;
    }

    public function closure(string $start, ?string $end = null, ?int $branch = null): int
    {
        unset($_SESSION['error_message'], $_SESSION['success_message']);
        $controller = new LibraryCalendarController();
        $controller->store($this->request('/admin/calendar/closures', [
            'data_inizio' => $start, 'data_fine' => $end ?? $start,
            'descrizione' => 'Review25 closure', 'sede_id' => $branch === null ? '' : (string) $branch,
        ]), new Response(), $this->db);
        return $controller->createdId() ?? 0;
    }

    public function due(int $id): string
    {
        return (string) $this->scalar("SELECT data_scadenza FROM prestiti WHERE id={$id}");
    }

    public function events(int $book): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id={$book}
            AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi,'$._activity.event'))='loan.due_moved'");
    }

    public function renew(int $id): Response
    {
        return (new PrestitiController())->renew($this->request('/admin/loans/renew/' . $id), new Response(), $this->db, $id);
    }

    public function feed(): array
    {
        $request = $this->request('/api/v1/me/notifications')->withAttribute('mobile_user', ['id' => $this->reader]);
        $response = (new ActionsController($this->db))->notifications($request, new Response());
        self::eq($response->getStatusCode(), 200, 'Notification feed must answer normally');
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    public function rules(array $cells): void
    {
        (new CirculationSettingsController())->saveRules($this->request('/admin/settings/circulation/rules', ['rules' => $cells]), new Response(), $this->db);
    }

    public function type(int $duration, int $renewalDays = 14): int
    {
        $n = ++$this->serial;
        $controller = new CirculationSettingsController();
        $controller->createType($this->request('/admin/settings/circulation/types', [
            'nome' => "Review type {$n}", 'codice' => "rv25-{$n}", 'durata_prestito_giorni' => (string) $duration,
            'giorni_rinnovo' => (string) $renewalDays, 'prenotazioni_abilitate' => '1',
        ]), new Response(), $this->db);
        $id = $controller->createdTypeId();
        self::check($id !== null, 'Patron type must be created before exercising it');
        return $id;
    }

    public function lend(array $book, int $reader): Response
    {
        return (new PrestitiController())->store($this->request('/admin/loans/store', [
            'utente_id' => (string) $reader, 'libro_id' => (string) $book['id'], 'copy_code' => $book['codes'][0],
            'data_prestito' => $this->today, 'data_scadenza' => '', 'consegna_immediata' => '1',
            'loan_submission_token' => OneTimeFormToken::issue('loan.create'),
        ]), new Response(), $this->db);
    }
}

$cases = [
    '01 Calendar save cannot consume capacity just promised by another operator' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA); $due = $t->day(5); $next = $t->day(6);
        $loan = $t->loan($b, $due);
        $competingWrite = false;
        $t->db->beforeBookLock = static function () use ($t, $b, $next, &$competingWrite): void {
            $t->other->begin_transaction();
            $t->other->query("SELECT id FROM libri WHERE id={$b['id']} FOR UPDATE")->fetch_all();
            $t->other->query("INSERT INTO prenotazioni (libro_id,utente_id,data_inizio_richiesta,data_fine_richiesta,data_scadenza_prenotazione,stato)
                VALUES ({$b['id']},{$t->secondReader},'{$next}','{$next}','{$next} 23:59:59','attiva')");
            $t->other->commit();
            $competingWrite = true;
        };
        SettingsReview25::check($t->closure($due, branch: $t->branchA) > 0, 'Calendar save must finish');
        SettingsReview25::check($competingWrite, 'Competing reservation must commit during the calendar save');
        SettingsReview25::eq($t->due($loan), $due, 'The only copy is already promised on the added day');
    },
    '02 First open day after a retroactively closed due day still permits renewal' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA); $loan = $t->loan($b, $t->day(-1));
        SettingsReview25::check($t->closure($t->day(-1), branch: $t->branchA) > 0, 'Closure must save');
        $result = $t->renew($loan);
        SettingsReview25::check(!str_contains($result->getHeaderLine('Location'), 'error='), 'An on-time reader must be allowed to renew: ' . $result->getHeaderLine('Location'));
        SettingsReview25::eq($t->due($loan), $t->day(13), 'Fourteen-day renewal starts from the stored original due day');
    },
    '03 Mobile feed must not call that on-time reader overdue' => static function (SettingsReview25 $t): void {
        $loan = $t->loan($t->book($t->branchA), $t->day(-1), ['status' => 'in_ritardo']);
        $t->closure($t->day(-1), branch: $t->branchA);
        SettingsReview25::eq($t->scalar("SELECT stato FROM prestiti WHERE id={$loan}"), 'in_corso', 'Calendar save reconciles the old late state');
        $overdue = array_values(array_filter($t->feed(), static fn (array $n): bool => $n['type'] === 'loan_overdue'));
        SettingsReview25::eq($overdue, [], 'No overdue message on the first usable return day');
        $request = $t->request('/api/v1/me/loans')->withAttribute('mobile_user', ['id' => $t->reader]);
        $response = (new ActionsController($t->db))->myLoans($request, new Response());
        $active = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data']['active'][0];
        SettingsReview25::eq([$active['due_at'], $active['effective_due_at'], $active['is_overdue']], [$t->day(-1), $t->today, false], 'Android receives stored and usable dates plus authoritative lateness');
        $staff = (new \App\Plugins\MobileApi\Support\StaffLoanReader($t->db))->loan($loan);
        SettingsReview25::eq([$staff['effective_due_at'], $staff['is_overdue'], $staff['days_overdue']], [$t->today, false, 0], 'Desk agrees with the reader and the fine calendar');
        SettingsReview25::check(in_array('renew', $staff['actions'], true) && !in_array('recall', $staff['actions'], true), 'Native desk offers renewal, not recall, to an on-time reader');
        $dueNotices = array_values(array_filter($t->feed(), static fn (array $n): bool => $n['type'] === 'loan_due'));
        SettingsReview25::eq(array_column($dueNotices, 'date'), [$t->today], 'Due-soon notice names the usable day');
        $list = (new \App\Models\LoanRepository($t->db))->listRecent();
        SettingsReview25::eq([$list[0]['effective_due_at'], $list[0]['is_overdue']], [$t->today, false], 'Web-rendered loan list agrees with the mobile presentation');
        $tableResponse = (new \App\Controllers\PrestitiApiController())->list($t->request('/api/prestiti'), new Response(), $t->db);
        $tableRow = json_decode((string) $tableResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'][0];
        SettingsReview25::eq([$tableRow['effective_due_at'], $tableRow['is_overdue'], $tableRow['due_attention']], [$t->today, false, true], 'Paged web table uses the same server calendar flags');
        $readerLoan = (new \App\Models\LoanRepository($t->db))->getById($loan);
        foreach (['profile/reservations.php', 'user_dashboard/prenotazioni.php'] as $view) {
            $html = (static function (string $view, array $activePrestiti): string {
                $items = $pastPrestiti = $myReviews = [];
                ob_start();
                try {
                    require dirname(__DIR__) . '/app/Views/' . $view;
                    return (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            })($view, [$readerLoan]);
            SettingsReview25::check(!str_contains($html, '<div class="alert-overdue"'), 'Reader page must not show a false overdue alert: ' . $view);
        }
    },
    '04 A reader who also missed the first open day really is overdue in the feed' => static function (SettingsReview25 $t): void {
        $loan = $t->loan($t->book($t->branchA), $t->day(-3));
        $t->closure($t->day(-3), $t->day(-2), $t->branchA);
        (new MaintenanceService($t->db))->updateOverdueLoans();
        $overdue = array_values(array_filter($t->feed(), static fn (array $n): bool => $n['type'] === 'loan_overdue'));
        SettingsReview25::eq(array_column($overdue, 'id'), ['overdue:' . $loan], 'Missing yesterday as well must produce exactly one overdue notice');
        SettingsReview25::eq(array_column($overdue, 'date'), [$t->day(-1)], 'Late notice names the first usable return date rather than the closed original day');
        $staff = (new \App\Plugins\MobileApi\Support\StaffLoanReader($t->db))->loan($loan);
        SettingsReview25::eq([$staff['is_overdue'], $staff['days_overdue']], [true, 1], 'Desk counts the one open late day, excluding the retroactive closure');
        SettingsReview25::check(in_array('recall', $staff['actions'], true) && !in_array('renew', $staff['actions'], true), 'Genuinely overdue native loans offer recall and refuse renewal');
    },
    '05 A branch closure shifts its loan while a second branch keeps its date' => static function (SettingsReview25 $t): void {
        $due = $t->day(8);
        $a = $t->loan($t->book($t->branchA), $due); $b = $t->loan($t->book($t->branchB), $due);
        $t->closure($due, $t->day(9), $t->branchA);
        SettingsReview25::eq([$t->due($a), $t->due($b)], [$t->day(10), $due], 'Two-day branch closure must not leak to another branch');
    },
    '06 A booked physical copy cannot be extended just because another copy is free' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA, copies: 2); $due = $t->day(6);
        $loan = $t->loan($b, $due);
        $t->loan($b, $t->day(10), ['start' => $t->day(7), 'reader' => $t->secondReader, 'status' => 'prenotato']);
        SettingsReview25::check($t->closure($due, branch: $t->branchA) > 0, 'Conflicting extension must not discard the closure');
        SettingsReview25::eq([$t->due($loan), $t->events($b['id'])], [$due, 0], 'Per-copy promise wins even when title-wide capacity is two');
    },
    '07 Capacity promised only on the final added day still blocks the whole extension' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA); $due = $t->day(5); $last = $t->day(8);
        $loan = $t->loan($b, $due);
        $t->db->query("INSERT INTO prenotazioni (libro_id,utente_id,data_inizio_richiesta,data_fine_richiesta,data_scadenza_prenotazione,stato)
            VALUES ({$b['id']},{$t->secondReader},'{$last}','{$last}','{$last} 23:59:59','attiva')");
        $t->closure($due, $t->day(7), $t->branchA);
        SettingsReview25::eq($t->due($loan), $due, 'Inclusive last-day occupancy must be checked, not just the first added day');
    },
    '08 Adjacent closures extend twice but never add duplicate history on a replay' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA); $loan = $t->loan($b, $t->day(4));
        $t->closure($t->day(4), branch: $t->branchA);
        $t->closure($t->day(5), $t->day(6), $t->branchA);
        $t->closure($t->day(5), $t->day(6), $t->branchA);
        SettingsReview25::eq([$t->due($loan), $t->events($b['id'])], [$t->day(7), 2], 'Each actual due-date change gets one history entry');
    },
    '09 Weekly closure plus library holiday plus branch closure form one continuous gap' => static function (SettingsReview25 $t): void {
        $sunday = (new DateTimeImmutable($t->day(7), new DateTimeZone('UTC')))->modify('next Sunday')->format('Y-m-d');
        $monday = $t->day(1, $sunday); $tuesday = $t->day(2, $sunday);
        (new LibraryCalendarController())->saveWeekdays($t->request('/admin/calendar/weekdays', ['closed_weekdays' => ['0']]), new Response(), $t->db);
        (new LibraryCalendarController())->storeCustom($t->request('/admin/calendar/custom', [
            'nome' => 'Review Monday', 'tipo' => 'fixed', 'fixed_mese' => substr($monday, 5, 2),
            'fixed_giorno' => substr($monday, 8, 2), 'durata_giorni' => '1', 'attiva' => '1',
        ]), new Response(), $t->db);
        $a = $t->loan($t->book($t->branchA), $sunday); $b = $t->loan($t->book($t->branchB), $sunday);
        $t->closure($tuesday, branch: $t->branchA);
        SettingsReview25::eq([$t->due($a), $t->due($b)], [$t->day(3, $sunday), $tuesday], 'Sources combine and branch-only Tuesday remains scoped');
    },
    '10 Re-enabling the calendar updates checked-out, ready and scheduled loans together' => static function (SettingsReview25 $t): void {
        $t->saveLoans(['feature_closures_calendar' => '0']); $due = $t->day(9); $loans = [];
        foreach (['in_corso', 'da_ritirare', 'prenotato'] as $status) {
            $loans[] = $t->loan($t->book($t->branchA), $due, ['status' => $status]);
        }
        $t->closure($due, $t->day(10), $t->branchA);
        SettingsReview25::eq(array_map($t->due(...), $loans), [$due, $due, $due], 'Disabled module must not move existing commitments');
        $t->saveLoans();
        SettingsReview25::eq(array_map($t->due(...), $loans), [$t->day(11), $t->day(11), $t->day(11)], 'Activation applies the saved policy to every eligible state');
    },
    '11 Removing a closure never shortens a date already promised to the reader' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA); $loan = $t->loan($b, $t->day(4));
        $closure = $t->closure($t->day(4), $t->day(5), $t->branchA);
        (new LibraryCalendarController())->delete($t->request('/admin/calendar/closures/' . $closure), new Response(), $t->db, $closure);
        $t->saveLoans(['feature_closures_calendar' => '0']); $t->saveLoans();
        SettingsReview25::eq([$t->due($loan), $t->events($b['id'])], [$t->day(6), 1], 'Reopening and toggling must not retract a granted deadline');
    },
    '12 Undoing late status preserves notice counters and never fabricates another recall' => static function (SettingsReview25 $t): void {
        $loan = $t->loan($t->book($t->branchA), $t->day(-1), ['status' => 'in_ritardo']);
        $t->db->query("UPDATE prestiti SET overdue_notification_sent=1, recall_count=2, last_recall_at='{$t->day(-2)} 12:00:00' WHERE id={$loan}");
        $t->closure($t->day(-1), branch: $t->branchA);
        $row = $t->db->query("SELECT stato,overdue_notification_sent,recall_count,last_recall_at FROM prestiti WHERE id={$loan}")->fetch_assoc();
        $row['overdue_notification_sent'] = (int) $row['overdue_notification_sent'];
        $row['recall_count'] = (int) $row['recall_count'];
        SettingsReview25::eq($row, ['stato' => 'in_corso', 'overdue_notification_sent' => 1, 'recall_count' => 2, 'last_recall_at' => $t->day(-2) . ' 12:00:00'], 'A calendar reconciliation changes only lateness, not sent history');
    },
    '13 A non-overlap database refusal rolls back closure, due date and history together' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA); $due = $t->day(5); $loan = $t->loan($b, $due);
        $t->db->query("CREATE TRIGGER review25_refuse_due_move BEFORE UPDATE ON prestiti FOR EACH ROW
            BEGIN IF NEW.id={$loan} AND NEW.data_scadenza<>OLD.data_scadenza THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='review25 storage refusal'; END IF; END");
        SettingsReview25::eq($t->closure($due, branch: $t->branchA), 0, 'A genuine storage refusal cannot be treated as an expected overlap');
        SettingsReview25::eq([(int) $t->scalar('SELECT COUNT(*) FROM calendario_chiusure'), $t->due($loan), $t->events($b['id'])], [0, $due, 0], 'Failed save is atomic');
        SettingsReview25::check(isset($_SESSION['error_message']), 'Operator must see that the calendar was not saved');
    },
    '14 A book-lock timeout aborts the calendar save and a retry succeeds exactly once' => static function (SettingsReview25 $t): void {
        $b = $t->book($t->branchA); $due = $t->day(5); $loan = $t->loan($b, $due);
        $t->other->begin_transaction(); $t->other->query("SELECT id FROM libri WHERE id={$b['id']} FOR UPDATE")->fetch_all();
        try {
            SettingsReview25::eq($t->closure($due, branch: $t->branchA), 0, 'Blocked save must report failure');
            SettingsReview25::eq([(int) $t->scalar('SELECT COUNT(*) FROM calendario_chiusure'), $t->due($loan)], [0, $due], 'No half-saved closure after timeout');
        } finally { $t->other->rollback(); }
        SettingsReview25::check($t->closure($due, branch: $t->branchA) > 0, 'Retry after contention must save');
        SettingsReview25::eq([$t->due($loan), $t->events($b['id'])], [$t->day(6), 1], 'One successful retry moves and records the date once');
    },
    '15 Material override and a branch closure both affect a real desk loan' => static function (SettingsReview25 $t): void {
        $type = $t->type(7); $reader = $t->patron(type: $type); $b = $t->book($t->branchA, 'dvd');
        $t->rules([$type => ['dvd' => ['duration' => '9']]]);
        $t->closure($t->day(9), $t->day(10), $t->branchA);
        $response = $t->lend($b, $reader);
        SettingsReview25::check(!str_contains($response->getHeaderLine('Location'), 'error='), 'Desk loan must succeed: ' . $response->getHeaderLine('Location'));
        SettingsReview25::eq($t->scalar("SELECT data_scadenza FROM prestiti WHERE libro_id={$b['id']}"), $t->day(11), 'Nine-day material rule crosses the two-day closure');
    },
    '16 Clearing a material duration changes the next real loan back to its patron type' => static function (SettingsReview25 $t): void {
        $type = $t->type(7); $reader = $t->patron(type: $type);
        $t->rules([$type => ['dvd' => ['duration' => '9']]]);
        $t->rules([$type => ['dvd' => ['duration' => '']]]);
        $b = $t->book($t->branchA, 'dvd'); $response = $t->lend($b, $reader);
        SettingsReview25::check(!str_contains($response->getHeaderLine('Location'), 'error='), 'Inherited desk loan must succeed');
        SettingsReview25::eq($t->scalar("SELECT data_scadenza FROM prestiti WHERE libro_id={$b['id']}"), $t->day(7), 'Empty means inherit seven days, not zero or the old nine');
    },
    '17 Moving a reader to another type changes the renewal of an already open loan' => static function (SettingsReview25 $t): void {
        $first = $t->type(7, 4); $second = $t->type(9, 6); $reader = $t->patron(type: $first);
        $loan = $t->loan($t->book($t->branchA), $t->day(3), ['reader' => $reader]);
        $t->db->query("UPDATE utenti SET tipologia_id={$second} WHERE id={$reader}");
        $response = $t->renew($loan);
        SettingsReview25::check(!str_contains($response->getHeaderLine('Location'), 'error='), 'Renewal must use the reader current policy');
        SettingsReview25::eq($t->due($loan), $t->day(9), 'Three remaining days plus the new type six-day renewal');
    },
    '18 Lowering and restoring the renewal ceiling never resets the used renewal count' => static function (SettingsReview25 $t): void {
        $loan = $t->loan($t->book($t->branchA), $t->day(3));
        $t->db->query("UPDATE prestiti SET renewals=2 WHERE id={$loan}");
        $t->saveLoans(['max_renewals' => '2']);
        SettingsReview25::check(str_contains($t->renew($loan)->getHeaderLine('Location'), 'error=max_renewals'), 'Ceiling equal to usage blocks another renewal');
        SettingsReview25::eq($t->due($loan), $t->day(3), 'Refused renewal must leave the date untouched');
        $t->saveLoans(['max_renewals' => '3']); $t->renew($loan);
        SettingsReview25::eq([$t->due($loan), (int) $t->scalar("SELECT renewals FROM prestiti WHERE id={$loan}")], [$t->day(17), 3], 'Restored ceiling permits precisely one further fourteen-day renewal');
    },
    '19 One invalid matrix cell must not partially change another valid material cell' => static function (SettingsReview25 $t): void {
        $type = $t->type(7); $reader = $t->patron(type: $type);
        $t->rules([$type => ['dvd' => ['duration' => '9'], 'libro' => ['duration' => '8']]]);
        $t->rules([$type => ['dvd' => ['duration' => '5'], 'libro' => ['duration' => '-1']]]);
        $b = $t->book($t->branchA, 'dvd'); $t->lend($b, $reader);
        SettingsReview25::eq($t->scalar("SELECT data_scadenza FROM prestiti WHERE libro_id={$b['id']}"), $t->day(9), 'Rejected matrix save must preserve the original nine-day DVD rule');
    },
    '20 Decimal-comma fine, deducted grace and closed days agree at the actual return' => static function (SettingsReview25 $t): void {
        $t->saveLoans(['daily_fine' => '0,75', 'fine_grace_days' => '2', 'fine_grace_mode' => 'deduct']);
        $b = $t->book($t->branchA);
        $loan = $t->loan($b, '2026-01-10', ['start' => '2026-01-01', 'status' => 'restituito', 'returned' => '2026-01-16']);
        $t->closure('2026-01-12', '2026-01-13', $t->branchA);
        $ledger = PatronLedger::forDb($t->db);
        // Six days after Jan 10, two closed days, two deducted grace days: 2 × 75 cents.
        SettingsReview25::eq($ledger->chargeLateReturn($loan, $t->operator), 150, 'Persisted return must charge two billable open days');
        SettingsReview25::eq($ledger->balanceCents($t->reader), 150, 'The reader ledger must contain the same amount');
    },
    '21 An excessive fine rejects the whole loan-settings form before changing durations' => static function (SettingsReview25 $t): void {
        // A syntactically valid amount just above the form's 9,999.99 limit.
        $t->saveLoans(['loan_duration_days' => '27', 'daily_fine' => '10000.00']);
        $b = $t->book($t->branchA); $t->lend($b, $t->reader);
        SettingsReview25::eq($t->scalar("SELECT data_scadenza FROM prestiti WHERE libro_id={$b['id']}"), $t->day(14), 'Invalid money must not partially persist the requested 27-day duration');
        SettingsReview25::eq($t->scalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='daily_fine'"), '0.50', 'Original daily fine stays saved');
    },
    '22 Capped late fine remains single after retry and after calendar off-on' => static function (SettingsReview25 $t): void {
        $t->saveLoans(['daily_fine' => '0.75', 'fine_max_per_loan' => '1.25']);
        $loan = $t->loan($t->book($t->branchA), '2026-01-10', ['start' => '2026-01-01', 'status' => 'restituito', 'returned' => '2026-01-16']);
        $ledger = PatronLedger::forDb($t->db);
        SettingsReview25::eq($ledger->chargeLateReturn($loan, $t->operator), 125, 'Cap limits six late days to 125 cents');
        $t->saveLoans(['daily_fine' => '0.75', 'fine_max_per_loan' => '1.25', 'feature_closures_calendar' => '0']);
        $t->saveLoans(['daily_fine' => '0.75', 'fine_max_per_loan' => '1.25']);
        SettingsReview25::eq(PatronLedger::forDb($t->db)->chargeLateReturn($loan, $t->operator), 0, 'Retry cannot charge the same returned loan again');
        SettingsReview25::eq($ledger->balanceCents($t->reader), 125, 'Calendar toggles never duplicate a settled charge');
    },
    '23 Debt equal to the threshold is allowed but one cent above is blocked' => static function (SettingsReview25 $t): void {
        $t->saveLoans(['debt_block_threshold' => '1.00', 'feature_automatic_fines' => '0']);
        $ledger = PatronLedger::forDb($t->db);
        $ledger->manualCharge($t->reader, 100, 'Exactly at threshold', $t->operator);
        SettingsReview25::eq(PatronLedger::isDebtBlocked($t->db, $t->reader), false, 'Equal debt is not above the configured threshold');
        $ledger->manualCharge($t->reader, 1, 'One more cent', $t->operator);
        SettingsReview25::eq(PatronLedger::isDebtBlocked($t->db, $t->reader), true, 'One cent above blocks even with automatic fines off');
        $t->saveLoans(['debt_block_threshold' => '1.00', 'feature_automatic_fines' => '0', 'feature_debt_block' => '0']);
        SettingsReview25::eq(PatronLedger::isDebtBlocked($t->db, $t->reader), false, 'Disabling this control releases the block without erasing the debt');
        SettingsReview25::eq($ledger->balanceCents($t->reader), 101, 'The manual ledger remains intact');
    },
    '24 Mixed staff settings POST saves permitted duration but cannot alter money or calendar policy' => static function (SettingsReview25 $t): void {
        $staff = $t->patron('staff'); $_SESSION = ['user' => ['id' => $staff, 'tipo_utente' => 'staff', 'stato' => 'attivo']];
        $t->saveLoans(['loan_duration_days' => '19', 'daily_fine' => '7.25', 'feature_closures_calendar' => '0', 'feature_fines' => '0']);
        $t->asAdmin();
        $b = $t->book($t->branchA); $t->closure($t->day(19), branch: $t->branchA); $t->lend($b, $t->reader);
        SettingsReview25::eq($t->scalar("SELECT data_scadenza FROM prestiti WHERE libro_id={$b['id']}"), $t->day(20), 'Permitted duration changes, protected calendar remains effective');
        SettingsReview25::eq($t->scalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='daily_fine'"), '0.50', 'A mixed POST must not bypass money permissions');
    },
    '25 Two operators toggling one holiday leave it in its original state' => static function (SettingsReview25 $t): void {
        $date = $t->day(15); $controller = new LibraryCalendarController();
        $controller->storeCustom($t->request('/admin/calendar/custom', [
            'nome' => 'Review toggle race', 'tipo' => 'fixed', 'fixed_mese' => substr($date, 5, 2),
            'fixed_giorno' => substr($date, 8, 2), 'durata_giorni' => '1', 'attiva' => '1',
        ]), new Response(), $t->db);
        $id = $controller->createdId(); SettingsReview25::check($id !== null, 'Holiday fixture must be saved');
        $competingToggle = false;
        $t->db->beforeCalendarLock = static function () use ($t, $id, &$competingToggle): void {
            ConfigStore::usingConnection($t->other, static function () use ($t, $id): void {
                (new LibraryCalendarController())->toggleCustom($t->request('/admin/calendar/custom/toggle'), new Response(), $t->other, $id);
            });
            $competingToggle = true;
        };
        $controller->toggleCustom($t->request('/admin/calendar/custom/toggle'), new Response(), $t->db, $id);
        SettingsReview25::check($competingToggle, 'Second operator must really toggle while the first is waiting');
        SettingsReview25::eq((int) $t->scalar("SELECT attiva FROM festivita_personalizzate WHERE id={$id}"), 1, 'Two distinct toggles must not collapse into one stale read');
        $loan = $t->loan($t->book($t->branchA), $date);
        $t->closure($t->day(40), branch: $t->branchA);
        SettingsReview25::eq($t->due($loan), $t->day(16), 'The holiday must still affect due dates after both toggles');
    },
];

$suite = null; $passed = 0; $failed = 0; $savedSession = $_SESSION ?? [];
try {
    SettingsReview25::eq(count($cases), 25, 'Acceptance review must contain exactly 25 independent scenarios');
    $suite = new SettingsReview25();
    ConfigStore::usingConnection($suite->db, static function () use ($suite, $cases, &$passed, &$failed): void {
        $selected = array_filter(explode(',', getenv('REVIEW25_CASE') ?: ''));
        $ids = array_map(static fn (string $name): string => substr($name, 0, 2), array_keys($cases));
        SettingsReview25::check(array_diff($selected, $ids) === [], 'REVIEW25_CASE must select existing two-digit scenario numbers');
        foreach ($cases as $name => $case) {
            if ($selected !== [] && !in_array(substr($name, 0, 2), $selected, true)) { continue; }
            try {
                $suite->reset(); $case($suite); $passed++;
                echo 'PASS ' . $name . PHP_EOL;
            } catch (Throwable $e) {
                $failed++;
                echo 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL;
            }
        }
    });
} catch (Throwable $e) {
    $failed++; fwrite(STDERR, 'FAIL acceptance review setup: ' . $e->getMessage() . PHP_EOL);
} finally {
    $_SESSION = $savedSession;
    LibraryCalendar::reset();
    if ($suite !== null) { $suite->close(); }
}
echo "Independent settings acceptance: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
