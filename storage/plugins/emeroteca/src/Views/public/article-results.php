<?php
/**
 * Shared list of public articles: the emeroteca home, a masthead page and the
 * article search all render this block.
 *
 * Each row carries its image — its own, else the masthead's, else the
 * catalogue's placeholder, resolved by ContributionService::coverUrl() so this
 * block and the article page cannot disagree — and
 * the links that make the list navigable. Confirmed authors open the shared author archive; unlinked names open a
 * catalogue filter; publication and keywords narrow the article search. Citation names
 * remain free text and are never automatically merged into authority records.
 *
 * @var array{rows: array<int, array<string, mixed>>} $articleResults
 */
$articleResults=$articleResults??['rows'=>[]]; $ae=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$articlePlaceholder=url('/uploads/copertine/placeholder.jpg');
/** Article search narrowed by one field: ['autore'|'pubblicazione'|'keyword' => value]. */
$articleFilterUrl=static fn(string $key,string $value):string=>($key==='autore' ? route_path('catalog') : url('/emeroteca/articoli')).'?'.http_build_query([$key=>$value]);
// Plugin classes have no autoloader scope and a view must not depend on the
// controller having loaded them: require the service before reading it.
require_once __DIR__.'/../../Services/ContributionService.php';
/**
 * Emptiness for a rendered metadata part: the stored value, not PHP's notion
 * of truth. A periodical's pilot issue really is numbered "0", and a bare
 * array_filter() would drop it from the line.
 */
$articleKeep=static fn(mixed $value):bool=>trim((string)$value)!=='';
// Same rule for the image: coverUrl() returns '' and only '' for "no image", so
// the test is `!== ''`. `?:` would read the perfectly valid path "0" as absent
// — the falsy-value mistake this file already carries a helper to avoid.
?>
<?php
/*
 * Grid variant — the catalogue's cover grid (public/assets/catalog-pages.css),
 * used by the article search page, which supplies its own heading, result count
 * and pagination. The list below stays the default for the emeroteca home and
 * the masthead pages. Same links, same image resolution, same metadata rules.
 */
if(($articleResultsVariant??'list')==='grid'): ?>
<?php if(!$articleResults['rows']): ?>
<div class="empty-state">
    <i class="fas fa-search empty-state-icon" aria-hidden="true"></i>
    <h2 class="empty-state-title"><?= __('Nessun risultato trovato') ?></h2>
    <p class="empty-state-text"><?= __('Prova a modificare i filtri o la tua ricerca') ?></p>
    <a class="btn-cta btn-cta-sm" href="<?= $ae(url('/emeroteca/articoli')) ?>"><i class="fas fa-redo mr-2" aria-hidden="true"></i><?= __('Mostra tutti gli articoli') ?></a>
