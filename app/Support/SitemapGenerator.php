<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use RuntimeException;
use Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;
use Thepixeldeveloper\Sitemap\Url;
use Thepixeldeveloper\Sitemap\Urlset;

class SitemapGenerator
{
    private mysqli $db;
    private string $baseUrl;

    /**
     * @var array<string> Active locale codes
     */
    private array $activeLocales = [];

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
        'books' => 0,
        'authors' => 0,
        'publishers' => 0,
        'genres' => 0,
    ];

    public function __construct(mysqli $db, string $baseUrl)
    {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->loadActiveLocales();
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
     * @return array<int,array<string,mixed>>
     */
    private function getStaticEntries(): array
    {
        $entries = [];
        $staticPages = [
            ['path' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['path' => '/catalogo', 'changefreq' => 'daily', 'priority' => '0.9'],
            ['path' => '/chi-siamo', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['path' => '/contatti', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['path' => '/privacy-policy', 'changefreq' => 'yearly', 'priority' => '0.4'],
            ['path' => '/register', 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['path' => '/login', 'changefreq' => 'monthly', 'priority' => '0.4'],
        ];

        // Generate URL for each active locale
        foreach ($this->activeLocales as $locale) {
            $localePrefix = $this->getLocalePrefix($locale);

            foreach ($staticPages as $page) {
                $entries[] = [
                    'loc' => $this->baseUrl . $localePrefix . $page['path'],
                    'changefreq' => $page['changefreq'],
                    'priority' => $page['priority'],
                ];
            }
        }

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

                $lastmod = $row['updated_at'] ?? $row['created_at'] ?? null;

                // Generate URL for each active locale
                foreach ($this->activeLocales as $locale) {
                    $localePrefix = $this->getLocalePrefix($locale);

                    $entries[] = [
                        'loc' => $this->baseUrl . $localePrefix . '/' . rawurlencode($slug),
                        'changefreq' => 'monthly',
                        'priority' => '0.6',
                        'lastmod' => $lastmod,
                    ];
                }
            }
            $result->free();
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getBookEntries(): array
    {
        $entries = [];
        $sql = "
            SELECT l.id,
                   l.titolo,
                   l.updated_at,
                   l.created_at,
                   (
                       SELECT a.nome
                       FROM libri_autori la
                       JOIN autori a ON la.autore_id = a.id
                       WHERE la.libro_id = l.id
                       ORDER BY CASE la.ruolo WHEN 'principale' THEN 0 ELSE 1 END, la.ordine_credito
                       LIMIT 1
                   ) AS autore_principale
            FROM libri l
            WHERE l.deleted_at IS NULL
            ORDER BY l.updated_at DESC
            LIMIT 2000
        ";

        if ($result = $this->db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $id = isset($row['id']) ? (int)$row['id'] : null;
                $title = (string)($row['titolo'] ?? '');
                if ($id === null || $id <= 0 || $title === '') {
                    continue;
                }

                // Generate URL for each active locale
                foreach ($this->activeLocales as $locale) {
                    $localePrefix = $this->getLocalePrefix($locale);
                    $bookPath = $this->buildBookPath($id, $title, (string)($row['autore_principale'] ?? ''));

                    $entries[] = [
                        'loc' => $this->baseUrl . $localePrefix . $bookPath,
                        'changefreq' => 'weekly',
                        'priority' => '0.8',
                        'lastmod' => $row['updated_at'] ?? $row['created_at'] ?? null,
                    ];
                }
            }
            $result->free();
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getAuthorEntries(): array
    {
        $entries = [];
        $sql = "SELECT nome, created_at FROM autori ORDER BY created_at DESC LIMIT 500";

        if ($result = $this->db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['nome'] ?? ''));
                if ($name === '') {
                    continue;
                }

                // Generate URL for each active locale
                foreach ($this->activeLocales as $locale) {
                    $localePrefix = $this->getLocalePrefix($locale);

                    $entries[] = [
                        'loc' => $this->baseUrl . $localePrefix . '/autore/' . rawurlencode($name),
                        'changefreq' => 'monthly',
                        'priority' => '0.6',
                        'lastmod' => $row['created_at'] ?? null,
                    ];
                }
            }
            $result->free();
        }

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getPublisherEntries(): array
    {
        $entries = [];
        $sql = "SELECT nome FROM editori ORDER BY nome ASC";

        if ($result = $this->db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['nome'] ?? ''));
                if ($name === '') {
                    continue;
                }

                // Generate URL for each active locale
                foreach ($this->activeLocales as $locale) {
                    $localePrefix = $this->getLocalePrefix($locale);

                    $entries[] = [
                        'loc' => $this->baseUrl . $localePrefix . '/editore/' . rawurlencode($name),
                        'changefreq' => 'monthly',
                        'priority' => '0.5',
                        'lastmod' => null,
                    ];
                }
            }
            $result->free();
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
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['nome'] ?? ''));
                if ($name === '') {
                    continue;
                }

                // Generate URL for each active locale
                foreach ($this->activeLocales as $locale) {
                    $localePrefix = $this->getLocalePrefix($locale);

                    $entries[] = [
                        'loc' => $this->baseUrl . $localePrefix . '/genere/' . rawurlencode($name),
                        'changefreq' => 'monthly',
                        'priority' => '0.5',
                        'lastmod' => null,
                    ];
                }
            }
            $result->free();
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
        } catch (\Exception $exception) {
            // Ignore invalid dates
        }
    }

    /**
     * Load active locales from database
     */
    private function loadActiveLocales(): void
    {
        try {
            $result = $this->db->query("
                SELECT code, is_default
                FROM languages
                WHERE is_active = 1
                ORDER BY is_default DESC, code ASC
            ");

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = (string)($row['code'] ?? '');
                    if ($code !== '') {
                        $this->activeLocales[] = $code;
                        if ((int)($row['is_default'] ?? 0) === 1) {
                            $this->defaultLocale = $code;
                        }
                    }
                }
                $result->free();
            }
        } catch (\Exception $e) {
            // Fallback to default locale only
            $this->activeLocales = ['it_IT'];
            $this->defaultLocale = 'it_IT';
        }

        // Ensure at least one locale
        if (empty($this->activeLocales)) {
            $this->activeLocales = ['it_IT'];
        }
    }

    /**
     * Get locale prefix for URL (empty for default locale, /xx for others)
     */
    private function getLocalePrefix(string $locale): string
    {
        // Default locale has no prefix
        if ($locale === $this->defaultLocale) {
            return '';
        }

        // Extract language code (first 2 chars of locale: it_IT -> it, en_US -> en)
        $langCode = strtolower(substr($locale, 0, 2));
        return '/' . $langCode;
    }

    private function buildBookPath(int $bookId, string $title, string $authorName): string
    {
        return book_url([
            'id' => $bookId,
            'titolo' => $title,
            'autore_principale' => $authorName,
        ]);
    }
}
