<?php
/**
 * API Book Scraper Plugin
 *
 * Plugin per lo scraping di dati libri tramite API esterna personalizzabile.
 * Supporta autenticazione API key e ha priorità più alta di Open Library.
 *
 * @author Pinakes Team
 * @version 1.0.0
 */

use App\Support\Hooks;

class ApiBookScraperPlugin
{
    private const PLUGIN_NAME = 'api-book-scraper';

    private ?\mysqli $db = null;
    private ?object $hookManager = null;
    private ?int $pluginId = null;

    // Default settings
    private string $apiEndpoint = '';
    private string $apiKey = '';
    private int $timeout = 10;
    private bool $enabled = false;

    /**
     * Costruttore del plugin
     */
    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;

        // Auto-load plugin ID from database if not set
        // This handles the case when HookManager instantiates the plugin
        if ($this->db && !$this->pluginId) {
            $this->autoLoadPluginId();
        }
    }

    /**
     * Auto-load plugin ID from database by plugin name
     */
    private function autoLoadPluginId(): void
    {
        if (!$this->db) {
            return;
        }

        $stmt = $this->db->prepare("SELECT id FROM plugins WHERE name = ? AND is_active = 1 LIMIT 1");
        if (!$stmt) {
            error_log('[ApiBookScraperPlugin] Failed to prepare statement for autoLoadPluginId: ' . $this->db->error);
            return;
        }

        $pluginName = self::PLUGIN_NAME;
        $stmt->bind_param('s', $pluginName);

        if (!$stmt->execute()) {
            error_log('[ApiBookScraperPlugin] Failed to execute autoLoadPluginId query: ' . $stmt->error);
            $stmt->close();
            return;
        }

        $result = $stmt->get_result();
        if (!$result) {
            error_log('[ApiBookScraperPlugin] Failed to get result in autoLoadPluginId: ' . $stmt->error);
            $stmt->close();
            return;
        }

        if ($row = $result->fetch_assoc()) {
            $this->pluginId = (int)$row['id'];
            $this->loadSettings();
        }

        $stmt->close();
    }

    /**
     * Set the plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->loadSettings();
    }

    /**
     * Called when plugin is installed via PluginManager
     */
    public function onInstall(): void
    {
        error_log('[ApiBookScraper] Plugin installed');
        if ($this->pluginId) {
            $this->registerHooks();
        }
    }

    /**
     * Called when plugin is activated via PluginManager
     */
    public function onActivate(): void
    {
        $this->loadSettings();
        $this->registerHooks();
        error_log('[ApiBookScraper] Plugin activated');
    }

    /**
     * Called when plugin is deactivated
     */
    public function onDeactivate(): void
    {
        $this->deleteHooks();
        error_log('[ApiBookScraper] Plugin deactivated');
    }

    /**
     * Called when plugin is uninstalled
     */
    public function onUninstall(): void
    {
        $this->deleteHooks();
        // Opzionale: rimuovi anche le settings
        error_log('[ApiBookScraper] Plugin uninstalled');
    }

    /**
     * Register hooks in the database for persistence
     */
    private function registerHooks(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            error_log('[ApiBookScraper] Cannot register hooks: missing DB or plugin ID');
            return;
        }

        // Se il plugin non è abilitato, non registrare gli hooks
        if (!$this->enabled || empty($this->apiEndpoint) || empty($this->apiKey)) {
            error_log('[ApiBookScraper] Plugin not enabled or missing configuration');
            return;
        }

        // Priority 2 = second highest (after Scraping Pro)
        // API Book Scraper often has high-res covers from retail sources
        $hooks = [
            ['scrape.sources', 'addApiSource', 2],
            ['scrape.fetch.custom', 'fetchFromApi', 2],
            ['scrape.isbn.validate', 'validateIsbn', 2],
        ];

        // Delete existing hooks for this plugin
        $this->deleteHooks();

        foreach ($hooks as [$hookName, $method, $priority]) {
            $stmt = $this->db->prepare(
                "INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())"
            );

            if (!$stmt) {
                error_log('[ApiBookScraper] Failed to prepare statement: ' . $this->db->error);
                continue;
            }

            $className = __CLASS__;
            $stmt->bind_param('isssi', $this->pluginId, $hookName, $className, $method, $priority);

            if (!$stmt->execute()) {
                error_log('[ApiBookScraper] Failed to register hook ' . $hookName . ': ' . $stmt->error);
            }

            $stmt->close();
        }

        error_log('[ApiBookScraper] Hooks registered successfully');
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

    /**
     * Carica le impostazioni del plugin
     */
    private function loadSettings(): void
    {
        if (!$this->pluginId || !$this->db) {
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT setting_key, setting_value FROM plugin_settings WHERE plugin_id = ?"
        );

        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $key = $row['setting_key'];
            $value = $this->decryptIfNeeded($row['setting_value']);

            switch ($key) {
                case 'api_endpoint':
                    $this->apiEndpoint = $value;
                    break;
                case 'api_key':
                    $this->apiKey = $value;
                    break;
                case 'timeout':
                    $this->timeout = (int)$value;
                    break;
                case 'enabled':
                    $this->enabled = (bool)$value;
                    break;
            }
        }

        $stmt->close();
    }

    /**
     * Decrypt value if it starts with ENC:
     */
    private function decryptIfNeeded(string $value): string
    {
        if (strpos($value, 'ENC:') !== 0) {
            return $value;
        }

        // Rimuovi prefisso ENC:
        $encrypted = substr($value, 4);

        // Ottieni chiave di crittografia
        $key = getenv('PLUGIN_ENCRYPTION_KEY') ?: getenv('APP_KEY');
        if (!$key) {
            return $value;
        }

        // Decodifica base64
        $decoded = base64_decode($encrypted);
        if ($decoded === false) {
            return $value;
        }

        // Estrai IV, tag e ciphertext
        $ivLength = openssl_cipher_iv_length('aes-256-gcm');
        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, 16);
        $ciphertext = substr($decoded, $ivLength + 16);

        // Decripta
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $decrypted !== false ? $decrypted : $value;
    }

    /**
     * Aggiunge API personalizzata alle sorgenti di scraping
     */
    public function addApiSource(array $sources, string $isbn): array
    {
        if (!$this->enabled || empty($this->apiEndpoint)) {
            return $sources;
        }

        // Aggiunge la sorgente in testa all'array (priorità massima)
        array_unshift($sources, [
            'name' => 'Custom API',
            'endpoint' => $this->apiEndpoint,
            'priority' => 3,
            'enabled' => true
        ]);

        return $sources;
    }

    /**
     * Validazione ISBN personalizzata (opzionale)
     */
    public function validateIsbn(bool $isValid, string $isbn): bool
    {
        // Mantiene la validazione esistente
        return $isValid;
    }

    /**
     * Fetch dati libro da API personalizzata
     *
     * Uses intelligent merging to combine data from custom API with existing data
     * from other sources, filling empty fields without overwriting existing data.
     *
     * @param mixed $existing Previous accumulated result from other plugins
     * @param array $sources List of available sources
     * @param string $isbn ISBN to search for
     * @return array|null Merged book data or previous result if no new data
     */
    public function fetchFromApi($existing, array $sources, string $isbn): ?array
    {
        if (!$this->enabled || empty($this->apiEndpoint) || empty($this->apiKey)) {
            return $existing; // Pass through existing data unchanged
        }

        try {
            $bookData = $this->callApi($isbn);

            if ($bookData) {
                $this->log('info', "Dati recuperati per ISBN: $isbn", ['isbn' => $isbn]);

                // Use BookDataMerger if available, otherwise simple merge
                if (class_exists('\\App\\Support\\BookDataMerger')) {
                    return \App\Support\BookDataMerger::merge($existing, $bookData, 'api-book-scraper');
                }

                // Fallback: simple merge for empty fields only
                if ($existing === null) {
                    return $bookData;
                }

                // Fill empty fields in existing data with API data
                foreach ($bookData as $key => $value) {
                    if (!isset($existing[$key]) || $existing[$key] === '' || $existing[$key] === null) {
                        $existing[$key] = $value;
                    }
                }
                return $existing;
            }

            return $existing; // Pass through existing data unchanged

        } catch (\Exception $e) {
            $this->log('error', "Errore scraping ISBN $isbn: " . $e->getMessage(), [
                'isbn' => $isbn,
                'error' => $e->getMessage()
            ]);

            Hooks::do('scrape.error', [$e, $isbn, 'custom-api']);
            return $existing; // Pass through existing data unchanged
        }
    }

    /**
     * Effettua la chiamata all'API esterna
     */
    private function callApi(string $isbn): ?array
    {
        // Costruisce URL con ISBN
        $url = rtrim($this->apiEndpoint, '/');

        if (strpos($url, '{isbn}') !== false) {
            $url = str_replace('{isbn}', urlencode($isbn), $url);
        } else {
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $url .= $separator . 'isbn=' . urlencode($isbn);
        }

        // Inizializza cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: Pinakes-API-Scraper/1.0'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Errore cURL: $error");
        }

        if ($httpCode !== 200) {
            throw new \Exception("HTTP $httpCode: Errore chiamata API");
        }

        if (empty($response)) {
            throw new \Exception("Risposta API vuota");
        }

        $jsonData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Errore parsing JSON: " . json_last_error_msg());
        }

        return $this->mapApiResponse($jsonData, $isbn);
    }

    /**
     * Mappa la risposta API al formato standard Pinakes
     */
    private function mapApiResponse(array $apiData, string $isbn): ?array
    {
        if (isset($apiData['error']) || (isset($apiData['success']) && !$apiData['success'])) {
            return null;
        }

        $data = $apiData['data'] ?? $apiData;

        $mappedData = [
            'title' => $data['title'] ?? $data['titolo'] ?? null,
            'subtitle' => $data['subtitle'] ?? $data['sottotitolo'] ?? null,
            'authors' => $this->parseAuthors($data),
            'publisher' => $data['publisher'] ?? $data['editore'] ?? null,
            'publish_date' => $data['publish_date'] ?? $data['data_pubblicazione'] ?? null,
            'isbn13' => $data['isbn13'] ?? $data['isbn_13'] ?? $isbn,
            'isbn10' => $data['isbn10'] ?? $data['isbn_10'] ?? null,
            'ean' => $data['ean'] ?? $isbn,
            'pages' => $data['pages'] ?? $data['numero_pagine'] ?? null,
            'language' => $data['language'] ?? $data['lingua'] ?? 'it',
            'description' => $data['description'] ?? $data['descrizione'] ?? null,
            'cover_url' => $data['cover_url'] ?? $data['copertina_url'] ?? $data['image'] ?? null,
            'series' => $data['series'] ?? $data['collana'] ?? null,
            'format' => $data['format'] ?? $data['formato'] ?? null,
            'price' => $data['price'] ?? $data['prezzo'] ?? null,
            'weight' => $data['weight'] ?? $data['peso'] ?? null,
            'dimensions' => $data['dimensions'] ?? $data['dimensioni'] ?? null,
            'genres' => $data['genres'] ?? $data['generi'] ?? [],
            'subjects' => $data['subjects'] ?? $data['argomenti'] ?? [],
            'author_bio' => $data['author_bio'] ?? $data['biografia_autore'] ?? $data['bio'] ?? null,
        ];

        $mappedData = array_filter($mappedData, function($value) {
            return $value !== null && $value !== '' && $value !== [];
        });

        return !empty($mappedData) ? $mappedData : null;
    }

    /**
     * Parse autori dalla risposta API
     */
    private function parseAuthors(array $data): array
    {
        $authors = [];

        if (isset($data['authors']) && is_array($data['authors'])) {
            foreach ($data['authors'] as $author) {
                if (is_string($author)) {
                    $authors[] = ['name' => $author];
                } elseif (is_array($author) && isset($author['name'])) {
                    $authors[] = $author;
                }
            }
        } elseif (isset($data['author']) && is_string($data['author'])) {
            $authors[] = ['name' => $data['author']];
        } elseif (isset($data['autori']) && is_array($data['autori'])) {
            foreach ($data['autori'] as $autore) {
                if (is_string($autore)) {
                    $authors[] = ['name' => $autore];
                }
            }
        } elseif (isset($data['autore']) && is_string($data['autore'])) {
            $authors[] = ['name' => $data['autore']];
        }

        return $authors;
    }

    /**
     * Log eventi plugin
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->pluginId || !$this->db) {
            error_log("[ApiBookScraper] $level: $message");
            return;
        }

        $contextJson = json_encode($context);

        $stmt = $this->db->prepare(
            "INSERT INTO plugin_logs (plugin_id, level, message, context, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );

        if ($stmt) {
            $stmt->bind_param('isss', $this->pluginId, $level, $message, $contextJson);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Salva le impostazioni del plugin
     */
    public function saveSettings(array $settings): bool
    {
        if (!$this->pluginId || !$this->db) {
            return false;
        }

        $success = true;

        foreach ($settings as $key => $value) {
            $shouldEncrypt = ($key === 'api_key');
            $finalValue = $shouldEncrypt ? $this->encryptValue($value) : $value;

            // Delete existing
            $deleteStmt = $this->db->prepare(
                "DELETE FROM plugin_settings WHERE plugin_id = ? AND setting_key = ?"
            );
            if ($deleteStmt) {
                $deleteStmt->bind_param('is', $this->pluginId, $key);
                $deleteStmt->execute();
                $deleteStmt->close();
            }

            // Insert new
            $insertStmt = $this->db->prepare(
                "INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, autoload)
                 VALUES (?, ?, ?, 1)"
            );

            if ($insertStmt) {
                $insertStmt->bind_param('iss', $this->pluginId, $key, $finalValue);
                $success = $success && $insertStmt->execute();
                $insertStmt->close();
            } else {
                $success = false;
            }
        }

        if ($success) {
            $this->loadSettings();
            // Re-register hooks con nuove impostazioni
            $this->registerHooks();
        }

        return $success;
    }

    /**
     * Encrypt value with AES-256-GCM
     */
    private function encryptValue(string $value): string
    {
        $key = getenv('PLUGIN_ENCRYPTION_KEY') ?: getenv('APP_KEY');
        if (!$key) {
            return $value;
        }

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';

        $encrypted = openssl_encrypt(
            $value,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            return $value;
        }

        // Combina IV + tag + ciphertext e codifica in base64
        $combined = $iv . $tag . $encrypted;
        return 'ENC:' . base64_encode($combined);
    }

    /**
     * Ottiene le impostazioni correnti (per la UI)
     */
    public function getSettings(): array
    {
        return [
            'api_endpoint' => $this->apiEndpoint,
            'api_key' => $this->apiKey ? '••••••••' : '',
            'timeout' => $this->timeout,
            'enabled' => $this->enabled
        ];
    }
}
