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
        <li class="text-gray-900 font-medium">Nuovo</li>
      </ol>
    </nav>
    <div class="mb-8 fade-in">
      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-gray-200/60 dark:border-gray-700/60 p-6">
        <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center gap-3">
          <i class="fas fa-tags text-blue-600"></i>
          Crea Nuovo Genere
        </h1>
        <p class="text-gray-600 dark:text-gray-300">Aggiungi un genere o un sottogenere alla struttura.</p>
      </div>
    </div>

    <form method="post" action="/admin/generi/crea" class="space-y-6">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

      <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-lg border border-gray-200/60 dark:border-gray-700/60 p-6">
        <div class="grid grid-cols-1 gap-6">
          <div>
            <label for="nome" class="form-label">Nome genere <span class="text-red-500">*</span></label>
            <input id="nome" name="nome" required aria-required="true" class="form-input" placeholder="es. Fantasy contemporaneo" />
          </div>
          <div>
            <label for="parent_id" class="form-label">Genere padre (opzionale)</label>
            <select id="parent_id" name="parent_id" class="form-input">
              <option value="">Nessun padre (genere principale)</option>
              <?php foreach (($generiParentOptions ?? []) as $g): if (($g['parent_id'] ?? null) === null): ?>
                <option value="<?php echo (int)$g['id']; ?>">
                  <?php echo htmlspecialchars($g['nome'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endif; endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mt-6 flex gap-3 justify-end">
          <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Salva</button>
        </div>
      </div>
    </form>
  </div>
  
</div>
