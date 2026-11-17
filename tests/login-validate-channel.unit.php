<?php
declare(strict_types=1);

/**
 * 'login.validate' tells hooks where a login comes from.
 *
 * The filter runs for the web form, the mobile app login and NCIP Basic auth.
 * A trailing $channel argument ('web' / 'mobile' / 'ncip') lets a hook keep a
 * browser-only check (a CAPTCHA) on the web form without locking out the
 * non-browser clients. It is appended, so callbacks written for the old three
 * arguments keep working.
 *
 * A. NCIP Basic auth is executed against the database: the hook receives
 *    'ncip', a legacy three-parameter callback still runs, and a hook that
 *    refuses the 'ncip' channel blocks the login.
 * B. The web and mobile call sites pass 'web' and 'mobile'.
 *
 * DB: E2E_DB_* or .env (fixtures on a reserved id, removed at the end).
 * Run: php tests/login-validate-channel.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';

use App\Support\HookManager;
use App\Support\Hooks;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

echo "B. call sites\n";
$web = (string) file_get_contents($root . '/app/Controllers/AuthController.php');
$mobile = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/AuthController.php');
$ncip = (string) file_get_contents($root . '/storage/plugins/ncip-server/NcipServerPlugin.php');
$check(str_contains($web, "Hooks::apply('login.validate', true, [\$email, \$request, 'web'])"), "web login passes 'web'");
$check(str_contains($mobile, "Hooks::apply('login.validate', true, [\$email, \$request, 'mobile'])"), "mobile login passes 'mobile'");
$check(str_contains($ncip, "Hooks::apply('login.validate', true, [\$email, \$request, 'ncip'])"), "NCIP Basic auth passes 'ncip'");
foreach (['web' => $web, 'mobile' => $mobile, 'ncip' => $ncip] as $label => $source) {
    $check(substr_count($source, "Hooks::apply('login.validate'") === 1, "{$label}: a single login.validate call");
}

echo "A. NCIP Basic auth, executed\n";
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (str_contains($line, '=') && !str_starts_with(trim($line), '#')) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
if ($name === '' || $user === '') {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: no database configured (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "  SKIP no database configured\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli('localhost', $user, $dbPass, $name, 0, $socket)
        : new mysqli($host, $user, $dbPass, $name, $port);
    $db->set_charset('utf8mb4');
    $id = 900771;
    $email = 'login-validate-channel-' . bin2hex(random_bytes(3)) . '@example.invalid';
    $password = 'Channel-Test-1!';
    $db->query("DELETE FROM utenti WHERE id = {$id}");
    $stmt = $db->prepare("INSERT INTO utenti (id, codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
        VALUES (?, ?, 'Channel', 'Probe', ?, ?, 'standard', 'attivo', 1)");
    $tessera = 'LVCH' . $id;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt->bind_param('isss', $id, $tessera, $email, $hash);
    $stmt->execute();
    $stmt->close();

    try {
        $hooks = new HookManager($db);
        Hooks::init($hooks);
        $seen = [];
        $legacyRan = false;
        $refuseNcip = false;
        $hooks->addHook('login.validate', static function ($isValid, $mail, $request, string $channel = '?') use (&$seen, &$refuseNcip) {
            $seen[] = $channel;
            return $refuseNcip && $channel === 'ncip' ? false : $isValid;
        });
        // A callback written for the documented three arguments.
        $hooks->addHook('login.validate', static function ($isValid, $mail, $request) use (&$legacyRan) {
            $legacyRan = true;
            return $isValid;
        });

        $plugin = new \App\Plugins\NcipServer\NcipServerPlugin($db, $hooks);
        $auth = new ReflectionMethod($plugin, 'authenticate');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/ncip', ['REMOTE_ADDR' => '203.0.113.' . random_int(1, 250)])
            ->withHeader('Authorization', 'Basic ' . base64_encode($email . ':' . $password));

        $result = $auth->invoke($plugin, $request);
        $check(is_array($result) && (int) $result['id'] === $id, 'valid NCIP credentials authenticate');
        $check($seen === ['ncip'], "the hook receives channel 'ncip'");
        $check($legacyRan, 'a three-parameter callback still runs (the channel is a trailing extra argument)');

        $refuseNcip = true;
        $check($auth->invoke($plugin, $request) === null, "a hook refusing the 'ncip' channel blocks the NCIP login");
    } catch (\Throwable $e) {
        $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    } finally {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
        $db->close();
    }
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
