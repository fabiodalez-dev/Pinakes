<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, "needs decision" items now decided — web group (A).
 *
 *   A. [13] Private mode fails CLOSED: a settings read that failed (DB down,
 *      query error) makes PrivateModeMiddleware treat the site as private for
 *      that request, while an absent / '0' setting keeps it public and the
 *      login route stays reachable. ConfigStore::getStrict() surfaces the
 *      failure; ConfigStore::get() keeps falling back to defaults.
 *   B. [65] HSTS is opt-in (advanced.enable_hsts) and HTTPS-only, one year,
 *      no includeSubDomains/preload; the unconditional preload header is gone;
 *      the installer defaults FORCE_HTTPS to true only for an HTTPS install
 *      (forwarded headers count only from TRUSTED_PROXIES).
 *   C. [90] DB statement time limit: the SQL per server flavour, the env
 *      override, the exempt long-running paths, skipped in CLI, failures never
 *      throw, and (with a reachable MySQL/MariaDB) the session variable is
 *      really set on the web path and lifted by lift().
 *
 * Run: php tests/security-decisions-web.unit.php
 */

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';
require_once $ROOT . '/installer/classes/Installer.php';

use App\Middleware\PrivateModeMiddleware;
use App\Support\ConfigStore;
use App\Support\DbStatementTimeout;
use App\Support\Hsts;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// createEnvFile() binds the install to the PHP session: start one now, before
// any output, in a private temp directory.
$sessionDir = sys_get_temp_dir() . '/pk_sec_dec_web_sess_' . bin2hex(random_bytes(4));
mkdir($sessionDir, 0700, true);
session_save_path($sessionDir);
session_start();

$pass = 0;
$fail = 0;
$skip = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$guard = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        $check(false, $label . ' — threw ' . get_class($e) . ': ' . $e->getMessage());
    }
};

// ─────────────────────────────────────────────────────────────────────────────
echo "A. [13] Private mode fails closed\n";
$_SESSION = [];
$handler = new class implements RequestHandlerInterface {
    public int $calls = 0;
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return new Response(204);
    }
};
$factory = new ServerRequestFactory();
$run = static function (PrivateModeMiddleware $mw, string $path) use ($handler, $factory): array {
    $before = $handler->calls;
    $res = $mw->process($factory->createServerRequest('GET', $path), $handler);
    return [$res, $handler->calls === $before + 1];
};

$guard(function () use ($check, $run): void {
    $throwing = new PrivateModeMiddleware(static function (): mixed {
        throw new \RuntimeException('simulated settings read failure');
    });
    [$res, $passed] = $run($throwing, '/');
    $check(!$passed && $res->getStatusCode() === 302
        && str_contains($res->getHeaderLine('Location'), 'error=private_mode'),
        'read failure: anonymous home page is redirected to the login page');

    [$res, $passed] = $run($throwing, '/api/books/1/availability');
    $check(!$passed && $res->getStatusCode() === 401, 'read failure: anonymous internal API gets 401');

    [, $passed] = $run($throwing, '/login');
    $check($passed, 'read failure: the login route stays reachable');
    [, $passed] = $run($throwing, '/assets/app.css');
    $check($passed, 'read failure: static assets stay reachable');
    [, $passed] = $run($throwing, '/health');
    $check($passed, 'read failure: health check stays reachable');

    $_SESSION = ['user' => ['id' => 1]];
    [, $passed] = $run($throwing, '/');
    $check($passed, 'read failure: an authenticated user is never restricted');
    $_SESSION = [];

    [, $passed] = $run(new PrivateModeMiddleware(static fn (): mixed => '0'), '/');
    $check($passed, "setting '0': site stays public");
    [, $passed] = $run(new PrivateModeMiddleware(static fn (): mixed => null), '/');
    $check($passed, 'setting absent (null): site stays public');
    [, $passed] = $run(new PrivateModeMiddleware(static fn (): mixed => false), '/');
    $check($passed, 'setting false: site stays public');
    [$res, $passed] = $run(new PrivateModeMiddleware(static fn (): mixed => '1'), '/');
    $check(!$passed && $res->getStatusCode() === 302, "setting '1': site is private");
    [$res, $passed] = $run(new PrivateModeMiddleware(static fn (): mixed => true), '/');
    $check(!$passed && $res->getStatusCode() === 302, 'setting true (after ConfigStore::set): site is private');
}, 'injected reader scenarios');

