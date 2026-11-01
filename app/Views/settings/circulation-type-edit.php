<?php
/**
 * @var array<string, mixed> $type
 * @var array<string, mixed> $globals
 */
use App\Support\Csrf;

$csrfToken = Csrf::ensureToken();
$typeForm = $type;
$cGlobals = $globals;
?>
<div class="max-w-4xl mx-auto py-6 px-4 sm:px-6 lg:px-8 space-y-6">
  <div class="flex items-center justify-between pk-stack-mobile">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <i class="fas fa-id-badge text-gray-500"></i>
        <?= __("Modifica tipologia lettore") ?>
      </h1>
      <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars((string) ((string) $type['nome']), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <a href="<?= htmlspecialchars(url('/admin/settings?tab=circulation'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors">
      <i class="fas fa-arrow-left"></i>
      <?= __("Torna alla circolazione") ?>
    </a>
  </div>
  <form action="<?= htmlspecialchars(url('/admin/settings/circulation/types/' . (int) $type['id']), ENT_QUOTES, 'UTF-8') ?>" method="post" class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4" data-testid="circulation-type-edit">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <?php require __DIR__ . '/partials/circulation-type-fields.php'; ?>
    <div class="flex justify-end">
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
        <i class="fas fa-save"></i>
        <?= __("Salva tipologia") ?>
      </button>
    </div>
  </form>
</div>
