<?php

namespace App\Plugins\ScrapingPro;

use App\Support\HookManager;
use App\Support\Hooks;
use mysqli;

class ScrapingProPlugin
{
    private ?mysqli $db;
    private ?HookManager $hookManager;
    private ?int $pluginId = null;

    private const LIBRERIA_PATTERN = 'https://www.libreriauniversitaria.it/morale-anarchica-libro-petr-alekseevic-kropotkin/libro/{isbn}';
    private const LIBRERIA_HOSTS = ['www.libreriauniversitaria.it', 'libreriauniversitaria.it'];
    private const FELTRINELLI_PATTERN = 'https://www.lafeltrinelli.it/morale-anarchica-libro-petr-alekseevic-kropotkin/e/{isbn}';
    private const FELTRINELLI_HOSTS = ['www.lafeltrinelli.it', 'lafeltrinelli.it'];
    private const USER_AGENT = 'Mozilla/5.0 (compatible; BibliotecaBot/1.0) Safari/537.36';
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 20;

    public function __construct(?mysqli $db = null, ?HookManager $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;

        if ($this->db instanceof mysqli) {
            $this->pluginId = $this->resolvePluginId($this->db);
        }
    }

    public function activate(): void
    {
        Hooks::add('scrape.sources', [$this, 'registerSources'], 2);
        Hooks::add('scrape.fetch.custom', [$this, 'scrape'], 2);
    }

    public function onInstall(): void
    {
        $this->registerHooks(true);
    }

    public function onActivate(): void
    {
        $this->registerHooks(true);
    }

    public function onDeactivate(): void
    {
        $this->setHooksActive(false);
    }

    public function onUninstall(): void
    {
        $this->deleteHooks();
    }

    public function registerSources(array $sources, string $isbn): array
    {
        $sources['libreriauniversitaria'] = [
            'name' => 'LibreriaUniversitaria',
            'url_pattern' => self::LIBRERIA_PATTERN,
            'enabled' => true,
            'priority' => 2,
            'fields' => ['title', 'subtitle', 'authors', 'publisher', 'isbn', 'ean', 'pubDate', 'pages', 'format', 'description', 'notes', 'tipologia'],
        ];

        $sources['feltrinelli_cover'] = [
            'name' => 'Feltrinelli (Copertina)',
            'url_pattern' => self::FELTRINELLI_PATTERN,
            'enabled' => true,
            'priority' => 3,
            'fields' => ['image'],
        ];

        return $sources;
    }

