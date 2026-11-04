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
    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-2xl font-bold text-gray-900 mb-3 flex items-center gap-3"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

          <?= __("Modifica Libro") ?>
        <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
          <i class="fas fa-book-open text-blue-600"></i>
          <?= __("Modifica Libro") ?>

      <!-- Breadcrumb -->



    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?>

        <p class="text-gray-600 text-base mb-4">
          <?= __("Aggiorna i dettagli del libro:") ?> <a href="<?= htmlspecialchars(url('/admin/books/' . (int)($book['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="text-blue-600 hover:text-blue-800 hover:underline font-semibold transition-colors"><strong><?php echo htmlspecialchars($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></a>
        </p>

        <div class="flex items-center text-sm text-gray-500">
          <i class="fas fa-info-circle mr-2"></i>
          <?= __("I campi con * sono obbligatori") ?>
        </div>
      <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
        <ol>
          <li>
            <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
          </li>

          <li>
            <a href="<?= htmlspecialchars(url('/admin/books'), ENT_QUOTES, 'UTF-8') ?>">
              <?= __("Libri") ?>
            </a>
          </li>

          <li aria-current="page"><?= __("Modifica") ?></li>
        </ol>
      </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <!-- Quick Actions Bar: only for a book that has a cover to show (a card
         saying there is none, or one whose image file is gone, is just an
         empty box at the top of the form). -->
    <?php $currentCover = $book['copertina_url'] ?? ($book['copertina'] ?? ''); ?>
    <?php if (!empty($currentCover)): ?>
    <div class="grid grid-cols-1 gap-4 mb-6" data-current-cover>
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-900 mb-2">
          <i class="fas fa-image text-gray-900 mr-2"></i>
          <?= __("Copertina Attuale") ?>
        </h3>
        <div class="flex items-center gap-3">
            <img src="<?= htmlspecialchars(url($currentCover), ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?php echo HtmlHelper::e(($book['titolo'] ?? 'Libro') . ' - Copertina attuale'); ?>"
                 class="w-12 h-16 object-cover rounded border"
                 onerror="this.style.display='none'; this.closest('[data-current-cover]').style.display = 'none';" />
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Main Form -->
    <?php
      $mode = 'edit';
      include __DIR__ . '/partials/book_form.php';
    ?>
  </div>
</div>
