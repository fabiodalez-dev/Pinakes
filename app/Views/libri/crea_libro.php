<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="/admin/libri" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-book mr-1"></i><?= __("Libri") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <?= __("Nuovo") ?>
        </li>
      </ol>
    </nav>

    <!-- Header -->
    <div class="mb-8 fade-in">
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= __("Aggiungi Nuovo Libro") ?></h1>
      <p class="text-gray-600"><?= __("Compila i dettagli del libro per aggiungerlo alla biblioteca") ?></p>
    </div>

    <!-- ISBN Import Card -->
    <div class="card mb-8 slide-in-up">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-barcode text-primary"></i>
          <?= __("Importa da ISBN") ?>
        </h2>
        <p class="text-sm text-gray-600 mt-1"><?= __("Usa i servizi online per precompilare automaticamente i dati del libro") ?></p>
      </div>
      <div class="card-body">
        <div class="form-grid-2">
          <div>
            <label class="form-label"><?= __("Codice ISBN o EAN") ?></label>
            <input id="importIsbn" type="text" class="form-input" placeholder="<?= __('es. 9788842935780') ?>" />
          </div>
          <div class="flex items-end">
            <button type="button" id="btnImportIsbn" class="btn-primary w-full">
              <i class="fas fa-download mr-2"></i>
              <?= __("Importa Dati") ?>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Form -->
    <?php
      $mode = 'create';
      $book = [
        'stato' => 'Disponibile',
        'copie_totali' => 1,
        'copie_disponibili' => 1,
      ];
      include __DIR__ . '/partials/book_form.php';
    ?>
  </div>
</div>
