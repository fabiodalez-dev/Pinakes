<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
$book = $libro ?? [];
if (!isset($book['copie_totali'])) $book['copie_totali'] = 1;
if (!isset($book['copie_disponibili'])) $book['copie_disponibili'] = 1;
if (!isset($book['stato'])) $book['stato'] = 'Disponibile';
if (!isset($book['posizione_progressiva']) && isset($book['posizione_id'])) {
    $book['posizione_progressiva'] = (int)$book['posizione_id'];
}
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Header -->
    <div class="mb-8 fade-in">
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
            <a href="/admin/libri" class="text-gray-500 hover:text-gray-700 transition-colors">
              <i class="fas fa-book mr-1"></i>Libri
            </a>
          </li>
          <li>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
          </li>
          <li class="text-gray-900 font-medium">__("Modifica")</li>
        </ol>
      </nav>
      
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h1 class="text-2xl font-bold text-gray-900 mb-3 flex items-center gap-3">
          <i class="fas fa-book-open text-blue-600"></i>
          Modifica Libro
        </h1>
        <p class="text-gray-600 text-base mb-4">
          Aggiorna i dettagli del libro: <a href="/admin/libri/<?php echo (int)($book['id'] ?? 0); ?>" class="text-blue-600 hover:text-blue-800 hover:underline font-semibold transition-colors"><strong><?php echo HtmlHelper::e($book['titolo'] ?? ''); ?></strong></a>
        </p>
        
        <div class="flex items-center text-sm text-gray-500">
          <i class="fas fa-info-circle mr-2"></i>
          I campi con * sono obbligatori
        </div>
      </div>
    </div>

    <!-- Quick Actions Bar -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-900 mb-2">
          <i class="fas fa-barcode text-primary mr-2"></i>
          Aggiorna da ISBN
        </h3>
        <div class="flex gap-2">
          <input id="importIsbn" class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm" 
                 placeholder="<?= __('es. 978-88-429-3578-0') ?>" 
                 value="<?php echo HtmlHelper::e($book['isbn13'] ?? $book['isbn10'] ?? ''); ?>" />
          <button type="button" id="btnImportIsbn" class="px-4 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-blue-700 transition">
            <i class="fas fa-sync-alt mr-1"></i>
            Aggiorna Dati
          </button>
        </div>
      </div>
      
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-900 mb-2">
          <i class="fas fa-image text-primary mr-2"></i>
          Copertina Attuale
        </h3>
        <div class="flex items-center gap-3">
          <?php $currentCover = $book['copertina_url'] ?? ($book['copertina'] ?? ''); ?>
          <?php if (!empty($currentCover)): ?>
            <img src="<?php echo HtmlHelper::e($currentCover); ?>"
                 alt="<?php echo HtmlHelper::e(($book['titolo'] ?? 'Libro') . ' - Copertina attuale'); ?>"
                 class="w-12 h-16 object-cover rounded border"
                 onerror="this.style.display='none'" />
          <?php else: ?>
            <span class="text-sm text-gray-500">Nessuna copertina caricata</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Main Form -->
    <?php
      $mode = 'edit';
      include __DIR__ . '/partials/book_form.php';
    ?>
  </div>
</div>