</div>
<?php else: ?>
<div class="books-grid emeroteca-articles-grid">
<?php foreach($articleResults['rows'] as $a): $articleUrl=url('/emeroteca/articolo/'.(int)$a['id']); $articleCover=\App\Plugins\Emeroteca\Services\ContributionService::coverUrl($a); ?>
<article class="book-card" data-record-kind="article" data-article-id="<?= (int)$a['id'] ?>">
    <div class="book-image-container">
        <a href="<?= $ae($articleUrl) ?>" tabindex="-1" aria-hidden="true"><img class="book-image" src="<?= $ae(url($articleCover!==''?$articleCover:'/uploads/copertine/placeholder.jpg')) ?>" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=<?= $ae(json_encode($articlePlaceholder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></a>
    </div>
    <div class="book-content">
        <h3 class="book-title"><a href="<?= $ae($articleUrl) ?>"><?= $ae($a['titolo']) ?></a></h3>
        <?php if(($a['sottotitolo']??'')!==''): ?><p class="book-subtitle"><?= $ae($a['sottotitolo']) ?></p><?php endif; ?>
        <?php if(($a['autori']??'')!==''): $rowAuthors=\App\Plugins\Emeroteca\Services\ContributionService::authorLinks($a); ?><p class="book-author"><?php if($rowAuthors): foreach($rowAuthors as $i=>$an): ?><?= $i?'; ':'' ?><a href="<?= $ae(($an['id'] !== null ? route_path('author').'/'.$an['id'] : $articleFilterUrl('autore',$an['name']))) ?>"><?= $ae($an['name']) ?></a><?php endforeach; else: ?><?= $ae($a['autori']) ?><?php endif; ?></p><?php endif; ?>
        <?php $rest=array_filter([$a['data_pubblicazione_testo']??'',$a['volume']??'',$a['numero']??'',$a['pagine']??''],$articleKeep); ?>
        <p class="book-meta"><?php if(($a['contenitore_titolo']??'')!==''): ?><a href="<?= $ae($articleFilterUrl('pubblicazione',(string)$a['contenitore_titolo'])) ?>"><?= $ae($a['contenitore_titolo']) ?></a><?= $rest?' · ':'' ?><?php endif; ?><?= $ae(implode(' · ',$rest)) ?></p>
        <div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $ae($articleUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __('Dettagli') ?></a></div>
    </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php return; endif; ?>
<section class="py-8"><h2 class="text-2xl font-semibold mb-5"><?= __('Articoli') ?></h2>
<?php if(!$articleResults['rows']): ?><p><?= __('Nessun articolo disponibile.') ?></p><?php endif; ?>
<ul class="divide-y"><?php foreach($articleResults['rows'] as $a): ?><li class="py-4"><div style="display:flex;gap:1rem;align-items:flex-start;">
<a href="<?= $ae(url('/emeroteca/articolo/'.(int)$a['id'])) ?>" tabindex="-1" aria-hidden="true" style="flex:0 0 auto;"><?php $articleCover=\App\Plugins\Emeroteca\Services\ContributionService::coverUrl($a); ?><img src="<?= $ae(url($articleCover!==''?$articleCover:'/uploads/copertine/placeholder.jpg')) ?>" alt="" loading="lazy" decoding="async" style="width:72px;height:96px;object-fit:cover;border-radius:.25rem;" onerror="this.onerror=null;this.src=<?= $ae(json_encode($articlePlaceholder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></a>
<div style="flex:1 1 auto;min-width:0;">
<a class="font-semibold underline" href="<?= $ae(url('/emeroteca/articolo/'.(int)$a['id'])) ?>"><?= $ae($a['titolo']) ?></a>
<?php /* On these records the subtitle routinely carries half the meaning of
         the title; hiding it in the list makes two different articles look
         like the same one. */ ?>
<?php if(($a['sottotitolo']??'')!==''): ?><span class="text-sm text-gray-600"> : <?= $ae($a['sottotitolo']) ?></span><?php endif; ?>
<?php if(($a['autori']??'')!==''): $rowAuthors=\App\Plugins\Emeroteca\Services\ContributionService::authorLinks($a); ?><p><?php if($rowAuthors): foreach($rowAuthors as $i=>$an): ?><?= $i?'; ':'' ?><a class="underline" href="<?= $ae(($an['id'] !== null ? route_path('author').'/'.$an['id'] : $articleFilterUrl('autore',$an['name']))) ?>"><?= $ae($an['name']) ?></a><?php endforeach; else: ?><?= $ae($a['autori']) ?><?php endif; ?></p><?php endif; ?>
<p class="text-sm"><?php if(($a['contenitore_titolo']??'')!==''): ?><a class="underline" href="<?= $ae($articleFilterUrl('pubblicazione',(string)$a['contenitore_titolo'])) ?>"><?= $ae($a['contenitore_titolo']) ?></a><?php $rest=array_filter([$a['data_pubblicazione_testo']??'',$a['volume']??'',$a['numero']??'',$a['pagine']??''],$articleKeep); if($rest): ?> · <?php endif; ?><?php else: $rest=array_filter([$a['data_pubblicazione_testo']??'',$a['volume']??'',$a['numero']??'',$a['pagine']??''],$articleKeep); endif; ?><?= $ae(implode(' · ',$rest)) ?></p>
</div></div></li><?php endforeach; ?></ul>
<a class="inline-block underline mt-5" href="<?= $ae(url('/emeroteca/articoli').'?'.http_build_query(['q'=>$q??'','testata'=>$testata['id']??0])) ?>"><?= __('Cerca tutti gli articoli') ?></a></section>
