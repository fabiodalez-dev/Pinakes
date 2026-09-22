<?php
/**
 * Public article search.
 *
 * Besides the free term the page answers three narrowing filters — author,
 * publication, keyword — which are where the links on an article page lead.
 * An active filter is shown as a removable chip: a visitor who arrived from
 * "everything by this author" must be able to see why the list is short and
 * get back to the whole corpus in one click.
 *
 * Every variable below is supplied by PublicController::articles(); the
 * fallbacks exist because a view file is a public surface of the plugin and
 * must not fatal when required with less than that.
 */
$term=$term??''; $testata=$testata??0; $rows=$rows??[]; $page=$page??1; $pages=$pages??1; $total=$total??count($rows); $filters=$filters??[];
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$filterLabels=['autore'=>__('Autore'),'pubblicazione'=>__('Pubblicazione'),'keyword'=>__('Parola chiave')];
?>
<main class="max-w-6xl mx-auto px-4 py-10"><h1 class="text-3xl font-bold mb-6"><?= __('Articoli') ?></h1>
<form class="flex flex-wrap gap-3" method="get"><label for="article-q" class="sr-only"><?= __('Cerca titolo, autore o pubblicazione') ?></label><input id="article-q" class="form-input" name="q" maxlength="200" value="<?= $e($term) ?>"><input type="hidden" name="testata" value="<?= (int)$testata ?>"><?php foreach($filters as $key=>$value): ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach; ?><button class="btn-primary"><?= __('Cerca') ?></button></form>
<?php if($filters): ?><p class="mt-4 text-sm"><?php foreach($filters as $key=>$value): $remaining=array_diff_key($filters,[$key=>true]); ?><span class="mr-3"><?= $e(($filterLabels[$key]??$key).': '.$value) ?> <?php $rest=array_filter(['q'=>$term,'testata'=>(int)$testata]+$remaining); ?><a class="underline" href="<?= $e(url('/emeroteca/articoli').($rest?'?'.http_build_query($rest):'')) ?>" aria-label="<?= $e(__('Rimuovi il filtro')) ?>">&times;</a></span><?php endforeach; ?>
<a class="underline" href="<?= $e(url('/emeroteca/articoli')) ?>"><?= __('Mostra tutti gli articoli') ?></a></p><?php endif; ?>
<p class="mt-4 text-sm text-gray-600"><?= $e(__n('%d articolo', '%d articoli', (int)$total)) ?></p>
<?php $articleResults=['rows'=>$rows]; $q=$term; $testataId=$testata; $testata=['id'=>$testata]; require __DIR__.'/article-results.php'; ?>
<nav class="flex gap-3" aria-label="<?= $e(__('Paginazione')) ?>"><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?><a class="underline p-3" <?= $p===$page?'aria-current="page"':'' ?> href="<?= $e(url('/emeroteca/articoli').'?'.http_build_query(['q'=>$term,'testata'=>$testataId,'page'=>$p]+$filters)) ?>"><?= $p ?></a><?php endfor; ?></nav></main>
