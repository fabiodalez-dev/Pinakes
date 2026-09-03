<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Modules;

use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Mobile bridge: exposes the Emeroteca (periodicals) to the Pinakes
 * Android/iOS app through the mobile-api plugin's bearer-token surface.
 *
 * Routes live under /api/v1/periodicals/* and are guarded by the SAME
 * AppAuthMiddleware + TokenQuotaMiddleware the mobile-api plugin uses, so
 * the app authenticates once and reuses its token (book-club
 * MobileModule pattern). The whole group is wrapped by
 * HttpsEnforceMiddleware exactly like the core /api/v1 group.
 *
 * The bridge registers its routes ONLY when the mobile-api plugin is
 * active (its classes are loaded at bootstrap by PluginManager): without
 * mobile-api the module is inert and /api/v1/periodicals/* simply 404s.
 *
 * Response shape: the CORE mobile-api envelope ({data, meta, error} via
 * ResponseEnvelope) with raw, non-HTML-escaped values (the app renders
 * text natively — same contract as CatalogController). Internal fields
 * (pdf_path, note, numero_inventario) are NEVER exposed: the PDF is only
 * reachable through the public /emeroteca/fascicolo/{id}/pdf route and
 * only when the per-issue pdf_pubblico opt-in is on.
 */
final class MobileModule
{
    /** Default / maximum page size for cursor pagination (CatalogController). */
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    /** Hard cap for the un-paginated issues-of-a-year listing. */
    private const ISSUES_CAP = 400;

    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    // ── Mounting ──────────────────────────────────────────────────────

