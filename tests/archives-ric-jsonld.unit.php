<?php
declare(strict_types=1);

/**
 * Unit tests for Archives RiC-O JSON-LD export (issue #122).
 *
 * Scope: pure builder behavior, no database or HTTP server required.
 * Run:
 *   php tests/archives-ric-jsonld.unit.php
 */

require_once __DIR__ . '/../storage/plugins/archives/RicJsonLdBuilder.php';

use App\Plugins\Archives\RicJsonLdBuilder;

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

$base = 'https://archivio.example.test';
$builder = new RicJsonLdBuilder($base, 'it_IT');

echo "RiC-O unit export:\n";
$unit = $builder->buildUnit([
    'id' => '10',
    'parent_id' => '3',
    'level' => 'fonds',
    'reference_code' => 'IT-TEST-FONDO-001',
    'constructed_title' => 'Fondo Rossi',
    'formal_title' => '',
    'scope_content' => 'Carteggio familiare e fotografie.',
    'extent' => '12 fascicoli',
    'archival_history' => 'Conservato presso la sede storica.',
    'physical_location' => 'Archivio comunale, deposito A',
    'language_codes' => 'ita; lat, eng',
    'date_start' => '850',
    'date_end' => '-12',
    'rights_statement_url' => 'https://rightsstatements.org/vocab/InC/1.0/',
    'ark_identifier' => 'ark:/12345/test',
], [
    [
        'id' => '7',
        'type' => 'person',
        'authorised_form' => 'Rossi, Maria',
        'dates_of_existence' => '1880-1945',
        'role' => 'creator',
    ],
], [
    ['id' => '11', 'level' => 'series', 'constructed_title' => 'Corrispondenza', 'formal_title' => ''],
    ['id' => '12', 'level' => 'file', 'constructed_title' => '', 'formal_title' => ''],
]);

