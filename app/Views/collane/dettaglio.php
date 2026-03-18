<?php
/** @var string $collana */
/** @var array $books */
/** @var bool $hasParentWork */
use App\Support\HtmlHelper;
use App\Support\Csrf;
$csrfToken = Csrf::ensureToken();
?>

<div class="max-w-5xl mx-auto">
  <!-- Breadcrumb -->
  <nav class="mb-4 text-sm text-gray-500">
    <a href="<?= htmlspecialchars(url('/admin/collane'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-indigo-600"><?= __("Collane") ?></a>
    <span class="mx-1">/</span>
    <span class="text-gray-900 font-medium"><?= HtmlHelper::e($collana) ?></span>
  </nav>

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
      <i class="fas fa-layer-group text-indigo-500"></i>
      <?= HtmlHelper::e($collana) ?>
      <span class="text-base font-normal text-gray-500">(<?= count($books) ?> <?= __("libri") ?>)</span>
    </h1>
  </div>

  <!-- Messages -->
  <?php if (!empty($_SESSION['success_message'])): ?>
  <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">
    <i class="fas fa-check-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['success_message']) ?>
  </div>
  <?php unset($_SESSION['success_message']); endif; ?>

  <?php if (!empty($_SESSION['error_message'])): ?>
  <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm">
    <i class="fas fa-exclamation-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['error_message']) ?>
  </div>
  <?php unset($_SESSION['error_message']); endif; ?>

  <!-- Books Table -->
  <?php if (!empty($books)): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-16">#</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Titolo") ?></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Autore") ?></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ISBN</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php foreach ($books as $b): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 text-sm font-semibold text-indigo-600">
            <?= HtmlHelper::e($b['numero_serie'] ?? '') ?>
          </td>
          <td class="px-4 py-3 text-sm">
            <a href="<?= htmlspecialchars(url('/admin/libri/' . (int)$b['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-primary hover:underline font-medium">
              <?= HtmlHelper::e($b['titolo']) ?>
            </a>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= HtmlHelper::e($b['autore'] ?? '') ?></td>
          <td class="px-4 py-3 text-sm text-gray-500"><?= HtmlHelper::e(($b['isbn13'] ?? '') ?: ($b['isbn10'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    <!-- Rename -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
      <h3 class="text-sm font-semibold text-gray-700 mb-3">
        <i class="fas fa-pen text-gray-400 mr-1"></i> <?= __("Rinomina collana") ?>
      </h3>
      <form method="post" action="<?= htmlspecialchars(url('/admin/collane/rinomina'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="old_name" value="<?= HtmlHelper::e($collana) ?>">
        <input type="text" name="new_name" value="<?= HtmlHelper::e($collana) ?>" class="form-input w-full mb-2" required>
        <button type="submit" class="btn-primary text-sm px-4 py-2"><?= __("Rinomina") ?></button>
      </form>
    </div>

    <!-- Merge -->
    <div class="bg-white rounded-xl shadow-sm border border-amber-200 p-5">
      <h3 class="text-sm font-semibold text-amber-700 mb-3">
        <i class="fas fa-compress-arrows-alt text-amber-400 mr-1"></i> <?= __("Unisci con altra collana") ?>
      </h3>
      <form method="post" action="<?= htmlspecialchars(url('/admin/collane/unisci'), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('<?= __("Sei sicuro? Tutti i libri verranno spostati nella collana di destinazione.") ?>')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="source" value="<?= HtmlHelper::e($collana) ?>">
        <input type="text" name="target" placeholder="<?= HtmlHelper::e(__('Nome collana di destinazione')) ?>" class="form-input w-full mb-2" required>
        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white text-sm px-4 py-2 rounded"><?= __("Unisci") ?></button>
      </form>
    </div>

    <!-- Create Parent Work -->
    <?php if (!$hasParentWork && count($books) >= 2): ?>
    <div class="bg-white rounded-xl shadow-sm border border-indigo-200 p-5 md:col-span-2">
      <h3 class="text-sm font-semibold text-indigo-700 mb-3">
        <i class="fas fa-book-open text-indigo-400 mr-1"></i> <?= __("Crea opera multi-volume") ?>
      </h3>
      <p class="text-xs text-gray-500 mb-3"><?= __("Crea un libro padre che raccoglie tutti i volumi di questa collana.") ?></p>
      <form method="post" action="<?= htmlspecialchars(url('/admin/collane/crea-opera'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="collana" value="<?= HtmlHelper::e($collana) ?>">
        <div class="flex gap-2">
          <input type="text" name="parent_title" value="<?= HtmlHelper::e($collana) ?>" class="form-input flex-1" placeholder="<?= HtmlHelper::e(__('Titolo dell\'opera completa')) ?>" required>
          <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white text-sm px-4 py-2 rounded whitespace-nowrap"><?= __("Crea opera") ?></button>
        </div>
      </form>
    </div>
    <?php endif; ?>

  </div>
</div>
