<?php
/**
 * Public page of a standalone article.
 *
 * Confirmed author links open the common author archive. Unlinked legacy names
 * open a name search; publication and keywords narrow the article search.
 * Free-text credits never create authority records automatically.
 *
 * Input $article: array<string, mixed>
 * Input $neighbours: array{prev: ?array<string,mixed>, next: ?array<string,mixed>}
 * Input $relatedTestata: list<array<string,mixed>>
 * Input $relatedAuthor: list<array<string,mixed>>
 * Input $relatedAuthorName: string
 */
$article=$article??[]; $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
require_once dirname(__DIR__, 2) . '/Support/CodeLists.php';
$articlePlaceholder=url('/uploads/copertine/placeholder.jpg');
$articleFilterUrl=static fn(string $key,string $value):string=>($key==='autore' ? route_path('catalog') : url('/emeroteca/articoli')).'?'.http_build_query([$key=>$value]);
// Plugin classes have no autoloader scope and a view must not depend on the
// controller having loaded them: require the service before reading it.
require_once __DIR__.'/../../Services/ContributionService.php';
require_once __DIR__.'/../../Support/CitationFormatter.php';
/** Shared identities or unlinked credit names, one link each. */
$articleAuthors=\App\Plugins\Emeroteca\Services\ContributionService::authorLinks($article);
/** Free-text keyword list: comma-separated by convention, one link each. */
$articleKeywords=array_values(array_filter(array_map('trim', explode(',', (string)($article['keywords']??''))), static fn(string $k):bool=>$k!==''));
/** Own image, else the masthead's, else '' — one owner for the rule. */
$articleCover=\App\Plugins\Emeroteca\Services\ContributionService::coverUrl($article);
/** danMARC2 856. One owner decides whether it exists and whether it may be a link. */
$articleResource=\App\Plugins\Emeroteca\Services\ContributionService::resource($article,true);
$articleHasPdf=!empty($article['pdf_path']) && !empty($article['pdf_pubblico']);
/** The reader may open at most one primary action; the PDF is the library's own copy and wins. */
$articleResourceIsPrimary=$articleResource!==null && $articleResource['linkable'] && !$articleHasPdf;
$articleCitations=\App\Plugins\Emeroteca\Support\CitationFormatter::all($article);
$articleParts=\App\Plugins\Emeroteca\Support\CitationFormatter::parts($article);
/**
 * A stored ISO code rendered in the READER's language, not the cataloguer's.
 * `dan` is "Danish" to Uwe and "danese" to an Italian; the word "Dansk" typed
 * into the box would have been "Dansk" to both, which is why the column holds
 * a code. Without intl the code itself is shown — honest, if terse.
 */
$articleCodeLabel=static function(string $code,bool $region):string{
    if($code===''){return '';}
    if(!class_exists(\Locale::class)){return $code;}
    $locale=\App\Support\I18n::getLocale();
    $label=$region?\Locale::getDisplayRegion('und_'.$code,$locale):\Locale::getDisplayLanguage($code,$locale);
    return ($label===''||$label===$code)?$code:$label;
};
$neighbours=$neighbours??['prev'=>null,'next'=>null];
$relatedTestata=$relatedTestata??[];
$relatedAuthor=$relatedAuthor??[];
$corePartials=dirname(__DIR__, 6).'/app/Views/frontend/partials';
$catalogPageStyles=true;
$bookDetailStyles=true;
/** Where the article sits: masthead and issue, when it was placed in them. */
$articleTestataId=(int)($article['testata_id']??0);
$articleTestataTitle=trim((string)($article['testata_titolo']??''));
$articleFascicoloId=(int)($article['fascicolo_id']??0);
$articleIssueLabel=$articleFascicoloId>0 && trim((string)($article['fascicolo_numero']??''))!==''
    ? sprintf(__('n. %s'),(string)$article['fascicolo_numero']).(trim((string)($article['fascicolo_anno']??''))!=='' ? ' ('.(int)$article['fascicolo_anno'].')' : '')
    : '';
