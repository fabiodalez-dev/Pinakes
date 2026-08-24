<?php
/**
 * Edit form for an FRBR/LRM Expression (espressione).
 *
 * @var array<string, mixed> $espressione
 * @var array<int, array{id:int, nome:string}> $autori
 * @var string $pageTitle
 */
$operaId = (int) ($espressione['opera_id'] ?? 0);
$action = url('/admin/opere/espressioni/' . (int) $espressione['id'] . '/edit');
$val = static function (string $key) use ($espressione): string {
    return htmlspecialchars((string) ($espressione[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
/** @var array<string,string> $tipiEspressione tipo => label */
$tipiEspressione = [
    'testo'            => __("Testo (originale)"),
    'traduzione'      => __("Traduzione"),
    'revisione'       => __("Revisione"),
    'adattamento'     => __("Adattamento"),
    'edizione_critica' => __("Edizione critica"),
    'audio'           => __("Audio"),
    'altro'           => __("Altro"),
];
$currentTipo = (string) ($espressione['tipo_espressione'] ?? 'testo');
$autoreSelect = static function (string $name, int $current) use ($autori): string {
    $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="w-full border border-gray-300 rounded-lg px-3 py-2">';
    $html .= '<option value="">' . htmlspecialchars(__("— Nessuno —"), ENT_QUOTES, 'UTF-8') . '</option>';
    foreach ($autori as $a) {
        $sel = ((int) $a['id'] === $current) ? ' selected' : '';
        $html .= '<option value="' . (int) $a['id'] . '"' . $sel . '>' . htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html . '</select>';
};
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav class="flex items-center text-sm text-gray-500 mb-4">
      <a href="<?= htmlspecialchars(url('/admin/opere'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700"><?= __("Opere") ?></a>
      <i class="fas fa-chevron-right mx-2 text-xs text-gray-400"></i>
      <a href="<?= htmlspecialchars(url('/admin/opere/' . $operaId), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700"><?= __("Opera") ?></a>
      <i class="fas fa-chevron-right mx-2 text-xs text-gray-400"></i>
      <span class="text-gray-900 font-medium"><?= __("Modifica espressione") ?></span>
    </nav>

    <div class="bg-white shadow rounded-lg p-6">
      <h1 class="text-xl font-bold text-gray-900 mb-6 flex items-center gap-2">
        <i class="fas fa-stream text-gray-600"></i>
        <?= __("Modifica espressione") ?>
      </h1>

      <form method="POST" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Tipo di espressione") ?></label>
            <select name="tipo_espressione" class="w-full border border-gray-300 rounded-lg px-3 py-2">
              <?php foreach ($tipiEspressione as $tipoKey => $tipoLabel): ?>
                <option value="<?= htmlspecialchars($tipoKey, ENT_QUOTES, 'UTF-8') ?>" <?= $currentTipo === $tipoKey ? 'selected' : '' ?>>
                  <?= htmlspecialchars($tipoLabel, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Lingua") ?></label>
            <input type="text" name="lingua" value="<?= $val('lingua') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2">
          </div>
        </div>

        <div>
          <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Titolo dell'espressione") ?></label>
          <input type="text" name="titolo_espressione" value="<?= $val('titolo_espressione') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2">
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Anno") ?></label>
            <input type="number" name="anno_espressione" value="<?= $val('anno_espressione') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2">
          </div>
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Traduttore") ?></label>
            <?= $autoreSelect('traduttore_autore_id', (int) ($espressione['traduttore_autore_id'] ?? 0)) ?>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Curatore") ?></label>
            <?= $autoreSelect('curatore_autore_id', (int) ($espressione['curatore_autore_id'] ?? 0)) ?>
          </div>
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Revisore") ?></label>
            <?= $autoreSelect('revisore_autore_id', (int) ($espressione['revisore_autore_id'] ?? 0)) ?>
          </div>
        </div>

        <div>
          <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Note") ?></label>
          <textarea name="note" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2"><?= $val('note') ?></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-4 border-t">
          <a href="<?= htmlspecialchars(url('/admin/opere/' . $operaId), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 text-gray-600 hover:text-gray-900"><?= __("Annulla") ?></a>
          <button type="submit" class="px-5 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg text-sm font-medium">
            <i class="fas fa-save mr-2"></i><?= __("Salva espressione") ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