    /**
     * Mount the bridge group. No-op when the mobile-api plugin is not
     * active: the middleware classes it relies on would be missing.
     * ($app stays untyped like AbstractModule::registerRoutes — Slim\App's
     * container template is not covariant, so an annotated generic here
     * would reject the caller's own annotated instance.)
     */
    public function registerRoutes($app): void
    {
        if (!$this->mobileApiAvailable()) {
            return;
        }

        $db = $this->db;
        $module = $this;

        // Same wiring as MobileApiPlugin::registerRoutes: LIFO middleware —
        // auth added last so it runs first and the quota sees the token id.
        $appAccessEnabled = $this->appAccessEnabled();
        $authMw = static fn(): \App\Plugins\MobileApi\Support\AppAuthMiddleware =>
            new \App\Plugins\MobileApi\Support\AppAuthMiddleware($db, $appAccessEnabled);
        $quotaMw = static fn(): \App\Plugins\MobileApi\Support\TokenQuotaMiddleware =>
            new \App\Plugins\MobileApi\Support\TokenQuotaMiddleware();

        $group = $app->group('/api/v1/periodicals', function ($g) use ($module, $authMw, $quotaMw): void {
            // Discovery probe for the app — authenticated like the data surface.
            $g->get('/health', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $module->health($rq, $rs))->add($quotaMw())->add($authMw());

            $g->get('', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $module->listPeriodicals($rq, $rs))->add($quotaMw())->add($authMw());
            $g->get('/years/{id:[0-9]+}/issues', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $module->yearIssues($rq, $rs, (int) $a['id']))->add($quotaMw())->add($authMw());
            $g->get('/issues/{id:[0-9]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $module->issueDetail($rq, $rs, (int) $a['id']))->add($quotaMw())->add($authMw());
            $g->get('/{id:[0-9]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $module->periodicalDetail($rq, $rs, (int) $a['id']))->add($quotaMw())->add($authMw());
        });

        // Enforce HTTPS-except-loopback for the whole bridge (same as the core group).
        $group->add(new \App\Plugins\MobileApi\Support\HttpsEnforceMiddleware());
    }

    /**
     * The bridge needs the mobile-api plugin: active in the plugins table
     * AND its middleware classes loaded (PluginManager requires the main
     * file of every active plugin at bootstrap, before routes register).
     */
    public function mobileApiAvailable(): bool
    {
        if (!class_exists(\App\Plugins\MobileApi\Support\AppAuthMiddleware::class)
            || !class_exists(\App\Plugins\MobileApi\Support\TokenQuotaMiddleware::class)
            || !class_exists(\App\Plugins\MobileApi\Support\HttpsEnforceMiddleware::class)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare("SELECT is_active FROM plugins WHERE name = 'mobile-api' LIMIT 1");
            if ($stmt === false) {
                return false;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            return (int) ($row['is_active'] ?? 0) === 1;
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca:mobile] mobileApiAvailable check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Same gate the mobile-api plugin applies to its own surface
     * (system_settings mobile_api.enabled). AppAuthMiddleware re-checks it
     * per request; reading it here mirrors the wiring exactly.
     */
    public function appAccessEnabled(): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM system_settings WHERE category = 'mobile_api' AND setting_key = 'enabled' LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            return (string) ($row['setting_value'] ?? '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── GET /api/v1/periodicals/health ────────────────────────────────

    public function health(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return \App\Plugins\MobileApi\Support\ResponseEnvelope::success($response, ['status' => 'ok']);
    }

    // ── GET /api/v1/periodicals ───────────────────────────────────────

    /**
     * Mastheads directory, keyset-paginated on (titolo, id) ASC.
     * `q` filters titolo/sottotitolo/issn (LIKE-escaped); `type` is
     * whitelisted against EmerotecaPlugin::TIPI_TESTATA.
     */
    public function listPeriodicals(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $params = $request->getQueryParams();
            $limit = $this->clampLimit($params['limit'] ?? null);
            $q     = isset($params['q']) ? trim((string) $params['q']) : '';
            $type  = isset($params['type']) ? trim((string) $params['type']) : '';

            if ($type !== '' && !array_key_exists($type, \EmerotecaPlugin::TIPI_TESTATA)) {
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'validation_error',
                    __('Tipo di testata non valido.'),
                    422
                );
            }

            $conditions = [];
            $bindParams = [];
            $bindTypes  = '';

            if ($q !== '') {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
                $conditions[] = "(t.titolo LIKE ? ESCAPE '\\\\' OR t.sottotitolo LIKE ? ESCAPE '\\\\' OR t.issn LIKE ? ESCAPE '\\\\')";
                $bindParams[] = $like;
                $bindParams[] = $like;
                $bindParams[] = $like;
                $bindTypes   .= 'sss';
            }
            if ($type !== '') {
                $conditions[] = 't.tipo = ?';
                $bindParams[] = $type;
                $bindTypes   .= 's';
            }

            // Opaque keyset cursor over (titolo, id) ASC. Never trusted as
            // authorization input (CursorCodec contract).
            $cursor = \App\Plugins\MobileApi\Support\CursorCodec::decode(
                isset($params['cursor']) ? (string) $params['cursor'] : null
            );
            if (is_array($cursor)
                && isset($cursor['last_id']) && is_numeric($cursor['last_id'])
                && isset($cursor['last_title']) && is_string($cursor['last_title'])
            ) {
                $conditions[] = '(t.titolo > ? OR (t.titolo = ? AND t.id > ?))';
                $bindParams[] = $cursor['last_title'];
                $bindParams[] = $cursor['last_title'];
                $bindParams[] = (int) $cursor['last_id'];
                $bindTypes   .= 'ssi';
            }

            $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

            // Fetch limit+1 to detect a further page without a COUNT round-trip.
            $fetch = $limit + 1;
            $sql = "
                SELECT
                    t.id, t.titolo, t.sottotitolo, t.issn, t.tipo, t.periodicita,
                    t.logo_url, t.anno_inizio, t.anno_fine, t.stato_raccolta,
                    t.editore_id, e.nome AS editore_nome,
                    (SELECT COUNT(*) FROM emeroteca_annate a WHERE a.testata_id = t.id) AS years_count,
                    (SELECT COUNT(*) FROM emeroteca_fascicoli f
                       JOIN emeroteca_annate a2 ON f.annata_id = a2.id
                      WHERE a2.testata_id = t.id) AS issues_count
                FROM emeroteca_testate t
                LEFT JOIN editori e ON t.editore_id = e.id
                {$where}
                ORDER BY t.titolo ASC, t.id ASC
                LIMIT ?
            ";
            $bindParams[] = $fetch;
            $bindTypes   .= 'i';

            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca:mobile] list prepare failed: ' . $this->db->error);
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'internal_error',
                    __('Elenco testate non disponibile.'),
                    500
                );
            }
            $stmt->bind_param($bindTypes, ...$bindParams);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
                $rows[] = $row;
            }
            $stmt->close();

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                $rows = array_slice($rows, 0, $limit);
            }

            $items = array_map(fn (array $r): array => $this->mapPeriodicalItem($r), $rows);

            $nextCursor = null;
            if ($hasMore && $rows !== []) {
                $last = $rows[count($rows) - 1];
                $nextCursor = \App\Plugins\MobileApi\Support\CursorCodec::encode([
                    'last_id'    => (int) $last['id'],
                    'last_title' => (string) ($last['titolo'] ?? ''),
                ]);
            }

