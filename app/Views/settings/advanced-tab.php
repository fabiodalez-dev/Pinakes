<?php
use App\Support\HtmlHelper;
?>
<section data-settings-panel="advanced" class="settings-panel <?php echo $activeTab === 'advanced' ? 'block' : 'hidden'; ?>">
  <form action="/admin/settings/advanced" method="post" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">

    <!-- JavaScript Personalizzato - Informazioni Generali -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-5 mb-6">
      <div class="flex items-start gap-3">
        <i class="fas fa-info-circle text-blue-600 text-xl mt-0.5"></i>
        <div class="flex-1">
          <h3 class="text-sm font-semibold text-blue-900 mb-2">Gestione JavaScript Personalizzati basata su Cookie</h3>
          <div class="text-xs text-blue-800 space-y-2">
            <p>Gli script JavaScript sono divisi in 3 categorie in base alla tipologia di cookie:</p>
            <ul class="list-disc pl-5 space-y-1">
              <li><strong><?= __("Essenziali:") ?></strong> <?= __("Si caricano sempre, indipendentemente dal consenso cookie") ?></li>
              <li><strong><?= __("Analitici:") ?></strong> <?= __("Si caricano solo se l'utente accetta i cookie Analytics nel banner") ?></li>
              <li><strong><?= __("Marketing:") ?></strong> <?= __("Si caricano solo se l'utente accetta i cookie Marketing nel banner") ?></li>
            </ul>
            <p class="mt-3"><strong>⚙️ Comportamento Automatico:</strong> Se inserisci codice in "JavaScript Analitici" o "JavaScript Marketing", i rispettivi toggle in <a href="/admin/settings?tab=privacy#privacy" class="underline font-semibold">Impostazioni Privacy</a> verranno automaticamente selezionati.</p>
            <p class="mt-2"><strong>📋 Importante:</strong> Devi elencare manualmente i cookie tracciati da questi script nella <a href="/cookies" target="_blank" class="underline font-semibold">Pagina Cookie</a> per conformità GDPR.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- JavaScript Essenziali -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-shield-alt text-gray-500"></i>
          JavaScript Essenziali
        </h2>
        <p class="text-sm text-gray-600">Script necessari per il funzionamento del sito (es. chat support, accessibility tools)</p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-bolt text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <strong><?= __("Caricamento automatico:") ?></strong> <?= __("Questi script si caricano sempre, senza richiedere consenso cookie.") ?>
            </div>
          </div>
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
            <div class="text-xs text-yellow-800">
              <strong><?= __("Attenzione:") ?></strong> <?= __("Inserisci solo script che NON tracciano utenti. Per analytics/marketing usa le sezioni dedicate.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
        <label for="custom_js_essential" class="block text-sm font-medium text-gray-700">Codice JavaScript</label>
        <textarea id="custom_js_essential"
                  name="custom_js_essential"
                  rows="10"
                  class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 font-mono"
                  placeholder="<?= __('// Script essenziali (es. chat, accessibility)
// Esempio:
// console.log(\'Essential JS loaded\');') ?>"><?php echo HtmlHelper::e($advancedSettings['custom_js_essential'] ?? ''); ?></textarea>
        <p class="text-xs text-gray-500">
          <i class="fas fa-info-circle mr-1"></i>
          Non includere tag &lt;script&gt;&lt;/script&gt;
        </p>
      </div>
    </div>

    <!-- JavaScript Analitici -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-chart-line text-gray-500"></i>
          JavaScript Analitici
        </h2>
        <p class="text-sm text-gray-600">Script di analisi e statistiche (es. Google Analytics, Matomo, Hotjar)</p>
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-cookie-bite text-blue-600 mt-0.5"></i>
            <div class="text-xs text-blue-800">
              <strong><?= __("Caricamento condizionale:") ?></strong> <?= __("Questi script si caricano solo se l'utente accetta i cookie Analytics nel banner.") ?>
            </div>
          </div>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-magic text-green-600 mt-0.5"></i>
            <div class="text-xs text-green-800">
              <strong>Auto-attivazione:</strong> Se compili questo campo, il toggle "Mostra Cookie Analitici" in Privacy verrà attivato automaticamente.
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
        <label for="custom_js_analytics" class="block text-sm font-medium text-gray-700">Codice JavaScript</label>
        <textarea id="custom_js_analytics"
                  name="custom_js_analytics"
                  rows="10"
                  class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 font-mono"
                  placeholder="<?= __('// Script analytics (es. Google Analytics)
// Esempio Google Analytics 4:
// (function(i,s,o,g,r,a,m){i[\'GoogleAnalyticsObject\']=r;i[r]=i[r]||function(){
// (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
// m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
// })(window,document,\'script\',\'https://www.google-analytics.com/analytics.js\',\'ga\');
// ga(\'create\', \'UA-XXXXX-Y\', \'auto\');
// ga(\'send\', \'pageview\');') ?>"><?php echo HtmlHelper::e($advancedSettings['custom_js_analytics'] ?? ''); ?></textarea>
        <p class="text-xs text-gray-500">
          <i class="fas fa-info-circle mr-1"></i>
          Non includere tag &lt;script&gt;&lt;/script&gt;
        </p>
      </div>
    </div>

    <!-- JavaScript Marketing -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-bullhorn text-gray-500"></i>
          JavaScript Marketing
        </h2>
        <p class="text-sm text-gray-600">Script pubblicitari e remarketing (es. Facebook Pixel, Google Ads, LinkedIn Insight)</p>
        <div class="bg-purple-50 border border-purple-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-cookie-bite text-purple-600 mt-0.5"></i>
            <div class="text-xs text-purple-800">
              <strong><?= __("Caricamento condizionale:") ?></strong> <?= __("Questi script si caricano solo se l'utente accetta i cookie Marketing nel banner.") ?>
            </div>
          </div>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-magic text-green-600 mt-0.5"></i>
            <div class="text-xs text-green-800">
              <strong>Auto-attivazione:</strong> Se compili questo campo, il toggle "Mostra Cookie Marketing" in Privacy verrà attivato automaticamente.
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
        <label for="custom_js_marketing" class="block text-sm font-medium text-gray-700">Codice JavaScript</label>
        <textarea id="custom_js_marketing"
                  name="custom_js_marketing"
                  rows="10"
                  class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 font-mono"
                  placeholder="<?= __('// Script marketing (es. Facebook Pixel)
// Esempio Facebook Pixel:
// !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
// n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
// n.push=n;n.loaded=!0;n.version=\'2.0\';n.queue=[];t=b.createElement(e);t.async=!0;
// t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
// document,\'script\',\'https://connect.facebook.net/en_US/fbevents.js\');
// fbq(\'init\', \'YOUR_PIXEL_ID\');
// fbq(\'track\', \'PageView\');') ?>"><?php echo HtmlHelper::e($advancedSettings['custom_js_marketing'] ?? ''); ?></textarea>
        <p class="text-xs text-gray-500">
          <i class="fas fa-info-circle mr-1"></i>
          Non includere tag &lt;script&gt;&lt;/script&gt;
        </p>
      </div>
    </div>

    <!-- Custom CSS Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-paint-brush text-gray-500"></i>
          CSS Personalizzato
        </h2>
        <p class="text-sm text-gray-600">Codice CSS da applicare a tutte le pagine del frontend</p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <strong><?= __("Personalizzazione:") ?></strong> <?= __("Usa questo campo per personalizzare lo stile del sito senza modificare i file di tema.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
        <label for="custom_header_css" class="block text-sm font-medium text-gray-700">Codice CSS</label>
        <textarea id="custom_header_css"
                  name="custom_header_css"
                  rows="12"
                  class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 font-mono"
                  placeholder="<?= __('/* Inserisci il tuo codice CSS qui */
/* Esempio: */
/* body { font-size: 16px; } */') ?>"><?php echo HtmlHelper::e($advancedSettings['custom_header_css'] ?? ''); ?></textarea>
        <p class="text-xs text-gray-500">
          <i class="fas fa-info-circle mr-1"></i>
          Il codice verrà inserito in un tag &lt;style&gt; nell'header. Non includere i tag &lt;style&gt;&lt;/style&gt;
        </p>
      </div>
    </div>

    <!-- Loan Expiry Warning Settings -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-bell text-gray-500"></i>
          Notifiche Prestiti
        </h2>
        <p class="text-sm text-gray-600">Configura quando inviare l'avviso di scadenza prestiti agli utenti</p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <strong><?= __("Funzionamento automatico:") ?></strong> <?= __("Il sistema invierà automaticamente una email di promemoria agli utenti prima della scadenza del prestito. Il valore predefinito è 3 giorni.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
        <div>
          <label for="days_before_expiry_warning" class="block text-sm font-medium text-gray-700 mb-2">
            Giorni di preavviso per scadenza prestito
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="days_before_expiry_warning"
                   name="days_before_expiry_warning"
                   min="1"
                   max="30"
                   value="<?php echo isset($advancedSettings['days_before_expiry_warning']) && $advancedSettings['days_before_expiry_warning'] > 0 ? (int)$advancedSettings['days_before_expiry_warning'] : 3; ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600">giorni prima della scadenza</span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            Valore compreso tra 1 e 30 giorni. Consigliato: 3 giorni
          </p>
        </div>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-3">
          <div class="text-xs text-gray-700">
            <strong><?= __("Esempio:") ?></strong> <?= __("Con valore 3, un prestito che scade il 15 Gennaio riceverà l'avviso il 12 Gennaio") ?>
          </div>
        </div>
      </div>
    </div>

    <div class="flex justify-end">
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
        <i class="fas fa-save"></i>
        Salva Impostazioni Avanzate
      </button>
    </div>
  </form>

  <?php
    $lastGeneratedRaw = $advancedSettings['sitemap_last_generated_at'] ?? '';
    $lastGeneratedDisplay = null;
    if ($lastGeneratedRaw !== '') {
        try {
            $dt = new DateTimeImmutable($lastGeneratedRaw);
            $tz = new DateTimeZone(date_default_timezone_get());
            $lastGeneratedDisplay = $dt->setTimezone($tz)->format('d/m/Y H:i:s T');
        } catch (\Throwable $exception) {
            $lastGeneratedDisplay = $lastGeneratedRaw;
        }
    }

    $totalUrls = isset($advancedSettings['sitemap_last_generated_total'])
        ? (int)$advancedSettings['sitemap_last_generated_total']
        : 0;
    $projectRoot = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    $cronExample = '0 2 * * * cd ' . $projectRoot . ' && /usr/bin/php scripts/generate-sitemap.php >> storage/logs/sitemap.log 2>&1';
    $filesystemPath = realpath(__DIR__ . '/../../public/sitemap.xml') ?: (__DIR__ . '/../../public/sitemap.xml');
    $sitemapExists = file_exists($filesystemPath);
    $sitemapFileModified = $sitemapExists ? @filemtime($filesystemPath) : null;
    $publicBaseUrl = \App\Controllers\SeoController::resolveBaseUrl();
    $publicSitemapUrl = rtrim($publicBaseUrl, '/') . '/sitemap.xml';
  ?>

  <!-- Sitemap XML Section -->
  <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden mt-6">
    <div class="border-b border-gray-200 px-6 py-4">
      <div class="flex items-center gap-3">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-gray-100">
          <i class="fas fa-sitemap text-gray-600"></i>
        </span>
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Sitemap XML</h3>
          <p class="text-sm text-gray-600 mt-0.5">Mappa del sito per i motori di ricerca</p>
        </div>
      </div>
    </div>
    <div class="p-6 space-y-6">
      <!-- Sitemap Info -->
      <div class="space-y-3">
        <div class="flex items-start gap-2 text-sm">
          <i class="fas fa-link text-gray-400 mt-0.5"></i>
          <div>
            <span class="text-gray-600">URL pubblico:</span>
            <a href="<?php echo HtmlHelper::e($publicSitemapUrl); ?>" class="text-gray-900 hover:text-black underline ml-2" target="_blank"><?php echo HtmlHelper::e($publicSitemapUrl); ?></a>
          </div>
        </div>
        <div class="flex items-start gap-2 text-sm">
          <i class="fas fa-file-code text-gray-400 mt-0.5"></i>
          <div>
            <span class="text-gray-600">Percorso file:</span>
            <code class="bg-gray-100 px-2 py-1 rounded text-xs ml-2"><?php echo HtmlHelper::e($filesystemPath); ?></code>
          </div>
        </div>
        <div class="flex items-start gap-2 text-sm">
          <i class="fas fa-clock text-gray-400 mt-0.5"></i>
          <div>
            <span class="text-gray-600">Ultima generazione:</span>
            <?php if ($lastGeneratedDisplay !== null): ?>
              <span class="text-gray-900 ml-2"><?php echo HtmlHelper::e($lastGeneratedDisplay); ?></span>
              <?php if ($totalUrls > 0): ?>
                <span class="ml-2 text-xs bg-gray-100 px-2 py-1 rounded-full text-gray-700"><?php echo $totalUrls; ?> URL</span>
              <?php endif; ?>
            <?php elseif ($sitemapExists && $sitemapFileModified): ?>
              <span class="text-gray-900 ml-2"><?php echo date('d/m/Y H:i:s', $sitemapFileModified); ?></span>
              <span class="ml-2 text-xs bg-yellow-100 px-2 py-1 rounded-full text-yellow-800">
                <i class="fas fa-info-circle"></i> File esistente (data modifica)
              </span>
            <?php else: ?>
              <span class="inline-flex items-center gap-2 text-red-600 ml-2">
                <i class="fas fa-exclamation-triangle"></i>Mai generata
              </span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($sitemapExists): ?>
        <div class="flex items-start gap-2 text-sm">
          <i class="fas fa-check-circle text-green-500 mt-0.5"></i>
          <div>
            <span class="text-green-700 font-medium">File sitemap presente</span>
            <span class="text-xs text-gray-500 ml-2">(<?php echo HtmlHelper::e($filesystemPath); ?>)</span>
          </div>
        </div>
        <?php else: ?>
        <div class="flex items-start gap-2 text-sm">
          <i class="fas fa-times-circle text-red-500 mt-0.5"></i>
          <div>
            <span class="text-red-700 font-medium">File sitemap non trovato</span>
            <span class="text-xs text-gray-500 ml-2">Usa il pulsante "Rigenera adesso" per crearla</span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Cron Configuration -->
      <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3">
        <div class="$1"><?= __("$2") ?></div>
        <code class="block text-xs bg-gray-900 text-green-400 border border-gray-800 rounded-lg p-3 overflow-x-auto"><?php echo HtmlHelper::e($cronExample); ?></code>
        <p class="text-xs text-gray-600">Esegue la rigenerazione ogni giorno alle 02:00 e registra il log in <code class="bg-gray-100 px-1 py-0.5 rounded">storage/logs/sitemap.log</code>.</p>
      </div>

      <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
        <p class="text-xs text-gray-600">
          <i class="fas fa-info-circle mr-1"></i>
          Lo script CLI utilizza il valore di <code class="bg-gray-100 px-1 py-0.5 rounded">APP_CANONICAL_URL</code>. Assicurati che sia configurato correttamente per evitare URL duplicati.
        </p>
      </div>

      <!-- Regenerate Button -->
      <div class="border-t border-gray-200 pt-6">
        <h4 class="text-base font-semibold text-gray-900 mb-3 flex items-center gap-2">
          <i class="fas fa-sync-alt text-gray-500"></i>
          Rigenera Sitemap
        </h4>
        <p class="text-sm text-gray-600 mb-4">
          La sitemap viene aggiornata automaticamente quando premi il pulsante oppure tramite lo script CLI
          <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">php scripts/generate-sitemap.php</code>.
          Usa questa azione dopo aver importato un grande numero di libri o modifiche ai contenuti CMS.
        </p>
        <form action="/admin/settings/advanced/regenerate-sitemap" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">
          <button type="submit"
                  class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
            <i class="fas fa-cogs"></i>
            Rigenera adesso
          </button>
        </form>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-xs text-gray-600 space-y-2 mt-4">
          <div class="$1"><?= __("$2") ?></div>
          <ul class="list-disc pl-5 space-y-1">
            <li>Il file generato si trova in <code class="bg-gray-100 px-1 py-0.5 rounded">public/sitemap.xml</code></li>
            <li>Il cron utilizza gli stessi permessi dell'utente di sistema che lo esegue</li>
            <li>Dopo la rigenerazione, invia l'URL della sitemap a Google Search Console e Bing Webmaster Tools</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Public API Section -->
  <?php
    use App\Models\ApiKeyRepository;
    $apiKeyRepo = new ApiKeyRepository($GLOBALS['db'] ?? $db ?? null);
    if ($apiKeyRepo !== null && method_exists($apiKeyRepo, 'ensureTable')) {
      try {
        $apiKeyRepo->ensureTable();
      } catch (\Throwable $e) {
        error_log('Failed to ensure API keys table: ' . $e->getMessage());
      }
    }
    $apiKeys = [];
    try {
      $apiKeys = $apiKeyRepo !== null ? $apiKeyRepo->getAll() : [];
    } catch (\Throwable $e) {
      error_log('Failed to get API keys: ' . $e->getMessage());
    }
    $apiEnabled = ($advancedSettings['api_enabled'] ?? '0') === '1';
    $apiEndpoint = \App\Controllers\SeoController::resolveBaseUrl() . '/api/public/books/search';
  ?>

  <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden mt-6">
    <div class="border-b border-gray-200 px-6 py-4 cursor-pointer hover:bg-gray-50 transition-colors" onclick="toggleApiSection()">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-gray-100">
            <i class="fas fa-key text-gray-600"></i>
          </span>
          <div>
            <h3 class="text-lg font-semibold text-gray-900">API Pubblica</h3>
            <p class="text-sm text-gray-600 mt-0.5">Gestisci l'accesso all'API per cercare libri via EAN, ISBN e autore</p>
          </div>
        </div>
        <i class="fas fa-chevron-down text-gray-400 transition-transform" id="api-section-icon"></i>
      </div>
    </div>

    <div id="api-section-content" class="p-6 space-y-6">
      <!-- Enable/Disable API -->
      <form action="/admin/settings/api/toggle" method="post" id="api-toggle-form">
        <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-200">
          <div>
            <h4 class="text-sm font-semibold text-gray-900">Stato API</h4>
            <p class="text-xs text-gray-600 mt-1">Abilita o disabilita l'accesso all'API pubblica</p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox"
                   name="api_enabled"
                   value="1"
                   <?php echo $apiEnabled ? 'checked' : ''; ?>
                   class="sr-only peer"
                   onchange="document.getElementById('api-toggle-form').submit()">
            <div class="w-20 h-10 bg-white border-4 border-gray-900 rounded-full peer
                        peer-checked:bg-gray-900
                        transition-all duration-300 ease-in-out
                        relative cursor-pointer
                        shadow-inner">
              <span class="absolute top-0.5 left-0.5 w-8 h-8 bg-gray-900 rounded-full
                           peer-checked:translate-x-9 peer-checked:bg-white
                           transition-all duration-300 ease-in-out
                           shadow-lg
                           flex items-center justify-center text-white text-xs font-bold peer-checked:text-gray-900">
                <?php echo $apiEnabled ? 'ON' : 'OFF'; ?>
              </span>
            </div>
          </label>
        </div>
      </form>

      <!-- API Keys Management -->
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <h4 class="text-base font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-shield-alt text-gray-500"></i>
            API Keys
          </h4>
          <button type="button"
                  onclick="showCreateApiKeyModal()"
                  class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
            <i class="fas fa-plus"></i>
            Crea Nuova API Key
          </button>
        </div>

        <?php if (empty($apiKeys)): ?>
          <div class="text-center py-12 bg-gray-50 rounded-xl border border-gray-200">
            <i class="fas fa-key text-4xl text-gray-300 mb-4"></i>
            <p class="text-gray-600 mb-4">Nessuna API key configurata</p>
            <button type="button"
                    onclick="showCreateApiKeyModal()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
              <i class="fas fa-plus"></i>
              Crea Prima API Key
            </button>
          </div>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($apiKeys as $key): ?>
              <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-200 hover:border-gray-300 transition-colors">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-3">
                    <h5 class="text-sm font-semibold text-gray-900"><?php echo HtmlHelper::e($key['name']); ?></h5>
                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-medium <?php echo $key['is_active'] ? 'bg-gray-900 text-white' : 'bg-gray-200 text-gray-600'; ?>">
                      <?php echo $key['is_active'] ? 'Attiva' : 'Disattivata'; ?>
                    </span>
                  </div>
                  <?php if (!empty($key['description'])): ?>
                    <p class="text-xs text-gray-600 mt-1"><?php echo HtmlHelper::e($key['description']); ?></p>
                  <?php endif; ?>
                  <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                    <span><i class="fas fa-clock mr-1"></i>Creata: <?php echo date('d/m/Y H:i', strtotime($key['created_at'])); ?></span>
                    <?php if ($key['last_used_at']): ?>
                      <span><i class="fas fa-history mr-1"></i>Ultimo uso: <?php echo date('d/m/Y H:i', strtotime($key['last_used_at'])); ?></span>
                    <?php else: ?>
                      <span class="text-yellow-600"><i class="fas fa-exclamation-triangle mr-1"></i>Mai utilizzata</span>
                    <?php endif; ?>
                  </div>
                  <div class="mt-3">
                    <button type="button"
                            onclick="toggleApiKeyVisibility('key-<?php echo $key['id']; ?>')"
                            class="text-xs text-gray-700 hover:text-black font-medium">
                      <i class="fas fa-eye mr-1"></i>
                      <span id="key-<?php echo $key['id']; ?>-toggle-text">Mostra API Key</span>
                    </button>
                    <div id="key-<?php echo $key['id']; ?>" class="hidden mt-2 p-3 bg-gray-900 rounded-lg">
                      <code class="text-xs text-green-400 font-mono break-all"><?php echo HtmlHelper::e($key['api_key']); ?></code>
                      <button type="button"
                              onclick="copyToClipboard('<?php echo HtmlHelper::e($key['api_key']); ?>', this)"
                              class="ml-2 text-xs text-gray-300 hover:text-white">
                        <i class="fas fa-copy"></i> Copia
                      </button>
                    </div>
                  </div>
                </div>
                <div class="flex items-center gap-2 ml-4">
                  <form action="/admin/settings/api/keys/<?php echo $key['id']; ?>/toggle" method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">
                    <button type="submit"
                            class="p-2 rounded-lg <?php echo $key['is_active'] ? 'bg-gray-200 text-gray-700 hover:bg-gray-300' : 'bg-gray-900 text-white hover:bg-black'; ?> transition-colors"
                            title="<?php echo $key['is_active'] ? 'Disattiva' : 'Attiva'; ?>">
                      <i class="fas <?php echo $key['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                    </button>
                  </form>
                  <form action="/admin/settings/api/keys/<?php echo $key['id']; ?>/delete" method="post" class="inline" onsubmit="return confirm(__('Sei sicuro di voler eliminare questa API key? Questa azione è irreversibile.'))">
                    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">
                    <button type="submit"
                            class="p-2 rounded-lg bg-red-100 text-red-700 hover:bg-red-200 transition-colors"
                            title="Elimina">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- API Documentation -->
      <details class="border border-gray-200 rounded-xl overflow-hidden">
        <summary class="cursor-pointer bg-gray-50 px-5 py-4 font-semibold text-gray-900 hover:bg-gray-100 transition-colors flex items-center gap-2">
          <i class="fas fa-book text-gray-500"></i>
          Documentazione API
        </summary>
        <div class="p-5 space-y-4">
          <div>
            <h5 class="text-sm font-semibold text-gray-900 mb-2">Endpoint</h5>
            <div class="bg-gray-900 rounded-xl p-4">
              <code class="text-sm text-green-400 font-mono break-all"><?php echo HtmlHelper::e($apiEndpoint); ?></code>
              <button type="button"
                      onclick="copyToClipboard('<?php echo HtmlHelper::e($apiEndpoint); ?>', this)"
                      class="ml-2 text-xs text-gray-300 hover:text-white">
                <i class="fas fa-copy"></i> Copia
              </button>
            </div>
          </div>

          <div>
            <h5 class="text-sm font-semibold text-gray-900 mb-2">Autenticazione</h5>
            <p class="text-xs text-gray-600 mb-3">L'API key può essere fornita in due modi:</p>
            <div class="space-y-2 text-xs">
              <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                <strong class="text-gray-900">Header HTTP (consigliato):</strong>
                <pre class="mt-2 bg-gray-900 text-green-400 p-2 rounded overflow-x-auto"><code>X-API-Key: your-api-key-here</code></pre>
              </div>
              <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                <strong class="text-gray-900">Query parameter:</strong>
                <pre class="mt-2 bg-gray-900 text-green-400 p-2 rounded overflow-x-auto"><code>?api_key=your-api-key-here</code></pre>
              </div>
            </div>
          </div>

          <div>
            <h5 class="text-sm font-semibold text-gray-900 mb-2">Parametri di Ricerca</h5>
            <p class="text-xs text-gray-600 mb-3">Almeno uno dei seguenti parametri è richiesto:</p>
            <div class="space-y-2">
              <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                <code class="text-xs font-mono text-gray-900">ean</code>
                <span class="text-xs text-gray-600 ml-2">- Cerca per codice EAN</span>
              </div>
              <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                <code class="text-xs font-mono text-gray-900">isbn13</code>
                <span class="text-xs text-gray-600 ml-2">- Cerca per ISBN-13</span>
              </div>
              <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                <code class="text-xs font-mono text-gray-900">isbn10</code>
                <span class="text-xs text-gray-600 ml-2">- Cerca per ISBN-10</span>
              </div>
              <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
                <code class="text-xs font-mono text-gray-900">author</code>
                <span class="text-xs text-gray-600 ml-2">- Cerca per nome autore (corrispondenza parziale)</span>
              </div>
            </div>
          </div>

          <div>
            <h5 class="text-sm font-semibold text-gray-900 mb-2">Esempio di Chiamata</h5>
            <div class="bg-gray-900 rounded-xl p-4 overflow-x-auto">
              <pre class="text-xs text-green-400 font-mono"><code>curl -X GET "<?php echo HtmlHelper::e($apiEndpoint); ?>?isbn13=9788804668619" \
  -H "X-API-Key: your-api-key-here"</code></pre>
            </div>
          </div>

          <div>
            <h5 class="text-sm font-semibold text-gray-900 mb-2">Risposta JSON</h5>
            <p class="text-xs text-gray-600 mb-2">La risposta include tutti i dati del libro:</p>
            <ul class="text-xs text-gray-600 space-y-1 list-disc pl-5">
              <li>Dati bibliografici completi (titolo, sottotitolo, ISBN, EAN, ecc.)</li>
              <li>Informazioni editore</li>
              <li>Autori con biografie</li>
              <li>Genere letterario</li>
              <li>Stato prestito corrente</li>
              <li>Recensioni utenti</li>
              <li>Numero prenotazioni attive</li>
              <li>Disponibilità copie</li>
            </ul>
          </div>

          <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
            <div class="flex items-start gap-2">
              <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
              <div class="text-xs text-gray-700">
                <p class="font-semibold mb-1">Note Importanti</p>
                <ul class="list-disc pl-5 space-y-1">
                  <li>L'API è limitata a 50 risultati per richiesta</li>
                  <li>Tutte le date sono in formato ISO 8601 (YYYY-MM-DD HH:MM:SS)</li>
                  <li>I campi null indicano dati non disponibili</li>
                  <li>Le API key disattivate restituiranno errore 401</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </details>
    </div>
  </div>
</section>

<!-- Create API Key Modal -->
<div id="create-api-key-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl max-w-md w-full p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-xl font-bold text-gray-900">Crea Nuova API Key</h3>
      <button type="button" onclick="hideCreateApiKeyModal()" class="text-gray-400 hover:text-gray-600">
        <i class="fas fa-times text-xl"></i>
      </button>
    </div>
    <form action="/admin/settings/api/keys/create" method="post">
      <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">
      <div class="space-y-4">
        <div>
          <label for="api_key_name" class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
          <input type="text"
                 id="api_key_name"
                 name="name"
                 required aria-required="true"
                 class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('es. Integrazione Sito Web') ?>">
        </div>
        <div>
          <label for="api_key_description" class="block text-sm font-medium text-gray-700 mb-1">__("Descrizione")</label>
          <textarea id="api_key_description"
                    name="description"
                    rows="3"
                    class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                    placeholder="<?= __('Descrivi l\'utilizzo di questa API key...') ?>"></textarea>
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-3">
          <div class="flex items-start gap-2">
            <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
            <p class="text-xs text-yellow-800">
              <strong><?= __("Importante:") ?></strong> <?= __("Salva la API key in un luogo sicuro. Non sarà possibile visualizzarla nuovamente dopo la creazione.") ?>
            </p>
          </div>
        </div>
      </div>
      <div class="flex gap-3 mt-6">
        <button type="submit"
                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
          <i class="fas fa-plus"></i>
          Crea API Key
        </button>
        <button type="button"
                onclick="hideCreateApiKeyModal()"
                class="px-4 py-3 rounded-xl bg-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-300 transition-colors">
          Annulla
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function showCreateApiKeyModal() {
  document.getElementById('create-api-key-modal').classList.remove('hidden');
}

function hideCreateApiKeyModal() {
  document.getElementById('create-api-key-modal').classList.add('hidden');
}

function toggleApiKeyVisibility(keyId) {
  const element = document.getElementById(keyId);
  const toggleText = document.getElementById(keyId + '-toggle-text');
  if (element.classList.contains('hidden')) {
    element.classList.remove('hidden');
    toggleText.textContent = 'Nascondi API Key';
  } else {
    element.classList.add('hidden');
    toggleText.textContent = 'Mostra API Key';
  }
}

function copyToClipboard(text, button) {
  navigator.clipboard.writeText(text).then(() => {
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-check"></i> Copiato!';
    setTimeout(() => {
      button.innerHTML = originalHTML;
    }, 2000);
  }).catch(err => {
    alert('Errore nella copia: ' + err);
  });
}

// Close modal on escape key
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    hideCreateApiKeyModal();
  }
});

// Toggle API Section
function toggleApiSection() {
  const content = document.getElementById('api-section-content');
  const icon = document.getElementById('api-section-icon');

  if (content.style.display === 'none') {
    content.style.display = 'block';
    icon.classList.add('rotate-180');
  } else {
    content.style.display = 'none';
    icon.classList.remove('rotate-180');
  }
}
</script>
