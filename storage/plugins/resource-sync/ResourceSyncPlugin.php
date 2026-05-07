<?php

declare(strict_types=1);

namespace App\Plugins\ResourceSync;

use App\Support\HookManager;
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
        $this->deleteHooksFromDb();
    }

    public function onInstall(): void {}
    public function onUninstall(): void {}

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[ResourceSync] pluginId not set; cannot register hook ' . $hookName);
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
            throw new \RuntimeException('[ResourceSync] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        $callbackClass = 'ResourceSyncPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[ResourceSync] hook insert failed for ' . $hookName . ': ' . $err);
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

    /** Register routes via the HookManager. */
    public function registerRoutes(\Slim\App $app): void
    {
        $plugin = $this;

        // RFC 5785 well-known URI — source description
        $app->get('/.well-known/resourcesync', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->sourceDescriptionAction($request, $response);
        });

        $app->get('/resync/capabilitylist.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->capabilityListAction($request, $response);
        });

        $app->get('/resync/resourcelist.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->resourceListAction($request, $response);
        });

        $app->get('/resync/changelist.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->changeListAction($request, $response);
        });
    }

    // ─── Endpoint handlers ────────────────────────────────────────────────────

    public function sourceDescriptionAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $base = $this->baseUrl($request);
        $xml  = $this->buildSourceDescription($base);
        return $this->xmlResponse($response, $xml);
    }

    public function capabilityListAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $base = $this->baseUrl($request);
        $xml  = $this->buildCapabilityList($base);
        return $this->xmlResponse($response, $xml);
    }

    public function resourceListAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $base   = $this->baseUrl($request);
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
        $base   = $this->baseUrl($request);
        $params = $request->getQueryParams();
        $since  = isset($params['from']) ? (string) $params['from'] : null;
        $books  = $this->fetchChangedBooks($since);
        $xml    = $this->buildChangeList($base, $books, $since);
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

        // rs:ln — self-reference for ResourceSync discovery
        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'resourcesync');
        $xw->writeAttribute('href', $base . '/resync/capabilitylist.xml');
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
            $id        = (int) $book['id'];
            $modified  = $this->w3cDate((string) ($book['updated_at'] ?? $book['created_at'] ?? ''));
            $loc       = $base . '/api/bibframe/book/' . $id;

            $xw->startElement('url');
            $xw->writeElement('loc', $loc);
            $xw->writeElement('lastmod', $modified);
            $xw->startElementNs('rs', 'md', null);
            $xw->writeAttribute('type', 'application/ld+json');
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
    private function buildChangeList(string $base, array $books, ?string $since): string
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

        $xw->startElementNs('rs', 'ln', null);
        $xw->writeAttribute('rel', 'up');
        $xw->writeAttribute('href', $base . '/resync/capabilitylist.xml');
        $xw->endElement();

        foreach ($books as $book) {
            $id = (int) $book['id'];
            if ($book['deleted_at'] !== null) {
                $modified = $this->w3cDate((string) $book['deleted_at']);
                $change   = 'deleted';
            } else {
                $modified = $this->w3cDate((string) ($book['updated_at'] ?? $book['created_at'] ?? ''));
                $change   = ($book['is_new_entry'] ?? false) ? 'created' : 'updated';
            }

            $xw->startElement('url');
            $xw->writeElement('loc', $base . '/api/bibframe/book/' . $id);
            $xw->writeElement('lastmod', $modified);
            $xw->startElementNs('rs', 'md', null);
            $xw->writeAttribute('change', $change);
            $xw->writeAttribute('type', 'application/ld+json');
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
            'SELECT id, updated_at, created_at
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
    private function fetchChangedBooks(?string $since): array
    {
        if ($since !== null && !preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:]+Z?)?$/', $since)) {
            $since = null;
        }

        if ($since !== null) {
            // Include deleted records so harvesters can remove them
            $stmt = $this->db->prepare(
                'SELECT id, updated_at, created_at, deleted_at,
                        (created_at >= ?) AS is_new_entry
                 FROM libri
                 WHERE updated_at >= ? OR deleted_at >= ?
                 ORDER BY COALESCE(deleted_at, updated_at) ASC'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('sss', $since, $since, $since);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, updated_at, created_at, deleted_at, 0 AS is_new_entry
                 FROM libri
                 WHERE deleted_at IS NULL
                 ORDER BY updated_at DESC
                 LIMIT 500'
            );
            if ($stmt === false) {
                return [];
            }
        }

        $stmt->execute();
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    // ─── Utilities ────────────────────────────────────────────────────────────

    private function baseUrl(ServerRequestInterface $request): string
    {
        $uri    = $request->getUri();
        $scheme = $uri->getScheme();
        $host   = $uri->getHost();
        $port   = $uri->getPort();

        $base = $scheme . '://' . $host;
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $base .= ':' . $port;
        }

        $basePath = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        return $base . $basePath;
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