            $meta = [
                'count'       => count($items),
                'limit'       => $limit,
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ];

            $etag = $this->payloadEtag('periodicals-list', [$items, $meta]);
            if ($this->notModified($request, $etag)) {
                return $this->notModifiedResponse($response, $etag);
            }

            $response = \App\Plugins\MobileApi\Support\ResponseEnvelope::success($response, $items, $meta, 200);

            return $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca:mobile] list failed: ' . $e->getMessage());
            return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                $response,
                'internal_error',
                __('Elenco testate non disponibile.'),
                500
            );
        }
    }

    // ── GET /api/v1/periodicals/{id} ──────────────────────────────────

    public function periodicalDetail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        try {
            $stmt = $this->db->prepare(
                'SELECT t.id, t.titolo, t.sottotitolo, t.issn, t.tipo, t.periodicita,
                        t.logo_url, t.anno_inizio, t.anno_fine, t.stato_raccolta,
                        t.luogo_pubblicazione, t.lingua, t.descrizione,
                        t.editore_id, e.nome AS editore_nome,
                        (SELECT COUNT(*) FROM emeroteca_annate a WHERE a.testata_id = t.id) AS years_count,
                        (SELECT COUNT(*) FROM emeroteca_fascicoli f
                           JOIN emeroteca_annate a2 ON f.annata_id = a2.id
                          WHERE a2.testata_id = t.id) AS issues_count
                   FROM emeroteca_testate t
                   LEFT JOIN editori e ON t.editore_id = e.id
                  WHERE t.id = ?
                  LIMIT 1'
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca:mobile] detail prepare failed: ' . $this->db->error);
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'internal_error',
                    __('Testata non disponibile.'),
                    500
                );
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) {
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'not_found',
                    __('Testata non trovata.'),
                    404
                );
            }

            $years = [];
            $stmt = $this->db->prepare(
                "SELECT a.id, a.anno, a.volume, a.rilegata, a.copertina_url,
                        (SELECT COUNT(*) FROM emeroteca_fascicoli f WHERE f.annata_id = a.id) AS issues_count,
                        (SELECT COUNT(*) FROM emeroteca_fascicoli f2
                          WHERE f2.annata_id = a.id AND f2.stato = 'posseduto') AS owned_count
                   FROM emeroteca_annate a
                  WHERE a.testata_id = ?
                  ORDER BY a.anno DESC, a.id DESC"
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca:mobile] years prepare failed: ' . $this->db->error);
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'internal_error',
                    __('Testata non disponibile.'),
                    500
                );
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res !== false && ($y = $res->fetch_assoc()) !== null) {
                $years[] = [
                    'id'           => (int) $y['id'],
                    'year'         => (int) $y['anno'],
                    'volume'       => $this->nullableString($y['volume'] ?? null),
                    'bound'        => (int) ($y['rilegata'] ?? 0) === 1,
                    'cover_url'    => $this->mediaUrl($y['copertina_url'] ?? null),
                    'issues_count' => (int) ($y['issues_count'] ?? 0),
                    'owned_count'  => (int) ($y['owned_count'] ?? 0),
                ];
            }
            $stmt->close();

            $data = $this->mapPeriodicalItem($row);
            $data['description'] = $this->nullableString($row['descrizione'] ?? null);
            $data['place']       = $this->nullableString($row['luogo_pubblicazione'] ?? null);
            $data['language']    = $this->nullableString($row['lingua'] ?? null);
            $data['holdings']    = \EmerotecaPlugin::consistenzaTestata($this->db, $id);
            $data['years']       = $years;

            $etag = $this->payloadEtag('periodical-detail', $data);
            if ($this->notModified($request, $etag)) {
                return $this->notModifiedResponse($response, $etag);
            }

            $response = \App\Plugins\MobileApi\Support\ResponseEnvelope::success($response, $data);

            return $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca:mobile] detail failed: ' . $e->getMessage());
            return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                $response,
                'internal_error',
                __('Testata non disponibile.'),
                500
            );
        }
    }

    // ── GET /api/v1/periodicals/years/{id}/issues ─────────────────────

    public function yearIssues(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $yearId
    ): ResponseInterface {
        try {
            $stmt = $this->db->prepare('SELECT id FROM emeroteca_annate WHERE id = ? LIMIT 1');
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca:mobile] year probe prepare failed: ' . $this->db->error);
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'internal_error',
                    __('Fascicoli non disponibili.'),
                    500
                );
            }
            $stmt->bind_param('i', $yearId);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res instanceof \mysqli_result && $res->fetch_row() !== null;
            $stmt->close();
            if (!$exists) {
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'not_found',
                    __('Annata non trovata.'),
                    404
                );
            }

            // A single annata is bounded in the real world (a daily is ~365
            // issues), so a hard cap replaces cursor pagination here.
            $cap = self::ISSUES_CAP;
            $stmt = $this->db->prepare(
                "SELECT id, numero, numero_progressivo, titolo_fascicolo, data_copertina,
                        data_pubblicazione, pagine, stato, copertina_url, pdf_pubblico, pdf_path
                   FROM emeroteca_fascicoli
                  WHERE annata_id = ?
                  ORDER BY (numero_progressivo IS NULL), CAST(numero_progressivo AS UNSIGNED),
                           numero_progressivo, CAST(numero AS UNSIGNED), numero, id
                  LIMIT ?"
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca:mobile] issues prepare failed: ' . $this->db->error);
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'internal_error',
                    __('Fascicoli non disponibili.'),
                    500
                );
            }
            $stmt->bind_param('ii', $yearId, $cap);
            $stmt->execute();
            $res = $stmt->get_result();
            $items = [];
            while ($res !== false && ($f = $res->fetch_assoc()) !== null) {
                $items[] = $this->mapIssueItem($f);
            }
            $stmt->close();

            $meta = ['count' => count($items)];

            $etag = $this->payloadEtag('periodical-issues:' . $yearId, [$items, $meta]);
            if ($this->notModified($request, $etag)) {
                return $this->notModifiedResponse($response, $etag);
            }

            $response = \App\Plugins\MobileApi\Support\ResponseEnvelope::success($response, $items, $meta);

            return $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca:mobile] issues failed: ' . $e->getMessage());
            return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                $response,
                'internal_error',
                __('Fascicoli non disponibili.'),
                500
            );
        }
    }

    // ── GET /api/v1/periodicals/issues/{id} ───────────────────────────

    public function issueDetail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $issueId
    ): ResponseInterface {
        try {
            $stmt = $this->db->prepare(
                'SELECT f.id, f.numero, f.numero_progressivo, f.titolo_fascicolo, f.data_copertina,
                        f.data_pubblicazione, f.pagine, f.stato, f.copertina_url, f.supplementi,
                        f.pdf_pubblico, f.pdf_path,
                        a.id AS year_id, a.anno AS year_anno, a.volume AS year_volume,
                        t.id AS testata_id, t.titolo AS testata_titolo
                   FROM emeroteca_fascicoli f
                   JOIN emeroteca_annate a ON f.annata_id = a.id
                   JOIN emeroteca_testate t ON a.testata_id = t.id
                  WHERE f.id = ?
                  LIMIT 1'
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca:mobile] issue prepare failed: ' . $this->db->error);
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'internal_error',
                    __('Fascicolo non disponibile.'),
                    500
                );
            }
            $stmt->bind_param('i', $issueId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row)) {
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'not_found',
                    __('Fascicolo non trovato.'),
                    404
                );
            }

            $articles = [];
            $stmt = $this->db->prepare(
                'SELECT titolo, autori, pagina_inizio, pagina_fine, tipo
                   FROM emeroteca_articoli
                  WHERE fascicolo_id = ?
                  ORDER BY (pagina_inizio IS NULL), pagina_inizio ASC, id ASC'
            );
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca:mobile] articles prepare failed: ' . $this->db->error);
                return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                    $response,
                    'internal_error',
                    __('Fascicolo non disponibile.'),
                    500
                );
            }
            $stmt->bind_param('i', $issueId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res !== false && ($a = $res->fetch_assoc()) !== null) {
                $articles[] = [
                    'title'      => (string) ($a['titolo'] ?? ''),
                    'authors'    => $this->nullableString($a['autori'] ?? null),
                    'page_start' => $a['pagina_inizio'] !== null ? (int) $a['pagina_inizio'] : null,
                    'page_end'   => $a['pagina_fine'] !== null ? (int) $a['pagina_fine'] : null,
                    'type'       => (string) ($a['tipo'] ?? 'articolo'),
                ];
            }
            $stmt->close();

            $data = $this->mapIssueItem($row);
            $data['supplements'] = $this->nullableString($row['supplementi'] ?? null);
            // The PDF URL points at the public streaming route, NEVER at the
            // stored pdf_path, and only when the per-issue opt-in is on.
            $data['pdf_url'] = $data['has_public_pdf']
                ? absoluteUrl('/emeroteca/fascicolo/' . (int) $row['id'] . '/pdf')
                : null;
            $data['masthead'] = [
                'id'    => (int) $row['testata_id'],
                'title' => (string) ($row['testata_titolo'] ?? ''),
            ];
            $data['year'] = [
                'id'     => (int) $row['year_id'],
                'year'   => (int) $row['year_anno'],
                'volume' => $this->nullableString($row['year_volume'] ?? null),
            ];
            $data['articles'] = $articles;

            $etag = $this->payloadEtag('periodical-issue', $data);
            if ($this->notModified($request, $etag)) {
                return $this->notModifiedResponse($response, $etag);
            }

            $response = \App\Plugins\MobileApi\Support\ResponseEnvelope::success($response, $data);

            return $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca:mobile] issue failed: ' . $e->getMessage());
            return \App\Plugins\MobileApi\Support\ResponseEnvelope::error(
                $response,
                'internal_error',
                __('Fascicolo non disponibile.'),
                500
            );
        }
    }

    // ── OpenAPI (filter target of 'mobile_api.openapi' via EmerotecaPlugin) ──

    /**
     * Appends the bridge paths to mobile-api's OpenAPI document. Static:
     * the hook fires on the plugin instance, which passes its mysqli
     * handle. Mirrors registerRoutes' availability condition so the
     * document only advertises routes that are actually mounted.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function extendOpenApi(array $doc, \mysqli $db): array
    {
        if (!class_exists(\App\Plugins\MobileApi\Support\AppAuthMiddleware::class)) {
            return $doc;
        }
        try {
            $stmt = $db->prepare("SELECT is_active FROM plugins WHERE name = 'mobile-api' LIMIT 1");
            if ($stmt === false) {
                return $doc;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row === null || (int) $row['is_active'] !== 1) {
                return $doc;
            }
        } catch (\Throwable) {
            return $doc;
        }

        $idParam = static fn(string $name): array => [
            'name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'],
        ];
        $typeEnum = array_keys(\EmerotecaPlugin::TIPI_TESTATA);
        $ok  = static fn(string $desc): array => ['200' => ['description' => $desc . ' — core envelope {data, meta, error}']];
        $sec = [['bearerAuth' => []]];
        $tag = ['periodicals'];

        $paths = [
            '/periodicals/health' => ['get' => ['tags' => $tag, 'summary' => 'Bridge discovery probe: 200 {status: ok} while the emeroteca bridge is mounted.', 'security' => $sec, 'responses' => $ok('Discovery payload')]],
            '/periodicals' => [
                'get' => [
                    'tags'        => $tag,
                    'summary'     => 'Mastheads directory (q/type filters, cursor pagination on title).',
                    'security'    => $sec,
                    'description' => 'List of periodical mastheads (riviste, giornali, magazines). Supports typed filtering and keyset cursor pagination by title+id.',
                    'parameters'  => [
                        ['name' => 'q',      'in' => 'query', 'schema' => ['type' => 'string'],  'description' => 'Search by title, subtitle, or ISSN (SQL LIKE backend).'],
                        ['name' => 'type',   'in' => 'query', 'schema' => ['type' => 'string', 'enum' => $typeEnum], 'description' => 'Filter by periodical type.'],
                        ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Opaque pagination cursor from meta.next_cursor.'],
                        ['name' => 'limit',  'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20], 'description' => 'Page size (max 50).'],
                    ],
                    'responses' => $ok('Masthead list')
                ],
            ],
            '/periodicals/{id}' => ['get' => ['tags' => $tag, 'summary' => 'Masthead detail: bibliographic data, holdings summary, years (annate).', 'security' => $sec, 'parameters' => [$idParam('id')], 'responses' => $ok('Masthead detail')]],
            '/periodicals/years/{id}/issues' => ['get' => ['tags' => $tag, 'summary' => 'Issues of one year (annata), ordered by sequence/number.', 'security' => $sec, 'parameters' => [$idParam('id')], 'responses' => $ok('Issue list')]],
            '/periodicals/issues/{id}' => ['get' => ['tags' => $tag, 'summary' => 'Issue detail: masthead, year, articles index, public PDF link when opted in.', 'security' => $sec, 'parameters' => [$idParam('id')], 'responses' => $ok('Issue detail')]],
        ];

        $doc['paths'] = array_merge(is_array($doc['paths'] ?? null) ? $doc['paths'] : [], $paths);

        return $doc;
    }

    // ── Mapping helpers ───────────────────────────────────────────────

    /**
     * Public shape of one masthead row. Internal fields (note) stay out.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapPeriodicalItem(array $r): array
    {
        $publisher = null;
        if (isset($r['editore_id']) && isset($r['editore_nome'])) {
            $publisher = [
                'id'   => (int) $r['editore_id'],
                'name' => (string) $r['editore_nome'],
            ];
        }

        return [
            'id'                => (int) $r['id'],
            'title'             => (string) ($r['titolo'] ?? ''),
            'subtitle'          => $this->nullableString($r['sottotitolo'] ?? null),
            'issn'              => $this->nullableString($r['issn'] ?? null),
            'type'              => (string) ($r['tipo'] ?? 'rivista'),
            'frequency'         => $this->nullableString($r['periodicita'] ?? null),
            'publisher'         => $publisher,
            'logo_url'          => $this->mediaUrl($r['logo_url'] ?? null),
            'year_start'        => $r['anno_inizio'] !== null ? (int) $r['anno_inizio'] : null,
            'year_end'          => $r['anno_fine'] !== null ? (int) $r['anno_fine'] : null,
            'collection_status' => (string) ($r['stato_raccolta'] ?? 'attiva'),
            'years_count'       => (int) ($r['years_count'] ?? 0),
            'issues_count'      => (int) ($r['issues_count'] ?? 0),
        ];
    }

    /**
     * Public shape of one issue row. pdf_path / note / numero_inventario
     * are NEVER exposed (stored-XSS history on inventory/notes; the path
     * is a server-internal detail).
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function mapIssueItem(array $f): array
    {
        $hasPublicPdf = (int) ($f['pdf_pubblico'] ?? 0) === 1
            && trim((string) ($f['pdf_path'] ?? '')) !== '';

        return [
            'id'               => (int) $f['id'],
            'number'           => (string) ($f['numero'] ?? ''),
            'sequence'         => $this->nullableString($f['numero_progressivo'] ?? null),
            'title'            => $this->nullableString($f['titolo_fascicolo'] ?? null),
            'cover_date'       => $this->nullableString($f['data_copertina'] ?? null),
            'publication_date' => $this->nullableString($f['data_pubblicazione'] ?? null),
            'pages'            => $f['pagine'] !== null ? (int) $f['pagine'] : null,
            'status'           => (string) ($f['stato'] ?? 'posseduto'),
            'cover_url'        => $this->mediaUrl($f['copertina_url'] ?? null),
            'has_public_pdf'   => $hasPublicPdf,
        ];
    }

    /**
     * Absolute media URL for the app: an already-absolute external URL is
     * passed through, a site-rooted path goes through absoluteUrl(), an
     * empty value maps to null (CatalogController's $absMedia pattern).
     *
     * @param mixed $raw
     */
    private function mediaUrl($raw): ?string
    {
        $v = trim((string) ($raw ?? ''));
        if ($v === '') {
            return null;
        }

        return preg_match('#^https?://#i', $v) === 1 ? $v : absoluteUrl($v);
    }

    /** @param mixed $raw */
    private function nullableString($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = (string) $raw;

        return $v === '' ? null : $v;
    }

    private function clampLimit(mixed $raw): int
    {
        $limit = is_numeric($raw) ? (int) $raw : self::DEFAULT_LIMIT;
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    // ── Caching helpers (ETag / 304, CatalogController pattern) ───────

    /** @param mixed $payload */
    private function payloadEtag(string $prefix, $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '"' . sha1($prefix . ':' . ($json === false ? '' : $json)) . '"';
    }

    private function notModified(ServerRequestInterface $request, string $etag): bool
    {
        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        if ($ifNoneMatch === '' || $ifNoneMatch === '*') {
            // "*" is only meaningful for conditional writes; for a GET, serve
            // the full representation instead of a forced 304.
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            // Strip a weak validator prefix before comparing.
            $candidate = preg_replace('/^W\//', '', $candidate) ?? $candidate;
            // Apache mod_deflate appends "-gzip"/"-br" to the ETag it emits when
            // compressing; the client echoes the mangled value back, so strip the
            // suffix — otherwise 304 revalidation never succeeds behind a
            // compressing web server (the exact production setup).
            $candidate = preg_replace('/-(gzip|br)("?)$/', '$2', $candidate) ?? $candidate;
            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function notModifiedResponse(ResponseInterface $response, string $etag): ResponseInterface
    {
        return $response
            ->withStatus(304)
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
    }
}
