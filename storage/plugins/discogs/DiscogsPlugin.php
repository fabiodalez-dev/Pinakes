<?php

declare(strict_types=1);

namespace App\Plugins\Discogs;

use App\Support\Hooks;

/**
 * Multi-source Music Scraper Plugin (Discogs, MusicBrainz, Deezer)
 *
 * Integrates Discogs, MusicBrainz + Cover Art Archive, and Deezer APIs
 * for music media metadata scraping. Searches by barcode (EAN/UPC) with
 * MusicBrainz as fallback, and enriches with Deezer HD covers.
 *
 * @see https://www.discogs.com/developers
 * @see https://musicbrainz.org/doc/MusicBrainz_API
 * @see https://developers.deezer.com/api
 */
class DiscogsPlugin
{
    private const API_BASE = 'https://api.discogs.com';
    private const TIMEOUT = 15;
    /** Discogs REQUIRES a descriptive User-Agent with contact info */
    private const USER_AGENT = 'Pinakes/1.0 +https://github.com/fabiodalez-dev/Pinakes';

    private ?\mysqli $db = null;
    /** @phpstan-ignore-next-line Property kept for PluginManager interface compatibility */
    private ?object $hookManager = null;
    private ?int $pluginId = null;
    private ?\App\Support\PluginManager $pluginManager = null;
    /** @var float Timestamp of last MusicBrainz API request for rate limiting */
    private static float $lastMbRequestTime = 0.0;

    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Activate the plugin and register all hooks
     */
    public function activate(): void
    {
        Hooks::add('scrape.sources', [$this, 'addDiscogsSource'], 8);
        Hooks::add('scrape.fetch.custom', [$this, 'fetchFromDiscogs'], 8);
        Hooks::add('scrape.data.modify', [$this, 'enrichWithDiscogsData'], 15);
    }

    /**
     * Called when plugin is installed via PluginManager
     */
    public function onInstall(): void
    {
        \App\Support\SecureLogger::debug('[Discogs] Plugin installed');
        $this->registerHooks();
    }

    /**
     * Called when plugin is activated via PluginManager
     */
    public function onActivate(): void
    {
        $this->registerHooks();
        \App\Support\SecureLogger::debug('[Discogs] Plugin activated');
    }

    /**
     * Called when plugin is deactivated via PluginManager
     */
    public function onDeactivate(): void
    {
        $this->deleteHooks();
        \App\Support\SecureLogger::debug('[Discogs] Plugin deactivated');
    }

    /**
     * Called when plugin is uninstalled via PluginManager
     */
    public function onUninstall(): void
    {
        $this->deleteHooks();
        \App\Support\SecureLogger::debug('[Discogs] Plugin uninstalled');
    }

    /**
     * Set the plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->ensureHooksRegistered();
    }

    /**
     * Register hooks in the database for persistence
     */
    private function registerHooks(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            \App\Support\SecureLogger::warning('[Discogs] Cannot register hooks: missing DB or plugin ID');
            return;
        }

        $hooks = [
            ['scrape.sources', 'addDiscogsSource', 8],
            ['scrape.fetch.custom', 'fetchFromDiscogs', 8],
            ['scrape.data.modify', 'enrichWithDiscogsData', 15],
        ];

        $stmt = null;
        try {
            $this->db->begin_transaction();

            $deleteStmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
            if ($deleteStmt === false) {
                throw new \RuntimeException('[Discogs] Failed to prepare hook cleanup: ' . $this->db->error);
            }
            $deleteStmt->bind_param('i', $this->pluginId);
            if (!$deleteStmt->execute()) {
                throw new \RuntimeException('[Discogs] Failed to delete existing hooks: ' . $deleteStmt->error);
            }
            $deleteStmt->close();

            foreach ($hooks as [$hookName, $method, $priority]) {
                $stmt = $this->db->prepare(
                    "INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, NOW())"
                );

                if ($stmt === false) {
                    throw new \RuntimeException('[Discogs] Failed to prepare statement: ' . $this->db->error);
                }

                $callbackClass = 'DiscogsPlugin';
                $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);

                if (!$stmt->execute()) {
                    throw new \RuntimeException("[Discogs] Failed to register hook {$hookName}: " . $stmt->error);
                }

                $stmt->close();
                $stmt = null;
            }

