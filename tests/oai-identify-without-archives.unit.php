<?php
declare(strict_types=1);

/**
 * OAI-PMH Identify on an installation without the Archives plugin.
 *
 * ConfigStore enables MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so any query
 * on a missing table throws. Identify used to run
 * `SELECT MIN(created_at) FROM archival_units` unconditionally and answered
 * 500 wherever archival_units had never been created. The verb must probe the
 * table first (hasArchivalUnitsTable()) and never touch it when it is absent.
 *
 * The real database is used, seen through a mysqli subclass that reports
 * archival_units as absent and throws (like a missing table under STRICT
 * mode) on any statement that reads it, recording every such attempt.
 *
 * Run: php tests/oai-identify-without-archives.unit.php   (exit 0 iff all pass)
 */

use App\Plugins\OaiPmhServer\OaiPmhServerPlugin;
use App\Support\HookManager;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/** mysqli that behaves as if archival_units did not exist. */
final class NoArchivalUnitsDb extends mysqli
{
    /** @var list<string> */
    public array $archivalReads = [];

    private function intercept(string $sql): ?string
    {
        if (stripos($sql, 'archival_units') === false) {
            return null;
        }
        if (stripos($sql, 'INFORMATION_SCHEMA') !== false) {
            // The existence probe: answer "no such table".
            return 'SELECT 0 AS c';
        }
        $this->archivalReads[] = $sql;
        throw new mysqli_sql_exception("Table 'archival_units' doesn't exist", 1146);
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        return parent::query($this->intercept($query) ?? $query, $result_mode);
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        return parent::prepare($this->intercept($query) ?? $query);
    }
}

$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new NoArchivalUnitsDb(null, $user, $dbPass, $name, 0, $socket)
        : new NoArchivalUnitsDb($env['DB_HOST'] ?? '127.0.0.1', $user, $dbPass, $name, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL: database not reachable (' . $e->getMessage() . ") — this suite requires the real DB\n");
    exit(1);
}

$plugin = new OaiPmhServerPlugin($db, new HookManager($db));

// 1. The probe itself reports the table as absent.
$probe = new ReflectionMethod(OaiPmhServerPlugin::class, 'hasArchivalUnitsTable');
$check($probe->invoke($plugin) === false, '01 hasArchivalUnitsTable() is false when the table is missing');

// 2. Identify through the real dispatcher: a well-formed answer, no exception.
$request = (new ServerRequestFactory())
    ->createServerRequest('GET', '/oai')
    ->withQueryParams(['verb' => 'Identify']);
$body = '';
$threw = null;
try {
    $response = $plugin->oaiPmhAction($request, new SlimResponse());
    $stream = $response->getBody();
    $stream->rewind();
    $body = $stream->getContents();
} catch (\Throwable $e) {
    $threw = $e;
}
$check($threw === null, '02 Identify does not throw without archival_units' . ($threw ? ' (' . $threw->getMessage() . ')' : ''));
$check(
    (bool) preg_match('/<earliestDatestamp>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z<\/earliestDatestamp>/', $body)
        && !str_contains($body, '<error'),
    '03 Identify answers with an earliestDatestamp'
);

// 3. And it never read the missing table.
$check($db->archivalReads === [], '04 no statement on archival_units was attempted (' . count($db->archivalReads) . ')');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
