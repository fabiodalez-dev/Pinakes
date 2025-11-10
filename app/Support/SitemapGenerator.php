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
        return [
            [
                'loc' => $this->baseUrl . '/',
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'loc' => $this->baseUrl . '/catalogo',
                'changefreq' => 'daily',
                'priority' => '0.9',
            ],
            [
                'loc' => $this->baseUrl . '/chi-siamo',
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ],
            [
                'loc' => $this->baseUrl . '/contatti',
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ],
            [
                'loc' => $this->baseUrl . '/privacy-policy',
                'changefreq' => 'yearly',
                'priority' => '0.4',
            ],
            [
                'loc' => $this->baseUrl . '/register',
                'changefreq' => 'monthly',
                'priority' => '0.5',
            ],
            [
                'loc' => $this->baseUrl . '/login',
                'changefreq' => 'monthly',
                'priority' => '0.4',
            ],
        ];
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
                $entries[] = [
                    'loc' => $this->baseUrl . '/' . rawurlencode($slug),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                    'lastmod' => $lastmod,
                ];
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
                       ORDER BY CASE la.ruolo WHEN 'principale' THEN 0 ELSE 1 END, la.id
                       LIMIT 1
                   ) AS autore_principale
            FROM libri l
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

                $entries[] = [
                    'loc' => $this->baseUrl . $this->buildBookPath($id, $title, (string)($row['autore_principale'] ?? '')),
                    'changefreq' => 'weekly',
                    'priority' => '0.8',
                    'lastmod' => $row['updated_at'] ?? $row['created_at'] ?? null,
                ];
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

                $entries[] = [
                    'loc' => $this->baseUrl . '/autore/' . rawurlencode($name),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                    'lastmod' => $row['created_at'] ?? null,
                ];
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

                $entries[] = [
                    'loc' => $this->baseUrl . '/editore/' . rawurlencode($name),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                    'lastmod' => null,
                ];
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
            JOIN libri l ON l.genere_id = g.id
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

                $entries[] = [
                    'loc' => $this->baseUrl . '/genere/' . rawurlencode($name),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                    'lastmod' => null,
                ];
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

    private function buildBookPath(int $bookId, string $title, string $authorName): string
    {
        return book_url([
            'id' => $bookId,
            'titolo' => $title,
            'autore_principale' => $authorName,
        ]);
    }
}
