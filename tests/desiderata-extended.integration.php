<?php
declare(strict_types=1);

/**
 * The half of the desiderata test plan that needs a real database and the real
 * classes: T2, T3, T4, T6, T10, T11, T12 and T17.
 *
 * WHERE EACH SECTION RUNS
 *   Sections 1-5 (T6, T10, T11, T12, T17) run against the INSTALLATION's
 *   database. They only ever create rows carrying this run's unique prefix and
 *   delete exactly those in the finally.
 *
 *   Section 6 (T2, T3, T4) does NOT. It drives the plugin LIFECYCLE —
 *   onActivate()/onDeactivate() rewrite home_content, plugin_hooks and
 *   plugin_settings, none of which can be scoped to a test fixture — so it runs
 *   in a disposable sandbox database of its own, refused by name when that name
 *   is the installation's, following tests/desiderata-visibility.integration.php
 *   section D. Like that suite it FAILS HARD rather than skipping when the
 *   sandbox is unreachable: a skip is not a pass.
 *
 * TRAPS THIS SUITE IS BUILT AROUND
 *   - Mail is really sent. The development installation runs the `mail` driver,
 *     for which Mailer::isSmtpReachable() answers true without probing
 *     anything, so an un-injected run hands a real message to PHP's mail() once
 *     per active operator — the owner's own address included. A capturing
 *     EmailService is injected before the first line of fixture work, not just
 *     inside T17.
 *   - ConfigStore cannot reach the database from a bare CLI process, because
 *     config/settings.php reads $_ENV and nothing populates it here. The .env
 *     is copied in below and the connection is verified against a known row.
 *   - mysqli::$insert_id must not be read after offer(): the operator
 *     notification is written on the same connection straight afterwards, so
 *     insert_id then names the admin_notifications row. Offers are read back by
 *     this run's prefix instead, exactly as tests/desiderata.integration.php
 *     does.
 *   - DesiderataPlugin memoises the plugin id and the presence of the
 *     libri_editori junction in CLASS statics. Both are reset at the sandbox
 *     boundary: a memo carried over from a database that has no junction table
 *     would make the publisher checks quietly stop exercising it.
 *
 * Run:  php tests/desiderata-extended.integration.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/storage/plugins/desiderata/wrapper.php';

use App\Support\ConfigStore;
use App\Support\Hooks;
use App\Support\HookManager;
use App\Support\I18n;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Fault injection, on prepare() only — which is the whole reason the writes
 * under test use prepared statements. A $db->query() would slip straight past
 * this and an "injected failure" test built on one proves nothing.
 */
final class DesiderataExtendedDb extends mysqli
{
    public bool $failCopy = false;
    public ?Closure $beforeOfferInsert = null;
    public bool $failPluginSettings = false;

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->failCopy && preg_match('/INSERT\s+INTO\s+copie/i', $query) === 1) {
            throw new RuntimeException('Injected physical copy failure');
        }
        if ($this->failPluginSettings && preg_match('/INSERT\s+INTO\s+plugin_settings/i', $query) === 1) {
            throw new RuntimeException('Injected plugin_settings failure');
        }

        if ($this->beforeOfferInsert !== null && str_starts_with($query, 'INSERT INTO desiderata_offers (book_id, donor_name')) {
            $callback = $this->beforeOfferInsert;
            $this->beforeOfferInsert = null;
            $callback();
        }
        return parent::prepare($query);
    }
}

