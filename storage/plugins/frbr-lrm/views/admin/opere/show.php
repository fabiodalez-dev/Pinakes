<?php
/**
 * @var array<string, mixed> $opera
 * @var array<int, array<string, mixed>> $edizioni
 * @var array<int, array<string, mixed>> $espressioni
 * @var array<int, array{id:int, nome:string}> $autori
 * @var string $pageTitle
 */
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
$autori = $autori ?? [];
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav class="flex items-center text-sm text-gray-500 mb-4">
      <a href="<?= htmlspecialchars(url('/admin/opere'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700"><?= __("Opere") ?></a>
      <i class="fas fa-chevron-right mx-2 text-xs text-gray-400"></i>
      <span class="text-gray-900 font-medium"><?= htmlspecialchars((string) $opera['titolo_uniforme'], ENT_QUOTES, 'UTF-8') ?></span>
    </nav>

    <div class="bg-white shadow rounded-lg p-6 mb-6">
      <div class="flex items-start justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) $opera['titolo_uniforme'], ENT_QUOTES, 'UTF-8') ?></h1>
          <?php if (!empty($opera['titolo_originale'])): ?>
            <p class="text-gray-500 italic mt-1"><?= htmlspecialchars((string) $opera['titolo_originale'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
          <p class="text-sm text-gray-600 mt-2">
            <?php if (!empty($opera['autore_nome'])): ?>
              <i class="fas fa-user mr-1"></i><?= htmlspecialchars((string) $opera['autore_nome'], ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
            <?php if (!empty($opera['lingua_originale'])): ?>
              &nbsp;·&nbsp; <i class="fas fa-language mr-1"></i><?= htmlspecialchars((string) $opera['lingua_originale'], ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </p>
        </div>
        <div class="flex items-center gap-2">
          <?php if (!empty($opera['slug'])): ?>
            <a href="<?= htmlspecialchars(url('/opera/' . (string) $opera['slug']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="px-3 py-2 text-sm text-gray-600 hover:text-gray-900 border rounded-lg"><i class="fas fa-external-link-alt mr-1"></i><?= __("Vedi pagina pubblica") ?></a>
          <?php endif; ?>
          <a href="<?= htmlspecialchars(url('/admin/opere/' . (int) $opera['id'] . '/edit'), ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 text-sm bg-gray-800 text-white rounded-lg hover:bg-gray-700"><i class="fas fa-edit mr-1"></i><?= __("Modifica") ?></a>
        </div>
      </div>
    </div>

    <!-- Manifestations (edizioni) -->
    <div class="bg-white shadow rounded-lg p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
        <i class="fas fa-book text-gray-500"></i><?= __("Edizioni (Manifestation)") ?>
        <span class="text-sm font-normal bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full"><?= count($edizioni) ?></span>
      </h2>
      <?php if (empty($edizioni)): ?>
        <p class="text-gray-500 text-sm"><?= __("Nessuna edizione collegata. Apri un libro e usa «Collega a Opera».") ?></p>
      <?php else: ?>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($edizioni as $ed): ?>
            <li class="py-2 flex items-center justify-between">
              <a href="<?= htmlspecialchars(url('/admin/books/' . (int) $ed['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-900 hover:text-primary">
                <?= htmlspecialchars((string) $ed['titolo'], ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($ed['anno_pubblicazione'])): ?><span class="text-gray-400">(<?= (int) $ed['anno_pubblicazione'] ?>)</span><?php endif; ?>
              </a>
              <span class="text-xs text-gray-500"><?= htmlspecialchars((string) ($ed['editore'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Expressions (espressioni) -->
    <div class="bg-white shadow rounded-lg p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
        <i class="fas fa-stream text-gray-500"></i><?= __("Espressioni (Expression)") ?>
        <span class="text-sm font-normal bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full"><?= count($espressioni) ?></span>
      </h2>
      <?php if (empty($espressioni)): ?>
        <p class="text-gray-500 text-sm mb-4"><?= __("Nessuna espressione registrata (traduzioni, revisioni, adattamenti).") ?></p>
      <?php else: ?>
        <ul class="divide-y divide-gray-100 mb-4">
          <?php foreach ($espressioni as $es): ?>
            <?php $tipoKey = (string) $es['tipo_espressione']; ?>
            <li class="py-2 flex items-center justify-between gap-2">
              <span class="text-sm text-gray-700">
                <span class="inline-block bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-xs mr-2"><?= htmlspecialchars($tipiEspressione[$tipoKey] ?? $tipoKey, ENT_QUOTES, 'UTF-8') ?></span>
                <?= htmlspecialchars((string) ($es['titolo_espressione'] ?? $es['lingua'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($es['traduttore_nome'])): ?><span class="text-gray-400">· <?= __("trad.") ?> <?= htmlspecialchars((string) $es['traduttore_nome'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
              </span>
              <span class="flex items-center gap-2 shrink-0">
                <a href="<?= htmlspecialchars(url('/admin/opere/espressioni/' . (int) $es['id'] . '/edit'), ENT_QUOTES, 'UTF-8') ?>" class="text-xs text-gray-500 hover:text-gray-800"><i class="fas fa-edit mr-1"></i><?= __("Modifica") ?></a>
                <form method="POST" action="<?= htmlspecialchars(url('/admin/opere/espressioni/' . (int) $es['id'] . '/delete'), ENT_QUOTES, 'UTF-8') ?>"
                      data-swal-confirm="<?= htmlspecialchars(__("Eliminare questa espressione?"), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">
                  <button type="submit" class="text-xs text-red-600 hover:text-red-700"><i class="fas fa-trash mr-1"></i><?= __("Elimina") ?></button>
                </form>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <!-- Add espressione -->
      <details class="border border-gray-200 rounded-lg">
        <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-lg">
          <i class="fas fa-plus mr-2 text-gray-500"></i><?= __("Aggiungi espressione") ?>
        </summary>
        <form method="POST" action="<?= htmlspecialchars(url('/admin/opere/' . (int) $opera['id'] . '/espressioni'), ENT_QUOTES, 'UTF-8') ?>" class="p-4 space-y-4 border-t border-gray-100">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Tipo di espressione") ?></label>
              <select name="tipo_espressione" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                <?php foreach ($tipiEspressione as $tipoKey => $tipoLabel): ?>
                  <option value="<?= htmlspecialchars($tipoKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tipoLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Lingua") ?></label>
              <input type="text" name="lingua" class="w-full border border-gray-300 rounded-lg px-3 py-2">
            </div>
          </div>
          <div>
            <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Titolo dell'espressione") ?></label>
            <input type="text" name="titolo_espressione" class="w-full border border-gray-300 rounded-lg px-3 py-2">
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Anno") ?></label>
              <input type="number" name="anno_espressione" class="w-full border border-gray-300 rounded-lg px-3 py-2">
            </div>
            <div>
              <label class="form-label block text-sm font-medium text-gray-700 mb-1"><?= __("Traduttore") ?></label>
              <select name="traduttore_autore_id" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                <option value=""><?= __("— Nessuno —") ?></option>
                <?php foreach ($autori as $a): ?>
                  <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="flex items-center justify-end pt-2">
            <button type="submit" class="px-5 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg text-sm font-medium">
              <i class="fas fa-save mr-2"></i><?= __("Salva espressione") ?>
            </button>
          </div>
        </form>
      </details>
    </div>

    <!-- Danger zone -->
    <form method="POST" action="<?= htmlspecialchars(url('/admin/opere/' . (int) $opera['id'] . '/delete'), ENT_QUOTES, 'UTF-8') ?>"
          data-swal-confirm="<?= htmlspecialchars(__("Eliminare questa opera? Le edizioni collegate verranno scollegate ma NON eliminate."), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="px-4 py-2 text-sm text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
        <i class="fas fa-trash mr-2"></i><?= __("Elimina Opera") ?>
      </button>
    </form>
  </div>
</div>
