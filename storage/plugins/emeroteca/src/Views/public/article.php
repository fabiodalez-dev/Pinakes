<?php
/**
 * Public page of a standalone article.
 *
 * Confirmed author links open the common author archive. Unlinked legacy names
 * open a name search; publication and keywords narrow the article search.
 * Free-text credits never create authority records automatically.
 *
 * @var array<string, mixed> $article
 */
$article=$article??[]; $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
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
?>
<?php /* data-articolo-id is what the OpenURL resolver's injected script looks
         for: finding it, it fetches the article's COinS and drops a Z3988
         span in the page, which is how Zotero, Mendeley and EndNote import
         this record without the reader downloading anything. Harmless when
         that plugin is inactive — nothing reads the attribute. */ ?>
<main class="max-w-4xl mx-auto px-4 py-10" data-articolo-id="<?= (int)($article['id'] ?? 0) ?>"><a class="underline" href="<?= $e(url('/emeroteca/articoli')) ?>"><?= __('Articoli') ?></a>
<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;margin-top:1.25rem;">
<img src="<?= $e(url($articleCover!==''?$articleCover:'/uploads/copertine/placeholder.jpg')) ?>" alt="" loading="lazy" decoding="async" style="width:150px;height:200px;object-fit:cover;border-radius:.375rem;flex:0 0 auto;" onerror="this.onerror=null;this.src=<?= $e(json_encode($articlePlaceholder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
<div style="flex:1 1 320px;min-width:0;"><h1 class="text-3xl font-bold mb-3"><?= $e($article['titolo']) ?></h1>
<?php if(!empty($article['sottotitolo'])): ?><p class="text-xl text-gray-600 mb-3"><?= $e($article['sottotitolo']) ?></p><?php endif; ?>
<?php if(!empty($article['autori'])): ?><p class="text-xl mb-6"><?php if($articleAuthors): foreach($articleAuthors as $i=>$an): ?><?= $i?'; ':'' ?><a class="underline" href="<?= $e(($an['id'] !== null ? route_path('author').'/'.$an['id'] : $articleFilterUrl('autore',$an['name']))) ?>"><?= $e($an['name']) ?></a><?php endforeach; else: ?><?= $e($article['autori']) ?><?php endif; ?></p><?php endif; ?>
</div></div>
<dl class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-8">
<?php /* What this record IS. A component-part record exists to say "this is a
         piece of something", and until now that lived only in the table it sat
         in and in structured data nobody reads. */ ?>
<div><dt class="text-sm text-gray-600"><?= __('Tipo di materiale') ?></dt><dd><?= $e(\App\Plugins\Emeroteca\Services\ContributionService::materialType($article)) ?></dd></div>
<?php foreach(['contenitore_titolo'=>__('Pubblicazione'),'contenitore_curatori'=>__('Curatori del volume'),'contenitore_editore'=>__('Editore'),'contenitore_luogo'=>__('Luogo di pubblicazione'),'isbn'=>'ISBN','data_pubblicazione_testo'=>__('Data di pubblicazione'),'anno_pubblicazione'=>__('Anno'),'volume'=>__('Volume'),'numero'=>__('Numero'),'pagine'=>__('Pagine'),'issn'=>'ISSN','doi'=>'DOI'] as $key=>$label): if(empty($article[$key]))continue; if(in_array($key,['contenitore_curatori','contenitore_editore','contenitore_luogo','isbn'],true) && ($article['contenitore_tipo']??'')!=='antologia')continue; ?><div><dt class="text-sm text-gray-600"><?= $e($label) ?></dt><dd><?php if($key==='contenitore_titolo'): ?><a class="underline" href="<?= $e($articleFilterUrl('pubblicazione',(string)$article[$key])) ?>"><?= $e($article[$key]) ?></a><?php else: ?><?= $e($article[$key]) ?><?php endif; ?></dd></div><?php endforeach; ?>
<?php if($articleKeywords): ?><div><dt class="text-sm text-gray-600"><?= __('Parole chiave') ?></dt><dd><?php foreach($articleKeywords as $i=>$kw): ?><?= $i?', ':'' ?><a class="underline" href="<?= $e($articleFilterUrl('keyword',$kw)) ?>"><?= $e($kw) ?></a><?php endforeach; ?></dd></div><?php endif; ?>
<?php if(!empty($article['lingua'])): ?><div><dt class="text-sm text-gray-600"><?= __('Lingua') ?></dt><dd><?= $e($articleCodeLabel((string)$article['lingua'],false)) ?></dd></div><?php endif; ?>
<?php if(!empty($article['paese'])): ?><div><dt class="text-sm text-gray-600"><?= __('Paese di pubblicazione') ?></dt><dd><?= $e($articleCodeLabel((string)$article['paese'],true)) ?></dd></div><?php endif; ?>
<?php if(!empty($article['classificazione'])): ?><div><dt class="text-sm text-gray-600"><?= __('Classificazione') ?></dt><dd><?= $e(trim(((string)($article['classificazione_schema']??'')!==''?$article['classificazione_schema'].': ':'').$article['classificazione'])) ?></dd></div><?php endif; ?>
<?php if(!empty($article['nota_possesso'])): ?><div><dt class="text-sm text-gray-600"><?= __('Nota di possesso') ?></dt><dd><?= $e($article['nota_possesso']) ?></dd></div><?php endif; ?>
<?php /* An address a browser cannot follow — a share, a file: URI, an archive
         identifier — is shown as what it is and never wrapped in an anchor. */ ?>