$articleTestataUrl=$articleTestataId>0 ? url('/emeroteca/'.$articleTestataId) : '';
$articleIssueUrl=$articleIssueLabel!=='' ? url('/emeroteca/fascicolo/'.$articleFascicoloId) : '';
$articleAuthorHref=static fn(array $an):string=>$an['id']!==null ? route_path('author').'/'.$an['id'] : $articleFilterUrl('autore',$an['name']);
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.10.0')) ?>">
<?php
// ── Hero: the same "scheda" as a book ──────────────────────────────────────
$kicker='<span class="book-media-type"><i class="far fa-file-lines mr-1" aria-hidden="true"></i>'.$e(\App\Plugins\Emeroteca\Services\ContributionService::materialType($article)).'</span>';
if($articleTestataUrl!=='' && $articleTestataTitle!==''){
    $kicker.='<span class="book-kicker-separator" aria-hidden="true">·</span><span class="book-hero-publishers"><a href="'.$e($articleTestataUrl).'">'.$e($articleTestataTitle).'</a></span>';
}
$byline='';
if(!empty($article['autori'])){
    if($articleAuthors){
        foreach($articleAuthors as $an){
            $byline.='<a href="'.$e($articleAuthorHref($an)).'" class="no-underline"><span class="author-item">'.$e($an['name']).'</span></a>';
        }
    } else {
        $byline.='<span class="author-item">'.$e($article['autori']).'</span>';
    }
}
// "In {testata}, n. X (anno), pp. a–b": the placement, every part a way up.
$placement=[];
if($articleTestataUrl!=='' && $articleTestataTitle!==''){
    $placement[]='<a href="'.$e($articleTestataUrl).'">'.$e($articleTestataTitle).'</a>';
} elseif(!empty($article['contenitore_titolo'])){
    $placement[]='<a href="'.$e($articleFilterUrl('pubblicazione',(string)$article['contenitore_titolo'])).'">'.$e($article['contenitore_titolo']).'</a>';
}
if($articleIssueUrl!==''){
    $placement[]='<a href="'.$e($articleIssueUrl).'">'.$e($articleIssueLabel).'</a>';
} else {
    foreach(['data_pubblicazione_testo','volume','numero'] as $key){ if(trim((string)($article[$key]??''))!==''){ $placement[]=$e($article[$key]); } }
}
if(trim((string)($article['pagine']??''))!==''){ $placement[]=$e($article['pagine']); }
$extra=$placement!==[] ? '<p class="resource-placement">'.$e(__('In')).' '.implode(', ',$placement).'</p>' : '';

$resourceCover=url($articleCover!==''?$articleCover:'/uploads/copertine/placeholder.jpg');
$resourceCoverKind='cover';
// Blur only a real image of this article or of its issue: a masthead logo
// repeated as a backdrop reads as a pattern, the placeholder as nothing.
$resourceCoverBlur=$articleCover!=='' && $articleCover!==trim((string)($article['testata_logo_url']??''));
$resourceCoverAlt='';
$resourceKickerHtml=$kicker;
$resourceTitle=(string)($article['titolo']??'');
$resourceSubtitle=(string)($article['sottotitolo']??'');
$resourceBylineHtml=$byline;
$resourceExtraHtml=$extra;
$breadcrumbItems=[['label'=>__('Home'),'href'=>url('/')],['label'=>__('Emeroteca'),'href'=>url('/emeroteca')]];
if($articleTestataUrl!=='' && $articleTestataTitle!==''){
    $breadcrumbItems[]=['label'=>$articleTestataTitle,'href'=>$articleTestataUrl];
    if($articleIssueUrl!==''){ $breadcrumbItems[]=['label'=>$articleIssueLabel,'href'=>$articleIssueUrl]; }
} else {
    $breadcrumbItems[]=['label'=>__('Articoli'),'href'=>url('/emeroteca/articoli')];
}
$breadcrumbItems[]=['label'=>(string)($article['titolo']??'')];
include $corePartials.'/resource-hero.php';
?>
<?php /* data-articolo-id is what the OpenURL resolver's injected script looks
         for: finding it, it fetches the article's COinS and drops a Z3988
         span in the page, which is how Zotero, Mendeley and EndNote import
         this record without the reader downloading anything. Harmless when
         that plugin is inactive — nothing reads the attribute. */ ?>
<div id="emeroteca-articolo" class="container emeroteca-public" data-articolo-id="<?= (int)($article['id'] ?? 0) ?>">
<?php
$pagerLabel=__('Articoli dello stesso fascicolo');
$pagerPrev=$neighbours['prev']!==null ? ['href'=>url('/emeroteca/articolo/'.(int)$neighbours['prev']['id']),'label'=>(string)$neighbours['prev']['titolo']] : null;
$pagerNext=$neighbours['next']!==null ? ['href'=>url('/emeroteca/articolo/'.(int)$neighbours['next']['id']),'label'=>(string)$neighbours['next']['titolo']] : null;
$pagerUp=$articleIssueUrl!=='' ? ['href'=>$articleIssueUrl,'label'=>$articleIssueLabel] : null;
include $corePartials.'/resource-pager.php';
?>
<div class="flex flex-wrap -mx-3">
<div class="w-full lg:w-2/3 px-3">
<?php /* Exactly one primary action, ever. The uploaded PDF is the library's
         own copy and the only thing guaranteed to resolve, so it wins; an
         external address becomes primary only when there is no PDF, and
         otherwise sits beside it as an ordinary one. The access conditions
         follow the link in both positions, because that is where a reader
         needs to be told "for internal use only". */ ?>
