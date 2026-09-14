<?php $articleResults=$articleResults??['rows'=>[]]; $ae=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<section class="max-w-6xl mx-auto px-4 py-8"><h2 class="text-2xl font-semibold mb-5"><?= __('Articoli') ?></h2>
<?php if(!$articleResults['rows']): ?><p><?= __('Nessun articolo disponibile.') ?></p><?php endif; ?>
<ul class="divide-y"><?php foreach($articleResults['rows'] as $a): ?><li class="py-4"><a class="font-semibold underline" href="<?= $ae(url('/emeroteca/articolo/'.(int)$a['id'])) ?>"><?= $ae($a['titolo']) ?></a><p><?= $ae($a['autori']) ?></p><p class="text-sm"><?= $ae(implode(' · ',array_filter([$a['contenitore_titolo'],$a['data_pubblicazione_testo'],$a['volume'],$a['numero'],$a['pagine']]))) ?></p></li><?php endforeach; ?></ul>
<a class="inline-block underline mt-5" href="<?= $ae(url('/emeroteca/articoli').'?'.http_build_query(['q'=>$q??'','testata'=>$testata['id']??0])) ?>"><?= __('Cerca tutti gli articoli') ?></a></section>
