<?php
declare(strict_types=1);

/**
 * A donor whose browser was never given a session must not be told their
 * session expired.
 *
 * The donation form also lives on `/` and on a book page, and SessionPolicy
 * serves both WITHOUT a session — deliberately, because those pages are
 * edge-cacheable and a per-visitor CSRF token baked into a shared cache would be
 * handed to every visitor. So the form there renders an empty token and the
 * browser mints one before submitting. With scripting off nothing mints it: the
 * POST arrives with no token and no prior session, and the core CSRF guard
 * answers a bare "Sessione Scaduta" — to someone who did nothing wrong, and
 * whose typed text is gone.
 *
 * confirmFirstContact() runs before that guard and ONLY in the one case that
 * could never have been a valid protected request: no token submitted AND no
 * token in the session. It writes nothing; it starts the session, hands the
 * visitor their own form back with everything they typed and a real token, and
 * asks for one more send.
 *
 * Half of this file is therefore about what must STILL be refused. A guard that
 * is relaxed in the wrong direction is worse than the error it replaced, so the
 * two hostile shapes — a wrong token, and an empty token where a session already
 * exists — are asserted to stay 403, and the database is checked to be untouched
 * after all of them.
 *
 * Run:  php tests/desiderata-first-contact.integration.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$base = getenv('E2E_BASE_URL') ?: 'http://localhost:8081';
$MARK = 'ZZFIRSTCONTACT-' . bin2hex(random_bytes(4));
$email = strtolower($MARK) . '@example.invalid';

/**
 * A request made the way a browser makes it, with its own cookie jar so each
 * scenario starts from the session state it is actually about.
 *
 * @param array<string,string> $fields
 * @return array{status:int, body:string, location:?string}
 */
$post = static function (string $url, array $fields, ?string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) === 1 ? trim($m[1]) : null;

    return ['status' => $status, 'body' => substr($raw, $headerSize), 'location' => $location];
};

$tokenIn = static function (string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
};

$payload = [
    'donor_name' => 'Donatore ' . $MARK,
    'donor_email' => $email,
    'title' => 'Titolo ' . $MARK,
    'notes' => 'Copia in ottimo stato',
    'consent' => '1',
];

$offersUrl = rtrim($base, '/') . '/desiderata/offers';
$jarFirst = tempnam(sys_get_temp_dir(), 'dwfc');
$jarHostile = tempnam(sys_get_temp_dir(), 'dwfc');
$db = null;

$fatalError = null;

try {
    // Reachability first: a suite that silently measured a dead server would
    // report every refusal below as a pass.
    $probe = @file_get_contents(rtrim($base, '/') . '/desiderata');
    if ($probe === false) {
        throw new RuntimeException("the desiderata page is unreachable at {$base}; start Apache and activate the plugin");
    }

    echo "A. The visitor with no session and no script\n";

    $first = $post($offersUrl, $payload + ['csrf_token' => ''], $jarFirst);
    $check($first['status'] === 200, "the first send is answered 200, not a 403 error page (got {$first['status']})");
    // Asserted on the <title>, not on the phrase anywhere in the body: every
    // page ships a translation dictionary that contains "Sessione Scaduta" as a
    // KEY, so a plain str_contains matches a perfectly healthy page. The error
    // pages are the two that announce themselves in the title.
    $title = preg_match('/<title>([^<]*)/', $first['body'], $m) === 1 ? trim($m[1]) : '';
    $check(!str_contains($title, 'Sessione Scaduta') && !str_contains($title, 'Errore di Sicurezza'),
        "and it is NOT one of the CSRF error pages (title: {$title})");
    $check(str_contains($first['body'], 'data-desiderata-form'), 'the donation form comes back');
    foreach (['donor_name' => 'their name', 'title' => 'the title they typed', 'notes' => 'their notes'] as $field => $what) {
        $check(str_contains($first['body'], $payload[$field]), "with {$what} still in it");
    }
    $check(str_contains($first['body'], 'name="consent" value="1" required checked'), 'and the consent they had ticked');

    $token = $tokenIn($first['body']);
    $check($token !== '', 'this time the form carries a real token');

    echo "\nB. And the second send goes through\n";

    $second = $post($offersUrl, $payload + ['csrf_token' => $token], $jarFirst);
    $check($second['status'] === 303, "the confirmation is accepted (got {$second['status']})");
    $check(is_string($second['location']) && str_contains($second['location'], 'inviata=1'),
        'and redirects to the thank-you, like any other successful proposal');

    echo "\nC. What must still be refused\n";

    // A wrong token on a session that exists: an ordinary CSRF failure, and the
    // interception above must not have softened it.
    $wrong = $post($offersUrl, $payload + ['csrf_token' => 'deadbeef-0000-0000'], $jarFirst);
    $check($wrong['status'] === 403, "a wrong token is still refused (got {$wrong['status']})");

    // An EMPTY token where a session already holds one. This is the case the
    // interception must not swallow: it looks like first contact but is not.
    $emptyWithSession = $post($offersUrl, $payload + ['csrf_token' => ''], $jarFirst);
    $check($emptyWithSession['status'] === 403,
        "an empty token is still refused once a session exists (got {$emptyWithSession['status']})");

    // First contact twice in a row from a fresh jar: still only ever re-presents.
    $hostile = $post($offersUrl, ['donor_name' => 'x', 'donor_email' => 'x@y.invalid', 'title' => 'x', 'consent' => '1', 'csrf_token' => ''], $jarHostile);
    $check($hostile['status'] === 200 && str_contains($hostile['body'], 'data-desiderata-form'),
        'a sessionless post is only ever answered with the form again');

    echo "\nD. Nothing was written except the one confirmed proposal\n";

    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
        if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');

    $stmt = $db->prepare('SELECT COUNT(*) c FROM desiderata_offers WHERE donor_email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    $check($count === 1, "exactly one proposal was recorded, from the confirmed send (found {$count})");

    $hostileCount = (int) $db->query("SELECT COUNT(*) c FROM desiderata_offers WHERE donor_email = 'x@y.invalid'")->fetch_assoc()['c'];
    $check($hostileCount === 0, "and the refused and re-presented sends wrote nothing (found {$hostileCount})");
} catch (\Throwable $error) {
    // Recorded, not acted on. exit() here would end the process before the
    // finally block below runs — PHP does not unwind finally on exit — and
    // leave the confirmed proposal in the installation's desiderata_offers
    // and the cookie jars on disk. The failure is reported after cleanup.
    $fatalError = $error;
} finally {
    if ($db instanceof mysqli) {
        $stmt = $db->prepare('DELETE FROM desiderata_offers WHERE donor_email = ? OR donor_email = ?');
        $hostileEmail = 'x@y.invalid';
        $stmt->bind_param('ss', $email, $hostileEmail);
        $stmt->execute();
        $stmt->close();
        $db->close();
    }
    @unlink($jarFirst);
    @unlink($jarHostile);
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
