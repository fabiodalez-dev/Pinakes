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

    /**
     * The Emeroteca's citation decomposition, when that plugin is present.
     * Plugin classes have no autoloader scope, so the file is required by
     * hand — and only if it is actually there, because Emeroteca is optional.
     */
    private function loadCitationFormatter(): bool
    {
        if (class_exists(\App\Plugins\Emeroteca\Support\CitationFormatter::class, false)) {
            return true;
        }
        $path = __DIR__ . '/../emeroteca/src/Support/CitationFormatter.php';
        if (!is_file($path)) {
            return false;
        }
        require_once $path;

        return class_exists(\App\Plugins\Emeroteca\Support\CitationFormatter::class, false);
    }

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

        // Journal articles. The Emeroteca plugin catalogues single articles out
        // of periodicals the library does not hold, and those records are the
        // ones a researcher most wants in Zotero — a book they can find by
        // ISBN, an article they cannot.
        $app->get('/api/coins/article/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->articleCoinsAction($request, $response, (int) $args['id']);
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
    var kind="book";
    if(!el){el=document.querySelector("[data-articolo-id]");kind="article";}
    if(!el)return;
    var id=parseInt(el.getAttribute(kind==="book"?"data-libro-id":"data-articolo-id"),10);
    if(!id)return;
    fetch(' . json_encode($basePath . '/api/coins/', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . '+kind+"/"+id)
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

        // A journal request used to be refused with 400. That was honest while
        // the catalogue held no articles; since the Emeroteca plugin gained
        // standalone analytic records it is not, because the very request a
        // researcher sends — "do you have this article?" — is the one this
        // endpoint answered with "not supported". A local article is now
        // matched by DOI first and by title second; anything unmatched still
        // falls through to the external resolver, which is what a link
        // resolver is for.
        $rftValFmt = (string) ($params['rft_val_fmt'] ?? '');
        if ($rftValFmt === 'info:ofi/fmt:kev:mtx:journal') {
            $article = $this->findArticle($params);
            if ($article !== null) {
                // absoluteUrl(), for the same reason localBookUrl() uses it:
                // $request->getUri()->getAuthority() is the client-supplied Host
                // header, so building the origin from it sends the visitor
                // wherever that header says and skips APP_TRUSTED_HOSTS.
                return $response->withStatus(302)->withHeader(
                    'Location',
                    absoluteUrl('/emeroteca/articolo/' . (int) $article['id'])
                );
            }

            return $response->withStatus(302)
                ->withHeader('Location', $this->buildExternalUrl($params, ''));
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

    public function articleCoinsAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $article = $this->loadCitationFormatter() ? $this->fetchArticle($id) : null;
        if ($article === null) {
            $response->getBody()->write((string) json_encode(['error' => true, 'message' => __('Articolo non trovato.')]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $kev  = $this->buildArticleKev($article, $request);
        $html = '<span class="Z3988" title="' . htmlspecialchars($kev, ENT_QUOTES, 'UTF-8') . '"></span>';

        $payload = json_encode([
            'coins_title' => $kev,
            'coins_html'  => $html,
            'article_id'  => $id,
        ]);
        $response->getBody()->write((string) $payload);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * An OpenURL context object for a JOURNAL ARTICLE (Z39.88 mtx:journal).
     *
     * This is the format Zotero, Mendeley and EndNote read straight out of the
     * page, which is what makes an article catalogued here importable without
     * the reader downloading anything. The subfields are the same ones the
     * danMARC2 773 block carries — host title, enumeration, chronology,
     * pagination, ISSN — because 773 and mtx:journal describe the same thing.
     *
     * @param array<string, mixed> $article
     */
    private function buildArticleKev(array $article, ServerRequestInterface $request): string
    {
        $parts = [
            'url_ver'     => 'Z39.88-2004',
            'ctx_ver'     => 'Z39.88-2004',
            'ctx_enc'     => 'info:ofi/enc:UTF-8',
            'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:journal',
            // genre=article is the component part; the newspaper distinction
            // is carried by the host title, not by a different genre.
            'rft.genre'   => 'article',
        ];

        $title = trim((string) ($article['titolo'] ?? ''));
        $subtitle = trim((string) ($article['sottotitolo'] ?? ''));
        if ($subtitle !== '') {
            $title = $title . ' : ' . $subtitle;
        }
        if ($title !== '') {
            $parts['rft.atitle'] = $title;
        }

        foreach ([
            'rft.jtitle' => 'contenitore_titolo',
            'rft.issn'   => 'issn',
            'rft.volume' => 'volume',
            'rft.issue'  => 'numero',
        ] as $key => $column) {
            $value = trim((string) ($article[$column] ?? ''));
            if ($value !== '') {
                $parts[$key] = $value;
            }
        }

        // The page ends come from the same decomposition the citation and the
        // structured data use, so a reader importing into Zotero and a reader
        // copying the APA line cannot be given different pages.
        $pages = \App\Plugins\Emeroteca\Support\CitationFormatter::parts($article);
        if ($pages['pageStart'] !== '') {
            $parts['rft.spage'] = $pages['pageStart'];
        }
        if ($pages['pageEnd'] !== '') {
            $parts['rft.epage'] = $pages['pageEnd'];
        }
        if ($pages['year'] !== '') {
            $parts['rft.date'] = $pages['year'];
        }

        $lang = $this->mapLanguage((string) ($article['lingua'] ?? ''));
        if ($lang !== '') {
            $parts['rft.language'] = $lang;
        }

        $auParts = [];
        $first = true;
        foreach ($pages['authors'] as $name) {
            if ($first) {
                $first = false;
                if (str_contains($name, ',')) {
                    [$last, $given] = explode(',', $name, 2);
                    $auParts[] = 'rft.aulast='  . rawurlencode(trim($last));
                    $auParts[] = 'rft.aufirst=' . rawurlencode(trim($given));
                    continue;
                }
            }
            $auParts[] = 'rft.au=' . rawurlencode($name);
        }

        $doi = trim((string) ($article['doi'] ?? ''));
        if ($doi !== '') {
            $parts['rft_id'] = 'info:doi/' . $doi;
        }

        $uri    = $request->getUri();
        $origin = $uri->getScheme() . '://' . $uri->getHost();
        $parts['rfr_id'] = 'info:sid/' . preg_replace('#^https?://#', '', $origin) . ':pinakes';

        $query = http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
        if ($auParts !== []) {
            $query .= '&' . implode('&', $auParts);
        }

        return $query;
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
            $val = preg_replace('/[^0-9X]/', '', strtoupper(self::param($params, $key))) ?? '';
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

        // Build the search query from whatever title and author the request
        // carries. Every key goes through param(), which asks for both
        // spellings: read directly, `rft.btitle` and friends are never present
        // because PHP has already turned the dot into an underscore, so a
        // spec-conformant OpenURL used to fall through to an EMPTY WorldCat
        // search and only the undotted aliases beside them ever matched.
        //
        // `rft.atitle` and `rft.jtitle` are the journal keys: an unmatched
        // journal request reaches here, and without them the one thing the
        // researcher actually typed — the article title — was dropped.
        $parts = [];
        foreach ([
            'rft.atitle',
            'rft.btitle',
            'rft.jtitle',
            'title',
            'rft.au',
            'au',
            'rft.aulast',
            'rft.aufirst',
            'au_last',
            'au_first',
        ] as $k) {
            $v = self::param($params, $k);
            if ($v !== '' && !in_array($v, $parts, true)) {
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
     * Read an OpenURL key under BOTH spellings.
     *
     * PHP turns a dot into an underscore when it parses a query string, so
     * `rft.atitle=…` arrives as `rft_atitle` and the dotted key a reader of
     * the specification would reach for is never present. Every incoming
     * parameter therefore has to be asked for twice. This was already true of
     * `rft.isbn` on the book path, where the lookup only ever worked through
     * the undotted `isbn` fallback beside it — the dotted attempt in front of
     * it had never matched anything.
     *
     * @param array<string, mixed> $params
     */
    private static function param(array $params, string $key): string
    {
        $value = $params[$key] ?? $params[str_replace('.', '_', $key)] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The Emeroteca's standalone articles, when that plugin is installed.
     *
     * The table belongs to another plugin and may not exist at all, so every
     * path through here tolerates its absence: a resolver that fatals because
     * an optional plugin is switched off is worse than one that finds nothing.
     */
    private function articlesTableExists(): bool
    {
        $res = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emeroteca_contributi'
                AND EXISTS (SELECT 1 FROM plugins WHERE name = 'emeroteca' AND is_active = 1) LIMIT 1"
        );

        return $res instanceof \mysqli_result && $res->num_rows > 0;
    }

    /**
     * Find a published article from an incoming OpenURL.
     *
     * DOI first, because it identifies the article and nothing else. Title
     * second, and only on an exact match: a LIKE here would resolve a request
     * for one paper to a different paper with a similar name, and a link
     * resolver that sends a reader to the wrong article is worse than one that
     * sends them to the publisher.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function findArticle(array $params): ?array
    {
        if (!$this->articlesTableExists()) {
            return null;
        }

        $doi = self::param($params, 'rft_id');
        if ($doi === '') {
            $doi = self::param($params, 'rft.doi');
        }
        $doi = (string) preg_replace('~^(?:info:doi/|https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', $doi);
        if ($doi !== '') {
            $stmt = $this->db->prepare(
                'SELECT id FROM emeroteca_contributi WHERE pubblico = 1 AND doi = ? LIMIT 1'
            );
            if ($stmt !== false) {
                $lower = strtolower($doi);
                $stmt->bind_param('s', $lower);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (is_array($row)) {
                    return $row;
                }
            }
        }

        $title = self::param($params, 'rft.atitle');
        if ($title === '') {
            return null;
        }
        // Accept the same complete title that our COinS exports. Never strip a
        // colon: it may be part of the actual title rather than punctuation.
        $where = ["pubblico = 1", "(titolo = ? OR CONCAT(titolo, CASE WHEN COALESCE(sottotitolo, '') = '' THEN '' ELSE CONCAT(' : ', sottotitolo) END) = ?)"];
        $values = [$title, $title];
        foreach (['rft.issn' => 'issn', 'rft.jtitle' => 'contenitore_titolo',
                  'rft.volume' => 'volume', 'rft.issue' => 'numero'] as $key => $column) {
            $value = self::param($params, $key);
            if ($value !== '') {
                $where[] = "$column = ?";
                $values[] = $value;
            }
        }
        $stmt = $this->db->prepare('SELECT id FROM emeroteca_contributi WHERE ' . implode(' AND ', $where) . ' LIMIT 2');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // A common title is not an identifier. Let the external resolver handle
        // ambiguity rather than arbitrarily returning the first local record.
        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchArticle(int $id): ?array
    {
        if ($id <= 0 || !$this->articlesTableExists()) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, titolo, sottotitolo, autori, contenitore_titolo, contenitore_tipo, issn,
                    volume, numero, pagine, anno_pubblicazione, data_pubblicazione_testo, doi, lingua
               FROM emeroteca_contributi
              WHERE id = ? AND pubblico = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    /**
     * A requested (desiderata) title is not a holding: never resolve an OpenURL to it.
     *
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
                        AND la.ruolo IN ('principale', 'co-autore')
                      ORDER BY (la.ruolo = 'principale') DESC, COALESCE(la.ordine_credito, 0), la.autore_id
                      LIMIT 1) AS autore_principale
               FROM libri l
              WHERE l.{$col} = ? AND l.deleted_at IS NULL
                AND " . \App\Support\BookVisibility::catalogue($this->db, 'l') . "
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
              WHERE l.id = ? AND l.deleted_at IS NULL
                AND ' . \App\Support\BookVisibility::catalogue($this->db, 'l')
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
                AND la.ruolo IN (\'principale\', \'co-autore\')
              ORDER BY (la.ruolo = \'principale\') DESC, COALESCE(la.ordine_credito, 0), la.autore_id'
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
        $value = strtolower(trim($italianName));
        // An analytic record stores an ISO code, not a language name: `dan`
        // is already what rft.language wants, and running it through a list
        // of Italian names would silently drop it. Books keep storing free
        // text, so both readings have to work here.
        if (preg_match('/^[a-z]{3}$/D', $value) === 1) {
            return $value;
        }
        if (preg_match('/^[a-z]{2}$/D', $value) === 1) {
            return $value;
        }

        return match ($value) {
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
