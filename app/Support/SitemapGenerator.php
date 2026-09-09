<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use RuntimeException;
use App\Support\RouteTranslator;
use Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;
use Thepixeldeveloper\Sitemap\Url;
use Thepixeldeveloper\Sitemap\Urlset;

/**
 * Builds the public sitemap.
 *
 * URLs are emitted ONLY for the installation (default) locale and only for
 * routes that actually resolve: the app registers translated route variants
 * at the root and serves the session locale, so locale-prefixed URLs
 * (`/en/...`) do not exist and must never enter the sitemap. Auth pages are
 * excluded on purpose — robots.txt disallows them, and a sitemap must not
 * advertise URLs that robots policy blocks.
 */
class SitemapGenerator
{
    /**
     * Hard per-section caps keep the file under the 50k-URL/50MB sitemap
     * protocol limits. When a cap is hit the generator logs a warning so the
     * truncation is visible instead of silent.
     */
    private const MAX_BOOKS = 40000;
    private const MAX_AUTHORS = 5000;
    // The sitemap protocol caps a single file at 50k URLs. The per-section caps
    // above (plus a few static/CMS/event/publisher/genre rows) stay well under
    // it, but a global ceiling guarantees a valid file even on a pathological
    // catalogue instead of silently emitting an over-limit sitemap.
    private const MAX_TOTAL_URLS = 50000;

    /**
     * Values the sitemap protocol accepts for <changefreq>. Anything else is
     * dropped from a plugin entry instead of producing an invalid sitemap.
     */
    private const VALID_CHANGEFREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    /** Sitemap protocol hard limit for a single <loc>. */
    private const MAX_LOC_LENGTH = 2048;

    private mysqli $db;
    private string $baseUrl;

    /**
     * @var string Default locale code
     */
    private string $defaultLocale = 'it_IT';

    /**
     * @var array<string,int>
     */
    private array $stats = [
        'total' => 0,
        'static' => 0,
        'cms' => 0,
        'events' => 0,
        'books' => 0,
        'authors' => 0,
        'publishers' => 0,
        'genres' => 0,
        'plugins' => 0,
    ];

    public function __construct(mysqli $db, string $baseUrl)
    {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->loadDefaultLocale();
    }

    /**
     * Generate sitemap XML string.
     *
     * Every entry — core and plugin — passes through the `sitemap.entries`
     * filter (see applyEntriesFilter()) before the XML is written, so this one
     * method covers all three entry points: the dynamic /sitemap.xml route
     * (SeoController), the admin "regenerate" button and the CLI script (both
     * of which go through saveTo(), which calls generate()).
     */
    public function generate(): string
    {
        $this->stats = [
            'total' => 0,
            'static' => 0,
            'cms' => 0,
            'events' => 0,
            'books' => 0,
            'authors' => 0,
            'publishers' => 0,
            'genres' => 0,
            'plugins' => 0,
        ];

        $urlset = new Urlset();
        /** @var array<string,array<string,mixed>> $unique */
        $unique = [];

        // Small, bounded sections first (static + CMS + events).
        foreach (['static' => $this->getStaticEntries(), 'cms' => $this->getCmsEntries(), 'events' => $this->getEventEntries()] as $statKey => $entries) {
            foreach ($entries as $entry) {
                $unique[$entry['loc']] = $entry;
                $this->stats[$statKey]++;
            }
        }

        // Large sections receive the remaining capacity, so each query's SQL
        // LIMIT caps allocation AT COLLECTION TIME — memory and the file can
        // never exceed the sitemap protocol's 50k-URL limit even on a
        // pathological catalogue, instead of allocating everything then slicing.
        foreach (['books', 'authors', 'publishers', 'genres'] as $statKey) {
            $remaining = self::MAX_TOTAL_URLS - count($unique);
            if ($remaining <= 0) {
                \App\Support\SecureLogger::warning(
                    'SitemapGenerator: reached the sitemap URL limit; later sections skipped',
                    ['limit' => self::MAX_TOTAL_URLS, 'skipped_from' => $statKey]
                );
                break;
            }
            $entries = match ($statKey) {
                'books' => $this->getBookEntries($remaining),
                'authors' => $this->getAuthorEntries($remaining),
                'publishers' => $this->getPublisherEntries($remaining),
                'genres' => $this->getGenreEntries($remaining),
            };
            foreach ($entries as $entry) {
                $unique[$entry['loc']] = $entry;
                $this->stats[$statKey]++;
                if (count($unique) >= self::MAX_TOTAL_URLS) {
                    break;
                }
            }
        }

        $unique = $this->applyEntriesFilter($unique);

        $this->stats['total'] = count($unique);

        foreach ($unique as $entry) {
            $urlset->add($this->buildUrlEntry($entry));
        }

        $driver = new XmlWriterDriver();
        $driver->addComment('Generated on ' . gmdate('c'));
        $urlset->accept($driver);

        return $driver->output();
    }