/** Every notification this suite triggers stops here. */
final class DesiderataExtendedMail extends \App\Support\EmailService
{
    /** @var list<array{to: string, subject: string, name: string, locale: ?string}> */
    public array $sent = [];
    /** When true, every send throws — the "the mail server is having a bad day" case. */
    public bool $explode = false;

    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null, array $attachments = []): bool
    {
        if ($this->explode) {
            throw new RuntimeException('Injected mail failure');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'name' => $toName, 'locale' => $locale];

        return true;
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
/** A precondition, not an assertion: if it does not hold, the checks below mean nothing. */
$must = static function (bool $ok, string $what): void {
    if (!$ok) {
        throw new RuntimeException('precondition failed: ' . $what);
    }
};

$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
foreach ($env as $key => $value) {
    if (!array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null);
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$db = new DesiderataExtendedDb(
    $env['DB_HOST'] ?? 'localhost',
    $dbUser,
    $dbPass,
    $dbName,
    (int) ($env['DB_PORT'] ?? 3306),
    is_string($socket) && $socket !== '' ? $socket : null
);
$db->set_charset('utf8mb4');

$plugin = new DesiderataPlugin($db, new HookManager($db));
$plugin->ensureSchema();
$mailbox = new DesiderataExtendedMail($db);
$plugin->setEmailService($mailbox);

$prefix = 'DWTEST_' . bin2hex(random_bytes(6));
$token = substr($prefix, 7, 12);
$bookIds = [];
$publisherIds = [];
$offerIds = [];
$userIds = [];
$notificationMark = (int) $db->query('SELECT COALESCE(MAX(id), 0) AS n FROM admin_notifications')->fetch_assoc()['n'];

$scalar = static fn(string $sql): mixed => $db->query($sql)->fetch_row()[0];
$repo = new App\Models\BookRepository($db);
$copies = new App\Models\CopyRepository($db);

/** A book of this run, tracked for the cleanup. */
$makeBook = static function (string $suffix, bool $wanted, array $extra = []) use ($repo, $prefix, &$bookIds): int {
    $id = $repo->createBasic($extra + [
        'titolo' => $prefix . $suffix,
        'copie_totali' => 0,
        'is_desiderata' => $wanted ? 1 : 0,
    ]);
    $bookIds[] = $id;

    return $id;
};

$adminRequest = static fn(array $body = []) => (new ServerRequestFactory())
    ->createServerRequest('POST', '/admin/desiderata/books/0/received')
    ->withParsedBody($body);

/**
 * The id of the proposal just written, read back by prefix. NEVER
 * mysqli::$insert_id — see the file header.
 */
$lastOfferId = static function () use ($db, $prefix): int {
    $like = $prefix . '%';
    $stmt = $db->prepare('SELECT MAX(id) FROM desiderata_offers WHERE title LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    if ($id === 0) {
        throw new RuntimeException('no proposal found for prefix ' . $prefix);
    }

    return $id;
};

$fatalError = null;
// Every cleanup statement that threw, with the statement that threw it. Under
// MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT a failed query() is an exception,
// and an exception raised inside the finally below would do two bad things at
// once: abandon the deletes after it, leaving this run's fixtures in the
// operator's live catalogue, and REPLACE whatever failure the run was about to
// report with a stack trace from the teardown. So the deletes are each run
// independently and their failures accumulate here, to be reported after the
// primary result rather than instead of it.
$cleanupErrors = [];
// Set when this run had to create email/from_email because the installation
// did not have one; declared here so the finally can see it even if the run
// dies before the line that sets it.
$seededFromEmail = false;
$sandbox = null;

try {
    echo "Checkbox receipt regression tests\n";
    $receiptBook = $makeBook('checkbox-receipt', true);
    $receiptInput = ['desiderata_form' => '1', 'desiderata_copies' => '2'];
    $receiptFields = $plugin->prepareBook(['titolo' => $prefix . 'checkbox-receipt'], $receiptInput, $receiptBook);
    $check(!isset($receiptFields['is_desiderata']), 'unchecking preserves the stored flag until receipt commits');
    $repo->updateBasic($receiptBook, $receiptFields);
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$receiptBook") === 1, 'metadata save does not close the request');
    $db->failCopy = true;
    $plugin->bookSaved($receiptBook, $receiptFields);
    $db->failCopy = false;
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$receiptBook") === 1, 'copy failure retains desiderata');
    $check((int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$receiptBook") === 0, 'copy failure leaves no inventory');
    $check((int)$scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id=$receiptBook") === 0, 'copy failure leaves no receipt');
    $check(!empty($_SESSION['error_message']), 'copy failure is visible to operator');
    unset($_SESSION['error_message']);
    $receiptFields = $plugin->prepareBook(['titolo' => $prefix . 'checkbox-receipt'], $receiptInput, $receiptBook);
    $repo->updateBasic($receiptBook, $receiptFields);
    $plugin->bookSaved($receiptBook, $receiptFields);
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$receiptBook") === 0, 'retry closes request');
    $check((int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$receiptBook") === 2, 'retry registers requested copies');
    $check((int)$scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id=$receiptBook AND status='received'") === 1, 'retry creates one audit receipt');
    $plugin->bookSaved($receiptBook, $receiptFields);
    $check((int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$receiptBook") === 2, 'repeated hook creates no duplicates');
    foreach (['pending', 'accepted'] as $openStatus) {
        $blockedBook = $makeBook('checkbox-' . $openStatus, true);
        $title = $prefix . 'checkbox-' . $openStatus;
        $stmt = $db->prepare("INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, notes, status) VALUES (?, 'Test', 'test@example.invalid', ?, '', ?)");
        $stmt->bind_param('iss', $blockedBook, $title, $openStatus); $stmt->execute(); $stmt->close();
        $f = $plugin->prepareBook(['titolo' => $title], $receiptInput, $blockedBook);
        $repo->updateBasic($blockedBook, $f);
        $plugin->bookSaved($blockedBook, $f);
        $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$blockedBook") === 1, "$openStatus offer retains request");
        $check((int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$blockedBook") === 0, "$openStatus offer prevents direct inventory");
        $check((int)$scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id=$blockedBook AND status='received'") === 0, "$openStatus offer prevents direct receipt");
        $check((int)$scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id=$blockedBook AND status='$openStatus'") === 1, "$openStatus donor proposal remains intact");
        $check(!empty($_SESSION['error_message']), "$openStatus offer explains rejection");
        unset($_SESSION['error_message']);
    }


    echo "Release: proposal/receipt serialization (10 checks)\n";
    $raceBook = $makeBook('release-race', true);
    $submitReleaseOffer = static function (?int $book) use ($plugin, $prefix): Response {
        $_SESSION = [];
        $q = (new ServerRequestFactory())->createServerRequest('POST', '/desiderata/offers')->withParsedBody([
            'book_id' => $book === null ? '' : (string)$book,
            'donor_name' => 'Release test', 'donor_email' => 'release@example.invalid',
            'title' => $prefix . 'release-offer', 'author' => '', 'publisher' => '',
            'isbn' => '', 'notes' => '', 'consent' => '1',
        ]);
        return $plugin->offer($q, new Response());
    };
    $locked = false;
    $db->beforeOfferInsert = static function () use ($env, $dbUser, $dbPass, $dbName, $socket, $raceBook, &$locked): void {
        $peer = new mysqli($env['DB_HOST'] ?? 'localhost', $dbUser, $dbPass, $dbName, (int)($env['DB_PORT'] ?? 3306), $socket ?: null);
        try {
            $peer->query('SET SESSION innodb_lock_wait_timeout=1');
            $peer->begin_transaction();
            try {
                $peer->query("SELECT id FROM libri WHERE id=$raceBook FOR UPDATE");
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1205) { throw $e; }
                $locked = true;
            }
        } finally { $peer->rollback(); $peer->close(); }
    };
    $proposal = $submitReleaseOffer($raceBook);
    $check($locked, 'R01: another connection cannot receive between proposal validation and insert');
    $check($proposal->getStatusCode() === 303 && (int)$scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id=$raceBook AND status='pending'") === 1, 'R02: the lock owner commits exactly one proposal');
    $check($plugin->receiveDirect($adminRequest(), new Response(), $raceBook)->getStatusCode() === 422 && (int)$scalar("SELECT COUNT(*) FROM copie WHERE libro_id=$raceBook") === 0, 'R03: receipt after proposal commit is refused without copies');
    $receivedFirst = $makeBook('release-received-first', true);
    $plugin->receiveDirect($adminRequest(), new Response(), $receivedFirst);
    $check($submitReleaseOffer($receivedFirst)->getStatusCode() === 422, 'R04: proposal after receipt commit is rejected');
    $check((int)$scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id=$receivedFirst AND status='pending'") === 0, 'R05: closed request has no late proposal');
    $failedBook = $makeBook('release-insert-failure', true);
    $db->beforeOfferInsert = static function (): void { throw new RuntimeException('Injected proposal insert failure'); };
    $check($submitReleaseOffer($failedBook)->getStatusCode() === 422, 'R06: insert failure returns recoverable validation response');
    $check((int)$scalar("SELECT is_desiderata FROM libri WHERE id=$failedBook") === 1 && (int)$scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id=$failedBook") === 0, 'R07: failed proposal leaves request intact without partial rows');
    $check(empty($_SESSION['desiderata_last_offer']) && empty($_SESSION['desiderata_success']), 'R08: failed proposal neither consumes throttle nor reports success');
    $check($submitReleaseOffer($failedBook)->getStatusCode() === 303, 'R09: retry after rollback succeeds');
    $check($submitReleaseOffer(null)->getStatusCode() === 303, 'R10: free-form donations still commit without a requested book');

    $mailbox->sent = [];
    // ConfigStore has to be REACHING the database, not answering from the
    // shipped defaults: T17 reads the mail driver through it, and a suite that
    // silently ran on defaults would be describing a different installation.
    // The row is created when it is missing rather than required to be there.
    // A fresh installation — every CI runner is one — has no email/from_email
    // yet, and demanding it made this assert a property of the DEVELOPER's
    // database instead of a property of ConfigStore. What has to be proven is
    // the round trip: a value written to system_settings is what ConfigStore
    // answers, so the suite is reaching the database and not the shipped
    // defaults (which is exactly what an empty $_ENV would silently cause).
    $knownRow = $db->query("SELECT setting_value FROM system_settings WHERE category = 'email' AND setting_key = 'from_email' LIMIT 1")->fetch_assoc();
    $seededFromEmail = false;
    if ($knownRow === null) {
        $probeAddress = strtolower($prefix) . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('email', 'from_email', ?)");
        $stmt->bind_param('s', $probeAddress);
        $stmt->execute();
        $stmt->close();
        $seededFromEmail = true;
        $knownRow = ['setting_value' => $probeAddress];
        ConfigStore::clearCache();
    }
    $must(
        (string) ConfigStore::get('mail.from_email', '') === (string) $knownRow['setting_value'],
        'ConfigStore reads the database (copy .env into $_ENV before touching it)'
    );

    echo "1. T6 — the public search reaches the publisher, through both places it lives\n";
    $must(
        $db->query("SHOW TABLES LIKE 'libri\\_editori'")->num_rows === 1,
        'this installation has the libri_editori junction (without it the junction branch cannot be exercised)'
    );
    $publisherName = $prefix . '-EDITORE Casa';
    $stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
    $stmt->bind_param('s', $publisherName);
    $stmt->execute();
    $publisherId = (int) $db->insert_id;
    $stmt->close();
    $publisherIds[] = $publisherId;

    // Deliberately NOT containing the search term in any title: the term only
    // ever matches through the publisher, which is the branch under test.
    $cover = '/uploads/copertine/' . $prefix . '-cover.jpg';
    $byColumn = $makeBook(' alpha', true, ['editore_id' => $publisherId, 'copertina_url' => $cover]);
    $byJunction = $makeBook(' beta', true);
    $db->query('INSERT INTO libri_editori (libro_id, editore_id, ordine) VALUES (' . $byJunction . ', ' . $publisherId . ', 1)');
    $held = $makeBook(' gamma held', true, ['editore_id' => $publisherId]);
    $copies->createWithAllocatedInventoryCode($held, $prefix . '-held');
    $archived = $makeBook(' delta archived', true, ['editore_id' => $publisherId]);
    $db->query('UPDATE libri SET deleted_at = NOW() WHERE id = ' . $archived);

    $rows = $plugin->wanted($prefix . '-EDITORE', 50);
    $found = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    sort($found);
    $expected = [$byColumn, $byJunction];
    sort($expected);
    $check($found === $expected, 'a publisher search finds the libri.editore_id book AND the libri_editori one, and nothing else');
    $check(!in_array($held, $found, true), 'a sibling of the same publisher that the library already holds is excluded');
    $check(!in_array($archived, $found, true), 'a soft-deleted sibling of the same publisher is excluded');
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }
    $check(
        isset($byId[$byColumn]['cover']) && $byId[$byColumn]['cover'] === url($cover),
        'the row carries the seeded cover URL'
    );
    $check(
        isset($byId[$byJunction]['cover']) && $byId[$byJunction]['cover'] === url(DesiderataPlugin::PLACEHOLDER_COVER),
        'a request with no cover carries the placeholder URL instead of an empty src'
    );
    $check(
        array_filter($rows, static fn(array $row): bool => ($row['cover'] ?? '') === '') === [],
        'every returned row carries a cover URL'
    );
    // The control: without it the two checks above would also pass on a
    // wanted() that matched everything, since the fixture titles share a prefix.
    $check($plugin->wanted($prefix . '-EDITORE-NOBODY', 50) === [], 'a publisher nobody carries matches nothing');

    echo "2. T10 — direct receipt is all-or-nothing\n";
    $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin']];
    $transactional = $makeBook(' transactional', true);
    $db->failCopy = true;
    ob_start();
    $response = $plugin->receiveDirect($adminRequest(), new Response(), $transactional);
    ob_end_clean();
    $db->failCopy = false;
    $check($response->getStatusCode() === 422, 'an injected copy failure refuses the receipt');
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id = $transactional") === 0, 'no physical copy survives the rollback');
    $check((int) $scalar("SELECT is_desiderata FROM libri WHERE id = $transactional") === 1, 'the request flag is still set');
    $check((int) $scalar("SELECT copie_totali FROM libri WHERE id = $transactional") === 0, 'the holdings count is still zero');
    $check((int) $scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id = $transactional") === 0, 'no audit row was left behind');
    $check($mailbox->sent === [], 'a refused receipt notifies nobody');

    echo "3. T11 — direct receipt refuses what the donor-tracked flow owns\n";
    $withProposal = $makeBook(' with proposal', true);
    $proposalTitle = $prefix . ' with proposal';
    $stmt = $db->prepare("INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, notes, status) VALUES (?, 'Donor', 'donor@example.invalid', ?, '', 'pending')");
    $stmt->bind_param('is', $withProposal, $proposalTitle);
    $stmt->execute();
    $stmt->close();
    $offerIds[] = $lastOfferId();

    ob_start();
    $response = $plugin->receiveDirect($adminRequest(), new Response(), $withProposal);
    $html = (string) $response->getBody();
    ob_end_clean();
    $check($response->getStatusCode() === 422, 'a book somebody has already offered is refused');
    $check(
        str_contains($html, htmlspecialchars(__('Questo libro ha proposte aperte: registra la ricezione dalla proposta.'), ENT_QUOTES, 'UTF-8')),
        'and the operator is told to receive it from the proposal instead'
    );
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id = $withProposal") === 0, 'no copy is created for a book with an open proposal');
    $check((int) $scalar("SELECT is_desiderata FROM libri WHERE id = $withProposal") === 1, 'its request stays open');
    $check((int) $scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id = $withProposal") === 1, 'and no second audit row appears beside the proposal');

    $ordinary = $makeBook(' ordinary holding', false);
    $copies->createWithAllocatedInventoryCode($ordinary, $prefix . '-ordinary');
    ob_start();
    $response = $plugin->receiveDirect($adminRequest(), new Response(), $ordinary);
    $html = (string) $response->getBody();
    ob_end_clean();
    $check($response->getStatusCode() === 422, 'an ordinary book the library already holds is refused');
    $check(
        str_contains($html, htmlspecialchars(__('Il libro non è più una richiesta aperta.'), ENT_QUOTES, 'UTF-8')),
        'and is refused for being no open request, not for something else'
    );
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id = $ordinary") === 1, 'its single copy is not joined by a second one');

    $deleted = $makeBook(' archived request', true);
    $db->query('UPDATE libri SET deleted_at = NOW() WHERE id = ' . $deleted);
    ob_start();
    $response = $plugin->receiveDirect($adminRequest(), new Response(), $deleted);
    ob_end_clean();
    $check($response->getStatusCode() === 422, 'a soft-deleted request is refused');
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id = $deleted") === 0, 'and gains no copy');
    $check((int) $scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id = $deleted") === 0, 'and gains no audit row');

    echo "4. T12 — patrons never reach the new admin surfaces\n";
    // The real registered routes with their real middleware, not the method.
    $app = Slim\Factory\AppFactory::create();
    $app->addBodyParsingMiddleware();
    $plugin->registerRoutes($app);
    $factory = new ServerRequestFactory();
    $patronTarget = $makeBook(' patron target', true);
    $receivePath = '/admin/desiderata/books/' . $patronTarget . '/received';

    $_SESSION = [];
    $response = $app->handle($factory->createServerRequest('POST', $receivePath)->withParsedBody(['return_to' => 'dashboard']));
    $check($response->getStatusCode() === 302, 'an anonymous POST to the direct-receipt route is turned away');

    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'standard']];
    $response = $app->handle($factory->createServerRequest('POST', $receivePath)->withParsedBody(['return_to' => 'dashboard']));
    $check($response->getStatusCode() === 302, 'a patron POST to the direct-receipt route is turned away');
    $check(
        $response->getHeaderLine('Location') === \App\Support\RouteTranslator::route('profile'),
        'and is sent to their own profile, not to an admin page'
    );
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id = $patronTarget") === 0, 'no copy was created for the patron');
    $check((int) $scalar("SELECT is_desiderata FROM libri WHERE id = $patronTarget") === 1, 'the request is untouched');
    $check((int) $scalar("SELECT COUNT(*) FROM desiderata_offers WHERE book_id = $patronTarget") === 0, 'and no audit row was written');

    $response = $app->handle($factory->createServerRequest('GET', '/admin/desiderata'));
    $check($response->getStatusCode() === 302, 'a patron cannot open the desiderata admin page either');

    // The dashboard is shared with patrons (AuthMiddleware admits standard and
    // premium), so the protection there is the $isAdminOrStaff gate around the
    // hook, not the route. Both roles are rendered: the admin render is the
    // control that proves the panel is wired at all, without which the patron
    // assertion would pass on a plugin that renders nothing for anybody.
    // The handler is registered HERE rather than read out of plugin_hooks.
    // Loading the registry from the database would make this check assert a
    // property of whatever installation it runs against: on a developer machine
    // with the plugin switched on the panel appears and the check passes, while
    // on a clean CI schema the plugin is registered INACTIVE (it is optional),
    // no hook rows exist, and the same code fails for a reason that has nothing
    // to do with the code. setPluginsLoadedRuntime() keeps the manager from
    // consulting the database at all, so what is under test is the plugin's own
    // handler and the operator gate inside it.
    $wiredHooks = new HookManager($db);
    $wiredHooks->setPluginsLoadedRuntime();
    Hooks::init($wiredHooks);
    Hooks::add('admin.dashboard.sections', static fn(): mixed => $plugin->dashboard());
    $dashboard = new \App\Controllers\DashboardController();
    $renderDashboard = static function (string $role) use ($dashboard, $factory, $db): string {
        $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => $role, 'nome' => 'Test', 'cognome' => 'Operator']];
        $response = $dashboard->index($factory->createServerRequest('GET', '/admin/dashboard'), new Response(), $db);
        $_SESSION = [];

        return (string) $response->getBody();
    };
    $asAdmin = $renderDashboard('admin');
    $check(str_contains($asAdmin, 'id="desiderata-dashboard"'), 'an operator dashboard carries the desiderata panels');
    foreach (['standard', 'premium'] as $role) {
        $asPatron = $renderDashboard($role);
        $check($asPatron !== '', "the $role dashboard still renders");
        $check(!str_contains($asPatron, 'id="desiderata-dashboard"'), "a $role dashboard carries no desiderata panel");
        $check(!str_contains($asPatron, '/admin/desiderata'), "a $role dashboard offers no link into the desiderata admin");
    }
    // Put the hook registry back the way this process found it, so nothing
    // below accidentally runs through the installation's plugins.
    $emptyHooks = new HookManager($db);
    $emptyHooks->setPluginsLoadedRuntime();
    Hooks::init($emptyHooks);

    echo "5. T17 — a proposal rings the bell once and mails each operator in their own language\n";
    $must(\App\Support\Mailer::isSmtpReachable(), 'Mailer::isSmtpReachable() is true, so the e-mail branch actually runs');
    $seededOperators = [
        'it' => ['tessera' => 'ZZDW' . $token . 'I', 'email' => 'zzdw-it-' . $token . '@example.invalid', 'locale' => 'it_IT', 'stato' => 'attivo'],
        'en' => ['tessera' => 'ZZDW' . $token . 'E', 'email' => 'zzdw-en-' . $token . '@example.invalid', 'locale' => 'en_US', 'stato' => 'attivo'],
        'off' => ['tessera' => 'ZZDW' . $token . 'S', 'email' => 'zzdw-off-' . $token . '@example.invalid', 'locale' => 'it_IT', 'stato' => 'sospeso'],
    ];
    foreach ($seededOperators as $key => $operator) {
        $stmt = $db->prepare(
            "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, locale, privacy_accettata)
             VALUES (?, 'ZZDW', ?, ?, 'x', ?, 'admin', ?, 1)"
        );
        $surname = strtoupper($key);
        $stmt->bind_param('sssss', $operator['tessera'], $surname, $operator['email'], $operator['stato'], $operator['locale']);
        $stmt->execute();
        $userIds[] = (int) $db->insert_id;
        $stmt->close();
    }

    $inLocale = static function (string $locale, callable $fn): string {
        $previous = I18n::getLocale();
        I18n::setLocale($locale);
        try {
            return (string) $fn();
        } finally {
            I18n::setLocale($previous);
        }
    };
    $notified = $makeBook(' notified', true);
    $notifiedTitle = $prefix . ' notified';
    $expectedIt = $inLocale('it_IT', static fn(): string => sprintf(__('Nuova proposta di donazione: %s'), $notifiedTitle));
    $expectedEn = $inLocale('en_US', static fn(): string => sprintf(__('Nuova proposta di donazione: %s'), $notifiedTitle));
    $check($expectedIt !== $expectedEn, 'the two locales really produce different subjects (otherwise the check below is vacuous)');

    $mailbox->sent = [];
    $_SESSION = [];
    $offerRequest = (new ServerRequestFactory())
        ->createServerRequest('POST', '/desiderata/offers')
        ->withParsedBody([
            'donor_name' => 'Test donor',
            'donor_email' => 'donor@example.invalid',
            'title' => $prefix,
            'consent' => '1',
            'book_id' => (string) $notified,
        ]);
    $response = $plugin->offer($offerRequest, new Response());
    $check($response->getStatusCode() === 303, 'the proposal is accepted');
    $notifiedOffer = $lastOfferId();
    $offerIds[] = $notifiedOffer;

    $bell = $db->query(
        "SELECT type, link, related_id FROM admin_notifications
          WHERE id > $notificationMark AND related_id = $notifiedOffer AND type = 'general'"
    )->fetch_all(MYSQLI_ASSOC);
    $check(count($bell) === 1, 'exactly one bell row is written for the proposal');
    $check(($bell[0]['type'] ?? '') === 'general', "the bell row's type is general");
    $check(str_contains((string) ($bell[0]['link'] ?? ''), '/admin/desiderata#donation-offers'), "the bell row links to the proposals list");
    $check((int) ($bell[0]['related_id'] ?? 0) === $notifiedOffer, 'the bell row points at this very proposal');

    $toSeeded = [];
    foreach ($mailbox->sent as $message) {
        foreach ($seededOperators as $key => $operator) {
            if ($message['to'] === $operator['email']) {
                $toSeeded[$key][] = $message;
            }
        }
    }
    $check(count($toSeeded['it'] ?? []) === 1, 'the Italian operator got exactly one e-mail');
    $check(count($toSeeded['en'] ?? []) === 1, 'the English operator got exactly one e-mail');
    $check(!isset($toSeeded['off']), 'the suspended operator got none');
    $check(($toSeeded['it'][0]['subject'] ?? '') === $expectedIt, 'the Italian operator reads an Italian subject');
    $check(($toSeeded['en'][0]['subject'] ?? '') === $expectedEn, 'the English operator reads an English subject');
    $check(($toSeeded['it'][0]['locale'] ?? '') === 'it_IT' && ($toSeeded['en'][0]['locale'] ?? '') === 'en_US', 'each message is rendered in the recipient locale');
    $check(I18n::getLocale() === I18n::getInstallationLocale(), 'the per-recipient locale switching leaked nothing');

    // A mail server having a bad day must not cost the library the donation.
    $mailbox->sent = [];
    $mailbox->explode = true;
    $_SESSION = [];
    $response = $plugin->offer($offerRequest, new Response());
    $mailbox->explode = false;
    $check($response->getStatusCode() === 303, 'a throwing mailer still lets the proposal through');
    $secondOffer = $lastOfferId();
    $offerIds[] = $secondOffer;
    $check($secondOffer !== $notifiedOffer, 'and the proposal really was written');
    $stillBelled = (int) $scalar("SELECT COUNT(*) FROM admin_notifications WHERE id > $notificationMark AND related_id = $secondOffer AND type = 'general'");
    $check($stillBelled === 1, 'the bell row is written even when the e-mail cannot be');
    $check($mailbox->sent === [], 'and nothing was silently delivered after the failure');

    echo "6. T2/T3/T4 — plugin lifecycle and the CMS card (SANDBOX database)\n";
    // onActivate()/onDeactivate() rewrite tables that belong to the whole
    // installation, so they never run against it.
    $sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName) !== 1) {
        throw new RuntimeException("invalid sandbox database name '{$sandboxName}'");
    }
    if (strcasecmp($sandboxName, (string) $dbName) === 0) {
        fwrite(STDERR, <<<TXT
            FAIL: DESIDERATA_SANDBOX_DB is set to '{$sandboxName}', which is the installation's own database.
                  Section 6 drops and rebuilds home_content, plugin_hooks and plugin_settings, so it must never point there.
                  Leave it unset to use '{$dbName}_desiderata', or name a disposable database.

            TXT);
        throw new RuntimeException('the sandbox database is the installation\'s own');
    }
    try {
        $db->query("CREATE DATABASE IF NOT EXISTS `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (\Throwable) {
        // It may already exist and simply not be creatable by this account.
    }
    $probe = null;
    try {
        $probe = $db->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $db->real_escape_string($sandboxName) . "'");
    } catch (\Throwable) {
        $probe = null;
    }
    if (!($probe instanceof \mysqli_result) || $probe->num_rows !== 1) {
        fwrite(STDERR, <<<TXT
            FAIL: the sandbox database '{$sandboxName}' does not exist and this account cannot create it.
                  Section 6 rewrites plugin lifecycle tables, so it needs a database of its own.
                  Create it once, as an administrator:
                    CREATE DATABASE `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                    GRANT ALL PRIVILEGES ON `{$sandboxName}`.* TO '{$dbUser}'@'localhost';
                  Or point DESIDERATA_SANDBOX_DB at a disposable database you already own.

            TXT);
        throw new RuntimeException("the sandbox database '{$sandboxName}' is unreachable");
    }
    $probe->free();

    $sandbox = new DesiderataExtendedDb(
        $env['DB_HOST'] ?? 'localhost',
        $dbUser,
        $dbPass,
        $sandboxName,
        (int) ($env['DB_PORT'] ?? 3306),
        is_string($socket) && $socket !== '' ? $socket : null
    );
    $sandbox->set_charset('utf8mb4');

    // The class statics the plugin memoises. Left alone, the installation's
    // plugin id and its libri_editori answer would be reused here.
    $resolvedId = new ReflectionProperty(DesiderataPlugin::class, 'resolvedPluginId');
    $resolvedId->setAccessible(true);
    $resolvedId->setValue(null, null);
    $junction = new ReflectionProperty(DesiderataPlugin::class, 'publisherJunction');
    $junction->setAccessible(true);
    $junction->setValue(null, null);

    // The sandbox is EMPTIED, not relieved of a named list. It is shared with
    // the other desiderata suites, which load the full schema.sql into it, and
    // that schema hangs foreign keys off the very tables named below —
    // plugin_data onto plugins, copie onto libri. Dropping a fixed list leaves
    // the referencing tables standing and the DROP is refused ("Cannot drop
    // table 'plugins' referenced by a foreign key constraint
    // 'fk_plugin_data_plugin'"), so whether this section runs at all depended
    // on which sibling happened to go first.
    $sandbox->query('SET FOREIGN_KEY_CHECKS = 0');
    $leftovers = [];
    $shown = $sandbox->query('SHOW TABLES');
    while ($t = $shown->fetch_row()) {
        $leftovers[] = $t[0];
    }
    foreach ($leftovers as $table) {
        $sandbox->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $sandbox->query('SET FOREIGN_KEY_CHECKS = 1');
    // deleted_at is not optional: the plugin's composite index is
    // (is_desiderata, deleted_at) and ensureSchema() would fail without it.
    $sandbox->query('CREATE TABLE libri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        titolo VARCHAR(255) NOT NULL,
        deleted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $sandbox->query('CREATE TABLE plugins (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        path VARCHAR(255) NOT NULL DEFAULT "",
        version VARCHAR(20) NOT NULL DEFAULT "1.1.0",
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $sandbox->query('CREATE TABLE plugin_hooks (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        plugin_id INT UNSIGNED NOT NULL,
        hook_name VARCHAR(100) NOT NULL,
        callback_class VARCHAR(255) NOT NULL,
        callback_method VARCHAR(100) NOT NULL,
        priority INT NOT NULL DEFAULT 10,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    // The UNIQUE key is what makes writeSetting()'s ON DUPLICATE KEY UPDATE an
    // upsert rather than an endless stream of rows.
    $sandbox->query('CREATE TABLE plugin_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        plugin_id INT UNSIGNED NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value LONGTEXT NULL,
        autoload TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_plugin_setting (plugin_id, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $sandbox->query('CREATE TABLE home_content (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        section_key VARCHAR(50) NOT NULL UNIQUE,
        title VARCHAR(255) NULL,
        is_active TINYINT(1) NULL DEFAULT 1,
        display_order INT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $sandbox->query("INSERT INTO home_content (section_key, title, is_active, display_order) VALUES ('hero', 'Hero', 1, 1), ('cta', 'CTA', 1, 2)");
    $sandbox->query("INSERT INTO plugins (name, path, version, is_active) VALUES ('desiderata', 'desiderata', '1.1.0', 1)");
    $sandboxPluginId = (int) $sandbox->insert_id;

    $sandboxPlugin = new DesiderataPlugin($sandbox, new HookManager($sandbox));
    $sandboxPlugin->setPluginId($sandboxPluginId);

    $sectionRow = static function () use ($sandbox): ?array {
        $result = $sandbox->query("SELECT id, is_active, display_order FROM home_content WHERE section_key = 'desiderata'");

        return $result === false ? null : ($result->fetch_assoc() ?: null);
    };
    $sectionCount = static fn(): int => (int) $sandbox->query("SELECT COUNT(*) AS n FROM home_content WHERE section_key = 'desiderata'")->fetch_assoc()['n'];
    $hookCount = static fn(): int => (int) $sandbox->query('SELECT COUNT(*) AS n FROM plugin_hooks')->fetch_assoc()['n'];

    // --- T2 ------------------------------------------------------------
    $sandboxPlugin->onActivate();
    $first = $sectionRow();
    $check($sectionCount() === 1, 'activation inserts the home_content row');
    $check((int) ($first['display_order'] ?? 0) === 3, 'it lands after the existing sections');
    $check((int) ($first['is_active'] ?? 0) === 1, 'and starts visible');
    $registered = $hookCount();
    $check($registered > 0, 'activation registers the hook rows');

    $sandboxPlugin->onActivate();
    $second = $sectionRow();
    $check($sectionCount() === 1, 'a second activation does not add a second row');
    $check($second == $first, 'and leaves the existing row byte for byte as it was');
    $check($hookCount() === $registered, 'and re-registers exactly the same hooks, without duplicating them');

    // The operator moves the section and hides it. This is the state an
    // upgrade must not destroy.
    $sandbox->query("UPDATE home_content SET display_order = 3, is_active = 0 WHERE section_key = 'desiderata'");
    $sandbox->query("UPDATE home_content SET display_order = 5 WHERE section_key = 'cta'");
    $sandboxPlugin->onActivate();
    $afterUpgrade = $sectionRow();
    $check(
        (int) ($afterUpgrade['display_order'] ?? -1) === 3 && (int) ($afterUpgrade['is_active'] ?? -1) === 0,
        "the 1.0.0 to 1.1.0 upgrade path leaves the operator's position and visibility alone"
    );
    $check((int) ($afterUpgrade['id'] ?? 0) === (int) ($first['id'] ?? -1), 'and it is the same row, not a recreated one');

    $sandboxPlugin->onDeactivate();
    $check($sectionCount() === 0, 'deactivation removes the row, so the CMS list no longer offers it');
    $check($hookCount() === 0, 'and drops every hook row');
    $snapshot = $sandbox->query("SELECT setting_value FROM plugin_settings WHERE plugin_id = $sandboxPluginId AND setting_key = 'home_section_state'")->fetch_assoc();
    $decoded = json_decode((string) ($snapshot['setting_value'] ?? ''), true);
    $check(
        is_array($decoded) && (int) ($decoded['display_order'] ?? -1) === 3 && (int) ($decoded['is_active'] ?? -1) === 0,
        'the operator position is remembered in plugin_settings'
    );

    $sandboxPlugin->onActivate();
    $restored = $sectionRow();
    $check($sectionCount() === 1, 're-activation brings the row back');
    $check(
        (int) ($restored['display_order'] ?? -1) === 3 && (int) ($restored['is_active'] ?? -1) === 0,
        'at the position and visibility the operator had chosen'
    );
    $check($hookCount() === $registered, 'and with the full hook set again');

    // --- T3 ------------------------------------------------------------
    $localeBefore = I18n::getLocale();
    $saveErrors = $sandboxPlugin->cmsSave([], ['desiderata' => ['is_active' => '1', 'texts' => [
        'en_US' => ['title' => 'Books we seek EN'],
        'it_IT' => ['title' => 'Libri IT'],
    ]]]);
    $check($saveErrors === [], 'a valid CMS save reports no error');
    $check($sandboxPlugin->texts('en_US')['title'] === 'Books we seek EN', 'the English override wins in English');
    $check($sandboxPlugin->texts('it_IT')['title'] === 'Libri IT', 'the Italian override wins in Italian');
    $defaultDe = $inLocale('de_DE', static fn(): string => __('I libri che cerchiamo'));
    $defaultIt = $inLocale('it_IT', static fn(): string => __('I libri che cerchiamo'));
    $check($defaultDe !== $defaultIt, 'the shipped title really is translated per locale (otherwise the next check is vacuous)');
    $check($sandboxPlugin->texts('de_DE')['title'] === $defaultDe, 'a locale with no override reads the shipped text IN THAT LOCALE');
    $check(
        $sandboxPlugin->texts('en_US')['eyebrow'] === $inLocale('en_US', static fn(): string => __('Cresciamo insieme')),
        'a field with no override falls back per locale, not to the overridden locale'
    );
    $check(I18n::getLocale() === $localeBefore, 'rendering other locales restores the request locale');

    // --- T4 ------------------------------------------------------------
    $sandboxPlugin->cmsSave([], ['desiderata' => ['is_active' => '1', 'texts' => ['it_IT' => ['eyebrow' => '  <b>x</b>  ']]]]);
    $check($sandboxPlugin->texts('it_IT')['eyebrow'] === 'x', 'submitted HTML is stripped and the value trimmed before it is stored');
    $check(
        !str_contains((string) json_encode($sandboxPlugin->allTexts()), '<b>'),
        'no markup reaches the stored map at all'
    );

    $sandbox->query("UPDATE home_content SET is_active = 1 WHERE section_key = 'desiderata'");
    $storedBefore = (string) json_encode($sandboxPlugin->allTexts());
    // is_active is deliberately ABSENT from this payload: a save that got as
    // far as its writes would flip the section off, so the check below proves
    // the refusal happened before anything was written.
    $tooLong = $sandboxPlugin->cmsSave([], ['desiderata' => ['texts' => ['it_IT' => ['title' => str_repeat('a', 500)]]]]);
    $check(
        $tooLong === [__('Testo troppo lungo per la sezione Desiderata (%s).', __('Titolo della sezione'))],
        'an over-long text is reported, naming the field'
    );
    $check((string) json_encode($sandboxPlugin->allTexts()) === $storedBefore, 'and nothing is written to the stored texts');
    $check((int) ($sectionRow()['is_active'] ?? 0) === 1, 'and the visibility the operator had is untouched');

    $incoming = ['un errore del form principale'];
    $passedThrough = $sandboxPlugin->cmsSave($incoming, ['desiderata' => ['texts' => ['it_IT' => ['title' => 'non deve essere scritto']]]]);
    $check($passedThrough === $incoming, 'a non-empty incoming error list is returned untouched');
    $check((string) json_encode($sandboxPlugin->allTexts()) === $storedBefore, 'and the save is skipped entirely, like every core block on that page');

    $sandbox->failPluginSettings = true;
    $thrown = null;
    try {
        $onFailure = $sandboxPlugin->cmsSave([], ['desiderata' => ['is_active' => '1', 'texts' => ['it_IT' => ['title' => 'mai scritto']]]]);
    } catch (\Throwable $escaped) {
        $thrown = $escaped;
        $onFailure = null;
    } finally {
        $sandbox->failPluginSettings = false;
    }
    $check($thrown === null, 'a database failure never escapes the filter (HookManager would swallow it and report success)');
    $check(
        is_array($onFailure) && in_array(__('Impossibile salvare la sezione Desiderata. Riprova.'), $onFailure, true),
        'it is reported as an error instead'
    );
    $check((string) json_encode($sandboxPlugin->allTexts()) === $storedBefore, 'and nothing was written');
} catch (\Throwable $thrownFatal) {
    // Recorded, never rethrown: the fixtures below live in the real catalogue
    // and the wanted ones are invisible in the UI that could remove them.
    $fatalError = $thrownFatal;
} finally {
    $db->failCopy = false;
    $_SESSION = [];
    if ($sandbox instanceof mysqli) {
        $sandbox->failPluginSettings = false;
        // Same reason as the teardown's counterpart at setup: a fixed list
        // cannot drop a table something else still references.
        try {
            $sandbox->query('SET FOREIGN_KEY_CHECKS = 0');
        } catch (\Throwable) {
            // Best effort; the loop below reports nothing either way.
        }
        foreach (['desiderata_offers', 'plugin_hooks', 'plugin_settings', 'home_content', 'plugins', 'libri'] as $table) {
            try {
                $sandbox->query("DROP TABLE IF EXISTS `{$table}`");
            } catch (\Throwable) {
                // A sandbox left standing costs nothing; failing here would
                // hide the real error.
            }
        }
        $sandbox->close();
    }
    // Restore the memoised statics for whatever runs next in this process.
    try {
        $resolved = new ReflectionProperty(DesiderataPlugin::class, 'resolvedPluginId');
        $resolved->setAccessible(true);
        $resolved->setValue(null, null);
        $junctionMemo = new ReflectionProperty(DesiderataPlugin::class, 'publisherJunction');
        $junctionMemo->setAccessible(true);
        $junctionMemo->setValue(null, null);
    } catch (\Throwable) {
        // Reflection on a renamed property must not mask the real failure.
    }
    // Swept by PREFIX, not only by the ids the happy paths recorded. A run that
    // ends early — or one where the code under test accepts something this
    // suite expected it to refuse — writes proposals nobody tracked, and an
    // id-only cleanup leaves them in the operator's backlog. The prefix is
    // unique to this process, so the sweep can never reach anybody's data.
    $like = $db->real_escape_string($prefix) . '%';
    // One statement, one attempt, never a lost successor: a row this suite
    // cannot delete must not stop it deleting the rest.
    $sweep = static function (string $sql) use ($db, &$cleanupErrors): void {
        try {
            $db->query($sql);
        } catch (\Throwable $error) {
            $cleanupErrors[] = $sql . "\n      " . $error->getMessage();
        }
    };
    try {
        foreach ($db->query("SELECT id FROM desiderata_offers WHERE title LIKE '$like'")->fetch_all(MYSQLI_ASSOC) as $row) {
            $offerIds[] = (int) $row['id'];
        }
    } catch (\Throwable) {
        // A failure here must not hide the real error reported below.
    }
    $offerIds = array_values(array_unique(array_filter($offerIds, static fn(int $id): bool => $id > 0)));
    if ($offerIds !== []) {
        // Only the bell rows this run created: bounded by the high-water mark
        // taken at the start AND by the proposals this suite owns.
        $sweep('DELETE FROM admin_notifications WHERE id > ' . $notificationMark . " AND type = 'general' AND related_id IN (" . implode(',', $offerIds) . ')');
        $sweep('DELETE FROM desiderata_offers WHERE id IN (' . implode(',', $offerIds) . ')');
    }
    $sweep("DELETE FROM desiderata_offers WHERE title LIKE '$like'");
    if ($bookIds !== []) {
        $list = implode(',', $bookIds);
        $sweep('DELETE FROM desiderata_offers WHERE book_id IN (' . $list . ') OR received_book_id IN (' . $list . ')');
        $sweep('DELETE FROM libri_editori WHERE libro_id IN (' . $list . ')');
        $sweep('DELETE FROM copie WHERE libro_id IN (' . $list . ')');
        $sweep("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN (" . $list . ')');
        $sweep('DELETE FROM libri WHERE id IN (' . $list . ')');
    }
    if ($seededFromEmail) {
        $sweep("DELETE FROM system_settings WHERE category = 'email' AND setting_key = 'from_email'");
    }
    if ($publisherIds !== []) {
        $sweep('DELETE FROM editori WHERE id IN (' . implode(',', $publisherIds) . ')');
    }
    if ($userIds !== []) {
        $sweep('DELETE FROM utenti WHERE id IN (' . implode(',', $userIds) . ')');
    }
    try {
        App\Support\ContentCache::booksChanged();
        $db->close();
    } catch (\Throwable $error) {
        $cleanupErrors[] = 'closing the connection' . "\n      " . $error->getMessage();
    }
}

// Reported BEFORE the primary result so the primary result is what the reader
// is left looking at — a teardown problem is a real problem, but it is never
// the explanation of the run.
if ($cleanupErrors !== []) {
    fwrite(STDERR, "\nCLEANUP FAILED — fixture rows may still be in the database:\n");
    foreach ($cleanupErrors as $message) {
        fwrite(STDERR, "    {$message}\n");
    }
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
if ($cleanupErrors !== []) {
    // On its own this still has to fail the run: a cleanup that quietly gives
    // up is how the demo data in this database would get polluted unnoticed.
    fwrite(STDERR, "FAIL: the cleanup reported above did not complete.\n");
}
exit($fail === 0 && $cleanupErrors === [] ? 0 : 1);
