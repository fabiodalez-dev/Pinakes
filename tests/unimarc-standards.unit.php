<?php
declare(strict_types=1);

/**
 * Source-level regression checks for UNIMARC XML standards alignment.
 *
 * Run:
 *   php tests/unimarc-standards.unit.php
 */

$failed = 0;
$passed = 0;

$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        ++$passed;
        echo "  OK   {$label}\n";
    } else {
        ++$failed;
        echo "  FAIL {$label}\n";
    }
};

$oai = file_get_contents(__DIR__ . '/../storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php');
$sruFormatter = file_get_contents(__DIR__ . '/../storage/plugins/z39-server/classes/UNIMARCXMLFormatter.php');
$oaiE2e = file_get_contents(__DIR__ . '/oai-pmh-server.spec.js');
$directE2e = file_get_contents(__DIR__ . '/unimarc-export.spec.js');
$sruE2e = file_get_contents(__DIR__ . '/sru-unimarcxml.spec.js');

echo "UNIMARC XML standards alignment:\n";

$marcxNs = 'info:lc/xmlns/marcxchange-v2';
$marcxSchema = 'http://www.loc.gov/standards/iso25577/marcxchange-2-0.xsd';

$check($oai !== false && str_contains($oai, "private const NS_MARCXCHANGE = '{$marcxNs}'"), 'OAI-PMH plugin defines the MARCXchange namespace for UNIMARC');
$check($oai !== false && str_contains($oai, "private const SCHEMA_MARCXCHANGE = '{$marcxSchema}'"), 'OAI-PMH plugin defines the MARCXchange 2.0 schema URL');
$check(
    $oai !== false
    && preg_match(
        "/'prefix'\\s*=>\\s*'unimarc'.*?'schema'\\s*=>\\s*self::SCHEMA_MARCXCHANGE.*?'namespace'\\s*=>\\s*self::NS_MARCXCHANGE/s",
        $oai
    ) === 1,
    'OAI-PMH ListMetadataFormats advertises MARCXchange for metadataPrefix=unimarc'
);
$check(
    $oai !== false
    && str_contains($oai, "\$xw->startElementNs(null, 'record', self::NS_MARCXCHANGE);")
    && str_contains($oai, "\$xw->writeAttribute('type', 'Bibliographic');"),
    'OAI-PMH/direct UNIMARC XML records are emitted as MARCXchange Bibliographic records'
);
$check(
    $sruFormatter !== false
    && str_contains($sruFormatter, "\$recordEl = \$this->doc->createElementNS(self::NS_MARCXCHANGE, 'record');")
    && str_contains($sruFormatter, "\$recordEl->setAttribute('type', 'Bibliographic');"),
    'SRU UNIMARC XML records are emitted as MARCXchange Bibliographic records'
);
$check(
    $oaiE2e !== false
    && $directE2e !== false
    && $sruE2e !== false
    && str_contains($oaiE2e, $marcxNs)
    && str_contains($directE2e, $marcxNs)
    && str_contains($sruE2e, $marcxNs),
    'E2E coverage expects MARCXchange namespace for OAI, direct export, and SRU UNIMARC XML'
);

echo "\nSRU record formatters (behaviour):\n";

require_once __DIR__ . '/../vendor/autoload.php';
foreach (['RecordFormatter', 'MARCXMLFormatter', 'UNIMARCXMLFormatter', 'UnimarcLibriParser'] as $class) {
    require_once __DIR__ . '/../storage/plugins/z39-server/classes/' . $class . '.php';
}

$book = [
    'id' => 9001,
    'titolo' => 'Il nome della rosa',
    'sottotitolo' => 'Romanzo',
    'lingua' => 'inglese',
    'anno_pubblicazione' => '1980',
    'contributors' => [
        ['nome' => 'Umberto Eco', 'ruolo' => 'principale'],
        ['nome' => 'Omero', 'ruolo' => 'co-autore'],
        ['nome' => 'Lewis Carroll (Charles Dodgson)', 'ruolo' => 'traduttore'],
    ],
];
$renderXpath = static function (string $class, array $record): DOMXPath {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->appendChild((new $class($doc))->format($record));
    return new DOMXPath($doc);
};

