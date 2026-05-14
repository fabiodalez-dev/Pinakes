<?php

declare(strict_types=1);

namespace App\Plugins\Archives;

/**
 * Builds RiC-O (Records in Contexts Ontology) JSON-LD representations
 * of archival units and authority records by mapping the existing
 * ISAD(G) / ISAAR(CPF) data model onto the equivalent RiC-CM entities.
 *
 * Phase 1 of issue #122 — read-only translator. No new tables are
 * introduced; existing rows in `archival_units` and `authority_records`
 * are simply re-serialised using the RiC-O vocabulary so external
 * consumers (Europeana, ArchivesPortalEurope, ICA harvester) can ingest
 * Pinakes archives as a graph rather than a tree.
 *
 * RiC-O reference: https://www.ica.org/standards/RiC/ontology
 * RiC-CM reference: https://www.ica.org/standards/RiC/RiC-CM-1.0.html
 *
 * The class is intentionally pure: no DB access, no I/O. Callers fetch
 * rows from `ArchivesPlugin` (which already has dedicated finders and
 * batchers) and pass them in. This keeps the builder unit-testable in
 * isolation and avoids inflating the already-large plugin file.
 */
final class RicJsonLdBuilder
{
    public const NS_RIC  = 'https://www.ica.org/standards/RiC/ontology#';
    public const NS_RDFS = 'http://www.w3.org/2000/01/rdf-schema#';
    public const NS_XSD  = 'http://www.w3.org/2001/XMLSchema#';
    public const NS_OWL  = 'http://www.w3.org/2002/07/owl#';

    /**
     * ISAD(G) hierarchy levels → RiC-CM types. Fonds and series are
     * aggregations of records, hence RecordSet. Files and items are
     * leaf records, hence Record.
     */
    private const LEVEL_TO_TYPE = [
        'fonds'  => 'ric:RecordSet',
        'series' => 'ric:RecordSet',
        'file'   => 'ric:Record',
        'item'   => 'ric:Record',
    ];

    /**
     * ISAAR(CPF) types → RiC-CM agent types.
     */
    private const AUTHORITY_TYPE_TO_RIC = [
        'person'    => 'ric:Person',
        'corporate' => 'ric:CorporateBody',
        'family'    => 'ric:Family',
    ];

    /**
     * Local roles on `archival_unit_authority.role` → RiC-O predicates.
     * The role describes the relationship FROM the agent TO the
     * archival unit (e.g. "creator OF this fonds").
     */
    private const ROLE_TO_PREDICATE = [
        'creator'    => 'ric:isCreatorOf',
        'subject'    => 'ric:isSubjectOf',
        'custodian'  => 'ric:isOrWasCustodianOf',
        'recipient'  => 'ric:isAddresseeOf',
        'associated' => 'ric:isAssociatedWith',
    ];

    private string $baseUrl;
    private string $lang;

