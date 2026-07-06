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
<section class="bc-card">
  <div class="bc-section-header mb-1">
    <i class="fas fa-robot"></i>
    <h2><?= $e(__('Automazioni')) ?></h2>
  </div>
  <p class="bc-muted mb-4"><?= $e(__('Promemoria automatici inviati ai membri attivi dal cron di manutenzione. Ogni avviso parte una sola volta per libro o votazione.')) ?></p>

  <form method="post" action="<?= $e(url('/book-club/' . $slug . '/automations')) ?>">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <div class="d-flex flex-column gap-3">
      <?php foreach ($rows as $trigger => $meta): ?>
        <?php
          $auto = $automations[$trigger] ?? ['channel' => 'email', 'offset_hours' => 24, 'is_active' => 0];
          $offset = max(1, min(168, (int) ($auto['offset_hours'] ?? 24)));
        ?>
        <div class="border rounded-3 px-3 py-3">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <label class="d-flex align-items-center gap-2 fw-semibold mb-0" style="cursor: pointer">
              <input type="checkbox" name="active[<?= $e($trigger) ?>]" value="1" class="form-check-input mt-0"
                     <?= (int) ($auto['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
              <i class="fas <?= $e($meta['icon']) ?> text-muted"></i><?= $e($meta['label']) ?>
            </label>
            <div class="d-flex align-items-center gap-2">
              <label class="form-label bc-muted small mb-0"><?= $e(__('Anticipo (ore)')) ?></label>
              <input type="number" name="offset[<?= $e($trigger) ?>]" min="1" max="168" value="<?= $offset ?>"
                     class="form-control form-control-sm" style="width: 5.5rem">
              <select name="channel[<?= $e($trigger) ?>]" class="form-select form-select-sm w-auto">
                <?php foreach ($channelLabels as $value => $label): ?>
                  <option value="<?= $e($value) ?>" <?= ($auto['channel'] ?? 'email') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="bc-muted small mt-1 mb-0"><?= $e($meta['help']) ?></p>
        </div>
      <?php endforeach; ?>

      <!-- Informational: handled by the plugin core, not editable -->
      <div class="border rounded-3 px-3 py-3 bg-light">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
          <span class="d-flex align-items-center gap-2 fw-semibold text-muted">
            <i class="fas fa-calendar-check"></i><?= $e(__('Promemoria incontro')) ?>
          </span>
          <span class="bc-muted small">
            <i class="fas fa-lock me-1"></i><?= $e(__('24 ore prima · email · gestito dal sistema')) ?>
          </span>
        </div>
        <p class="bc-muted small mt-1 mb-0"><?= $e(__('Il promemoria degli incontri è sempre attivo e viene inviato dal nucleo del plugin.')) ?></p>
      </div>
    </div>

    <button type="submit" class="bc-btn mt-4">
      <?= $e(__('Salva automazioni')) ?>
    </button>
  </form>
</section>
