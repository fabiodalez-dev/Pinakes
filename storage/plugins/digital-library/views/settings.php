<?php
/**
 * Digital Library — admin settings page (issue #480).
 *
 * Rendered by PluginController::settingsPage() inside the admin layout; a POST to the
 * same URL comes back here through PluginController::updateSettings() (self-handling
 * form, like the Mobile API page). Admin route is an English literal.
 *
 * @var \DigitalLibraryPlugin|null $pluginInstance
 * @var int|null                   $pluginId
 * @var array<string,mixed>|null   $args
 */

$plugin = $pluginInstance ?? ($GLOBALS['plugins']['digital-library'] ?? null);
if (!$plugin instanceof \DigitalLibraryPlugin) {
    echo '<div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 px-4 py-3 rounded-lg">'
        . htmlspecialchars(__('Errore: plugin Digital Library non caricato correttamente.'), ENT_QUOTES, 'UTF-8')
        . '</div>';
    return;
}

$resolvedId = isset($pluginId) ? (int) $pluginId : (isset($args['id']) ? (int) $args['id'] : 0);
$formAction = htmlspecialchars(url('/admin/plugins/' . $resolvedId . '/settings'), ENT_QUOTES, 'UTF-8');
$pluginsRoute = htmlspecialchars(url('/admin/plugins'), ENT_QUOTES, 'UTF-8');

$successMessage = '';
$errorMessage = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!\App\Support\Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = __('Token CSRF non valido.');
    } elseif (isset($_POST['move_digital_files'])) {
        // An audiobook copied across disks can take longer than max_execution_time.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $result = $plugin->migrateLegacyFiles(PHP_INT_MAX, 25.0);
        if ($result['failed'] > 0) {
            $errorMessage = sprintf(__('%1$d file spostati, %2$d non spostati: controlla i permessi delle cartelle.'), $result['moved'], $result['failed']);
        } elseif ($result['remaining'] > 0) {
            $successMessage = sprintf(__('%1$d file spostati, %2$d ancora da spostare: premi di nuovo il pulsante.'), $result['moved'], $result['remaining']);
        } else {
            $successMessage = sprintf(__('%d file spostati nella cartella protetta.'), $result['moved']);
        }
    } elseif (isset($_POST['save_digital_library_settings'])) {
        if ($plugin->saveSettings([
            'default_visibility' => (string) ($_POST['default_visibility'] ?? 'public'),
            'show_locked_entries' => isset($_POST['show_locked_entries']) ? '1' : '0',
        ])) {
            $successMessage = __('Impostazioni salvate correttamente.');
        } else {
            $errorMessage = __('Errore nel salvataggio delle impostazioni.');
        }
    }
}

$settings = $plugin->getSettings();
$defaultVisibility = $settings['default_visibility'];
$showLocked = $settings['show_locked_entries'] === '1';
$counts = $plugin->storageCounts();
$csrfToken = \App\Support\Csrf::ensureToken();
$visibilityOptions = [
    'public' => [__('Chiunque'), __('Chiunque visiti la scheda, anche senza accedere.')],
    'users' => [__('Utenti registrati'), __('Solo chi ha effettuato l’accesso.')],
    'staff' => [__('Solo amministratori e staff'), __('Il file non compare nella scheda pubblica.')],
    'loan' => [__('Chi ha il libro in prestito'), __('Amministratori, staff e l’utente che ha in corso il prestito di quel libro.')],
];
?>

<div class="max-w-4xl mx-auto py-6 px-4 max-sm:!py-3">

  <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center gap-3"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

        <?= htmlspecialchars(__('Digital Library'), ENT_QUOTES, 'UTF-8') ?>
      <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

      <p class="text-gray-600 mt-2">
        <?= htmlspecialchars(__('Chi può aprire eBook, audiolibri e documenti allegati alle schede, e dove sono conservati i file.'), ENT_QUOTES, 'UTF-8') ?>
      </p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><a href="<?= $pluginsRoute ?>" class="btn-secondary whitespace-nowrap">
      <i class="fas fa-arrow-left mr-2"></i>
      <?= htmlspecialchars(__('Plugin'), ENT_QUOTES, 'UTF-8') ?>
    </a><?php
$workspaceHeader['back'] = (string) ob_get_clean();
ob_start();
?>
  <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
