<?php
declare(strict_types=1);

/**
 * 'login.validate' tells hooks where a login comes from.
 *
 * The filter runs for the web form, the mobile app login and every Basic-auth
 * gate (NCIP, OAI-PMH, ResourceSync, VIAF). A trailing $channel argument lets a hook keep a
 * browser-only check (a CAPTCHA) on the web form without locking out the
 * non-browser clients. It is appended, so callbacks written for the old three
 * arguments keep working.
 *
 * A. NCIP Basic auth is executed against the database: the hook receives
 *    'ncip', a legacy three-parameter callback still runs, and a hook that
 *    refuses the 'ncip' channel blocks the login.
 * B. Every call site passes its own channel: 'web', 'mobile', 'ncip',
 *    'oai-pmh', 'resourcesync', 'viaf'.
 *
 * DB: a disposable sandbox rebuilt from schema.sql (tests/helpers/
 *    hardening-sandbox-db.php; E2E_DB_* or .env), dropped at the end.
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
$sites = [
    'web' => 'app/Controllers/AuthController.php',
    'mobile' => 'storage/plugins/mobile-api/src/Controllers/AuthController.php',
    'ncip' => 'storage/plugins/ncip-server/NcipServerPlugin.php',
    'oai-pmh' => 'storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php',
    'resourcesync' => 'storage/plugins/resource-sync/ResourceSyncPlugin.php',
    'viaf' => 'storage/plugins/viaf-authority/ViafAuthorityPlugin.php',
];
foreach ($sites as $channel => $file) {
    $source = (string) file_get_contents($root . '/' . $file);
    $check(str_contains($source, "Hooks::apply('login.validate', true, [\$email, \$request, '{$channel}'])"), "{$file} passes '{$channel}'");
    $check(substr_count($source, "Hooks::apply('login.validate'") === 1, "{$channel}: a single login.validate call");
}

echo "A. NCIP Basic auth, executed\n";
// A disposable database rebuilt from schema.sql (never the configured one):
// the fixture user cannot leak into a developer's database or collide with
// a concurrent run.
require_once $root . '/tests/helpers/hardening-sandbox-db.php';
$sandbox = hardeningSandboxDb('lvchannel');
if ($sandbox === null) {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: no database configured (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP: no database configured\n";
} else {
    $db = $sandbox['db'];
    $email = 'login-validate-channel-' . bin2hex(random_bytes(3)) . '@example.invalid';
    $password = 'Channel-Test-1!';
    $id = hardeningSeedUser($db, $email, $password, 'standard');
    $ip = '203.0.113.' . random_int(1, 250);

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
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/ncip', ['REMOTE_ADDR' => $ip])
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
        \App\Support\RateLimiter::reset('ncip_basic:' . \App\Support\RateLimiter::clientKey($ip) . ':' . \App\Support\RateLimiter::accountKey($email));
        \App\Support\RateLimiter::reset('ncip_basic_fail:' . \App\Support\RateLimiter::clientKey($ip));
        ($sandbox['drop'])();
    }
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
