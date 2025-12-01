<?php
/**
 * API Book Scraper Plugin - Pagina Impostazioni
 */

// Recupera il plugin
$plugin = $GLOBALS['plugins']['api-book-scraper'] ?? null;
if (!$plugin) {
    echo '<div class="alert alert-danger">Errore: Plugin non caricato correttamente.</div>';
    return;
}

// Gestione salvataggio impostazioni
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_api_scraper_settings'])) {
    if (\App\Support\Csrf::validate($_POST['csrf_token'] ?? null)) {
        $settings = [
            'api_endpoint' => $_POST['api_endpoint'] ?? '',
            'api_key' => $_POST['api_key'] ?? '',
            'timeout' => $_POST['timeout'] ?? 10,
            'enabled' => isset($_POST['enabled']) ? true : false
        ];

        if ($plugin->saveSettings($settings)) {
            $successMessage = 'Impostazioni salvate correttamente!';
        } else {
            $errorMessage = 'Errore nel salvataggio delle impostazioni.';
        }
    } else {
        $errorMessage = 'Token CSRF non valido.';
    }
}

// Test connessione
$testResult = null;
if (isset($_POST['test_connection']) && \App\Support\Csrf::validate($_POST['csrf_token'] ?? null)) {
    $testIsbn = '9788804668619'; // ISBN di test
    // In produzione, qui chiameremmo l'API per testare la connessione
    $testResult = [
        'success' => false,
        'message' => 'Funzione di test non ancora implementata. Salva le impostazioni e prova a importare un libro.'
    ];
}

$currentSettings = $plugin->getSettings();
$csrfToken = \App\Support\Csrf::ensureToken();
?>

