<?php

declare(strict_types=1);

namespace App\Plugins\BibframeLinkedData;

use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * BIBFRAME 2.0 Linked Data plugin for Pinakes v0.7.1.
 *
 * Exposes books as BIBFRAME 2.0 Linked Data with content negotiation:
 *   GET /api/bibframe/book/{id}           — Work + Instance @graph
 *   GET /api/bibframe/book/{id}/work      — bf:Work only
 *   GET /api/bibframe/book/{id}/instance  — bf:Instance only
 *
 * Content negotiation via Accept header:
 *   application/ld+json  (default) → JSON-LD
 *   text/turtle                    → Turtle (RDF)
 *   application/rdf+xml            → RDF/XML (minimal)
 *
 * BIBFRAME 2.0 spec: http://id.loc.gov/ontologies/bibframe/
 * Library of Congress BIBFRAME guidelines: https://www.loc.gov/bibframe/
 */
class BibframeLinkedDataPlugin
{
    private mysqli $db;
    /** @phpstan-ignore property.onlyWritten */
    private HookManager $hookManager;
    private ?int $pluginId = null;

    private const BF_NS   = 'http://id.loc.gov/ontologies/bibframe/';
    private const RDFS_NS = 'http://www.w3.org/2000/01/rdf-schema#';
    private const RDF_NS  = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const XSD_NS  = 'http://www.w3.org/2001/XMLSchema#';

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db          = $db;
        $this->hookManager = $hookManager;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function onActivate(): void
    {
        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register', 'registerRoutes', 30);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function onInstall(): void {}

    public function onDeactivate(): void
    {
        $this->db->begin_transaction();
        try {
            $this->deleteHooksFromDb();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ── Hook registration ─────────────────────────────────────────────────────

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) { return; }
        $del = $this->db->prepare(
            'DELETE FROM plugin_hooks WHERE plugin_id = ? AND hook_name = ? AND callback_method = ?'
        );
        if ($del !== false) {
            $del->bind_param('iss', $this->pluginId, $hookName, $method);
            $del->execute();
            $del->close();
        }
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())'
        );
        if ($stmt === false) {
            throw new \RuntimeException('[BibframeLD] prepare() failed: ' . $this->db->error);
        }
        $class = self::class;
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $class, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[BibframeLD] hook insert failed: ' . $err);
        }
        $stmt->close();
    }

    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) { return; }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('[BibframeLD] hook delete prepare() failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $this->pluginId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[BibframeLD] hook delete failed: ' . $err);
        }
        $stmt->close();
    }

    // ── Route registration ────────────────────────────────────────────────────

    public function registerRoutes($app): void
    {
        $plugin = $this;

        $app->get('/api/bibframe/book/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->bookGraphAction($request, $response, $args);
        });

        $app->get('/api/bibframe/book/{id:[0-9]+}/work', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->bookWorkAction($request, $response, $args);
        });

        $app->get('/api/bibframe/book/{id:[0-9]+}/instance', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->bookInstanceAction($request, $response, $args);
        });

        // Persistent linked-data URIs (Linked Data Principles / FAIR)
        // GET /id/work/{id}     — 303 for HTML; machine-readable → bf:Work JSON-LD
        // GET /id/instance/{id} — 303 for HTML; machine-readable → bf:Instance JSON-LD
        $app->get('/id/work/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->idWorkAction($request, $response, $args);
        });

        $app->get('/id/instance/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->idInstanceAction($request, $response, $args);
        });

        // GET /id/item/{id} — bf:Item URI (physical copy); redirect to Instance representation
        $app->get('/id/item/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->handleItemRequest($request, $response, $args);
        });
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function bookGraphAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $book = $this->fetchBook((int) ($args['id'] ?? 0));
        if ($book === null) {
            return $this->notFound($response);
        }
        $graph    = $this->buildGraph($book);
        return $this->serializeResponse($request, $response, $graph);
    }

    public function bookWorkAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $book = $this->fetchBook((int) ($args['id'] ?? 0));
        if ($book === null) {
            return $this->notFound($response);
        }
        $bookId  = (int) $book['id'];
        $graph   = $this->buildGraph($book);
        $workUri = absoluteUrl('/id/work/' . $bookId);
        $work    = array_values(array_filter($graph['@graph'], fn($n) => is_array($n) && ($n['@id'] ?? '') === $workUri))[0] ?? $graph;
        $doc     = ['@context' => $graph['@context'], '@graph' => [$work]];
        return $this->serializeResponse($request, $response, $doc);
    }

    public function bookInstanceAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $book = $this->fetchBook((int) ($args['id'] ?? 0));
        if ($book === null) {
            return $this->notFound($response);
        }
        $bookId      = (int) $book['id'];
        $graph       = $this->buildGraph($book);
        $instanceUri = absoluteUrl('/id/instance/' . $bookId);
        $instance    = array_values(array_filter($graph['@graph'], fn($n) => is_array($n) && ($n['@id'] ?? '') === $instanceUri))[0] ?? $graph;
        $doc         = ['@context' => $graph['@context'], '@graph' => [$instance]];
        return $this->serializeResponse($request, $response, $doc);
    }

    // ── Persistent linked-data URI handlers ──────────────────────────────────

    public function idWorkAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $book = $this->fetchBook((int) ($args['id'] ?? 0));
        if ($book === null) {
            return $this->notFound($response);
        }
        if ($this->wantsMachineReadable(strtolower($request->getHeaderLine('Accept')))) {
            $bookId  = (int) $book['id'];
            $graph   = $this->buildGraph($book);
            $nodes   = is_array($graph['@graph']) ? $graph['@graph'] : [];
            $workUri = absoluteUrl('/id/work/' . $bookId);
            $node    = null;
            foreach ($nodes as $n) {
                if (is_array($n) && ($n['@id'] ?? '') === $workUri) { $node = $n; break; }
            }
            $doc = ['@context' => $graph['@context'], '@graph' => [$node ?? $graph]];
            return $this->serializeResponse($request, $response, $doc);
        }
        return $response->withStatus(303)->withHeader('Location', absoluteUrl(book_url($book)));
    }

    public function idInstanceAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $book = $this->fetchBook((int) ($args['id'] ?? 0));
        if ($book === null) {
            return $this->notFound($response);
        }
        if ($this->wantsMachineReadable(strtolower($request->getHeaderLine('Accept')))) {
            $bookId      = (int) $book['id'];
            $graph       = $this->buildGraph($book);
            $nodes       = is_array($graph['@graph']) ? $graph['@graph'] : [];
            $instanceUri = absoluteUrl('/id/instance/' . $bookId);
            $node        = null;
            foreach ($nodes as $n) {
                if (is_array($n) && ($n['@id'] ?? '') === $instanceUri) { $node = $n; break; }
            }
            $doc = ['@context' => $graph['@context'], '@graph' => [$node ?? $graph]];
            return $this->serializeResponse($request, $response, $doc);
        }
        return $response->withStatus(303)->withHeader('Location', absoluteUrl(book_url($book)));
    }

    public function handleItemRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $response->withStatus(404);
        }
        // Item is a physical copy — redirect to the Instance representation (303 See Other)
        $instanceUri = absoluteUrl('/id/instance/' . $id);
        return $response->withHeader('Location', $instanceUri)->withStatus(303);
    }

    private function wantsMachineReadable(string $accept): bool
    {
        // FIX F044: Do NOT match plain `application/json` here. serializeResponse()
        // emits `Content-Type: application/ld+json`, so a client that asked only
        // for `application/json` would receive a different media type and a strict
        // negotiator would reject the response. Clients that want the JSON-LD
        // representation must request `application/ld+json` (or another RDF
        // serialization) explicitly. Plain JSON callers fall through to the
        // HTML-redirect branch like any other browser request.
        return str_contains($accept, 'application/ld+json')
            || str_contains($accept, 'text/turtle')
            || str_contains($accept, 'application/rdf+xml');
    }

    // ── BIBFRAME graph builder ────────────────────────────────────────────────

    /**
     * Build a BIBFRAME 2.0 @graph with Work + Instance + Item nodes.
     *
     * @param array<string, mixed> $book
     * @return array<string, mixed>
     */
    private function buildGraph(array $book): array
    {
        $bookId     = (int) $book['id'];
        $authors    = $this->fetchAuthors($bookId);
        $publishers = $this->fetchPublishersForBook($bookId, !empty($book['editore_id']) ? (int) $book['editore_id'] : null);
        $genre      = !empty($book['genere_id'])  ? $this->fetchGenre((int) $book['genere_id'])     : null;

        $title    = (string) ($book['titolo'] ?? '');
        $subtitle = (string) ($book['sottotitolo'] ?? '');
        $year     = (string) ($book['anno_pubblicazione'] ?? '');
        $langCode = $this->langCode((string) ($book['lingua'] ?? ''));
        $viafId   = (string) ($book['viaf_id'] ?? '');

        $workUri     = absoluteUrl('/id/work/' . $bookId);
        $instanceUri = absoluteUrl('/id/instance/' . $bookId);

        // bf:Work node
        $mainTitleVal = $langCode !== ''
            ? ['@value' => $title, '@language' => $langCode]
            : ['@value' => $title];
        $work = [
            '@id'   => $workUri,
            '@type' => 'bf:Work',
            'bf:title' => [
                '@type'         => 'bf:Title',
                'bf:mainTitle'  => $mainTitleVal,
            ],
        ];

        if ($subtitle !== '') {
            $subtitleVal = $langCode !== ''
                ? ['@value' => $subtitle, '@language' => $langCode]
                : ['@value' => $subtitle];
            $work['bf:title']['bf:subtitle'] = $subtitleVal;
        }

        if ($langCode !== '') {
            $work['bf:language'] = [
                '@type'   => 'bf:Language',
                'bf:code' => ['@value' => $langCode, '@type' => 'xsd:string'],
            ];
        }

        $work['bf:content'] = ['@type' => 'bf:Content', '@id' => 'http://id.loc.gov/vocabulary/contentTypes/txt'];

        // FIX F045: Work-level VIAF link (mirrors per-author VIAF pattern below).
        // If the book record carries a Work-level VIAF identifier, expose it as
        // owl:sameAs on the bf:Work — same shape used for bf:Person agents.
        if ($viafId !== '') {
            $work['owl:sameAs'] = [
                '@id' => 'https://viaf.org/viaf/' . rawurlencode($viafId),
            ];
        }

        // Contributions (authors)
        $contributions = [];
        foreach ($authors as $a) {
            $role    = (string) ($a['ruolo'] ?? 'autore');
            $relUri  = $this->marcRelatorUri($role);
            $contrib = [
                '@type'    => 'bf:Contribution',
                'bf:agent' => [
                    '@type'      => 'bf:Person',
                    'rdfs:label' => (string) $a['nome'],
                ],
                'bf:role'  => ['@id' => $relUri],
            ];
            // Link VIAF if available (stored on the author row)
            if (!empty($a['viaf_id'])) {
                $contrib['bf:agent']['owl:sameAs'] = [
                    '@id' => 'https://viaf.org/viaf/' . rawurlencode((string) $a['viaf_id']),
                ];
            }
            $contributions[] = $contrib;
        }
        if (!empty($contributions)) {
            $work['bf:contribution'] = count($contributions) === 1 ? $contributions[0] : $contributions;
        }

        // Genre form
        if ($genre !== null && !empty($genre['nome'])) {
            $work['bf:genreForm'] = [
                '@type'      => 'bf:GenreForm',
                'rdfs:label' => (string) $genre['nome'],
            ];
        }

        // Subject — keywords
        if (!empty($book['parole_chiave'])) {
            $kws = array_filter(array_map('trim', explode(',', (string) $book['parole_chiave'])));
            if (!empty($kws)) {
                $kwNodes = array_map(fn($k) => ['@type' => 'bf:Topic', 'rdfs:label' => $k], array_values($kws));
                /** @phpstan-ignore isset.offset */
                $existing = isset($work['bf:subject']) ? [$work['bf:subject']] : [];
                $work['bf:subject'] = array_merge($existing, $kwNodes);
            }
        }

        // Dewey classification
        if (!empty($book['classificazione_dewey'])) {
            $work['bf:classification'] = [
                '@type'     => 'bf:ClassificationDdc',
                'rdf:value' => (string) $book['classificazione_dewey'],
            ];
        }

        $work['bf:hasInstance'] = ['@id' => $instanceUri];

        // bf:Instance node
        $instanceMainTitleVal = $langCode !== ''
            ? ['@value' => $title, '@language' => $langCode]
            : ['@value' => $title];
        $instance = [
            '@id'            => $instanceUri,
            '@type'          => 'bf:Instance',
            'bf:instanceOf' => ['@id' => $workUri],
            'bf:title'       => [
                '@type'        => 'bf:Title',
                'bf:mainTitle' => $instanceMainTitleVal,
            ],
            'bf:carrier' => ['@id' => 'http://id.loc.gov/vocabulary/carriers/nc'],
            'bf:media'   => ['@id' => 'http://id.loc.gov/vocabulary/mediaTypes/n'],
        ];

        // Identifiers
        $identifiers = [];
        if (!empty($book['isbn13'])) {
            $identifiers[] = ['@type' => 'bf:Isbn', 'rdf:value' => (string) $book['isbn13']];
        } elseif (!empty($book['isbn10'])) {
            $identifiers[] = ['@type' => 'bf:Isbn', 'rdf:value' => (string) $book['isbn10']];
        }
        if (!empty($book['ean'])) {
            $identifiers[] = ['@type' => 'bf:Identifier', 'bf:source' => ['@type' => 'bf:Source', 'rdfs:label' => 'EAN'], 'rdf:value' => (string) $book['ean']];
        }
        if (!empty($identifiers)) {
            $instance['bf:identifiedBy'] = count($identifiers) === 1 ? $identifiers[0] : $identifiers;
        }

        // Provision activity (publication). BIBFRAME allows multiple
        // bf:provisionActivity nodes — emit one bf:Publication per publisher
        // (each carrying the publication date + agent), mirroring how authors
        // map to multiple bf:contribution nodes.
        $provisionNodes = [];
        foreach ($publishers as $pub) {
            if (empty($pub['nome'])) { continue; }
            $node = ['@type' => 'bf:Publication'];
            if ($year !== '') {
                $node['bf:date'] = ['@value' => $year, '@type' => 'xsd:gYear'];
            }
            $node['bf:agent'] = ['@type' => 'bf:Agent', 'rdfs:label' => (string) $pub['nome']];
            $provisionNodes[] = $node;
        }
        if ($provisionNodes === [] && $year !== '') {
            // No publisher, but the publication year is known.
            $provisionNodes[] = ['@type' => 'bf:Publication', 'bf:date' => ['@value' => $year, '@type' => 'xsd:gYear']];
        }
        if ($provisionNodes !== []) {
            $instance['bf:provisionActivity'] = count($provisionNodes) === 1 ? $provisionNodes[0] : $provisionNodes;
        }

        // Extent (pages)
        if (!empty($book['numero_pagine'])) {
            $instance['bf:extent'] = [
                '@type'      => 'bf:Extent',
                'rdfs:label' => (string) $book['numero_pagine'] . ' pages',
            ];
        }

        // Series
        if (!empty($book['collana'])) {
            $seriesTitle = (string) $book['collana'];
            if (!empty($book['numero_serie'])) {
                $seriesTitle .= ', ' . (string) $book['numero_serie'];
            }
            $instance['bf:seriesStatement'] = $seriesTitle;
        }

        // Summary
        $desc = !empty($book['descrizione_plain']) ? $book['descrizione_plain'] : ($book['descrizione'] ?? '');
        if ($desc !== '') {
            $instance['bf:summary'] = [
                '@type'      => 'bf:Summary',
                'rdfs:label' => strip_tags((string) $desc),
            ];
        }

        // bf:Item node
        $itemUri = absoluteUrl('/id/item/' . $bookId);
        $item = [
            '@id'       => $itemUri,
            '@type'     => 'bf:Item',
            'bf:itemOf' => ['@id' => $instanceUri],
            'bf:heldBy' => ['@id' => rtrim(absoluteUrl('/'), '/'), '@type' => 'bf:Agent'],
        ];
        $instance['bf:hasItem'] = ['@id' => $itemUri];

        return [
            '@context' => [
                'bf'   => self::BF_NS,
                'rdfs' => self::RDFS_NS,
                'rdf'  => self::RDF_NS,
                'xsd'  => self::XSD_NS,
                'owl'  => 'http://www.w3.org/2002/07/owl#',
            ],
            '@graph' => [$work, $instance, $item],
        ];
    }

    // ── Content negotiation serializer ────────────────────────────────────────

    /**
     * @param array<string, mixed> $graph
     */
    private function serializeResponse(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $graph
    ): ResponseInterface {
        $accept = strtolower($request->getHeaderLine('Accept'));

        if (str_contains($accept, 'text/turtle')) {
            $body        = $this->toTurtle($graph);
            $contentType = 'text/turtle; charset=utf-8';
        } elseif (str_contains($accept, 'application/rdf+xml')) {
            $body        = $this->toRdfXml($graph);
            $contentType = 'application/rdf+xml; charset=utf-8';
        } else {
            $body        = (string) json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $contentType = 'application/ld+json; charset=utf-8';
        }

        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Vary', 'Accept');
    }

    /**
     * Minimal Turtle serializer for our specific BIBFRAME graph structure.
     *
     * @param array<string, mixed> $graph
     */
    private function toTurtle(array $graph): string
    {
        $ctx = (array) ($graph['@context'] ?? []);
        $lines = [];

        foreach ($ctx as $prefix => $uri) {
            if (is_string($uri)) {
                $lines[] = "@prefix {$prefix}: <{$uri}> .";
            }
        }
        $lines[] = '';

        foreach ((array) ($graph['@graph'] ?? []) as $node) {
            if (!is_array($node)) { continue; }
            $id = (string) ($node['@id'] ?? '');
            if ($id === '') { continue; }
            $lines[] = '<' . $id . '>';
            $type = (string) ($node['@type'] ?? '');
            if ($type !== '') {
                $lines[] = '    a ' . $type . ' ;';
            }
            foreach ($node as $prop => $val) {
                if (in_array($prop, ['@id', '@type'], true)) { continue; }
                if (!is_array($val)) {
                    // scalar value — emit as a literal
                    $turtleVal = '"' . $this->escapeTurtleString((string) $val) . '"';
                } else {
                    $turtleVal = $this->turtleValues($val, $ctx);
                }
                if ($turtleVal !== '') {
                    $lines[] = '    ' . $prop . ' ' . $turtleVal . ' ;';
                }
            }
            // Replace last ';' with '.'
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (str_ends_with(rtrim($lines[$i]), ';')) {
                    $lines[$i] = rtrim(substr(rtrim($lines[$i]), 0, -1)) . ' .';
                    break;
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Serialize a predicate value for Turtle, handling both single nodes and numeric lists.
     *
     * @param array<mixed> $val
     * @param array<string, string> $ctx
     */
    private function turtleValues(array $val, array $ctx): string
    {
        // Numeric-indexed array → multi-value predicate (e.g. bf:contribution, bf:subject)
        if (array_is_list($val)) {
            $items = [];
            foreach ($val as $item) {
                if (is_array($item)) {
                    $rendered = $this->turtleValue($item, $ctx);
                    if ($rendered !== '') {
                        $items[] = $rendered;
                    }
                }
            }
            return implode(' , ', $items);
        }
        return $this->turtleValue($val, $ctx);
    }

    /**
     * @param array<string, mixed> $val
     * @param array<string, string> $ctx
     */
    private function turtleValue(array $val, array $ctx): string
    {
        if (isset($val['@id'])) {
            return '<' . (string) $val['@id'] . '>';
        }
        if (isset($val['@value'])) {
            $v = $this->escapeTurtleString((string) $val['@value']);
            if (isset($val['@language'])) {
                return '"' . $v . '"@' . (string) $val['@language'];
            }
            if (isset($val['@type'])) {
                $dt = (string) $val['@type'];
                // Expand prefix if needed
                foreach ($ctx as $prefix => $uri) {
                    if (str_starts_with($dt, $prefix . ':')) {
                        $dt = '<' . $uri . substr($dt, strlen($prefix) + 1) . '>';
                        break;
                    }
                }
                if (!str_starts_with($dt, '<')) { $dt = '<' . $dt . '>'; }
                return '"' . $v . '"^^' . $dt;
            }
            return '"' . $v . '"';
        }
        if (isset($val['@type'])) {
            // Blank node — serialize all properties recursively
            $type  = (string) $val['@type'];
            $parts = ['a ' . $type];
            foreach ($val as $prop => $propVal) {
                if (in_array($prop, ['@id', '@type'], true)) {
                    continue;
                }
                $inner = is_array($propVal)
                    ? $this->turtleValues($propVal, $ctx)
                    : '"' . $this->escapeTurtleString((string) $propVal) . '"';
                if ($inner !== '') {
                    $parts[] = $prop . ' ' . $inner;
                }
            }
            return '[ ' . implode(' ; ', $parts) . ' ]';
        }
        return '';
    }

    private function escapeTurtleString(string $s): string
    {
        return str_replace(
            ['\\',  '"',   "\n",  "\r",  "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $s
        );
    }

    /**
     * Minimal RDF/XML serializer for our BIBFRAME graph.
     *
     * @param array<string, mixed> $graph
     */
    private function toRdfXml(array $graph): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs('rdf', 'RDF', self::RDF_NS);
        $xw->writeAttributeNs('xmlns', 'bf',   null, self::BF_NS);
        $xw->writeAttributeNs('xmlns', 'rdfs', null, self::RDFS_NS);
        $xw->writeAttributeNs('xmlns', 'owl',  null, 'http://www.w3.org/2002/07/owl#');
        $xw->writeAttributeNs('xmlns', 'xsd',  null, self::XSD_NS);

        foreach ((array) ($graph['@graph'] ?? []) as $node) {
            if (!is_array($node)) { continue; }
            $id   = (string) ($node['@id'] ?? '');
            $type = (string) ($node['@type'] ?? '');
            if ($id === '' || !str_contains($type, ':')) { continue; }
            [$tPfx, $tLocal] = explode(':', $type, 2);
            $xw->startElementNs($tPfx, $tLocal, null);
            $xw->writeAttributeNs('rdf', 'about', null, $id);
            $this->writeRdfXmlProperties($xw, $node);
            $xw->endElement();
        }

        $xw->endElement(); // rdf:RDF
        $xw->endDocument();
        return $xw->outputMemory();
    }

    /**
     * @param array<string, mixed> $node
     */
    private function writeRdfXmlProperties(\XMLWriter $xw, array $node): void
    {
        foreach ($node as $predicate => $value) {
            if ($predicate === '@id' || $predicate === '@type' || !str_contains($predicate, ':')) {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $entry) {
                    $this->writeRdfXmlProperty($xw, $predicate, $entry);
                }
                continue;
            }
            $this->writeRdfXmlProperty($xw, $predicate, $value);
        }
    }

    private function writeRdfXmlProperty(\XMLWriter $xw, string $predicate, mixed $value): void
    {
        [$pfx, $local] = explode(':', $predicate, 2);
        $xw->startElementNs($pfx, $local, null);

        if (is_array($value)) {
            if (!empty($value['@id'])) {
                $xw->writeAttributeNs('rdf', 'resource', null, (string) $value['@id']);
                $xw->endElement();
                return;
            }
            if (array_key_exists('@value', $value)) {
                if (!empty($value['@language'])) {
                    $xw->writeAttribute('xml:lang', (string) $value['@language']);
                }
                if (!empty($value['@type']) && is_string($value['@type']) && str_contains($value['@type'], ':')) {
                    $xw->writeAttributeNs('rdf', 'datatype', null, $this->expandCurie($value['@type']));
                }
                $xw->text((string) $value['@value']);
                $xw->endElement();
                return;
            }
            if (!empty($value['@type']) && is_string($value['@type']) && str_contains($value['@type'], ':')) {
                [$typePfx, $typeLocal] = explode(':', $value['@type'], 2);
                $xw->startElementNs($typePfx, $typeLocal, null);
                $this->writeRdfXmlProperties($xw, $value);
                $xw->endElement();
                $xw->endElement();
                return;
            }
        }

        $xw->text((string) $value);
        $xw->endElement();
    }

    private function expandCurie(string $curie): string
    {
        [$pfx, $local] = explode(':', $curie, 2);
        return match ($pfx) {
            'bf'   => self::BF_NS . $local,
            'rdf'  => self::RDF_NS . $local,
            'rdfs' => self::RDFS_NS . $local,
            'xsd'  => self::XSD_NS . $local,
            'owl'  => 'http://www.w3.org/2002/07/owl#' . $local,
            default => $curie,
        };
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function fetchBook(int $id): ?array
    {
        if ($id <= 0) { return null; }
        $stmt = $this->db->prepare(
            'SELECT l.*, a.viaf_id AS viaf_id, a.nome AS autore_principale
               FROM libri l
               LEFT JOIN autori a ON a.id = (
                   SELECT la2.autore_id FROM libri_autori la2 WHERE la2.libro_id = l.id
                   ORDER BY COALESCE(la2.ordine_credito, 0), la2.autore_id LIMIT 1
               )
              WHERE l.id = ? AND l.deleted_at IS NULL'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAuthors(int $bookId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.nome, a.viaf_id, la.ruolo
               FROM libri_autori la
               JOIN autori a ON a.id = la.autore_id
              WHERE la.libro_id = ?
              ORDER BY COALESCE(la.ordine_credito, 0), la.autore_id'
        );
        if ($stmt === false) { return []; }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        if ($res instanceof \mysqli_result) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPublisher(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM editori WHERE id = ?');
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = ($result instanceof \mysqli_result) ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * All publishers for a book (issue #143), ordered. Falls back to the single
     * primary publisher (editore_id) when the libri_editori junction is empty or
     * absent (installs predating the multi-publisher migration).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchPublishersForBook(int $bookId, ?int $primaryEditoreId): array
    {
        $out = [];
        $stmt = $this->db->prepare(
            'SELECT e.id, e.nome
               FROM libri_editori le
               JOIN editori e ON e.id = le.editore_id
              WHERE le.libro_id = ?
              ORDER BY le.ordine, e.nome'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) { $out[] = $r; }
                $result->free();
            }
            $stmt->close();
        }
        if ($out === [] && $primaryEditoreId !== null && $primaryEditoreId > 0) {
            $single = $this->fetchPublisher($primaryEditoreId);
            if ($single !== null) { $out[] = $single; }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGenre(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM generi WHERE id = ?');
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = ($result instanceof \mysqli_result) ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function langCode(string $lingua): string
    {
        $map = [
            'italiano' => 'it', 'italian' => 'it',
            'inglese'  => 'en', 'english' => 'en',
            'francese' => 'fr', 'français' => 'fr', 'french' => 'fr',
            'tedesco'  => 'de', 'deutsch' => 'de', 'german' => 'de',
            'spagnolo' => 'es', 'español' => 'es', 'spanish' => 'es',
            'ita' => 'it', 'eng' => 'en', 'fre' => 'fr', 'ger' => 'de', 'spa' => 'es',
        ];
        return $map[strtolower(trim($lingua))] ?? '';
    }

    private function marcRelatorUri(string $role): string
    {
        return match ($role) {
            'traduttore'   => 'http://id.loc.gov/vocabulary/relators/trl',
            'curatore'     => 'http://id.loc.gov/vocabulary/relators/edt',
            'illustratore' => 'http://id.loc.gov/vocabulary/relators/ill',
            default        => 'http://id.loc.gov/vocabulary/relators/aut',
        };
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $body = (string) json_encode(['error' => true, 'message' => __('Libro non trovato.')]);
        $response->getBody()->write($body);
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
