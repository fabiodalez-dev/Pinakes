<?php
/**
 * Book Rating Plugin
 *
 * Questo è un plugin di esempio che dimostra come:
 * - Estendere i dati dei libri
 * - Creare tabelle personalizzate
 * - Integrare API esterne
 * - Registrare hook
 * - Gestire impostazioni
 * - Utilizzare il logging
 *
 * @package BookRatingPlugin
 * @version 1.0.0
 */

declare(strict_types=1);

use App\Support\HookManager;
use App\Support\Hooks;

class BookRatingPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

    /**
     * Costruttore - Inizializzato quando il plugin viene caricato
     *
     * @param mysqli $db Database connection
     * @param HookManager $hookManager Hook manager instance
     */
    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;

        // Ottieni ID del plugin dal database
        $result = $db->query("SELECT id FROM plugins WHERE name = 'book-rating' LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $this->pluginId = (int)$row['id'];
        }
    }

    /**
     * Hook: Eseguito durante l'installazione del plugin
     * Crea le tabelle necessarie e imposta le configurazioni iniziali
     */
    public function onInstall(): void
    {
        // Crea tabella per memorizzare i rating esterni
        $this->db->query("
            CREATE TABLE IF NOT EXISTS book_rating_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                libro_id INT NOT NULL,
                goodreads_rating DECIMAL(3,2) NULL COMMENT 'Rating da Goodreads (0-5)',
                goodreads_ratings_count INT NULL COMMENT 'Numero di valutazioni',
                goodreads_reviews_count INT NULL COMMENT 'Numero di recensioni',
                goodreads_url VARCHAR(255) NULL COMMENT 'URL Goodreads',
                last_sync DATETIME NULL COMMENT 'Ultima sincronizzazione',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (libro_id),
                FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Imposta configurazioni iniziali
        $this->setSetting('goodreads_api_key', '');
        $this->setSetting('auto_sync_enabled', 'true');
        $this->setSetting('sync_interval_hours', '24');
        $this->setSetting('show_on_frontend', 'true');

        // Log installazione
        $this->log('info', 'Plugin installato con successo', [
            'tables_created' => ['book_rating_data'],
            'default_settings' => true
        ]);
    }

    /**
     * Hook: Eseguito quando il plugin viene attivato
     * Registra gli hook e avvia eventuali processi in background
     */
    public function onActivate(): void
    {
        // Registra gli hook nel database
        $this->registerHooks();

        // Verifica configurazione API
        $apiKey = $this->getSetting('goodreads_api_key');
        if (empty($apiKey)) {
            $this->log('warning', 'API key Goodreads non configurata', [
                'action_required' => 'Configurare API key nelle impostazioni'
            ]);
        }

        // Log attivazione
        $this->log('info', 'Plugin attivato', [
            'api_configured' => !empty($apiKey),
            'auto_sync' => $this->getSetting('auto_sync_enabled') === 'true'
        ]);
    }

    /**
     * Hook: Eseguito quando il plugin viene disattivato
     * Pulisce le risorse temporanee
     */
    public function onDeactivate(): void
    {
        // Non eliminiamo i dati, solo log della disattivazione
        $this->log('info', 'Plugin disattivato', [
            'data_preserved' => true,
            'note' => 'I dati dei rating sono stati mantenuti'
        ]);
    }

    /**
     * Hook: Eseguito durante la disinstallazione
     * Pulisce tutte le tabelle e i dati
     */
    public function onUninstall(): void
    {
        // Elimina tabella personalizzata
        $this->db->query("DROP TABLE IF EXISTS book_rating_data");

        $this->log('info', 'Plugin disinstallato', [
            'tables_dropped' => ['book_rating_data'],
            'settings_removed' => 'automatic (via CASCADE)'
        ]);
    }

    /**
     * Registra tutti gli hook del plugin nel database
     */
    private function registerHooks(): void
    {
        if ($this->pluginId === null) {
            return;
        }

        // Definisci gli hook da registrare
        $hooks = [
            // Estendi i dati del libro con rating esterni
            [
                'hook_name' => 'book.data.get',
                'callback_class' => 'BookRatingBookHandler',
                'callback_method' => 'enrichBookData',
                'priority' => 10
            ],
            // Salva rating dopo il salvataggio del libro
            [
                'hook_name' => 'book.save.after',
                'callback_class' => 'BookRatingBookHandler',
                'callback_method' => 'syncRatingData',
                'priority' => 10
            ],
            // Aggiungi campi rating al form libro nel backend
            [
                'hook_name' => 'book.fields.backend.form',
                'callback_class' => 'BookRatingBookHandler',
                'callback_method' => 'renderBackendFields',
                'priority' => 10
            ],
            // Mostra rating nella pagina dettaglio libro frontend
            [
                'hook_name' => 'book.frontend.details',
                'callback_class' => 'BookRatingFrontendHandler',
                'callback_method' => 'renderFrontendRating',
                'priority' => 10
            ]
        ];

        // Registra ogni hook nel database
        foreach ($hooks as $hook) {
            $stmt = $this->db->prepare("
                INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    callback_class = VALUES(callback_class),
                    callback_method = VALUES(callback_method),
                    priority = VALUES(priority),
                    is_active = 1
            ");

            $stmt->bind_param(
                'isssi',
                $this->pluginId,
                $hook['hook_name'],
                $hook['callback_class'],
                $hook['callback_method'],
                $hook['priority']
            );

            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Ottieni un'impostazione del plugin
     *
     * @param string $key Chiave impostazione
     * @param string $default Valore predefinito se l'impostazione non esiste
     * @return string
     */
    private function getSetting(string $key, string $default = ''): string
    {
        if ($this->pluginId === null) {
            return $default;
        }

        $stmt = $this->db->prepare("
            SELECT setting_value
            FROM plugin_settings
            WHERE plugin_id = ? AND setting_key = ?
        ");

        $stmt->bind_param('is', $this->pluginId, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ? $row['setting_value'] : $default;
    }

    /**
     * Salva un'impostazione del plugin
     *
     * @param string $key Chiave impostazione
     * @param string $value Valore da salvare
     */
    private function setSetting(string $key, string $value): void
    {
        if ($this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = NOW()
        ");

        $stmt->bind_param('iss', $this->pluginId, $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Log un messaggio
     *
     * @param string $level Livello log (info, warning, error, debug)
     * @param string $message Messaggio
     * @param array $context Dati contestuali aggiuntivi
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->pluginId === null) {
            return;
        }

        $contextJson = json_encode($context);

        $stmt = $this->db->prepare("
            INSERT INTO plugin_logs (plugin_id, level, message, context, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param('isss', $this->pluginId, $level, $message, $contextJson);
        $stmt->execute();
        $stmt->close();
    }
}
