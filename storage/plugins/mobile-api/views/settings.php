<?php
/**
 * Mobile API — admin settings page.
 *
 * Rendered by PluginController::settingsPage() inside the admin layout, which
 * exposes $pluginInstance (and via the controller scope) $pluginId and $args.
 * Admin route is an English literal (/admin/plugins/{id}/settings) — never route_path().
 *
 * @var \App\Plugins\MobileApi\MobileApiPlugin|null $pluginInstance
 * @var int|null                                    $pluginId
 * @var array<string,mixed>|null                    $args
 */

$plugin = $pluginInstance ?? ($GLOBALS['plugins']['mobile-api'] ?? null);
if (!$plugin) {
    echo '<div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 px-4 py-3 rounded-lg">'
        . htmlspecialchars(__('Errore: plugin Mobile API non caricato correttamente.'), ENT_QUOTES, 'UTF-8')
        . '</div>';
    return;
}

// $pluginId is in scope from PluginController::settingsPage(); fall back to $args['id'].
$resolvedId = isset($pluginId) ? (int) $pluginId
    : (isset($args['id']) ? (int) $args['id'] : 0);

$formAction   = htmlspecialchars(url('/admin/plugins/' . $resolvedId . '/settings'), ENT_QUOTES, 'UTF-8');
$pluginsRoute = htmlspecialchars(url('/admin/plugins'), ENT_QUOTES, 'UTF-8');

// ── Active tab ──────────────────────────────────────────────────────────────
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'devices' ? 'devices' : 'settings';

// ── Handle admin revoke (POST action=revoke_device) ─────────────────────────
/** @var string $successMessage */
$successMessage = '';
/** @var string $errorMessage */
$errorMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!\App\Support\Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = __('Token CSRF non valido.');
    } elseif (isset($_POST['action']) && $_POST['action'] === 'revoke_device') {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        if ($deviceId > 0 && $plugin->adminRevokeDevice($deviceId)) {
            $successMessage = __('Dispositivo revocato.');
        } else {
            $errorMessage = __('Impossibile revocare il dispositivo.');
        }
        $activeTab = 'devices';
    } elseif (isset($_POST['save_mobile_api_settings'])) {
        $enabled = (isset($_POST['enabled']) && (string) $_POST['enabled'] === '1') ? '1' : '0';
        $pushProvider = (string) ($_POST['push_provider'] ?? 'unifiedpush');
        if (!in_array($pushProvider, ['unifiedpush', 'fcm'], true)) {
            $pushProvider = 'unifiedpush';
        }
        if ($plugin->saveSettings([
            'enabled'              => $enabled,
            'push_provider'        => $pushProvider,
            'push_vapid_subject'   => (string) ($_POST['push_vapid_subject'] ?? ''),
            'push_fcm_credentials' => (string) ($_POST['push_fcm_credentials'] ?? ''),
            'trusted_proxies'      => (string) ($_POST['trusted_proxies'] ?? ''),
        ])) {
            $successMessage = __('Impostazioni salvate correttamente.');
        } else {
            $errorMessage = __('Errore nel salvataggio delle impostazioni.');
        }
    }
}

/** @var array<string,string> $settings */
$settings  = $plugin->getSettings();
$isEnabled = (($settings['enabled'] ?? '0') === '1');
/** @var string $pushProvider */
$pushProvider = (string) ($settings['push_provider'] ?? 'unifiedpush');
/** @var string $vapidSubject */
$vapidSubject = (string) ($settings['push_vapid_subject'] ?? '');
/** @var string $trustedProxies */
$trustedProxies = (string) ($settings['trusted_proxies'] ?? '');

