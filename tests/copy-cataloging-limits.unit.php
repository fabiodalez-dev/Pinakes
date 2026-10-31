<?php
declare(strict_types=1);

/**
 * Copy cataloguing edge cases raised in review of #505:
 *  - when the result cap is reached, the remaining servers are listed in the
 *    report as not queried instead of disappearing from it;
 *  - an URL with the scheme's default port written out (https://host:443)
 *    is the same endpoint as the one without it.
 *
 * Offline: every server answers through a fake client. The database is only
 * read (the duplicate check), never written.
 * Run: php tests/copy-cataloging-limits.unit.php   (exit 0 iff every check passes)
 */

use App\Support\Marc\MarcCoverFetcher;
use Plugins\Z39Server\Classes\CopyCataloging;
use Plugins\Z39Server\Classes\SruClient;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/app/helpers.php';
require_once $root . '/storage/plugins/z39-server/classes/CopyCataloging.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

echo "Default ports\n";
$check(MarcCoverFetcher::effectivePort((array) parse_url('https://host/api/sru')) === MarcCoverFetcher::effectivePort((array) parse_url('https://host:443/api/sru')), '01 https://host and https://host:443 are the same port');
$check(MarcCoverFetcher::effectivePort((array) parse_url('http://host/x')) === 80 && MarcCoverFetcher::effectivePort((array) parse_url('http://host:8081/x')) === 8081, '02 http defaults to 80, an explicit port wins');
$check(MarcCoverFetcher::effectivePort((array) parse_url('https://host/x')) !== MarcCoverFetcher::effectivePort((array) parse_url('http://host/x')), '03 http and https defaults differ');

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    echo "SKIP: result cap: no .env\n";
} else {
    $env = Dotenv\Dotenv::parse((string) file_get_contents($envFile));
    $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
    $db->set_charset('utf8mb4');
    $_SESSION = [];

    $queried = [];
    $factory = static function (array $server) use (&$queried): SruClient {
        return new class ([$server], $queried) extends SruClient {
            /** @param list<string> $log */
            public function __construct(array $servers, private array &$log)
            {
                parent::__construct($servers);
            }

            public function searchServer(array $server, string $cql, int $max = 10): array
            {
                $this->log[] = (string) $server['name'];
                $records = [];
                for ($i = 1; $i <= $max; $i++) {
                    $records[] = ['data' => ['titolo' => "ZZCC {$server['name']} {$i}"], 'flavour' => 'marc21', 'marc' => null];
                }
                return ['total' => 500, 'records' => $records, 'error' => null];
            }
        };
    };
    $configured = [];
    for ($n = 1; $n <= 6; $n++) {
        $configured[] = ['name' => "Server {$n}", 'url' => "https://sru{$n}.example.org/sru", 'enabled' => true];
    }
    $cc = new CopyCataloging($db, null, $configured, false, false, 15, $factory);
    $keys = array_map(static fn (int $i): string => 'cfg:' . $i, range(0, 5));
    $answer = $cc->search($keys, 'title', 'x', 25);

    echo "Result cap\n";
    $check(count($answer['results']) === 100, '04 results stop at the cap of 100');
    $check(count($answer['servers']) === 6, '05 every chosen server is in the report, also those after the cap');
    $check($queried === ['Server 1', 'Server 2', 'Server 3', 'Server 4'], '06 servers after the cap are not queried');
    $notQueried = array_values(array_filter($answer['servers'], static fn (array $r): bool => $r['error'] !== null));
    $check(count($notQueried) === 2 && $notQueried[0]['name'] === 'Server 5' && str_contains((string) $notQueried[0]['error'], '100'), '07 they carry an explicit "limit reached" error');

    // 24 per server: the fifth server sends 24 records, only 4 fit under the cap.
    $queried = [];
    $cut = $cc->search($keys, 'title', 'x', 24);
    $fifth = array_values(array_filter($cut['servers'], static fn (array $r): bool => $r['name'] === 'Server 5'))[0] ?? [];
    $check(count($cut['results']) === 100 && ($fifth['count'] ?? null) === 4 && ($fifth['total'] ?? null) === 500, '08 a server cut by the cap reports the records listed (4), not the 24 it sent');
    $check(array_sum(array_column($cut['servers'], 'count')) === count($cut['results']), '09 the report counts add up to the results on the page');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