    public function scrape($current, array $sources, string $isbn): ?array
    {
        if ($current !== null) {
            return $current;
        }

        if (empty($sources['libreriauniversitaria']['enabled'])) {
            return null;
        }

        $source = $sources['libreriauniversitaria'];
        $urlPattern = $source['url_pattern'] ?? self::LIBRERIA_PATTERN;
        $url = str_replace('{isbn}', rawurlencode($isbn), $urlPattern);

        Hooks::do('scrape.before', [$source, $url, $isbn]);

        $page = $this->fetchLibreriaPage($url, $source, $isbn);
        if ($page === null) {
            return null;
        }

        [$html, $finalUrl] = $page;
        Hooks::do('scrape.after', [$html, $source, $isbn]);

        $xpath = $this->createXPath($html);
        if ($xpath === null) {
            Hooks::do('scrape.error', [
                'error' => 'invalid_html',
                'source' => $source,
                'isbn' => $isbn,
                'context' => ['url' => $finalUrl],
            ]);
            return null;
        }

        $image = $this->scrapeFeltrinelliCover($isbn);
        if ($image === '') {
            $image = $this->extractImage($xpath);
        }

        $author = $this->extractText($xpath, "//div[contains(@class, 'product-author')]//a");
        $authors = $this->extractAuthors($xpath);
        if (empty($authors) && $author !== '') {
            $authors = [$author];
        }

        $publisher = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Editore:')]/following-sibling::dd[1]//span[contains(@class, 'text')]");
        $pubDate = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Data di Pubblicazione:')]/following-sibling::dd[1]");
        $ean = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'EAN:')]/following-sibling::dd[1]//h2");
        $isbnScraped = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'ISBN:')]/following-sibling::dd[1]//h2");
        $pages = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Pagine:')]/following-sibling::dd[1]");
        $format = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Formato:')]/following-sibling::dd[1]");
        $collana = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Collana:')]/following-sibling::dd[1]//span[contains(@class, 'text')]");
        $translator = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Traduttore:')]/following-sibling::dd[1]");
        $description = $this->extractText($xpath, "//div[contains(@class, 'product-sinossi')]//div[contains(@class, 'truncate-text')]//p");
        $price = $this->extractText($xpath, "//div[contains(@class, 'product-price-container')]//span[contains(@class, 'current-price')]//span[contains(@class, 'value')]");
        $tipologia = $this->extractText($xpath, "//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Tipologia:')]/following-sibling::dd[1]");

        $title = $this->extractText($xpath, "//div[contains(@class, 'title-wrapper')]//h1[contains(@class, 'pc-title')]");
        $subtitle = $this->extractText($xpath, "//h2[contains(@class, 'product-subtitle')]");

        $notes = $this->buildNotes($pubDate, $collana, $tipologia);

        $sourceContext = array_merge($source, ['url' => $finalUrl]);

        $parsedData = [
            'title' => $title,
            'subtitle' => $subtitle,
            'series' => $collana,
            'author' => $author,
            'authors' => $authors,
            'publisher' => $publisher,
            'isbn' => $isbnScraped ?: $isbn,
            'ean' => $ean,
            'pubDate' => $pubDate,
            'price' => $price,
            'translator' => $translator,
            'pages' => $pages,
            'format' => $format,
            'description' => $description,
            'image' => $image,
            'notes' => $notes,
            'tipologia' => $tipologia,
            'source' => $finalUrl,
        ];

        $parsedData = Hooks::apply('scrape.parse', $parsedData, [$html, $sourceContext, $isbn]);

        $validation = Hooks::apply('scrape.validate.data', [
            'valid' => !empty($parsedData['title']),
            'errors' => [],
            'data' => $parsedData,
        ], [$parsedData, $sourceContext, $isbn]);

        if (!$validation['valid']) {
            Hooks::do('scrape.validation.failed', [
                $validation['errors'],
                $sourceContext,
                $isbn,
                $parsedData,
            ]);
            // Return null to allow other plugins to try
            return null;
        }

        $payload = $validation['data'];
        $payload = Hooks::apply('scrape.data.modify', $payload, [$isbn, $sourceContext, $payload]);

        return $payload;
    }

    private function fetchLibreriaPage(string $url, array $source, string $isbn): ?array
    {
        $ch = curl_init();
        $defaultOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $curlOptions = Hooks::apply('scrape.http.options', $defaultOptions, [$source, $url]);
        curl_setopt_array($ch, $curlOptions);

        $html = curl_exec($ch);
        if ($html === false || $html === '') {
            $err = curl_error($ch) ?: 'empty response';
            $eff = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            if ($eff) {
                curl_setopt($ch, CURLOPT_URL, $eff);
                $html = curl_exec($ch);
            }
            if ($html === false || $html === '') {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                Hooks::do('scrape.error', [
                    'error' => $err,
                    'source' => $source,
                    'isbn' => $isbn,
                    'context' => ['code' => $code, 'url' => $eff],
                ]);
                return null;
            }
        }

        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        $finalHost = parse_url($finalUrl, PHP_URL_HOST) ?: '';
        curl_close($ch);

        if (!in_array($finalHost, self::LIBRERIA_HOSTS, true)) {
            Hooks::do('scrape.error', [
                'error' => 'domain_not_allowed',
                'source' => $source,
                'isbn' => $isbn,
                'context' => ['url' => $finalUrl],
            ]);
            return null;
        }

        return [$html, $finalUrl];
    }

    private function createXPath(string $html): ?\DOMXPath
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $libxmlFlags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        $prev = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $prev = libxml_disable_entity_loader(true);
        }
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, $libxmlFlags);
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            libxml_disable_entity_loader((bool)$prev);
        }
        libxml_clear_errors();

        if (!$loaded) {
            return null;
        }

        return new \DOMXPath($doc);
    }

    private function scrapeFeltrinelliCover(string $isbn): string
    {
        $url = str_replace('{isbn}', rawurlencode($isbn), self::FELTRINELLI_PATTERN);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $html = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        $finalHost = parse_url($finalUrl, PHP_URL_HOST) ?: '';
        curl_close($ch);

        if ($html === false || $html === '' || $httpCode !== 200) {
            return '';
        }

        if (!in_array($finalHost, self::FELTRINELLI_HOSTS, true)) {
            return '';
        }

        $xpath = $this->createXPath($html);
        if ($xpath === null) {
            return '';
        }

        $imgNode = $xpath->query("//div[contains(@class, 'cc-content-img')]//img[contains(@class, 'cc-img')]");
        if ($imgNode->length > 0) {
            $src = $imgNode->item(0)->getAttribute('src');
            if (strpos($src, 'https://www.lafeltrinelli.it/') === 0) {
                return $src;
            }
        }

        return '';
    }

    private function extractImage(\DOMXPath $xpath): string
    {
        $imgNode = $xpath->query("//div[@id='image']//img");
        if ($imgNode->length > 0) {
            return $imgNode->item(0)->getAttribute('src') ?: '';
        }
        return '';
    }

    private function extractText(\DOMXPath $xpath, string $query): string
    {
        $nodeList = $xpath->query($query);
        if ($nodeList === false || $nodeList->length === 0) {
            return '';
        }
        return trim($nodeList->item(0)->textContent);
    }

    private function extractAuthors(\DOMXPath $xpath): array
    {
        $authors = [];
        $authorNodes = $xpath->query("//div[contains(@class, 'product-author')]//a");
        if ($authorNodes !== false && $authorNodes->length > 0) {
            foreach ($authorNodes as $authorNode) {
                $authorText = trim($authorNode->textContent);
                if ($authorText !== '') {
                    $authors[] = $authorText;
                }
            }
        }
        return $authors;
    }

    private function buildNotes(string $pubDate, string $collana, string $tipologia): string
    {
        $notes = [];
        if ($pubDate !== '') {
            $notes[] = 'Data di pubblicazione: ' . $pubDate;
        }
        if ($collana !== '') {
            $notes[] = 'Collana: ' . $collana;
        }
        if ($tipologia !== '') {
            $notes[] = 'Tipologia: ' . $tipologia;
        }
        return implode("\n", $notes);
    }

    private function resolvePluginId(mysqli $db): ?int
    {
        $stmt = $db->prepare("SELECT id FROM plugins WHERE name = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $name = 'scraping-pro';
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    }

    private function registerHooks(bool $active): void
    {
        if ($this->db === null || $this->pluginId === null) {
            return;
        }

        $hooks = [
            ['scrape.sources', 'registerSources', 2],
            ['scrape.fetch.custom', 'scrape', 2],
        ];

        $this->deleteHooks();

        foreach ($hooks as [$hookName, $method, $priority]) {
            $stmt = $this->db->prepare(
                "INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );

            if ($stmt === false) {
                continue;
            }

            $callbackClass = __CLASS__;
            $isActive = $active ? 1 : 0;
            $stmt->bind_param('isssii', $this->pluginId, $hookName, $callbackClass, $method, $priority, $isActive);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function setHooksActive(bool $active): void
    {
        if ($this->db === null || $this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("UPDATE plugin_hooks SET is_active = ? WHERE plugin_id = ?");
        if ($stmt === false) {
            return;
        }

        $activeInt = $active ? 1 : 0;
        $stmt->bind_param('ii', $activeInt, $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    private function deleteHooks(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }
}
