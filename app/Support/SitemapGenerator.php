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
    ];

    public function __construct(mysqli $db, string $baseUrl)
    {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->loadDefaultLocale();
    }

    /**
     * Generate sitemap XML string.
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
        ];

        $urlset = new Urlset();
        /** @var array<string,array<string,mixed>> $unique */
        $unique = [];

        foreach ($this->getStaticEntries() as $entry) {
            $unique[$entry['loc']] = $entry;
            $this->stats['static']++;
        }

        foreach ($this->getCmsEntries() as $entry) {
            $unique[$entry['loc']] = $entry;
            $this->stats['cms']++;
        }

        foreach ($this->getEventEntries() as $entry) {
            $unique[$entry['loc']] = $entry;
            $this->stats['events']++;
        }

        foreach ($this->getBookEntries() as $entry) {
            $unique[$entry['loc']] = $entry;
            $this->stats['books']++;
        }

        foreach ($this->getAuthorEntries() as $entry) {
            $unique[$entry['loc']] = $entry;
            $this->stats['authors']++;
        }

        foreach ($this->getPublisherEntries() as $entry) {
            $unique[$entry['loc']] = $entry;
            $this->stats['publishers']++;
        }

        foreach ($this->getGenreEntries() as $entry) {
            $unique[$entry['loc']] = $entry;
            $this->stats['genres']++;
        }

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
    private function getBookEntries(): array
    {
        $entries = [];
        $limit = self::MAX_BOOKS;
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
    private function getAuthorEntries(): array
    {
        $entries = [];
        $limit = self::MAX_AUTHORS;
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
    private function getPublisherEntries(): array
    {
        $entries = [];
        // Mirror the genre filter: publishers without visible books produce
        // empty archive pages and do not belong in the sitemap.
        $sql = "
            SELECT e.nome
            FROM editori e
            JOIN libri l ON l.editore_id = e.id AND l.deleted_at IS NULL
            GROUP BY e.id, e.nome
            HAVING COUNT(l.id) > 0
            ORDER BY e.nome ASC
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
    private function getGenreEntries(): array
    {
        $entries = [];
        $sql = "
            SELECT g.nome
            FROM generi g
            JOIN libri l ON l.genere_id = g.id AND l.deleted_at IS NULL
            GROUP BY g.id, g.nome
            HAVING COUNT(l.id) > 0
            ORDER BY g.nome ASC
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
