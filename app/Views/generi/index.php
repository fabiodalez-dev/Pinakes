<?php
/** @var array $generiPrincipali */
/** @var int $totalGeneri */
/** @var array $sottogeneri */
$csrf = App\Support\Csrf::ensureToken();
$totalPrincipali = count($generiPrincipali);
$totalSottogeneri = max(0, $totalGeneri - $totalPrincipali);
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center gap-2 text-sm text-gray-500">
        <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700"><i class="fas fa-home"></i></a></li>
        <li><i class="fas fa-chevron-right text-xs text-gray-400"></i></li>
        <li class="font-medium text-gray-900"><?= __("Generi") ?></li>
      </ol>
    </nav>

    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 class="text-3xl font-bold text-gray-900"><?= __("Generi") ?></h1>
        <p class="mt-1 text-sm text-gray-600"><?= __("Organizza generi e sottogeneri usati nel catalogo") ?></p>
        <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-600">
          <span class="status-badge bg-gray-100 text-gray-700"><?= $totalPrincipali ?> <?= __("principali") ?></span>
          <span class="status-badge bg-gray-100 text-gray-700"><?= $totalSottogeneri ?> <?= __("sottogeneri") ?></span>
          <span class="status-badge bg-gray-100 text-gray-700"><?= (int)$totalGeneri ?> <?= __("totali") ?></span>
        </div>
      </div>
      <a href="<?= htmlspecialchars(url('/admin/genres/create'), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">
        <i class="fas fa-plus mr-2"></i><?= __("Nuovo genere") ?>
      </a>
    </header>

    <?php if (!empty($_SESSION['success_message'])): ?>
      <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_message'])): ?>
      <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
      </div>
    <?php endif; ?>

    <section class="card mb-6">
      <div class="card-header">
        <h2 class="form-section-title"><i class="fas fa-bolt mr-2 text-gray-500"></i><?= __("Aggiunta rapida") ?></h2>
      </div>
      <form method="post" action="<?= htmlspecialchars(url('/admin/genres/create'), ENT_QUOTES, 'UTF-8') ?>" class="genre-quick-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div>
          <label for="nome_genere" class="form-label"><?= __("Nome") ?></label>
          <input id="nome_genere" name="nome" class="form-input" placeholder="<?= __('es. Noir mediterraneo') ?>" required aria-required="true">
        </div>
        <div>
          <label for="parent_id_genere" class="form-label"><?= __("Genere padre (opzionale)") ?></label>
          <select id="parent_id_genere" name="parent_id" class="form-input">
            <option value=""><?= __("– Nessuno –") ?></option>
            <?php foreach ($generiPrincipali as $g): ?>
              <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i><?= __("Salva") ?></button>
      </form>
    </section>

    <section class="card genre-list-card">
      <div class="card-header">
        <div>
          <h2 class="form-section-title"><i class="fas fa-sitemap mr-2 text-gray-500"></i><?= __("Struttura dei generi") ?></h2>
          <p class="mt-1 text-sm text-gray-600"><?= __("Apri una voce per modificarla, unirla o gestirne i sottogeneri") ?></p>
        </div>
      </div>

      <?php if (empty($generiPrincipali)): ?>
        <div class="py-12 text-center">
          <i class="fas fa-tags mb-4 text-4xl text-gray-300"></i>
          <h3 class="font-semibold text-gray-900"><?= __("Nessun genere trovato") ?></h3>
          <p class="mt-1 text-sm text-gray-600"><?= __("Inizia creando il primo genere letterario") ?></p>
        </div>
      <?php else: ?>
        <div class="genre-tree">
          <?php foreach ($generiPrincipali as $genere): ?>
            <article class="genre-group">
              <div class="genre-parent-row">
                <div class="min-w-0">
                  <h3 class="font-semibold text-gray-900 break-words"><?= htmlspecialchars($genere['nome']) ?></h3>
                  <p class="mt-1 text-xs text-gray-500"><?= (int)$genere['children_count'] ?> <?= __("sottogeneri") ?></p>
                </div>
                <a href="<?= htmlspecialchars(url('/admin/genres/' . (int)$genere['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary genre-detail-button">
                  <i class="fas fa-eye mr-2"></i><span><?= __("Dettagli") ?></span>
                </a>
              </div>

              <?php if (!empty($sottogeneri[$genere['id']])): ?>
                <div class="genre-children">
                  <?php foreach ($sottogeneri[$genere['id']] as $sottogenere): ?>
                    <a href="<?= htmlspecialchars(url('/admin/genres/' . (int)$sottogenere['id']), ENT_QUOTES, 'UTF-8') ?>" class="genre-child-link">
                      <span class="min-w-0 break-words"><i class="fas fa-tag mr-2 text-xs text-gray-400"></i><?= htmlspecialchars($sottogenere['nome']) ?></span>
                      <i class="fas fa-chevron-right ml-3 text-xs text-gray-400"></i>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="genre-empty-child"><?= __("Nessun sottogenere definito") ?></p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<style>
.genre-quick-form { display:grid; grid-template-columns:minmax(0, 1fr) minmax(14rem, .6fr) auto; align-items:end; gap:1rem; }
.genre-tree { display:grid; gap:1rem; }
.genre-group { border:1px solid #e5e7eb; border-radius:.5rem; overflow:hidden; }
.genre-parent-row { align-items:center; background:#f9fafb; display:flex; gap:1rem; justify-content:space-between; padding:1rem; }
.genre-children { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:.75rem; padding:1rem; }
.genre-child-link { align-items:center; border:1px solid #e5e7eb; border-radius:.5rem; color:#374151; display:flex; font-size:.875rem; justify-content:space-between; min-width:0; padding:.75rem; }
.genre-child-link:hover { background:#f9fafb; border-color:#d1d5db; }
.genre-empty-child { color:#6b7280; font-size:.875rem; padding:1rem; text-align:center; }
@media (max-width:767px) {
  .genre-quick-form { grid-template-columns:1fr; }
  .genre-quick-form .btn-primary { justify-content:center; width:100%; }
  .genre-children { grid-template-columns:1fr; }
}
@media (max-width:479px) {
  .genre-list-card { padding:1rem; }
  .genre-parent-row { align-items:flex-start; }
  .genre-detail-button { flex:0 0 2.75rem; padding-left:.75rem; padding-right:.75rem; width:2.75rem !important; }
  .genre-detail-button span { display:none; }
  .genre-detail-button i { margin-right:0; }
}
</style>