$check($unit['@id'] === $base . '/archives/10', 'unit @id uses canonical archival resource IRI');
$check($unit['@type'] === 'ric:RecordSet', 'fonds maps to ric:RecordSet');
$check(($unit['rdfs:label']['@language'] ?? null) === 'it', 'unit label uses BCP-47 Italian tag');
$check($unit['ric:language'] === ['ita', 'lat', 'eng'], 'language_codes splits semicolon and comma lists');
$date = $unit['ric:isAssociatedWithDate'] ?? [];
$check(($date['ric:hasBeginningDate']['@value'] ?? null) === '0850', 'positive pre-1000 years are valid zero-padded xsd:gYear');
$check(($date['ric:hasEndDate']['@value'] ?? null) === '-0012', 'BCE years are preserved as negative xsd:gYear');
$check(($unit['ric:isOrWasRegulatedBy']['owl:sameAs']['@id'] ?? null) === 'https://rightsstatements.org/vocab/InC/1.0/', 'rights statement is emitted as JSON-LD IRI object');
$check($unit['ric:isOrWasIncludedIn']['@id'] === $base . '/archives/3', 'parent is linked through ric:isOrWasIncludedIn');
$parts = $unit['ric:hasOrHadPart'] ?? [];
$check(count($parts) === 2, 'direct children are referenced as bounded part list');
$check(($parts[0]['rdfs:label'] ?? null) === 'Corrispondenza', 'child title is emitted when available');
$check(!array_key_exists('rdfs:label', $parts[1]), 'empty child titles do not emit empty rdfs:label');
$relation = $unit['ric:isOrWasRelatedTo'][0] ?? [];
$check(($relation['@id'] ?? null) === $base . '/archives/relations/10-7-creator', 'relation IRI is deterministic by unit, agent, role');
$check(($relation['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/agents/7', 'unit export relation source is the agent');
$check(($relation['ric:relationHasTarget']['@id'] ?? null) === $base . '/archives/10', 'unit export relation target is the archival unit');
$check(($relation['ric:relationType'] ?? null) === 'ric:isCreatorOf', 'creator role maps to ric:isCreatorOf');
$seeAlsoIds = array_map(static fn (array $node): string => (string) ($node['@id'] ?? ''), $unit['rdfs:seeAlso']);
$check(in_array($base . '/archives/10/manifest.json', $seeAlsoIds, true), 'unit seeAlso advertises IIIF manifest');
$check(in_array('https://n2t.net/ark:/12345/test', $seeAlsoIds, true), 'unit seeAlso advertises ARK resolver URL');

echo "\nRiC-O authority export:\n";
$authority = $builder->buildAuthority([
    'id' => '7',
    'type' => 'person',
    'authorised_form' => 'Rossi, Maria',
    'parallel_forms' => 'Maria Rossi',
    'other_forms' => 'M. Rossi',
    'dates_of_existence' => '1880-1945',
    'history' => 'Fotografa e archivista.',
    'functions' => 'Produzione fotografica',
    'places' => 'Torino',
], [
    [
        'id' => '10',
        'reference_code' => 'IT-TEST-FONDO-001',
        'level' => 'fonds',
        'constructed_title' => 'Fondo Rossi',
        'formal_title' => '',
        'role' => 'creator',
    ],
    [
        'id' => '20',
        'reference_code' => 'IT-TEST-FONDO-EMPTY',
        'level' => 'file',
        'constructed_title' => '',
        'formal_title' => '',
        'role' => 'associated',
    ],
], [
    'https://viaf.org/viaf/123',
    'javascript:alert(1)',
    'https://viaf.org/viaf/123',
    $base . '/archives/agents/7',
    'urn:isni:0000000121032683',
]);

$check($authority['@type'] === 'ric:Person', 'person authority maps to ric:Person');
$check(($authority['rdfs:label']['@language'] ?? null) === 'it', 'authority label uses Italian language tag');
$check(count($authority['ric:hasOrHadName']) === 2, 'parallel and other forms become ric:Name nodes');
$sameAs = $authority['owl:sameAs'] ?? [];
$check(is_array($sameAs) && array_is_list($sameAs), 'multiple owl:sameAs values are emitted as a list');
$sameAsIds = array_map(static fn (array $node): string => (string) ($node['@id'] ?? ''), $sameAs);
$check($sameAsIds === ['https://viaf.org/viaf/123', 'urn:isni:0000000121032683'], 'sameAs filters unsafe/self URIs and deduplicates while preserving valid IRIs');
$authRelation = $authority['ric:isOrWasRelatedTo'][0] ?? [];
$check(($authRelation['@id'] ?? null) === $relation['@id'], 'authority and unit exports converge on the same relation IRI');
$check(($authRelation['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/agents/7', 'authority export relation source is the agent');
$check(($authRelation['ric:relationHasTarget']['@id'] ?? null) === $base . '/archives/10', 'authority export relation target is the archival unit');
$emptyTarget = $authority['ric:isOrWasRelatedTo'][1]['ric:relationHasTarget'] ?? [];
$check(($emptyTarget['@id'] ?? null) === $base . '/archives/20', 'authority relation target keeps @id when title is empty');
$check(!array_key_exists('rdfs:label', $emptyTarget), 'authority relation target omits empty rdfs:label');

echo "\nRiC-O collection export:\n";
$collection = $builder->buildCollection([
    ['id' => '10', 'level' => 'fonds', 'constructed_title' => 'Fondo Rossi', 'formal_title' => ''],
    ['id' => '0', 'level' => 'fonds', 'constructed_title' => 'Invalid row', 'formal_title' => ''],
    ['id' => '20', 'level' => 'fonds', 'constructed_title' => '', 'formal_title' => ''],
]);
$check(($collection['rdfs:label']['@language'] ?? null) === 'en', 'collection hardcoded English label is tagged as English');
$check(is_string($collection['ric:title'] ?? null), 'collection ric:title remains an untagged plain string');
$check(count($collection['ric:hasOrHadPart']) === 2, 'collection skips invalid root unit IDs');
$check(!array_key_exists('rdfs:label', $collection['ric:hasOrHadPart'][1]), 'collection omits empty part labels');

// ── URI scheme allow-list (isValidLodUri) ────────────────────────────
// adamsreview F005 + CodeRabbit R3: only the safe Linked Data schemes
// should propagate to public output; everything else (javascript:,
// data:, file:, control-char-injected URIs, no-scheme strings) must
// be dropped. We exercise the private helper via Reflection so the
// allow-list cannot drift without a failing test.

echo "\nURI scheme allow-list:\n";
$ric    = new \ReflectionClass(\App\Plugins\Archives\RicJsonLdBuilder::class);
$isValid = $ric->getMethod('isValidLodUri');
$isValid->setAccessible(true);

$uriCases = [
    // Positive — every scheme in ALLOWED_URI_SCHEMES must pass.
    ['https://viaf.org/viaf/29539',        true,  'https VIAF URI is accepted'],
    ['http://example.org/foo',             true,  'plain http URI is accepted'],
    ['urn:isni:0000000121234567',          true,  'urn: scheme is accepted'],
    ['ark:/12345/abc',                     true,  'ark: scheme is accepted'],
    ['info:lc/authorities/n79006044',      true,  'info: scheme is accepted'],
    ['doi:10.1234/example',                true,  'doi: scheme is accepted'],
    ['HTTPS://CASE.example.org/foo',       true,  'scheme matching is case-insensitive'],
    [' https://trim.example.org/foo ',     true,  'leading/trailing whitespace is trimmed'],
    // Negative — every other scheme must be rejected.
    ['javascript:alert(1)',                false, 'javascript: scheme is rejected'],
    ['data:text/html,<script>',            false, 'data: scheme is rejected'],
    ['file:///etc/passwd',                 false, 'file: scheme is rejected'],
    ['no-scheme-at-all',                   false, 'scheme-less string is rejected'],
    ['',                                   false, 'empty string is rejected'],
    ["https://ok.example.org/\r\nX:Y",     false, 'CR/LF in URI is rejected (header-injection guard)'],
    ["https://ok.example.org/\x00null",    false, 'NUL byte in URI is rejected'],
    ["https://ok.example.org/\x1F",        false, 'unit-separator byte in URI is rejected'],
];

foreach ($uriCases as [$uri, $expected, $label]) {
    $check($isValid->invoke(null, $uri) === $expected, $label);
}

// ── xsd:gYear formatting (formatGYear via buildDateRange) ────────────
// adamsreview F008: the date emitter must zero-pad to 4 digits and
// support BCE via a leading `-`. (string) cast on a SMALLINT would
// emit "850" or "-100" which are not valid xsd:gYear literals.

echo "\nxsd:gYear formatting:\n";
$buildDateRange = $ric->getMethod('buildDateRange');
$buildDateRange->setAccessible(true);

// Helper that returns the two formatted gYear strings (begin, end).
$gyear = static function (?int $start, ?int $end) use ($builder, $buildDateRange): array {
    $node = $buildDateRange->invoke($builder, $start, $end);
    return [
        $node['ric:hasBeginningDate']['@value'] ?? null,
        $node['ric:hasEndDate']['@value'] ?? null,
        $node === null ? null : true,
    ];
};

// Both endpoints null → no DateRange node at all.
$nullNode = $buildDateRange->invoke($builder, null, null);
$check($nullNode === null, 'no DateRange when both dates are null');

// Year 0 (legal xsd:gYear) — preserved as "0000".
[$b, $e] = $gyear(0, null);
$check($b === '0000', 'year 0 is zero-padded to "0000"');

// 4-digit year — unchanged.
[$b, $e] = $gyear(1922, 1978);
$check($b === '1922' && $e === '1978', '4-digit years pass through unchanged');

// Year < 1000 — zero-padded.
[$b, $e] = $gyear(850, 999);
$check($b === '0850' && $e === '0999', 'years < 1000 are zero-padded to 4 digits');

// BCE — negative SMALLINT values emerge as "-YYYY".
[$b, $e] = $gyear(-44, -27);
$check($b === '-0044' && $e === '-0027', 'BCE years emit a leading "-" with zero padding');

// Asymmetric: only start, only end.
$onlyStart = $buildDateRange->invoke($builder, 1500, null);
$check(($onlyStart['ric:hasBeginningDate']['@value'] ?? null) === '1500'
    && !array_key_exists('ric:hasEndDate', $onlyStart),
    'DateRange with only a begin date omits ric:hasEndDate');

$onlyEnd = $buildDateRange->invoke($builder, null, 1999);
$check(!array_key_exists('ric:hasBeginningDate', $onlyEnd)
    && ($onlyEnd['ric:hasEndDate']['@value'] ?? null) === '1999',
    'DateRange with only an end date omits ric:hasBeginningDate');

// Both gYear literals must declare @type xsd:gYear so RDF consumers
// don't misinterpret them as xsd:string.
$typed = $buildDateRange->invoke($builder, 1898, 1978);
$check(($typed['ric:hasBeginningDate']['@type'] ?? null) === 'xsd:gYear', 'begin date carries xsd:gYear @type');
$check(($typed['ric:hasEndDate']['@type']       ?? null) === 'xsd:gYear', 'end date carries xsd:gYear @type');

echo "\n================================\n";
echo "RiC-O JSON-LD checks passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