    /**
     * @param string $baseUrl Absolute URL prefix without trailing slash,
     *                        e.g. "https://biblio.example.org".
     * @param string $locale  Locale to use for rdfs:label @language.
     *                        Accepts "it_IT", "it", "en_US", etc.
     */
    public function __construct(string $baseUrl, string $locale = 'en')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        // Normalise it_IT → it for JSON-LD BCP-47 language tag.
        $this->lang = preg_match('/^([a-z]{2})/i', $locale, $m) === 1
            ? strtolower($m[1])
            : 'en';
    }

    /**
     * Build the standard @context block emitted at the top of every
     * RiC-O JSON-LD document.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'ric'  => self::NS_RIC,
            'rdfs' => self::NS_RDFS,
            'xsd'  => self::NS_XSD,
            'owl'  => self::NS_OWL,
        ];
    }

    /**
     * Build the JSON-LD document for one `archival_units` row.
     *
     * Children are referenced (not embedded) via `ric:hasOrHadPart` so
     * the document size stays bounded. Authorities are embedded inline
     * with their `@id` so consumers can dereference each separately via
     * `/archives/agents/{id}/ric.json`.
     *
     * @param array<string, mixed>            $unit        Row from `archival_units`.
     * @param list<array<string, mixed>>      $authorities Rows from `fetchAuthoritiesForArchivalUnit()`.
     *                                                    Each row has: id, type, authorised_form,
     *                                                    dates_of_existence, role.
     * @param list<array<string, mixed>>      $children    Direct children — partial rows with
     *                                                    id, level, constructed_title.
     * @return array<string, mixed>
     */
    public function buildUnit(array $unit, array $authorities = [], array $children = []): array
    {
        $id       = (int) ($unit['id'] ?? 0);
        $level    = (string) ($unit['level'] ?? 'file');
        $type     = self::LEVEL_TO_TYPE[$level] ?? 'ric:Record';
        $title    = $this->preferTitle($unit);
        $entityId = $this->unitIri($id);

        $doc = [
            '@context' => $this->context(),
            '@id'      => $entityId,
            '@type'    => $type,
        ];

        if ($title !== '') {
            $doc['rdfs:label'] = ['@value' => $title, '@language' => $this->lang];
            $doc['ric:title']  = $title;
        }

        $refCode = $this->str($unit, 'reference_code');
        if ($refCode !== '') {
            $doc['ric:identifier'] = $refCode;
        }

        $scope = $this->str($unit, 'scope_content');
        if ($scope !== '') {
            $doc['ric:scopeAndContent'] = $scope;
        }

        $extent = $this->str($unit, 'extent');
        if ($extent !== '') {
            $doc['ric:hasExtent'] = $extent;
        }

        $history = $this->str($unit, 'archival_history');
        if ($history !== '') {
            $doc['ric:history'] = $history;
        }

        $dateNode = $this->buildDateRange(
            $this->intOrNull($unit['date_start'] ?? null),
            $this->intOrNull($unit['date_end']   ?? null)
        );
        if ($dateNode !== null) {
            $doc['ric:isAssociatedWithDate'] = $dateNode;
        }

        $location = $this->str($unit, 'physical_location');
        if ($location !== '') {
            // Inline anonymous Place node — Phase 4 will replace this
            // with a proper /archives/places/{id} reference.
            $doc['ric:isOrWasLocatedAt'] = [
                '@type'      => 'ric:Place',
                'rdfs:label' => $location,
            ];
        }

        $langCodes = $this->str($unit, 'language_codes');
        if ($langCodes !== '') {
            // language_codes can be either comma- or semicolon-separated
            // (MARC convention uses ';', some imports use ','). Split on
            // both so we emit a clean array of ISO 639 codes either way.
            $tokens = preg_split('/[,;]/', $langCodes) ?: [];
            $langs  = array_values(array_filter(array_map('trim', $tokens)));
            if (count($langs) === 1) {
                $doc['ric:language'] = $langs[0];
            } elseif (count($langs) > 1) {
                $doc['ric:language'] = $langs;
            }
        }

        $rightsUri = $this->str($unit, 'rights_statement_url');
        if ($rightsUri !== '') {
            $doc['ric:isOrWasRegulatedBy'] = [
                '@type'      => 'ric:Rule',
                'owl:sameAs' => $rightsUri,
            ];
        }

        $parentId = $this->intOrNull($unit['parent_id'] ?? null);
        if ($parentId !== null && $parentId > 0) {
            $doc['ric:isOrWasIncludedIn'] = ['@id' => $this->unitIri($parentId)];
        }

        if (!empty($children)) {
            $parts = [];
            foreach ($children as $child) {
                $cid    = (int) ($child['id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $clevel = (string) ($child['level'] ?? 'file');
                $parts[] = [
                    '@id'        => $this->unitIri($cid),
                    '@type'      => self::LEVEL_TO_TYPE[$clevel] ?? 'ric:Record',
                    'rdfs:label' => $this->preferTitle($child),
                ];
            }
            if (!empty($parts)) {
                $doc['ric:hasOrHadPart'] = $parts;
            }
        }

        if (!empty($authorities)) {
            $relations = [];
            foreach ($authorities as $auth) {
                $role      = (string) ($auth['role'] ?? 'associated');
                $predicate = self::ROLE_TO_PREDICATE[$role] ?? 'ric:isAssociatedWith';
                $agentId   = (int) ($auth['id'] ?? 0);
                if ($agentId <= 0) {
                    continue;
                }
                $authType  = (string) ($auth['type'] ?? 'person');
                $ricType   = self::AUTHORITY_TYPE_TO_RIC[$authType] ?? 'ric:Agent';

                $target = [
                    '@id'   => $this->agentIri($agentId),
                    '@type' => $ricType,
                ];
                $name = $this->str($auth, 'authorised_form');
                if ($name !== '') {
                    $target['ric:authorizedFormOfName'] = $name;
                }
                $existence = $this->str($auth, 'dates_of_existence');
                if ($existence !== '') {
                    $target['ric:descriptiveNote'] = $existence;
                }

                $relations[] = [
                    '@type'                  => 'ric:Relation',
                    'ric:relationType'       => $predicate,
                    'ric:relationHasSource'  => ['@id' => $entityId],
                    'ric:relationHasTarget'  => $target,
                ];
            }
            if (!empty($relations)) {
                $doc['ric:isOrWasRelatedTo'] = $relations;
            }
        }

        // Cross-references to other serialisations the plugin already
        // exposes. Lets a LD-aware consumer pivot from RiC-O to MARCXML,
        // EAD3 or IIIF without a second discovery round-trip.
        $seeAlso = [
            ['@id' => $this->baseUrl . '/archives/' . $id . '/dc.xml',
             'rdfs:label' => 'Dublin Core (OAI-DC)'],
            ['@id' => $this->baseUrl . '/archives/' . $id . '/ead.xml',
             'rdfs:label' => 'EAD3 finding aid'],
            ['@id' => $this->baseUrl . '/archives/' . $id . '/mets.xml',
             'rdfs:label' => 'METS package'],
            ['@id' => $this->baseUrl . '/archives/' . $id . '/manifest.json',
             'rdfs:label' => 'IIIF Manifest'],
        ];
        $ark = $this->str($unit, 'ark_identifier');
        if ($ark !== '') {
            $seeAlso[] = ['@id' => 'https://n2t.net/' . $ark, 'rdfs:label' => 'ARK persistent identifier'];
        }
        $doc['rdfs:seeAlso'] = $seeAlso;

        return $doc;
    }

    /**
     * Build the JSON-LD document for one `authority_records` row (Agent).
     *
     * @param array<string, mixed>       $auth     Row from `authority_records`.
     * @param list<array<string, mixed>> $units    Archival units this agent links to (via
     *                                             `fetchArchivalUnitsForAuthority`). Each row has
     *                                             id, reference_code, level, constructed_title, role.
     * @param list<string>               $sameAs   External authority URIs (VIAF, ISNI, Wikidata, ...)
     *                                             previously gathered by the caller from
     *                                             `autori_authority_link` ↔ `autori` ↔
     *                                             `author_authority_alternates`.
     * @return array<string, mixed>
     */
    public function buildAuthority(array $auth, array $units = [], array $sameAs = []): array
    {
        $id       = (int) ($auth['id'] ?? 0);
        $type     = (string) ($auth['type'] ?? 'person');
        $ricType  = self::AUTHORITY_TYPE_TO_RIC[$type] ?? 'ric:Agent';
        $entityId = $this->agentIri($id);

        $doc = [
            '@context' => $this->context(),
            '@id'      => $entityId,
            '@type'    => $ricType,
        ];

        $name = $this->str($auth, 'authorised_form');
        if ($name !== '') {
            $doc['rdfs:label']               = ['@value' => $name, '@language' => $this->lang];
            $doc['ric:authorizedFormOfName'] = $name;
        }

        $existence = $this->str($auth, 'dates_of_existence');
        if ($existence !== '') {
            $doc['ric:descriptiveNote'] = $existence;
        }

        foreach (['parallel_forms', 'other_forms'] as $variantKey) {
            $val = $this->str($auth, $variantKey);
            if ($val !== '') {
                $doc['ric:hasOrHadName'][] = $val;
            }
        }

        $history = $this->str($auth, 'history');
        if ($history !== '') {
            $doc['ric:history'] = $history;
        }

        $functions = $this->str($auth, 'functions');
        if ($functions !== '') {
            $doc['ric:performsOrPerformed'] = $functions;
        }

        $places = $this->str($auth, 'places');
        if ($places !== '') {
            $doc['ric:isOrWasLocatedAt'] = [
                '@type'      => 'ric:Place',
                'rdfs:label' => $places,
            ];
        }

        // Deduplicate, drop empties, drop the agent IRI itself.
        $sameAsClean = array_values(array_unique(array_filter(
            $sameAs,
            static fn (string $uri): bool => $uri !== '' && $uri !== $entityId
        )));
        if (!empty($sameAsClean)) {
            $doc['owl:sameAs'] = count($sameAsClean) === 1 ? $sameAsClean[0] : $sameAsClean;
        }

        if (!empty($units)) {
            $relations = [];
            foreach ($units as $unitRow) {
                $uid = (int) ($unitRow['id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $role      = (string) ($unitRow['role'] ?? 'associated');
                $predicate = self::ROLE_TO_PREDICATE[$role] ?? 'ric:isAssociatedWith';
                $ulevel    = (string) ($unitRow['level'] ?? 'file');
                $target = [
                    '@id'        => $this->unitIri($uid),
                    '@type'      => self::LEVEL_TO_TYPE[$ulevel] ?? 'ric:Record',
                    'rdfs:label' => $this->preferTitle($unitRow),
                ];
                $relations[] = [
                    '@type'                 => 'ric:Relation',
                    'ric:relationType'      => $predicate,
                    'ric:relationHasSource' => ['@id' => $entityId],
                    'ric:relationHasTarget' => $target,
                ];
            }
            if (!empty($relations)) {
                $doc['ric:isOrWasRelatedTo'] = $relations;
            }
        }

        return $doc;
    }

    /**
     * Build the JSON-LD root collection document — a synthetic RecordSet
     * that aggregates all top-level fonds (rows where parent_id IS NULL).
     *
     * @param list<array<string, mixed>> $rootUnits Partial rows: id, level, constructed_title.
     * @return array<string, mixed>
     */
    public function buildCollection(array $rootUnits): array
    {
        $parts = [];
        foreach ($rootUnits as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $ulevel = (string) ($u['level'] ?? 'fonds');
            $parts[] = [
                '@id'        => $this->unitIri($uid),
                '@type'      => self::LEVEL_TO_TYPE[$ulevel] ?? 'ric:RecordSet',
                'rdfs:label' => $this->preferTitle($u),
            ];
        }

        return [
            '@context'         => $this->context(),
            '@id'              => $this->baseUrl . '/archives/collection.ric.json',
            '@type'            => 'ric:RecordSet',
            'rdfs:label'       => ['@value' => 'Archival collections', '@language' => $this->lang],
            'ric:title'        => 'Archival collections',
            'ric:hasOrHadPart' => $parts,
        ];
    }

    public function unitIri(int $id): string
    {
        return $this->baseUrl . '/archives/' . $id;
    }

    public function agentIri(int $id): string
    {
        return $this->baseUrl . '/archives/agents/' . $id;
    }

    /**
     * Build a `ric:DateRange` node from two SMALLINT year columns, or
     * return null when both are empty/zero. RiC-O expects xsd:gYear
     * literals at year-precision.
     *
     * @return array<string, mixed>|null
     */
    private function buildDateRange(?int $start, ?int $end): ?array
    {
        $hasStart = $start !== null && $start > 0;
        $hasEnd   = $end   !== null && $end   > 0;
        if (!$hasStart && !$hasEnd) {
            return null;
        }
        $node = ['@type' => 'ric:DateRange'];
        if ($hasStart) {
            $node['ric:hasBeginningDate'] = ['@value' => (string) $start, '@type' => 'xsd:gYear'];
        }
        if ($hasEnd) {
            $node['ric:hasEndDate'] = ['@value' => (string) $end, '@type' => 'xsd:gYear'];
        }
        return $node;
    }

    /**
     * Read a string field from an associative row, trimmed; empty if
     * missing or non-string.
     *
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? trim($v) : '';
    }

    /**
     * Cast a row field to int when non-empty, otherwise null. Used for
     * nullable foreign-key columns where 0 is not a meaningful value.
     */
    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === false) {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }
        return null;
    }

    /**
     * Prefer `constructed_title` over `formal_title` (the constructed
     * one is always populated, the formal one is optional). Used by
     * both unit and child rows.
     *
     * @param array<string, mixed> $row
     */
    private function preferTitle(array $row): string
    {
        $constructed = $this->str($row, 'constructed_title');
        if ($constructed !== '') {
            return $constructed;
        }
        return $this->str($row, 'formal_title');
    }
}