// Reverse-proxy auto-diagnosis: if THIS admin request arrived with X-Forwarded-Proto set
// by a proxy whose IP isn't trusted yet, the app-facing /api/v1 will 426 ("HTTPS required")
// even over real HTTPS. Detect it so we can offer the exact IP to add.
$fwdProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
$proxyRemoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$proxyTrusted = false;
if ($proxyRemoteAddr !== '') {
    foreach (\App\Plugins\MobileApi\Support\ProxyTrust::trustedEntries() as $entry) {
        if ($entry === $proxyRemoteAddr) {
            $proxyTrusted = true;
            break;
        }
    }
}
$isLoopbackPeer = ($proxyRemoteAddr === '::1' || preg_match('/^127\./', $proxyRemoteAddr) === 1);
$showProxyHint = ($fwdProto !== '' && $proxyRemoteAddr !== '' && !$proxyTrusted && !$isLoopbackPeer);
/** @var string $fcmCredentials */
$fcmCredentials = (string) ($settings['push_fcm_credentials'] ?? '');
$csrfToken = \App\Support\Csrf::ensureToken();

// For the devices tab: only load when active.
/** @var list<array<string,mixed>> $devices */
$devices = ($activeTab === 'devices') ? $plugin->listAllDevices() : [];

$tabSettingsUrl = htmlspecialchars(url('/admin/plugins/' . $resolvedId . '/settings') . '?tab=settings', ENT_QUOTES, 'UTF-8');
$tabDevicesUrl  = htmlspecialchars(url('/admin/plugins/' . $resolvedId . '/settings') . '?tab=devices', ENT_QUOTES, 'UTF-8');
?>