    /**
     * Save sitemap to file.
     */
    public function saveTo(string $filePath): void
    {
        $xml = $this->generate();
        $directory = dirname($filePath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Impossibile creare la cartella per la sitemap: {$directory}");
            }
        }

        if (file_put_contents($filePath, $xml) === false) {
            throw new RuntimeException("Impossibile scrivere la sitemap in {$filePath}");
        }
    }

    /**
     * Return generation stats.
     *
     * @return array<string,int>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Run the collected entries through the `sitemap.entries` filter so plugins
     * can add their own public pages (the core generator only knows about
     * static pages, CMS pages, events, books, authors, publishers and genres).
     *
     * CONTRACT — filter `sitemap.entries`
     * -----------------------------------
     * Registration (plugin.json hook or Hooks::add):
     *     hook_name       = 'sitemap.entries'
     *     callback        = fn(array $entries, string $baseUrl, string $defaultLocale): array
     *
     * The listener receives the FULL entry list built so far — it may append,
     * modify or remove entries — and MUST return an array of the same shape.
     * Each entry is an associative array:
     *
     *     'loc'        string   REQUIRED. Absolute URL. Must start with
     *                           $baseUrl (which already contains the base path)
     *                           so a plugin cannot advertise third-party URLs
     *                           in this site's sitemap. `url` is accepted as an
     *                           alias of `loc`.
     *     'lastmod'    ?string  Optional. Anything DateTimeImmutable parses
     *                           (e.g. a MySQL DATETIME). Unparseable values are
     *                           dropped by applyLastMod(), entry kept.
     *     'changefreq' ?string  Optional. One of always|hourly|daily|weekly|
     *                           monthly|yearly|never. Anything else is dropped.
     *     'priority'   mixed    Optional. Numeric 0.0–1.0. Anything else is
     *                           dropped.
     *
     * Robustness rules (a plugin must never be able to break the sitemap):
     *  - a listener that throws is caught here (and inside HookManager) and the
     *    unfiltered core entries are used;
     *  - a return value that is not an array is discarded with a warning;
     *  - an entry without a usable `loc` is skipped with a warning;
     *  - entries are re-keyed by `loc`, so the last entry for a URL wins;
     *  - the MAX_TOTAL_URLS ceiling still applies after filtering.
     *
     * With no listener registered this is a no-op: Hooks::has() short-circuits
     * on a null hook manager and no array is copied.
     *
     * @param array<string,array<string,mixed>> $unique loc => entry
     * @return array<string,array<string,mixed>>
     */
    private function applyEntriesFilter(array $unique): array
    {
        try {
            if (!Hooks::has('sitemap.entries')) {
                return $unique;
            }

            $filtered = Hooks::apply(
                'sitemap.entries',
                array_values($unique),
                [$this->baseUrl, $this->defaultLocale]
            );
        } catch (\Throwable $exception) {
            SecureLogger::warning(
                'SitemapGenerator: sitemap.entries filter failed, keeping core entries: ' . $exception->getMessage()
            );
            return $unique;
        }

        if (!is_array($filtered)) {
            SecureLogger::warning('SitemapGenerator: sitemap.entries returned a non-array value; core entries kept');
            return $unique;
        }

        $result = [];
        $skipped = 0;
        foreach ($filtered as $candidate) {
            $entry = $this->normalizeFilteredEntry($candidate);
            if ($entry === null) {
                $skipped++;
                continue;
            }
            if (count($result) >= self::MAX_TOTAL_URLS && !isset($result[$entry['loc']])) {
                SecureLogger::warning(
                    'SitemapGenerator: sitemap.entries pushed past the URL limit; extra entries dropped',
                    ['limit' => self::MAX_TOTAL_URLS]
                );
                break;
            }
            $result[$entry['loc']] = $entry;
        }

        if ($skipped > 0) {
            SecureLogger::warning(
                'SitemapGenerator: sitemap.entries returned malformed entries that were discarded',
                ['skipped' => $skipped]
            );
        }

        $this->stats['plugins'] = count(array_diff_key($result, $unique));

        return $result;
    }

    /**
     * Validate one entry coming back from the `sitemap.entries` filter.
     *
     * @return array<string,mixed>|null null when the entry is unusable
     */
    private function normalizeFilteredEntry(mixed $candidate): ?array
    {
        if (!is_array($candidate)) {
            return null;
        }

        $rawLoc = $candidate['loc'] ?? $candidate['url'] ?? null;
        if (!is_string($rawLoc) && !is_int($rawLoc) && !is_float($rawLoc)) {
            return null;
        }

        $loc = trim((string) $rawLoc);
        if ($loc === '' || strlen($loc) > self::MAX_LOC_LENGTH) {
            return null;
        }

        // No whitespace or control characters: they would either break the XML
        // or smuggle a second URL / header into the document.
        if (preg_match('/[\x00-\x20\x7F]/', $loc) === 1) {
            return null;
        }

        // Same-origin only. $this->baseUrl is rtrim()-ed of its trailing slash,
        // so requiring the "$baseUrl/" prefix also rejects sibling hosts such
        // as https://example.com.evil/ when baseUrl is https://example.com.
        if ($loc !== $this->baseUrl && !str_starts_with($loc, $this->baseUrl . '/')) {
            return null;
        }

        $entry = ['loc' => $loc];

        $changefreq = $candidate['changefreq'] ?? null;
        if (is_string($changefreq) && in_array(strtolower(trim($changefreq)), self::VALID_CHANGEFREQ, true)) {
            $entry['changefreq'] = strtolower(trim($changefreq));
        } elseif ($changefreq !== null && $changefreq !== '') {
            SecureLogger::warning('SitemapGenerator: sitemap.entries changefreq ignored for ' . $loc);
        }

        $priority = $candidate['priority'] ?? null;
        if (is_numeric($priority) && (float) $priority >= 0.0 && (float) $priority <= 1.0) {
            $entry['priority'] = (string) $priority;
        } elseif ($priority !== null && $priority !== '') {
            SecureLogger::warning('SitemapGenerator: sitemap.entries priority ignored for ' . $loc);
        }

        $lastmod = $candidate['lastmod'] ?? null;
        if (is_string($lastmod) && trim($lastmod) !== '') {
            $entry['lastmod'] = trim($lastmod);
        }

        return $entry;
    }

    /**
     * Resolve a route key to its default-locale path.
     */
    private function routePath(string $key): string
    {
        return RouteTranslator::getRouteForLocale($key, $this->defaultLocale);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getStaticEntries(): array
    {
        // Auth pages (login/register) are deliberately absent: robots.txt
        // disallows them, and advertising blocked URLs triggers
        // "Submitted URL blocked by robots.txt" reports.
        $staticPages = [
            ['path' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['route' => 'catalog', 'changefreq' => 'daily', 'priority' => '0.9'],
            ['route' => 'about', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['route' => 'contact', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['route' => 'privacy', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ];

        $entries = [];
        foreach ($staticPages as $page) {
            $path = isset($page['route']) ? $this->routePath($page['route']) : $page['path'];
            $entries[] = [
                'loc' => $this->baseUrl . $path,
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
            ];
        }

        $entries[] = [
            'loc' => $this->baseUrl . '/feed.xml',
            'changefreq' => 'daily',
            'priority' => '0.3',
        ];

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getCmsEntries(): array
    {
        $entries = [];
        $sql = "SELECT slug, updated_at, created_at FROM cms_pages WHERE is_active = 1 ORDER BY updated_at DESC";

        if ($result = $this->db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                $entries[] = [
                    'loc' => $this->baseUrl . '/' . rawurlencode($slug),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                    'lastmod' => $row['updated_at'] ?? $row['created_at'] ?? null,
                ];
            }
            $result->free();
        } else {
            SecureLogger::warning('SitemapGenerator::getCmsEntries query failed: ' . $this->db->error);
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getEventEntries(): array
    {
        $entries = [];
        $sql = "SELECT slug, updated_at, created_at FROM events WHERE is_active = 1 ORDER BY event_date DESC";

        if ($result = $this->db->query($sql)) {
            $eventsPath = $this->routePath('events');
            while ($row = $result->fetch_assoc()) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                $entries[] = [
                    'loc' => $this->baseUrl . $eventsPath . '/' . rawurlencode($slug),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                    'lastmod' => $row['updated_at'] ?? $row['created_at'] ?? null,
                ];
            }
            $result->free();
        } else {
            SecureLogger::warning('SitemapGenerator::getEventEntries query failed: ' . $this->db->error);
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getBookEntries(int $cap): array
    {
        $entries = [];
        $limit = max(0, min(self::MAX_BOOKS, $cap));
        $sql = "
            SELECT l.id,
                   l.titolo,
                   l.updated_at,
                   l.created_at,
                   (
                       SELECT a.nome
                       FROM libri_autori la
                       JOIN autori a ON la.autore_id = a.id
                       WHERE la.libro_id = l.id AND la.ruolo IN ('principale', 'co-autore')
                       ORDER BY CASE la.ruolo WHEN 'principale' THEN 0 ELSE 1 END, la.ordine_credito
                       LIMIT 1
                   ) AS autore_principale_nome
            FROM libri l
            WHERE l.deleted_at IS NULL
            ORDER BY l.updated_at DESC
            LIMIT {$limit}
        ";

        if ($result = $this->db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $id = isset($row['id']) ? (int)$row['id'] : null;
                $title = (string)($row['titolo'] ?? '');
                if ($id === null || $id <= 0 || $title === '') {
                    continue;
                }

                $entries[] = [
                    'loc' => $this->baseUrl . $this->buildBookPath($id, $title, (string)($row['autore_principale_nome'] ?? '')),
                    'changefreq' => 'weekly',
                    'priority' => '0.8',
                    'lastmod' => $row['updated_at'] ?? $row['created_at'] ?? null,
                ];
            }
            $result->free();
            if (count($entries) >= $limit) {
                SecureLogger::warning('SitemapGenerator: book cap reached (' . $limit . ') — older books are not listed. Consider a sitemap index.');
            }
        } else {
            SecureLogger::warning('SitemapGenerator::getBookEntries query failed: ' . $this->db->error);
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getAuthorEntries(int $cap): array
    {
        $entries = [];
        $limit = max(0, min(self::MAX_AUTHORS, $cap));
        // Only authors with at least one visible book: an empty archive is a
        // thin page that wastes crawl budget.
        $sql = "
            SELECT a.nome, a.created_at
            FROM autori a
            JOIN libri_autori la ON la.autore_id = a.id
            JOIN libri l ON l.id = la.libro_id AND l.deleted_at IS NULL
            GROUP BY a.id, a.nome, a.created_at
            ORDER BY a.created_at DESC
            LIMIT {$limit}
        ";

        if ($result = $this->db->query($sql)) {
            $authorPath = $this->routePath('author');
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['nome'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $entries[] = [
                    'loc' => $this->baseUrl . $authorPath . '/' . rawurlencode($name),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                    'lastmod' => $row['created_at'] ?? null,
                ];
            }
            $result->free();
            if (count($entries) >= $limit) {
                SecureLogger::warning('SitemapGenerator: author cap reached (' . $limit . ') — older authors are not listed.');
            }
        } else {
            SecureLogger::warning('SitemapGenerator::getAuthorEntries query failed: ' . $this->db->error);
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getPublisherEntries(int $limit): array
    {
        $entries = [];
        // Mirror the genre filter: publishers without visible books produce
        // empty archive pages and do not belong in the sitemap. Match
        // publisherArchive(): a book counts for a publisher both as its primary
        // editore_id and — when the schema has it — as a secondary via
        // libri_editori, so secondary-only publishers with real pages are listed.
        $publisherMatch = 'l.editore_id = e.id';
        if (\App\Support\SchemaInfo::hasLibriEditori($this->db)) {
            $publisherMatch .= ' OR EXISTS (
                SELECT 1 FROM libri_editori le
                WHERE le.libro_id = l.id AND le.editore_id = e.id
            )';
        }
        $sql = "
            SELECT e.nome
            FROM editori e
            JOIN libri l ON ({$publisherMatch}) AND l.deleted_at IS NULL
            GROUP BY e.id, e.nome
            HAVING COUNT(l.id) > 0
            ORDER BY e.nome ASC
            LIMIT {$limit}
        ";

        if ($result = $this->db->query($sql)) {
            $publisherPath = $this->routePath('publisher');
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['nome'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $entries[] = [
                    'loc' => $this->baseUrl . $publisherPath . '/' . rawurlencode($name),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                    'lastmod' => null,
                ];
            }
            $result->free();
        } else {
            SecureLogger::warning('SitemapGenerator::getPublisherEntries query failed: ' . $this->db->error);
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getGenreEntries(int $limit): array
    {
        $entries = [];
        $sql = "
            SELECT g.nome
            FROM generi g
            JOIN libri l ON l.genere_id = g.id AND l.deleted_at IS NULL
            GROUP BY g.id, g.nome
            HAVING COUNT(l.id) > 0
            ORDER BY g.nome ASC
            LIMIT {$limit}
        ";

        if ($result = $this->db->query($sql)) {
            $genrePath = $this->routePath('genre');
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['nome'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $entries[] = [
                    'loc' => $this->baseUrl . $genrePath . '/' . rawurlencode($name),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                    'lastmod' => null,
                ];
            }
            $result->free();
        } else {
            SecureLogger::warning('SitemapGenerator::getGenreEntries query failed: ' . $this->db->error);
        }

        return $entries;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function buildUrlEntry(array $entry): Url
    {
        $url = new Url((string)$entry['loc']);

        if (!empty($entry['changefreq'])) {
            $url->setChangeFreq((string)$entry['changefreq']);
        }

        if (isset($entry['priority'])) {
            $priority = is_numeric($entry['priority'])
                ? number_format((float)$entry['priority'], 1, '.', '')
                : (string)$entry['priority'];
            $url->setPriority($priority);
        }

        if (!empty($entry['lastmod'])) {
            $this->applyLastMod($url, (string)$entry['lastmod']);
        }

        return $url;
    }

    private function applyLastMod(Url $url, string $date): void
    {
        try {
            $url->setLastMod(new \DateTimeImmutable($date));
        } catch (\Throwable $exception) {
            SecureLogger::warning('SitemapGenerator: invalid lastmod date: ' . $date);
        }
    }

    /**
     * Load the default (installation) locale from the database.
     */
    private function loadDefaultLocale(): void
    {
        try {
            $result = $this->db->query("
                SELECT code
                FROM languages
                WHERE is_active = 1 AND is_default = 1
                LIMIT 1
            ");

            if ($result) {
                $row = $result->fetch_assoc();
                $code = (string)($row['code'] ?? '');
                if ($code !== '') {
                    $this->defaultLocale = $code;
                }
                $result->free();
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('SitemapGenerator::loadDefaultLocale failed, falling back to it_IT: ' . $e->getMessage());
        }
    }

    private function buildBookPath(int $bookId, string $title, string $authorName): string
    {
        // Delegate to the shared book_path() helper which generates path WITHOUT basePath,
        // since $this->baseUrl already includes it.
        return book_path(['id' => $bookId, 'titolo' => $title, 'autore_principale' => $authorName]);
    }
}
