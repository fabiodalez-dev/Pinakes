<?php
declare(strict_types=1);

/**
 * Push notifications are written in the instance locale, as PushPayload
 * promises, whoever starts the sweep.
 *
 * The dispatcher builds its titles and bodies with __(), which follows the
 * locale of the running process. From cron that is the installation locale.
 * From the web it is not: maintenance also runs when an administrator signs
 * in and from the maintenance page, with that administrator's session
 * language. Every reader then got the push in the administrator's language.
 *
 * The real PushDispatcher against the database in E2E_DB_* (or the worktree
 * .env), with a provider that records what it is given and sends nothing.
 * The mobile API tables are created from the plugin's own schema when missing.
 *
 * Run: php tests/mobile-push-instance-locale.unit.php
 */

use App\Plugins\MobileApi\Push\PushDispatcher;
use App\Plugins\MobileApi\Push\PushPayload;
use App\Plugins\MobileApi\Push\PushProvider;
use App\Plugins\MobileApi\Push\PushResult;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\HookManager;
use App\Support\I18n;
use App\Support\LibraryCalendar;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
// The sweep below claims every due or overdue loan of the database and hands
// it to a provider double: on real data the readers would never get those
// pushes, already recorded as delivered. Same refusal as the other suites
// that sweep: never the DB_NAME of a .env.
$dbName = getenv('E2E_DB_NAME') ?: '';
if ($dbName === '') {
    fwrite(STDERR, "FAIL: refusing to run — this suite runs the GLOBAL push sweep against a provider double. Export E2E_DB_NAME (plus E2E_DB_HOST/E2E_DB_USER/E2E_DB_PASS or E2E_DB_SOCKET) pointing at a dedicated test database.\n");
    exit(1);
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $dbUser, $dbPass, $dbName, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$title = 'ZZ_PUSHLOCALE_' . $run;
$email = 'zzpushlocale+' . $run . '@pushlocale.test.local';
$processLocale = I18n::getLocale();
// What the sweep of this run wrote: the highest mobile_push_log id before it
// (once the table is known to exist) and the readers its provider double was
// handed a push for. Plus the due-soon window the run found, to put back.
$log = new class {
    public ?int $before = null;
    /** @var array<int,true> */
    public array $users = [];
    public bool $windowChanged = false;
    public ?string $window = null;
};

$cleanup = static function () use ($db, $title, $email, $log): void {
    $titleLike = $title . '%';
    // The sweep claims every due or overdue loan it finds, not only these:
    // its records go, for the readers it pushed to. Nothing else in the log
    // is touched, whatever its id.
    if ($log->before !== null && $log->users !== []) {
        $db->query('DELETE FROM mobile_push_log WHERE id > ' . $log->before . ' AND user_id IN (' . implode(',', array_keys($log->users)) . ')');
    }
    if ($log->windowChanged) {
        $settings = new \App\Models\SettingsRepository($db);
        $log->window === null
            ? $settings->delete('advanced', 'days_before_expiry_warning')
            : $settings->set('advanced', 'days_before_expiry_warning', $log->window);
        ConfigStore::clearCache();
        $log->windowChanged = false;
    }
    foreach ([
        ['DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?', $titleLike],
        ['DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE ?', $titleLike],
        ['DELETE FROM libri WHERE titolo LIKE ?', $titleLike],
        ['DELETE FROM utenti WHERE email = ?', $email],
    ] as [$sql, $value]) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $stmt->close();
    }
};

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$fatal = null;
try {
    ConfigStore::usingConnection($db, static function () use ($db, $run, $title, $email, $check, $cleanup, $log): void {
        $schema = (new \App\Plugins\MobileApi\MobileApiPlugin($db, new HookManager($db)))->ensureSchema();
        $check($schema['failed'] === [], '00 the mobile API tables are in place');
        $cleanup();
        $log->before = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM mobile_push_log')->fetch_row()[0];

        $today = DateHelper::today();
        $card = 'ZZPL' . strtoupper($run);
        $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Push', 'Locale', ?, '', 'standard', 'attivo')");
        $stmt->bind_param('ss', $card, $email);
        $stmt->execute();
        $userId = (int) $db->insert_id;
        $stmt->close();
        $loan = static function (string $suffix, string $due, string $state) use ($db, $title, $userId): int {
            $bookTitle = $title . '_' . $suffix;
            $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'prestato', 1, 0)");
            $stmt->bind_param('s', $bookTitle);
            $stmt->execute();
            $bookId = (int) $db->insert_id;
            $stmt->close();
            $start = LibraryCalendar::shift($due, -14);
            $stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (?, ?, ?, ?, ?, 'diretto', 1)");
            $stmt->bind_param('iisss', $bookId, $userId, $start, $due, $state);
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        // Due on the first open day from today (a closed day is never "due"),
        // and one loan long overdue.
        $dueDay = LibraryCalendar::forDb($db)->nextOpenDay($today);
        // The sweep reads the loans due within the due-soon window, which an
        // installation may set to 0 days and a closure may outlast: for this
        // run the window reaches the fixture's due day, whatever it found.
        $settings = new \App\Models\SettingsRepository($db);
        $windowDays = (int) ((strtotime($dueDay) - strtotime($today)) / 86400);
        if ($settings->daysBeforeExpiryWarning() < $windowDays) {
            $found = $settings->get('advanced', 'days_before_expiry_warning', null);
            $log->window = $found === null ? null : (string) $found;
            $log->windowChanged = true;
            $settings->set('advanced', 'days_before_expiry_warning', (string) $windowDays);
            ConfigStore::clearCache();
        }
        $dueLoan = $loan('due', $dueDay, 'in_corso');
        $lateLoan = $loan('late', LibraryCalendar::shift($today, -40), 'in_ritardo');
        $stmt = $db->prepare("INSERT INTO mobile_push_subscriptions (user_id, provider, endpoint) VALUES (?, 'unifiedpush', ?)");
        $endpoint = 'https://push.example.invalid/' . $run;
        $stmt->bind_param('is', $userId, $endpoint);
        $stmt->execute();
        $stmt->close();
        // Quiet hours would hold the push back: this reader has none.
        $db->query("DELETE FROM mobile_push_prefs WHERE user_id = {$userId}");

        $provider = new class($log) implements PushProvider {
            /** @var list<PushPayload> */
            public array $payloads = [];
            public function __construct(private object $log)
            {
            }
            public function name(): string
            {
                return 'unifiedpush';
            }
            public function send(array $subscription, PushPayload $payload): PushResult
            {
                $this->payloads[] = $payload;
                $this->log->users[(int) ($subscription['user_id'] ?? 0)] = true;
                return PushResult::ok();
            }
        };

        // The instance locale, and the language of an administrator's session.
        $instance = I18n::getInstallationLocale();
        $session = $instance === 'en_US' ? 'it_IT' : 'en_US';
        $inLocale = static function (string $locale, string $message): string {
            $before = I18n::getLocale();
            I18n::setLocale($locale);
            try {
                return __($message);
            } finally {
                I18n::setLocale($before);
            }
        };
        $check(
            $inLocale($instance, 'Prestito in scadenza') !== $inLocale($session, 'Prestito in scadenza')
                && $inLocale($instance, 'Prestito scaduto') !== $inLocale($session, 'Prestito scaduto'),
            "01 control: the titles differ between {$instance} and {$session}"
        );

        $check(I18n::setLocale($session), "02 the sweep starts with the process locale an administrator's session gives ({$session})");
        (new PushDispatcher($db, $provider))->dispatch();
        $check(I18n::getLocale() === $session, '03 the sweep leaves the process locale as it found it');

        $payloadOf = static function (string $type, int $loanId) use ($provider): ?PushPayload {
            foreach ($provider->payloads as $payload) {
                if ($payload->type === $type && ($payload->data['loan_id'] ?? null) === $loanId) {
                    return $payload;
                }
            }
            return null;
        };
        $due = $payloadOf('loan_due', $dueLoan);
        $late = $payloadOf('loan_overdue', $lateLoan);
        $check($due !== null && $late !== null, '04 the due-soon and the overdue push both reach the provider');
        $check($due?->title === $inLocale($instance, 'Prestito in scadenza'), "05 the due-soon title is in the instance locale (got '" . ($due->title ?? '') . "')");
        $check(
            $due?->body === sprintf($inLocale($instance, 'Il prestito di "%s" scade il %s.'), $title . '_due', $dueDay),
            "06 and so is its text (got '" . ($due->body ?? '') . "')"
        );
        $check($late?->title === $inLocale($instance, 'Prestito scaduto'), "07 the overdue title is in the instance locale (got '" . ($late->title ?? '') . "')");
        $check(
            $late !== null && str_starts_with($late->body, explode('%s', $inLocale($instance, 'Il prestito di "%s" è scaduto il %s.'))[0])
                && !str_starts_with($late->body, explode('%s', $inLocale($session, 'Il prestito di "%s" è scaduto il %s.'))[0]),
            "08 and so is its text (got '" . ($late->body ?? '') . "')"
        );
    });
} catch (Throwable $e) {
    $fatal = $e;
} finally {
    I18n::setLocale($processLocale);
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "cleanup failed: {$cleanupError->getMessage()}\n");
    }
    $db->close();
}

if ($fatal !== null) {
    fwrite(STDERR, 'FAIL: ' . $fatal->getMessage() . ' @ ' . basename($fatal->getFile()) . ':' . $fatal->getLine() . "\n");
    exit(1);
}
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
