<?php
declare(strict_types=1);

/**
 * Copy cataloguing edge cases raised in review of #505:
 *  - when the result cap is reached, the remaining servers are listed in the
 *    report as not queried instead of disappearing from it;
 *  - an URL with the scheme's default port written out (https://host:443)
 *    is the same endpoint as the one without it, for the cover fetcher and
 *    for the in-process local SRU client alike.
 *
 *  - a disabled configured server is neither offered nor queried, the book
 *    data kept in the session has a size cap, an ISSN search on SBN says it
 *    is not supported, and the history counts each chosen id once.
 *
 * Offline: every server answers through a fake client. The database is only
 * read (the duplicate check), except for one import_logs row the history
 * check writes and removes.
 * Run: php tests/copy-cataloging-limits.unit.php   (exit 0 iff every check passes)
 */

use App\Support\Marc\MarcCoverFetcher;
use Plugins\Z39Server\Classes\CopyCataloging;
use Plugins\Z39Server\Classes\LocalSruClient;
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

echo "Local SRU endpoint\n";
$streamOf = static function (string $bytes): \Psr\Http\Message\StreamInterface {
    $stream = (new \Slim\Psr7\Factory\StreamFactory())->createStream($bytes);
    $stream->seek(strlen($bytes)); // as a handler leaves it after writing
    return $stream;
};
$check(LocalSruClient::readBounded($streamOf('<ok/>'), 16) === '<ok/>', '03d an in-process answer within the cap is read whole');
try {
    LocalSruClient::readBounded($streamOf(str_repeat('x', 17)), 16);
    $check(false, '03e an in-process answer over the cap is refused');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'Response too large', '03e an in-process answer over the cap is refused');
}
$check(LocalSruClient::readBounded($streamOf(str_repeat('y', 16)), 16) === str_repeat('y', 16), '03f an answer of exactly the cap is read');
// LocalSruClient::isLocalUrl compares a target with this installation's own
// /api/sru, built from APP_CANONICAL_URL: the default HTTPS port written out
// or left implicit, on either side, is the same endpoint.
$savedCanonical = $_ENV['APP_CANONICAL_URL'] ?? null;
$_ENV['APP_CANONICAL_URL'] = 'https://catalogo.example.org';
$check(LocalSruClient::isLocalUrl('https://catalogo.example.org/api/sru') && LocalSruClient::isLocalUrl('https://catalogo.example.org:443/api/sru'), '03a the own SRU URL is local with the HTTPS port implicit and explicit');
$_ENV['APP_CANONICAL_URL'] = 'https://catalogo.example.org:443';
$check(LocalSruClient::isLocalUrl('https://catalogo.example.org/api/sru') && LocalSruClient::isLocalUrl('https://catalogo.example.org:443/api/sru'), '03b a canonical URL that spells out :443 still matches both forms');
$check(!LocalSruClient::isLocalUrl('https://catalogo.example.org:8443/api/sru') && !LocalSruClient::isLocalUrl('http://catalogo.example.org:443/api/sru'), '03c another port, or http on 443, is not this server');
if ($savedCanonical === null) {
    unset($_ENV['APP_CANONICAL_URL']);
} else {
    $_ENV['APP_CANONICAL_URL'] = $savedCanonical;
}

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

    echo "Disabled servers\n";
    $withOff = $configured;
    $withOff[1]['enabled'] = false;
    $withOff[] = ['name' => 'LoC off', 'url' => 'http://lx2.loc.gov:210/LCDB', 'enabled' => false];
    $ccOff = new CopyCataloging($db, null, $withOff, false, false, 15, $factory);
    $offered = $ccOff->servers();
    $check(!isset($offered['cfg:1']) && isset($offered['cfg:0']) && !isset($offered['cfg:6']) && !isset($offered['preset:loc']), '10 a disabled configured server is not offered, nor the preset it replaces');
    $queried = [];
    $offAnswer = $ccOff->search(['cfg:0', 'cfg:1'], 'title', 'x', 2);
    $check($queried === ['Server 1'] && count($offAnswer['servers']) === 1, '11 a disabled server named in the request is not queried');

    echo "Stored size cap\n";
    $bigFactory = static function (array $server) use (&$queried): SruClient {
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
                    $records[] = ['data' => ['titolo' => "ZZCC {$server['name']} {$i}", 'descrizione' => str_repeat('d', 600 * 1024)], 'flavour' => 'marc21', 'marc' => null];
                }
                return ['total' => 500, 'records' => $records, 'error' => null];
            }
        };
    };
    $queried = [];
    $ccBig = new CopyCataloging($db, null, $configured, false, false, 15, $bigFactory);
    $big = $ccBig->search(['cfg:0', 'cfg:1'], 'title', 'x', 25);
    $storedSize = strlen((string) json_encode(array_column($_SESSION['copy_cataloging_results'] ?? [], 'data')));
    $check(count($big['results']) === 6 && $storedSize <= CopyCataloging::MAX_STORED_BYTES, '12 the book data kept in the session stops at the size cap (6 records of 600 KiB under 4 MiB)');
    $check($queried === ['Server 1'] && ($big['servers'][0]['count'] ?? null) === 6 && ($big['servers'][1]['error'] ?? null) === __('Limite di dimensione dei risultati raggiunto: catalogo non interrogato.'), '13 the next server is reported as not queried because of the size cap');

    echo "SBN and ISSN\n";
    $ccSbn = new CopyCataloging($db, null, [], true, false, 15, $factory);
    $sbnIssn = $ccSbn->search(['sbn'], 'issn', '0391-6170', 5);
    $check($sbnIssn['results'] === [] && ($sbnIssn['servers'][0]['error'] ?? null) === __('Il catalogo SBN non permette la ricerca per ISSN.'), '14 an ISSN search on SBN says the catalogue has no ISSN index instead of a free-text search');

    echo "History counts\n";
    $_SESSION['copy_cataloging_results'] = [];
    $imp = $ccSbn->import(['zz-gone', 'zz-gone', 'zz-gone'], 'skip', false);
    $logged = null;
    if (($imp['import_id'] ?? null) !== null) {
        $logStmt = $db->prepare('SELECT total_rows FROM import_logs WHERE import_id = ?');
        $logStmt->bind_param('s', $imp['import_id']);
        $logStmt->execute();
        $logged = (int) ($logStmt->get_result()->fetch_row()[0] ?? -1);
        $logStmt->close();
        $delStmt = $db->prepare('DELETE FROM import_logs WHERE import_id = ?');
        $delStmt->bind_param('s', $imp['import_id']);
        $delStmt->execute();
        $delStmt->close();
    }
    $check($imp['failed'] === 1 && $logged === 1, '15 the history counts an id sent three times once, as the import handles it');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
