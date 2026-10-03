<?php
/*
 * Preview of "associate the selected articles with a masthead" (#412). Core
 * chrome: page shell, breadcrumb, header, a card per block, the action row.
 */
$rows=$rows??[]; $token=$token??''; $target_title=$target_title??''; $issue_label=$issue_label??''; $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$check=static function(string $name,string $label) use($e):void{
    ?><label class="flex items-start gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" name="<?= $e($name) ?>" value="1" class="mt-0.5 w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500"><span><?= $e($label) ?></span></label><?php
};
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li><a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-home mr-1"></i><?= __('Home') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li><a href="<?= $e(url('/admin/periodicals')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-newspaper mr-1"></i><?= __('Emeroteca') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li><a href="<?= $e(url('/admin/periodicals/articles')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><?= __('Articoli') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium"><?= __('Anteprima associazione') ?></li>
      </ol>
    </nav>

    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= __('Anteprima associazione') ?></h1>
      <p class="text-gray-600"><?= __('Testata di destinazione') ?>: <strong class="text-gray-900"><?= $e($target_title) ?></strong> · <?= $e($issue_label ?: __('Solo testata')) ?></p>
    </div>

    <form method="post" action="<?= $e(url('/admin/periodicals/articles/associate')) ?>" class="space-y-8">
      <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>"><input type="hidden" name="step" value="confirm"><input type="hidden" name="token" value="<?= $e($token) ?>">

      <div class="card">
        <div class="card-header"><h2 class="form-section-title flex items-center gap-2"><i class="fas fa-file-alt text-gray-900" aria-hidden="true"></i><?= __('Articoli') ?> <span class="status-badge bg-gray-100 text-gray-700"><?= count($rows) ?></span></h2></div>
        <div class="card-body">
          <ul class="divide-y divide-gray-200">
            <?php foreach($rows as $r): ?>
            <li class="py-3">
              <p class="font-medium text-gray-900"><?= $e($r['titolo']) ?></p>
              <p class="text-sm text-gray-600"><?= $e(implode(' · ',array_filter([(string)$r['autori'],(string)$r['contenitore_titolo'],(string)$r['pagine']],static fn(string $v):bool=>$v!==''))) ?></p>
              <?php if($r['testata_id']): ?><p class="mt-1"><span class="status-badge bg-yellow-100 text-yellow-800"><?= __('Già associato alla testata') ?> <?= $e($r['testata_titolo']??'') ?></span></p><?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="form-section-title flex items-center gap-2"><i class="fas fa-check-square text-gray-900" aria-hidden="true"></i><?= __('Conferma') ?></h2></div>
        <div class="card-body space-y-3">
          <?php $check('reassign',__('Confermo anche la riassegnazione degli articoli già associati a un’altra testata.')); ?>
          <?php if(!$issue_label && array_filter($rows, static fn($r)=>!empty($r['fascicolo_id']))): ?><?php $check('detach_issues',__('Confermo la rimozione del collegamento al fascicolo per gli articoli già collocati.')); ?><?php endif; ?>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a class="btn-secondary order-2 sm:order-1" href="<?= $e(url('/admin/periodicals/articles')) ?>"><i class="fas fa-times mr-2"></i><?= __('Annulla') ?></a>
        <button class="btn-primary order-1 sm:order-2" type="submit"><i class="fas fa-link mr-2"></i><?= __('Conferma associazione') ?></button>
      </div>
    </form>
  </div>
</div>