<?php if($articleHasPdf || ($articleResource!==null && $articleResource['linkable'])): ?>
<div class="action-buttons resource-action-buttons">
<?php if($articleHasPdf): ?><a class="btn-primary ui-button" href="<?= $e(url('/emeroteca/articolo/'.(int)$article['id'].'/pdf')) ?>"><i class="fas fa-file-pdf" aria-hidden="true"></i> <?= __('Leggi PDF') ?></a><?php endif; ?>
<?php if($articleResource!==null && $articleResource['linkable']): ?><a class="<?= $articleResourceIsPrimary?'btn-primary ui-button':'ui-button btn-outline' ?>" href="<?= $e($articleResource['url']) ?>" rel="noopener nofollow" target="_blank"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> <?= $e($articleResource['text']!==''?$articleResource['text']:__('Risorsa online')) ?></a><?php endif; ?>
<?php if($articleResource!==null && $articleResource['linkable'] && $articleResource['access']!==''): ?><p class="resource-access-note"><?= $e($articleResource['access']) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if(!empty($article['abstract'])): ?>
<div class="book-description-section">
<h2 class="section-title"><i class="fas fa-align-left" aria-hidden="true"></i> <?= __('Abstract') ?></h2>
<div class="description-content"><p class="whitespace-pre-line"><?= $e($article['abstract']) ?></p></div>
</div>
<?php endif; ?>

<div class="book-details-section">
<h2 class="section-title"><i class="fas fa-list-ul" aria-hidden="true"></i> <?= __('Dettagli articolo') ?></h2>
<div class="details-grid"><div class="details-column">
<?php /* What this record IS. A component-part record exists to say "this is a
         piece of something". */ ?>
<div class="meta-item"><div class="meta-label"><?= __('Tipo di materiale') ?></div><div class="meta-value"><?= $e(\App\Plugins\Emeroteca\Services\ContributionService::materialType($article)) ?></div></div>
<?php foreach(['contenitore_titolo'=>__('Pubblicazione'),'contenitore_curatori'=>__('Curatori del volume'),'contenitore_editore'=>__('Editore'),'contenitore_luogo'=>__('Luogo di pubblicazione'),'isbn'=>'ISBN','data_pubblicazione_testo'=>__('Data di pubblicazione'),'anno_pubblicazione'=>__('Anno'),'volume'=>__('Volume'),'numero'=>__('Numero'),'pagine'=>__('Pagine'),'issn'=>'ISSN','doi'=>'DOI'] as $key=>$label): if(empty($article[$key]))continue; if(in_array($key,['contenitore_curatori','contenitore_editore','contenitore_luogo','isbn'],true) && ($article['contenitore_tipo']??'')!=='antologia')continue; ?><div class="meta-item"><div class="meta-label"><?= $e($label) ?></div><div class="meta-value"><?php if($key==='contenitore_titolo'): ?><a href="<?= $e($articleFilterUrl('pubblicazione',(string)$article[$key])) ?>"><?= $e($article[$key]) ?></a><?php else: ?><?= $e($article[$key]) ?><?php endif; ?></div></div><?php endforeach; ?>
</div><div class="details-column">
<?php if($articleKeywords): ?><div class="meta-item"><div class="meta-label"><?= __('Parole chiave') ?></div><div class="meta-value"><?php foreach($articleKeywords as $i=>$kw): ?><?= $i?', ':'' ?><a href="<?= $e($articleFilterUrl('keyword',$kw)) ?>"><?= $e($kw) ?></a><?php endforeach; ?></div></div><?php endif; ?>
<?php if(!empty($article['lingua'])): ?><div class="meta-item"><div class="meta-label"><?= __('Lingua') ?></div><div class="meta-value"><?= $e($articleCodeLabel((string)$article['lingua'],false)) ?></div></div><?php endif; ?>
<?php if(!empty($article['paese'])): ?><div class="meta-item"><div class="meta-label"><?= __('Paese di pubblicazione') ?></div><div class="meta-value"><?= $e($articleCodeLabel((string)$article['paese'],true)) ?></div></div><?php endif; ?>
<?php if(!empty($article['classificazione'])): ?><div class="meta-item"><div class="meta-label"><?= __('Classificazione') ?></div><div class="meta-value"><?= $e(trim(((string)($article['classificazione_schema']??'')!==''?$article['classificazione_schema'].': ':'').$article['classificazione'])) ?></div></div><?php endif; ?>
<?php if(!empty($article['nota_possesso'])): ?><div class="meta-item"><div class="meta-label"><?= __('Nota di possesso') ?></div><div class="meta-value"><?= $e($article['nota_possesso']) ?></div></div><?php endif; ?>
<?php /* An address a browser cannot follow — a share, a file: URI, an archive
         identifier — is shown as what it is and never wrapped in an anchor. */ ?>
