<!-- Modern Genres Management Interface -->
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <a href="<?= htmlspecialchars(url('/admin/generi'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-900 hover:text-gray-900">
            <i class="fas fa-tags mr-1"></i><?= __("Generi") ?>
          </a>
        </li>
      </ol>
    </nav>
    <!-- Modern Header -->
    <div class="mb-8 fade-in">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 flex items-center">
          <i class="fas fa-tags text-blue-600 mr-3"></i>
          <?= __("Gestione Generi e Sottogeneri") ?>
        </h1>
        <p class="text-sm text-gray-600 mt-2"><?= __("Organizza e gestisci i generi letterari della biblioteca") ?></p>
      </div>
    </div>

    <!-- Success Messages -->
    <?php if(!empty($_SESSION['success_message'])): ?>
      <div class="mb-6 p-4 bg-green-100 text-green-800 rounded-lg border border-green-200 slide-in-up">
        <div class="flex items-center gap-2">
          <i class="fas fa-check-circle"></i>
          <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
        </div>
      </div>
    <?php endif; ?>

    <?php if(!empty($_SESSION['error_message'])): ?>
      <div class="mb-6 p-4 bg-red-100 text-red-800 rounded-lg border border-red-200 slide-in-up">
        <div class="flex items-center gap-2">
          <i class="fas fa-exclamation-triangle"></i>
          <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
        </div>
      </div>
    <?php endif; ?>

    <!-- Quick Add Card -->
    <div class="card mb-6">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-plus text-primary"></i>
          <?= __("Aggiungi Genere Rapido") ?>
        </h2>
      </div>
      <div class="card-body">
        <?php $csrf = App\Support\Csrf::ensureToken(); ?>
        <form method="post" action="<?= htmlspecialchars(url('/admin/generi/crea'), ENT_QUOTES, 'UTF-8') ?>" class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="md:col-span-2">
            <label for="nome_genere" class="form-label"><?= __("Nome") ?></label>
            <input id="nome_genere" name="nome" class="form-input" placeholder="<?= __('es. Noir mediterraneo') ?>" required aria-required="true">
          </div>
          <div>
            <label for="parent_id_genere" class="form-label"><?= __("Genere padre (opz.)") ?></label>
            <select id="parent_id_genere" name="parent_id" class="form-input">
              <option value=""><?= __("– Nessuno –") ?></option>
              <?php foreach ($generiPrincipali as $g): ?>
                <option value="<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['nome']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="md:col-span-3 flex justify-end">
            <button type="submit" class="btn-primary">
              <i class="fas fa-save mr-2"></i>
              <?= __("Salva") ?>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Generi Principali") ?></p>
            <p class="text-2xl font-bold text-gray-900"><?php echo count($generiPrincipali); ?></p>
          </div>
          <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-layer-group text-blue-600 text-xl"></i>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Sottogeneri") ?></p>
            <p class="text-2xl font-bold text-gray-900"><?php echo $totalGeneri - count($generiPrincipali); ?></p>
          </div>
          <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-tags text-green-600 text-xl"></i>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Totale Generi") ?></p>
            <p class="text-2xl font-bold text-gray-900"><?php echo $totalGeneri; ?></p>
          </div>
          <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-chart-bar text-purple-600 text-xl"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Create New Button -->
    <div class="mb-6">
      <a href="<?= htmlspecialchars(url('/admin/generi/crea'), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary inline-flex items-center">
        <i class="fas fa-plus mr-2"></i>
        <?= __("Crea Nuovo Genere") ?>
      </a>
    </div>

    <!-- Generi List Card -->
    <div class="card">
      <p class="text-sm text-gray-600"><?= __("Visualizzazione gerarchica di generi e sottogeneri") ?></p>
          </div>
          <div class="card-body">
            <?php if (empty($generiPrincipali)): ?>
              <div class="text-center py-12">
                <i class="fas fa-tags text-6xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-medium text-gray-900 mb-2"><?= __("Nessun genere trovato") ?></h3>
                <p class="text-gray-600 mb-6"><?= __("Inizia creando il primo genere letterario") ?></p>
                <a href="<?= htmlspecialchars(url('/admin/generi/crea'), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary inline-flex items-center">
                  <i class="fas fa-plus mr-2"></i>
              <?= __("Crea Primo Genere") ?>
            </a>
          </div>
        <?php else: ?>
          <div class="space-y-4">
            <?php foreach ($generiPrincipali as $genere): ?>
              <div class="border border-gray-200 rounded-xl overflow-hidden">
                <!-- Parent Genere -->
                <div class="bg-gray-50 p-4 flex items-center justify-between">
                  <div class="flex items-center space-x-3">
                    <div class="p-2 bg-blue-100 rounded-lg">
                      <i class="fas fa-layer-group text-blue-600"></i>
                    </div>
                    <div>
                      <h3 class="font-semibold text-gray-900">
                        <?php echo htmlspecialchars($genere['nome']); ?>
                      </h3>
                      <p class="text-sm text-gray-500">
                        <?= __("Genere principale") ?> • <?php echo $genere['children_count']; ?> <?= __("sottogeneri") ?>
                      </p>
                    </div>
                  </div>
                  <div class="flex items-center space-x-2">
                    <a href="<?= htmlspecialchars(url('/admin/generi/' . (int)$genere['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn-outline btn-sm">
                      <i class="fas fa-eye mr-1"></i>
                      <?= __("Dettagli") ?>
                    </a>
                  </div>
                </div>

                <!-- Sottogeneri -->
                <?php if (!empty($sottogeneri[$genere['id']])): ?>
                  <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                      <?php foreach ($sottogeneri[$genere['id']] as $sottogenere): ?>
                        <div class="flex items-center justify-between p-3 bg-white border border-gray-200 rounded-lg hover:shadow-sm transition-all">
                          <div class="flex items-center space-x-2">
                            <i class="fas fa-tag text-green-500 text-sm"></i>
                            <span class="text-sm font-medium text-gray-900">
                              <?php echo htmlspecialchars($sottogenere['nome']); ?>
                            </span>
                          </div>
                          <a href="<?= htmlspecialchars(url('/admin/generi/' . (int)$sottogenere['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn-outline btn-sm">
                            <i class="fas fa-external-link-alt mr-1"></i><?= __("Dettagli") ?>
                          </a>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="p-4 text-center text-gray-500 text-sm">
                    <?= __("Nessun sottogenere definito") ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Custom Styles for Enhanced UI -->
<style>
.fade-in {
  animation: fadeIn 0.5s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* Responsive design for mobile */
@media (max-width: 768px) {
  .grid-cols-1.md\:grid-cols-3 {
    grid-template-columns: 1fr;
  }
  
  .grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3 {
    grid-template-columns: 1fr;
  }
}
</style>