<?php if($articleResource!==null && !$articleResource['linkable']): ?><div><dt class="text-sm text-gray-600"><?= __('Percorso locale') ?></dt><dd><code class="font-mono text-xs break-all"><?= $e($articleResource['url']) ?></code><?php if($articleResource['access']!==''): ?> <span class="text-sm text-gray-600"><?= $e($articleResource['access']) ?></span><?php endif; ?></dd></div><?php endif; ?></dl>
<?php if(!empty($article['abstract'])): ?><p class="whitespace-pre-line my-8"><?= $e($article['abstract']) ?></p><?php endif; ?>
<?php if(!empty($article['testata_id'])): ?><p class="my-5"><a class="underline" href="<?= $e(url('/emeroteca/'.(int)$article['testata_id'])) ?>"><?= __('Consulta la testata') ?></a></p><?php endif; ?>
<?php /* Exactly one primary action, ever. The uploaded PDF is the library's
         own copy and the only thing guaranteed to resolve, so it wins; an
         external address becomes primary only when there is no PDF, and
         otherwise sits underneath as an ordinary link. The access conditions
         follow the link in both positions, because that is where a reader
         needs to be told "for internal use only". */ ?>
<?php if($articleHasPdf): ?><p class="mt-5"><a class="btn-primary inline-flex" href="<?= $e(url('/emeroteca/articolo/'.(int)$article['id'].'/pdf')) ?>"><?= __('Leggi PDF') ?></a></p><?php endif; ?>
<?php if($articleResource!==null && $articleResource['linkable']): ?>
<p class="mt-5"><a class="<?= $articleResourceIsPrimary?'btn-primary inline-flex':'underline' ?>" href="<?= $e($articleResource['url']) ?>" rel="noopener nofollow" target="_blank"><?= $e($articleResource['text']!==''?$articleResource['text']:__('Risorsa online')) ?></a><?php if($articleResource['access']!==''): ?> <span class="text-sm text-gray-600"><?= $e($articleResource['access']) ?></span><?php endif; ?></p>
<?php endif; ?>

<?php /* The record these articles produce IS a citation: the catalogue held
         every part of it and would not assemble it, leaving the reader to
         retype a string the database already knew. */ ?>
<section class="mt-8" id="article-citation">
<h2 class="text-xl font-bold mb-3"><?= __('Cita questo articolo') ?></h2>
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
</main>


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
if (!empty($article['lingua'])) { $structured['inLanguage']=$article['lingua']; }
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
