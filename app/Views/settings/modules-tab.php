<?php
/** @var string $activeTab */
/** @var string $csrfToken */
/** @var array<string, bool> $moduleSettings */
$moduleGroups = [
    'catalogue' => [
        'icon' => 'fa-book',
        'title' => __('Catalogazione'),
        'text' => __('Strumenti di catalogazione che si aggiungono alla scheda libro, ai menu di import ed export e alla barra laterale.'),
        'features' => ['stocktake', 'marc', 'catalogue_cards', 'suppliers', 'rda_types'],
    ],
    'users' => [
        'icon' => 'fa-users',
        'title' => __('Utenti'),
        'text' => __('Strumenti del modulo utenti: stampa delle tessere e caricamento massivo dei lettori.'),
        'features' => ['patron_cards', 'patron_photos', 'user_import'],
    ],
    'public' => [
        'icon' => 'fa-door-open',
        'title' => __('Servizi al pubblico'),
        'text' => __('Servizi rivolti a chi entra in biblioteca.'),
        'features' => ['visitor_log', 'attendance_retention'],
    ],
];
?>
<section data-settings-panel="modules" class="settings-panel <?php echo $activeTab === 'modules' ? 'block' : 'hidden'; ?>">
  <form action="<?= htmlspecialchars(url('/admin/settings/modules'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-6" id="modules-settings-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

    <?php foreach ($moduleGroups as $groupKey => $group): ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" data-modules-group="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas <?= htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8') ?> text-gray-500"></i>
          <?= htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p class="text-sm text-gray-600"><?= htmlspecialchars($group['text'], ENT_QUOTES, 'UTF-8') ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <?= __("Un modulo spento scompare dai menu e le sue pagine non sono raggiungibili. I dati già salvati restano e tornano visibili alla riaccensione.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <?php foreach ($group['features'] as $index => $featureKey): $fieldId = 'feature_' . $featureKey; ?>
        <div class="flex items-center justify-between gap-4<?= $index > 0 ? ' border-t border-gray-100 pt-4' : '' ?>">
          <div>
            <span id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>_label" class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(\App\Support\Features::label($featureKey), ENT_QUOTES, 'UTF-8') ?></span>
            <p id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>_desc" class="text-xs text-gray-600 mt-1"><?= htmlspecialchars(\App\Support\Features::help($featureKey), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer shrink-0">
            <?php if (isset(\App\Support\Features::INHERITED[$featureKey]) || $featureKey === 'attendance_retention'): ?>
            <input type="hidden" name="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" value="0">
            <?php endif; ?>
            <input type="checkbox"
                   id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                   name="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                   value="1"
                   aria-labelledby="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>_label"
                   aria-describedby="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>_desc"
                   <?php echo !empty($moduleSettings[$featureKey]) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>
        <?php endforeach; ?>
        <?php if ($groupKey === 'public'): ?>
        <div class="border-t border-gray-100 pt-4">
          <label for="attendance_retention_days" class="block text-sm font-medium text-gray-700 mb-2"><?= __('Giorni di conservazione delle presenze') ?></label>
          <input type="number" id="attendance_retention_days" name="attendance_retention_days" min="0" max="<?= \App\Models\AttendanceRepository::MAX_RETENTION_DAYS ?>" value="<?= $attendanceRetentionDays ?? \App\Models\AttendanceRepository::DEFAULT_RETENTION_DAYS ?>" class="form-input">
          <p class="text-xs text-gray-600 mt-1"><?= __('0 = nessuna cancellazione automatica. La durata si applica ai dati esistenti; non riattiva la raccolta.') ?></p>
        </div>
        <?php elseif ($groupKey === 'users'): ?>
        <a class="text-sm underline" href="<?= htmlspecialchars(url('/admin/settings?tab=cards'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Configura le tessere anche a modulo spento') ?></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="flex justify-end">
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
        <i class="fas fa-save"></i>
        <?= __("Salva moduli") ?>
      </button>
    </div>
  </form>
</section>
