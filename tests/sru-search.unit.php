<?php
declare(strict_types=1);

/**
 * SruClient::search() / searchServer() / cqlFor(), offline: the client's fetch
 * returns saved responses (tests/fixtures/sru) or a response built here with
 * three MARCXML records, a Dublin Core response and an SRU diagnostic.
 *
 * Run: php tests/sru-search.unit.php   (exit 0 iff every check passes)
 */

use Plugins\Z39Server\Classes\SruClient;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/z39-server/classes/SruClient.php';
require_once $root . '/storage/plugins/z39-server/classes/Exceptions/CQLException.php';
require_once $root . '/storage/plugins/z39-server/classes/Exceptions/DatabaseException.php';
require_once $root . '/storage/plugins/z39-server/classes/Exceptions/InvalidCQLSyntaxException.php';
require_once $root . '/storage/plugins/z39-server/classes/Exceptions/SRUQueryException.php';
require_once $root . '/storage/plugins/z39-server/classes/Exceptions/UnsupportedIndexException.php';
require_once $root . '/storage/plugins/z39-server/classes/Exceptions/UnsupportedRelationException.php';
require_once $root . '/storage/plugins/z39-server/classes/CQLParser.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . "\n";
};

final class SearchFixtureClient extends SruClient
{
    /** @var list<string> */
    public array $urls = [];
    public function __construct(array $servers, private string $xml)
    {
        parent::__construct($servers);
    }
    protected function fetchUrl(string $url): ?string
    {
        $this->urls[] = $url;
        return $this->xml;
    }
}

$marcRecord = static fn (string $title, string $isbn, string $author): string =>
    '<record xmlns="http://www.loc.gov/MARC21/slim"><leader>00000nam a2200000 a 4500</leader>'
    . '<datafield tag="020" ind1=" " ind2=" "><subfield code="a">' . $isbn . '</subfield></datafield>'
    . '<datafield tag="100" ind1="1" ind2=" "><subfield code="a">' . $author . '</subfield></datafield>'
    . '<datafield tag="245" ind1="1" ind2="0"><subfield code="a">' . $title . '</subfield></datafield></record>';
$sru = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/"><version>1.1</version><numberOfRecords>42</numberOfRecords><records>';
foreach ([['Uno', '9788845292613', 'Eco, Umberto'], ['Due', '9780140449136', 'Dostoyevsky, Fyodor'], ['Tre', '9783161484100', 'Müller, Jürgen']] as [$t, $i, $a]) {
    $sru .= '<record><recordSchema>marcxml</recordSchema><recordPacking>xml</recordPacking><recordData>' . $marcRecord($t, $i, $a) . '</recordData></record>';
}
$sru .= '</records></searchRetrieveResponse>';

$server = ['name' => 'Fixture', 'url' => 'https://sru.example.org/sru', 'version' => '1.2', 'syntax' => 'marcxml', 'enabled' => true, 'indexes' => ['title' => 'bib.title']];

echo "CQL\n";
$check(SruClient::cqlFor($server, 'title', 'il nome della rosa') === 'bib.title="il nome della rosa"', '01 the server\'s own index, a multi-word term quoted');
$check(SruClient::cqlFor($server, 'author', 'Eco') === 'dc.creator="Eco"', '02 the standard index when the server maps none');
$check(SruClient::cqlFor(['indexes' => ['isbn' => 'alma.isbn']], 'isbn', '978-88-452-9261-3') === 'alma.isbn=9788845292613', '03 an ISBN is reduced to its digits');
$check(SruClient::cqlFor([], 'any', 'say "hi"') === 'cql.serverChoice="say \\"hi\\""', '04 embedded quotes are escaped');
$check(SruClient::cqlFor([], 'bogus', 'x') === 'cql.serverChoice="x"', '05 an unknown index falls back to all fields');
$check(SruClient::cqlFor([], 'title', "dell'arte") === 'dc.title="dell\'arte"', '05b a word with an apostrophe is quoted');
$check(SruClient::cqlFor([], 'title', 'and') === 'dc.title="and"', '05c a CQL keyword used as a word is quoted');
$check(SruClient::cqlFor(['quote_search_terms' => true], 'isbn', '88-452-9261-3') === 'bath.isbn="8845292613"', '05d an ISBN is quoted only when the server asks');
foreach (["dell'arte", 'and', 'not', 'Eco'] as $word) {
    $parsed = true;
    try {
        (new \Z39Server\CQLParser())->parse(SruClient::cqlFor([], 'title', $word));
    } catch (Throwable) {
        $parsed = false;
    }
    $check($parsed, "05e the local CQL parser accepts the query for '{$word}'");
}

