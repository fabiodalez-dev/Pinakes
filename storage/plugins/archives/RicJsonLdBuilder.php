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
     *                                                    id, level, constructed_title, formal_title.
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

        // adamsreview F005: same scheme allow-list as owl:sameAs below.
        $rightsUri = $this->str($unit, 'rights_statement_url');
        if ($rightsUri !== '' && self::isValidLodUri($rightsUri)) {
            $doc['ric:isOrWasRegulatedBy'] = [
                '@type'      => 'ric:Rule',
                'owl:sameAs' => ['@id' => $rightsUri],
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
                $part = [
                    '@id'   => $this->unitIri($cid),
                    '@type' => self::LEVEL_TO_TYPE[$clevel] ?? 'ric:Record',
                ];
                // CodeRabbit R3: omit rdfs:label when both titles are
                // missing — emitting "" is a syntactically valid but
                // semantically empty literal that pollutes the graph.
                $childLabel = $this->preferTitle($child);
                if ($childLabel !== '') {
                    $part['rdfs:label'] = $childLabel;
                }
                $parts[] = $part;
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

                // adamsreview F001: the predicate (ric:isCreatorOf etc.) reads
                // AGENT → UNIT — "the agent IS creator OF this unit". So the
                // agent MUST be the relation source and the unit MUST be the
                // target, regardless of which document is emitting the
                // serialisation. buildAuthority already does this naturally
                // (entityId == agentIri); buildUnit previously had source
                // and target inverted, producing the contradictory triple
                // "Unit isCreatorOf Agent" — semantically wrong RDF.
                $agentNode = [
                    '@id'   => $this->agentIri($agentId),
                    '@type' => $ricType,
                ];
                $name = $this->str($auth, 'authorised_form');
                if ($name !== '') {
                    $agentNode['ric:authorizedFormOfName'] = $name;
                }
                $existence = $this->str($auth, 'dates_of_existence');
                if ($existence !== '') {
                    $agentNode['ric:descriptiveNote'] = $existence;
                }

                $relations[] = [
                    '@id'                    => $this->relationIri($id, $agentId, $role),
                    '@type'                  => 'ric:Relation',
                    'ric:relationType'       => $predicate,
                    'ric:relationHasSource'  => $agentNode,
                    'ric:relationHasTarget'  => ['@id' => $entityId],
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

        // CodeRabbit #7: ric:hasOrHadName has range ric:Name, not xsd:string.
        // parallel_forms (ISAAR 5.1.3) and other_forms (5.1.5 — pseudonyms,
        // historical variants) carry distinct semantics that must be
        // preserved when we serialise them. Each value becomes a ric:Name
        // object tagged with its category and the installation language.
        $variantCategories = [
            'parallel_forms' => 'parallel',
            'other_forms'    => 'other',
        ];
        foreach ($variantCategories as $variantKey => $categoryLabel) {
            $val = $this->str($auth, $variantKey);
            if ($val !== '') {
                $doc['ric:hasOrHadName'][] = [
                    '@type'      => 'ric:Name',
                    'rdfs:label' => ['@value' => $val, '@language' => $this->lang],
                    'ric:type'   => $categoryLabel,
                ];
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

        // adamsreview F005: URIs come from DB rows (autori.viaf_uri,
        // autori.isni_uri, author_authority_alternates.uri) — the admin
        // form on save uses FILTER_VALIDATE_URL, which permits javascript:
        // and data: URIs. RDF consumers sometimes follow owl:sameAs
        // permissively, so a malicious stored URI could propagate into
        // user-agent dereferences. Filter on a strict scheme allow-list
        // here so the public JSON-LD never emits a non-Linked-Data scheme,
        // regardless of what an admin (or a future DB-direct entry path)
        // stored. Deduplicate, drop empties, drop the agent IRI itself.
        $sameAsClean = array_values(array_unique(array_filter(
            $sameAs,
            fn (string $uri): bool =>
                $uri !== '' && $uri !== $entityId && self::isValidLodUri($uri)
        )));
        if (!empty($sameAsClean)) {
            $sameAsNodes = array_map(
                static fn (string $uri): array => ['@id' => $uri],
                $sameAsClean
            );
            $doc['owl:sameAs'] = count($sameAsNodes) === 1 ? $sameAsNodes[0] : $sameAsNodes;
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
                    '@id'   => $this->unitIri($uid),
                    '@type' => self::LEVEL_TO_TYPE[$ulevel] ?? 'ric:Record',
                ];
                // CodeRabbit R3: omit empty rdfs:label rather than emit
                // a vacuous "" literal.
                $unitLabel = $this->preferTitle($unitRow);
                if ($unitLabel !== '') {
                    $target['rdfs:label'] = $unitLabel;
                }
                // CodeRabbit #6: same relation IRI emitted by buildUnit
                // (which sees the relation from the unit side) so the two
                // serialisations converge on the same node when merged.
                $relations[] = [
                    '@id'                   => $this->relationIri($uid, $id, $role),
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
     * @param list<array<string, mixed>> $rootUnits Partial rows: id, level, constructed_title, formal_title.
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
            $part = [
                '@id'   => $this->unitIri($uid),
                '@type' => self::LEVEL_TO_TYPE[$ulevel] ?? 'ric:RecordSet',
            ];
            // CodeRabbit R3: omit empty rdfs:label rather than emit "".
            $label = $this->preferTitle($u);
            if ($label !== '') {
                $part['rdfs:label'] = $label;
            }
            $parts[] = $part;
        }

        // CodeRabbit R3: the literal string "Archival collections" is
        // hardcoded English — tagging it with `@language: $this->lang`
        // (the INSTALLATION locale, e.g. `it`) would emit a false
        // language declaration ("Archival collections"@it). Tag with
        // `en` instead so consumers don't mis-index the literal in the
        // wrong language. ric:title stays as a plain string (no
        // language tag) since RiC-O treats it as language-neutral.
        return [
            '@context'         => $this->context(),
            '@id'              => $this->baseUrl . '/archives/collection.ric.json',
            '@type'            => 'ric:RecordSet',
            'rdfs:label'       => ['@value' => 'Archival collections', '@language' => 'en'],
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
     * Build a deterministic IRI for a ric:Relation between an archival
     * unit and an authority record, qualified by the role/predicate.
     *
     * Deterministic so the same logical relation emitted by buildUnit
     * (from the unit's side) and by buildAuthority (from the agent's
     * side) materialises as the same RDF node when the two documents
     * are merged into a graph by a consumer (CodeRabbit #6). Otherwise
     * each side would create a distinct blank node and the relation
     * would be duplicated in the union.
     *
     * The role is included so an Agent that is BOTH creator AND subject
     * of the same unit yields two distinct relations rather than one.
     */
    public function relationIri(int $unitId, int $agentId, string $role): string
    {
        $roleSlug = preg_replace('/[^a-z0-9]+/i', '-', $role) ?? 'rel';
        $roleSlug = strtolower(trim($roleSlug, '-'));
        if ($roleSlug === '') {
            $roleSlug = 'rel';
        }
        return $this->baseUrl . '/archives/relations/' . $unitId . '-' . $agentId . '-' . $roleSlug;
    }

    /**
     * Build a `ric:DateRange` node from two SMALLINT year columns, or
     * return null when both are null. RiC-O expects xsd:gYear literals
     * at year-precision.
     *
     * CodeRabbit #8: `xsd:gYear` requires at least 4 digits (YYYY) with
     * an optional `-` sign for BCE. Previously `(string) 850` produced
     * "850", which is not a valid xsd:gYear literal and would fail
     * SHACL/RDF parsers downstream. We also previously rejected year 0
     * and negative years entirely, dropping BCE dates that the SMALLINT
     * column can legitimately hold. Both behaviours are now fixed.
     *
     * @return array<string, mixed>|null
     */
    private function buildDateRange(?int $start, ?int $end): ?array
    {
        if ($start === null && $end === null) {
            return null;
        }
        $node = ['@type' => 'ric:DateRange'];
        if ($start !== null) {
            $node['ric:hasBeginningDate'] = ['@value' => self::formatGYear($start), '@type' => 'xsd:gYear'];
        }
        if ($end !== null) {
            $node['ric:hasEndDate'] = ['@value' => self::formatGYear($end), '@type' => 'xsd:gYear'];
        }
        return $node;
    }

    /**
     * Format an integer year as an xsd:gYear literal. Zero-pads to at
     * least 4 digits; emits a leading `-` for BCE (negative input). The
     * year 0 is preserved as `0000` since xsd:gYear permits it.
     */
    private static function formatGYear(int $year): string
    {
        if ($year < 0) {
            return '-' . sprintf('%04d', abs($year));
        }
        return sprintf('%04d', $year);
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

    /**
     * Whitelist of URI schemes safe to emit into public Linked Data
     * properties (`owl:sameAs`, rights URIs, etc.). Anything else —
     * `javascript:`, `data:`, `file:`, scheme-less, control chars — is
     * silently dropped, even if it round-tripped through PHP's
     * permissive `FILTER_VALIDATE_URL`. See adamsreview F005.
     *
     * `http(s)`: the overwhelming majority of Linked Data identifiers.
     * `urn`: namespaced URNs (ISNI URN form, ISBN URN, etc.).
     * `ark`: ARK persistent identifiers (frequently used by archives).
     * `info`: legacy LCNAF / OCLC namespace.
     * `doi`: DOI scheme used by publication identifiers.
     */
    private const ALLOWED_URI_SCHEMES = ['http', 'https', 'urn', 'ark', 'info', 'doi'];

    private static function isValidLodUri(string $uri): bool
    {
        // Strip leading/trailing whitespace defensively — a stored value
        // can carry stray characters that confuse parse_url.
        $uri = trim($uri);
        if ($uri === '') {
            return false;
        }
        // Control characters in URIs (NUL, CR, LF, etc.) are always wrong
        // and can lead to header-injection-style issues if the URI ends
        // up in a Link header. parse_url accepts them silently — guard
        // explicitly.
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return false;
        }
        $scheme = parse_url($uri, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            return false;
        }
        return in_array(strtolower($scheme), self::ALLOWED_URI_SCHEMES, true);
    }
}
