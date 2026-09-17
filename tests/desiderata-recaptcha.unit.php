<?php
declare(strict_types=1);

/**
 * T18 + T19 — reCAPTCHA v3 on the donation form.
 *
 * T18 drives the real verifier through an injected transport, so what is
 * proven is that the plugin passes the CONFIGURED secret and the POSTED token
 * to Google, sets the expected action and the 0.5 threshold, and writes no row
 * when the verdict is negative. T19 proves the two degradation rules the
 * contact form already follows: no keys configured means the verifier is never
 * called at all, and keys configured means a submission without a usable token
 * is refused rather than waved through.
 *
 * THE TRAP THIS SUITE IS BUILT AROUND
 * ConfigStore reaches the database through config/settings.php, which reads
 * $_ENV — and a bare `php tests/...` process has an empty $_ENV, because
 * phpdotenv is only run by the web bootstrap. A suite that merely called
 * ConfigStore::set() would write into the void, read back the shipped empty
 * default, skip every verification branch in verifyRecaptcha() and report a
 * full set of green checks having exercised nothing. The .env is therefore
 * copied into $_ENV below, and the very first assertion is that a written
 * secret reads back.
 *
 * Run:  php tests/desiderata-recaptcha.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/storage/plugins/desiderata/wrapper.php';

use App\Support\ConfigStore;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Records what the plugin asked Google and answers with canned JSON. */
final class DesiderataRecaptchaProbe implements \ReCaptcha\RequestMethod
{
    public int $calls = 0;
    public ?\ReCaptcha\RequestParameters $lastParams = null;

    public function __construct(private string $json)
    {
    }

    public function submit(\ReCaptcha\RequestParameters $params): string
    {
        $this->calls++;
        $this->lastParams = $params;

        return $this->json;
    }
}

/**
 * Explodes on contact. Used where the verifier must never run: a transport
 * that merely returned a failure would let a fail-open regression through,
 * because the offer would be refused for the wrong reason and the test would
 * still look right.
 */
final class DesiderataRecaptchaTripwire implements \ReCaptcha\RequestMethod
{
    public int $calls = 0;

    public function submit(\ReCaptcha\RequestParameters $params): string
    {
        $this->calls++;

        throw new RuntimeException('the reCAPTCHA verifier was called with no secret configured');
    }
}

/**
 * Mail never leaves this process. The development installation runs the `mail`
 * driver and Mailer::isSmtpReachable() answers true for it, so an un-injected
 * suite would hand a real message to PHP's mail() once per active operator —
 * including the owner's own address — every time an offer is accepted.
 */
final class DesiderataRecaptchaMail extends \App\Support\EmailService
{
    /** @var list<array{to: string, subject: string}> */
    public array $sent = [];

    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null, array $attachments = []): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject];

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

$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
// THE fix for the trap described above. Written before anything touches
// ConfigStore, and never overwriting a variable the caller exported.
foreach ($env as $key => $value) {
    if (!array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$db = new mysqli(
    $env['DB_HOST'] ?? 'localhost',
    getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? $env['DB_PASSWORD'] ?? ''),
    getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) ($env['DB_PORT'] ?? 3306),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null)
);
$db->set_charset('utf8mb4');

$plugin = new DesiderataPlugin($db, new App\Support\HookManager($db));
$plugin->ensureSchema();
$mailbox = new DesiderataRecaptchaMail($db);
$plugin->setEmailService($mailbox);

$prefix = 'DWTEST_' . bin2hex(random_bytes(6));
$secret = $prefix . '-secret';
$bookIds = [];
$offerIds = [];

$scalar = static fn(string $sql): mixed => $db->query($sql)->fetch_row()[0];
$offerCount = static fn(): int => (int) $db->query(
    "SELECT COUNT(*) FROM desiderata_offers WHERE title LIKE '" . $db->real_escape_string($prefix) . "%'"
)->fetch_row()[0];
$notificationMark = (int) $scalar('SELECT COALESCE(MAX(id), 0) FROM admin_notifications');

/**
 * The contacts settings this suite rewrites, exactly as found, so the
 * development installation is handed back untouched. The row may legitimately
 * not exist at all, which is a different state from an empty one.
 */
$settingSnapshot = [];
foreach (['recaptcha_site_key', 'recaptcha_secret_key'] as $key) {
    $row = $db->query("SELECT setting_value FROM system_settings WHERE category = 'contacts' AND setting_key = '" . $db->real_escape_string($key) . "' LIMIT 1")->fetch_assoc();
    $settingSnapshot[$key] = $row === null ? null : (string) $row['setting_value'];
}

$useSecret = static function (string $value) use ($check): void {
    ConfigStore::set('contacts.recaptcha_secret_key', $value);
    ConfigStore::clearCache();
    $readBack = ConfigStore::get('contacts', []);
    $actual = is_array($readBack) ? (string) ($readBack['recaptcha_secret_key'] ?? '') : '';
    // Asserted every single time, not once: this is the difference between a
    // suite that exercises verifyRecaptcha() and a suite that silently takes
    // the "no keys configured" early return for all of its checks.
    $check($actual === $value, 'the configured secret reads back as ' . ($value === '' ? 'empty' : 'the written value'));
};

