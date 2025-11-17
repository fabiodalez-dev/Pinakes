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
use App\Support\PluginManager;

class ApiBookScraperPlugin
{
    private $db;
    private $hookManager;
    private $pluginId;
    private $pluginManager;

    // Default settings
    private $apiEndpoint;
    private $apiKey;
    private $timeout = 10;
    private $enabled = false;

    /**
     * Costruttore del plugin
     */
    public function __construct(\mysqli $db, $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
        $this->pluginManager = new PluginManager($db);

        // Recupera ID plugin dal database
        $this->pluginId = $this->getPluginId();

        // Carica impostazioni
        $this->loadSettings();

        // Registra hooks solo se il plugin è abilitato
        if ($this->enabled && !empty($this->apiEndpoint) && !empty($this->apiKey)) {
            $this->registerHooks();
        }
    }

    /**
     * Recupera l'ID del plugin dal database
     */
    private function getPluginId(): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM plugins WHERE name = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $pluginName = 'api-book-scraper';
        $stmt->bind_param('s', $pluginName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    }

    /**
     * Carica le impostazioni del plugin
     */
    private function loadSettings(): void
    {
        if (!$this->pluginId) {
            return;
        }

        $this->apiEndpoint = $this->pluginManager->getSetting($this->pluginId, 'api_endpoint', '');
        $this->apiKey = $this->pluginManager->getSetting($this->pluginId, 'api_key', '');
        $this->timeout = (int)$this->pluginManager->getSetting($this->pluginId, 'timeout', 10);
        $this->enabled = (bool)$this->pluginManager->getSetting($this->pluginId, 'enabled', false);
    }

    /**
     * Registra gli hooks del plugin
     */
    private function registerHooks(): void
    {
        // Hook principale con priorità 3 (più alta di Open Library che usa 5)
        Hooks::add('scrape.fetch.custom', [$this, 'fetchFromApi'], 3);

        // Hook per aggiungere la sorgente
        Hooks::add('scrape.sources', [$this, 'addApiSource'], 3);

        // Hook per validazione ISBN
        Hooks::add('scrape.isbn.validate', [$this, 'validateIsbn'], 3);
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
     * @param mixed $data Dati esistenti (null se nessun dato precedente)
     * @param array $sources Lista sorgenti disponibili
     * @param string $isbn ISBN/EAN del libro
     * @return array|null Dati libro o null
     */
    public function fetchFromApi($data, array $sources, string $isbn): ?array
    {
        // Se già abbiamo dati e vogliamo usare solo questa API come fallback, decommentare:
        // if ($data !== null) {
        //     return $data;
        // }

        if (!$this->enabled || empty($this->apiEndpoint) || empty($this->apiKey)) {
            return $data;
        }

        try {
            $bookData = $this->callApi($isbn);

            if ($bookData) {
                // Log successo
                $this->log('info', "Dati recuperati per ISBN: $isbn", ['isbn' => $isbn]);
                return $bookData;
            }

            return $data;

        } catch (\Exception $e) {
            // Log errore
            $this->log('error', "Errore scraping ISBN $isbn: " . $e->getMessage(), [
                'isbn' => $isbn,
                'error' => $e->getMessage()
            ]);

            // Hook per gestione errori
            Hooks::do('scrape.error', [$e, $isbn, 'custom-api']);

            return $data;
        }
    }

    /**
     * Effettua la chiamata all'API esterna
     *
     * @param string $isbn ISBN/EAN del libro
     * @return array|null Dati del libro
     * @throws \Exception In caso di errore
     */
    private function callApi(string $isbn): ?array
    {
        // Costruisce URL con ISBN
        $url = rtrim($this->apiEndpoint, '/');

        // Supporta placeholder {isbn} nell'URL oppure aggiunge come query param
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

        // Parse JSON response
        $jsonData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Errore parsing JSON: " . json_last_error_msg());
        }

        // Mappa i dati dalla risposta al formato Pinakes
        return $this->mapApiResponse($jsonData, $isbn);
    }

    /**
     * Mappa la risposta API al formato standard Pinakes
     *
     * @param array $apiData Dati dalla API
     * @param string $isbn ISBN originale
     * @return array|null Dati mappati
     */
    private function mapApiResponse(array $apiData, string $isbn): ?array
    {
        // Se la risposta indica errore o nessun risultato
        if (isset($apiData['error']) || isset($apiData['success']) && !$apiData['success']) {
            return null;
        }

        // Se i dati sono annidati in un campo 'data'
        $data = $apiData['data'] ?? $apiData;

        // Mapping campi API -> Pinakes
        // Adatta questi campi in base alla struttura della TUA API
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
        ];

        // Rimuove campi null
        $mappedData = array_filter($mappedData, function($value) {
            return $value !== null && $value !== '' && $value !== [];
        });

        return !empty($mappedData) ? $mappedData : null;
    }

    /**
     * Parse autori dalla risposta API
     *
     * @param array $data Dati API
     * @return array Lista autori
     */
    private function parseAuthors(array $data): array
    {
        $authors = [];

        // Supporta diversi formati autori
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
        if ($this->pluginId) {
            $this->pluginManager->log($this->pluginId, $level, $message, $context);
        }
    }

    /**
     * Salva le impostazioni del plugin
     *
     * @param array $settings Impostazioni da salvare
     * @return bool Successo operazione
     */
    public function saveSettings(array $settings): bool
    {
        if (!$this->pluginId) {
            return false;
        }

        $success = true;

        // Salva endpoint API
        if (isset($settings['api_endpoint'])) {
            $endpoint = filter_var(trim($settings['api_endpoint']), FILTER_SANITIZE_URL);
            $success = $success && $this->pluginManager->setSetting(
                $this->pluginId,
                'api_endpoint',
                $endpoint
            );
        }

        // Salva API key (viene criptata automaticamente da PluginManager)
        if (isset($settings['api_key'])) {
            $success = $success && $this->pluginManager->setSetting(
                $this->pluginId,
                'api_key',
                trim($settings['api_key'])
            );
        }

        // Salva timeout
        if (isset($settings['timeout'])) {
            $timeout = max(5, min(60, (int)$settings['timeout']));
            $success = $success && $this->pluginManager->setSetting(
                $this->pluginId,
                'timeout',
                $timeout
            );
        }

        // Salva stato abilitazione
        if (isset($settings['enabled'])) {
            $success = $success && $this->pluginManager->setSetting(
                $this->pluginId,
                'enabled',
                (bool)$settings['enabled']
            );
        }

        if ($success) {
            // Ricarica impostazioni
            $this->loadSettings();
        }

        return $success;
    }

    /**
     * Ottiene le impostazioni correnti
     */
    public function getSettings(): array
    {
        return [
            'api_endpoint' => $this->apiEndpoint,
            'api_key' => $this->apiKey ? '••••••••' : '', // Maschera API key per sicurezza
            'timeout' => $this->timeout,
            'enabled' => $this->enabled
        ];
    }

    /**
     * Hook chiamato durante l'attivazione del plugin
     */
    public function onActivate(): void
    {
        // Inizializzazione se necessaria
        $this->log('info', 'Plugin API Book Scraper attivato');
    }

    /**
     * Hook chiamato durante la disattivazione del plugin
     */
    public function onDeactivate(): void
    {
        $this->log('info', 'Plugin API Book Scraper disattivato');
    }

    /**
     * Hook chiamato durante la disinstallazione del plugin
     */
    public function onUninstall(): void
    {
        // Pulizia dati se necessaria
        $this->log('info', 'Plugin API Book Scraper disinstallato');
    }
}