            $this->db->commit();
            \App\Support\SecureLogger::debug('[Discogs] Hooks registered');
        } catch (\Throwable $e) {
            if ($stmt instanceof \mysqli_stmt) {
                $stmt->close();
            }
            try {
                $this->db->rollback();
            } catch (\Throwable) {
            }
            \App\Support\SecureLogger::error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Ensure hooks are registered in the database
     */
    private function ensureHooksRegistered(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('i', $this->pluginId);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ((int)($row['total'] ?? 0) === 0) {
                $this->registerHooks();
            }
        }

        $stmt->close();
    }

    /**
     * Delete all hooks for this plugin
     */
    private function deleteHooks(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $this->pluginId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ─── Scraping Hooks ─────────────────────────────────────────────────

    /**
     * Add Discogs as a scraping source
     *
     * @param array $sources Existing sources
     * @param string $isbn ISBN/EAN being scraped
     * @return array Modified sources
     */
    public function addDiscogsSource(array $sources, string $isbn): array
    {
        $sources['discogs'] = [
            'name' => 'Discogs',
            'url_pattern' => self::API_BASE . '/database/search?barcode={isbn}&type=release',
            'enabled' => true,
            'priority' => 8,
            'fields' => ['title', 'authors', 'publisher', 'year', 'description', 'image', 'format'],
        ];

        return $sources;
    }

    /**
     * Fetch music metadata from Discogs API
     *
     * Search strategy:
     *   1. Barcode search (EAN/UPC)
     *   2. Query search as fallback
     *   3. Fetch full release details
     *
     * @param mixed $currentResult Previous accumulated result from other plugins
     * @param array $sources Available sources
     * @param string $isbn ISBN/EAN/barcode to search
     * @return array|null Merged data or previous result
     */
    public function fetchFromDiscogs($currentResult, array $sources, string $isbn): ?array
    {
        // Only proceed if Discogs source is enabled
        if (!isset($sources['discogs']) || !$sources['discogs']['enabled']) {
            return $currentResult;
        }

        // Don't skip — always try to merge Discogs data for additional fields
        // BookDataMerger::merge() only fills missing fields, so it's safe

        try {
            $token = $this->getSetting('api_token');

            // Search by barcode only (no generic fallback — too unreliable)
            $searchUrl = self::API_BASE . '/database/search?barcode=' . urlencode($isbn) . '&type=release';
            $searchResult = $this->apiRequest($searchUrl, $token);

            if (empty($searchResult['results'][0])) {
                // Title/artist fallback only works when a previous scraper already
                // provided partial data (title + artist).  When $currentResult is
                // null (first scraper in the chain), there is nothing to search for.
                if ($currentResult !== null && is_array($currentResult) && !empty($currentResult['title'])) {
                    $discogsFallback = $this->searchDiscogsByTitleArtist($currentResult, $token);
                    if ($discogsFallback !== null) {
                        return $this->mergeBookData($currentResult, $discogsFallback);
                    }
                }

                // Discogs found nothing — try MusicBrainz as fallback
                $mbResult = $this->searchMusicBrainz($isbn, $token, $isbn);
                if ($mbResult !== null) {
                    return $this->mergeBookData($currentResult, $mbResult);
                }
                return $currentResult;
            }

            $discogsData = $this->fetchDiscogsReleaseFromSearchResult($searchResult['results'][0], $token, $isbn);
            if ($discogsData === null) {
                return $currentResult;
            }

            // Merge with existing data
            return $this->mergeBookData($currentResult, $discogsData);

        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Discogs] Plugin Error: ' . $e->getMessage());
            return $currentResult;
        }
    }

    private function searchDiscogsByTitleArtist($currentResult, ?string $token): ?array
    {
        if (!is_array($currentResult)) {
            return null;
        }

        $resolvedTipoMedia = \App\Support\MediaLabels::resolveTipoMedia(
            $currentResult['format'] ?? $currentResult['formato'] ?? null,
            $currentResult['tipo_media'] ?? null
        );
        if ($resolvedTipoMedia !== 'disco') {
            return null;
        }

        $title = trim((string) ($currentResult['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $artist = trim((string) ($currentResult['author'] ?? ''));
        if ($artist === '' && !empty($currentResult['authors'])) {
            if (is_array($currentResult['authors'])) {
                $artist = trim((string) ($currentResult['authors'][0] ?? ''));
            } else {
                $artist = trim((string) $currentResult['authors']);
            }
        }

        $query = [
            'type=release',
            'release_title=' . urlencode($title),
        ];
        if ($artist !== '') {
            $query[] = 'artist=' . urlencode($artist);
        }

        $searchUrl = self::API_BASE . '/database/search?' . implode('&', $query);
        $searchResult = $this->apiRequest($searchUrl, $token);
        if (empty($searchResult['results'][0])) {
            return null;
        }

        return $this->fetchDiscogsReleaseFromSearchResult($searchResult['results'][0], $token, null);
    }

    private function fetchDiscogsReleaseFromSearchResult(array $searchResult, ?string $token, ?string $fallbackBarcode): ?array
    {
        $releaseId = $searchResult['id'] ?? null;
        if ($releaseId === null) {
            return null;
        }

        $releaseUrl = self::API_BASE . '/releases/' . $releaseId;
        $release = $this->apiRequest($releaseUrl, $token);

        if (empty($release) || empty($release['title'])) {
            return null;
        }

        return $this->mapReleaseToPinakes($release, $searchResult, $fallbackBarcode);
    }

    /**
     * Enrich existing data with Discogs cover if missing
     *
     * @param array $data Current payload
     * @param string $isbn ISBN/EAN
     * @param array $source Source information
     * @param array $originalPayload Original payload before modifications
     * @return array Enriched payload
     */
    public function enrichWithDiscogsData(array $data, string $isbn, array $source, array $originalPayload): array
    {
        // If data already has an image, skip
        if (!empty($data['image'])) {
            return $data;
        }

        // Only enrich from Deezer for music sources (avoid attaching music covers to books)
        $resolvedType = \App\Support\MediaLabels::inferTipoMedia($data['format'] ?? $data['formato'] ?? '');
        $isMusicSource = ($data['tipo_media'] ?? '') === 'disco'
            || $resolvedType === 'disco'
            || ($data['source'] ?? '') === 'discogs'
            || ($data['source'] ?? '') === 'musicbrainz';

        // Try to fetch cover from Discogs using discogs_id (regardless of source)
        $discogsId = $data['discogs_id'] ?? null;
        if ($discogsId === null) {
            // No discogs_id — skip to Deezer enrichment below (only for music)
            if ($isMusicSource && (empty($data['image']) || empty($data['genres'])) && !empty($data['title'])) {
                $data = $this->enrichFromDeezer($data);
            }
            return $data;
        }

        try {
            $token = $this->getSetting('api_token');
            $releaseUrl = self::API_BASE . '/releases/' . (int)$discogsId;
            $release = $this->apiRequest($releaseUrl, $token);

            if (!empty($release['images'][0]['uri'])) {
                $data['image'] = $release['images'][0]['uri'];
                $data['cover_url'] = $release['images'][0]['uri'];
            } elseif (!empty($release['thumb'])) {
                $data['image'] = $release['thumb'];
                $data['cover_url'] = $release['thumb'];
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[Discogs] Cover enrichment error: ' . $e->getMessage());
        }

        // If still missing cover or genre, try Deezer enrichment (only for music)
        if ($isMusicSource && (empty($data['image']) || empty($data['genres'])) && !empty($data['title'])) {
            $data = $this->enrichFromDeezer($data);
        }

        return $data;
    }

    // ─── Data Mapping ───────────────────────────────────────────────────

    /**
     * Map a Discogs release to Pinakes book data format
     *
     * @param array $release Full release data from /releases/{id}
     * @param array $searchResult Search result entry (has thumb/cover_image)
     * @param string|null $fallbackBarcode Original barcode/EAN used for a validated barcode search
     * @return array Pinakes-formatted data
     */
    private function mapReleaseToPinakes(array $release, array $searchResult, ?string $fallbackBarcode): array
    {
        // Extract album title — Discogs format is "Artist - Album Title"
        $title = $this->extractAlbumTitle($release['title'] ?? '');

        // Extract artists
        $artists = $this->extractArtists($release['artists'] ?? []);
        $firstArtist = $artists[0] ?? '';

        // Build tracklist description as HTML <ol>
        $description = $this->buildTracklistDescription($release['tracklist'] ?? []);

        // Get cover image: prefer full images (requires auth), fallback to search thumbnails
        $coverUrl = null;
        if (!empty($release['images'][0]['uri'])) {
            $coverUrl = $release['images'][0]['uri'];
        } elseif (!empty($searchResult['cover_image'])) {
            $coverUrl = $searchResult['cover_image'];
        } elseif (!empty($searchResult['thumb'])) {
            $coverUrl = $searchResult['thumb'];
        }

        // Extract publisher (label + catalog number)
        $publisher = '';
        $catalogNumber = '';
        if (!empty($release['labels'][0]['name'])) {
            $publisher = trim($release['labels'][0]['name']);
            $catalogNumber = trim($release['labels'][0]['catno'] ?? '');
        }

        // Extract series
        $series = null;
        if (!empty($release['series'][0]['name'])) {
            $series = trim($release['series'][0]['name']);
        }

        // Map Discogs format to Pinakes format
        $format = $this->mapDiscogsFormat($release['formats'] ?? []);

        // Extract all genres + styles as keywords
        $allGenres = [];
        foreach ($release['genres'] ?? [] as $g) {
            $g = trim((string) $g);
            if ($g !== '') {
                $allGenres[] = $g;
            }
        }
        foreach ($release['styles'] ?? [] as $style) {
            $s = trim((string) $style);
            if ($s !== '' && !in_array($s, $allGenres, true)) {
                $allGenres[] = $s;
            }
        }
        $genre = $allGenres[0] ?? '';
        $keywords = implode(', ', $allGenres);

        // Year
        $year = isset($release['year']) && $release['year'] > 0
            ? (string) $release['year']
            : null;

        // Weight in kg (Discogs gives grams)
        $weightKg = null;
        if (!empty($release['estimated_weight']) && is_numeric($release['estimated_weight'])) {
            $weightKg = round((float) $release['estimated_weight'] / 1000, 3);
        }

        // Price
        $price = null;
        if (!empty($release['lowest_price']) && is_numeric($release['lowest_price'])) {
            $price = (string) $release['lowest_price'];
        }

        // Number of tracks
        $trackCount = 0;
        foreach ($release['tracklist'] ?? [] as $track) {
            if (($track['type_'] ?? 'track') === 'track' && trim($track['title'] ?? '') !== '') {
                $trackCount++;
            }
        }

        // Format quantity (number of discs)
        $formatQty = (int) ($release['format_quantity'] ?? 1);

        // Notes from Discogs
        $discogsNotes = trim($release['notes'] ?? '');

        // Build note_varie with extra metadata
        $noteParts = [];
        if ($catalogNumber !== '') {
            $noteParts[] = 'Cat#: ' . $catalogNumber;
        }
        if (!empty($release['country'])) {
            $noteParts[] = 'Country: ' . $release['country'];
        }
        if ($formatQty > 1) {
            $noteParts[] = $formatQty . ' discs';
        }
        // Extra artists (producers, engineers, etc.)
        $credits = $this->extractCredits($release['extraartists'] ?? []);
        if ($credits !== '') {
            $noteParts[] = $credits;
        }
        if ($discogsNotes !== '') {
            $noteParts[] = $discogsNotes;
        }
        $noteVarie = implode("\n", $noteParts);

        // Discogs URL for sameAs
        $discogsUrl = $release['uri'] ?? null;

        // Physical description (format details)
        $physicalDesc = '';
        if (!empty($release['formats'][0])) {
            $fmt = $release['formats'][0];
            $parts = [$fmt['name'] ?? ''];
            foreach ($fmt['descriptions'] ?? [] as $desc) {
                $parts[] = $desc;
            }
            $physicalDesc = implode(', ', array_filter($parts));
        }

        $releaseBarcode = $fallbackBarcode ?? $this->extractBarcodeFromRelease($release, $searchResult);

        return [
            'title' => $title,
            'author' => $firstArtist,
            'authors' => $artists,
            'description' => $description,
            'image' => $coverUrl,
            'cover_url' => $coverUrl,
            'year' => $year,
            'publisher' => $publisher,
            'series' => $series ?? '',
            'format' => $format,
            'genres' => $genre,
            'parole_chiave' => $keywords,
            'isbn10' => null,
            'isbn13' => null,
            'ean' => $releaseBarcode,
            'country' => $release['country'] ?? null,
            'tipo_media' => 'disco',
            'source' => 'discogs',
            'discogs_id' => $release['id'] ?? null,
            'peso' => $weightKg,
            'prezzo' => $price,
            'numero_pagine' => $trackCount > 0 ? (string) $trackCount : null,
            'note_varie' => $noteVarie !== '' ? $noteVarie : null,
            'physical_description' => $physicalDesc !== '' ? $physicalDesc : null,
            'numero_inventario' => $catalogNumber !== '' ? $catalogNumber : null,
            'discogs_url' => $discogsUrl,
        ];
    }

    /**
     * Extract album title from Discogs "Artist - Album" format
     *
     * Discogs returns titles like "Pink Floyd - The Dark Side Of The Moon".
     * We want just the album part: "The Dark Side Of The Moon".
     *
     * @param string $fullTitle Full Discogs title
     * @return string Album title only
     */
    private function extractAlbumTitle(string $fullTitle): string
    {
        $fullTitle = trim($fullTitle);
        if ($fullTitle === '') {
            return '';
        }

        // Split on " - " (with spaces) to separate artist from album
        $parts = explode(' - ', $fullTitle, 2);
        if (count($parts) === 2) {
            $albumPart = trim($parts[1]);
            if ($albumPart !== '') {
                return $albumPart;
            }
        }

        // If no separator found or album part is empty, return full title
        return $fullTitle;
    }

    /**
     * Extract artist names from Discogs artists array
     *
     * @param array $artists Discogs artists array
     * @return array Artist name strings
     */
    private function extractArtists(array $artists): array
    {
        $names = [];
        foreach ($artists as $artist) {
            $name = trim($artist['name'] ?? '');
            if ($name !== '') {
                // Discogs appends " (2)" etc. for disambiguation — remove it
                $name = (string)preg_replace('/\s*\(\d+\)$/', '', $name);
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Extract credits from Discogs extraartists (producers, engineers, etc.)
     */
    private function extractCredits(array $extraartists): string
    {
        if (empty($extraartists)) {
            return '';
        }
        $credits = [];
        foreach ($extraartists as $person) {
            $name = trim($person['name'] ?? '');
            $role = trim($person['role'] ?? '');
            if ($name === '' || $role === '') {
                continue;
            }
            // Clean disambiguation suffix
            $name = (string) preg_replace('/\s*\(\d+\)$/', '', $name);
            $credits[] = $role . ': ' . $name;
        }
        if (empty($credits)) {
            return '';
        }
        return 'Credits: ' . implode(', ', $credits);
    }

    /**
     * Build a tracklist description from Discogs tracklist data
     *
     * Produces text like:
     *   Tracklist:
     *   1. Speak to Me (1:30)
     *   2. Breathe (2:43)
     *
     * @param array $tracklist Discogs tracklist array
     * @return string Formatted tracklist
     */
    private function buildTracklistDescription(array $tracklist): string
    {
        if (empty($tracklist)) {
            return '';
        }

        $items = [];
        foreach ($tracklist as $track) {
            $trackTitle = trim($track['title'] ?? '');
            if ($trackTitle === '') {
                continue;
            }
            $duration = trim($track['duration'] ?? '');
            $text = htmlspecialchars($trackTitle, ENT_QUOTES, 'UTF-8');
            if ($duration !== '') {
                $text .= ' <span class="text-gray-400">(' . htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') . ')</span>';
            }
            $items[] = $text;
        }

        if (empty($items)) {
            return '';
        }

        return '<ol class="tracklist">' . implode('', array_map(static fn(string $item): string => '<li>' . $item . '</li>', $items)) . '</ol>';
    }

    /**
     * Map Discogs format names to Pinakes format identifiers
     *
     * @param array $formats Discogs formats array
     * @return string Pinakes format string
     */
    private function mapDiscogsFormat(array $formats): string
    {
        if (empty($formats[0]['name'])) {
            return 'altro';
        }

        $discogsFormat = strtolower(trim($formats[0]['name']));

        $formatMap = [
            'cd'            => 'cd_audio',
            'cdr'           => 'cd_audio',
            'cds'           => 'cd_audio',
            'sacd'          => 'cd_audio',
            'vinyl'         => 'vinile',
            'lp'            => 'vinile',
            'cassette'      => 'audiocassetta',
            'dvd'           => 'dvd',
            'blu-ray'       => 'blu_ray',
            'file'          => 'digitale',
            'all media'     => 'altro',
        ];

        foreach ($formatMap as $discogsKey => $pinakesValue) {
            if (str_contains($discogsFormat, $discogsKey)) {
                return $pinakesValue;
            }
        }

        return 'altro';
    }

    // ─── API Communication ──────────────────────────────────────────────

    /**
     * Make an authenticated request to the Discogs API
     *
     * Discogs requires:
     *  - A descriptive User-Agent header (mandatory)
     *  - Optional: Authorization token for higher rate limits (60/min vs 25/min)
     *
     * @param string $url Full API URL
     * @param string|null $token Optional Discogs personal access token
     * @return array|null Decoded JSON response or null on failure
     */
    /** @var float Timestamp of last API request for rate limiting */
    private float $lastRequestTime = 0.0;

    private function apiRequest(string $url, ?string $token = null): ?array
    {
        // Centralized rate limiting: 1s with token (60 req/min), 2.5s without (25 req/min)
        $minInterval = ($token !== null && $token !== '') ? 1.0 : 2.5;
        $elapsed = microtime(true) - $this->lastRequestTime;
        if ($this->lastRequestTime > 0 && $elapsed < $minInterval) {
            usleep((int) (($minInterval - $elapsed) * 1_000_000));
        }
        $this->lastRequestTime = microtime(true);

        $headers = [
            'Accept: application/vnd.discogs.v2.discogs+json',
        ];

        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Discogs token=' . $token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            \App\Support\SecureLogger::warning('[Discogs] cURL error: ' . $curlError);
            return null;
        }

        if ($httpCode !== 200 || !is_string($response) || $response === '') {
            if ($httpCode === 429) {
                \App\Support\SecureLogger::warning('[Discogs] Rate limit exceeded (HTTP 429)');
            }
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    // ─── Settings ───────────────────────────────────────────────────────

    /**
     * Read a plugin setting from plugin_settings table
     *
     * Settings are stored with the plugin's ID in the plugin_settings table,
     * following the same pattern as OpenLibraryPlugin.
     *
     * @param string $key Setting key (e.g. 'api_token')
     * @return string|null Setting value or null
     */
    private function getSetting(string $key): ?string
    {
        $pluginId = $this->resolvePluginId();
        $manager = $this->getPluginManager();

        if ($pluginId === null || $manager === null) {
            return null;
        }

        $value = $manager->getSetting($pluginId, $key);
        return is_string($value) ? $value : null;
    }

    /**
     * Get public settings info (for admin UI)
     *
     * @return array Settings map
     */
    public function getSettings(): array
    {
        $token = $this->getSetting('api_token');
        return [
            'api_token' => $token !== null && $token !== '' ? '********' : '',
        ];
    }

    /**
     * Save plugin settings to plugin_settings table
     *
     * @param array<string, mixed> $settings Settings key-value pairs
     * @return bool True if all settings were saved successfully
     */
    public function saveSettings(array $settings): bool
    {
        $pluginId = $this->resolvePluginId();
        $manager = $this->getPluginManager();

        if ($pluginId === null || $manager === null) {
            return false;
        }

        foreach ($settings as $key => $value) {
            if (!$manager->setSetting($pluginId, (string) $key, $value, true)) {
                return false;
            }
        }

        return true;
    }

    private function resolvePluginId(): ?int
    {
        if ($this->pluginId !== null || $this->db === null) {
            return $this->pluginId;
        }

        $stmt = $this->db->prepare("SELECT id FROM plugins WHERE name = ? LIMIT 1");
        if ($stmt === false) {
            return null;
        }

        $pluginName = 'discogs';
        $stmt->bind_param('s', $pluginName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();

        $this->pluginId = isset($row['id']) ? (int) $row['id'] : null;
        return $this->pluginId;
    }

    private function getPluginManager(): ?\App\Support\PluginManager
    {
        if ($this->pluginManager !== null) {
            return $this->pluginManager;
        }

        if ($this->db === null) {
            return null;
        }

        $hookManager = $this->hookManager instanceof \App\Support\HookManager
            ? $this->hookManager
            : new \App\Support\HookManager($this->db);

        $this->pluginManager = new \App\Support\PluginManager($this->db, $hookManager);
        return $this->pluginManager;
    }

    /**
     * Whether this plugin has a dedicated settings page
     */
    public function hasSettingsPage(): bool
    {
        return true;
    }

    /**
     * Get the path to the settings view file
     */
    public function getSettingsViewPath(): string
    {
        return __DIR__ . '/views/settings.php';
    }

    /**
     * Get plugin info
     *
     * @return array Plugin metadata
     */
    public function getInfo(): array
    {
        return [
            'name' => 'discogs',
            'display_name' => 'Music Scraper (Discogs, MusicBrainz, Deezer)',
            'version' => '1.1.0',
            'description' => 'Scraping multi-sorgente di metadati musicali: Discogs, MusicBrainz + Cover Art Archive, Deezer.',
        ];
    }

    // ─── Data Merge ─────────────────────────────────────────────────────

    /**
     * Merge book data from a new source into existing data
     *
     * @param array|null $existing Existing accumulated data
     * @param array|null $new New data from current source
     * @return array|null Merged data
     */
    private function mergeBookData(?array $existing, ?array $new): ?array
    {
        // Use BookDataMerger if available
        if (class_exists('\\App\\Support\\BookDataMerger')) {
            return \App\Support\BookDataMerger::merge($existing, $new, 'discogs');
        }

        // Fallback: simple merge
        if ($new === null || empty($new)) {
            return $existing;
        }

        if ($existing === null || empty($existing)) {
            return $new;
        }

        // Fill empty fields in existing data with new data
        foreach ($new as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            if (!isset($existing[$key]) || $existing[$key] === '' ||
                (is_array($existing[$key]) && empty($existing[$key]))) {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    // ─── MusicBrainz Integration ────────────────────────────────────────

    /**
     * Search MusicBrainz by barcode as fallback when Discogs finds nothing
     *
     * @param string $barcode EAN/UPC barcode
     * @param string|null $discogsToken Discogs token (unused, kept for signature consistency)
     * @param string|null $fallbackBarcode Persist this barcode only when it was validated by the search path
     * @return array|null Pinakes-formatted data or null if not found
     */
    private function searchMusicBrainz(string $barcode, ?string $discogsToken, ?string $fallbackBarcode): ?array
    {
        // Search by barcode
        $url = 'https://musicbrainz.org/ws/2/release?query=barcode:' . urlencode($barcode) . '&fmt=json&limit=1';
        $result = $this->musicBrainzRequest($url);

        if (empty($result['releases'][0])) {
            return null;
        }

        $release = $result['releases'][0];
        $mbid = $release['id'] ?? null;
        if ($mbid === null || !is_string($mbid)) {
            return null;
        }

        // Fetch full release details
        $detailUrl = 'https://musicbrainz.org/ws/2/release/' . $mbid . '?inc=artists+labels+recordings+release-groups&fmt=json';
        $detail = $this->musicBrainzRequest($detailUrl);
        if (empty($detail)) {
            return null;
        }

        // Get cover from Cover Art Archive
        $coverUrl = $this->fetchCoverArtArchive($mbid);

        return $this->mapMusicBrainzToPinakes($detail, $fallbackBarcode, $coverUrl);
    }

    /**
     * Map MusicBrainz release data to Pinakes book data format
     *
     * @param array $release Full release data from MusicBrainz
     * @param string|null $fallbackBarcode Original barcode used for search
     * @param string|null $coverUrl Cover URL from Cover Art Archive
     * @return array Pinakes-formatted data
     */
    private function mapMusicBrainzToPinakes(array $release, ?string $fallbackBarcode, ?string $coverUrl): array
    {
        $title = trim($release['title'] ?? '');

        // Extract artists from artist-credit array
        $artists = [];
        $firstArtist = '';
        if (!empty($release['artist-credit']) && is_array($release['artist-credit'])) {
            foreach ($release['artist-credit'] as $credit) {
                $name = trim($credit['name'] ?? '');
                if ($name !== '') {
                    $artists[] = $name;
                }
            }
            $firstArtist = $artists[0] ?? '';
        }

        // Build tracklist HTML from media/tracks
        $description = '';
        if (!empty($release['media'][0]['tracks']) && is_array($release['media'][0]['tracks'])) {
            $items = [];
            foreach ($release['media'][0]['tracks'] as $track) {
                $trackTitle = trim($track['title'] ?? '');
                if ($trackTitle === '') {
                    continue;
                }
                $text = htmlspecialchars($trackTitle, ENT_QUOTES, 'UTF-8');
                // Length is in milliseconds
                $lengthMs = $track['length'] ?? null;
                if ($lengthMs !== null && is_numeric($lengthMs) && (int)$lengthMs > 0) {
                    $totalSeconds = (int)round((int)$lengthMs / 1000);
                    $minutes = intdiv($totalSeconds, 60);
                    $seconds = $totalSeconds % 60;
                    $duration = $minutes . ':' . str_pad((string)$seconds, 2, '0', STR_PAD_LEFT);
                    $text .= ' <span class="text-gray-400">(' . $duration . ')</span>';
                }
                $items[] = $text;
            }
            if (!empty($items)) {
                $description = '<ol class="tracklist">' . implode('', array_map(
                    static fn(string $item): string => '<li>' . $item . '</li>',
                    $items
                )) . '</ol>';
            }
        }

        // Year: first 4 chars of date
        $year = null;
        $date = $release['date'] ?? '';
        if (is_string($date) && strlen($date) >= 4) {
            $year = substr($date, 0, 4);
        }

        // Publisher: first label
        $publisher = '';
        if (!empty($release['label-info'][0]['label']['name'])) {
            $publisher = trim((string)$release['label-info'][0]['label']['name']);
        }

        // Format mapping
        $format = 'altro';
        if (!empty($release['media'][0]['format'])) {
            $mbFormat = strtolower(trim((string)$release['media'][0]['format']));
            $formatMap = [
                'cd'             => 'cd_audio',
                'vinyl'          => 'vinile',
                'cassette'       => 'audiocassetta',
                'digital media'  => 'digitale',
                'dvd'            => 'dvd',
                'blu-ray'        => 'blu_ray',
            ];
            foreach ($formatMap as $key => $value) {
                if (str_contains($mbFormat, $key)) {
                    $format = $value;
                    break;
                }
            }
        }

        // Track count
        $trackCount = 0;
        if (!empty($release['media'][0]['tracks']) && is_array($release['media'][0]['tracks'])) {
            $trackCount = count($release['media'][0]['tracks']);
        }

        $releaseBarcode = $fallbackBarcode ?? $this->extractBarcodeFromRelease($release);

        return [
            'title' => $title,
            'author' => $firstArtist,
            'authors' => $artists,
            'description' => $description,
            'image' => $coverUrl,
            'cover_url' => $coverUrl,
            'year' => $year,
            'publisher' => $publisher,
            'series' => '',
            'format' => $format,
            'genres' => '',
            'parole_chiave' => '',
            'isbn10' => null,
            'isbn13' => null,
            'ean' => $releaseBarcode,
            'country' => $release['country'] ?? null,
            'tipo_media' => 'disco',
            'source' => 'musicbrainz',
            'musicbrainz_id' => $release['id'] ?? null,
            'numero_pagine' => $trackCount > 0 ? (string)$trackCount : null,
        ];
    }

    private function extractBarcodeFromRelease(array $release, array $searchResult = []): ?string
    {
        $candidates = [];

        $this->appendBarcodeCandidates($candidates, $release['barcode'] ?? null);
        $this->appendBarcodeCandidates($candidates, $searchResult['barcode'] ?? null);
        foreach ($release['identifiers'] ?? [] as $identifier) {
            if (!is_array($identifier)) {
                continue;
            }
            $type = strtolower(trim((string) ($identifier['type'] ?? '')));
            if ($type !== '' && !str_contains($type, 'barcode')) {
                continue;
            }
            $candidates[] = (string) ($identifier['value'] ?? '');
        }

        foreach ($candidates as $candidate) {
            $normalized = preg_replace('/\D+/', '', $candidate) ?? '';
            if ($normalized !== '' && (strlen($normalized) === 12 || strlen($normalized) === 13)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $candidates
     * @param mixed $value
     */
    private function appendBarcodeCandidates(array &$candidates, $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $this->appendBarcodeCandidates($candidates, $nestedValue);
            }
            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        $candidates[] = (string) $value;
    }

    /**
     * Fetch cover art URL from the Cover Art Archive
     *
     * @param string $mbid MusicBrainz release ID
     * @return string|null URL of the cover image or null if unavailable
     */
    private function fetchCoverArtArchive(string $mbid): ?string
    {
        // Cover Art Archive — no rate limit, but may 404
        $url = 'https://coverartarchive.org/release/' . urlencode($mbid);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($resp)) {
            return null;
        }

        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['images']) || !is_array($data['images'])) {
            return null;
        }

        // Prefer front cover, then first image
        foreach ($data['images'] as $img) {
            if (!is_array($img)) {
                continue;
            }
            if (($img['front'] ?? false) === true) {
                return $img['thumbnails']['large'] ?? $img['image'] ?? null;
            }
        }

        $firstImg = $data['images'][0];
        if (is_array($firstImg)) {
            return $firstImg['thumbnails']['large'] ?? $firstImg['image'] ?? null;
        }

        return null;
    }

    /**
     * Make a rate-limited request to the MusicBrainz API
     *
     * MusicBrainz enforces a strict 1 request/second limit.
     * We use 1.1s between requests for safety margin.
     *
     * @param string $url Full MusicBrainz API URL
     * @return array|null Decoded JSON response or null on failure
     */
    private function musicBrainzRequest(string $url): ?array
    {
        // MusicBrainz requires 1 req/s strictly
        $elapsed = microtime(true) - self::$lastMbRequestTime;
        if (self::$lastMbRequestTime > 0 && $elapsed < 1.1) {
            usleep((int)((1.1 - $elapsed) * 1_000_000));
        }
        self::$lastMbRequestTime = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($resp)) {
            return null;
        }

        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }

    // ─── Deezer Integration ─────────────────────────────────────────────

    /**
     * Enrich data with Deezer album cover and metadata
     *
     * Searches Deezer by title+artist to find a matching album,
     * then fills in missing cover image.
     *
     * @param array $data Current Pinakes data (must have 'title')
     * @return array Enriched data
     */
    private function enrichFromDeezer(array $data): array
    {
        $title = trim($data['title'] ?? '');
        $artist = trim($data['author'] ?? '');
        if ($title === '') {
            return $data;
        }

        $query = $artist !== '' ? $artist . ' ' . $title : $title;
        $url = 'https://api.deezer.com/search/album?q=' . urlencode($query) . '&limit=1';

        // Simple rate limit — 1 second between Deezer requests
        usleep(1_000_000);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($resp)) {
            return $data;
        }

        $result = json_decode($resp, true);
        if (!is_array($result) || empty($result['data'][0])) {
            return $data;
        }

        $album = $result['data'][0];
        if (!is_array($album)) {
            return $data;
        }

        // Fill missing cover with Deezer's high-quality image
        if (empty($data['image']) && !empty($album['cover_xl'])) {
            $data['image'] = $album['cover_xl'];
            $data['cover_url'] = $album['cover_xl'];
        }

        return $data;
    }
}
