<?php
/**
 * Book Handler for Book Rating Plugin
 *
 * Gestisce le operazioni sui libri: arricchimento dati, sincronizzazione, form backend
 */

declare(strict_types=1);

class BookRatingBookHandler
{
    private mysqli $db;

    public function __construct()
    {
        // Ottieni database dal container globale o dalla sessione
        global $app;
        $this->db = $app->getContainer()->get('db');
    }

    /**
     * Hook: book.data.get
     * Arricchisce i dati del libro con rating esterni
     *
     * @param array $bookData Dati del libro dal database
     * @param int $bookId ID del libro
     * @return array Dati del libro arricchiti
     */
    public function enrichBookData(array $bookData, int $bookId): array
    {
        // Recupera rating dal database
        $stmt = $this->db->prepare("
            SELECT
                goodreads_rating,
                goodreads_ratings_count,
                goodreads_reviews_count,
                goodreads_url,
                last_sync
            FROM book_rating_data
            WHERE libro_id = ?
        ");

        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Aggiungi i dati rating al libro
            $bookData['external_rating'] = $row['goodreads_rating'];
            $bookData['external_ratings_count'] = $row['goodreads_ratings_count'];
            $bookData['external_reviews_count'] = $row['goodreads_reviews_count'];
            $bookData['external_rating_url'] = $row['goodreads_url'];
            $bookData['external_rating_last_sync'] = $row['last_sync'];

            // Calcola se necessita sincronizzazione
            $syncInterval = $this->getPluginSetting('sync_interval_hours', '24');
            $lastSync = strtotime($row['last_sync']);
            $needsSync = (time() - $lastSync) > ((int)$syncInterval * 3600);

            $bookData['external_rating_needs_sync'] = $needsSync;
        } else {
            // Nessun rating disponibile
            $bookData['external_rating'] = null;
            $bookData['external_ratings_count'] = null;
            $bookData['external_reviews_count'] = null;
            $bookData['external_rating_url'] = null;
            $bookData['external_rating_last_sync'] = null;
            $bookData['external_rating_needs_sync'] = true;
        }

        $stmt->close();

        return $bookData;
    }