// MARC 21: no ISBD punctuation is emitted, so leader/18 must not claim it.
$marc = $renderXpath(\Z39Server\MARCXMLFormatter::class, $book);
$marcLeader = (string) $marc->evaluate('string(//*[local-name()="leader"])');
$check(strlen($marcLeader) === 24 && $marcLeader[18] === ' ', "MARCXML leader/18 is ' ' (non-ISBD) — got '{$marcLeader}'");
$check((string) $marc->evaluate('string(//*[local-name()="datafield"][@tag="245"]/*[@code="a"])') === 'Il nome della rosa', 'MARCXML 245 $a carries no trailing ISBD punctuation');

$uni = $renderXpath(\Z39Server\UNIMARCXMLFormatter::class, $book);
$label = (string) $uni->evaluate('string(//*[local-name()="leader"])');
$check(strlen($label) === 24 && $label[9] === ' ' && $label[18] === ' ', "UNIMARC label positions 9 and 18 are blank — got '{$label}'");
$f100 = (string) $uni->evaluate('string(//*[local-name()="controlfield"][@tag="100"])');
$installLang = strtolower(substr(\App\Support\I18n::getInstallationLocale(), 0, 2));
$expectedCatLang = ['it' => 'ita', 'en' => 'eng', 'de' => 'ger', 'fr' => 'fre', 'da' => 'dan'][$installLang] ?? 'und';
$expectedCountry = ['it' => 'IT', 'en' => 'GB', 'de' => 'DE', 'fr' => 'FR', 'da' => 'DK'][$installLang] ?? 'XX';
$check(strlen($f100) === 36 && substr($f100, 22, 3) === $expectedCatLang, "UNIMARC 100/22-24 is the cataloguing language ({$expectedCatLang}), not the document language");
$check((string) $uni->evaluate('string(//*[local-name()="datafield"][@tag="101"]/*[@code="a"])') === 'eng', 'UNIMARC 101 $a keeps the document language');
$check((string) $uni->evaluate('string(//*[local-name()="datafield"][@tag="102"]/*[@code="a"])') === $expectedCountry, "UNIMARC 102 \$a follows the install locale ({$expectedCountry})");
$eco = $uni->query('//*[local-name()="datafield"][@tag="700"]')->item(0);
$check(
    $eco instanceof DOMElement && $eco->getAttribute('ind2') === '1'
    && (string) $uni->evaluate('string(*[@code="a"])', $eco) === 'Eco'
    && (string) $uni->evaluate('string(*[@code="b"])', $eco) === 'Umberto',
    'UNIMARC 700 enters "Umberto Eco" under the surname: ind2 1, $a Eco $b Umberto'
);
$omero = $uni->query('//*[local-name()="datafield"][@tag="701"]')->item(0);
$check(
    $omero instanceof DOMElement && $omero->getAttribute('ind2') === '0'
    && (string) $uni->evaluate('string(*[@code="a"])', $omero) === 'Omero'
    && $uni->query('*[@code="b"]', $omero)->length === 0,
    'a single-word name is direct order: ind2 0, whole name in $a'
);

// Round trip: export → import gives back the original names.
$exported = (new \Z39Server\UnimarcLibriParser())->toUnimarcXml($book);
$imported = (new \Z39Server\UnimarcLibriParser())->fromUnimarcXml($exported);
$names = array_map(static fn(array $a): string => $a['name'], $imported['authors'] ?? []);
$check(
    $names === ['Umberto Eco', 'Omero', 'Lewis Carroll (Charles Dodgson)'],
    'UNIMARC export → import round-trips every name unchanged (got ' . json_encode($names, JSON_UNESCAPED_UNICODE) . ')'
);

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
