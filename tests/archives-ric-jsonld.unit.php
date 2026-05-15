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

echo "\n================================\n";
echo "RiC-O JSON-LD checks passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