<?php if($articleResource!==null && !$articleResource['linkable']): ?><div class="meta-item"><div class="meta-label"><?= __('Percorso locale') ?></div><div class="meta-value"><code class="font-mono text-xs break-all"><?= $e($articleResource['url']) ?></code><?php if($articleResource['access']!==''): ?> <span class="resource-access-note"><?= $e($articleResource['access']) ?></span><?php endif; ?></div></div><?php endif; ?>
</div></div>
</div>

<?php /* The record these articles produce IS a citation: the catalogue held
         every part of it and would not assemble it, leaving the reader to
         retype a string the database already knew. */ ?>
<section class="book-details-section" id="article-citation">
<h2 class="section-title"><?= __('Cita questo articolo') ?></h2>
<?php
// One "Cite" button and a dialog with every style (#412), as on the book page.
$citeCitations = $articleCitations;
$citeTitle = (string) $article['titolo'];
$citeDownloads = [
    ['label' => __('Scarica la citazione in formato RIS (EndNote, Mendeley, Zotero)'), 'url' => url('/emeroteca/articolo/'.(int)$article['id'].'/citazione.ris')],
    ['label' => 'MARCXML', 'url' => url('/emeroteca/articolo/'.(int)$article['id'].'/marc.xml')],
];
include dirname(__DIR__, 6) . '/app/Views/partials/cite-dialog.php';
?>
</section>
</div>

<aside class="w-full lg:w-1/3 px-3" aria-label="<?= $e(__('Collocazione')) ?>">
<div class="card mb-4 resource-info-card">
<div class="card-header"><h2 class="mb-0 resource-info-title"><i class="fas fa-location-dot mr-2" aria-hidden="true"></i><?= __('Collocazione') ?></h2></div>
<div class="card-body">
<?php if($articleTestataUrl!=='' && $articleTestataTitle!==''): ?><div class="meta-item"><div class="meta-label"><?= __('Testata') ?></div><div class="meta-value"><a href="<?= $e($articleTestataUrl) ?>"><?= $e($articleTestataTitle) ?></a></div></div><?php endif; ?>
<?php if($articleIssueUrl!==''): ?><div class="meta-item"><div class="meta-label"><?= __('Fascicolo') ?></div><div class="meta-value"><a href="<?= $e($articleIssueUrl) ?>"><?= $e($articleIssueLabel) ?></a><?php if(trim((string)($article['fascicolo_titolo']??''))!==''): ?> — <?= $e($article['fascicolo_titolo']) ?><?php endif; ?></div></div><?php endif; ?>
<?php if(!empty($article['pagine'])): ?><div class="meta-item"><div class="meta-label"><?= __('Pagine') ?></div><div class="meta-value"><?= $e($article['pagine']) ?></div></div><?php endif; ?>
<?php if($articleTestataUrl==='' && !empty($article['contenitore_titolo'])): ?><div class="meta-item"><div class="meta-label"><?= __('Pubblicazione') ?></div><div class="meta-value"><?= $e($article['contenitore_titolo']) ?></div></div><?php endif; ?>
<?php
// The way back is the most specific place the article came from.
[$backHref,$backLabel]=$articleIssueUrl!==''
    ? [$articleIssueUrl, sprintf(__('Torna al fascicolo %s'), $articleIssueLabel)]
    : ($articleTestataUrl!=='' ? [$articleTestataUrl, __('Torna alla testata')] : [url('/emeroteca/articoli'), __('Torna agli articoli')]);
?>
<a class="ui-button btn-outline resource-back" href="<?= $e($backHref) ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> <?= $e($backLabel) ?></a>
</div>
</div>
</aside>
</div>
</div>