echo "searchServer\n";
$client = new SearchFixtureClient([$server], $sru);
$answer = $client->searchServer($server, 'bib.title=x', 3);
$check($answer['error'] === null && $answer['total'] === 42 && count($answer['records']) === 3, '06 three records of 42, no error');
$check(array_map(static fn ($r) => $r['data']['titolo'], $answer['records']) === ['Uno', 'Due', 'Tre'], '07 in server order, mapped with the MARC 21 mapper');
$check($answer['records'][1]['data']['isbn13'] === '9780140449136' && $answer['records'][2]['data']['autori'] === ['Müller, Jürgen'], '08 ISBN and authors read');
$check($answer['records'][0]['flavour'] === 'marc21' && ($answer['records'][0]['marc']['leader'] ?? '') === '00000nam a2200000 a 4500', '09 the MARC record is kept for the detail view');
parse_str((string) parse_url($client->urls[0], PHP_URL_QUERY), $q);
$check(($q['operation'] ?? '') === 'searchRetrieve' && ($q['maximumRecords'] ?? '') === '3' && ($q['query'] ?? '') === 'bib.title=x' && ($q['recordSchema'] ?? '') === 'marcxml', '10 the request asks for N records in the server\'s schema');
$two = (new SearchFixtureClient([$server], $sru))->searchServer($server, 'q', 2);
$check(count($two['records']) === 2, '11 $max caps the records read');

echo "Other answers\n";
$diag = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/"><numberOfRecords>0</numberOfRecords><diagnostics><diagnostic xmlns="http://www.loc.gov/zing/srw/diagnostic/"><uri>info:srw/diagnostic/1/16</uri><message>Unsupported index</message></diagnostic></diagnostics></searchRetrieveResponse>';
$bad = (new SearchFixtureClient([$server], $diag))->searchServer($server, 'q', 5);
$check($bad['error'] === 'Unsupported index' && $bad['records'] === [], '12 an SRU diagnostic is the server\'s error, not an exception');
$broken = (new SearchFixtureClient([$server], '<not xml'))->searchServer($server, 'q', 5);
$check($broken['error'] !== null, '13 a response that is not XML is reported');
$invalid = (new SearchFixtureClient([], $sru))->searchServer(['url' => 'ftp://x'], 'q', 5);
$check($invalid['error'] !== null, '14 a non-HTTP URL is refused');
$dc = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/"><numberOfRecords>2</numberOfRecords><records>'
    . '<record><recordData><oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Primo DC</dc:title><dc:creator>Rossi, Anna</dc:creator><dc:identifier>ISBN 9788845292613</dc:identifier></oai_dc:dc></recordData></record>'
    . '<record><recordData><oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Secondo DC</dc:title></oai_dc:dc></recordData></record>'
    . '</records></searchRetrieveResponse>';
$dcServer = ['url' => 'https://x.example.org/sru', 'syntax' => 'dc'];
$dcAnswer = (new SearchFixtureClient([$dcServer], $dc))->searchServer($dcServer, 'q', 5);
$check(array_map(static fn ($r) => $r['data']['titolo'], $dcAnswer['records']) === ['Primo DC', 'Secondo DC'] && $dcAnswer['records'][0]['data']['autori'] === ['Rossi, Anna'], '15 Dublin Core records, one by one');
$dan = (string) file_get_contents(__DIR__ . '/fixtures/sru/dbc-opensearch-9788702272451.xml');
$danServer = ['url' => 'https://opensearch.addi.dk/b3.5_5.2/?agency=100200&profile=test', 'syntax' => 'dbc-opensearch'];
$danClient = new SearchFixtureClient([$danServer], $dan);
$danAnswer = $danClient->searchServer($danServer, 'term.isbn=9788702272451', 10);
$check($danAnswer['records'] !== [] && $danAnswer['records'][0]['flavour'] === 'danmarc2' && str_contains($danClient->urls[0], 'stepValue=10') && str_contains($danClient->urls[0], 'agency=100200'), '16 DBC OpenSearch: danMARC2 records, stepValue, the agency kept');

echo "search() over every enabled server\n";
$multi = new SearchFixtureClient([$server, array_merge($server, ['enabled' => false, 'name' => 'Off']), ['name' => 'Second', 'url' => 'https://b.example.org/sru', 'enabled' => true]], $sru);
$all = $multi->search('dc.title=x', 2);
$check(count($all) === 2 && $all[0]['server'] === 'Fixture' && $all[1]['server'] === 'Second' && count($all[1]['records']) === 2, '17 disabled servers are skipped, each server answers');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