<div class="max-w-4xl mx-auto py-6 px-4 max-sm:!py-3">

  <!-- Header -->
  <div class="mb-6 flex items-start justify-between gap-4">
    <div>
      <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
        <i class="fas fa-mobile-screen-button text-blue-600"></i>
        <?= htmlspecialchars(__('Mobile API'), ENT_QUOTES, 'UTF-8') ?>
      </h1>
      <p class="text-gray-600 mt-2">
        <?= htmlspecialchars(__("Abilita l'accesso dell'app mobile a questa biblioteca tramite l'API REST /api/v1."), ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>
    <a href="<?= $pluginsRoute ?>" class="btn-secondary whitespace-nowrap">
      <i class="fas fa-arrow-left mr-2"></i>
      <?= htmlspecialchars(__('Plugin'), ENT_QUOTES, 'UTF-8') ?>
    </a>
  </div>

  <div class="space-y-6">

    <?php if ($successMessage !== ''): ?>
    <div role="status" aria-live="polite" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2">
      <i class="fas fa-check-circle" aria-hidden="true"></i>
      <span class="text-sm"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
    <div role="alert" aria-live="assertive" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center gap-2">
      <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
      <span class="text-sm"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <!-- Tab nav -->
    <div class="border-b border-gray-200 dark:border-gray-700">
      <nav class="-mb-px flex space-x-6">
        <a href="<?= $tabSettingsUrl ?>"
           class="pb-3 text-sm font-medium border-b-2 transition-colors <?= $activeTab === 'settings'
             ? 'border-blue-500 text-blue-600 dark:text-blue-400'
             : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300' ?>">
          <i class="fas fa-sliders mr-1.5"></i>
          <?= htmlspecialchars(__('Impostazioni'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="<?= $tabDevicesUrl ?>"
           class="pb-3 text-sm font-medium border-b-2 transition-colors <?= $activeTab === 'devices'
             ? 'border-blue-500 text-blue-600 dark:text-blue-400'
             : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300' ?>">
          <i class="fas fa-mobile-screen-button mr-1.5"></i>
          <?= htmlspecialchars(__('Dispositivi'), ENT_QUOTES, 'UTF-8') ?>
        </a>
      </nav>
    </div>

    <?php if ($activeTab === 'settings'): ?>
    <!-- ── Settings tab ───────────────────────────────────────────────────── -->
    <form method="POST" action="<?= $formAction ?>?tab=settings">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <!-- App access toggle -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-5 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
            <i class="fas fa-toggle-on text-blue-500"></i>
            <?= htmlspecialchars(__('Accesso app mobile'), ENT_QUOTES, 'UTF-8') ?>
          </h2>
        </div>
        <div class="p-5">
          <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" name="enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>
                   class="mt-1 h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span>
              <span class="block font-medium text-gray-900 dark:text-white">
                <?= htmlspecialchars(__('Abilita accesso app mobile'), ENT_QUOTES, 'UTF-8') ?>
              </span>
              <span class="block text-sm text-gray-500 dark:text-gray-400 mt-1">
                <?= htmlspecialchars(__("Quando disattivato, l'app non può autenticarsi. L'endpoint /api/v1/health e la documentazione /api/v1/docs restano sempre raggiungibili."), ENT_QUOTES, 'UTF-8') ?>
              </span>
            </span>
          </label>

          <?php if ($isEnabled): ?>
          <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg text-sm text-blue-700 dark:text-blue-300 flex items-center gap-2">
            <i class="fas fa-info-circle"></i>
            <span>
              <?= htmlspecialchars(__('API attiva. Endpoint:'), ENT_QUOTES, 'UTF-8') ?>
              <code class="font-mono">/api/v1/health</code> &middot;
              <a href="<?= htmlspecialchars(url('/api/v1/docs'), ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                 class="underline">/api/v1/docs</a>
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Push notifications -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-5 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
            <i class="fas fa-bell text-blue-500"></i>
            <?= htmlspecialchars(__('Notifiche push'), ENT_QUOTES, 'UTF-8') ?>
          </h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            <?= htmlspecialchars(__("Facoltative. Senza credenziali l'app riceve comunque le notifiche tramite il feed in-app (polling). Le notifiche push non bloccano mai il funzionamento."), ENT_QUOTES, 'UTF-8') ?>
          </p>
        </div>
        <div class="p-5 space-y-5">
          <div>
            <label for="push_provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= htmlspecialchars(__('Provider push'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="push_provider" name="push_provider"
                    class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:border-blue-500 focus:ring-blue-500">
              <option value="unifiedpush" <?= $pushProvider === 'unifiedpush' ? 'selected' : '' ?>>
                <?= htmlspecialchars(__('UnifiedPush (consigliato, nessuna credenziale centrale)'), ENT_QUOTES, 'UTF-8') ?>
              </option>
              <option value="fcm" <?= $pushProvider === 'fcm' ? 'selected' : '' ?>>
                <?= htmlspecialchars(__('Firebase Cloud Messaging (sperimentale)'), ENT_QUOTES, 'UTF-8') ?>
              </option>
            </select>
          </div>

          <div>
            <label for="push_vapid_subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= htmlspecialchars(__('Soggetto VAPID (UnifiedPush, facoltativo)'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="text" id="push_vapid_subject" name="push_vapid_subject"
                   value="<?= htmlspecialchars($vapidSubject, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="mailto:admin@example.org"
                   class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:border-blue-500 focus:ring-blue-500">
          </div>

          <div>
            <label for="push_fcm_credentials" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= htmlspecialchars(__('Credenziali FCM (JSON service-account, facoltative)'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea id="push_fcm_credentials" name="push_fcm_credentials" rows="3"
                      placeholder='{"type":"service_account", ...}'
                      class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm font-mono focus:border-blue-500 focus:ring-blue-500"><?= htmlspecialchars($fcmCredentials, ENT_QUOTES, 'UTF-8') ?></textarea>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
              <?= htmlspecialchars(__('Lascia vuoto per usare solo UnifiedPush / feed in-app.'), ENT_QUOTES, 'UTF-8') ?>
            </p>
          </div>

          <div>
            <label for="trusted_proxies" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= htmlspecialchars(__('Proxy fidati (reverse proxy)'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="text" id="trusted_proxies" name="trusted_proxies"
                   value="<?= htmlspecialchars($trustedProxies, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="127.0.0.1, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16"
                   class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
              <?= htmlspecialchars(__('Se Pinakes è dietro un reverse proxy (NAS/QNAP/Synology/nginx/Docker) che termina il TLS, elenca qui gli IP o le reti (CIDR) del proxy: solo così l\'app accetta l\'header X-Forwarded-Proto e l\'API non risponde "HTTPS richiesto" anche su HTTPS reale.'), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php if ($showProxyHint): ?>
              <div class="mt-2 rounded-lg border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/30 p-3 text-xs text-amber-800 dark:text-amber-200">
                <i class="fas fa-triangle-exclamation mr-1"></i>
                <?= htmlspecialchars(sprintf(__('Rilevato un reverse proxy da %s che inoltra HTTPS, ma quell\'indirizzo non è ancora fidato: l\'app riceverebbe un errore HTTPS. Aggiungilo alla lista qui sopra e salva.'), $proxyRemoteAddr), ENT_QUOTES, 'UTF-8') ?>
                <button type="button"
                        onclick="var f=document.getElementById('trusted_proxies');f.value=(f.value.trim()?f.value.trim().replace(/,\s*$/,'')+', ':'')+<?= json_encode($proxyRemoteAddr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;"
                        class="ml-1 underline font-medium">
                  <?= htmlspecialchars(sprintf(__('Aggiungi %s'), $proxyRemoteAddr), ENT_QUOTES, 'UTF-8') ?>
                </button>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Save row -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 flex items-center justify-between max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <p class="text-xs text-gray-500 dark:text-gray-400">
          <i class="fas fa-lock mr-1"></i>
          <?= htmlspecialchars(__("L'API richiede HTTPS (eccetto loopback in sviluppo)."), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <button type="submit" name="save_mobile_api_settings" value="1" class="btn-primary">
          <i class="fas fa-save mr-2"></i>
          <?= htmlspecialchars(__('Salva impostazioni'), ENT_QUOTES, 'UTF-8') ?>
        </button>
      </div>
    </form>

    <?php else: ?>
    <!-- ── Devices tab ────────────────────────────────────────────────────── -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
      <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <i class="fas fa-mobile-screen-button text-gray-500"></i>
          <?= htmlspecialchars(__('Dispositivi attivi'), ENT_QUOTES, 'UTF-8') ?>
          <span class="ml-1 px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs rounded-full">
            <?= count($devices) ?>
          </span>
        </h2>
        <p class="text-xs text-gray-500 dark:text-gray-400">
          <?= htmlspecialchars(__('I token revocati vengono invalidati immediatamente.'), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>

      <?php if (empty($devices)): ?>
      <div class="p-10 text-center text-gray-400 dark:text-gray-500">
        <i class="fas fa-mobile-screen-button text-4xl mb-3 block"></i>
        <p class="text-sm"><?= htmlspecialchars(__('Nessun dispositivo attivo.'), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                <?= htmlspecialchars(__('Utente'), ENT_QUOTES, 'UTF-8') ?>
              </th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                <?= htmlspecialchars(__('Dispositivo'), ENT_QUOTES, 'UTF-8') ?>
              </th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                <?= htmlspecialchars(__('Piattaforma'), ENT_QUOTES, 'UTF-8') ?>
              </th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                <?= htmlspecialchars(__('Ultimo accesso'), ENT_QUOTES, 'UTF-8') ?>
              </th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                <?= htmlspecialchars(__('Creato il'), ENT_QUOTES, 'UTF-8') ?>
              </th>
              <th class="px-5 py-3"><span class="sr-only"><?= htmlspecialchars(__('Azioni'), ENT_QUOTES, 'UTF-8') ?></span></th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <?php foreach ($devices as $dev): ?>
            <?php
            $devId       = (int) ($dev['id'] ?? 0);
            $userName    = trim((string) ($dev['nome'] ?? '') . ' ' . (string) ($dev['cognome'] ?? ''));
            $userEmail   = (string) ($dev['email'] ?? '');
            $deviceName  = (string) ($dev['device_name'] ?? '');
            $platform    = (string) ($dev['platform'] ?? '');
            $lastUsed    = (string) ($dev['last_used_at'] ?? '');
            $createdAt   = (string) ($dev['created_at'] ?? '');
            $platformIcon = match ($platform) {
                'android' => 'fab fa-android text-green-500',
                'ios'     => 'fab fa-apple text-gray-500 dark:text-gray-300',
                default   => 'fas fa-mobile-screen-button text-gray-400',
            };
            ?>
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
              <td class="px-5 py-4">
                <div class="text-sm font-medium text-gray-900 dark:text-white">
                  <?= htmlspecialchars($userName !== '' ? $userName : ($userEmail ?: __('Sconosciuto')), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($userName !== '' && $userEmail !== ''): ?>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                  <?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-300">
                <?= htmlspecialchars($deviceName !== '' ? $deviceName : __('Sconosciuto'), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-sm">
                <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                  <i class="<?= htmlspecialchars($platformIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                  <?= htmlspecialchars(ucfirst($platform !== '' ? $platform : '—'), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                <?= htmlspecialchars($lastUsed !== '' ? $lastUsed : '—', ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-right">
                <form id="mobile-device-revoke-form-<?= $devId ?>" method="post"
                      action="<?= $formAction ?>?tab=devices">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="revoke_device">
                  <input type="hidden" name="device_id" value="<?= $devId ?>">
                  <button type="button"
                          aria-label="<?= htmlspecialchars(__('Revoca') . ': ' . ($deviceName !== '' ? $deviceName : __('Sconosciuto')), ENT_QUOTES, 'UTF-8') ?>"
                          data-device-name="<?= htmlspecialchars($deviceName, ENT_QUOTES, 'UTF-8') ?>"
                          data-user-name="<?= htmlspecialchars($userName ?: $userEmail, ENT_QUOTES, 'UTF-8') ?>"
                          data-form-id="mobile-device-revoke-form-<?= $devId ?>"
                          onclick="mobileApiConfirmRevoke(this)"
                          class="inline-flex items-center px-3 py-1.5 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 text-xs font-medium rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors">
                    <i class="fas fa-ban mr-1.5" aria-hidden="true"></i>
                    <?= htmlspecialchars(__('Revoca'), ENT_QUOTES, 'UTF-8') ?>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div><!-- /p-6 -->
</div>

<script>
(function () {
  'use strict';
  var I18N = {
    revokeTitle:   <?= json_encode(__('Revocare dispositivo?'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    revokeNote:    <?= json_encode(__("L'app non potrà più usare questo token. L'utente dovrà effettuare di nuovo il login."), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    confirmBtn:    <?= json_encode(__('Revoca'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    cancelBtn:     <?= json_encode(__('Annulla'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    deviceLabel:   <?= json_encode(__('Dispositivo'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    userLabel:     <?= json_encode(__('Utente'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
  };

  function escHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  window.mobileApiConfirmRevoke = function (btn) {
    var deviceName = btn.dataset.deviceName || '';
    var userName   = btn.dataset.userName || '';
    var formId     = btn.dataset.formId;
    var form       = formId ? document.getElementById(formId) : null;

    var html = '';
    if (deviceName) { html += '<strong>' + escHtml(I18N.deviceLabel) + ':</strong> ' + escHtml(deviceName) + '<br>'; }
    if (userName)   { html += '<strong>' + escHtml(I18N.userLabel)   + ':</strong> ' + escHtml(userName) + '<br>'; }
    html += '<small>' + escHtml(I18N.revokeNote) + '</small>';

    if (typeof Swal === 'undefined' || !Swal.fire) {
      if (window.confirm(I18N.revokeTitle + '\n' + (deviceName ? deviceName + '\n' : '') + (userName ? userName + '\n' : '') + I18N.revokeNote) && form) {
        form.submit();
      }
      return;
    }

    Swal.fire({
      title: I18N.revokeTitle,
      html: html,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: I18N.confirmBtn,
      cancelButtonText: I18N.cancelBtn,
      confirmButtonColor: '#dc2626'
    }).then(function (result) {
      if (result && result.isConfirmed && form) { form.submit(); }
    });
  };
})();
</script>