// The real default path: ConfigStore with a connection that failed this request.
$guard(function () use ($check, $run): void {
    $failed = new ReflectionProperty(ConfigStore::class, 'connectionFailed');
    $shared = new ReflectionProperty(ConfigStore::class, 'sharedConnection');
    $prevFailed = $failed->getValue();
    $prevShared = $shared->getValue();
    try {
        $shared->setValue(null, null);
        $failed->setValue(null, true);
        ConfigStore::clearCache();

        $threw = false;
        try {
            ConfigStore::getStrict('advanced.private_mode', '0');
        } catch (\RuntimeException) {
            $threw = true;
        }
        $check($threw, 'ConfigStore::getStrict() throws when the settings read failed');
        $check(ConfigStore::get('advanced.private_mode', '0') === '0',
            'ConfigStore::get() keeps returning the default for every other caller');

        [$res, $passed] = $run(new PrivateModeMiddleware(), '/');
        $check(!$passed && $res->getStatusCode() === 302,
            'default middleware + failed ConfigStore read: anonymous visitor is sent to login');

        // Settings readable and private mode absent -> public (as before).
        $failed->setValue(null, false);
        ConfigStore::clearCache();
        $runtime = new ReflectionProperty(ConfigStore::class, 'runtimeCache');
        $runtime->setValue(null, ['advanced' => []]);
        [, $passed] = $run(new PrivateModeMiddleware(), '/');
        $check($passed, 'default middleware + readable settings without the flag: site stays public');
    } finally {
        $failed->setValue(null, $prevFailed);
        $shared->setValue(null, $prevShared);
        ConfigStore::clearCache();
    }
}, 'ConfigStore failure scenarios');

// ─────────────────────────────────────────────────────────────────────────────
echo "B. [65] HSTS opt-in, HTTPS only; installer FORCE_HTTPS default\n";
$guard(function () use ($check): void {
    $check(Hsts::headerValue('1', true) === 'max-age=31536000', "enabled '1' over HTTPS: max-age=31536000");
    $check(Hsts::headerValue(true, true) === 'max-age=31536000', 'enabled true over HTTPS: header sent');
    $check(Hsts::headerValue('1', false) === null, 'enabled but plain HTTP: no header');
    $check(Hsts::headerValue('0', true) === null, "setting '0' over HTTPS: no header (off by default)");
    $check(Hsts::headerValue(false, true) === null, 'setting false/absent over HTTPS: no header');
    $value = (string) Hsts::headerValue('1', true);
    $check(!str_contains(strtolower($value), 'includesubdomains') && !str_contains(strtolower($value), 'preload'),
        'header carries neither includeSubDomains nor preload');
}, 'Hsts::headerValue');

