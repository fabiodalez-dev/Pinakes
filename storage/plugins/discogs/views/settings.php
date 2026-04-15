<?php
/**
 * Discogs Plugin - Pagina Impostazioni
 */

// Recupera il plugin
$plugin = $pluginInstance ?? ($GLOBALS['plugins']['discogs'] ?? null);
if (!$plugin) {
    echo '<div class="alert alert-danger">Errore: Plugin non caricato correttamente.</div>';
    return;
}

// Gestione salvataggio impostazioni
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_discogs_settings']) || isset($_POST['clear_discogs_settings']))) {
    if (\App\Support\Csrf::validate($_POST['csrf_token'] ?? null)) {
        if (isset($_POST['clear_discogs_settings'])) {
            if ($plugin->saveSettings(['api_token' => ''])) {
                $successMessage = __('Token Discogs rimosso correttamente.');
            } else {
                $errorMessage = __('Errore nel salvataggio delle impostazioni.');
            }
        } else {
            $apiToken = trim($_POST['api_token'] ?? '');
            if ($apiToken !== '') {
                $settings = ['api_token' => $apiToken];
                if ($plugin->saveSettings($settings)) {
                    $successMessage = __('Impostazioni Discogs salvate correttamente.');
                } else {
                    $errorMessage = __('Errore nel salvataggio delle impostazioni.');
                }
            }
        }
    } else {
        $errorMessage = __('Token CSRF non valido.');
    }
}

$currentSettings = $plugin->getSettings();
$hasToken = !empty($currentSettings['api_token']);
$csrfToken = \App\Support\Csrf::ensureToken();
$pluginsRoute = htmlspecialchars(route_path('plugins'), ENT_QUOTES, 'UTF-8');
?>

<div class="max-w-4xl mx-auto py-6 px-4">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
            <i class="fas fa-compact-disc text-purple-600"></i>
            Discogs - <?= __("Configurazione") ?>
        </h1>
        <p class="text-gray-600 mt-2">
            <?= __("Configura l'integrazione con le API di Discogs per lo scraping di metadati musicali.") ?>
        </p>
    </div>

    <!-- Messaggi -->
    <?php if (isset($successMessage)): ?>
        <div class="mb-6 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <?php if (isset($errorMessage)): ?>
        <div class="mb-6 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <!-- Info Box -->
    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-xl p-5">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-600 text-xl mt-0.5"></i>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-blue-900 mb-2"><?= __("Informazioni Plugin") ?></h3>
                <div class="text-xs text-blue-800 space-y-2">
                    <p><strong><?= __("Funzionamento:") ?></strong> <?= __("Questo plugin si collega alle API di Discogs per recuperare automaticamente metadati di CD, vinili e altri supporti musicali tramite barcode (EAN/UPC) o ricerca testuale.") ?></p>
                    <p><strong><?= __("Token opzionale:") ?></strong> <?= __("Il token di accesso personale non è obbligatorio, ma aumenta il limite di richieste da 25 a 60 al minuto.") ?></p>
                    <p><strong><?= __("Priorità:") ?></strong> <?= __("Ha priorità 8, viene interrogato dopo le fonti di scraping standard.") ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Impostazioni -->
    <form method="post" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

        <!-- Card Configurazione API -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-key text-gray-500"></i>
                    <?= __("Token di Accesso") ?>
                </h2>
            </div>
            <div class="p-6 space-y-5">
                <!-- API Token -->
                <div>
                    <label for="api_token" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-key mr-1"></i>
                        <?= __("Personal Access Token") ?>
                    </label>
                    <div class="relative">
                        <input type="password"
                               id="api_token"
                               name="api_token"
                               value=""
                               class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4 font-mono pr-24"
                               placeholder="<?= $hasToken ? htmlspecialchars(__('Token configurato — lascia vuoto per mantenere'), ENT_QUOTES, 'UTF-8') : 'DiscogsPersonalAccessToken' ?>">
                        <button type="button"
                                onclick="togglePasswordVisibility('api_token')"
                                aria-label="<?= htmlspecialchars(__('Mostra/nascondi token'), ENT_QUOTES, 'UTF-8') ?>"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">
                            <i class="fas fa-eye" id="api_token_icon"></i>
                        </button>
                    </div>
                    <?php if ($hasToken): ?>
                        <span class="text-xs text-green-600 mt-1"><i class="fas fa-check-circle"></i> <?= htmlspecialchars(__('Token configurato'), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <p class="mt-2 text-xs text-gray-600">
                        <i class="fas fa-external-link-alt mr-1"></i>
                        <?= __("Genera il tuo token su") ?>
                        <a href="https://www.discogs.com/settings/developers" target="_blank" rel="noopener noreferrer" class="text-purple-600 hover:text-purple-800 underline">
                            discogs.com/settings/developers
                        </a>
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        <i class="fas fa-shield-alt mr-1"></i>
                        <?= __("Senza token: 25 richieste/minuto. Con token: 60 richieste/minuto.") ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Pulsanti Azione -->
        <div class="flex items-center gap-3">
            <button type="submit"
                    name="save_discogs_settings"
                    value="1"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-purple-600 text-white text-sm font-semibold hover:bg-purple-700 transition-colors">
                <i class="fas fa-save"></i>
                <?= __("Salva Impostazioni") ?>
            </button>

            <?php if ($hasToken): ?>
                <button type="submit"
                        name="clear_discogs_settings"
                        value="1"
                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-red-50 text-red-700 text-sm font-semibold hover:bg-red-100 transition-colors">
                    <i class="fas fa-trash-alt"></i>
                    <?= __("Rimuovi Token") ?>
                </button>
            <?php endif; ?>

            <a href="<?= $pluginsRoute ?>"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200 transition-colors">
                <i class="fas fa-arrow-left"></i>
                <?= __("Torna ai Plugin") ?>
            </a>
        </div>
    </form>
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
</script>
