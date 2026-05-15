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

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