require dirname(__DIR__, 4) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

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

    <form method="POST" action="<?= $formAction ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-5 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
            <i class="fas fa-lock text-blue-500"></i>
            <?= htmlspecialchars(__('Chi può aprire i file'), ENT_QUOTES, 'UTF-8') ?>
          </h2>
        </div>
        <div class="p-5 space-y-5">
          <div>
            <label for="default_visibility" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= htmlspecialchars(__('Visibilità predefinita'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="default_visibility" name="default_visibility"
                    class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:border-blue-500 focus:ring-blue-500">
              <?php foreach ($visibilityOptions as $value => [$name, $hint]): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $defaultVisibility === $value ? 'selected' : '' ?>>
                <?= htmlspecialchars($name . ' — ' . $hint, ENT_QUOTES, 'UTF-8') ?>
              </option>
              <?php endforeach; ?>
            </select>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
              <?= htmlspecialchars(__('Vale per ogni allegato lasciato su «Predefinita» nella scheda del libro; ogni allegato può avere una visibilità sua.'), ENT_QUOTES, 'UTF-8') ?>
            </p>
          </div>

          <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" name="show_locked_entries" value="1" <?= $showLocked ? 'checked' : '' ?>
                   class="mt-1 h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span>
              <span class="block font-medium text-gray-900 dark:text-white">
                <?= htmlspecialchars(__('Mostra i file non ancora accessibili'), ENT_QUOTES, 'UTF-8') ?>
              </span>
              <span class="block text-sm text-gray-500 dark:text-gray-400 mt-1">
                <?= htmlspecialchars(__('Nella scheda pubblica un file riservato agli utenti registrati o a chi ha il prestito compare chiuso, con il motivo. I file riservati allo staff non compaiono mai.'), ENT_QUOTES, 'UTF-8') ?>
              </span>
            </span>
          </label>
        </div>
      </div>

      <div class="pk-form-actions bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 flex items-center justify-between max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <p class="text-xs text-gray-500 dark:text-gray-400">
          <i class="fas fa-lock mr-1"></i>
          <?= htmlspecialchars(__('Ogni download passa da Pinakes, che controlla chi lo chiede.'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <button type="submit" name="save_digital_library_settings" value="1" class="btn-primary">
          <i class="fas fa-save mr-2"></i>
          <?= htmlspecialchars(__('Salva impostazioni'), ENT_QUOTES, 'UTF-8') ?>
        </button>
      </div>
    </form>

    <form method="POST" action="<?= $formAction ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
            <i class="fas fa-box-archive text-blue-500"></i>
            <?= htmlspecialchars(__('Archiviazione dei file'), ENT_QUOTES, 'UTF-8') ?>
          </h2>
        </div>
        <div class="p-5 space-y-3">
          <p class="text-sm text-gray-600 dark:text-gray-300">
            <?= htmlspecialchars(sprintf(__('File nella cartella protetta (storage/digital): %d'), $counts['storage']), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <p class="text-sm text-gray-600 dark:text-gray-300">
            <?= htmlspecialchars(sprintf(__('File ancora nella vecchia cartella pubblica (public/uploads/digital): %d'), $counts['public']), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <?php if ($counts['failed'] > 0): ?>
          <p class="text-sm text-amber-700 dark:text-amber-300" role="status">
            <i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>
            <?= htmlspecialchars(sprintf(__('File che non si riescono a spostare (permessi o un file diverso con lo stesso nome in storage/digital): %d. Sono comunque protetti; controlla il log e premi «Sposta ora» dopo aver sistemato.'), $counts['failed']), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <?php endif; ?>
          <?php if ($counts['public'] > 0): ?>
          <p class="text-sm text-gray-500 dark:text-gray-400">
            <?= htmlspecialchars(__('Pinakes li sposta da solo, un po’ alla volta, mentre il sito viene usato; intanto restano protetti dagli stessi controlli. Puoi spostarli subito.'), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <button type="submit" name="move_digital_files" value="1" class="btn-secondary">
            <i class="fas fa-right-left mr-2"></i>
            <?= htmlspecialchars(__('Sposta ora'), ENT_QUOTES, 'UTF-8') ?>
          </button>
          <?php endif; ?>
        </div>
      </div>
    </form>

  </div>
</div>
