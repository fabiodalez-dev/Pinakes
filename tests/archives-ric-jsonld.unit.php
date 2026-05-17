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

// ── ARK identifier sanitiser (sanitizeArkIdentifier) ─────────────────
// CodeRabbit R5: the ark_identifier column is a free-form VARCHAR(255)
// interpolated into `https://n2t.net/{ark}` — a stored value with
// whitespace, control characters, an absolute URL prefix, or a `../`
// escape would either produce a malformed n2t.net URL or, if the IRI
// later flows into an HTTP Link header, expose a header-injection
// surface. The sanitiser normalises canonical-form ARKs and rejects
// every other shape.

echo "\nARK identifier sanitiser:\n";
$sanitizeArk = $ric->getMethod('sanitizeArkIdentifier');
$sanitizeArk->setAccessible(true);

$arkCases = [
    // Canonical form already prefixed — pass through.
    ['ark:/12345/abc',              'ark:/12345/abc',  'canonical ark:/NAAN/Name accepted as-is'],
    ['ark:/99166/w6c34s1z',         'ark:/99166/w6c34s1z',  'real-world SNAC ARK accepted'],
    // Bare NAAN/Name form — prefix is added.
    ['12345/abc',                   'ark:/12345/abc',  'bare NAAN/Name form gets ark:/ prefix'],
    // Trim whitespace edges.
    [' ark:/12345/abc ',            'ark:/12345/abc',  'leading/trailing whitespace is trimmed'],
    // Rejections.
    ['',                            null, 'empty input is rejected'],
    [' ',                           null, 'whitespace-only input is rejected'],
    ['ark:/1234/abc',               null, 'NAAN < 5 digits is rejected'],
    ['ark:/abcde/foo',              null, 'non-digit NAAN is rejected'],
    ['12/abc',                      null, 'bare form with NAAN < 5 digits is rejected'],
    ['random-string',               null, 'unstructured string is rejected'],
    ['https://attacker.tld/ark/123', null, 'absolute URL is rejected (no scheme injection)'],
    ['/etc/passwd',                 null, 'leading-slash path is rejected'],
    ['../../escape',                null, 'path-traversal sequence is rejected'],
    ["ark:/12345/foo\r\nX:Y",       null, 'CR/LF inside ARK is rejected (header-injection guard)'],
    ["ark:/12345/foo\x00bar",       null, 'NUL byte inside ARK is rejected'],
    ["ark:/12345/foo bar",          null, 'internal whitespace is rejected'],
];

foreach ($arkCases as [$input, $expected, $label]) {
    $got = $sanitizeArk->invoke(null, $input);
    $check($got === $expected, $label);
}

// ── Phase 2 (v0.7.8) — Agents as first-class RiC-CM entities ─────────
// Issue #122 Phase 2: ric_type / birth_date / death_date /
// place_of_origin, multi-scheme owl:sameAs from archive_agent_identifiers,
// and Agent → Agent relations from archive_agent_relations.

echo "\nPhase 2 — ric_type → ric:* class mapping:\n";

$ricTypeAuth = $builder->buildAuthority([
    'id' => 100, 'type' => 'person', 'ric_type' => 'Position',
    'authorised_form' => 'Director of Catania State Archive',
]);
$check(($ricTypeAuth['@type'] ?? null) === 'ric:Position',
    'ric_type=Position overrides ISAAR `type` and emits ric:Position');

$ricTypeAuth2 = $builder->buildAuthority([
    'id' => 101, 'type' => 'corporate', 'ric_type' => 'Group',
    'authorised_form' => 'Editorial board',
]);
$check(($ricTypeAuth2['@type'] ?? null) === 'ric:Group',
    'ric_type=Group emits ric:Group (no ISAAR equivalent)');

// Fallback when ric_type empty — falls back to legacy `type` map.
$legacyAuth = $builder->buildAuthority([
    'id' => 102, 'type' => 'family', 'ric_type' => '',
    'authorised_form' => 'Famiglia Verga',
]);
$check(($legacyAuth['@type'] ?? null) === 'ric:Family',
    'empty ric_type falls back to AUTHORITY_TYPE_TO_RIC[type]');

echo "\nPhase 2 — birth_date / death_date / place_of_origin:\n";

