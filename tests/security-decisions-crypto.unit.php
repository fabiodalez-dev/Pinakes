<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, "needs decision" items — API keys, public API
 * payload, settings encryption (group B).
 *
 *   A. SettingsEncryption binds the setting (category + key) as GCM AAD: a new
 *      ciphertext decrypts only under its own setting; the legacy format (no
 *      AAD) still decrypts; every caller passes its category/key        (64)
 *   B. Public API keys are stored as SHA-256 + 8-char display prefix and
 *      looked up by hash; the admin page never shows a stored key again; the
 *      middleware authenticates by hash                           (14, 60)
 *   C. The public API payload carries neither note_varie nor any user id (15)
 *
 * B (DB part) and C need a database (E2E_DB_* or the worktree .env); they are
 * skipped otherwise. Sandbox: <DB_NAME>_secdec_b, dropped at the end.
 * The migration of existing keys is covered by tests/migration-0.9.6.unit.php.
 *
 * Run: php tests/security-decisions-crypto.unit.php
 */

use App\Support\SettingsEncryption;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$src = static fn (string $rel): string => (string) file_get_contents($ROOT . '/' . $rel);

// ---------------------------------------------------------------------------
echo "A. SettingsEncryption: setting-bound ciphertexts (64)\n";
// ---------------------------------------------------------------------------
$prevKey = getenv('PLUGIN_ENCRYPTION_KEY');
putenv('PLUGIN_ENCRYPTION_KEY=secdec-crypto-test-key');
$_ENV['PLUGIN_ENCRYPTION_KEY'] = 'secdec-crypto-test-key';
SettingsEncryption::resetKeyCache();
try {
    $secret = 'smtp-p@ss "w0rd"; ' . bin2hex(random_bytes(4));
    $enc = SettingsEncryption::encrypt($secret, 'email', 'smtp_password');
    $check(str_starts_with($enc, 'ENC:v2:'), 'new ciphertexts use the versioned v2 format');
    $check(str_starts_with($enc, SettingsEncryption::PREFIX), 'the v2 format still starts with ENC: (callers detecting ciphertext keep working)');
    $check(SettingsEncryption::decrypt($enc, 'email', 'smtp_password') === $secret, 'v2 round-trips under its own setting');
    $check(SettingsEncryption::decrypt($enc, 'email', 'smtp_username') === null, 'v2 copied into another key of the same category does not decrypt');
    $check(SettingsEncryption::decrypt($enc, 'mobile_api', 'smtp_password') === null, 'v2 copied into another category does not decrypt');
    $check(SettingsEncryption::decrypt($enc, 'email.smtp', 'password') === null, 'category/key boundaries are unambiguous');
    $check($enc !== SettingsEncryption::encrypt($secret, 'email', 'smtp_password'), 'a fresh IV per encryption');

    // Legacy format, exactly as written before 0.9.6 (no AAD).
    $k = hash('sha256', 'secdec-crypto-test-key', true);
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($secret, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
    $legacy = 'ENC:' . base64_encode($iv . $tag . $ct);
    $check(SettingsEncryption::decrypt($legacy, 'email', 'smtp_password') === $secret, 'a stored legacy ciphertext still decrypts');

    // A v2 value relabelled as legacy (prefix stripped back) must not decrypt either.
    $downgraded = 'ENC:' . substr($enc, strlen('ENC:v2:'));
    $check(SettingsEncryption::decrypt($downgraded, 'email', 'smtp_password') === null, 'a v2 ciphertext downgraded to the legacy prefix does not decrypt');

    $raw = (string) base64_decode(substr($enc, strlen('ENC:v2:')), true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    $check(SettingsEncryption::decrypt('ENC:v2:' . base64_encode($raw), 'email', 'smtp_password') === null, 'a tampered v2 ciphertext does not decrypt');
    $check(SettingsEncryption::decrypt('plain-legacy-value', 'email', 'smtp_password') === 'plain-legacy-value', 'non-ciphertext (legacy plaintext) passes through');
    $check(SettingsEncryption::decrypt(null, 'email', 'smtp_password') === null && SettingsEncryption::encrypt('', 'email', 'smtp_password') === '', 'null/empty unchanged');

    // Every caller names the setting it reads or writes (a missing argument is a fatal ArgumentCountError).
    $callers = [
        'app/Support/ConfigStore.php', 'app/Support/EmailService.php', 'app/Controllers/SettingsController.php',
        'storage/plugins/mobile-api/MobileApiPlugin.php', 'storage/plugins/mobile-api/src/Support/CalendarTokenService.php',
    ];
    $bare = [];
    foreach ($callers as $rel) {
        if (preg_match_all('/SettingsEncryption::(?:en|de)crypt\(([^;]*)\)/', $src($rel), $mm)) {
            foreach ($mm[1] as $args) {
                if (substr_count($args, ',') < 2) { $bare[] = $rel; }
            }
        }
    }
    $check($bare === [], 'every SettingsEncryption caller passes category and key' . ($bare ? ' (missing: ' . implode(', ', array_unique($bare)) . ')' : ''));
    $method = new ReflectionMethod(SettingsEncryption::class, 'decrypt');
    $check($method->getNumberOfRequiredParameters() === 3, 'decrypt() requires the category and key');

    // The installer writes the SMTP password with its own inlined cipher: it
    // must produce the v2 format bound to email.smtp_password.
    $step6 = $src('installer/steps/step6.php');
    $check(str_contains($step6, "'ENC:v2:'") && str_contains($step6, '"pinakes-settings-v2\\0email\\0smtp_password"'), 'installer step 6 writes the v2, setting-bound format');
    $aad = "pinakes-settings-v2\0email\0smtp_password";
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($secret, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, $aad);
    $check(SettingsEncryption::decrypt('ENC:v2:' . base64_encode($iv . $tag . $ct), 'email', 'smtp_password') === $secret, 'the installer-format value decrypts in the app');
} finally {
    if ($prevKey === false) { putenv('PLUGIN_ENCRYPTION_KEY'); unset($_ENV['PLUGIN_ENCRYPTION_KEY']); }
    else { putenv('PLUGIN_ENCRYPTION_KEY=' . $prevKey); $_ENV['PLUGIN_ENCRYPTION_KEY'] = $prevKey; }
    SettingsEncryption::resetKeyCache();
}

// ---------------------------------------------------------------------------
echo "B. API keys: hash + prefix, shown once (14, 60) — static part\n";
// ---------------------------------------------------------------------------
$k1 = bin2hex(random_bytes(32));
$check(\App\Models\ApiKeyRepository::hashKey($k1) === hash('sha256', $k1), 'hashKey() is the SHA-256 hex of the key');
$check(\App\Models\ApiKeyRepository::hashKey(strtoupper($k1)) === hash('sha256', $k1), 'hashKey() keeps the old case-insensitive match');
$view = $src('app/Views/settings/advanced-tab.php');
$check(!str_contains($view, "\$key['api_key']"), 'the settings page never renders a stored key');
$check(str_contains($view, "\$key['key_prefix']") && str_contains($view, '$newApiKey'), 'it renders the prefix, and the new key only from the one-time slot');

// ---------------------------------------------------------------------------
// DB-backed part (B and C)
// ---------------------------------------------------------------------------
$dotenv = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($ROOT . '/.env')) as $line) {
    if (str_contains($line, '=') && !str_starts_with(trim($line), '#')) {
        [$kk, $vv] = explode('=', $line, 2);
        $dotenv[trim($kk)] = trim(trim($vv), "\"'");
    }
}
$cfg = static fn (string $e2e, string $plain): string => (string) (getenv($e2e) ?: ($dotenv[$plain] ?? ''));
$dbUser = $cfg('E2E_DB_USER', 'DB_USER');
$dbPass = $cfg('E2E_DB_PASS', 'DB_PASS');
$dbHost = $cfg('E2E_DB_HOST', 'DB_HOST');
$dbPort = (int) ($cfg('E2E_DB_PORT', 'DB_PORT') ?: 3306);
$socket = $cfg('E2E_DB_SOCKET', 'DB_SOCKET');
$baseDb = preg_replace('/[^A-Za-z0-9_]/', '', $cfg('E2E_DB_NAME', 'DB_NAME'));
$db = null;
if ($dbUser !== '' && $baseDb !== '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $db = $socket !== '' && file_exists($socket)
            ? @new mysqli('localhost', $dbUser, $dbPass, '', 0, $socket)
            : @new mysqli($dbHost !== '' ? $dbHost : '127.0.0.1', $dbUser, $dbPass, '', $dbPort);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $e) {
        $db = null;
    }
}
$sandbox = $baseDb . '_secdec_b';
if (!$db instanceof mysqli || $db->query("CREATE DATABASE IF NOT EXISTS `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") === false) {
    echo "  SKIP B/C database part: no reachable database (E2E_DB_* or .env)\n";
} else {
    try {
        $db->set_charset('utf8mb4');
        $db->select_db($sandbox);
        // Structure only, from the configured database (no rows, no FKs, no triggers).
        foreach (['libri', 'editori', 'generi', 'autori', 'libri_autori', 'prestiti', 'recensioni', 'utenti', 'prenotazioni', 'system_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS `{$t}`");
            if ($db->query("CREATE TABLE `{$t}` LIKE `{$baseDb}`.`{$t}`") === false) {
                throw new RuntimeException("cannot clone {$t}: " . $db->error);
            }
        }
        $db->query("SET SESSION sql_mode = ''");

        // -------------------------------------------------------------------
        echo "B. API keys — database part\n";
        // -------------------------------------------------------------------
        $repo = new \App\Models\ApiKeyRepository($db);
        $repo->ensureTable();
        $created = $repo->create('Secdec integration', 'test');
        $plain = $created['api_key'];
        $row = $db->query('SELECT * FROM api_keys WHERE id = ' . (int) $created['id'])->fetch_assoc();
        $check(array_key_exists('api_key', $row) && $row['api_key'] === null && $row['key_hash'] === hash('sha256', $plain), 'create() stores the hash, not the key');
        $check(!str_contains((string) json_encode($row), $plain), 'no column of the stored row holds the key');
        $check($row['key_prefix'] === substr($plain, 0, 8), 'create() stores the 8-character display prefix');
        $check(!str_contains((string) json_encode($repo->getAll()), $plain), 'getAll() (the admin listing) never carries the key');
        $check(is_array($repo->getByKey($plain)) && $repo->getByKey($row['key_hash']) === null, 'lookup by the key works, by the stored hash does not');

        $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('api', 'enabled', '1')");
        $handler = new class implements RequestHandlerInterface {
            public ?array $keyData = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->keyData = $request->getAttribute('api_key_data');
                return new \Slim\Psr7\Response(200);
            }
        };
        $mw = new \App\Middleware\ApiKeyMiddleware($db);
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/public/books/search');
        $resp = $mw->process($req->withHeader('X-API-Key', $plain), $handler);
        $check($resp->getStatusCode() === 200 && ($handler->keyData['name'] ?? '') === 'Secdec integration', 'the middleware authenticates the key by its hash');
        $check(!array_key_exists('api_key', (array) $handler->keyData) && !array_key_exists('key_hash', (array) $handler->keyData), 'the request attribute carries neither the key nor its hash');
        $used = $db->query('SELECT last_used_at FROM api_keys WHERE id = ' . (int) $created['id'])->fetch_row()[0];
        $check($used !== null, 'last_used_at is updated for the matched key');
        $resp = $mw->process($req->withHeader('Authorization', 'Bearer ' . $row['key_hash']), $handler);
        $check($resp->getStatusCode() === 401, 'presenting the stored hash is refused (401)');

        // -------------------------------------------------------------------
        echo "C. Public API payload (15)\n";
        // -------------------------------------------------------------------
        $db->query("INSERT INTO utenti (id, nome, cognome, email, tipo_utente) VALUES (987654, 'Rev', 'Iewer', 'rev-secdec@example.invalid', 'standard')");
        $db->query("INSERT INTO libri (id, titolo, ean, note_varie, copie_totali, copie_disponibili) VALUES (424242, 'Secdec book', '9790000424242', 'INTERNAL-NOTE-secdec', 1, 1)");
        $db->query("INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato, approved_by, created_at, updated_at) VALUES (424242, 987654, 5, 't', 'd', 'approvata', 987654, NOW(), NOW())");
        $ctl = new \App\Controllers\PublicApiController();
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/public/books/search')
            ->withQueryParams(['ean' => '9790000424242'])
            ->withAttribute('api_key_data', ['name' => 'Secdec integration']);
        $resp = $ctl->searchBooks($req, new \Slim\Psr7\Response(), $db);
        $body = (string) $resp->getBody();
        $json = json_decode($body, true);
        $check($resp->getStatusCode() === 200 && ($json['count'] ?? 0) === 1, 'the book is returned');
        $book = $json['results'][0] ?? [];
        $check(!array_key_exists('note_varie', $book) && !str_contains($body, 'INTERNAL-NOTE-secdec'), 'note_varie is not in the payload');
        $check(count($book['recensioni'] ?? []) === 1 && ($book['recensioni'][0]['utente']['nome'] ?? '') === 'Rev', 'the approved review is returned with the reviewer display name');
        $check(!str_contains($body, '987654') && !preg_match('/"(utente_id|user_id|approved_by)"/', $body), 'no reviewer / user account id anywhere in the payload');
    } catch (\Throwable $e) {
        $check(false, 'DB-backed checks raised ' . get_class($e) . ': ' . $e->getMessage());
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
        $db->close();
    }
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