    /**
     * Hook: book.save.after
     * Sincronizza rating dopo il salvataggio del libro
     *
     * @param int $bookId ID del libro salvato
     * @param array $bookData Dati del libro
     */
    public function syncRatingData(int $bookId, array $bookData): void
    {
        // Verifica se sync automatica è abilitata
        $autoSync = $this->getPluginSetting('auto_sync_enabled', 'true');
        if ($autoSync !== 'true') {
            return;
        }

        // Verifica se abbiamo ISBN per la ricerca
        $isbn = $bookData['isbn13'] ?? $bookData['isbn10'] ?? null;
        if (empty($isbn)) {
            $this->pluginLog('debug', 'Sync rating saltata: ISBN mancante', [
                'book_id' => $bookId
            ]);
            return;
        }

        // Cerca rating da API Goodreads
        $ratingData = $this->fetchGoodreadsRating($isbn);

        if ($ratingData) {
            // Salva rating nel database
            $stmt = $this->db->prepare("
                INSERT INTO book_rating_data (
                    libro_id,
                    goodreads_rating,
                    goodreads_ratings_count,
                    goodreads_reviews_count,
                    goodreads_url,
                    last_sync
                ) VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    goodreads_rating = VALUES(goodreads_rating),
                    goodreads_ratings_count = VALUES(goodreads_ratings_count),
                    goodreads_reviews_count = VALUES(goodreads_reviews_count),
                    goodreads_url = VALUES(goodreads_url),
                    last_sync = NOW()
            ");

            $stmt->bind_param(
                'idiis',
                $bookId,
                $ratingData['rating'],
                $ratingData['ratings_count'],
                $ratingData['reviews_count'],
                $ratingData['url']
            );

            $stmt->execute();
            $stmt->close();

            $this->pluginLog('info', 'Rating sincronizzato con successo', [
                'book_id' => $bookId,
                'isbn' => $isbn,
                'rating' => $ratingData['rating']
            ]);
        }
    }

    /**
     * Hook: book.fields.backend.form
     * Aggiunge campi rating al form libro nel backend
     *
     * @param array|null $bookData Dati del libro (null se nuovo)
     * @param int|null $bookId ID del libro (null se nuovo)
     */
    public function renderBackendFields(?array $bookData, ?int $bookId): void
    {
        // Se è un nuovo libro, non mostrare nulla
        if ($bookId === null || $bookData === null) {
            return;
        }

        // Recupera rating
        $stmt = $this->db->prepare("
            SELECT *
            FROM book_rating_data
            WHERE libro_id = ?
        ");

        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ratingData = $result->fetch_assoc();
        $stmt->close();

        // Render campi personalizzati
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-star text-yellow-500 mr-2"></i>
                    Rating Esterno (Goodreads)
                </h3>
                <button type="button" onclick="syncExternalRating(<?= $bookId ?>)"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm">
                    <i class="fas fa-sync mr-2"></i>Sincronizza
                </button>
            </div>

            <?php if ($ratingData): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="text-sm text-gray-600 mb-1">Rating Medio</div>
                        <div class="text-2xl font-bold text-gray-900">
                            <?= number_format((float)$ratingData['goodreads_rating'], 2) ?>/5
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="text-sm text-gray-600 mb-1">Numero Valutazioni</div>
                        <div class="text-2xl font-bold text-gray-900">
                            <?= number_format((int)$ratingData['goodreads_ratings_count']) ?>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="text-sm text-gray-600 mb-1">Numero Recensioni</div>
                        <div class="text-2xl font-bold text-gray-900">
                            <?= number_format((int)$ratingData['goodreads_reviews_count']) ?>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between text-sm text-gray-600">
                    <span>
                        Ultima sincronizzazione:
                        <?= date('d/m/Y H:i', strtotime($ratingData['last_sync'])) ?>
                    </span>
                    <?php if ($ratingData['goodreads_url']): ?>
                        <a href="<?= htmlspecialchars($ratingData['goodreads_url']) ?>"
                           target="_blank"
                           class="text-blue-600 hover:text-blue-800">
                            Vedi su Goodreads <i class="fas fa-external-link-alt ml-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-6 text-gray-500">
                    <i class="fas fa-info-circle text-3xl mb-2"></i>
                    <p>Nessun rating disponibile. Clicca su "Sincronizza" per recuperare i dati.</p>
                </div>
            <?php endif; ?>
        </div>

        <script>
        function syncExternalRating(bookId) {
            // Implementa chiamata AJAX per sincronizzare rating
            console.log('Sync rating per libro:', bookId);
            // TODO: Implementare endpoint API per sync manuale
        }
        </script>
        <?php
    }

    /**
     * Recupera rating da API Goodreads (simulato)
     *
     * @param string $isbn ISBN del libro
     * @return array|null Dati rating o null se non trovato
     */
    private function fetchGoodreadsRating(string $isbn): ?array
    {
        $apiKey = $this->getPluginSetting('goodreads_api_key');

        if (empty($apiKey)) {
            return null;
        }

        // NOTA: Questa è una implementazione simulata
        // In produzione, dovresti fare una vera chiamata API a Goodreads
        // Esempio: https://www.goodreads.com/book/isbn/{isbn}?key={apiKey}

        // Simulazione risposta API
        return [
            'rating' => rand(30, 48) / 10, // Rating tra 3.0 e 4.8
            'ratings_count' => rand(100, 5000),
            'reviews_count' => rand(50, 1000),
            'url' => "https://www.goodreads.com/book/isbn/{$isbn}"
        ];

        /*
        // Implementazione reale (esempio):
        try {
            $url = "https://www.goodreads.com/book/isbn/{$isbn}?key={$apiKey}";
            $response = file_get_contents($url);
            $xml = simplexml_load_string($response);

            return [
                'rating' => (float)$xml->book->average_rating,
                'ratings_count' => (int)$xml->book->ratings_count,
                'reviews_count' => (int)$xml->book->text_reviews_count,
                'url' => (string)$xml->book->url
            ];
        } catch (Exception $e) {
            $this->pluginLog('error', 'Errore chiamata API Goodreads', [
                'isbn' => $isbn,
                'error' => $e->getMessage()
            ]);
            return null;
        }
        */
    }

    /**
     * Helper: Ottieni impostazione plugin
     */
    private function getPluginSetting(string $key, string $default = ''): string
    {
        $result = $this->db->query("
            SELECT setting_value
            FROM plugin_settings ps
            JOIN plugins p ON ps.plugin_id = p.id
            WHERE p.name = 'book-rating' AND ps.setting_key = '{$key}'
            LIMIT 1
        ");

        if ($result && $row = $result->fetch_assoc()) {
            return $row['setting_value'];
        }

        return $default;
    }

    /**
     * Helper: Log plugin
     */
    private function pluginLog(string $level, string $message, array $context = []): void
    {
        $contextJson = $this->db->real_escape_string(json_encode($context));

        $this->db->query("
            INSERT INTO plugin_logs (plugin_id, level, message, context, created_at)
            SELECT id, '{$level}', '{$message}', '{$contextJson}', NOW()
            FROM plugins
            WHERE name = 'book-rating'
            LIMIT 1
        ");
    }
}