$guard(function () use ($check, $ROOT): void {
    $fwd = ['HTTP_X_FORWARDED_PROTO' => 'https'];
    $check(Hsts::untrustedForwardedHttps($fwd, false, ''), 'X-Forwarded-Proto: https from an untrusted peer is flagged');
    $check(Hsts::untrustedForwardedHttps(['HTTP_X_FORWARDED_SSL' => 'on'], false, ''), 'X-Forwarded-Ssl: on from an untrusted peer is flagged');
    $check(Hsts::untrustedForwardedHttps(['HTTP_X_FORWARDED_PROTO' => 'https, http'], false, 'http://lib.example'), 'first hop of a forwarded list counts; an http canonical URL does not help');
    $check(!Hsts::untrustedForwardedHttps($fwd, true, ''), 'a TRUSTED_PROXIES peer is not flagged');
    $check(!Hsts::untrustedForwardedHttps($fwd, false, 'https://lib.example'), 'an https APP_CANONICAL_URL is not flagged');
    $check(!Hsts::untrustedForwardedHttps($fwd + ['HTTPS' => 'on'], false, ''), 'direct HTTPS is not flagged');
    $check(!Hsts::untrustedForwardedHttps([], false, '') && !Hsts::untrustedForwardedHttps(['HTTP_X_FORWARDED_PROTO' => 'http'], false, ''),
        'plain HTTP without forwarded HTTPS is not flagged');

    // The advanced tab renders the notice only when the controller sets it.
    // The partial is rendered without a database, so it stops at the API-key
    // block (after the HSTS toggle): only the markup before that is checked.
    $render = static function (bool $notice, array $advancedSettings = []) use ($ROOT): string {
        $isAdmin = true;
        $hstsProxyNotice = $notice;
        $csrfToken = 't';
        set_error_handler(static fn (): bool => true);
        ob_start();
        try {
            include $ROOT . '/app/Views/settings/advanced-tab.php';
        } catch (\Throwable) {
            // expected: no database for the API-key block
        } finally {
            restore_error_handler();
        }
        return (string) ob_get_clean();
    };
    $with = $render(true);
    $without = $render(false);
    $check(str_contains($with, 'data-testid="hsts-proxy-notice"') && str_contains($with, 'TRUSTED_PROXIES')
        && str_contains($with, 'APP_CANONICAL_URL'), 'advanced tab shows the TRUSTED_PROXIES / APP_CANONICAL_URL notice');
    $check(str_contains($without, 'id="enable_hsts"') && !str_contains($without, 'hsts-proxy-notice'),
        'no notice when the request is not untrusted forwarded HTTPS');
    // HSTS used to be sent on every HTTPS response; it is opt-in now, so an
    // upgraded HTTPS site stops sending it. The page says so while it is off.
    $check(str_contains($without, 'data-testid="hsts-upgrade-note"') && str_contains($without, '0.9.5'),
        'with HSTS off the advanced tab explains that the upgrade stopped sending it');
    $check(!str_contains($render(false, ['enable_hsts' => '1']), 'hsts-upgrade-note'), 'with HSTS on the upgrade note is gone');
    $ctl = (string) file_get_contents($ROOT . '/app/Controllers/SettingsController.php');
    $check(str_contains($ctl, 'Hsts::untrustedForwardedHttps(') && str_contains($ctl, "'hstsProxyNotice',"),
        'SettingsController computes the notice and passes it to the view');
}, 'HSTS untrusted-proxy notice');

$guard(function () use ($check, $ROOT): void {
    $index = (string) file_get_contents($ROOT . '/public/index.php');
    $check(preg_match('/withHeader\(\s*[\'"]Strict-Transport-Security/', $index) !== 1
        && !str_contains($index, 'includeSubDomains; preload'),
        'public/index.php no longer adds an unconditional HSTS (preload) header');
    $check(preg_match('/Hsts::headerValue\(\s*\\\\App\\\\Support\\\\ConfigStore::get\(\'advanced\.enable_hsts\'.*?\$httpsForSharedHeaders\s*\)/s', $index) === 1,
        'public/index.php gates HSTS on advanced.enable_hsts and trusted-proxy HTTPS detection');
}, 'index.php wiring');