$request = static fn(array $body) => (new ServerRequestFactory())
    ->createServerRequest('POST', '/desiderata/offers')
    ->withParsedBody($body);

$payload = [
    'donor_name' => 'Test donor',
    'donor_email' => 'donor@example.invalid',
    'title' => $prefix,
    'consent' => '1',
];

$fatalError = null;

try {
    $repo = new App\Models\BookRepository($db);
    $bookId = $repo->createBasic(['titolo' => $prefix . ' wanted', 'copie_totali' => 0, 'is_desiderata' => 1]);
    $bookIds[] = $bookId;
    if ((int) $scalar("SELECT is_desiderata FROM libri WHERE id = $bookId") !== 1) {
        throw new RuntimeException('the fixture book is not an open request');
    }

    echo "T18. Verification through an injected transport\n";
    $useSecret($secret);

    $success = new DesiderataRecaptchaProbe((string) json_encode([
        'success' => true,
        'score' => 0.9,
        'action' => 'desiderata_offer',
        'challenge_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        'hostname' => 'localhost',
    ]));
    $plugin->setRecaptchaTransport($success);
    $_SESSION = [];
    $before = $offerCount();
    $response = $plugin->offer($request($payload + ['book_id' => (string) $bookId, 'recaptcha_token' => 'token-from-the-browser']), new Response());
    $check($response->getStatusCode() === 303, 'a verified submission is accepted');
    $check($offerCount() === $before + 1, 'and writes exactly one proposal');
    $offerIds[] = (int) $scalar("SELECT MAX(id) FROM desiderata_offers WHERE title LIKE '" . $db->real_escape_string($prefix) . "%'");
    $check($success->calls === 1, 'the verifier was called exactly once');
    $sent = $success->lastParams?->toArray() ?? [];
    $check(($sent['secret'] ?? null) === $secret, 'the CONFIGURED secret reached the verifier');
    $check(($sent['response'] ?? null) === 'token-from-the-browser', 'the POSTED token reached the verifier');

    // Three separate negative verdicts, because they fail through three
    // different code paths in the library: the threshold check, the action
    // check and the success flag itself. Dropping setScoreThreshold(0.5) or
    // setExpectedAction() would leave the third one passing on its own.
    $rejections = [
        'a score under the 0.5 threshold' => ['success' => true, 'score' => 0.2, 'action' => 'desiderata_offer'],
        'a token minted for another form' => ['success' => true, 'score' => 0.9, 'action' => 'contact_form'],
        'an outright failure from Google' => ['success' => false, 'error-codes' => ['invalid-input-response']],
    ];
    foreach ($rejections as $description => $json) {
        $probe = new DesiderataRecaptchaProbe((string) json_encode($json + ['challenge_ts' => gmdate('Y-m-d\TH:i:s\Z'), 'hostname' => 'localhost']));
        $plugin->setRecaptchaTransport($probe);
        $_SESSION = [];
        $before = $offerCount();
        ob_start();
        $response = $plugin->offer($request($payload + ['book_id' => (string) $bookId, 'recaptcha_token' => 'token-from-the-browser']), new Response());
        ob_end_clean();
        $check($response->getStatusCode() === 422, "the proposal is refused on $description");
        $check($offerCount() === $before, "nothing is written on $description");
        $check($probe->calls === 1, "the verifier was consulted on $description");
        $check(!isset($_SESSION['desiderata_last_offer']), "a refused proposal on $description does not consume the throttle");
    }

    if (getenv('DESIDERATA_RECAPTCHA_LIVE') === '1') {
        // Opt-in only, so the suite stays offline-safe: proves the shipped
        // transport really reaches Google's endpoint, which an injected one
        // never can.
        $live = (new \ReCaptcha\ReCaptcha('bogus-secret-for-a-probe'))->verify('bogus-token', '127.0.0.1');
        $check(in_array('invalid-input-secret', $live->getErrorCodes(), true), 'the real transport reaches the verifier (live probe)');
    } else {
        echo "     NOTE: the optional live probe is off (set DESIDERATA_RECAPTCHA_LIVE=1 to run it)\n";
    }

    echo "T19. Degradation, exactly like the contact form\n";

    // (a) No keys configured: the verifier must not be built, let alone called.
    $useSecret('');
    $tripwire = new DesiderataRecaptchaTripwire();
    $plugin->setRecaptchaTransport($tripwire);
    $_SESSION = [];
    $before = $offerCount();
    $response = $plugin->offer($request($payload + ['book_id' => (string) $bookId]), new Response());
    $check($response->getStatusCode() === 303, 'with no secret configured the proposal is accepted');
    $check($tripwire->calls === 0, 'and the verifier is never called');
    $check($offerCount() === $before + 1, 'the proposal is written as usual');
    $offerIds[] = (int) $scalar("SELECT MAX(id) FROM desiderata_offers WHERE title LIKE '" . $db->real_escape_string($prefix) . "%'");

    // (b)+(c) Keys configured, token missing or of the wrong type. Asserted on
    // the METHOD as well as the endpoint: the message is checked here, where it
    // cannot be confused with the markup of a view somebody may be restyling.
    $useSecret($secret);
    $verify = new ReflectionMethod($plugin, 'verifyRecaptcha');
    $verify->setAccessible(true);
    $expected = __('Verifica reCAPTCHA fallita. Riprova.');
    $unusableTokens = [
        'missing altogether' => [],
        'an empty string' => ['recaptcha_token' => ''],
        'an array instead of a string' => ['recaptcha_token' => ['forged']],
        'a number instead of a string' => ['recaptcha_token' => 12345],
    ];
    foreach ($unusableTokens as $description => $extra) {
        $tripwire = new DesiderataRecaptchaTripwire();
        $plugin->setRecaptchaTransport($tripwire);
        try {
            $verify->invoke($plugin, $payload + $extra);
            $check(false, "verifyRecaptcha() accepted a token that is $description");
        } catch (InvalidArgumentException $rejected) {
            $check($rejected->getMessage() === $expected, "a token that is $description is refused with the reCAPTCHA message");
        }
        $check($tripwire->calls === 0, "a token that is $description is refused before any network call");

        $_SESSION = [];
        $before = $offerCount();
        ob_start();
        $response = $plugin->offer($request($payload + ['book_id' => (string) $bookId] + $extra), new Response());
        ob_end_clean();
        $check($response->getStatusCode() === 422, "the endpoint refuses a token that is $description");
        $check($offerCount() === $before, "and writes nothing for a token that is $description");
        $check(!isset($_SESSION['desiderata_last_offer']), "and does not consume the throttle for a token that is $description");
    }

    // The control that makes the four refusals above mean something: the very
    // same payload, with the very same secret, is accepted once a usable token
    // is present. Without it a suite could be refusing everything for an
    // unrelated reason and still look perfect.
    $probe = new DesiderataRecaptchaProbe((string) json_encode([
        'success' => true,
        'score' => 0.9,
        'action' => 'desiderata_offer',
        'challenge_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        'hostname' => 'localhost',
    ]));
    $plugin->setRecaptchaTransport($probe);
    $_SESSION = [];
    $before = $offerCount();
    $response = $plugin->offer($request($payload + ['book_id' => (string) $bookId, 'recaptcha_token' => 'good-token']), new Response());
    $check($response->getStatusCode() === 303 && $offerCount() === $before + 1, 'the identical payload with a usable token is accepted');
    $offerIds[] = (int) $scalar("SELECT MAX(id) FROM desiderata_offers WHERE title LIKE '" . $db->real_escape_string($prefix) . "%'");

    $check($mailbox->sent !== [], 'the capturing mailer intercepted the operator notifications instead of PHP mail()');
} catch (\Throwable $thrown) {
    $fatalError = $thrown;
} finally {
    $plugin->setRecaptchaTransport(null);
    // Restore the installation's own contacts settings, byte for byte.
    foreach ($settingSnapshot as $key => $value) {
        if ($value === null) {
            $db->query("DELETE FROM system_settings WHERE category = 'contacts' AND setting_key = '" . $db->real_escape_string($key) . "'");
            continue;
        }
        $db->query(
            "UPDATE system_settings SET setting_value = '" . $db->real_escape_string($value) . "'"
            . " WHERE category = 'contacts' AND setting_key = '" . $db->real_escape_string($key) . "'"
        );
    }
    ConfigStore::clearCache();
    // Swept by PREFIX, not only by the ids the happy paths recorded. A run that
    // ends early — or one where the code under test accepts a submission this
    // suite expected it to refuse — writes proposals nobody tracked, and an
    // id-only cleanup leaves them in the operator's backlog. The prefix is
    // unique to this process, so the sweep can never reach anybody's data.
    $like = $db->real_escape_string($prefix) . '%';
    foreach ($db->query("SELECT id FROM desiderata_offers WHERE title LIKE '$like'")->fetch_all(MYSQLI_ASSOC) as $row) {
        $offerIds[] = (int) $row['id'];
    }
    $offerIds = array_values(array_unique(array_filter($offerIds, static fn(int $id): bool => $id > 0)));
    if ($offerIds !== []) {
        // Only the bell rows this run created: bounded by the id high-water
        // mark taken at the start AND by the proposals this suite owns.
        $db->query('DELETE FROM admin_notifications WHERE id > ' . $notificationMark . " AND type = 'general' AND related_id IN (" . implode(',', $offerIds) . ')');
        $db->query('DELETE FROM desiderata_offers WHERE id IN (' . implode(',', $offerIds) . ')');
    }
    $db->query("DELETE FROM desiderata_offers WHERE title LIKE '$like'");
    if ($bookIds !== []) {
        $db->query('DELETE FROM copie WHERE libro_id IN (' . implode(',', $bookIds) . ')');
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN (" . implode(',', $bookIds) . ')');
        $db->query('DELETE FROM libri WHERE id IN (' . implode(',', $bookIds) . ')');
    }
    App\Support\ContentCache::booksChanged();
    $db->close();
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