$datedAuth = $builder->buildAuthority([
    'id' => 200, 'type' => 'person', 'ric_type' => 'Person',
    'authorised_form' => 'Verga, Giovanni',
    'birth_date' => '1840-08-31',
    'death_date' => '1922-01-27',
    'place_of_origin' => 'Catania',
    'dates_of_existence' => '1840-1922',
]);
$check(($datedAuth['ric:beginningDate']['@value'] ?? null) === '1840-08-31',
    'birth_date populates ric:beginningDate with xsd:date value');
$check(($datedAuth['ric:beginningDate']['@type'] ?? null) === 'xsd:date',
    'ric:beginningDate carries xsd:date @type');
$check(($datedAuth['ric:endDate']['@value'] ?? null) === '1922-01-27',
    'death_date populates ric:endDate');
$check(($datedAuth['ric:isOrWasLocatedAt']['@type'] ?? null) === 'ric:Place'
    && ($datedAuth['ric:isOrWasLocatedAt']['rdfs:label'] ?? null) === 'Catania',
    'place_of_origin emits ric:Place node with label');
$check(!array_key_exists('ric:descriptiveNote', $datedAuth),
    'dates_of_existence is suppressed when birth/death dates are structured');

// Backward compat: pre-Phase-2 rows with only dates_of_existence still
// surface via ric:descriptiveNote.
$legacyDates = $builder->buildAuthority([
    'id' => 201, 'type' => 'person',
    'authorised_form' => 'Manzoni, Alessandro',
    'dates_of_existence' => '1785-1873',
]);
$check(($legacyDates['ric:descriptiveNote'] ?? null) === '1785-1873',
    'pre-Phase-2 dates_of_existence preserved as ric:descriptiveNote');
$check(!array_key_exists('ric:beginningDate', $legacyDates),
    'no ric:beginningDate when only legacy dates_of_existence is set');

echo "\nPhase 2 — Agent → Agent relations + agentRelationIri():\n";

$withAgentRels = $builder->buildAuthority(
    ['id' => 300, 'type' => 'corporate', 'ric_type' => 'CorporateBody',
     'authorised_form' => 'Comune di Catania'],
    [],  // no archival units linked
    [],  // no sameAs
    [    // agentRelations
        ['related_id' => 301, 'ric_predicate' => 'ric:isSuccessorOf',
         'qualifier' => 'merger 1927', 'date_start' => '1927-01-01', 'date_end' => ''],
        ['related_id' => 302, 'ric_predicate' => 'ric:isMemberOf',
         'qualifier' => '', 'date_start' => '', 'date_end' => ''],
        // Invalid rows must be skipped, not crash.
        ['related_id' => 0, 'ric_predicate' => 'ric:isMemberOf'],
        ['related_id' => 303, 'ric_predicate' => ''],
    ]
);
$rels = $withAgentRels['ric:isOrWasRelatedTo'] ?? [];
$check(count($rels) === 2, 'invalid agent relation rows are skipped');
$check(($rels[0]['ric:relationType'] ?? null) === 'ric:isSuccessorOf',
    'ric:isSuccessorOf predicate is preserved verbatim');
