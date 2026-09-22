<?php
/**
 * Shared list of public articles: the emeroteca home, a masthead page and the
 * article search all render this block.
 *
 * Each row carries its image (or the catalogue's own placeholder, so a list of
 * articles reads like the catalogue next door rather than a wall of text) and
 * the three links that make the list navigable: the author, the publication
 * and each keyword, all pointing at the same article search narrowed by that
 * value — on a standalone article those fields are free text, not rows in the
 * core registries, so a filtered search is the only honest destination.
 *
 * @var array{rows: array<int, array<string, mixed>>} $articleResults
 */
$articleResults=$articleResults??['rows'=>[]]; $ae=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$articlePlaceholder=url('/uploads/copertine/placeholder.jpg');
/** Article search narrowed by one field: ['autore'|'pubblicazione'|'keyword' => value]. */
$articleFilterUrl=static fn(string $key,string $value):string=>url('/emeroteca/articoli').'?'.http_build_query([$key=>$value]);
?>
<section class="max-w-6xl mx-auto px-4 py-8"><h2 class="text-2xl font-semibold mb-5"><?= __('Articoli') ?></h2>
<?php if(!$articleResults['rows']): ?><p><?= __('Nessun articolo disponibile.') ?></p><?php endif; ?>
<ul class="divide-y"><?php foreach($articleResults['rows'] as $a): ?><li class="py-4"><div style="display:flex;gap:1rem;align-items:flex-start;">
<a href="<?= $ae(url('/emeroteca/articolo/'.(int)$a['id'])) ?>" tabindex="-1" aria-hidden="true" style="flex:0 0 auto;"><img src="<?= $ae(url(($a['copertina_url']??'')!==''?(string)$a['copertina_url']:'/uploads/copertine/placeholder.jpg')) ?>" alt="" loading="lazy" decoding="async" style="width:72px;height:96px;object-fit:cover;border-radius:.25rem;" onerror="this.onerror=null;this.src=<?= $ae(json_encode($articlePlaceholder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></a>
<div style="flex:1 1 auto;min-width:0;">
<a class="font-semibold underline" href="<?= $ae(url('/emeroteca/articolo/'.(int)$a['id'])) ?>"><?= $ae($a['titolo']) ?></a>
<?php if(($a['autori']??'')!==''): ?><p><a class="underline" href="<?= $ae($articleFilterUrl('autore',(string)$a['autori'])) ?>"><?= $ae($a['autori']) ?></a></p><?php endif; ?>
<p class="text-sm"><?php if(($a['contenitore_titolo']??'')!==''): ?><a class="underline" href="<?= $ae($articleFilterUrl('pubblicazione',(string)$a['contenitore_titolo'])) ?>"><?= $ae($a['contenitore_titolo']) ?></a><?php $rest=array_filter([$a['data_pubblicazione_testo']??'',$a['volume']??'',$a['numero']??'',$a['pagine']??'']); if($rest): ?> · <?php endif; ?><?php else: $rest=array_filter([$a['data_pubblicazione_testo']??'',$a['volume']??'',$a['numero']??'',$a['pagine']??'']); endif; ?><?= $ae(implode(' · ',$rest)) ?></p>
</div></div></li><?php endforeach; ?></ul>
<a class="inline-block underline mt-5" href="<?= $ae(url('/emeroteca/articoli').'?'.http_build_query(['q'=>$q??'','testata'=>$testata['id']??0])) ?>"><?= __('Cerca tutti gli articoli') ?></a></section>
