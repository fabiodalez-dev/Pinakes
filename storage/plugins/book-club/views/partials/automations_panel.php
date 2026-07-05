<?php
/**
 * Book Club — governance module panel on the public club page (managers
 * only): per-club automation toggles with offset hours and channel, plus the
 * informational always-on meeting reminder handled by the plugin core.
 *
 * @var array<string, mixed> $club
 * @var array<string, array<string, mixed>> $automations trigger_key → row
 * @var string $csrf
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$rows = [
    \App\Plugins\BookClub\Modules\GovernanceModule::TRIGGER_READING => [
        'label' => __('Scadenza lettura'),
        'help' => __('Avvisa i membri quando la fine della lettura corrente si avvicina.'),
        'icon' => 'fa-book-open',
    ],
    \App\Plugins\BookClub\Modules\GovernanceModule::TRIGGER_POLL => [
        'label' => __('Votazione in chiusura'),
        'help' => __('Avvisa i membri quando una votazione aperta sta per chiudersi.'),
        'icon' => 'fa-vote-yea',
    ],
];
$channelLabels = [
    'email' => __('Email'),
    'inapp' => __('Notifica in-app'),
    'both' => __('Email + in-app'),
];
?>
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-lg font-semibold text-gray-900 mb-1"><i class="fas fa-robot mr-2 text-gray-400"></i><?= $e(__('Automazioni')) ?></h2>
  <p class="text-sm text-gray-500 mb-4"><?= $e(__('Promemoria automatici inviati ai membri attivi dal cron di manutenzione. Ogni avviso parte una sola volta per libro o votazione.')) ?></p>

  <form method="post" action="<?= $e(url('/book-club/' . $slug . '/automations')) ?>">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="space-y-3">
      <?php foreach ($rows as $trigger => $meta): ?>
        <?php
          $auto = $automations[$trigger] ?? ['channel' => 'email', 'offset_hours' => 24, 'is_active' => 0];
          $offset = max(1, min(168, (int) ($auto['offset_hours'] ?? 24)));
        ?>
        <div class="border rounded-lg px-4 py-3">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center text-sm font-medium text-gray-900 cursor-pointer">
              <input type="checkbox" name="active[<?= $e($trigger) ?>]" value="1" class="mr-2 rounded"
                     <?= (int) ($auto['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
              <i class="fas <?= $e($meta['icon']) ?> mr-2 text-gray-400"></i><?= $e($meta['label']) ?>
            </label>
            <div class="flex items-center gap-2 text-sm">
              <label class="text-xs text-gray-400"><?= $e(__('Anticipo (ore)')) ?></label>
              <input type="number" name="offset[<?= $e($trigger) ?>]" min="1" max="168" value="<?= $offset ?>"
                     class="border border-gray-300 rounded-lg px-2 py-1 w-20 text-sm">
              <select name="channel[<?= $e($trigger) ?>]" class="border border-gray-300 rounded-lg px-2 py-1 text-sm">
                <?php foreach ($channelLabels as $value => $label): ?>
                  <option value="<?= $e($value) ?>" <?= ($auto['channel'] ?? 'email') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-1"><?= $e($meta['help']) ?></p>
        </div>
      <?php endforeach; ?>

      <!-- Informational: handled by the plugin core, not editable -->
      <div class="border rounded-lg px-4 py-3 bg-gray-50">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span class="flex items-center text-sm font-medium text-gray-500">
            <i class="fas fa-calendar-check mr-2 text-gray-400"></i><?= $e(__('Promemoria incontro')) ?>
          </span>
          <span class="text-xs text-gray-400">
            <i class="fas fa-lock mr-1"></i><?= $e(__('24 ore prima · email · gestito dal sistema')) ?>
          </span>
        </div>
        <p class="text-xs text-gray-400 mt-1"><?= $e(__('Il promemoria degli incontri è sempre attivo e viene inviato dal nucleo del plugin.')) ?></p>
      </div>
    </div>

    <button type="submit" class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
      <?= $e(__('Salva automazioni')) ?>
    </button>
  </form>
</section>
