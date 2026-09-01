<?php

declare(strict_types=1);

namespace App\Plugins\ResourceSync;

use App\Support\HookManager;
use App\Support\RateLimiter;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ResourceSync (ANSI/NISO Z39.99-2014) plugin.
 *
 * Exposes four endpoints required by the ResourceSync Framework:
 *   /.well-known/resourcesync            → Source Description (links to capability list)
 *   /resync/capabilitylist.xml           → Capability List (what capabilities this server offers)
 *   /resync/resourcelist.xml             → Resource List (complete dump of all resources)
 *   /resync/changelist.xml               → Change List (incremental changes since a date)
 *
 * All documents are Sitemap XML with the RS namespace.
 */
class ResourceSyncPlugin
{
    private const RS_NS    = 'http://www.openarchives.org/rs/terms/';
    private const SM_NS    = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const PAGE_SIZE = 500;

    /** @phpstan-ignore property.onlyWritten */
    private HookManager $hookManager;
    private \mysqli $db;
    private ?int $pluginId = null;
    /** Per-request cache for the bibframe-linked-data active check. */
    private ?bool $bibframeActiveCache = null;

    public function __construct(\mysqli $db, HookManager $hookManager)
    {
        $this->db          = $db;
        $this->hookManager = $hookManager;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function onActivate(): void
    {
        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register', 'registerRoutes', 10);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

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

    public function onInstall(): void
    {
        // FIX F077: seed default for the opt-in Basic Auth gate. Default '0' preserves
        // current spec-compliant public behavior; operators can flip to '1' to require
        // admin/staff Basic Auth on all four ResourceSync endpoints.
        $this->seedSettingDefault('require_basic_auth', '0');
    }

    public function onUninstall(): void {}

    /**
     * FIX F077: seed a default plugin setting on install (no-op if already present).
     */
    private function seedSettingDefault(string $key, string $value): void
    {
        if ($this->pluginId === null) { return; }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO plugin_settings (plugin_id, setting_key, setting_value, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        if ($stmt === false) { return; }
        $stmt->bind_param('iss', $this->pluginId, $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[ResourceSync] pluginId not set; cannot register hook ' . $hookName);
            return;
        }
        $callbackClass = 'ResourceSyncPlugin'; // must match wrapper.php global class name, not self::class
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE priority = VALUES(priority), is_active = 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('[ResourceSync] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[ResourceSync] hook upsert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }

    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) { return; }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('[ResourceSync] hook delete prepare() failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $this->pluginId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[ResourceSync] hook delete failed: ' . $err);
        }
        $stmt->close();
    }

    /** Register routes via the HookManager. */
    public function registerRoutes(\Slim\App $app): void
    {
        $plugin = $this;

        // RFC 5785 well-known URI — source description
        $app->get('/.well-known/resourcesync', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            // FIX F077: opt-in admin/staff Basic Auth gate (default off for spec compliance).
            $gate = null;
            if (!$plugin->gateRequest($request, $response, $gate)) { return $gate; }
            return $plugin->sourceDescriptionAction($request, $response);
        });

        $app->get('/resync/capabilitylist.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            // FIX F077: opt-in admin/staff Basic Auth gate (default off for spec compliance).
            $gate = null;
            if (!$plugin->gateRequest($request, $response, $gate)) { return $gate; }
            return $plugin->capabilityListAction($request, $response);
        });

        $app->get('/resync/resourcelist.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            // FIX F077: opt-in admin/staff Basic Auth gate (default off for spec compliance).
            $gate = null;
            if (!$plugin->gateRequest($request, $response, $gate)) { return $gate; }
            return $plugin->resourceListAction($request, $response);
        });