$check(($rels[0]['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/agents/300',
    'agent relation source is the current agent IRI');
$check(($rels[0]['ric:relationHasTarget']['@id'] ?? null) === $base . '/archives/agents/301',
    'agent relation target is the related agent IRI');
$check(($rels[0]['@id'] ?? null) === $base . '/archives/agent-relations/300-301-ric-issuccessorof',
    'agent relation @id is deterministic by (agentId, relatedId, predicate)');
$check(($rels[0]['ric:descriptiveNote'] ?? null) === 'merger 1927',
    'qualifier becomes ric:descriptiveNote on the relation');
$check(($rels[0]['ric:isAssociatedWithDate']['@type'] ?? null) === 'ric:DateRange',
    'agent relation with date emits ric:DateRange');
$check(($rels[0]['ric:isAssociatedWithDate']['ric:hasBeginningDate']['@value'] ?? null) === '1927-01-01',
    'agent relation date_start populates ric:hasBeginningDate');
$check(!array_key_exists('ric:isAssociatedWithDate', $rels[1]),
    'agent relation without dates omits ric:isAssociatedWithDate');
$check(!array_key_exists('ric:descriptiveNote', $rels[1]),
    'agent relation without qualifier omits ric:descriptiveNote');

// agentRelationIri stability under predicate slugging.
$iri = $builder->agentRelationIri(10, 20, 'ric:isMemberOf');
$check($iri === $base . '/archives/agent-relations/10-20-ric-ismemberof',
    'agentRelationIri slugs the predicate ("ric:" → "ric-", lowercase)');
$iri2 = $builder->agentRelationIri(10, 20, '');
$check($iri2 === $base . '/archives/agent-relations/10-20-rel',
    'agentRelationIri uses "rel" fallback for empty predicate');

// ── Phase 3 (v0.7.9) — Activities as RiC-CM entities ─────────────────
// Issue #122 Phase 3: archive_activities + archive_unit_activities.

echo "\nPhase 3 — buildActivity:\n";

$act = $builder->buildActivity([
    'id' => 5,
    'title' => 'Corrispondenza diplomatica del Prefetto',
    'description' => 'Lettere e dispacci 1890-1920',
    'activity_type' => 'activity',
    'agent_id' => 3,
    'parent_id' => 9,
    'date_start' => '1890',
    'date_end'   => '1920',
    'is_ongoing' => 0,
    'source_ref' => 'RD 9 ottobre 1861 n. 250',
]);
$check(($act['@type'] ?? null) === 'ric:Activity',
    'buildActivity emits @type ric:Activity');
$check(($act['@id'] ?? null) === $base . '/archives/activities/5',
    'activity @id follows /archives/activities/{id} pattern');
$check(($act['rdfs:label']['@value'] ?? null) === 'Corrispondenza diplomatica del Prefetto',
    'activity rdfs:label carries the title with @language tag');
$check(($act['ric:name'] ?? null) === 'Corrispondenza diplomatica del Prefetto',
    'activity ric:name carries the title as plain string');
$check(($act['ric:descriptiveNote'] ?? null) === 'Lettere e dispacci 1890-1920',
    'activity description maps to ric:descriptiveNote');
$check(($act['ric:type'] ?? null) === 'activity',
    'activity_type column surfaces as ric:type literal');
$check(($act['ric:hasSource'] ?? null) === 'RD 9 ottobre 1861 n. 250',
    'source_ref surfaces as ric:hasSource');
$check(($act['ric:isOrWasPerformedBy']['@id'] ?? null) === $base . '/archives/agents/3',
    'agent_id emits ric:isOrWasPerformedBy → agent IRI');
$check(($act['ric:hasOrHadPartOf']['@id'] ?? null) === $base . '/archives/activities/9',
    'parent_id emits ric:hasOrHadPartOf → parent activity IRI');
$check(($act['ric:isAssociatedWithDate']['@type'] ?? null) === 'ric:DateRange',
    'date range emitted as ric:DateRange');
$check(($act['ric:isAssociatedWithDate']['ric:hasBeginningDate']['@type'] ?? null) === 'xsd:date',
    'activity dates carry xsd:date @type');

$actOngoing = $builder->buildActivity([
    'id' => 7, 'title' => 'Ongoing function', 'is_ongoing' => 1,
]);
$check(str_contains((string)($actOngoing['ric:descriptiveNote'] ?? ''), '[ongoing]'),
    'is_ongoing=1 surfaces as [ongoing] note');

// activityIri stability.
$check($builder->activityIri(42) === $base . '/archives/activities/42',
    'activityIri composes /archives/activities/{id}');

// Activity → Unit relations
$actWithUnits = $builder->buildActivity(
    ['id' => 10, 'title' => 'Census 1881', 'activity_type' => 'function'],
    [
        ['unit_id' => 100, 'level' => 'fonds',
         'constructed_title' => 'Fondo Anagrafe', 'formal_title' => '',
         'ric_predicate' => 'ric:resultsOrResultedFrom'],
        ['unit_id' => 101, 'level' => 'series',
         'constructed_title' => '', 'formal_title' => 'Serie atti',
         'ric_predicate' => 'ric:isOrWasUsedBy'],
        // Skipped: bad unit_id.
        ['unit_id' => 0, 'level' => 'file', 'ric_predicate' => 'ric:isSubjectOf'],
        // Default predicate when blank.
        ['unit_id' => 102, 'level' => 'item',
         'constructed_title' => 'Doc isolato', 'ric_predicate' => ''],
    ]
);
$rels = $actWithUnits['ric:isOrWasRelatedTo'] ?? [];
$check(count($rels) === 3, 'invalid unit links are skipped');
$check(($rels[0]['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/activities/10',
    'activity-side relation source is the activity itself');
$check(($rels[0]['ric:relationHasTarget']['@type'] ?? null) === 'ric:RecordSet',
    'activity-side relation target picks the right @type from unit level');
$check(($rels[0]['ric:relationType'] ?? null) === 'ric:resultsOrResultedFrom',
    'first unit link carries the explicit predicate');
$check(($rels[1]['ric:relationHasTarget']['rdfs:label'] ?? null) === 'Serie atti',
    'unit-link target rdfs:label falls back to formal_title when constructed_title is empty');
$check(($rels[2]['ric:relationType'] ?? null) === 'ric:resultsOrResultedFrom',
    'empty predicate defaults to ric:resultsOrResultedFrom');

// Relation @id convergence: unitActivityRelationIri must produce the
// same IRI regardless of which side called it. This is what lets a
// graph merge collapse the unit-side and activity-side emissions to
// a single RDF node.
$fromActivity = $rels[0]['@id'] ?? '';
$fromUnit     = $builder->unitActivityRelationIri(100, 10, 'ric:resultsOrResultedFrom');
$check($fromActivity === $fromUnit,
    'activity-side and unit-side both produce the same relation IRI for the same (unit, activity, predicate) triple');
$check(str_ends_with($fromActivity, '100-10-ric-resultsorresultedfrom'),
    'unit-activity relation IRI slug is unit-activity-predicate (deterministic)');

echo "\nPhase 3 — buildUnit gains activity relations:\n";

$unitWithActs = $builder->buildUnit(
    ['id' => 200, 'level' => 'fonds', 'constructed_title' => 'Fondo Verga'],
    [],  // no authorities
    [],  // no children
    [
        ['activity_id' => 5, 'ric_predicate' => 'ric:resultsOrResultedFrom',
         'title' => 'Catalogo manoscritti', 'activity_type' => 'function'],
        ['activity_id' => 0, 'ric_predicate' => 'ric:isOrWasUsedBy'],  // skipped
    ]
);
$rels = $unitWithActs['ric:isOrWasRelatedTo'] ?? [];
$check(count($rels) === 1, 'unit emits one activity relation when one valid link given');
$check(($rels[0]['ric:relationHasTarget']['@type'] ?? null) === 'ric:Activity',
    'unit→activity relation target is @type ric:Activity');
$check(($rels[0]['ric:relationHasTarget']['rdfs:label'] ?? null) === 'Catalogo manoscritti',
    'activity title appears as rdfs:label on the relation target');

// ── Phase 4 (v0.7.10) — Places + polymorphic Relations graph ────────
// Issue #122 Phase 4: archive_places + archive_relations.

echo "\nPhase 4 — buildPlace:\n";

$place = $builder->buildPlace([
    'id' => 7,
    'name' => 'Catania',
    'place_type' => 'municipality',
    'latitude'  => '37.50213',
    'longitude' => '15.08719',
    'geonames_id' => '2525068',
    'wikidata_id' => 'Q40218',
    'tgn_id' => '7008168',
    'description' => 'Capoluogo della provincia di Catania',
    'parent_id' => 2,
    'date_start' => '',
    'date_end' => '',
]);
$check(($place['@type'] ?? null) === 'ric:Place',
    'buildPlace emits @type ric:Place');
$check(($place['@id'] ?? null) === $base . '/archives/places/7',
    'place @id follows /archives/places/{id} pattern');
$check(($place['rdfs:label']['@value'] ?? null) === 'Catania'
    && ($place['rdfs:label']['@language'] ?? null) === 'it',
    'place rdfs:label carries name with installation @language');
$check(($place['ric:type'] ?? null) === 'municipality',
    'place_type column surfaces as ric:type');
$check(($place['ric:descriptiveNote'] ?? null) === 'Capoluogo della provincia di Catania',
    'description maps to ric:descriptiveNote');
$check(($place['ric:hasOrHadCoordinate']['ric:latitude'] ?? null) === 37.50213
    && ($place['ric:hasOrHadCoordinate']['ric:longitude'] ?? null) === 15.08719,
    'lat/lng produce ric:CoordinateLocation with float values');
$sameAs = $place['owl:sameAs'] ?? [];
$check(is_array($sameAs) && count($sameAs) === 3,
    'GeoNames + Wikidata + TGN all emit as owl:sameAs entries');
$check(($sameAs[0]['@id'] ?? null) === 'https://www.geonames.org/2525068',
    'GeoNames id composes the canonical https://www.geonames.org/ID URI');
$check(($sameAs[1]['@id'] ?? null) === 'https://www.wikidata.org/entity/Q40218',
    'Wikidata id composes the canonical wikidata entity URI');
$check(str_starts_with($sameAs[2]['@id'] ?? '', 'http://vocab.getty.edu/page/tgn/'),
    'TGN id composes the Getty TGN URI');
$check(($place['ric:isOrWasIncludedIn']['@id'] ?? null) === $base . '/archives/places/2',
    'parent_id emits ric:isOrWasIncludedIn → parent place IRI');

// Single-source sameAs degrades to a node, not a single-element array.
$placeOneRef = $builder->buildPlace([
    'id' => 8, 'name' => 'Italia', 'place_type' => 'country',
    'wikidata_id' => 'Q38',
]);
$check(is_array($placeOneRef['owl:sameAs']) && isset($placeOneRef['owl:sameAs']['@id']),
    'single sameAs entry collapses from list to single node');

// Historical place with date range.
$placeHist = $builder->buildPlace([
    'id' => 9, 'name' => 'Regno delle Due Sicilie',
    'place_type' => 'country',
    'date_start' => '1816', 'date_end' => '1861',
]);
$check(($placeHist['ric:isAssociatedWithDate']['@type'] ?? null) === 'ric:DateRange',
    'historical place emits ric:DateRange');

// placeIri helper.
$check($builder->placeIri(42) === $base . '/archives/places/42',
    'placeIri composes /archives/places/{id}');

echo "\nPhase 4 — buildRelationNode (polymorphic):\n";

// Unit → Place: ric:isOrWasLocatedAt
$relRow = $builder->buildRelationNode([
    'id' => 10,
    'source_type' => 'archival_unit',  'source_id' => 100,
    'target_type' => 'archive_place',  'target_id' => 7,
    'ric_predicate' => 'ric:isOrWasLocatedAt',
    'qualifier' => 'Conservato presso Archivio di Stato',
    'certainty' => 'certain',
]);
$check($relRow !== null, 'valid archive_relations row produces a node');
$check(($relRow['@type'] ?? null) === 'ric:Relation',
    'relation node @type is ric:Relation');
$check(($relRow['@id'] ?? null) === $base . '/archives/relations/10',
    'relation @id uses the row id (/archives/relations/{id})');
$check(($relRow['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/100',
    'source IRI resolves via iriForEntity (archival_unit → /archives/{id})');
$check(($relRow['ric:relationHasTarget']['@id'] ?? null) === $base . '/archives/places/7',
    'target IRI resolves via iriForEntity (archive_place → /archives/places/{id})');
$check(($relRow['ric:descriptiveNote'] ?? null) === 'Conservato presso Archivio di Stato',
    'qualifier becomes ric:descriptiveNote');
$check(!array_key_exists('ric:certainty', $relRow),
    'certainty=certain is the default — omitted from output');

// Agent → Place with uncertain certainty + source citation.
$relAgent = $builder->buildRelationNode([
    'id' => 11,
    'source_type' => 'authority_record', 'source_id' => 4,
    'target_type' => 'archive_place',    'target_id' => 7,
    'ric_predicate' => 'ric:isOrWasResidentAt',
    'certainty' => 'uncertain',
    'source_ref' => 'Atti notarili 1850',
    'date_start' => '1850-01-01', 'date_end' => '1860-12-31',
]);
$check(($relAgent['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/agents/4',
    'authority_record source resolves to /archives/agents/{id}');
$check(($relAgent['ric:certainty'] ?? null) === 'uncertain',
    'non-default certainty is surfaced in the JSON-LD');
$check(($relAgent['ric:hasSource'] ?? null) === 'Atti notarili 1850',
    'source_ref maps to ric:hasSource');
$check(($relAgent['ric:isAssociatedWithDate']['@type'] ?? null) === 'ric:DateRange',
    'relation with date emits ric:DateRange');

// Activity → Place
$relAct = $builder->buildRelationNode([
    'id' => 12,
    'source_type' => 'archive_activity', 'source_id' => 1,
    'target_type' => 'archive_place',    'target_id' => 7,
    'ric_predicate' => 'ric:isOrWasPerformedAt',
]);
$check(($relAct['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/activities/1',
    'archive_activity source resolves to /archives/activities/{id}');

// Place → Place (parent hierarchy via relations)
$relPlace = $builder->buildRelationNode([
    'id' => 13,
    'source_type' => 'archive_place', 'source_id' => 7,
    'target_type' => 'archive_place', 'target_id' => 2,
    'ric_predicate' => 'ric:isOrWasIncludedIn',
]);
$check(($relPlace['ric:relationHasSource']['@id'] ?? null) === $base . '/archives/places/7'
    && ($relPlace['ric:relationHasTarget']['@id'] ?? null) === $base . '/archives/places/2',
    'archive_place ↔ archive_place relation resolves both endpoints');

// Malformed rows return null.
$check($builder->buildRelationNode([]) === null,
    'empty row returns null (no malformed RDF emitted)');
$check($builder->buildRelationNode([
    'source_type' => 'unknown_type', 'source_id' => 1,
    'target_type' => 'archive_place', 'target_id' => 1,
    'ric_predicate' => 'ric:isOrWasLocatedAt',
]) === null,
    'unknown source_type returns null');
$check($builder->buildRelationNode([
    'source_type' => 'archival_unit', 'source_id' => 0,
    'target_type' => 'archive_place', 'target_id' => 1,
    'ric_predicate' => 'ric:isOrWasLocatedAt',
]) === null,
    'zero source_id returns null');
$check($builder->buildRelationNode([
    'source_type' => 'archival_unit', 'source_id' => 1,
    'target_type' => 'archive_place', 'target_id' => 1,
    'ric_predicate' => '',
]) === null,
    'empty predicate returns null');

// ─────────────────────────────────────────────────────────────────────
// Phase 6 — RDF/XML serializer (OAI-PMH metadataPrefix=ric-o)
// ─────────────────────────────────────────────────────────────────────

$serializeUnit = function (array $unit, array $authorities = [], array $children = []) use ($builder): string {
    $doc = $builder->buildUnit($unit, $authorities, $children);
    $xw  = new \XMLWriter();
    $xw->openMemory();
    $xw->startElement('rdf:RDF');
    $xw->writeAttributeNs('xmlns', 'rdf',  null, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
    $xw->writeAttributeNs('xmlns', 'rdfs', null, RicJsonLdBuilder::NS_RDFS);
    $xw->writeAttributeNs('xmlns', 'xsd',  null, RicJsonLdBuilder::NS_XSD);
    $xw->writeAttributeNs('xmlns', 'owl',  null, RicJsonLdBuilder::NS_OWL);
    $xw->writeAttributeNs('xmlns', 'ric',  null, RicJsonLdBuilder::NS_RIC);
    $builder->serializeToRdfXml($doc, $xw);
    $xw->endElement();
    return $xw->outputMemory();
};

$xmlUnit = $serializeUnit([
    'id' => 42, 'level' => 'file', 'reference_code' => 'AS-FS-1', 'language_codes' => 'it',
    'constructed_title' => 'Fascicolo personale',
    'date_start' => 1850, 'date_end' => 1899,
]);
$check(strpos($xmlUnit, 'rdf:about="' . $base . '/archives/42"') !== false,
    'RDF/XML root has rdf:about pointing to unit IRI');
$check(strpos($xmlUnit, '<ric:Record') !== false,
    'RDF/XML root element is ric:Record (mapped from level=file)');
$check(strpos($xmlUnit, '<ric:title>Fascicolo personale</ric:title>') !== false,
    'ric:title emitted as plain literal property element');
$check(strpos($xmlUnit, '<ric:identifier>AS-FS-1</ric:identifier>') !== false,
    'ric:identifier emitted from reference_code');
$check(strpos($xmlUnit, '<rdfs:label xml:lang="it">Fascicolo personale</rdfs:label>') !== false,
    'rdfs:label literal carries xml:lang attribute (BCP-47 short form)');
$check(strpos($xmlUnit, '<ric:DateRange>') !== false
    && strpos($xmlUnit, 'rdf:datatype="' . RicJsonLdBuilder::NS_XSD . 'gYear"') !== false,
    'DateRange nested resource with xsd:gYear typed literals');

// Reference (parent) emitted as rdf:resource attribute, not nested literal.
$xmlChild = $serializeUnit([
    'id' => 43, 'level' => 'series', 'parent_id' => 42, 'constructed_title' => 'Fondo XYZ',
]);
$check(strpos($xmlChild, '<ric:isOrWasIncludedIn rdf:resource="' . $base . '/archives/42"') !== false,
    'unit reference emits rdf:resource (not rdf:about/nested)');

// Agent relation produces nested ric:Relation subject with embedded Agent.
$xmlWithAgent = $serializeUnit(
    ['id' => 44, 'level' => 'item', 'constructed_title' => 'Lettera'],
    [['id' => 7, 'type' => 'person', 'authorised_form' => 'Manzoni, Alessandro', 'role' => 'creator']]
);
$check(strpos($xmlWithAgent, '<ric:Relation') !== false,
    'agent link emits nested <ric:Relation> subject');
$check(strpos($xmlWithAgent, '<ric:Person') !== false
    && strpos($xmlWithAgent, 'rdf:about="' . $base . '/archives/agents/7"') !== false,
    'agent embedded as <ric:Person> with rdf:about');

// Unknown CURIE prefix returns empty URI so the writer can fall back.
$reflection = new \ReflectionClass($builder);
$method = $reflection->getMethod('expandCurie');
$method->setAccessible(true);
$ret = $method->invoke($builder, 'unknownprefix:Foo', []);
$check($ret[1] === 'Foo' && $ret[0]['uri'] === '',
    'unknown prefix → empty namespace URI (falls back to rdf:Description on emit)');

// ── F010 / F011 / F017: RDF/XML serializer hardening ────────────────
// Exercise the private writers directly so we can verify the boolean /
// integer / float datatype emission, the typed-reference compact form,
// and the `@id === '0'` survival without depending on a database row.
$writeProp    = $reflection->getMethod('writeRdfProperty');
$writeProp->setAccessible(true);
$writeSubject = $reflection->getMethod('writeRdfSubject');
$writeSubject->setAccessible(true);

$renderProp = static function (string $tag, mixed $value) use ($builder, $writeProp): string {
    $xw = new \XMLWriter();
    $xw->openMemory();
    $xw->startElement('rdf:RDF');
    $xw->writeAttributeNs('xmlns', 'rdf', null, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
    $xw->writeAttributeNs('xmlns', 'ric', null, RicJsonLdBuilder::NS_RIC);
    $xw->writeAttributeNs('xmlns', 'xsd', null, RicJsonLdBuilder::NS_XSD);
    $ctx = ['ric' => RicJsonLdBuilder::NS_RIC, 'xsd' => RicJsonLdBuilder::NS_XSD];
    $writeProp->invoke($builder, $xw, $tag, $value, $ctx);
    $xw->endElement();
    return $xw->outputMemory();
};

// F010: bool false must NOT become an empty string element. It must
// carry rdf:datatype="xsd:boolean" with literal "false".
$xmlBoolFalse = $renderProp('ric:isDigital', false);
$check(strpos($xmlBoolFalse, 'rdf:datatype="' . RicJsonLdBuilder::NS_XSD . 'boolean"') !== false
    && strpos($xmlBoolFalse, '>false<') !== false,
    'F010: boolean false serialises as xsd:boolean "false" (not empty literal)');

$xmlBoolTrue = $renderProp('ric:isDigital', true);
$check(strpos($xmlBoolTrue, 'rdf:datatype="' . RicJsonLdBuilder::NS_XSD . 'boolean"') !== false
    && strpos($xmlBoolTrue, '>true<') !== false,
    'F010: boolean true serialises as xsd:boolean "true"');

// F010: integer literals must carry xsd:integer, not implicit xsd:string.
$xmlInt = $renderProp('ric:extent', 42);
$check(strpos($xmlInt, 'rdf:datatype="' . RicJsonLdBuilder::NS_XSD . 'integer"') !== false
    && strpos($xmlInt, '>42<') !== false,
    'F010: integer literal carries xsd:integer datatype');

// F010: float literals must carry xsd:double.
$xmlFloat = $renderProp('ric:measure', 3.14);
$check(strpos($xmlFloat, 'rdf:datatype="' . RicJsonLdBuilder::NS_XSD . 'double"') !== false,
    'F010: float literal carries xsd:double datatype');

// F010: strings retain the plain-element form (no datatype attribute).
$xmlString = $renderProp('ric:title', 'Fondo Rossi');
$check(strpos($xmlString, 'rdf:datatype') === false
    && strpos($xmlString, '<ric:title>Fondo Rossi</ric:title>') !== false,
    'F010: string literal stays plain (no rdf:datatype)');

// F011: typed-reference {'@id', '@type'} must collapse to compact
// rdf:resource attribute rather than the heavier striped resource.
$xmlTypedRef = $renderProp('ric:isOrWasIncludedIn', [
    '@id'   => 'https://example.test/archives/3',
    '@type' => 'ric:RecordSet',
]);
$check(strpos($xmlTypedRef, 'rdf:resource="https://example.test/archives/3"') !== false
    && strpos($xmlTypedRef, '<ric:RecordSet') === false,
    'F011: typed reference {@id,@type} emits compact rdf:resource (no striped subject)');

// F011 sanity: untyped reference still uses the same compact form.
$xmlUntypedRef = $renderProp('ric:isOrWasIncludedIn', [
    '@id' => 'https://example.test/archives/4',
]);
$check(strpos($xmlUntypedRef, 'rdf:resource="https://example.test/archives/4"') !== false,
    'F011: untyped reference {@id} emits compact rdf:resource (regression)');

// F017: a node whose @id is literal "0" must survive serialisation.
// !empty('0') === true would silently drop the rdf:about attribute.
$renderSubject = static function (array $node) use ($builder, $writeSubject): string {
    $xw = new \XMLWriter();
    $xw->openMemory();
    $xw->startElement('rdf:RDF');
    $xw->writeAttributeNs('xmlns', 'rdf', null, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
    $xw->writeAttributeNs('xmlns', 'ric', null, RicJsonLdBuilder::NS_RIC);
    $ctx = ['ric' => RicJsonLdBuilder::NS_RIC];
    $writeSubject->invoke($builder, $xw, $node, $ctx);
    $xw->endElement();
    return $xw->outputMemory();
};
$xmlZeroId = $renderSubject([
    '@id'   => '0',
    '@type' => 'ric:Record',
    'ric:title' => 'Edge case',
]);
$check(strpos($xmlZeroId, 'rdf:about="0"') !== false,
    'F017: literal "0" identifier is preserved in rdf:about (not dropped by !empty)');

// ============================================================
// F006/F017 attachRelationsToRicDoc wrapper: validate the shape
// of buildRelationNode + @graph composition that the wrapper
// produces. attachRelationsToRicDoc itself lives inside
// ArchivesPlugin (DB-coupled), so we assert on the builder-side
// contract the wrapper depends on.
// ============================================================
echo "\n=== F017 attachRelationsToRicDoc wrapper contract ===\n";

// Test A: a fonds doc + zero relations → @graph still well-formed
$doc1 = $builder->buildUnit([
    'id' => '1',
    'level' => 'fonds',
    'constructed_title' => 'F1',
]);
$graphEmpty = ['@context' => $builder->context(), '@graph' => [$doc1]];
$check(
    isset($graphEmpty['@context']) && isset($graphEmpty['@graph']) && count($graphEmpty['@graph']) === 1,
    'F017: empty-relations @graph contains only the primary doc'
);

// Test B: buildRelationNode emits canonical @id + preserved predicate
$relNode = $builder->buildRelationNode([
    'id' => 99,
    'source_type'   => 'archival_unit',
    'source_id'     => 1,
    'target_type'   => 'archive_place',
    'target_id'     => 5,
    'ric_predicate' => 'ric:isOrWasLocatedAt',
]);
$check(
    is_array($relNode) && ($relNode['@id'] ?? null) === $base . '/archives/relations/99',
    'F017: buildRelationNode emits canonical /archives/relations/{id} IRI'
);
$check(
    is_array($relNode) && ($relNode['ric:relationType'] ?? null) === 'ric:isOrWasLocatedAt',
    'F017: buildRelationNode preserves ric_predicate verbatim'
);

// Test C: @graph composition shape — doc + relation node coexist
$graph = ['@context' => $builder->context(), '@graph' => [$doc1, $relNode]];
$check(count($graph['@graph']) === 2, 'F017: @graph contains doc + relation');
$check(
    isset($graph['@graph'][1]['@id']) && str_contains((string) $graph['@graph'][1]['@id'], '/archives/relations/'),
    'F017: relation node IRI shape correct inside @graph'
);

echo "\n================================\n";
echo "RiC-O JSON-LD checks passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