<?php if($relatedTestata!==[] || $relatedAuthor!==[]): ?>
<section class="resource-related">
<div class="container">
<?php if($relatedTestata!==[]): ?>
<div class="listing-section">
<h2 class="listing-section-title"><span><?= $e(sprintf(__('Altri articoli di %s'), $articleTestataTitle)) ?></span><a href="<?= $e($articleTestataUrl) ?>#emeroteca-articoli-testata"><?= __('Tutti') ?> →</a></h2>
<?php $articleResults=['rows'=>$relatedTestata]; $articleEmpty=null; require __DIR__.'/article-results.php'; ?>
</div>
<?php endif; ?>
<?php if($relatedAuthor!==[]): ?>
<div class="listing-section">
<h2 class="listing-section-title"><span><?= $e(sprintf(__('Altri articoli di %s'), (string)($relatedAuthorName??''))) ?></span></h2>
<?php $articleResults=['rows'=>$relatedAuthor]; $articleEmpty=null; require __DIR__.'/article-results.php'; ?>
</div>
<?php endif; ?>
</div>
</section>
<?php endif; ?>


<?php
$structured=['@context'=>'https://schema.org','@type'=>'Article','headline'=>$article['titolo'],'url'=>absoluteUrl('/emeroteca/articolo/'.(int)$article['id'])];
if (!empty($article['sottotitolo'])) { $structured['alternativeHeadline']=$article['sottotitolo']; }
// One Person per credited name. Declaring "Rossi, Mario; Bianchi, Anna" as a
// single Person was a statement no aggregator could use and none of it true.
if ($articleAuthors) {
    $structured['author']=array_map(static fn(array $n):array=>['@type'=>'Person','name'=>$n['name']], $articleAuthors);
} elseif (!empty($article['autori'])) {
    $structured['author']=['@type'=>'Person','name'=>$article['autori']];
}
if (($article['contenitore_tipo']??'')==='antologia' && !empty($article['contenitore_titolo'])) {
    // A chapter is part of a book, not of a periodical (#412).
    $editors=array_map(static fn(string $n):array=>['@type'=>'Person','name'=>$n], \App\Plugins\Emeroteca\Services\ContributionService::authorList((string)($article['contenitore_curatori']??'')));
    $structured['@type']='Chapter';
    $structured['isPartOf']=array_filter(['@type'=>'Book','name'=>$article['contenitore_titolo'],'isbn'=>$article['isbn']??null,
        'editor'=>$editors?:null,'publisher'=>!empty($article['contenitore_editore'])?['@type'=>'Organization','name'=>$article['contenitore_editore']]:null]);
} elseif (!empty($article['contenitore_titolo'])) {
    $periodical=array_filter(['@type'=>'Periodical','name'=>$article['contenitore_titolo'],'issn'=>$article['issn']??null]);
    // The real chain when the record carries one: an article is part of an
    // issue, which is part of a volume, which is part of the periodical.
    if (!empty($article['volume'])) { $periodical=['@type'=>'PublicationVolume','volumeNumber'=>(string)$article['volume'],'isPartOf'=>$periodical]; }
    if (!empty($article['numero'])) { $periodical=['@type'=>'PublicationIssue','issueNumber'=>(string)$article['numero'],'isPartOf'=>$periodical]; }
    $structured['isPartOf']=$periodical;
}
// schema.org reads BCP 47: "it", not the "ita" the record stores.
if (!empty($article['lingua'])) { $structured['inLanguage']=\App\Plugins\Emeroteca\Support\CodeLists::languageTag((string)$article['lingua']); }
// A bare year is valid ISO 8601. The free-text date is NOT parsed into one:
// "giugno 2019" and "Nr. 31 (1988)" are prose, and guessing a day from them
// would publish a precision the record never had.
if (!empty($article['anno_pubblicazione'])) { $structured['datePublished']=(string)(int)$article['anno_pubblicazione']; }
if ($articleParts['pageStart']!=='') { $structured['pageStart']=$articleParts['pageStart']; }
if ($articleParts['pageEnd']!=='') { $structured['pageEnd']=$articleParts['pageEnd']; }
if ($articleHasPdf) { $structured['encoding']=['@type'=>'MediaObject','encodingFormat'=>'application/pdf','contentUrl'=>absoluteUrl('/emeroteca/articolo/'.(int)$article['id'].'/pdf')]; }
if (!empty($article['pagine'])) { $structured['pagination']=$article['pagine']; }
if (!empty($article['doi'])) { $structured['identifier']=$article['doi']; }
// Only a real image: the shared placeholder is chrome, and declaring it here
// would tell an aggregator every article looks the same. The masthead's logo
// IS declared, even though every article of one publication then shares it:
// it is a true statement about the record, which the placeholder never was.
if ($articleCover !== '') { $structured['image']=absoluteUrl($articleCover); }
if ($articleKeywords) { $structured['keywords']=$articleKeywords; }
?>
<script type="application/ld+json"><?= json_encode($structured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
