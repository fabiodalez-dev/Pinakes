<?php use App\Support\Csrf; $csrf = Csrf::ensureToken(); ?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="/admin/generi" class="text-gray-500 hover:text-gray-700 transition-colors">
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
          <div>
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center gap-3">
              <i class="fas fa-tag text-blue-600"></i>
              <?php echo App\Support\HtmlHelper::e($genere['nome'] ?? 'Genere'); ?>
            </h1>
            <p class="text-gray-600 dark:text-gray-300">
              <?php if (!empty($genere['parent_nome'])): ?>
                Sottogenere di <strong class="text-blue-600 dark:text-blue-400"><?php echo App\Support\HtmlHelper::e($genere['parent_nome']); ?></strong>
              <?php else: ?>
                Genere principale
              <?php endif; ?>
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6">
      <!-- Children list -->
      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-gray-200/60 dark:border-gray-700/60">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
          <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <i class="fas fa-sitemap text-primary"></i>
            Sottogeneri
          </h2>
        </div>
        <div class="p-6">
          <?php if (empty($children)): ?>
            <div class="text-center py-10 text-gray-500 dark:text-gray-400">
              Nessun sottogenere.
            </div>
          <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <?php foreach ($children as $c): ?>
                <a href="/admin/generi/<?php echo (int)$c['id']; ?>" class="flex items-center justify-between p-3 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:shadow-md transition">
                  <span class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo App\Support\HtmlHelper::e($c['nome']); ?></span>
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
            Aggiungi Sottogenere
          </h2>
        </div>
        <form method="post" action="/admin/generi/crea" class="p-6 space-y-4">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="parent_id" value="<?php echo (int)($genere['id'] ?? 0); ?>">
          <div>
            <label for="nome_sottogenere" class="form-label">Nome sottogenere</label>
            <input id="nome_sottogenere" name="nome" class="form-input" placeholder="es. Urban fantasy" required aria-required="true">
          </div>
          <div class="flex justify-end">
            <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Salva</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
