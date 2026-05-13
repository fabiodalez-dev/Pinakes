<?php

declare(strict_types=1);

namespace App\Plugins\OpenUrlResolver;

use App\Support\HookManager;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OpenURL Z39.88-2004 Resolver + COinS plugin for Pinakes v0.7.2.
 *
 * Endpoints:
 *   GET  /openurl                → Resolver: accepts KEV params, redirects to
 *                                  best available resource (local → WorldCat → Google Books)
 *   GET  /api/coins/book/{id}   → Returns COinS title string and HTML span for a book
 *
 * Hook: assets.head → injects a small script that embeds a <span class="Z3988">
 *       on book detail pages (detected via data-libro-id attribute).
 *
 * KEV context object format (ANSI/NISO Z39.88-2004):
 *   ctx_ver=Z39.88-2004
 *   rft_val_fmt=info:ofi/fmt:kev:mtx:book
 *   rft.btitle, rft.au, rft.isbn, rft.date, rft.pub, rft.language, rft.genre
 *
 * Spec: https://www.niso.org/standards-committees/openurl
 */
class OpenUrlResolverPlugin
{
    /** @phpstan-ignore property.onlyWritten */
    private HookManager $hookManager;
    private \mysqli $db;
    private ?int $pluginId = null;

    // External resolver targets (in priority order when redirecting)
    private const WORLDCAT_SEARCH  = 'https://www.worldcat.org/search?q=';
    private const GOOGLE_BOOKS_ISBN = 'https://books.google.com/books?vid=ISBN';
    private const GOOGLE_BOOKS_QUERY = 'https://books.google.com/books?q=';

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
            $this->registerHookInDb('assets.head', 'injectCoinsScript', 20);
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
            SecureLogger::warning('[OpenUrlResolver] pluginId not set; cannot register hook ' . $hookName);
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
            throw new \RuntimeException('[OpenUrlResolver] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        $callbackClass = 'OpenUrlResolverPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[OpenUrlResolver] hook insert failed for ' . $hookName . ': ' . $err);
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

        $app->get('/openurl', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->resolverAction($request, $response);
        });