        $app->get('/resync/changelist.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            // FIX F077: opt-in admin/staff Basic Auth gate (default off for spec compliance).
            $gate = null;
            if (!$plugin->gateRequest($request, $response, $gate)) { return $gate; }
            return $plugin->changeListAction($request, $response);
        });
    }

    /**
     * FIX F077: opt-in Basic Auth gate.
     *
     * Returns true when the request is allowed to proceed. When the
     * `require_basic_auth` setting is enabled, validates an admin/staff session
     * or HTTP Basic Auth header; otherwise sets `$out` to a 401 challenge or 403
     * response (RFC 7235 §3.1) and returns false. When the setting is disabled
     * (default), this is a no-op — preserving ResourceSync spec compliance.
     *
     * @internal Called from route closures; public for closure visibility only.
     */
    public function gateRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?ResponseInterface &$out
    ): bool {
        if (!$this->isSettingEnabled('require_basic_auth', false)) {
            return true;
        }

        // Session-based admin/staff access
        if (
            isset($_SESSION['user']) &&
            in_array($_SESSION['user']['tipo_utente'] ?? '', ['admin', 'staff'], true)
        ) {
            return true;
        }

        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && str_starts_with($auth, 'Basic ')) {
            // Throttle credential guessing per client IP. This is the only
            // brute-force control on the Basic Auth gate, so every attempt must
            // count toward the limit (mirrors the core login throttle:
            // RateLimitMiddleware(15, 300, 'login') and mobile_login 10/300).
            $ip   = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $rlId = 'resourcesync_basic:' . $ip;
            if (RateLimiter::isLimited($rlId, 10, 300)) {
                $out = $response
                    ->withStatus(429)
                    ->withHeader('Retry-After', '300');
                return false;
            }

            $decoded = base64_decode(substr($auth, 6), true);
            if ($decoded !== false) {
                $parts = explode(':', $decoded, 2);
                if (count($parts) === 2 && $this->authenticateBasic($parts[0], $parts[1])) {
                    // Successful auth clears the throttle for this IP.
                    RateLimiter::reset($rlId);
                    return true;
                }
            }
            // Credentials present but invalid
            $out = $response->withStatus(403);
            return false;
        }

        // No credentials provided — challenge the client
        $out = $response
            ->withStatus(401)
            ->withHeader('WWW-Authenticate', 'Basic realm="ResourceSync"');
        return false;
    }

    /**
     * FIX F077: validate admin/staff credentials against the `utenti` table.
     */
    private function authenticateBasic(string $email, string $pass): bool
    {
        $stmt = $this->db->prepare(
            "SELECT password FROM utenti
             WHERE email = ? AND stato = 'attivo'
               AND tipo_utente IN ('admin','staff')
             LIMIT 1"
        );
        if ($stmt === false) { return false; }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row === null) {
            // Constant-time dummy verify: avoids an account-enumeration timing
            // side channel between "no such admin/staff email" and "wrong
            // password" by always spending one bcrypt verification.
            password_verify($pass, '$2y$12$FYWkjQ0krgMEuFnovQ3C6.vL6MZP/pdGGrLm.Q1PBhX29YNNu.Bfe');
            return false;
        }
        return password_verify($pass, (string) $row['password']);
    }

    /**
     * FIX F077: fetch a plugin setting (raw, no decryption — booleans only here).
     */
    private function getSetting(string $key, string $default = ''): string
    {
        if ($this->pluginId === null) {
            return $default;
        }
        $stmt = $this->db->prepare(
            'SELECT setting_value FROM plugin_settings WHERE plugin_id = ? AND setting_key = ?'
        );
        if ($stmt === false) { return $default; }
        $stmt->bind_param('is', $this->pluginId, $key);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row === null) { return $default; }
        $value = $row['setting_value'];
        return is_string($value) ? $value : $default;
    }

    /**
     * FIX F077: check a boolean-style plugin setting.
     */
    private function isSettingEnabled(string $key, bool $default = false): bool
    {
        $value = $this->getSetting($key, $default ? '1' : '0');
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    // ─── Endpoint handlers ────────────────────────────────────────────────────

    public function sourceDescriptionAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $base = $this->baseUrl();
        $xml  = $this->buildSourceDescription($base);
        return $this->xmlResponse($response, $xml);
    }

    public function capabilityListAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $base = $this->baseUrl();
        $xml  = $this->buildCapabilityList($base);
        return $this->xmlResponse($response, $xml);
    }

    public function resourceListAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $base   = $this->baseUrl();
        $params = $request->getQueryParams();
        $page   = max(0, (int) ($params['page'] ?? 0));
        $books  = $this->fetchBooks($page);
        $xml    = $this->buildResourceList($base, $books, $page);
        return $this->xmlResponse($response, $xml);
    }

    public function changeListAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $base   = $this->baseUrl();
        $params = $request->getQueryParams();
        $sinceRaw = isset($params['from']) ? (string) $params['from'] : null;
        $page     = max(0, (int) ($params['page'] ?? 0));
        // Normalize $since so the XML from-attribute matches what the DB actually used
        $since = ($sinceRaw !== null && preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:]+Z?)?$/', $sinceRaw))
            ? $sinceRaw
            : null;
        $books  = $this->fetchChangedBooks($since, $page);
        $xml    = $this->buildChangeList($base, $books, $since, $page);
        return $this->xmlResponse($response, $xml);
    }

    // ─── XML builders ─────────────────────────────────────────────────────────

    private function buildSourceDescription(string $base): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->setIndentString('  ');
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs(null, 'urlset', self::SM_NS);
        $xw->writeAttribute('xmlns:rs', self::RS_NS);

        // rs:md — marks this as a Source Description
        $xw->startElementNs('rs', 'md', null);
        $xw->writeAttribute('capability', 'description');
        $xw->writeAttribute('at', gmdate('c'));
        $xw->endElement();

        // rs:ln rel="self" — canonical URL of this document
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'self');
        $xw->writeAttribute('href', $base . '/.well-known/resourcesync');
        $xw->endElement();

        // one <url> per capability list (we have only one)
        $xw->startElement('url');
        $xw->writeElement('loc', $base . '/resync/capabilitylist.xml');
        $xw->startElementNs('rs', 'md', null);
        $xw->writeAttribute('capability', 'capabilitylist');
        $xw->endElement();
        $xw->endElement(); // url

        $xw->endElement(); // urlset
        $xw->endDocument();

        return (string) $xw->outputMemory();
    }

    private function buildCapabilityList(string $base): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->setIndentString('  ');
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs(null, 'urlset', self::SM_NS);
        $xw->writeAttribute('xmlns:rs', self::RS_NS);

        // rs:md — this document is the capability list; link back to source description
        $xw->startElementNs('rs', 'md', null);
        $xw->writeAttribute('capability', 'capabilitylist');
        $xw->writeAttribute('at', gmdate('c'));
        $xw->endElement();

        // rs:ln rel="self" — canonical URL of this document
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'self');
        $xw->writeAttribute('href', $base . '/resync/capabilitylist.xml');
        $xw->endElement();

        // rs:ln — up-link to source description
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'up');
        $xw->writeAttribute('href', $base . '/.well-known/resourcesync');
        $xw->endElement();

        $capabilityListUrl = $base . '/resync/capabilitylist.xml';

        // resourcelist capability
        $xw->startElement('url');
        $xw->writeElement('loc', $base . '/resync/resourcelist.xml');
        $xw->startElementNs('rs', 'md', null);
        $xw->writeAttribute('capability', 'resourcelist');
        $xw->endElement();
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'resourcesync');
        $xw->writeAttribute('href', $capabilityListUrl);
        $xw->endElement();
        $xw->endElement();

        // changelist capability
        $xw->startElement('url');
        $xw->writeElement('loc', $base . '/resync/changelist.xml');
        $xw->startElementNs('rs', 'md', null);
        $xw->writeAttribute('capability', 'changelist');
        $xw->endElement();
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'resourcesync');
        $xw->writeAttribute('href', $capabilityListUrl);
        $xw->endElement();
        $xw->endElement();

        $xw->endElement(); // urlset
        $xw->endDocument();

        return (string) $xw->outputMemory();
    }

    /**
     * @param array<int, array<string, mixed>> $books
     */
    private function buildResourceList(string $base, array $books, int $page = 0): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->setIndentString('  ');
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs(null, 'urlset', self::SM_NS);
        $xw->writeAttribute('xmlns:rs', self::RS_NS);

        $xw->startElementNs('rs', 'md', null);
        $xw->writeAttribute('capability', 'resourcelist');
        $xw->writeAttribute('at', gmdate('c'));
        $xw->endElement();

        // rs:ln rel="self" — canonical URL of this document (page-aware)
        $selfHref = $base . '/resync/resourcelist.xml' . ($page > 0 ? '?page=' . $page : '');
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'self');
        $xw->writeAttribute('href', $selfHref);
        $xw->endElement();

        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'up');
        $xw->writeAttribute('href', $base . '/resync/capabilitylist.xml');
        $xw->endElement();

        if ($page > 0) {
            $xw->startElementNs('rs', 'ln', null);
            $xw->writeAttribute('rel', 'prev');
            $xw->writeAttribute('href', $base . '/resync/resourcelist.xml?page=' . ($page - 1));
            $xw->endElement();
        }
        if (count($books) === self::PAGE_SIZE) {
            $xw->startElementNs('rs', 'ln', null);
            $xw->writeAttribute('rel', 'next');
            $xw->writeAttribute('href', $base . '/resync/resourcelist.xml?page=' . ($page + 1));
            $xw->endElement();
        }

        foreach ($books as $book) {
            $modified  = $this->w3cDate((string) ($book['updated_at'] ?? $book['created_at'] ?? ''));
            $loc       = $this->resourceLoc($base, $book);

            $xw->startElement('url');
            $xw->writeElement('loc', $loc);
            $xw->writeElement('lastmod', $modified);
            $xw->startElementNs('rs', 'md', null);
            $xw->writeAttribute('type', $this->resourceType());
            $xw->endElement();
            $xw->endElement(); // url
        }

        $xw->endElement(); // urlset
        $xw->endDocument();

        return (string) $xw->outputMemory();
    }

    /**
     * @param array<int, array<string, mixed>> $books
     */
    private function buildChangeList(string $base, array $books, ?string $since, int $page = 0): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->setIndentString('  ');
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs(null, 'urlset', self::SM_NS);
        $xw->writeAttribute('xmlns:rs', self::RS_NS);

        $xw->startElementNs('rs', 'md', null);
        $xw->writeAttribute('capability', 'changelist');
        $xw->writeAttribute('at', gmdate('c'));
        if ($since !== null) {
            $xw->writeAttribute('from', $since);
        }
        $xw->endElement();

        // rs:ln rel="self" — canonical URL of this document (page- and since-aware)
        $selfQuery = [];
        if ($page > 0) { $selfQuery[] = 'page=' . $page; }
        if ($since !== null) { $selfQuery[] = 'from=' . urlencode($since); }
        $selfHref = $base . '/resync/changelist.xml' . (!empty($selfQuery) ? '?' . implode('&', $selfQuery) : '');
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'self');
        $xw->writeAttribute('href', $selfHref);
        $xw->endElement();

        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'up');
        $xw->writeAttribute('href', $base . '/resync/capabilitylist.xml');
        $xw->endElement();

        // Pagination links: next/prev for harvesters
        $sinceParam = $since !== null ? '&from=' . urlencode($since) : '';
        if (count($books) === self::PAGE_SIZE) {
            $xw->startElementNs('rs', 'ln', null);
            $xw->writeAttribute('rel', 'next');
            $xw->writeAttribute('href', $base . '/resync/changelist.xml?page=' . ($page + 1) . $sinceParam);
            $xw->endElement();
        }
        if ($page > 0) {
            $xw->startElementNs('rs', 'ln', null);
            $xw->writeAttribute('rel', 'prev');
            $xw->writeAttribute('href', $base . '/resync/changelist.xml?page=' . ($page - 1) . $sinceParam);
            $xw->endElement();
        }

        foreach ($books as $book) {
            if ($book['deleted_at'] !== null) {
                $modified = $this->w3cDate((string) $book['deleted_at']);
                $change   = 'deleted';
            } else {
                $modified = $this->w3cDate((string) ($book['updated_at'] ?? $book['created_at'] ?? ''));
                $change   = ($book['is_new_entry'] ?? false) ? 'created' : 'updated';
            }

            $xw->startElement('url');
            $xw->writeElement('loc', $this->resourceLoc($base, $book));
            $xw->writeElement('lastmod', $modified);
            $xw->startElementNs('rs', 'md', null);
            $xw->writeAttribute('change', $change);
            $xw->writeAttribute('type', $this->resourceType());
            $xw->endElement();
            $xw->endElement(); // url
        }

        $xw->endElement(); // urlset
        $xw->endDocument();

        return (string) $xw->outputMemory();
    }

    // ─── DB helpers ───────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBooks(int $page = 0): array
    {
        $offset = $page * self::PAGE_SIZE;
        $stmt   = $this->db->prepare(
            'SELECT id, titolo, updated_at, created_at
             FROM libri
             WHERE deleted_at IS NULL
             ORDER BY id ASC
             LIMIT ? OFFSET ?'
        );
        if ($stmt === false) {
            return [];
        }
        $limit = self::PAGE_SIZE;
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchChangedBooks(?string $since, int $page = 0): array
    {
        if ($since !== null && !preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:]+Z?)?$/', $since)) {
            $since = null;
        }

        if ($since !== null) {
            // FIX F078: bound tombstone exposure on `?from=` queries.
            // - Live rows: deleted_at IS NULL AND updated_at >= ?  (soft-delete consistency)
            // - Tombstones: deleted_at IS NOT NULL AND deleted_at >= ?
            //   AND deleted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            //   This caps the tombstone window at 90 days regardless of how far back
            //   the harvester asks (?from=1970-01-01 no longer leaks every soft-deleted
            //   id/timestamp ever recorded).
            $stmt = $this->db->prepare(
                'SELECT id, titolo, updated_at, created_at, deleted_at,
                        (created_at >= ?) AS is_new_entry
                 FROM libri
                 WHERE (deleted_at IS NULL AND updated_at >= ?)
                    OR (deleted_at IS NOT NULL AND deleted_at >= ?
                        AND deleted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))
                 ORDER BY COALESCE(deleted_at, updated_at) ASC
                 LIMIT ? OFFSET ?'
            );
            if ($stmt === false) {
                return [];
            }
            $limit  = self::PAGE_SIZE;
            $offset = max(0, $page) * self::PAGE_SIZE;
            $stmt->bind_param('sssii', $since, $since, $since, $limit, $offset);
        } else {
            // Include recent tombstones (≤30 days) for ResourceSync — intentional exception to strict deleted_at IS NULL rule
            $stmt = $this->db->prepare(
                'SELECT id, titolo, updated_at, created_at, deleted_at, 0 AS is_new_entry
                 FROM libri
                 WHERE (deleted_at IS NULL OR deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
                 ORDER BY COALESCE(deleted_at, updated_at, created_at) DESC
                 LIMIT ? OFFSET ?'
            );
            if ($stmt === false) {
                return [];
            }
            $limit  = self::PAGE_SIZE;
            $offset = max(0, $page) * self::PAGE_SIZE;
            $stmt->bind_param('ii', $limit, $offset);
        }

        $stmt->execute();
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    // ─── Utilities ────────────────────────────────────────────────────────────

    private function baseUrl(): string
    {
        return \App\Support\HtmlHelper::getBaseUrl();
    }

    /**
     * Resolve the canonical <loc> URL for a book.
     *
     * Prefers the BIBFRAME JSON-LD endpoint (richer linked-data view) only when
     * the bibframe-linked-data plugin is active, since that route is registered
     * exclusively by that plugin. Otherwise falls back to the always-available
     * core book detail URL, so ResourceSync documents never emit <loc> URLs that
     * 404 for harvesters when the optional plugin is not installed/active.
     *
     * @param array<string, mixed> $book
     */
    private function resourceLoc(string $base, array $book): string
    {
        if ($this->bibframeActive()) {
            return $base . '/api/bibframe/book/' . (int) $book['id'];
        }
        // book_path() is host-relative and WITHOUT the base path; $base already
        // carries scheme+host+base path, so the concatenation is absolute.
        return $base . \book_path($book);
    }

    /** MIME type served by the URL returned from resourceLoc(). */
    private function resourceType(): string
    {
        return $this->bibframeActive() ? 'application/ld+json' : 'text/html';
    }

    /**
     * Whether the bibframe-linked-data plugin is installed and active.
     * Cached per request.
     */
    private function bibframeActive(): bool
    {
        if ($this->bibframeActiveCache !== null) {
            return $this->bibframeActiveCache;
        }

        $active = false;
        $stmt = $this->db->prepare(
            "SELECT id FROM plugins WHERE name = 'bibframe-linked-data' AND is_active = 1 LIMIT 1"
        );
        if ($stmt !== false) {
            $stmt->execute();
            $res = $stmt->get_result();
            $active = ($res instanceof \mysqli_result) && $res->num_rows > 0;
            $stmt->close();
        }

        return $this->bibframeActiveCache = $active;
    }

    private function w3cDate(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return gmdate('c');
        }
        $ts = strtotime($mysqlDatetime);
        if ($ts === false) {
            return gmdate('c');
        }
        return gmdate('c', $ts);
    }

    private function xmlResponse(ResponseInterface $response, string $xml): ResponseInterface
    {
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