$guard(function () use ($check): void {
    $prevEnv = $_ENV['TRUSTED_PROXIES'] ?? null;
    $prevGetenv = getenv('TRUSTED_PROXIES');
    try {
        unset($_ENV['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
        $check(Installer::isInstallRequestHttps(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9']) === true,
            'installer over direct HTTPS: FORCE_HTTPS defaults to true');
        $check(Installer::isInstallRequestHttps(['HTTPS' => 'off', 'REMOTE_ADDR' => '203.0.113.9']) === false,
            'installer over plain HTTP: FORCE_HTTPS stays false');
        $check(Installer::isInstallRequestHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']) === false,
            'installer: X-Forwarded-Proto from an untrusted peer is ignored');
        // ... and step 5 says why "Force HTTPS" is not preselected there.
        $check(Installer::untrustedForwardedHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']) === true
            && Installer::untrustedForwardedHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_SSL' => 'on']) === true,
            'installer: untrusted forwarded HTTPS is reported for the step-5 note');
        $check(Installer::untrustedForwardedHttps(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']) === false
            && Installer::untrustedForwardedHttps(['REMOTE_ADDR' => '203.0.113.9']) === false,
            'installer: no note on direct HTTPS or plain HTTP');
        $step5 = (string) file_get_contents(dirname(__DIR__) . '/installer/steps/step5.php');
        $check(str_contains($step5, '<?php if (Installer::untrustedForwardedHttps()): ?>') && str_contains($step5, 'install-proxy-https-note'),
            'installer step 5 shows the note when forwarded HTTPS is not trusted');

        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
        $check(Installer::isInstallRequestHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https, http']) === true,
            'installer: X-Forwarded-Proto from a TRUSTED_PROXIES peer is honoured');
        $check(Installer::isInstallRequestHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_SSL' => 'on']) === true,
            'installer: X-Forwarded-SSL from a TRUSTED_PROXIES peer is honoured');
        $check(Installer::isInstallRequestHttps(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_PROTO' => 'https']) === false,
            'installer: forwarded HTTPS from a peer outside TRUSTED_PROXIES is ignored');
        $check(Installer::untrustedForwardedHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https']) === false,
            'installer: no note once the proxy is trusted');
    } finally {
        if ($prevEnv === null) { unset($_ENV['TRUSTED_PROXIES']); } else { $_ENV['TRUSTED_PROXIES'] = $prevEnv; }
        if ($prevGetenv === false) { putenv('TRUSTED_PROXIES'); } else { putenv('TRUSTED_PROXIES=' . $prevGetenv); }
    }

    // createEnvFile() writes what isInstallRequestHttps() says (behavioural, on a temp dir).
    $tmp = sys_get_temp_dir() . '/pk_sec_dec_web_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700, true);
    $prevServer = $_SERVER;
    try {
        $installer = new Installer($tmp);
        $method = new ReflectionMethod(Installer::class, 'createEnvFile');
        $writeEnv = static function () use ($installer, $method, $tmp): string {
            @unlink($tmp . '/.env');
            $args = [];
            foreach ($method->getParameters() as $p) {
                $args[] = match ($p->getName()) {
                    'host' => 'localhost', 'user' => 'u', 'password' => 'p', 'database' => 'd',
                    'port' => 3306, 'socket' => '', 'locale' => 'it_IT',
                    default => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : '',
                };
            }
            $method->invokeArgs($installer, $args);
            return is_file($tmp . '/.env') ? (string) file_get_contents($tmp . '/.env') : '';
        };
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'library.example';
        $env = $writeEnv();
        $check(preg_match('/^FORCE_HTTPS=true$/m', $env) === 1, 'createEnvFile over HTTPS writes FORCE_HTTPS=true');
        unset($_SERVER['HTTPS']);
        $env = $writeEnv();
        $check(preg_match('/^FORCE_HTTPS=false$/m', $env) === 1, 'createEnvFile over HTTP writes FORCE_HTTPS=false');
    } finally {
        $_SERVER = $prevServer;
        $rm = static function (string $dir) use (&$rm): void {
            foreach (scandir($dir) ?: [] as $e) {
                if ($e === '.' || $e === '..') { continue; }
                $p = $dir . '/' . $e;
                is_dir($p) && !is_link($p) ? $rm($p) : @unlink($p);
            }
            @rmdir($dir);
        };
        $rm($tmp);
    }
}, 'installer HTTPS detection');

// ─────────────────────────────────────────────────────────────────────────────
echo "C. [90] DB statement time limit\n";
$guard(function () use ($check): void {
    $my = '8.4.3';
    $maria = '11.4.2-MariaDB-log';
    $check(DbStatementTimeout::isMariaDb($maria) && !DbStatementTimeout::isMariaDb($my), 'server flavour detected from VERSION()');
    $check(DbStatementTimeout::statementFor($my, 30) === 'SET SESSION max_execution_time = 30000',
        'MySQL: max_execution_time in milliseconds');
    $check(DbStatementTimeout::statementFor($maria, 60) === 'SET SESSION max_statement_time = 60',
        'MariaDB: max_statement_time in seconds');
    $check(DbStatementTimeout::statementFor($my, 0) === null && DbStatementTimeout::statementFor($maria, 0) === null,
        '0 seconds disables the limit');
    $check(DbStatementTimeout::configuredSeconds($my, '') === 30, 'default MySQL limit is 30 s');
    $check(DbStatementTimeout::configuredSeconds($maria, '') === 60, 'default MariaDB limit is a more generous 60 s');
    $check(DbStatementTimeout::configuredSeconds($my, '12') === 12 && DbStatementTimeout::configuredSeconds($maria, '12') === 12,
        'DB_STATEMENT_TIMEOUT overrides both flavours');
    $check(DbStatementTimeout::configuredSeconds($my, '0') === 0, 'DB_STATEMENT_TIMEOUT=0 disables');
    $check(DbStatementTimeout::configuredSeconds($my, 'abc') === 30 && DbStatementTimeout::configuredSeconds($my, '-5') === 30,
        'an invalid DB_STATEMENT_TIMEOUT falls back to the default');

    foreach (['/admin/updates/perform', '/admin/maintenance/perform',
              '/installer/', '/admin/books/import/chunk', '/admin/users/import/upload', '/admin/books/export/csv',
              '/admin/archives/export.xml', '/api/autori/bulk-export', '/admin/updates/backup/restore',
              '/api/dewey-editor/restore/it_IT', '/admin/settings/advanced/regenerate-sitemap',
              '/api/v1/staff/imports/10000000-0000-4000-8000-000000000001/chunk',
              '/api/v1/staff/marc/imports/10000000-0000-4000-8000-000000000001/chunk',
              '/api/v1/staff/user-imports/10000000-0000-4000-8000-000000000001/chunk',
              '/api/v1/staff/exports/librarything.tsv', '/api/v1/staff/marc/export', '/api/v1/staff/attendance/export.csv',
              '/admin/books/import-sbn', '/admin/loans/export-csv',
              '/admin/inventory/7/report.csv', '/admin/books/12/export.unimarc.xml'] as $path) {
        $check(DbStatementTimeout::isExemptPath($path), "exempt long-running path: {$path}");
    }
    // Anonymous whole-catalogue endpoints: never exempt, a raised limit instead.
    foreach (['/oai', '/archives/oai', '/resync/resourcelist.xml', '/.well-known/resourcesync',
              '/api/sru', '/api/archives/sru', '/sitemap.xml'] as $path) {
        $check(!DbStatementTimeout::isExemptPath($path) && DbStatementTimeout::isRaisedPath($path),
            "anonymous harvesting path is raised, not exempt: {$path}");
    }
    $check(!DbStatementTimeout::isRaisedPath('/catalogo') && !DbStatementTimeout::isRaisedPath('/libro/1/oai'),
        'other paths do not get the raised limit');
    $check(DbStatementTimeout::harvestSeconds($my, '') === 300 && DbStatementTimeout::harvestSeconds($maria, '') === 300,
        'harvest limit defaults to 300 s on both flavours');
    $check(DbStatementTimeout::harvestSeconds($my, '12') === 120, 'harvest limit scales with DB_STATEMENT_TIMEOUT (x10)');
    $check(DbStatementTimeout::harvestSeconds($my, '0') === 0, 'DB_STATEMENT_TIMEOUT=0 disables the harvest limit too');
    $check(DbStatementTimeout::harvestSeconds($my, 'abc') === 300, 'an invalid DB_STATEMENT_TIMEOUT keeps the 300 s harvest default');
    $check(DbStatementTimeout::isExemptPath('/sub/admin/updates/backup', '/sub'),
        'exempt route under a base path');
    foreach (['/', '/catalogo', '/api/search/autori', '/api/libri', '/admin/books', '/libro/12/titolo',
              '/api/v1/staff/marc/imports/10000000-0000-4000-8000-000000000001', '/api/v1/staff/imports/invalid/chunk',
              '/admin/updatesx', '/admin/settings',
              // free-form segments that merely CONTAIN a keyword stay bounded
              '/libro/123/important-book', '/libro/5/the-exporter', '/autore/9/backup-singers',
              '/catalogo/restored-works', '/evento/sitemaps-2026', '/admin/books/import-anything',
              // an exempt route that is not anchored at the start of the path
              '/uploads/x/admin/books/export/csv', '/libro/1/oai'] as $path) {
        $check(!DbStatementTimeout::isExemptPath($path), "interactive path is bounded: {$path}");
    }
    $check(!DbStatementTimeout::isExemptPath('/admin/updates/backup', '/sub'),
        'a path outside the base path is bounded');
}, 'pure helpers');

$guard(function () use ($check): void {
    // A mysqli that never connects: records queries, optionally throws.
    $makeStub = static function (bool $throw): \mysqli {
        return new class ($throw) extends \mysqli {
            /** @var list<string> */
            public array $queries = [];
            public function __construct(private bool $throwOnQuery) {}
            #[\ReturnTypeWillChange]
            public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
            {
                $this->queries[] = $query;
                if ($this->throwOnQuery) {
                    throw new \mysqli_sql_exception('simulated failure');
                }
                return true;
            }
        };
    };
    $stub = $makeStub(false);
    DbStatementTimeout::applyForWebRequest($stub, '/catalogo', true);
    $check($stub->queries === [], 'CLI: no query is sent at all');
    DbStatementTimeout::applyForWebRequest($stub, '/admin/updates/perform', false);
    $check($stub->queries === [], 'web + exempt path: no query is sent');
    DbStatementTimeout::lift($stub);
    $check($stub->queries === [], 'lift() on a connection that never had the limit sends nothing');

    // Harvesting routes get the raised, non-zero limit; any other path the default.
    $harvest = $makeStub(false);
    DbStatementTimeout::applyForWebRequest($harvest, '/oai', false);
    $harvestMs = DbStatementTimeout::harvestSeconds('') * 1000;
    $check($harvestMs > 0 && in_array('SET SESSION max_execution_time = ' . $harvestMs, $harvest->queries, true),
        "web + /oai: raised limit of {$harvestMs} ms is set");
    $plain = $makeStub(false);
    DbStatementTimeout::applyForWebRequest($plain, '/libro/42/important-oai-export', false);
    $defaultMs = DbStatementTimeout::configuredSeconds('') * 1000;
    $check(in_array('SET SESSION max_execution_time = ' . $defaultMs, $plain->queries, true),
        "web + arbitrary path: default limit of {$defaultMs} ms is set");

    $broken = $makeStub(true);
    $threw = false;
    try {
        DbStatementTimeout::applyForWebRequest($broken, '/catalogo', false);
    } catch (\Throwable) {
        $threw = true;
    }
    $check(!$threw && $broken->queries !== [], 'a failure while setting the limit is logged and ignored');

    // container.php wires it on the web connection.
    $container = (string) file_get_contents(dirname(__DIR__) . '/config/container.php');
    $check(str_contains($container, 'DbStatementTimeout::applyForWebRequest($mysqli)'),
        'config/container.php applies the limit to the shared web connection');
    foreach (['app/Support/Updater.php', 'app/Support/BackupManager.php', 'app/Support/SchemaRepair.php',
              'app/Support/MaintenanceService.php'] as $file) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
        $check(str_contains($src, 'DbStatementTimeout::lift($db)'), "{$file} lifts the limit on its connection");
    }
    $pm = (string) file_get_contents(dirname(__DIR__) . '/app/Support/PluginManager.php');
    $check(substr_count($pm, 'DbStatementTimeout::lift($this->db)') === 3,
        'PluginManager lifts the limit before activation, ZIP install and bundled registration');

    // Plugin activation executes the lift before any plugin code runs.
    $applied = $makeStub(false);
    DbStatementTimeout::applyForWebRequest($applied, '/admin/plugins/9/activate', false);
    $applied->queries = [];
    $manager = new class ($applied) extends \App\Support\PluginManager {
        public function __construct(\mysqli $db)
        {
            parent::__construct($db, new \App\Support\HookManager($db));
        }
        public function getPlugin(int $pluginId): ?array
        {
            return ['id' => $pluginId, 'name' => 'ghost', 'is_active' => 0, 'path' => '__pk_missing_plugin__',
                    'main_file' => 'ghost.php', 'metadata' => []];
        }
    };
    $manager->activatePlugin(9);
    $check(in_array('SET SESSION max_execution_time = 0', $applied->queries, true),
        'activatePlugin() lifts the statement time limit');
}, 'apply/lift with stub connections');

// Live servers: MySQL from E2E_DB_* / the worktree .env, MariaDB on 3307 if present.
$liveTargets = [];
$envFile = Installer::readEnvFile(dirname(__DIR__) . '/.env') ?? [];
$cfg = static fn (string $k, string $d = '') => (string) (getenv('E2E_' . $k) ?: ($envFile[$k] ?? $d));
$liveTargets['MySQL (app DB)'] = [
    'host' => $cfg('DB_HOST', 'localhost'), 'user' => $cfg('DB_USER'), 'pass' => $cfg('DB_PASS'),
    'port' => (int) $cfg('DB_PORT', '3306'), 'socket' => $cfg('DB_SOCKET'), 'dbname' => $cfg('DB_NAME'),
];
if (is_file('/opt/homebrew/var/mariadb.sock') || @fsockopen('127.0.0.1', 3307, $en, $es, 0.3)) {
    $liveTargets['MariaDB (:3307)'] = ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'port' => 3307, 'socket' => '', 'dbname' => ''];
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
foreach ($liveTargets as $label => $t) {
    if ($t['user'] === '') { $skip++; echo "SKIP: {$label}: no credentials\n"; continue; }
    try {
        $host = ($t['host'] === 'localhost' && $t['socket'] === '') ? '127.0.0.1' : $t['host'];
        $db = new \mysqli($host, $t['user'], $t['pass'], '', $t['port'], $t['socket'] !== '' ? $t['socket'] : null);
    } catch (\Throwable $e) {
        $skip++;
        echo "SKIP: {$label}: not reachable\n";
        continue;
    }
    $version = (string) ($db->query('SELECT VERSION()')->fetch_row()[0] ?? '');
    $isMaria = DbStatementTimeout::isMariaDb($version);
    $var = $isMaria ? 'max_statement_time' : 'max_execution_time';
    $read = static fn (): float => (float) ($db->query('SELECT @@SESSION.' . $var)->fetch_row()[0] ?? -1);
    $before = $read();
    DbStatementTimeout::applyForWebRequest($db, '/catalogo', true);
    $check($read() === $before, "{$label}: CLI leaves @@{$var} untouched");
    DbStatementTimeout::applyForWebRequest($db, '/admin/books/import/chunk', false);
    $check($read() === $before, "{$label}: exempt path leaves @@{$var} untouched");
    DbStatementTimeout::applyForWebRequest($db, '/catalogo', false);
    $expected = $isMaria ? (float) DbStatementTimeout::configuredSeconds($version) : (float) (DbStatementTimeout::configuredSeconds($version) * 1000);
    $check($read() === $expected, "{$label}: web path sets @@{$var} = {$expected}");
    DbStatementTimeout::lift($db);
    $check($read() === 0.0, "{$label}: lift() resets @@{$var} to 0");

    // SchemaRepair (first request after an upgrade) lifts it before its DDL.
    // A root without schema.sql makes repair() throw right after the lift,
    // so nothing is written to the database.
    if ($t['dbname'] !== '') {
        $db->select_db($t['dbname']);
        $hasUsers = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti'")->num_rows > 0;
        if (!$hasUsers) {
            $skip++;
            echo "SKIP: {$label}: SchemaRepair lift needs an installed database\n";
        } else {
            $root = sys_get_temp_dir() . '/pk_dbst_repair_' . bin2hex(random_bytes(4));
            mkdir($root . '/storage', 0700, true);
            file_put_contents($root . '/version.json', json_encode(['version' => '9.9.9-test']));
            DbStatementTimeout::applyForWebRequest($db, '/catalogo', false);
            $check($read() > 0.0, "{$label}: limit set again before SchemaRepair");
            try {
                \App\Support\SchemaRepair::runOncePerVersion($db, $root);
            } catch (\RuntimeException) {
                // expected: no schema.sql in the temp root
            }
            $check($read() === 0.0, "{$label}: SchemaRepair::runOncePerVersion() lifts @@{$var}");
            @unlink($root . '/version.json');
            @rmdir($root . '/storage');
            @rmdir($root);
        }
    }
    $db->close();
}

session_destroy();
array_map('unlink', glob($sessionDir . '/*') ?: []);
@rmdir($sessionDir);
echo "\nPassed: {$pass}   Failed: {$fail}   Skipped: {$skip}\n";
exit($fail > 0 ? 1 : 0);
