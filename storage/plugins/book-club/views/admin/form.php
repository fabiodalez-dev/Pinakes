<?php
/**
 * Book Club — create/edit club form.
 *
 * @var array<string, mixed>|null $club
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$isEdit = $club !== null;
$settings = $isEdit ? ($club['settings'] ?? []) : [];
$action = $isEdit ? url('/admin/book-club/' . (int) $club['id'] . '/edit') : url('/admin/book-club/new');
?>
<div class="p-6 max-w-3xl mx-auto">
  <div class="mb-6">
    <a href="<?= $e(url('/admin/book-club')) ?>" class="text-sm text-gray-500 hover:text-gray-700">
      <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Book Club')) ?>
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">
      <?= $isEdit ? $e(__('Modifica club')) : $e(__('Nuovo club')) ?>
    </h1>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= $e($action) ?>" class="bg-white rounded-xl shadow p-6 space-y-5">
    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1"><?= $e(__('Nome del club')) ?> *</label>
      <input type="text" name="name" required maxlength="190" value="<?= $e($club['name'] ?? '') ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1"><?= $e(__('Descrizione')) ?></label>
      <textarea name="description" rows="4"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"><?= $e($club['description'] ?? '') ?></textarea>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1"><?= $e(__('Regolamento del club')) ?></label>
      <textarea name="rules" rows="4"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"><?= $e($club['rules'] ?? '') ?></textarea>
      <p class="text-xs text-gray-400 mt-1"><?= $e(__('Mostrato nella pagina del club, visibile a tutti i membri.')) ?></p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1"><?= $e(__('Privacy')) ?></label>
        <select name="privacy" class="w-full border border-gray-300 rounded-lg px-3 py-2">
          <?php foreach (['public' => __('Pubblico — chiunque può unirsi'), 'private' => __('Privato — adesione con approvazione'), 'invite' => __('Su invito — solo con link di invito'), 'hidden' => __('Nascosto — invisibile nelle liste')] as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= ($club['privacy'] ?? 'public') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1"><?= $e(__('Colore')) ?></label>
        <input type="color" name="color" value="<?= $e($club['color'] ?? '#4f46e5') ?>"
               class="w-full h-10 border border-gray-300 rounded-lg px-1 py-1">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1"><?= $e(__('Numero massimo membri')) ?></label>
        <input type="number" name="max_members" min="1" value="<?= $club !== null && $club['max_members'] !== null ? (int) $club['max_members'] : '' ?>"
               placeholder="<?= $e(__('illimitato')) ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2">
      </div>
    </div>

    <?php $modules = $modules ?? []; ?>
    <?php if ($modules !== []): ?>
      <div class="border-t pt-5">
        <label class="block text-sm font-medium text-gray-700 mb-2"><?= $e(__('Moduli attivi per questo club')) ?></label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <?php
            // No explicit list yet (new club or pre-modules club) → defaults.
            $enabledSlugs = null;
            if ($isEdit && array_key_exists('modules', $settings) && is_array($settings['modules'])) {
                $enabledSlugs = $settings['modules'];
            }
          ?>
          <?php foreach ($modules as $module): ?>
            <?php $on = $enabledSlugs === null ? $module->defaultEnabled() : in_array($module->slug(), $enabledSlugs, true); ?>
            <label class="flex items-start text-sm text-gray-700 border rounded-lg px-3 py-2">
              <input type="checkbox" name="modules[]" value="<?= $e($module->slug()) ?>" class="mr-2 mt-0.5 rounded" <?= $on ? 'checked' : '' ?>>
              <span>
                <span class="font-medium"><?= $e($module->label()) ?></span>
                <?php if ($module->description() !== ''): ?>
                  <span class="block text-xs text-gray-400"><?= $e($module->description()) ?></span>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="border-t pt-5 space-y-3">
      <label class="flex items-center text-sm text-gray-700">
        <input type="checkbox" name="moderate_proposals" value="1" class="mr-2 rounded" <?= !empty($settings['moderate_proposals']) ? 'checked' : '' ?>>
        <?= $e(__('Modera le proposte: un moderatore deve approvarle prima che diventino visibili')) ?>
      </label>
      <div class="flex items-center gap-3">
        <label class="text-sm text-gray-700"><?= $e(__('Massimo proposte aperte per membro')) ?></label>
        <input type="number" name="max_proposals_per_member" min="1" style="width:6rem"
               value="<?= isset($settings['max_proposals_per_member']) && $settings['max_proposals_per_member'] !== null ? (int) $settings['max_proposals_per_member'] : '' ?>"
               placeholder="<?= $e(__('illimitato')) ?>"
               class="border border-gray-300 rounded-lg px-3 py-1.5">
      </div>
      <?php if ($isEdit): ?>
        <label class="flex items-center text-sm text-gray-700">
          <input type="checkbox" name="is_active" value="1" class="mr-2 rounded" <?= (int) ($club['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          <?= $e(__('Club attivo')) ?>
        </label>
      <?php else: ?>
        <input type="hidden" name="is_active" value="1">
      <?php endif; ?>
    </div>

    <div class="flex items-center justify-between pt-2">
      <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
        <?= $isEdit ? $e(__('Salva modifiche')) : $e(__('Crea club')) ?>
      </button>
    </div>
  </form>

  <?php if ($isEdit): ?>
    <form method="post" action="<?= $e(url('/admin/book-club/' . (int) $club['id'] . '/delete')) ?>" class="mt-4"
          onsubmit="return confirm('<?= $e(__('Eliminare questo club? I dati restano nel database ma il club non sarà più visibile.')) ?>');">
      <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
      <button type="submit" class="text-sm text-red-600 hover:underline"><?= $e(__('Elimina club')) ?></button>
    </form>
  <?php endif; ?>
</div>
