<?php
/**
 * GoodLib plugin settings — rendered inside the plugin settings modal.
 *
 * @var array{anna_enabled: bool, zlib_enabled: bool, gutenberg_enabled: bool, show_frontend: bool, show_admin: bool} $settings
 */
?>
<div class="space-y-6">
  <!-- Sources -->
  <div>
    <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
      <i class="fas fa-database text-gray-400"></i>
      <?= __("Fonti attive") ?>
    </h4>
    <div class="space-y-3">
      <?php
      $sourcesList = [
          'anna_enabled' => ["Anna's Archive", 'fas fa-book-open', '#e74c3c'],
          'zlib_enabled' => ['Z-Library', 'fas fa-search', '#3498db'],
          'gutenberg_enabled' => ['Project Gutenberg', 'fas fa-feather-alt', '#27ae60'],
      ];
      foreach ($sourcesList as $key => [$label, $icon, $color]):
          $checked = $settings[$key] ? 'checked' : '';
      ?>
        <label class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors cursor-pointer">
          <div class="flex items-center gap-3">
            <span class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-sm"
                  style="background: <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>">
              <i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
            </span>
            <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <input type="checkbox" name="<?= $key ?>" value="1" <?= $checked ?>
                 class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Visibility -->
  <div>
    <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
      <i class="fas fa-eye text-gray-400"></i>
      <?= __("Visibilita") ?>
    </h4>
    <div class="space-y-3">
      <label class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors cursor-pointer">
        <div class="flex items-center gap-3">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center bg-gray-100 text-gray-600 text-sm">
            <i class="fas fa-globe"></i>
          </span>
          <div>
            <span class="text-sm font-medium text-gray-700"><?= __("Catalogo pubblico") ?></span>
            <p class="text-xs text-gray-500"><?= __("Mostra i badge nella pagina dettaglio libro del catalogo") ?></p>
          </div>
        </div>
        <input type="checkbox" name="show_frontend" value="1" <?= $settings['show_frontend'] ? 'checked' : '' ?>
               class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
      </label>
      <label class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors cursor-pointer">
        <div class="flex items-center gap-3">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center bg-gray-100 text-gray-600 text-sm">
            <i class="fas fa-lock"></i>
          </span>
          <div>
            <span class="text-sm font-medium text-gray-700"><?= __("Scheda libro admin") ?></span>
            <p class="text-xs text-gray-500"><?= __("Mostra i badge nella scheda libro dell'area amministrazione") ?></p>
          </div>
        </div>
        <input type="checkbox" name="show_admin" value="1" <?= $settings['show_admin'] ? 'checked' : '' ?>
               class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
      </label>
    </div>
  </div>
</div>
