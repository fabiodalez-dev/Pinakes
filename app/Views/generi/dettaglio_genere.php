<?php
/** @var array $genere */
/** @var array $children */
/** @var array $allGeneri */
use App\Support\Csrf;
use App\Support\HtmlHelper;
$csrf = Csrf::ensureToken();
$genereId = (int)($genere['id'] ?? 0);
$genereName = $genere['nome'] ?? 'Genere';
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/generi'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-tags mr-1"></i>Generi
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">Dettaglio</li>
      </ol>
    </nav>
    <div class="mb-8 fade-in">
      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-gray-200/60 dark:border-gray-700/60 p-6">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center gap-3">
              <i class="fas fa-tag text-blue-600"></i>
              <span id="genre-name-display"><?= HtmlHelper::e($genereName) ?></span>
            </h1>
            <p class="text-gray-600 dark:text-gray-300">
              <?php if (!empty($genere['parent_nome'])): ?>
                <?= __("Sottogenere di") ?> <strong class="text-blue-600 dark:text-blue-400"><?= HtmlHelper::e($genere['parent_nome']) ?></strong>
              <?php else: ?>
                <?= __("Genere principale") ?>
              <?php endif; ?>
            </p>
          </div>
          <div class="flex items-center gap-2">
            <button id="btn-edit-genre" class="btn-secondary text-sm" title="<?= __('Modifica') ?>">
              <i class="fas fa-edit mr-1"></i><?= __("Modifica") ?>
            </button>
          </div>
        </div>

        <!-- Inline edit form (hidden by default) -->
        <form id="edit-genre-form" method="post" action="<?= htmlspecialchars(url("/admin/generi/{$genereId}/modifica"), ENT_QUOTES, 'UTF-8') ?>" class="mt-4 hidden">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <div class="space-y-3">
            <div>
              <label for="edit_nome" class="form-label"><?= __("Nome genere") ?></label>
              <input id="edit_nome" name="nome" type="text" class="form-input" value="<?= htmlspecialchars($genereName, ENT_QUOTES, 'UTF-8') ?>" required aria-required="true">
            </div>
            <div>
              <label for="edit_parent_id" class="form-label"><?= __("Genere superiore") ?></label>
              <select id="edit_parent_id" name="parent_id" class="form-input">
                <option value=""><?= __("— Nessuno (genere principale) —") ?></option>
                <?php foreach ($allGeneri as $g): ?>
                  <?php if ((int)$g['id'] === $genereId) continue; ?>
                  <?php if ($g['parent_id'] !== null) continue; ?>
                  <option value="<?= (int)$g['id'] ?>"<?= ((int)($genere['parent_id'] ?? 0) === (int)$g['id']) ? ' selected' : '' ?>><?= HtmlHelper::e($g['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex items-center gap-3">
              <button type="submit" class="btn-primary text-sm">
                <i class="fas fa-check mr-1"></i><?= __("Salva") ?>
              </button>
              <button type="button" id="btn-cancel-edit" class="btn-secondary text-sm">
                <i class="fas fa-times mr-1"></i><?= __("Annulla") ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6">
      <!-- Children list -->
      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-gray-200/60 dark:border-gray-700/60">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <i class="fas fa-sitemap text-primary"></i>
            <?= __("Sottogeneri") ?>
          </h2>
        </div>
        <div class="p-6">
          <?php if (empty($children)): ?>
            <div class="text-center py-10 text-gray-500 dark:text-gray-400">
              <?= __("Nessun sottogenere.") ?>
            </div>
          <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <?php foreach ($children as $c): ?>
                <a href="<?= htmlspecialchars(url('/admin/generi/' . (int)$c['id']), ENT_QUOTES, 'UTF-8') ?>" class="flex items-center justify-between p-3 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:shadow-md transition">
                  <span class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= HtmlHelper::e($c['nome']) ?></span>
                  <i class="fas fa-chevron-right text-gray-400"></i>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick add child -->
      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-gray-200/60 dark:border-gray-700/60">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <i class="fas fa-plus text-primary"></i>
            <?= __("Aggiungi Sottogenere") ?>
          </h2>
        </div>
        <form method="post" action="<?= htmlspecialchars(url('/admin/generi/crea'), ENT_QUOTES, 'UTF-8') ?>" class="p-6 space-y-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="parent_id" value="<?= $genereId ?>">
          <div>
            <label for="nome_sottogenere" class="form-label"><?= __("Nome sottogenere") ?></label>
            <input id="nome_sottogenere" name="nome" class="form-input" placeholder="<?= __('es. Urban fantasy') ?>" required aria-required="true">
          </div>
          <div class="flex justify-end">
            <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i><?= __("Salva") ?></button>
          </div>
        </form>
      </div>

      <!-- Merge genre -->
      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-amber-200/60 dark:border-amber-700/60">
        <div class="p-6">
          <h2 class="text-lg font-semibold text-amber-700 dark:text-amber-400 flex items-center gap-2 mb-3">
            <i class="fas fa-compress-arrows-alt"></i>
            <?= __("Unisci con altro genere") ?>
          </h2>
          <p class="text-sm text-gray-600 dark:text-gray-400 mb-4"><?= __("Sposta tutti i libri e sottogeneri di questo genere nel genere selezionato, poi elimina questo genere.") ?></p>
          <form id="merge-genre-form" method="post" action="<?= htmlspecialchars(url("/admin/generi/{$genereId}/unisci"), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="flex items-end gap-3">
              <div class="flex-1">
                <label for="merge_target_id" class="form-label"><?= __("Genere di destinazione") ?></label>
                <select id="merge_target_id" name="target_id" class="form-input" required>
                  <option value=""><?= __("— Seleziona —") ?></option>
                  <?php
                  $grouped = [];
                  foreach ($allGeneri as $g) {
                      if ((int)$g['id'] === $genereId) continue;
                      if ($g['parent_id'] === null) {
                          $grouped[(int)$g['id']]['_self'] = $g;
                      } else {
                          $grouped[(int)$g['parent_id']]['_children'][] = $g;
                      }
                  }
                  foreach ($grouped as $gid => $group):
                      if (isset($group['_self'])):
                          $parentG = $group['_self'];
                  ?>
                  <optgroup label="<?= HtmlHelper::e($parentG['nome']) ?>">
                    <option value="<?= (int)$parentG['id'] ?>"><?= HtmlHelper::e($parentG['nome']) ?></option>
                    <?php if (!empty($group['_children'])): ?>
                      <?php foreach ($group['_children'] as $childG): ?>
                        <option value="<?= (int)$childG['id'] ?>">  └ <?= HtmlHelper::e($childG['nome']) ?></option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </optgroup>
                  <?php
                      endif;
                  endforeach;
                  ?>
                </select>
              </div>
              <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors text-sm whitespace-nowrap">
                <i class="fas fa-compress-arrows-alt mr-1"></i><?= __("Unisci") ?>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Delete genre -->
      <?php if (empty($children)): ?>
      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-red-200/60 dark:border-red-700/60">
        <div class="p-6">
          <h2 class="text-lg font-semibold text-red-700 dark:text-red-400 flex items-center gap-2 mb-3">
            <i class="fas fa-trash-alt"></i>
            <?= __("Elimina genere") ?>
          </h2>
          <p class="text-sm text-gray-600 dark:text-gray-400 mb-4"><?= __("Questa azione elimina il genere in modo permanente. Possibile solo se non ha sottogeneri e non è usato da nessun libro.") ?></p>
          <form method="post" action="<?= htmlspecialchars(url("/admin/generi/{$genereId}/elimina"), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode(__('Sei sicuro di voler eliminare questo genere?')), ENT_QUOTES, 'UTF-8') ?>);">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm">
              <i class="fas fa-trash-alt mr-1"></i><?= __("Elimina") ?>
            </button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const editBtn = document.getElementById('btn-edit-genre');
  const cancelBtn = document.getElementById('btn-cancel-edit');
  const editForm = document.getElementById('edit-genre-form');
  const nameDisplay = document.getElementById('genre-name-display');

  if (editBtn && editForm && cancelBtn) {
    editBtn.addEventListener('click', function() {
      editForm.classList.remove('hidden');
      editBtn.classList.add('hidden');
      document.getElementById('edit_nome').focus();
    });
    cancelBtn.addEventListener('click', function() {
      editForm.classList.add('hidden');
      editBtn.classList.remove('hidden');
    });
  }

  var mergeForm = document.getElementById('merge-genre-form');
  if (mergeForm) {
    mergeForm.addEventListener('submit', function(e) {
      var target = document.getElementById('merge_target_id');
      var targetName = target.options[target.selectedIndex].textContent.trim();
      var msgPrefix = <?= json_encode(__("Sei sicuro di voler unire questo genere con")) ?>;
      var msgSuffix = <?= json_encode(__("Questa azione è irreversibile.")) ?>;
      if (!confirm(msgPrefix + ' "' + targetName + '"? ' + msgSuffix)) {
        e.preventDefault();
      }
    });
  }
});
</script>
