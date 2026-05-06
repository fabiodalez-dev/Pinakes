<?php

declare(strict_types=1);

namespace App\Plugins\ViafAuthority;

use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * VIAF + ISNI Authority Control plugin for Pinakes v0.7.4.
 *
 * Endpoints:
 *   GET  /api/viaf/suggest?q=...          — proxies VIAF AutoSuggest (returns viaf_id + isni)
 *   GET  /api/viaf/author/{id}            — retrieves authority data for an author
 *   POST /api/viaf/author/{id}/set        — stores VIAF + ISNI for an author
 *   POST /api/viaf/author/{id}/isni/set   — stores ISNI only for an author
 *
 * VIAF AutoSuggest API: https://viaf.org/viaf/AutoSuggest?query=NAME
 * ISNI SRU API: https://isni.oclc.org/sru/?operation=searchRetrieve&query=pica.nw=NAME
 */
class ViafAuthorityPlugin
{
    private mysqli $db;
    /** @phpstan-ignore-next-line property.onlyWritten */
    private HookManager $hookManager;
    private ?int $pluginId = null;

    /** VIAF AutoSuggest base URL */
    private const VIAF_SUGGEST_URL = 'https://viaf.org/viaf/AutoSuggest?query=';

    /** HTTP timeout for external API requests (seconds) */
    private const API_TIMEOUT = 5;

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
        $this->ensureSchemaColumns();
        $this->ensureAlternatesTable();
        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register', 'registerRoutes', 20);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function onDeactivate(): void
    {
        $this->deleteHooksFromDb();
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    private function ensureSchemaColumns(): void
    {
        $columns = $this->getExistingColumns('autori');

        if (!isset($columns['viaf_id'])) {
            $this->db->query("ALTER TABLE autori ADD COLUMN viaf_id VARCHAR(50) DEFAULT NULL AFTER sito_web");
            $this->db->query("ALTER TABLE autori ADD KEY idx_viaf_id (viaf_id)");
        }
        if (!isset($columns['viaf_uri'])) {
            $this->db->query("ALTER TABLE autori ADD COLUMN viaf_uri VARCHAR(500) DEFAULT NULL AFTER viaf_id");
        }
        if (!isset($columns['isni_id'])) {
            $this->db->query("ALTER TABLE autori ADD COLUMN isni_id CHAR(19) DEFAULT NULL AFTER viaf_uri");
            $this->db->query("ALTER TABLE autori ADD UNIQUE KEY uq_isni_id (isni_id)");
        }
        if (!isset($columns['isni_uri'])) {
            $this->db->query("ALTER TABLE autori ADD COLUMN isni_uri VARCHAR(500) DEFAULT NULL AFTER isni_id");
        }
        if (!isset($columns['authority_source'])) {
            $this->db->query(
                "ALTER TABLE autori ADD COLUMN authority_source ENUM('manual','viaf','isni','sbn','wikidata') DEFAULT NULL AFTER isni_uri"
            );
        }
        if (!isset($columns['authority_confidence'])) {
            $this->db->query(
                "ALTER TABLE autori ADD COLUMN authority_confidence ENUM('exact','probable','candidate','rejected') DEFAULT NULL AFTER authority_source"
            );
        }
    }

    /** @return array<string, true> column name → true */
    private function getExistingColumns(string $table): array
    {
        $res = $this->db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $this->db->real_escape_string($table) . "'"
        );
        if (!($res instanceof \mysqli_result)) { return []; }
        $cols = [];
        while ($row = $res->fetch_assoc()) {
            $cols[(string) $row['COLUMN_NAME']] = true;
        }
        $res->free();
        return $cols;
    }