<div class="max-w-4xl mx-auto py-6 px-4">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
            <i class="fas fa-cloud-download-alt text-blue-600"></i>
            API Book Scraper - Configurazione
        </h1>
        <p class="text-gray-600 mt-2">
            Configura il client per il servizio web di scraping dati libri tramite API personalizzata.
        </p>
    </div>

    <!-- Messaggi -->
    <?php if (isset($successMessage)): ?>
        <div class="mb-6 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($successMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if (isset($errorMessage)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?php echo htmlspecialchars($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($testResult): ?>
        <div class="mb-6 bg-<?php echo $testResult['success'] ? 'green' : 'yellow'; ?>-50 border border-<?php echo $testResult['success'] ? 'green' : 'yellow'; ?>-200 text-<?php echo $testResult['success'] ? 'green' : 'yellow'; ?>-800 px-4 py-3 rounded-lg">
            <strong>Test Connessione:</strong> <?php echo htmlspecialchars($testResult['message']); ?>
        </div>
    <?php endif; ?>

    <!-- Info Box -->
    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-xl p-5">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-600 text-xl mt-0.5"></i>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-blue-900 mb-2">Informazioni Plugin</h3>
                <div class="text-xs text-blue-800 space-y-2">
                    <p><strong>Funzionamento:</strong> Questo plugin si collega a un servizio web esterno per recuperare automaticamente i dati dei libri durante la creazione o modifica.</p>
                    <p><strong>Priorità:</strong> Ha priorità 3 (più alta di Open Library che ha priorità 5), quindi verrà interrogato per primo.</p>
                    <p><strong>Sicurezza:</strong> L'API key viene criptata nel database e trasmessa tramite header HTTP sicuro.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Impostazioni -->
    <form method="post" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <!-- Card Configurazione API -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-cog text-gray-500"></i>
                    Configurazione API
                </h2>
            </div>
            <div class="p-6 space-y-5">
                <!-- API Endpoint -->
                <div>
                    <label for="api_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-link mr-1"></i>
                        URL Endpoint API *
                    </label>
                    <input type="url"
                           id="api_endpoint"
                           name="api_endpoint"
                           value="<?php echo htmlspecialchars($currentSettings['api_endpoint']); ?>"
                           class="block w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 font-mono"
                           placeholder="https://api.example.com/books/{isbn}"
                           required>
                    <p class="mt-2 text-xs text-gray-600">
                        <i class="fas fa-lightbulb mr-1"></i>
                        <strong>Suggerimento:</strong> Usa <code class="bg-gray-100 px-2 py-0.5 rounded">{isbn}</code> come placeholder per l'ISBN.
                        Esempio: <code class="bg-gray-100 px-2 py-0.5 rounded">https://api.example.com/books/{isbn}</code>
                        oppure <code class="bg-gray-100 px-2 py-0.5 rounded">https://api.example.com/books/search</code> (ISBN verrà aggiunto come parametro ?isbn=...)
                    </p>
                </div>

                <!-- API Key -->
                <div>
                    <label for="api_key" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-key mr-1"></i>
                        API Key *
                    </label>
                    <div class="relative">
                        <input type="password"
                               id="api_key"
                               name="api_key"
                               value="<?php echo htmlspecialchars($currentSettings['api_key']); ?>"
                               class="block w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 font-mono pr-24"
                               placeholder="your-api-key-here"
                               required>
                        <button type="button"
                                onclick="togglePasswordVisibility('api_key')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">
                            <i class="fas fa-eye" id="api_key_icon"></i>
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-gray-600">
                        <i class="fas fa-shield-alt mr-1"></i>
                        L'API key viene criptata con AES-256-GCM prima di essere salvata nel database.
                    </p>
                </div>

                <!-- Timeout -->
                <div>
                    <label for="timeout" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-clock mr-1"></i>
                        Timeout Richiesta (secondi)
                    </label>
                    <div class="flex items-center gap-4">
                        <input type="number"
                               id="timeout"
                               name="timeout"
                               min="5"
                               max="60"
                               value="<?php echo htmlspecialchars($currentSettings['timeout']); ?>"
                               class="block w-32 rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 text-center">
                        <span class="text-sm text-gray-600">secondi (min: 5, max: 60)</span>
                    </div>
                    <p class="mt-2 text-xs text-gray-600">
                        Tempo massimo di attesa per la risposta dell'API. Consigliato: 10 secondi.
                    </p>
                </div>

                <!-- Stato Abilitazione -->
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <label class="inline-flex items-center gap-3 cursor-pointer">
                        <input type="checkbox"
                               id="enabled"
                               name="enabled"
                               value="1"
                               <?php echo $currentSettings['enabled'] ? 'checked' : ''; ?>
                               class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <div>
                            <span class="text-sm font-semibold text-gray-900">
                                <i class="fas fa-power-off mr-1"></i>
                                Abilita Plugin
                            </span>
                            <p class="text-xs text-gray-600 mt-1">
                                Quando abilitato, il plugin interrogherà l'API durante l'importazione dati libri.
                            </p>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Pulsanti Azione -->
        <div class="flex items-center gap-3">
            <button type="submit"
                    name="save_api_scraper_settings"
                    value="1"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 transition-colors">
                <i class="fas fa-save"></i>
                Salva Impostazioni
            </button>

            <button type="submit"
                    name="test_connection"
                    value="1"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-300 transition-colors">
                <i class="fas fa-vial"></i>
                Test Connessione
            </button>

            <a href="/admin/plugins"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200 transition-colors">
                <i class="fas fa-arrow-left"></i>
                Torna ai Plugin
            </a>
        </div>
    </form>

    <!-- Documentazione Rapida -->
    <div class="mt-8 bg-white border border-gray-200 rounded-2xl overflow-hidden">
        <div class="border-b border-gray-200 px-6 py-4 cursor-pointer hover:bg-gray-50 transition-colors" onclick="toggleSection('quick-docs')">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-book text-gray-500"></i>
                    Guida Rapida
                </h2>
                <i class="fas fa-chevron-down text-gray-400 transition-transform" id="quick-docs-icon"></i>
            </div>
        </div>
        <div id="quick-docs-content" class="p-6 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-2">1. Formato Richiesta</h3>
                <div class="bg-gray-900 rounded-lg p-4 text-sm font-mono text-green-400">
                    GET {api_endpoint}?isbn={ISBN}<br>
                    Header: X-API-Key: {api_key}
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-2">2. Formato Risposta JSON (Campi Supportati)</h3>
                <div class="bg-gray-900 rounded-lg p-4 text-sm font-mono text-green-400 overflow-x-auto">
{<br>
  &nbsp;&nbsp;"success": true,<br>
  &nbsp;&nbsp;"data": {<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"title": "Titolo del libro",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"subtitle": "Sottotitolo (opzionale)",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"authors": ["Autore 1", "Autore 2"],<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"publisher": "Editore",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"publish_date": "2024-01-15",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"isbn13": "9788804668619",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"ean": "9788804668619",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"pages": 350,<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"language": "it",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"description": "Descrizione...",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"cover_url": "https://...",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"series": "Nome collana",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"price": "19.90",<br>
    &nbsp;&nbsp;&nbsp;&nbsp;"format": "Brossura"<br>
  &nbsp;&nbsp;}<br>
}
                </div>
                <p class="mt-2 text-xs text-gray-600">
                    <strong>Nota:</strong> Tutti i campi sono opzionali. Il plugin userà solo i campi presenti nella risposta.
                </p>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-2">3. Codici Risposta HTTP</h3>
                <ul class="text-sm text-gray-700 space-y-1 list-disc pl-5">
                    <li><code class="bg-gray-100 px-2 py-0.5 rounded">200 OK</code> - Libro trovato, dati restituiti</li>
                    <li><code class="bg-gray-100 px-2 py-0.5 rounded">404 Not Found</code> - ISBN non trovato nel database</li>
                    <li><code class="bg-gray-100 px-2 py-0.5 rounded">401 Unauthorized</code> - API key non valida</li>
                    <li><code class="bg-gray-100 px-2 py-0.5 rounded">500 Server Error</code> - Errore server</li>
                </ul>
            </div>

            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                <div class="flex items-start gap-2">
                    <i class="fas fa-book-open text-yellow-600 mt-0.5"></i>
                    <div class="text-xs text-yellow-800">
                        <strong>Documentazione Completa:</strong>
                        Per la guida completa su come implementare il server API, consulta il file
                        <code class="bg-yellow-100 px-2 py-0.5 rounded">storage/plugins/api-book-scraper/SERVER_IMPLEMENTATION_GUIDE.md</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '_icon');

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function toggleSection(sectionId) {
    const content = document.getElementById(sectionId + '-content');
    const icon = document.getElementById(sectionId + '-icon');

    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.classList.add('rotate-180');
    } else {
        content.style.display = 'none';
        icon.classList.remove('rotate-180');
    }
}
</script>
