<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\ConfigStore;
use App\Support\I18n;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FeedController
{
    public function rssFeed(Request $request, Response $response, mysqli $db): Response
    {
        $baseUrl = SeoController::resolveBaseUrl($request);
        $appName = (string) ConfigStore::get('app.name', 'Pinakes');
        $appDesc = (string) ConfigStore::get('app.footer_description', '');
        $locale = I18n::getLocale();
        $langCode = strtolower(substr($locale, 0, 2));

        $items = $this->getLatestBooks($db, $baseUrl);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '  <title>' . $this->xmlEscape($appName) . '</title>' . "\n";
        $xml .= '  <link>' . $this->xmlEscape($baseUrl) . '</link>' . "\n";
        $xml .= '  <description>' . $this->xmlEscape($appDesc) . '</description>' . "\n";
        $xml .= '  <language>' . $this->xmlEscape($langCode) . '</language>' . "\n";
        $xml .= '  <atom:link href="' . $this->xmlAttrEscape($baseUrl . '/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n";
        $xml .= '  <lastBuildDate>' . gmdate('r') . '</lastBuildDate>' . "\n";

        foreach ($items as $item) {
            $xml .= '  <item>' . "\n";
            $xml .= '    <title>' . $this->xmlEscape($item['title']) . '</title>' . "\n";
            $xml .= '    <link>' . $this->xmlEscape($item['link']) . '</link>' . "\n";
            $xml .= '    <guid isPermaLink="true">' . $this->xmlEscape($item['link']) . '</guid>' . "\n";
            $xml .= '    <description>' . $this->xmlEscape($item['description']) . '</description>' . "\n";
            $xml .= '    <pubDate>' . $item['pubDate'] . '</pubDate>' . "\n";
            $xml .= '  </item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>' . "\n";

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    /**
     * @return array<int, array{title: string, link: string, description: string, pubDate: string}>
     */
    private function getLatestBooks(mysqli $db, string $baseUrl): array
    {
        $sql = "
            SELECT l.id, l.titolo, l.descrizione_plain, l.created_at,
                   (
                       SELECT a.nome
                       FROM libri_autori la
                       JOIN autori a ON la.autore_id = a.id
                       WHERE la.libro_id = l.id
                       ORDER BY CASE la.ruolo WHEN 'principale' THEN 0 ELSE 1 END, la.ordine_credito
                       LIMIT 1
                   ) AS autore_principale,
                   e.nome AS editore
            FROM libri l
            LEFT JOIN editori e ON l.editore_id = e.id
            WHERE l.deleted_at IS NULL
            ORDER BY l.created_at DESC
            LIMIT 50
        ";

        $items = [];
        $result = $db->query($sql);
        if (!$result) {
            return $items;
        }

        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['id'] ?? 0);
            $title = (string)($row['titolo'] ?? '');
            if ($id <= 0 || $title === '') {
                continue;
            }

            $author = (string)($row['autore_principale'] ?? '');
            $publisher = (string)($row['editore'] ?? '');
            $itemTitle = $title;
            if ($author !== '') {
                $itemTitle .= ' — ' . $author;
            }

            // Build description from plain text excerpt
            $desc = (string)($row['descrizione_plain'] ?? '');
            if (mb_strlen($desc) > 300) {
                $desc = mb_substr($desc, 0, 297) . '...';
            }
            if ($desc === '' && $publisher !== '') {
                $desc = $publisher;
            }

            $bookPath = book_path(['id' => $id, 'titolo' => $title, 'autore_principale' => $author]);
            $link = $baseUrl . $bookPath;

            $pubDate = '';
            if (!empty($row['created_at'])) {
                try {
                    $dt = new \DateTimeImmutable((string)$row['created_at']);
                    $pubDate = $dt->format('r');
                } catch (\Throwable $e) {
                    $pubDate = gmdate('r');
                }
            }

            $items[] = [
                'title' => $itemTitle,
                'link' => $link,
                'description' => $desc,
                'pubDate' => $pubDate,
            ];
        }
        $result->free();

        return $items;
    }

    private function xmlEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xmlAttrEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