    private function ensureAlternatesTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS author_authority_alternates (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                autore_id      INT NOT NULL,
                source_code    VARCHAR(20)  NOT NULL,
                source_id      VARCHAR(100) NOT NULL,
                source_uri     VARCHAR(500) NULL,
                preferred_form VARCHAR(500) NULL,
                created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_aaa_autore FOREIGN KEY (autore_id) REFERENCES autori(id) ON DELETE CASCADE,
                UNIQUE KEY uq_source (source_code, source_id),
                INDEX idx_autore_id (autore_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    // ── Hook registration ─────────────────────────────────────────────────────

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[ViafAuthority] pluginId not set; cannot register hook ' . $hookName);
            return;
        }
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
            throw new \RuntimeException('[ViafAuthority] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        $class = self::class;
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $class, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[ViafAuthority] hook insert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }

    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) { return; }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) { return; }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    // ── Route registration ────────────────────────────────────────────────────

    public function registerRoutes($app): void
    {
        $plugin = $this;

        $app->get('/api/viaf/suggest', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->viafSuggestAction($request, $response);
        });

        $app->get('/api/viaf/author/{id}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->getAuthorViafAction($request, $response, $args);
        });

        $app->post('/api/viaf/author/{id}/set', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->setAuthorViafAction($request, $response, $args);
        });

        // POST /api/viaf/author/{id}/isni/set — store ISNI only
        $app->post('/api/viaf/author/{id}/isni/set', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->setAuthorIsniAction($request, $response, $args);
        });

        // W3C Reconciliation API (OpenRefine compatible)
        // GET  /admin/api/reconcile → service manifest
        // POST /admin/api/reconcile → batch reconciliation queries
        $app->get('/admin/api/reconcile', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->reconcileAction($request, $response);
        });

        $app->post('/admin/api/reconcile', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->reconcileAction($request, $response);
        });
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function viafSuggestAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff($response, $out, $request)) {
            return $out;
        }

        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));

        if ($q === '') {
            return $this->json($response, ['error' => true, 'message' => __('Parametro q mancante.')], 400);
        }
        if (strlen($q) < 2) {
            return $this->json($response, ['error' => true, 'message' => __('La query deve avere almeno 2 caratteri.')], 400);
        }

        $url = self::VIAF_SUGGEST_URL . urlencode($q);
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => self::API_TIMEOUT,
                'follow_location' => 1,
                'header'          => 'Accept: application/json',
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            SecureLogger::warning('[ViafAuthority] VIAF API unreachable for query: ' . $q);
            return $this->json($response, [
                'error'   => true,
                'message' => __('Servizio VIAF non raggiungibile. Riprova più tardi.'),
            ], 503);
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->json($response, ['error' => true, 'message' => __('Risposta VIAF non valida.')], 502);
        }

        $results = [];
        foreach ((array) ($data['result'] ?? []) as $item) {
            if (!is_array($item)) { continue; }
            $viafId = (string) ($item['viafid'] ?? '');
            $term   = (string) ($item['term']   ?? '');
            if ($viafId === '' || $term === '') { continue; }

            // VIAF AutoSuggest returns raw 16-digit ISNI without spaces
            $rawIsni = preg_replace('/\s+/', '', (string) ($item['isni'] ?? ''));
            $isniId  = ($rawIsni !== '' && self::isValidIsni($rawIsni)) ? $rawIsni : null;
            $isniUri = $isniId !== null ? 'https://isni.org/isni/' . $isniId : null;

            $results[] = [
                'viafid'    => $viafId,
                'name'      => $term,
                'nametype'  => (string) ($item['nametype'] ?? 'unknown'),
                'viafUrl'   => 'https://viaf.org/viaf/' . $viafId,
                'lc'        => (string) ($item['lc']   ?? ''),
                'dnb'       => (string) ($item['dnb']  ?? ''),
                'isni_id'   => $isniId,
                'isni_uri'  => $isniUri,
            ];
        }

        return $this->json($response, ['results' => $results]);
    }

    public function getAuthorViafAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff($response, $out, $request)) {
            return $out;
        }

        $id   = (int) ($args['id'] ?? 0);
        $stmt = $this->db->prepare(
            'SELECT id, nome, viaf_id, viaf_uri, isni_id, isni_uri, authority_source, authority_confidence
               FROM autori WHERE id = ?'
        );
        if ($stmt === false) {
            return $this->json($response, ['error' => true, 'message' => __('Errore interno.')], 500);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return $this->json($response, ['error' => true, 'message' => __('Autore non trovato.')], 404);
        }

        $viafId = $row['viaf_id'] !== null ? (string) $row['viaf_id'] : null;
        $isniId = $row['isni_id'] !== null ? (string) $row['isni_id'] : null;

        return $this->json($response, [
            'id'                   => (int) $row['id'],
            'nome'                 => (string) $row['nome'],
            'viaf_id'              => $viafId,
            'viaf_uri'             => $row['viaf_uri'] !== null ? (string) $row['viaf_uri'] : ($viafId !== null ? 'https://viaf.org/viaf/' . $viafId : null),
            'isni_id'              => $isniId,
            'isni_uri'             => $row['isni_uri'] !== null ? (string) $row['isni_uri'] : ($isniId !== null ? 'https://isni.org/isni/' . $isniId : null),
            'authority_source'     => $row['authority_source'],
            'authority_confidence' => $row['authority_confidence'],
        ]);
    }

    public function setAuthorViafAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff($response, $out, $request)) {
            return $out;
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $id     = (int) ($args['id'] ?? 0);
        $viafId = trim((string) ($body['viaf_id'] ?? ''));
        $isniRaw = preg_replace('/\s+/', '', trim((string) ($body['isni_id'] ?? '')));

        // Validate VIAF ID (numeric string)
        if ($viafId !== '' && !preg_match('/^\d{1,22}$/', $viafId)) {
            return $this->json($response, ['error' => true, 'message' => __('VIAF ID non valido. Deve essere un numero.')], 400);
        }

        // Validate ISNI if provided
        if ($isniRaw !== '' && !self::isValidIsni($isniRaw)) {
            return $this->json($response, ['error' => true, 'message' => __('ISNI non valido (check digit errato o formato errato).')], 400);
        }

        $viafIdParam  = $viafId !== '' ? $viafId : null;
        $viafUriParam = $viafIdParam !== null ? 'https://viaf.org/viaf/' . $viafIdParam : null;
        $isniIdParam  = $isniRaw !== '' ? $isniRaw : null;
        $isniUriParam = $isniIdParam !== null ? 'https://isni.org/isni/' . $isniIdParam : null;
        $sourceParam  = $viafIdParam !== null ? 'viaf' : null;
        $confParam    = $viafIdParam !== null ? 'exact' : null;

        $stmt = $this->db->prepare(
            'UPDATE autori
                SET viaf_id = ?, viaf_uri = ?, isni_id = ?, isni_uri = ?,
                    authority_source = ?, authority_confidence = ?
              WHERE id = ?'
        );
        if ($stmt === false) {
            return $this->json($response, ['error' => true, 'message' => __('Errore interno.')], 500);
        }
        $stmt->bind_param('ssssssi', $viafIdParam, $viafUriParam, $isniIdParam, $isniUriParam, $sourceParam, $confParam, $id);
        $ok       = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if (!$ok || $affected === 0) {
            return $this->json($response, ['error' => true, 'message' => __('Autore non trovato.')], 404);
        }

        return $this->json($response, [
            'success'  => true,
            'message'  => $viafIdParam !== null ? __('Dati VIAF/ISNI salvati.') : __('Dati VIAF/ISNI rimossi.'),
            'viaf_id'  => $viafIdParam,
            'isni_id'  => $isniIdParam,
        ]);
    }

    public function setAuthorIsniAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff($response, $out, $request)) {
            return $out;
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $id      = (int) ($args['id'] ?? 0);
        $isniRaw = preg_replace('/\s+/', '', trim((string) ($body['isni_id'] ?? '')));

        if ($isniRaw !== '' && !self::isValidIsni($isniRaw)) {
            return $this->json($response, ['error' => true, 'message' => __('ISNI non valido (check digit errato o formato errato).')], 400);
        }

        $isniIdParam  = $isniRaw !== '' ? $isniRaw : null;
        $isniUriParam = $isniIdParam !== null ? 'https://isni.org/isni/' . $isniIdParam : null;

        $stmt = $this->db->prepare('UPDATE autori SET isni_id = ?, isni_uri = ? WHERE id = ?');
        if ($stmt === false) {
            return $this->json($response, ['error' => true, 'message' => __('Errore interno.')], 500);
        }
        $stmt->bind_param('ssi', $isniIdParam, $isniUriParam, $id);
        $ok       = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if (!$ok || $affected === 0) {
            return $this->json($response, ['error' => true, 'message' => __('Autore non trovato.')], 404);
        }

        return $this->json($response, [
            'success' => true,
            'message' => $isniIdParam !== null ? __('ISNI salvato.') : __('ISNI rimosso.'),
            'isni_id' => $isniIdParam,
        ]);
    }

    // ── ISNI validation ───────────────────────────────────────────────────────

    /**
     * Validates an ISNI using the MOD 11-2 check digit algorithm (ISO/IEC 7064).
     * Accepts with or without spaces (e.g. "0000000121436345" or "0000 0001 2143 6345").
     */
    public static function isValidIsni(string $isni): bool
    {
        $digits = preg_replace('/[\s-]/', '', $isni);
        if ($digits === null || !preg_match('/^\d{15}[\dX]$/i', $digits)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $sum = ($sum + (int) $digits[$i]) * 2;
        }
        $remainder  = $sum % 11;
        $checkDigit = (12 - $remainder) % 11;
        $expected   = $checkDigit === 10 ? 'X' : (string) $checkDigit;
        return strtoupper($digits[15]) === $expected;
    }

    /**
     * Formats a 16-digit ISNI as "XXXX XXXX XXXX XXXX" for display.
     */
    public static function formatIsni(string $isni): string
    {
        $digits = preg_replace('/\s+/', '', $isni) ?? '';
        if (strlen($digits) !== 16) { return $isni; }
        return implode(' ', str_split($digits, 4));
    }

    // ── W3C Reconciliation API ────────────────────────────────────────────────

    /**
     * GET  /admin/api/reconcile → W3C Reconciliation service manifest
     * POST /admin/api/reconcile → batch reconciliation (OpenRefine compatible)
     *
     * Spec: https://reconciliation-api.github.io/specs/latest/
     * Supports JSONP via `?callback=` for legacy OpenRefine clients.
     */
    public function reconcileAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff($response, $out, $request)) {
            return $out;
        }

        $params   = $request->getQueryParams();
        $callback = isset($params['callback']) ? preg_replace('/[^a-zA-Z0-9_$]/', '', (string) $params['callback']) : null;
        $method   = strtoupper($request->getMethod());

        // GET or POST without queries → return service manifest
        $queriesRaw = '';
        if ($method === 'POST') {
            $body       = (array) ($request->getParsedBody() ?? []);
            $queriesRaw = trim((string) ($body['queries'] ?? $params['queries'] ?? ''));
        } else {
            $queriesRaw = trim((string) ($params['queries'] ?? ''));
        }

        if ($queriesRaw === '') {
            return $this->reconcileManifest($response, $callback);
        }

        return $this->reconcileQueries($response, $queriesRaw, $callback);
    }

    /**
     * Return the W3C Reconciliation service manifest (JSON).
     */
    private function reconcileManifest(ResponseInterface $response, ?string $callback): ResponseInterface
    {
        $base    = absoluteUrl('/');
        $service = rtrim($base, '/') . '/admin/api/reconcile';

        $manifest = [
            'versions'        => ['0.2'],
            'name'            => 'Pinakes Author Reconciliation (VIAF/local)',
            'identifierSpace' => 'http://viaf.org/viaf/',
            'schemaSpace'     => 'http://schema.org/',
            'defaultTypes'    => [
                ['id' => '/authors/viaf', 'name' => 'Autore (VIAF)'],
            ],
            'view'    => ['url' => 'https://viaf.org/viaf/{{id}}'],
            'preview' => ['url' => $service . '/preview?id={{id}}', 'width' => 430, 'height' => 300],
            'suggest' => [
                'entity' => [
                    'service_url'  => rtrim($base, '/'),
                    'service_path' => '/api/viaf/suggest',
                ],
            ],
        ];

        return $this->reconcileJson($response, $manifest, $callback);
    }

    /**
     * Execute batch reconciliation queries and return results.
     *
     * @param string      $queriesRaw  JSON string: {"q0":{"query":"...","limit":3},...}
     * @param string|null $callback    JSONP callback name or null
     */
    private function reconcileQueries(
        ResponseInterface $response,
        string $queriesRaw,
        ?string $callback
    ): ResponseInterface {
        $queries = json_decode($queriesRaw, true);
        if (!is_array($queries)) {
            return $this->reconcileJson($response, ['error' => 'Invalid queries JSON'], $callback, 400);
        }

        $results = [];
        foreach ($queries as $qid => $qdata) {
            if (!is_array($qdata)) { $results[(string) $qid] = ['result' => []]; continue; }
            $queryStr = trim((string) ($qdata['query'] ?? ''));
            $limit    = min((int) ($qdata['limit'] ?? 3), 10);
            if ($queryStr === '') { $results[(string) $qid] = ['result' => []]; continue; }
            $results[(string) $qid] = ['result' => $this->reconcileSearch($queryStr, $limit)];
        }

        return $this->reconcileJson($response, $results, $callback);
    }

    /**
     * Search local `autori` for matches and score them.
     *
     * Scoring:
     *   100 — exact case-insensitive match
     *    90 — normalised match (accents stripped)
     *    75 — query is a substring of name or name is a substring of query
     *    50–74 — `similar_text()` percentage ≥ 50
     *
     * @return list<array<string, mixed>>
     */
    private function reconcileSearch(string $query, int $limit): array
    {
        $like  = '%' . $this->db->real_escape_string($query) . '%';
        $stmt  = $this->db->prepare(
            "SELECT id, nome, viaf_id, viaf_uri, isni_id
               FROM autori
              WHERE nome LIKE ?
           ORDER BY nome
              LIMIT ?"
        );
        if ($stmt === false) { return []; }
        $stmt->bind_param('si', $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res instanceof \mysqli_result) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            $res->free();
        }
        $stmt->close();

        $qNorm = $this->normalizeForMatch((string) $query);
        $out   = [];
        foreach ($rows as $r) {
            $name   = (string) $r['nome'];
            $nNorm  = $this->normalizeForMatch($name);
            $score  = 0;
            $match  = false;

            if (strcasecmp($query, $name) === 0) {
                $score = 100; $match = true;
            } elseif ($qNorm !== '' && $qNorm === $nNorm) {
                $score = 90; $match = true;
            } elseif (stripos($name, $query) !== false || stripos($query, $name) !== false) {
                $score = 75;
            } else {
                similar_text($qNorm, $nNorm, $pct);
                $score = (int) $pct;
            }

            if ($score < 30) { continue; }

            $viafId = !empty($r['viaf_id']) ? (string) $r['viaf_id'] : null;
            $entry  = [
                'id'    => $viafId ?? ('local:' . $r['id']),
                'name'  => $name,
                'score' => $score,
                'match' => $match,
                'type'  => [['id' => '/authors/viaf', 'name' => 'Autore (VIAF)']],
            ];
            if ($viafId !== null) {
                $entry['uri'] = 'https://viaf.org/viaf/' . $viafId;
            }
            if (!empty($r['isni_id'])) {
                $entry['isni'] = (string) $r['isni_id'];
            }
            $out[] = $entry;
        }

        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    private function normalizeForMatch(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return preg_replace('/[^a-z0-9 ]/', '', $s) ?? $s;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function reconcileJson(
        ResponseInterface $response,
        array $data,
        ?string $callback,
        int $status = 200
    ): ResponseInterface {
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($callback !== null && $callback !== '') {
            $body        = $callback . '(' . $json . ')';
            $contentType = 'application/javascript; charset=utf-8';
        } else {
            $body        = $json;
            $contentType = 'application/json; charset=utf-8';
        }
        $response->getBody()->write($body);
        return $response->withStatus($status)->withHeader('Content-Type', $contentType);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Checks admin/staff access via PHP session or Basic Auth header.
     *
     * @param-out ResponseInterface $out
     */
    private function requireAdminOrStaff(
        ResponseInterface $response,
        ?ResponseInterface &$out,
        ?ServerRequestInterface $request = null
    ): bool {
        // Session-based auth (browser/admin panel)
        $user = $_SESSION['user'] ?? null;
        $role = is_array($user) ? (string) ($user['tipo_utente'] ?? '') : '';
        if ($user && in_array($role, ['admin', 'staff'], true)) {
            $out = $response;
            return true;
        }

        // Basic Auth (API scripts / E2E tests)
        if ($request !== null) {
            $authHeader = $request->getHeaderLine('Authorization');
        } else {
            $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        }
        if (str_starts_with($authHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$email, $pass] = explode(':', $decoded, 2);
                if ($this->authenticateBasic($email, $pass)) {
                    $out = $response;
                    return true;
                }
            }
        }

        $out = $this->json($response, ['error' => true, 'message' => __('Accesso negato.')], 403);
        return false;
    }

    private function authenticateBasic(string $email, string $pass): bool
    {
        $stmt = $this->db->prepare(
            "SELECT password, tipo_utente FROM utenti
              WHERE email = ? AND stato = 'attivo' AND tipo_utente IN ('admin','staff')
              LIMIT 1"
        );
        if ($stmt === false) { return false; }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row === null) { return false; }
        return password_verify($pass, (string) $row['password']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write((string) $body);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