        $app->get('/api/coins/book/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->coinsAction($request, $response, (int) $args['id']);
        });
    }

    /** Inject COinS script on book detail pages. */
    public function injectCoinsScript(): void
    {
        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : '';
        // Only inject a lightweight script; it self-disables on non-book pages.
        echo '<script>
(function(){
  document.addEventListener("DOMContentLoaded",function(){
    var el=document.querySelector("[data-libro-id]");
    if(!el)return;
    var id=parseInt(el.getAttribute("data-libro-id"),10);
    if(!id)return;
    fetch(' . json_encode($basePath . '/api/coins/book/', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . '+id)
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){
        if(!d||!d.coins_title)return;
        var s=document.createElement("span");
        s.className="Z3988";
        s.title=d.coins_title;
        s.style.display="none";
        document.body.appendChild(s);
      }).catch(function(){});
  });
})();
</script>' . "\n";
    }

    // ─── Endpoint handlers ────────────────────────────────────────────────────

    public function resolverAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();

        // Soft-validate OpenURL version (log warning; don't hard-reject as many systems omit it)
        $urlVer = (string) ($params['url_ver'] ?? '');
        if ($urlVer !== '' && $urlVer !== 'Z39.88-2004') {
            SecureLogger::warning('[OpenUrlResolver] Non-conformant url_ver received: ' . $urlVer);
        }

        // Reject journal requests — this resolver handles books only
        $rftValFmt = (string) ($params['rft_val_fmt'] ?? '');
        if ($rftValFmt === 'info:ofi/fmt:kev:mtx:journal') {
            $body = (string) json_encode([
                'error'   => true,
                'message' => __('Questo resolver supporta solo risorse di tipo libro (book). I metadati per articoli di riviste non sono supportati.'),
            ]);
            $response->getBody()->write($body);
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // 1. Try to match locally by ISBN
        $isbn = $this->extractIsbn($params);
        if ($isbn !== '') {
            $book = $this->findBookByIsbn($isbn);
            if ($book !== null) {
                $url = $this->localBookUrl($request, $book);
                return $response->withStatus(302)->withHeader('Location', $url);
            }
        }

        // 2. Fall back to external resolvers
        $url = $this->buildExternalUrl($params, $isbn);
        return $response->withStatus(302)->withHeader('Location', $url);
    }

    public function coinsAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $book    = $this->fetchBook($id);
        if ($book === null) {
            $response->getBody()->write((string) json_encode(['error' => true, 'message' => __('Libro non trovato.')]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $authors = $this->fetchAuthors($id);
        $kev     = $this->buildKev($book, $authors, $request);
        $html    = '<span class="Z3988" title="' . htmlspecialchars($kev, ENT_QUOTES, 'UTF-8') . '"></span>';

        $payload = json_encode([
            'coins_title' => $kev,
            'coins_html'  => $html,
            'book_id'     => $id,
        ]);
        $response->getBody()->write((string) $payload);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    // ─── KEV builder ──────────────────────────────────────────────────────────

    /**
     * Build an OpenURL Z39.88-2004 KEV (Key-Encoded Values) context object.
     *
     * @param array<string, mixed>       $book
     * @param list<array<string, mixed>> $authors
     */
    private function buildKev(
        array $book,
        array $authors,
        ServerRequestInterface $request
    ): string {
        $parts = [
            'url_ver'     => 'Z39.88-2004',
            'ctx_ver'     => 'Z39.88-2004',
            'ctx_enc'     => 'info:ofi/enc:UTF-8',
            'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:book',
            'rft.genre'   => 'book',
        ];

        $title = trim((string) ($book['titolo'] ?? ''));
        if ($title !== '') {
            $parts['rft.btitle'] = $title;
        }

        // Authors: first author split into rft.aulast/rft.aufirst if comma-separated
        $auParts     = [];
        $firstAuthor = true;
        foreach ($authors as $a) {
            $name = trim((string) ($a['nome'] ?? ''));
            if ($name === '') { continue; }
            if ($firstAuthor) {
                $firstAuthor = false;
                if (str_contains($name, ',')) {
                    [$last, $first] = explode(',', $name, 2);
                    $auParts[] = 'rft.aulast='  . rawurlencode(trim($last));
                    $auParts[] = 'rft.aufirst=' . rawurlencode(trim($first));
                } else {
                    $auParts[] = 'rft.au=' . rawurlencode($name);
                }
            } else {
                $auParts[] = 'rft.au=' . rawurlencode($name);
            }
        }

        $isbn13 = trim((string) ($book['isbn13'] ?? ''));
        $isbn10 = trim((string) ($book['isbn10'] ?? ''));
        $isbn   = $isbn13 !== '' ? $isbn13 : $isbn10;
        if ($isbn !== '') {
            $parts['rft.isbn'] = preg_replace('/[^0-9X]/', '', strtoupper($isbn)) ?? '';
        }

        $year = (int) ($book['anno_pubblicazione'] ?? 0);
        if ($year > 0) {
            $parts['rft.date'] = (string) $year;
        }

        $publisher = trim((string) ($book['editore'] ?? ''));
        if ($publisher !== '') {
            $parts['rft.pub'] = $publisher;
        }

        $lang = $this->mapLanguage((string) ($book['lingua'] ?? ''));
        if ($lang !== '') {
            $parts['rft.language'] = $lang;
        }

        // rfr_id — identifies this resolver
        $uri    = $request->getUri();
        $origin = $uri->getScheme() . '://' . $uri->getHost();
        $parts['rfr_id'] = 'info:sid/' . preg_replace('#^https?://#', '', $origin) . ':pinakes';

        // Build the query string (handle repeated rft.au manually)
        $query = http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
        if ($auParts !== []) {
            $query .= '&' . implode('&', $auParts);
        }
        return $query;
    }

    // ─── Resolver helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     */
    private function extractIsbn(array $params): string
    {
        foreach (['rft.isbn', 'isbn', 'rft_id'] as $key) {
            $val = (string) ($params[$key] ?? '');
            $val = preg_replace('/[^0-9X]/', '', strtoupper($val)) ?? '';
            if (strlen($val) === 13 || strlen($val) === 10) {
                return $val;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildExternalUrl(array $params, string $isbn): string
    {
        // If we have an ISBN, prefer Google Books direct lookup
        if ($isbn !== '') {
            return self::GOOGLE_BOOKS_ISBN . rawurlencode($isbn);
        }

        // Build search query from title + author
        $parts = [];
        foreach ([
            'rft.btitle',
            'title',
            'rft.au',
            'au',
            'rft.aulast',
            'rft.aufirst',
            'au_last',
            'au_first',
        ] as $k) {
            $v = trim((string) ($params[$k] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }

        if ($parts !== []) {
            return self::WORLDCAT_SEARCH . rawurlencode(implode(' ', $parts));
        }

        // Fallback to Google Books with raw query string
        $q = trim((string) ($params['q'] ?? $params['query'] ?? ''));
        return $q !== ''
            ? self::GOOGLE_BOOKS_QUERY . rawurlencode($q)
            : self::WORLDCAT_SEARCH;
    }

    /**
     * Build the absolute URL to the local book detail page, respecting the
     * installation locale (route_path('book') resolves to '/libro' in it_IT
     * and '/book' in en_US, etc.) and the configured base path.
     *
     * @param array<string, mixed> $book
     */
    private function localBookUrl(ServerRequestInterface $request, array $book): string
    {
        // Use absoluteUrl() so the origin goes through the trusted-host check
        // (APP_TRUSTED_HOSTS in HtmlHelper::getBaseUrl). Reading the Host header
        // from $request->getUri() directly bypasses that guard.
        return absoluteUrl(book_url($book));
    }

    // ─── DB helpers ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function findBookByIsbn(string $isbn): ?array
    {
        $col  = strlen($isbn) === 13 ? 'isbn13' : 'isbn10';
        $stmt = $this->db->prepare(
            "SELECT l.id, l.titolo,
                    (SELECT a.nome
                       FROM libri_autori la
                       JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id
                      ORDER BY COALESCE(la.ordine_credito, 0), la.autore_id
                      LIMIT 1) AS autore_principale
               FROM libri l
              WHERE l.{$col} = ? AND l.deleted_at IS NULL
              LIMIT 1"
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('s', $isbn);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchBook(int $id): ?array
    {
        if ($id <= 0) { return null; }
        $stmt = $this->db->prepare(
            'SELECT l.id, l.titolo, l.isbn10, l.isbn13,
                    l.anno_pubblicazione, l.lingua, e.nome AS editore
               FROM libri l
               LEFT JOIN editori e ON e.id = l.editore_id
              WHERE l.id = ? AND l.deleted_at IS NULL'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAuthors(int $bookId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.nome
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

    // ─── Utilities ────────────────────────────────────────────────────────────

    private function mapLanguage(string $italianName): string
    {
        return match (strtolower(trim($italianName))) {
            'italiano', 'italian' => 'ita',
            'inglese', 'english'  => 'eng',
            'tedesco', 'german'   => 'ger',
            'francese', 'french'  => 'fre',
            'spagnolo', 'spanish' => 'spa',
            'portoghese', 'portuguese' => 'por',
            'russo', 'russian'    => 'rus',
            'cinese', 'chinese'   => 'chi',
            'giapponese', 'japanese' => 'jpn',
            'arabo', 'arabic'     => 'ara',
            default               => '',
        };
    }
}
