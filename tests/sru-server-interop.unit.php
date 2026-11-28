<?php
declare(strict_types=1);

/**
 * The SRU server as other people's clients read it.
 *
 *   A. SRU 1.1/1.2 answers bind the Library of Congress namespace to the
 *      conventional "zs" prefix. With the namespace left as the default one, a
 *      client that matches element names as text (xmltodict and the like)
 *      finds no zs:record / zs:recordData, and the envelope <record> collides
 *      by name with the MARCXML <record> inside it: explain came back empty.
 *   B. SRU 2.0 answers are written in the OASIS namespaces (sruResponse, scan,
 *      diagnostic) with recordXMLEscaping, not in the 1.x namespace with a
 *      "2.0" version number on it.
 *   C. Pinakes' own SRU client (copy cataloguing, another Pinakes as a source)
 *      still reads all of it, and still reads the unprefixed and 2.0 forms
 *      other servers answer with.
 *   D. scan browses the index: the terms at and after the start term, in index
 *      order, with responsePosition deciding where the start term sits. It is
 *      not a prefix search.
 *
 * The real SRUServer against the real database. The catalogue rows the checks
 * need are written inside a transaction that is rolled back, and the last
 * check proves nothing was left behind.
 *
 * Run: php tests/sru-server-interop.unit.php   (exit 0 iff all pass)
 */

use App\Support\BookVisibility;
use Plugins\Z39Server\Classes\SruClient;
use Z39Server\SRUServer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
foreach ([
    'Exceptions/CQLException.php',
    'Exceptions/InvalidCQLSyntaxException.php',
    'Exceptions/UnsupportedIndexException.php',
    'Exceptions/UnsupportedRelationException.php',
    'Exceptions/SRUQueryException.php',
    'Exceptions/DatabaseException.php',
    'CQLParser.php',
    'RecordFormatter.php',
    'DublinCoreFormatter.php',
    'MARCXMLFormatter.php',
    'MODSFormatter.php',
    'UNIMARCXMLFormatter.php',
    'SRUServer.php',
    'SruClient.php',
] as $z39File) {
    require_once $root . '/storage/plugins/z39-server/classes/' . $z39File;
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

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
        ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $dbPass, $name, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL: database not reachable (' . $e->getMessage() . ") — this suite requires the real DB\n");
    exit(1);
}

const NS_LOC      = 'http://www.loc.gov/zing/srw/';
const NS_LOC_DIAG = 'http://www.loc.gov/zing/srw/diagnostic/';
const NS_OASIS      = 'http://docs.oasis-open.org/ns/search-ws/sruResponse';
const NS_OASIS_SCAN = 'http://docs.oasis-open.org/ns/search-ws/scan';
const NS_OASIS_DIAG = 'http://docs.oasis-open.org/ns/search-ws/diagnostic';
const NS_ZEEREX = 'http://explain.z3950.org/dtd/2.1/';
const NS_MARC   = 'http://www.loc.gov/MARC21/slim';

$settings = [
    'server_host'       => 'localhost',
    'server_port'       => '80',
    'server_database'   => 'catalog',
    'supported_formats' => 'marcxml,dc,mods,unimarcxml',
    'default_format'    => 'marcxml',
    'default_records'   => '10',
    'max_records'       => '100',
    'enable_logging'    => 'false',
];
/** One SRU request through the real server; returns the XML it answers. */
$sru = static fn(array $params): string => (new SRUServer($db, $settings))->handleRequest($params);

$xpathOf = static function (string $xml): \DOMXPath {
    $doc = new \DOMDocument();
    if (!@$doc->loadXML($xml)) {
        throw new \RuntimeException('the response is not well-formed XML: ' . substr($xml, 0, 200));
    }
    $xp = new \DOMXPath($doc);
    foreach (['zs' => NS_LOC, 'd1' => NS_LOC_DIAG, 'sru' => NS_OASIS, 'scan' => NS_OASIS_SCAN, 'd2' => NS_OASIS_DIAG, 'ex' => NS_ZEEREX, 'marc' => NS_MARC] as $prefix => $uri) {
        $xp->registerNamespace($prefix, $uri);
    }
    return $xp;
};
/**
 * Walk down by the element names exactly as written in the document, the way
 * a client that ignores namespaces does (xmltodict keys, a regex, an XSLT
 * written against one sample). Returns the first match, or null.
 */
$byName = static function (\DOMXPath $xp, string ...$names): ?\DOMElement {
    $node = $xp->document;
    foreach ($names as $wanted) {
        $next = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === $wanted) {
                $next = $child;
                break;
            }
        }
        if ($next === null) {
            return null;
        }
        $node = $next;
    }
    return $node instanceof \DOMElement ? $node : null;
};
/** @return list<string> element names (as written) of the element children */
$childNames = static function (?\DOMNode $node): array {
    $names = [];
    foreach ($node?->childNodes ?? [] as $child) {
        if ($child instanceof \DOMElement) {
            $names[] = $child->nodeName;
        }
    }
    return $names;
};
$count = static fn(\DOMXPath $xp, string $query): int => (int) $xp->evaluate("count({$query})");
$text = static fn(\DOMXPath $xp, string $query): string => trim((string) $xp->evaluate("string({$query})"));
/** @return list<string> */
$texts = static function (\DOMXPath $xp, string $query): array {
    $out = [];
    foreach ($xp->query($query) ?: [] as $node) {
        $out[] = trim((string) $node->textContent);
    }
    return $out;
};
/** The terms of a scan response, whatever version it is written in. */
$scanTerms = static function (array $params) use ($sru, $xpathOf, $texts): array {
    return $texts($xpathOf($sru(['operation' => 'scan'] + $params)), "//*[local-name()='term']/*[local-name()='value']");
};

