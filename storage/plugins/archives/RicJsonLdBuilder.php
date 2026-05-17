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
 * The class avoids DB access — callers fetch rows from ArchivesPlugin
 * (which has dedicated finders and batchers) and pass them in. The only
 * I/O is the optional RDF/XML emission via serializeToRdfXml(), which
 * writes to an XMLWriter stream the caller provides; the JSON-LD path
 * remains pure (returns arrays). This keeps the builder unit-testable
 * in isolation and avoids inflating the already-large plugin file.
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
     * Legacy ISAAR(CPF) types → RiC-CM agent types. Used as a fallback
     * when the Phase 2 `ric_type` column (v0.7.8+) is not populated.
     */
    private const AUTHORITY_TYPE_TO_RIC = [
        'person'    => 'ric:Person',
        'corporate' => 'ric:CorporateBody',
        'family'    => 'ric:Family',
    ];

    /**
     * Phase 2 (v0.7.8) RiC-CM canonical types. Maps the
     * `authority_records.ric_type` enum values to their RiC-O class
     * IRIs. `Position` and `Group` have no ISAAR equivalent — they
     * exist only in this layer.
     */
    private const RIC_TYPE_TO_CLASS = [
        'Person'        => 'ric:Person',
        'CorporateBody' => 'ric:CorporateBody',
        'Family'        => 'ric:Family',
        'Position'      => 'ric:Position',
        'Group'         => 'ric:Group',
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
     * @param list<array<string, mixed>>      $activities  RiC-CM Phase 3 link rows
     *                                                    (`archive_unit_activities` joined to
     *                                                    `archive_activities`). Each row carries
     *                                                    `activity_id`, `ric_predicate`, `title`,
     *                                                    `activity_type`, `date_start`, `date_end`.
     * @return array<string, mixed>
     */
    public function buildUnit(array $unit, array $authorities = [], array $children = [], array $activities = []): array
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
        // CodeRabbit R5: validate ark_identifier before interpolating it
        // into an n2t.net resolver URL. The DB column is a free-form
        // VARCHAR(255) so a row could carry whitespace, control chars
        // (CR/LF/NUL — header-injection risk if the IRI later flows
        // into a Link header), or an unexpected prefix (an absolute
        // URL like https://attacker.tld/, or a leading "../"). Apply
        // the same defensive posture as the F005 URI allow-list.
        $ark = self::sanitizeArkIdentifier($this->str($unit, 'ark_identifier'));
        if ($ark !== null) {
            $seeAlso[] = ['@id' => 'https://n2t.net/' . $ark, 'rdfs:label' => 'ARK persistent identifier'];
        }
        $doc['rdfs:seeAlso'] = $seeAlso;

        // Phase 3 (v0.7.9): activity relations from
        // archive_unit_activities. Each link becomes a ric:Relation
        // node typed with the row's `ric_predicate` (default
        // ric:resultsOrResultedFrom). Targets reference the Activity
        // by its dedicated IRI (/archives/activities/{id}) so a
        // consumer can dereference the full activity document
        // separately.
        if (!empty($activities)) {
            $existing = $doc['ric:isOrWasRelatedTo'] ?? [];
            foreach ($activities as $link) {
                $actId = (int) ($link['activity_id'] ?? 0);
                if ($actId <= 0) {
                    continue;
                }
                $predicate = (string) ($link['ric_predicate'] ?? 'ric:resultsOrResultedFrom');
                if ($predicate === '') {
                    $predicate = 'ric:resultsOrResultedFrom';
                }
                $target = [
                    '@id'   => $this->activityIri($actId),
                    '@type' => 'ric:Activity',
                ];
                $title = $this->str($link, 'title');
                if ($title !== '') {
                    $target['rdfs:label'] = $title;
                }
                $existing[] = [
                    '@id'                   => $this->unitActivityRelationIri($id, $actId, $predicate),
                    '@type'                 => 'ric:Relation',
                    'ric:relationType'      => $predicate,
                    'ric:relationHasSource' => ['@id' => $entityId],
                    'ric:relationHasTarget' => $target,
                ];
            }
            if (!empty($existing)) {
                $doc['ric:isOrWasRelatedTo'] = $existing;
            }
        }

        return $doc;
    }

    /**
     * Build the JSON-LD document for one `archive_activities` row.
     * Phase 3 (v0.7.9) — RiC-CM Activity entity.
     *
     * @param array<string, mixed>       $activity    Row from `archive_activities`.
     * @param list<array<string, mixed>> $unitLinks   Rows from `archive_unit_activities`
     *                                                joined to `archival_units` — each carries
     *                                                `unit_id`, `level`, `constructed_title`,
     *                                                `formal_title`, `ric_predicate`.
     * @return array<string, mixed>
     */
    public function buildActivity(array $activity, array $unitLinks = []): array
    {
        $id       = (int) ($activity['id'] ?? 0);
        $entityId = $this->activityIri($id);

        $doc = [
            '@context' => $this->context(),
            '@id'      => $entityId,
            '@type'    => 'ric:Activity',
        ];

        $title = $this->str($activity, 'title');
        if ($title !== '') {
            $doc['rdfs:label']  = ['@value' => $title, '@language' => $this->lang];
            $doc['ric:name']    = $title;
        }

        $description = $this->str($activity, 'description');
        if ($description !== '') {
            $doc['ric:descriptiveNote'] = $description;
        }

        $activityType = $this->str($activity, 'activity_type');
        if ($activityType !== '') {
            // Surface the ISDF sub-type so consumers can filter by
            // function vs activity vs transaction. ric:type is a
            // free-form classification slot in RiC-O.
            $doc['ric:type'] = $activityType;
        }

        $sourceRef = $this->str($activity, 'source_ref');
        if ($sourceRef !== '') {
            $doc['ric:hasSource'] = $sourceRef;
        }

        // Date range — both Phase-2 helpers (xsd:date for individual
        // dates) and Phase-1 buildDateRange (xsd:gYear for year-only)
        // accept the same VARCHAR(20) shape. Detect year-only values
        // (e.g. "1789") and emit them as xsd:gYear; full ISO dates
        // (e.g. "1789-07-14") remain xsd:date. A bare year is NOT a
        // valid xsd:date lexical value, so this avoids producing
        // RDF that fails XSD validation.
        $start = $this->str($activity, 'date_start');
        $end   = $this->str($activity, 'date_end');
        if ($start !== '' || $end !== '') {
            $dateNode = ['@type' => 'ric:DateRange'];
            if ($start !== '') {
                $dt = preg_match('/^\d{4}$/', $start) === 1 ? 'xsd:gYear' : 'xsd:date';
                $dateNode['ric:hasBeginningDate'] = ['@value' => $start, '@type' => $dt];
            }
            if ($end !== '') {
                $dt = preg_match('/^\d{4}$/', $end) === 1 ? 'xsd:gYear' : 'xsd:date';
                $dateNode['ric:hasEndDate'] = ['@value' => $end, '@type' => $dt];
            }
            $doc['ric:isAssociatedWithDate'] = $dateNode;
        }

        $isOngoing = (int) ($activity['is_ongoing'] ?? 0);
        if ($isOngoing === 1) {
            // RiC-O has no dedicated "ongoing" flag — surface it as a
            // descriptive note keyed on a stable label so consumers
            // querying for active activities can match it.
            $doc['ric:descriptiveNote'] = ($description === '' ? '' : $description . "\n")
                . '[ongoing]';
        }

        // ric:isOrWasPerformedBy — when the activity carries an
        // agent_id, emit a relation pointing at the agent IRI.
        $agentId = $this->intOrNull($activity['agent_id'] ?? null);
        if ($agentId !== null && $agentId > 0) {
            $doc['ric:isOrWasPerformedBy'] = ['@id' => $this->agentIri($agentId)];
        }

        // Parent activity in the ISDF function → activity → transaction
        // hierarchy.
        $parentId = $this->intOrNull($activity['parent_id'] ?? null);
        if ($parentId !== null && $parentId > 0) {
            $doc['ric:hasOrHadPartOf'] = ['@id' => $this->activityIri($parentId)];
        }

        // ric:resultsOrResultedIn — units the activity produced /
        // touched. Inverse of the buildUnit emission above; both
        // directions must converge on the same relation IRI when the
        // two documents are merged into a graph.
        if (!empty($unitLinks)) {
            $relations = [];
            foreach ($unitLinks as $link) {
                $uid = (int) ($link['unit_id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $predicate = (string) ($link['ric_predicate'] ?? 'ric:resultsOrResultedFrom');
                if ($predicate === '') {
                    $predicate = 'ric:resultsOrResultedFrom';
                }
                $ulevel = (string) ($link['level'] ?? 'file');
                $target = [
                    '@id'   => $this->unitIri($uid),
                    '@type' => self::LEVEL_TO_TYPE[$ulevel] ?? 'ric:Record',
                ];
                $label = $this->preferTitle($link);
                if ($label !== '') {
                    $target['rdfs:label'] = $label;
                }
                $relations[] = [
                    '@id'                   => $this->unitActivityRelationIri($uid, $id, $predicate),
                    '@type'                 => 'ric:Relation',
                    // Activity side: the activity is the source, the
                    // unit is the target. Predicate stays the same as
                    // the buildUnit side so the merged graph is
                    // internally consistent.
                    'ric:relationType'      => $predicate,
                    'ric:relationHasSource' => ['@id' => $entityId],
                    'ric:relationHasTarget' => $target,
                ];
            }
            if (!empty($relations)) {
                $doc['ric:isOrWasRelatedTo'] = $relations;
            }
        }

        // Cross-reference to the public Activity detail page.
        $doc['rdfs:seeAlso'] = [
            ['@id' => $this->baseUrl . '/archives/activities/' . $id,
             'rdfs:label' => 'Activity detail (HTML)'],
        ];

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
    public function buildAuthority(array $auth, array $units = [], array $sameAs = [], array $agentRelations = []): array
    {
        $id       = (int) ($auth['id'] ?? 0);
        // Phase 2 (v0.7.8): prefer the new ric_type column over the
        // legacy ISAAR `type` so authorities tagged as Position or
        // Group surface with the correct RiC-O class. Fall back to
        // the ISAAR map when ric_type is empty (pre-Phase-2 row that
        // the backfill SQL missed for any reason).
        $ricTypeRaw = $this->str($auth, 'ric_type');
        if ($ricTypeRaw !== '' && isset(self::RIC_TYPE_TO_CLASS[$ricTypeRaw])) {
            $ricType = self::RIC_TYPE_TO_CLASS[$ricTypeRaw];
        } else {
            $type    = (string) ($auth['type'] ?? 'person');
            $ricType = self::AUTHORITY_TYPE_TO_RIC[$type] ?? 'ric:Agent';
        }
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

        // Phase 2 (v0.7.8): structured birth/death dates take precedence
        // over the legacy free-text `dates_of_existence` blob. When
        // present, emit them as RiC-O begin/end date predicates so
        // consumers can filter agents by lifespan; otherwise fall back
        // to the descriptiveNote for back-compat with pre-Phase-2 data.
        $birth = $this->str($auth, 'birth_date');
        $death = $this->str($auth, 'death_date');
        if ($birth !== '') {
            $doc['ric:beginningDate'] = ['@value' => $birth, '@type' => 'xsd:date'];
        }
        if ($death !== '') {
            $doc['ric:endDate'] = ['@value' => $death, '@type' => 'xsd:date'];
        }
        $existence = $this->str($auth, 'dates_of_existence');
        if ($existence !== '' && $birth === '' && $death === '') {
            $doc['ric:descriptiveNote'] = $existence;
        }

        // Phase 2 (v0.7.8): place_of_origin emits a Place node so Phase 4
        // can swap the literal label for a /archives/places/{id} IRI
        // without churning consumers. The `places` blob (TEXT, free
        // form) stays as a fallback for installs that haven't filled in
        // the structured column.
        $placeOfOrigin = $this->str($auth, 'place_of_origin');
        if ($placeOfOrigin !== '') {
            $doc['ric:isOrWasLocatedAt'] = [
                '@type'      => 'ric:Place',
                'rdfs:label' => $placeOfOrigin,
            ];
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

        // Legacy free-text places blob — only used if Phase 2's
        // structured place_of_origin column is empty (above) so we
        // don't double-emit the same predicate.
        $places = $this->str($auth, 'places');
        if ($places !== '' && !isset($doc['ric:isOrWasLocatedAt'])) {
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

        // Phase 2 (v0.7.8): Agent → Agent relations from
        // archive_agent_relations. The role/predicate is stored
        // verbatim in the DB (VARCHAR), so we trust it as-is —
        // validation lives in the admin form, not here. Each edge
        // becomes a ric:Relation with the current agent as source.
        if (!empty($agentRelations)) {
            $existing = $doc['ric:isOrWasRelatedTo'] ?? [];
            foreach ($agentRelations as $rel) {
                $targetId = (int) ($rel['related_id'] ?? 0);
                $pred     = (string) ($rel['ric_predicate'] ?? '');
                if ($targetId <= 0 || $pred === '') {
                    continue;
                }
                $relNode = [
                    '@id'                   => $this->agentRelationIri($id, $targetId, $pred),
                    '@type'                 => 'ric:Relation',
                    'ric:relationType'      => $pred,
                    'ric:relationHasSource' => ['@id' => $entityId],
                    'ric:relationHasTarget' => ['@id' => $this->agentIri($targetId)],
                ];
                $qual = $this->str($rel, 'qualifier');
                if ($qual !== '') {
                    $relNode['ric:descriptiveNote'] = $qual;
                }
                $start = $this->str($rel, 'date_start');
                $end   = $this->str($rel, 'date_end');
                if ($start !== '' || $end !== '') {
                    $dateNode = ['@type' => 'ric:DateRange'];
                    if ($start !== '') {
                        $dateNode['ric:hasBeginningDate'] = ['@value' => $start, '@type' => 'xsd:date'];
                    }
                    if ($end !== '') {
                        $dateNode['ric:hasEndDate'] = ['@value' => $end, '@type' => 'xsd:date'];
                    }
                    $relNode['ric:isAssociatedWithDate'] = $dateNode;
                }
                $existing[] = $relNode;
            }
            if (!empty($existing)) {
                $doc['ric:isOrWasRelatedTo'] = $existing;
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
     * Phase 3 (v0.7.9): IRI for an `archive_activities` row.
     * Public route at /archives/activities/{id}/ric.json serves the
     * RiC-O JSON-LD; /archives/activities/{id} is the HTML view.
     */
    public function activityIri(int $id): string
    {
        return $this->baseUrl . '/archives/activities/' . $id;
    }

    /**
     * Phase 4 (v0.7.10): IRI for an `archive_places` row.
     */
    public function placeIri(int $id): string
    {
        return $this->baseUrl . '/archives/places/' . $id;
    }

    /**
     * Phase 4 (v0.7.10): build a RiC-O Place document.
     *
     * @param array<string, mixed> $place Row from `archive_places`.
     * @return array<string, mixed>
     */
    public function buildPlace(array $place): array
    {
        $id       = (int) ($place['id'] ?? 0);
        $entityId = $this->placeIri($id);

        $doc = [
            '@context' => $this->context(),
            '@id'      => $entityId,
            '@type'    => 'ric:Place',
        ];

        $name = $this->str($place, 'name');
        if ($name !== '') {
            $doc['rdfs:label'] = ['@value' => $name, '@language' => $this->lang];
            $doc['ric:name']   = $name;
        }

        $placeType = $this->str($place, 'place_type');
        if ($placeType !== '') {
            $doc['ric:type'] = $placeType;
        }

        $description = $this->str($place, 'description');
        if ($description !== '') {
            $doc['ric:descriptiveNote'] = $description;
        }

        // Lat/lng as a WGS84 geometry node — non-standard RiC-O but a
        // common LD pattern; consumers that don't know the wkt
        // namespace ignore it safely.
        $lat = $place['latitude']  ?? null;
        $lng = $place['longitude'] ?? null;
        if ($lat !== null && $lng !== null) {
            $doc['ric:hasOrHadCoordinate'] = [
                '@type' => 'ric:CoordinateLocation',
                'ric:latitude'  => (float) $lat,
                'ric:longitude' => (float) $lng,
            ];
        }

        // External LOD links.
        $sameAs = [];
        $gn  = $this->str($place, 'geonames_id');
        $wd  = $this->str($place, 'wikidata_id');
        $tgn = $this->str($place, 'tgn_id');
        if ($gn  !== '') { $sameAs[] = ['@id' => 'https://www.geonames.org/' . $gn]; }
        if ($wd  !== '') { $sameAs[] = ['@id' => 'https://www.wikidata.org/entity/' . $wd]; }
        if ($tgn !== '') { $sameAs[] = ['@id' => 'http://vocab.getty.edu/page/tgn/' . $tgn]; }
        if (!empty($sameAs)) {
            $doc['owl:sameAs'] = count($sameAs) === 1 ? $sameAs[0] : $sameAs;
        }

        // Parent place reference (catania → sicilia → italia).
        $parentId = $this->intOrNull($place['parent_id'] ?? null);
        if ($parentId !== null && $parentId > 0) {
            $doc['ric:isOrWasIncludedIn'] = ['@id' => $this->placeIri($parentId)];
        }

        // Historical date range (kingdoms, abolished provinces).
        // Detect year-only values (e.g. "1816") and emit as xsd:gYear
        // — bare years are not valid xsd:date lexical values.
        $start = $this->str($place, 'date_start');
        $end   = $this->str($place, 'date_end');
        if ($start !== '' || $end !== '') {
            $dateNode = ['@type' => 'ric:DateRange'];
            if ($start !== '') {
                $dt = preg_match('/^\d{4}$/', $start) === 1 ? 'xsd:gYear' : 'xsd:date';
                $dateNode['ric:hasBeginningDate'] = ['@value' => $start, '@type' => $dt];
            }
            if ($end !== '') {
                $dt = preg_match('/^\d{4}$/', $end) === 1 ? 'xsd:gYear' : 'xsd:date';
                $dateNode['ric:hasEndDate'] = ['@value' => $end, '@type' => $dt];
            }
            $doc['ric:isAssociatedWithDate'] = $dateNode;
        }

        return $doc;
    }

    /**
     * Phase 4 (v0.7.10): convert one polymorphic `archive_relations`
     * row into a ric:Relation node. Returns null when the row is
     * unparseable (missing types or ids), so the caller can drop
     * malformed entries without crashing.
     *
     * @param array<string, mixed> $row Row from `archive_relations`.
     * @return array<string, mixed>|null
     */
    public function buildRelationNode(array $row): ?array
    {
        $srcType = (string) ($row['source_type'] ?? '');
        $srcId   = (int) ($row['source_id'] ?? 0);
        $tgtType = (string) ($row['target_type'] ?? '');
        $tgtId   = (int) ($row['target_id'] ?? 0);
        $pred    = (string) ($row['ric_predicate'] ?? '');
        if ($srcType === '' || $srcId <= 0 || $tgtType === '' || $tgtId <= 0 || $pred === '') {
            return null;
        }

        $srcIri = $this->iriForEntity($srcType, $srcId);
        $tgtIri = $this->iriForEntity($tgtType, $tgtId);
        if ($srcIri === null || $tgtIri === null) {
            return null;
        }

        // Reject rows without a usable primary key — emitting them would
        // collapse every malformed/synthetic row onto the same
        // `/archives/relations/0` IRI and silently shadow legitimate
        // relations downstream.
        $relId = (int) ($row['id'] ?? 0);
        if ($relId <= 0) {
            return null;
        }
        $node = [
            '@id'                   => $this->baseUrl . '/archives/relations/' . $relId,
            '@type'                 => 'ric:Relation',
            'ric:relationType'      => $pred,
            'ric:relationHasSource' => ['@id' => $srcIri],
            'ric:relationHasTarget' => ['@id' => $tgtIri],
        ];

        $qual = $this->str($row, 'qualifier');
        if ($qual !== '') {
            $node['ric:descriptiveNote'] = $qual;
        }
        $certainty = $this->str($row, 'certainty');
        if ($certainty !== '' && $certainty !== 'certain') {
            $node['ric:certainty'] = $certainty;
        }
        $sref = $this->str($row, 'source_ref');
        if ($sref !== '') {
            $node['ric:hasSource'] = $sref;
        }
        $start = $this->str($row, 'date_start');
        $end   = $this->str($row, 'date_end');
        if ($start !== '' || $end !== '') {
            $dateNode = ['@type' => 'ric:DateRange'];
            if ($start !== '') {
                $dateNode['ric:hasBeginningDate'] = ['@value' => $start, '@type' => 'xsd:date'];
            }
            if ($end !== '') {
                $dateNode['ric:hasEndDate'] = ['@value' => $end, '@type' => 'xsd:date'];
            }
            $node['ric:isAssociatedWithDate'] = $dateNode;
        }

        return $node;
    }

    /**
     * Phase 4 (v0.7.10): build the entity IRI for a polymorphic
     * relation endpoint. Returns null when the entity_type is not
     * one of the four known RiC-CM types.
     */
    private function iriForEntity(string $type, int $id): ?string
    {
        switch ($type) {
            case 'archival_unit':    return $this->unitIri($id);
            case 'authority_record': return $this->agentIri($id);
            case 'archive_activity': return $this->activityIri($id);
            case 'archive_place':    return $this->placeIri($id);
            default:                 return null;
        }
    }

    /**
     * Phase 3 (v0.7.9): deterministic IRI for the unit ↔ activity
     * relation. Same convergence guarantee as relationIri: the unit
     * side (buildUnit) and the activity side (buildActivity) emit
     * relation nodes with identical @id so a graph-merge consumer
     * collapses them to one node.
     */
    public function unitActivityRelationIri(int $unitId, int $activityId, string $predicate): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $predicate) ?? 'rel';
        $slug = strtolower(trim($slug, '-'));
        if ($slug === '') {
            $slug = 'rel';
        }
        return $this->baseUrl . '/archives/unit-activity-relations/'
            . $unitId . '-' . $activityId . '-' . $slug;
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
     * Build a deterministic IRI for an Agent → Agent relation
     * (RiC-CM Phase 2 — v0.7.8). The predicate is part of the IRI so
     * an Agent that is both `ric:isMemberOf` and `ric:isSuccessorOf`
     * the same other agent yields two distinct relation nodes. The
     * predicate is slugged (`ric:isMemberOf` → `ric-ismemberof`) to
     * keep the URL safe.
     */
    public function agentRelationIri(int $agentId, int $relatedId, string $predicate): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $predicate) ?? 'rel';
        $slug = strtolower(trim($slug, '-'));
        if ($slug === '') {
            $slug = 'rel';
        }
        return $this->baseUrl . '/archives/agent-relations/' . $agentId . '-' . $relatedId . '-' . $slug;
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

    /**
     * Validate and normalise an ARK identifier (CodeRabbit R5).
     *
     * ARK syntax (RFC draft + n2t.net practice):
     *     ark:/NAAN/Name[/Qualifier][?Variant][#Anchor]
     * where NAAN is a 5+ digit naming authority number. We accept the
     * canonical form with or without the literal `ark:/` prefix — the
     * column historically stored both shapes — and emit the prefix
     * back so the resulting `https://n2t.net/ark:/…` URL is always
     * well-formed.
     *
     * Returns the prefix-bearing ARK on success, or null when the
     * input is empty / malformed / contains control characters or
     * whitespace. Control-character rejection mirrors isValidLodUri
     * to defend against the same header-injection class of attack.
     */
    private static function sanitizeArkIdentifier(string $raw): ?string
    {
        $candidate = trim($raw);
        if ($candidate === '') {
            return null;
        }
        // Reject control characters and any internal whitespace —
        // both would corrupt the IRI and the URL composition.
        if (preg_match('/[\x00-\x1F\x7F\s]/', $candidate) === 1) {
            return null;
        }
        // Reject anything that's already an absolute URL or escapes
        // the n2t.net path (`../`, leading `/`, scheme:// prefix).
        if (preg_match('#^(?:[a-z][a-z0-9+\-.]*://|/|\.\./)#i', $candidate) === 1) {
            return null;
        }
        // Normalise to canonical form `ark:/NAAN/Name…`. Accept both
        // bare `ark:/...` (correct) and `12345/foo` shapes; reject
        // anything that doesn't look like an ARK at all.
        $normalised = $candidate;
        if (preg_match('#^ark:/#i', $normalised) !== 1) {
            // Bare NAAN/Name form — must look like `<5+ digits>/<rest>`.
            if (preg_match('#^[0-9]{5,}/#', $normalised) !== 1) {
                return null;
            }
            $normalised = 'ark:/' . $normalised;
        } else {
            // Already prefixed — verify the NAAN segment is digits.
            if (preg_match('#^ark:/[0-9]{5,}/#i', $normalised) !== 1) {
                return null;
            }
        }
        // Reject dot-segments anywhere in the path (defence-in-depth
        // against path-traversal attempts after the NAAN prefix —
        // e.g. `ark:/12345/foo/../../etc/passwd` or trailing `/..`).
        $path = substr($normalised, 5); // strip 'ark:/'
        if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return null;
        }
        return $normalised;
    }

    /**
     * Phase 6 — RiC-O OAI-PMH support.
     *
     * Serialise a JSON-LD compact document (as produced by buildUnit /
     * buildAuthority / buildActivity / buildPlace / buildCollection) into
     * canonical RDF/XML on the provided XMLWriter. Caller is responsible
     * for opening the surrounding `<rdf:RDF>` element + namespace
     * declarations and for closing it afterwards — this method emits
     * one or more `<rico:Foo rdf:about="...">…</rico:Foo>` subjects.
     *
     * Mapping rules:
     *   - top-level `@graph` ⇒ emit one subject per array entry
     *   - `@id` ⇒ `rdf:about` (root) or `rdf:resource` (object reference)
     *   - `@type` ⇒ tag name for the subject element (CURIE expanded
     *     against the document's @context)
     *   - scalar string ⇒ `<prefix:local>value</prefix:local>`
     *   - `{"@value":"x","@language":"it"}` ⇒
     *     `<prefix:local xml:lang="it">x</prefix:local>`
     *   - `{"@value":"x","@type":"xsd:gYear"}` ⇒
     *     `<prefix:local rdf:datatype="…">x</prefix:local>`
     *   - `{"@id":"…"}` ⇒
     *     `<prefix:local rdf:resource="…"/>`
     *   - nested object with `@type` but no `@id` ⇒ blank node nested
     *     inside the property element
     *   - array of values ⇒ repeat the property element once per item
     *
     * Unknown @type CURIEs fall back to `rdf:Description`.
     *
     * @param array<string, mixed> $doc JSON-LD document to serialise.
     */
    public function serializeToRdfXml(array $doc, \XMLWriter $xw): void
    {
        $context = isset($doc['@context']) && is_array($doc['@context'])
            ? $doc['@context']
            : $this->context();

        if (isset($doc['@graph']) && is_array($doc['@graph'])) {
            foreach ($doc['@graph'] as $node) {
                if (is_array($node)) {
                    $this->writeRdfSubject($xw, $node, $context);
                }
            }
            return;
        }
        $this->writeRdfSubject($xw, $doc, $context);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $context
     */
    private function writeRdfSubject(\XMLWriter $xw, array $node, array $context): void
    {
        [$ns, $local] = $this->expandCurie((string) ($node['@type'] ?? 'rdf:Description'), $context);

        $xw->startElement(($ns['prefix'] === '' ? '' : $ns['prefix'] . ':') . $local);
        // F017: !empty('0') === true would silently drop a literal '0'
        // identifier; check existence and emptiness explicitly instead.
        // isset() already excludes null, so only the empty-string guard
        // is required after it.
        if (isset($node['@id']) && $node['@id'] !== '') {
            $xw->writeAttribute('rdf:about', (string) $node['@id']);
        }

        foreach ($node as $key => $value) {
            if ($key === '@id' || $key === '@type' || $key === '@context' || $key === '@graph') {
                continue;
            }
            $items = is_array($value) && array_is_list($value) ? $value : [$value];
            foreach ($items as $item) {
                $this->writeRdfProperty($xw, (string) $key, $item, $context);
            }
        }

        $xw->endElement();
    }

    /**
     * @param array<string, string> $context
     */
    private function writeRdfProperty(\XMLWriter $xw, string $curie, mixed $value, array $context): void
    {
        [$ns, $local] = $this->expandCurie($curie, $context);
        $tag = ($ns['prefix'] === '' ? '' : $ns['prefix'] . ':') . $local;

        // F010: emit explicit xsd datatypes for non-string scalars so
        // booleans don't become empty strings ((string) false === '') and
        // numeric literals carry the proper xsd:integer / xsd:double type.
        if (is_bool($value)) {
            $xw->startElement($tag);
            $xw->writeAttribute('rdf:datatype', self::NS_XSD . 'boolean');
            $xw->text($value ? 'true' : 'false');
            $xw->endElement();
            return;
        }
        if (is_int($value)) {
            $xw->startElement($tag);
            $xw->writeAttribute('rdf:datatype', self::NS_XSD . 'integer');
            $xw->text((string) $value);
            $xw->endElement();
            return;
        }
        if (is_float($value)) {
            $xw->startElement($tag);
            $xw->writeAttribute('rdf:datatype', self::NS_XSD . 'double');
            $xw->text((string) $value);
            $xw->endElement();
            return;
        }
        if (is_string($value)) {
            $xw->writeElement($tag, $value);
            return;
        }
        if (!is_array($value)) {
            return;
        }

        // Reference-only object: {"@id": "..."}
        if (isset($value['@id']) && !isset($value['@value']) && !isset($value['@type']) && count($value) === 1) {
            $xw->startElement($tag);
            $xw->writeAttribute('rdf:resource', (string) $value['@id']);
            $xw->endElement();
            return;
        }

        // F011: typed reference {"@id": "...", "@type": "..."} — emit the
        // compact rdf:resource form rather than the heavier striped
        // resource. Trade-off: the @type is dropped from the striped
        // output because RDF/XML cannot attach rdf:type to an external
        // reference in compact form; consumers must rely on the
        // dereferenced subject (or OWL/RDFS reasoning) to recover the
        // type. JSON-LD output is unaffected and still carries @type.
        if (isset($value['@id']) && isset($value['@type']) && !isset($value['@value']) && count($value) === 2) {
            $xw->startElement($tag);
            $xw->writeAttribute('rdf:resource', (string) $value['@id']);
            $xw->endElement();
            return;
        }

        // Typed/lang literal: {"@value": "...", "@language"|"@type": "..."}
        if (isset($value['@value'])) {
            $xw->startElement($tag);
            if (isset($value['@language']) && $value['@language'] !== '') {
                $xw->writeAttribute('xml:lang', (string) $value['@language']);
            } elseif (isset($value['@type']) && $value['@type'] !== '') {
                [$dns, $dlocal] = $this->expandCurie((string) $value['@type'], $context);
                $xw->writeAttribute('rdf:datatype', $dns['uri'] . $dlocal);
            }
            $xw->text((string) $value['@value']);
            $xw->endElement();
            return;
        }

        // Nested resource (blank node or addressable). Render as a child
        // subject inside the property element, RDF/XML "striped" form.
        $xw->startElement($tag);
        $this->writeRdfSubject($xw, $value, $context);
        $xw->endElement();
    }

    /**
     * Resolve a CURIE like "ric:title" against the @context.
     *
     * @param array<string, string> $context
     * @return array{0: array{prefix:string, uri:string}, 1: string}
     */
    private function expandCurie(string $curie, array $context): array
    {
        if (strpos($curie, ':') === false) {
            return [['prefix' => '', 'uri' => ''], $curie];
        }
        [$prefix, $local] = explode(':', $curie, 2);
        $uri = (string) ($context[$prefix] ?? '');
        if ($uri === '') {
            // Unknown prefix — fall through to rdf:Description / dc:format / etc.
            $known = [
                'rdf'  => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'rdfs' => self::NS_RDFS,
                'xsd'  => self::NS_XSD,
                'owl'  => self::NS_OWL,
                'ric'  => self::NS_RIC,
            ];
            $uri = $known[$prefix] ?? '';
        }
        return [['prefix' => $prefix, 'uri' => $uri], $local];
    }
}
