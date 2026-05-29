<?php
/**
 * @var array<string, mixed>|null $opera
 * @var array<int, array{id:int, nome:string}> $autori
 * @var string $pageTitle
 */
$isEdit = $opera !== null;
$action = $isEdit
    ? url('/admin/opere/' . (int) $opera['id'] . '/edit')
    : url('/admin/opere/new');
$val = static function (string $key) use ($opera): string {
    return htmlspecialchars((string) ($opera[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav class="flex items-center text-sm text-gray-500 mb-4">
      <a href="<?= htmlspecialchars(url('/admin/opere'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700"><?= __("Opere") ?></a>
      <i class="fas fa-chevron-right mx-2 text-xs text-gray-400"></i>
      <span class="text-gray-900 font-medium"><?= $isEdit ? __("Modifica") : __("Nuova Opera") ?></span>
    </nav>

    <div class="bg-white shadow rounded-lg p-6">
      <h1 class="text-xl font-bold text-gray-900 mb-6 flex items-center gap-2">
        <i class="fas fa-sitemap text-gray-600"></i>
        <?= $isEdit ? __("Modifica Opera") : __("Nuova Opera") ?>
      </h1>

      <form method="POST" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">

        <div>
          <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Titolo uniforme") ?> *</label>
          <input type="text" name="titolo_uniforme" required value="<?= $val('titolo_uniforme') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary">
        </div>

        <div>
          <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Titolo originale") ?></label>
          <input type="text" name="titolo_originale" value="<?= $val('titolo_originale') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary">
        </div>

        <div>
          <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Autore principale") ?></label>
          <select name="autore_principale_id" class="w-full border border-gray-300 rounded-lg px-3 py-2">
            <option value=""><?= __("— Nessuno —") ?></option>
            <?php foreach ($autori as $a): ?>
              <option value="<?= (int) $a['id'] ?>" <?= ($isEdit && (int) ($opera['autore_principale_id'] ?? 0) === (int) $a['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Anno creazione (da)") ?></label>
            <input type="number" name="data_creazione_da" value="<?= $val('data_creazione_da') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Anno creazione (a)") ?></label>
            <input type="number" name="data_creazione_a" value="<?= $val('data_creazione_a') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Lingua originale") ?></label>
            <input type="text" name="lingua_originale" value="<?= $val('lingua_originale') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("VIAF Work ID") ?></label>
            <input type="text" name="viaf_work_id" value="<?= $val('viaf_work_id') ?>"
                   placeholder="<?= __('es. 24604287') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2">
          </div>
        </div>

        <div>
          <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Wikidata ID") ?></label>
          <input type="text" name="wikidata_id" value="<?= $val('wikidata_id') ?>" placeholder="Q..."
                 class="w-full border border-gray-300 rounded-lg px-3 py-2">
        </div>

        <div>
          <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Note") ?></label>
          <textarea name="note" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2"><?= $val('note') ?></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-4 border-t">
          <a href="<?= htmlspecialchars(url('/admin/opere'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 text-gray-600 hover:text-gray-900"><?= __("Annulla") ?></a>
          <button type="submit" class="px-5 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg text-sm font-medium">
            <i class="fas fa-save mr-2"></i><?= $isEdit ? __("Salva modifiche") : __("Crea Opera") ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
