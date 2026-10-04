<?php
declare(strict_types=1);
$plugin=$GLOBALS['plugins']['emeroteca']??null;
if (!$plugin instanceof \EmerotecaPlugin) { return; }
$mode=$plugin->contributionService()->mode();
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li><a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-home mr-1"></i><?= __('Home') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li><a href="<?= $e(url('/admin/plugins')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-puzzle-piece mr-1"></i><?= __('Plugin') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium"><?= __('Emeroteca') ?></li>
      </ol>
    </nav>
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= __('Emeroteca') ?></h1>
      <p class="text-gray-600"><?= __('Gestione di riviste, giornali e periodici: testate, annate e fascicoli.') ?></p>
    </div>
    <?php $modeReturnTo='/admin/plugins'; require __DIR__.'/article-mode.php'; ?>
  </div>
</div>