// ── Fixtures ───────────────────────────────────────────────────────────────
// Letters only, so the collation orders the titles the way they read. "zzq"
// puts them after anything a real catalogue holds.
$run = 'zzq';
for ($i = 0; $i < 8; $i++) {
    $run .= chr(random_int(97, 122));
}
$isbn13 = static function (string $twelve): string {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return $twelve . ((10 - $sum % 10) % 10);
};
$isbnBase = '97999' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
$isbnAlfa = $isbn13($isbnBase . '11');
$isbnBeta = $isbn13($isbnBase . '22');
$author = ucfirst($run) . ' Autore';
$subject = ucfirst($run) . ' Soggetto';
$subSubject = ucfirst($run) . ' Sottosoggetto';
$translator = ucfirst($run) . ' Traduttore';
$literal = "{$run} m 50%_o'x";
$hasDesiderata = BookVisibility::hasDesiderata($db);

$db->begin_transaction();
try {
    $addBook = static function (string $title, ?string $isbn = null, array $extra = []) use ($db): int {
        $columns = ['titolo' => $title, 'isbn13' => $isbn] + $extra;
        $stmt = $db->prepare('INSERT INTO libri (' . implode(', ', array_keys($columns)) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $values = array_values($columns);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    // Mixed case on purpose: an index is ordered by its collation, not by bytes.
    $alfa    = $addBook("{$run} Alfa", $isbnAlfa);
    $beta    = $addBook("{$run} beta", $isbnBeta);
    $epsilon = $addBook("{$run} Epsilon");
    $gamma1  = $addBook("{$run} Gamma");
    $gamma2  = $addBook("{$run} Gamma");
    $deleted = $addBook("{$run} delta");
    $literalBook = $addBook($literal);
    // Three spellings the collation holds equal: one term, three records. The
    // byte-wise lowest is written last, so "whichever row comes first" is not
    // it.
    $addBook("{$run} Omega");
    $addBook("{$run} omega");
    $addBook("{$run} OMEGA");
    // CI-SOFT-DELETE-EXEMPT: the fixture is soft-deleted on purpose.
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$deleted}");
    if ($hasDesiderata) {
        $addBook("{$run} Dzeta", null, ['is_desiderata' => '1']);
    }

    $stmt = $db->prepare('INSERT INTO autori (nome) VALUES (?)');
    $stmt->bind_param('s', $author);
    $stmt->execute();
    $authorId = (int) $db->insert_id;
    $stmt->close();
    foreach ([$alfa, $beta, $deleted] as $bookId) {
        $db->query("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES ({$bookId}, {$authorId}, 'principale')");
    }
    $stmt = $db->prepare('INSERT INTO generi (nome) VALUES (?)');
    $stmt->bind_param('s', $subject);
    $stmt->execute();
    $subjectId = (int) $db->insert_id;
    $stmt->close();
    $db->query("UPDATE libri SET genere_id = {$subjectId} WHERE id IN ({$alfa}, {$gamma1}, {$gamma2})");
    // A subgenre of it, on one book.
    $stmt = $db->prepare('INSERT INTO generi (nome, parent_id) VALUES (?, ?)');
    $stmt->bind_param('si', $subSubject, $subjectId);
    $stmt->execute();
    $subSubjectId = (int) $db->insert_id;
    $stmt->close();
    $db->query("UPDATE libri SET sottogenere_id = {$subSubjectId} WHERE id = {$alfa}");
    // A name that is on a book only as its translator.
    $stmt = $db->prepare('INSERT INTO autori (nome) VALUES (?)');
    $stmt->bind_param('s', $translator);
    $stmt->execute();
    $translatorId = (int) $db->insert_id;
    $stmt->close();
    $db->query("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES ({$epsilon}, {$translatorId}, 'traduttore')");

    $oneBook = ['operation' => 'searchRetrieve', 'query' => 'dc.title="' . $run . ' Alfa"', 'recordSchema' => 'marcxml'];

    // ── A. SRU 1.1 / 1.2: the zs prefix ────────────────────────────────────
    echo "A. SRU 1.x envelope\n";
    foreach (['1.1', '1.2'] as $v) {
        $xml = $sru(['operation' => 'explain', 'version' => $v]);
        $xp = $xpathOf($xml);
        $check($xp->document->documentElement?->nodeName === 'zs:explainResponse' && $xp->document->documentElement->namespaceURI === NS_LOC,
            "A{$v} explain: the root is zs:explainResponse in the LoC namespace");
        $check(!str_contains($xml, 'xmlns="' . NS_LOC . '"'), "A{$v} explain: the LoC namespace is not the default namespace");
        $explain = $byName($xp, 'zs:explainResponse', 'zs:record', 'zs:recordData', 'explain');
        $check($explain !== null && $explain->namespaceURI === NS_ZEEREX, "A{$v} explain: zs:record > zs:recordData > explain is found by name, in the ZeeRex namespace");
        $check($byName($xp, 'zs:explainResponse', 'zs:record', 'zs:recordData', 'explain', 'indexInfo', 'index', 'map', 'name') !== null,
            "A{$v} explain: indexInfo > index > map > name is found by name");
        $check($count($xp, '/zs:explainResponse/zs:record/zs:recordData/ex:explain/ex:indexInfo/ex:index/ex:map/ex:name[@set]') === 13
            && $count($xp, '//ex:explain//*[namespace-uri() != "' . NS_ZEEREX . '"]') === 0,
            "A{$v} explain: 13 indexes, every element of the record in the ZeeRex namespace");
        $check($count($xp, '//*[name() = "record"]') === 0, "A{$v} explain: no unprefixed <record> for a by-name reader to trip on");
        $check($text($xp, '/zs:explainResponse/zs:version') === $v && $text($xp, '//ex:serverInfo/@version') === '1.2',
            "A{$v} explain: the request version is echoed, serverInfo names 1.2");

        $xml = $sru($oneBook + ['version' => $v]);
        $xp = $xpathOf($xml);
        $check($text($xp, '/zs:searchRetrieveResponse/zs:version') === $v && $text($xp, '/zs:searchRetrieveResponse/zs:numberOfRecords') === '1',
            "A{$v} searchRetrieve: zs:version {$v}, zs:numberOfRecords 1");
        $check($childNames($byName($xp, 'zs:searchRetrieveResponse')) === ['zs:version', 'zs:numberOfRecords', 'zs:records', 'zs:echoedSearchRetrieveRequest']
            && $childNames($byName($xp, 'zs:searchRetrieveResponse', 'zs:records', 'zs:record')) === ['zs:recordSchema', 'zs:recordPacking', 'zs:recordData', 'zs:recordPosition'],
            "A{$v} searchRetrieve: the 1.x elements, in the 1.x order, all prefixed");
        $marc = $byName($xp, 'zs:searchRetrieveResponse', 'zs:records', 'zs:record', 'zs:recordData', 'record');
        $check($marc !== null && $marc->namespaceURI === NS_MARC && $text($xp, '//marc:datafield[@tag="245"]/marc:subfield[@code="a"]') === "{$run} Alfa",
            "A{$v} searchRetrieve: the MARCXML record keeps its own namespace and its title");
        $check($count($xp, '//*[namespace-uri() = "' . NS_OASIS . '"]') === 0 && !str_contains($xml, 'recordXMLEscaping'),
            "A{$v} searchRetrieve: nothing of SRU 2.0 in a 1.x answer");

        $xp = $xpathOf($sru(['operation' => 'searchRetrieve', 'version' => $v, 'query' => 'nosuch.index=x']));
        $check($text($xp, '/zs:searchRetrieveResponse/zs:diagnostics/d1:diagnostic/d1:uri') === 'info:srw/diagnostic/1/16'
            && $byName($xp, 'zs:searchRetrieveResponse', 'zs:diagnostics', 'diag:diagnostic', 'diag:message') !== null,
            "A{$v} diagnostic: zs:diagnostics > diag:diagnostic in the 1.x diagnostic namespace");

        $xp = $xpathOf($sru($oneBook + ['version' => $v, 'recordPacking' => 'string']));
        $check(str_starts_with($text($xp, '//zs:recordData'), '<record xmlns="' . NS_MARC . '"') && $count($xp, '//zs:recordData/*') === 0,
            "A{$v} recordPacking=string still carries the record as text");

        $xp = $xpathOf($sru(['operation' => 'scan', 'version' => $v, 'scanClause' => 'dc.title="' . $run . '"', 'maximumTerms' => '2']));
        $check($byName($xp, 'zs:scanResponse', 'zs:terms', 'zs:term', 'zs:value') !== null && $text($xp, '/zs:scanResponse/zs:version') === $v,
            "A{$v} scan: zs:scanResponse > zs:terms > zs:term > zs:value");
    }

    // ── B. SRU 2.0: the OASIS vocabulary ───────────────────────────────────
    echo "\nB. SRU 2.0 envelope\n";
    $xml = $sru($oneBook + ['version' => '2.0']);
    $xp = $xpathOf($xml);
    $check($xp->document->documentElement?->localName === 'searchRetrieveResponse' && $xp->document->documentElement->namespaceURI === NS_OASIS,
        'B01 searchRetrieve: the root is in the OASIS sruResponse namespace');
    $check($count($xp, '//*[starts-with(namespace-uri(), "' . NS_LOC . '")]') === 0 && !str_contains($xml, NS_LOC),
        'B02 searchRetrieve: no element, and no declaration, of the 1.x namespace');
    $check($count($xp, '//*[local-name() = "version"]') === 0 && $text($xp, '/sru:searchRetrieveResponse/sru:numberOfRecords') === '1'
        && $xp->query('/sru:searchRetrieveResponse/*')?->item(0)?->localName === 'numberOfRecords',
        'B03 searchRetrieve: numberOfRecords 1 comes first, and neither the response nor its echo has a version element (SRU 2.0 removed it)');
    $check($texts($xp, '/sru:searchRetrieveResponse/sru:records/sru:record/*[namespace-uri() = "' . NS_OASIS . '"]/@*') === []
        && array_map(static fn(\DOMNode $n): string => (string) $n->localName, iterator_to_array($xp->query('/sru:searchRetrieveResponse/sru:records/sru:record/*') ?: [], false))
            === ['recordSchema', 'recordXMLEscaping', 'recordData', 'recordPosition'],
        'B04 searchRetrieve: a record is recordSchema, recordXMLEscaping, recordData, recordPosition');
    $check($count($xp, '//*[local-name() = "recordPacking"]') === 0 && $text($xp, '//sru:echoedSearchRetrieveRequest/sru:recordXMLEscaping') === 'xml',
        'B05 searchRetrieve: recordPacking is gone, the echo says recordXMLEscaping');
    $check($text($xp, '//sru:recordData/marc:record/marc:datafield[@tag="245"]/marc:subfield[@code="a"]') === "{$run} Alfa",
        'B06 searchRetrieve: the same MARCXML record inside');

    $xp = $xpathOf($sru($oneBook + ['version' => '2.0', 'recordXMLEscaping' => 'string']));
    $check(str_starts_with($text($xp, '//sru:recordData'), '<record xmlns="' . NS_MARC . '"') && $text($xp, '//sru:record/sru:recordXMLEscaping') === 'string',
        'B07 recordXMLEscaping=string carries the record as text');
    $xp = $xpathOf($sru($oneBook + ['version' => '2.0', 'recordPacking' => 'string']));
    $check(str_starts_with($text($xp, '//sru:recordData'), '<record xmlns="' . NS_MARC . '"'),
        'B08 a 2.0 request that still says recordPacking=string is read the 1.x way');
    $xp = $xpathOf($sru($oneBook + ['version' => '2.0', 'recordPacking' => 'packed']));
    $check($text($xp, '/sru:searchRetrieveResponse/sru:numberOfRecords') === '1' && $count($xp, '//sru:recordData/marc:record') === 1,
        'B09 recordPacking=packed (its 2.0 meaning) is accepted');
    $xp = $xpathOf($sru($oneBook + ['version' => '2.0', 'recordXMLEscaping' => 'bogus']));
    $check($text($xp, '/sru:searchRetrieveResponse/sru:diagnostics/d2:diagnostic/d2:uri') === 'info:srw/diagnostic/1/71'
        && $text($xp, '//d2:diagnostic/d2:details') === 'bogus' && $count($xp, '//d1:*') === 0,
        'B10 an unknown recordXMLEscaping is diagnostic 71, in the OASIS diagnostic namespace');
    $check($count($xp, '//*[local-name() = "version"]') === 0, 'B10b a 2.0 diagnostic response has no version element either');

    $xml = $sru(['operation' => 'explain', 'version' => '2.0']);
    $xp = $xpathOf($xml);
    $check($count($xp, '/sru:explainResponse/sru:record/sru:recordData/ex:explain/ex:indexInfo/ex:index') === 13 && !str_contains($xml, NS_LOC),
        'B11 explain: sruResponse envelope around the same ZeeRex record');
    $check($text($xp, '//ex:serverInfo/@version') === '2.0' && $text($xp, '/sru:explainResponse/sru:record/sru:recordXMLEscaping') === 'xml',
        'B12 explain: serverInfo names 2.0, the record says recordXMLEscaping');
    $check($count($xp, '/sru:explainResponse/sru:version') === 0, 'B12b explain: no version element in the 2.0 envelope');

    $xml = $sru(['operation' => 'scan', 'version' => '2.0', 'scanClause' => 'dc.title="' . $run . '"', 'maximumTerms' => '2']);
    $xp = $xpathOf($xml);
    $check($xp->document->documentElement?->namespaceURI === NS_OASIS_SCAN && $texts($xp, '/scan:scanResponse/scan:terms/scan:term/scan:value') === ["{$run} Alfa", "{$run} beta"],
        'B13 scan: the OASIS scan namespace, terms inside');
    $check($count($xp, '//*[local-name() = "version" or local-name() = "position"]') === 0 && !str_contains($xml, NS_LOC)
        && $text($xp, '/scan:scanResponse/scan:echoedScanRequest/scan:maximumTerms') === '2',
        'B14 scan: no version and no position element (the 2.0 schema has neither)');
    // No ISBN sorts after a letter, so this start term is past the end.
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '2.0', 'scanClause' => 'bath.isbn=zzzz']));
    $check($count($xp, '/scan:scanResponse/scan:terms') === 0 && $count($xp, '/scan:scanResponse/scan:echoedScanRequest') === 1,
        'B15 scan: past the end of the index there is no (empty) terms element');
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '2.0', 'scanClause' => 'nosuch.index=x']));
    $check($text($xp, '/scan:scanResponse/scan:diagnostics/d2:diagnostic/d2:uri') === 'info:srw/diagnostic/1/16',
        'B16 scan: a diagnostic is scan:diagnostics > OASIS diagnostic');

    // ── C. Pinakes' own client ─────────────────────────────────────────────
    echo "\nC. The bundled SRU client\n";
    $loopback = new class ([], $sru) extends SruClient {
        /** @param \Closure(array<string,mixed>):string $sru */
        public function __construct(array $servers, private \Closure $sru)
        {
            parent::__construct($servers);
        }
        protected function fetchUrl(string $url): ?string
        {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            return ($this->sru)($query);
        }
    };
    foreach (['1.1', '1.2', '2.0'] as $v) {
        foreach (['marcxml', 'dc'] as $syntax) {
            $server = ['name' => "Pinakes {$v}", 'url' => 'https://pinakes.example.org/api/sru', 'version' => $v, 'syntax' => $syntax, 'enabled' => true];
            $answer = $loopback->searchServer($server, 'dc.title="' . $run . ' Alfa"', 5);
            $check($answer['error'] === null && $answer['total'] === 1 && ($answer['records'][0]['data']['titolo'] ?? '') === "{$run} Alfa",
                "C{$v} search, {$syntax}: one record, title read" . ($answer['error'] !== null ? " ({$answer['error']})" : ''));
        }
        $server = ['name' => "Pinakes {$v}", 'url' => 'https://pinakes.example.org/api/sru', 'version' => $v, 'syntax' => 'marcxml', 'enabled' => true];
        $byIsbn = (new class ([$server], $sru) extends SruClient {
            /** @param \Closure(array<string,mixed>):string $sru */
            public function __construct(array $servers, private \Closure $sru)
            {
                parent::__construct($servers);
            }
            protected function fetchUrl(string $url): ?string
            {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                return ($this->sru)($query);
            }
        })->searchByIsbn($isbnBeta);
        $check(is_array($byIsbn) && ($byIsbn['title'] ?? $byIsbn['titolo'] ?? '') === "{$run} beta", "C{$v} ISBN lookup finds the book");
        $failed = $loopback->searchServer($server, 'nosuch.index=x', 5);
        $check($failed['error'] === 'Unsupported index' && $failed['records'] === [], "C{$v} a diagnostic is reported as the search error");
    }
    // What other servers answer with: 1.x in the default namespace, 2.0 in the
    // default namespace. Neither is what this server writes any more.
    $marcRecord = '<record xmlns="' . NS_MARC . '"><leader>00000nam a2200000 a 4500</leader><datafield tag="245" ind1="1" ind2="0"><subfield code="a">Altrove</subfield></datafield></record>';
    $foreign = static fn(string $xml): array => (new class ([], static fn(array $q): string => $xml) extends SruClient {
        /** @param \Closure(array<string,mixed>):string $sru */
        public function __construct(array $servers, private \Closure $sru)
        {
            parent::__construct($servers);
        }
        protected function fetchUrl(string $url): ?string
        {
            return ($this->sru)([]);
        }
    })->searchServer(['url' => 'https://other.example.org/sru', 'syntax' => 'marcxml'], 'x', 5);
    $unprefixed = $foreign('<?xml version="1.0"?><searchRetrieveResponse xmlns="' . NS_LOC . '"><version>1.2</version><numberOfRecords>7</numberOfRecords><records><record><recordSchema>marcxml</recordSchema><recordPacking>xml</recordPacking><recordData>' . $marcRecord . '</recordData></record></records></searchRetrieveResponse>');
    $check($unprefixed['error'] === null && $unprefixed['total'] === 7 && ($unprefixed['records'][0]['data']['titolo'] ?? '') === 'Altrove',
        'C-other an unprefixed 1.2 answer from another server is still read');
    $oasis = $foreign('<?xml version="1.0"?><searchRetrieveResponse xmlns="' . NS_OASIS . '"><numberOfRecords>3</numberOfRecords><records><record><recordSchema>marcxml</recordSchema><recordXMLEscaping>xml</recordXMLEscaping><recordData>' . $marcRecord . '</recordData></record></records></searchRetrieveResponse>');
    $check($oasis['error'] === null && $oasis['total'] === 3 && ($oasis['records'][0]['data']['titolo'] ?? '') === 'Altrove',
        'C-other an unprefixed 2.0 answer from another server is still read');

    // ── D. scan browses the index ──────────────────────────────────────────
    echo "\nD. scan\n";
    $title = static fn(string $rest): string => 'dc.title="' . $run . ' ' . $rest . '"';
    $check($scanTerms(['scanClause' => $title('b'), 'maximumTerms' => '3']) === ["{$run} beta", "{$run} Epsilon", "{$run} Gamma"],
        'D01 the first terms AT OR AFTER the start term, in index order (two of them do not begin with it)');
    $check($scanTerms(['scanClause' => $title('c'), 'maximumTerms' => '2']) === ["{$run} Epsilon", "{$run} Gamma"],
        'D02 a start term nothing begins with still answers what follows it');
    $all = $scanTerms(['scanClause' => 'dc.title="' . $run . '"', 'maximumTerms' => '50']);
    $check(array_slice($all, 0, 5) === ["{$run} Alfa", "{$run} beta", "{$run} Epsilon", "{$run} Gamma", $literal],
        'D03 the order is the collation\'s, case-insensitive (Alfa, beta, Epsilon, Gamma)');
    $check(!in_array("{$run} delta", $all, true), 'D04 a soft-deleted book is not a term');
    if ($hasDesiderata) {
        $check(!in_array("{$run} Dzeta", $all, true), 'D05 a requested book (desiderata) is not a term');
    } else {
        echo "  --  D05 not applicable: this installation has no libri.is_desiderata\n";
    }
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => $title('g'), 'maximumTerms' => '1']));
    $check($text($xp, '//zs:term[1]/zs:value') === "{$run} Gamma" && $text($xp, '//zs:term[1]/zs:numberOfRecords') === '2',
        'D06 numberOfRecords counts the records carrying the term (two books called Gamma)');

    $check($scanTerms(['scanClause' => $title('e'), 'maximumTerms' => '3', 'responsePosition' => '2']) === ["{$run} beta", "{$run} Epsilon", "{$run} Gamma"],
        'D07 responsePosition=2: one preceding term, then the start position');
    $check($scanTerms(['scanClause' => $title('e'), 'maximumTerms' => '3', 'responsePosition' => '3']) === ["{$run} Alfa", "{$run} beta", "{$run} Epsilon"],
        'D08 responsePosition=3: two preceding terms, the start position last');
    $check($scanTerms(['scanClause' => $title('Epsilon'), 'maximumTerms' => '2', 'responsePosition' => '0']) === ["{$run} Gamma", $literal],
        'D09 responsePosition=0: the list starts right after the start term');
    $check($scanTerms(['scanClause' => $title('Gamma'), 'maximumTerms' => '3', 'responsePosition' => '4']) === ["{$run} Alfa", "{$run} beta", "{$run} Epsilon"],
        'D10 responsePosition=maximumTerms+1: the terms right before the start term');
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => $title('e'), 'maximumTerms' => '3', 'responsePosition' => '5']));
    $check($text($xp, '/zs:scanResponse/zs:diagnostics/d1:diagnostic/d1:uri') === 'info:srw/diagnostic/1/120' && $count($xp, '//zs:term') === 0,
        'D11 a responsePosition beyond maximumTerms+1 is diagnostic 120');
    // SRU 2.0 has no such limit: the list begins responsePosition - 1 terms
    // before the start term and holds maximumTerms of them. Before Omega come
    // Alfa, beta, Epsilon, Gamma and the literal title: four back is beta.
    $check($scanTerms(['version' => '2.0', 'scanClause' => $title('Omega'), 'maximumTerms' => '3', 'responsePosition' => '5']) === ["{$run} beta", "{$run} Epsilon", "{$run} Gamma"],
        'D11b in 2.0 a responsePosition beyond maximumTerms+1 lists terms further before the start term');
    $check($scanTerms(['version' => '2.0', 'scanClause' => $title('Omega'), 'maximumTerms' => '3', 'responsePosition' => '4']) === ["{$run} Epsilon", "{$run} Gamma", $literal],
        'D11c and maximumTerms+1 is still the terms right before it');
    // Eight terms before beta is far before this run's titles (only Alfa
    // precedes it): whatever the catalogue holds there, it is not an error.
    $far = $xpathOf($sru(['operation' => 'scan', 'version' => '2.0', 'scanClause' => $title('beta'), 'maximumTerms' => '2', 'responsePosition' => '9']));
    $farTerms = $texts($far, '/scan:scanResponse/scan:terms/scan:term/scan:value');
    $check($count($far, '//d2:diagnostic') === 0 && count($farTerms) <= 2
        && array_filter($farTerms, static fn(string $term): bool => str_starts_with($term, $run . ' ')) === [],
        'D11d a position far before the start term answers what the index holds there, none of the terms next to it, and no diagnostic');
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => $title('e'), 'maximumTerms' => '3', 'responsePosition' => '2']));
    $check($text($xp, '//zs:echoedScanRequest/zs:responsePosition') === '2' && $text($xp, '//zs:echoedScanRequest/zs:maximumTerms') === '3',
        'D12 the request is echoed with its responsePosition and maximumTerms');

    // The start term is a value, never a pattern: %, _ and the quote are
    // compared literally, and the title that IS that string comes back first.
    $check($scanTerms(['scanClause' => 'dc.title="' . $literal . '"', 'maximumTerms' => '1']) === [$literal],
        'D13 a start term with % _ and a quote is matched literally');
    $beforePercent = $scanTerms(['scanClause' => 'dc.title="' . $run . ' %"', 'maximumTerms' => '50', 'responsePosition' => '51']);
    $check(array_filter($beforePercent, static fn(string $t): bool => str_starts_with($t, $run)) === [],
        'D14 "%" is not a wildcard: none of this run\'s titles is listed before it');

    $check($scanTerms(['scanClause' => 'dc.creator="' . $author . '"', 'maximumTerms' => '1']) === [$author], 'D15 dc.creator browses author names');
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => 'dc.creator="' . $author . '"', 'maximumTerms' => '1']));
    $check($text($xp, '//zs:term[1]/zs:numberOfRecords') === '2', 'D16 the author\'s count leaves out the soft-deleted book (2 of 3)');
    // A term is something the index can be searched for: dc.creator searches
    // authors and co-authors, so a translator is not one of its terms.
    $xp = $xpathOf($sru(['operation' => 'searchRetrieve', 'version' => '1.2', 'query' => 'dc.creator exact "' . $translator . '"']));
    $check(!in_array($translator, $scanTerms(['scanClause' => 'dc.creator="' . $translator . '"', 'maximumTerms' => '5']), true)
        && $text($xp, '/zs:searchRetrieveResponse/zs:numberOfRecords') === '0',
        'D16b a name that is only a translator is not a dc.creator term, as the search does not find it');
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => 'dc.subject="' . $subject . '"', 'maximumTerms' => '1']));
    $check($text($xp, '//zs:term[1]/zs:value') === $subject && $text($xp, '//zs:term[1]/zs:numberOfRecords') === '3', 'D17 dc.subject browses genres, with their record count');
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => 'dc.subject="' . $subSubject . '"', 'maximumTerms' => '1']));
    $found = $xpathOf($sru(['operation' => 'searchRetrieve', 'version' => '1.2', 'query' => 'dc.subject exact "' . $subSubject . '"']));
    $check($text($xp, '//zs:term[1]/zs:value') === $subSubject && $text($xp, '//zs:term[1]/zs:numberOfRecords') === '1'
        && $text($found, '/zs:searchRetrieveResponse/zs:numberOfRecords') === '1',
        'D17b a subgenre is a dc.subject term, and searching it finds the same one record');
    $check($scanTerms(['scanClause' => 'bath.isbn=' . $isbnBase, 'maximumTerms' => '2']) === [$isbnAlfa, $isbnBeta], 'D18 bath.isbn browses ISBNs from a partial number on');

    $first = $scanTerms(['scanClause' => 'dc.title', 'maximumTerms' => '3']);
    // Among the spellings the collation holds equal to the lowest title, the
    // server answers the byte-wise lowest one: MIN(titolo) alone may be any.
    $visible = "deleted_at IS NULL AND titolo <> '' AND " . BookVisibility::catalogue($db);
    $lowest = (string) $db->query("SELECT MIN(titolo COLLATE utf8mb4_bin) FROM libri WHERE {$visible}
                                   AND titolo = (SELECT MIN(titolo) FROM libri WHERE {$visible})")->fetch_row()[0];
    $check($first !== [] && $first[0] === $lowest, 'D19 scanClause=dc.title, an index alone, browses it from its first term');
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => 'dc.date=1980']));
    $check($text($xp, '//d1:diagnostic/d1:uri') === 'info:srw/diagnostic/1/16' && $text($xp, '//d1:diagnostic/d1:details') === 'dc.date',
        'D20 an index with no term list is diagnostic 16, not another index\'s terms');
    // The whole catalogue from "a" on, against the titles read row by row.
    // The collation decides what one term is: WEIGHT_STRING is its sort key,
    // so rows sharing it are one term however they are spelled, and a plain
    // GROUP BY titolo here would only repeat the server's query, arbitrary
    // spelling included. Expected, per term: the byte-wise lowest spelling and
    // the number of records.
    $expected = [];
    $lastKey = null;
    $db->real_query("SELECT titolo, HEX(WEIGHT_STRING(RTRIM(titolo))) FROM libri WHERE deleted_at IS NULL AND titolo <> '' AND titolo >= 'a' AND "
        . BookVisibility::catalogue($db) . ' ORDER BY titolo');
    $res = $db->use_result();
    while ($row = $res->fetch_row()) {
        $spelling = rtrim((string) $row[0], ' ');
        if ($row[1] !== $lastKey) {
            if (count($expected) === 100) {
                break;
            }
            $expected[] = ['value' => $spelling, 'records' => 0];
            $lastKey = $row[1];
        }
        $last = count($expected) - 1;
        $expected[$last]['records']++;
        if (strcmp($spelling, $expected[$last]['value']) < 0) {
            $expected[$last]['value'] = $spelling;
        }
    }
    $res->free();
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => 'dc.title=a', 'maximumTerms' => '100']));
    $answered = [];
    foreach ($xp->query('//zs:term') ?: [] as $term) {
        $answered[] = ['value' => rtrim((string) $xp->evaluate('string(zs:value)', $term), ' '), 'records' => (int) $xp->evaluate('string(zs:numberOfRecords)', $term)];
    }
    $firstDifference = '';
    foreach ($expected as $i => $want) {
        if (($answered[$i] ?? null) !== $want) {
            $firstDifference = "; term {$i}: expected " . json_encode($want, JSON_UNESCAPED_UNICODE) . ', answered ' . json_encode($answered[$i] ?? null, JSON_UNESCAPED_UNICODE);
            break;
        }
    }
    $check($expected !== [] && $answered === $expected,
        'D21 dc.title=a answers the catalogue\'s distinct titles from "a" on, each with its records (' . count($answered) . ' of ' . count($expected) . " terms{$firstDifference})");

    $ofThisRun = static fn(array $terms): array => array_values(array_filter($terms, static fn(string $t): bool => str_starts_with($t, $run)));
    $xp = $xpathOf($sru(['operation' => 'scan', 'version' => '1.2', 'scanClause' => $title('o'), 'maximumTerms' => '5']));
    $omega = $ofThisRun($texts($xp, '//zs:term/zs:value'));
    $check($omega === ["{$run} OMEGA"] && $text($xp, '//zs:term[1]/zs:numberOfRecords') === '3',
        'D22 spellings the collation holds equal are one term, under one fixed spelling, counting all their records (' . implode(' | ', $omega) . ')');
    $check($ofThisRun($scanTerms(['scanClause' => $title('OMEGA'), 'maximumTerms' => '5', 'responsePosition' => '0'])) === []
        && $scanTerms(['scanClause' => $title('omega'), 'maximumTerms' => '1', 'responsePosition' => '2']) === [$literal],
        'D23 browsing around any of those spellings treats them as that one term');
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $db->rollback();
}

$left = (int) $db->query("SELECT (SELECT COUNT(*) FROM libri WHERE titolo LIKE '{$run}%')"
    . " + (SELECT COUNT(*) FROM autori WHERE nome = '" . $db->real_escape_string($author) . "')"
    . " + (SELECT COUNT(*) FROM generi WHERE nome = '" . $db->real_escape_string($subject) . "')")->fetch_row()[0];
$check($left === 0, 'Z the fixtures were rolled back');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
