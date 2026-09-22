<?php
/**
 * Public page of a standalone article.
 *
 * Author, publication and each keyword are links into the article search
 * narrowed by that value. They are deliberately NOT links to the core author
 * or publisher pages: on a standalone article these are free-text fields, and
 * the library may hold a single article by someone who has no author record —
 * inventing one would put a person in the catalogue's registry on the strength
 * of a citation string.
 *
 * @var array<string, mixed> $article
 */
$article=$article??[]; $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$articlePlaceholder=url('/uploads/copertine/placeholder.jpg');
$articleFilterUrl=static fn(string $key,string $value):string=>url('/emeroteca/articoli').'?'.http_build_query([$key=>$value]);
/** Free-text keyword list: comma-separated by convention, one link each. */
$articleKeywords=array_values(array_filter(array_map('trim', explode(',', (string)($article['keywords']??''))), static fn(string $k):bool=>$k!==''));
?>
<main class="max-w-4xl mx-auto px-4 py-10"><a class="underline" href="<?= $e(url('/emeroteca/articoli')) ?>"><?= __('Articoli') ?></a>
<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;margin-top:1.25rem;">
<img src="<?= $e(url(($article['copertina_url']??'')!==''?(string)$article['copertina_url']:'/uploads/copertine/placeholder.jpg')) ?>" alt="" loading="lazy" decoding="async" style="width:150px;height:200px;object-fit:cover;border-radius:.375rem;flex:0 0 auto;" onerror="this.onerror=null;this.src=<?= $e(json_encode($articlePlaceholder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
<div style="flex:1 1 320px;min-width:0;"><h1 class="text-3xl font-bold mb-3"><?= $e($article['titolo']) ?></h1>
<?php if(!empty($article['autori'])): ?><p class="text-xl mb-6"><a class="underline" href="<?= $e($articleFilterUrl('autore',(string)$article['autori'])) ?>"><?= $e($article['autori']) ?></a></p><?php endif; ?>
</div></div>
<dl class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-8"><?php foreach(['contenitore_titolo'=>__('Pubblicazione'),'data_pubblicazione_testo'=>__('Data di pubblicazione'),'anno_pubblicazione'=>__('Anno'),'volume'=>__('Volume'),'numero'=>__('Numero'),'pagine'=>__('Pagine'),'issn'=>'ISSN','doi'=>'DOI'] as $key=>$label): if(empty($article[$key]))continue; ?><div><dt class="text-sm text-gray-600"><?= $e($label) ?></dt><dd><?php if($key==='contenitore_titolo'): ?><a class="underline" href="<?= $e($articleFilterUrl('pubblicazione',(string)$article[$key])) ?>"><?= $e($article[$key]) ?></a><?php else: ?><?= $e($article[$key]) ?><?php endif; ?></dd></div><?php endforeach; ?>
<?php if($articleKeywords): ?><div><dt class="text-sm text-gray-600"><?= __('Parole chiave') ?></dt><dd><?php foreach($articleKeywords as $i=>$kw): ?><?= $i?', ':'' ?><a class="underline" href="<?= $e($articleFilterUrl('keyword',$kw)) ?>"><?= $e($kw) ?></a><?php endforeach; ?></dd></div><?php endif; ?></dl>
<?php if(!empty($article['abstract'])): ?><p class="whitespace-pre-line my-8"><?= $e($article['abstract']) ?></p><?php endif; ?>
<?php if(!empty($article['testata_id'])): ?><p class="my-5"><a class="underline" href="<?= $e(url('/emeroteca/'.(int)$article['testata_id'])) ?>"><?= __('Consulta la testata') ?></a></p><?php endif; ?>
<?php if(!empty($article['pdf_path']) && !empty($article['pdf_pubblico'])): ?><a class="btn-primary inline-flex mt-5" href="<?= $e(url('/emeroteca/articolo/'.(int)$article['id'].'/pdf')) ?>"><?= __('Leggi PDF') ?></a><?php endif; ?></main>

<?php
$structured=['@context'=>'https://schema.org','@type'=>'Article','headline'=>$article['titolo'],'url'=>absoluteUrl('/emeroteca/articolo/'.(int)$article['id'])];
if (!empty($article['autori'])) { $structured['author']=['@type'=>'Person','name'=>$article['autori']]; }
if (!empty($article['contenitore_titolo'])) { $structured['isPartOf']=array_filter(['@type'=>'Periodical','name'=>$article['contenitore_titolo'],'issn'=>$article['issn']??null]); }
if (!empty($article['pagine'])) { $structured['pagination']=$article['pagine']; }
if (!empty($article['doi'])) { $structured['identifier']=$article['doi']; }
// Only a real image: the shared placeholder is chrome, and declaring it here
// would tell an aggregator every article looks the same.
if (!empty($article['copertina_url'])) { $structured['image']=absoluteUrl((string)$article['copertina_url']); }
if ($articleKeywords) { $structured['keywords']=$articleKeywords; }
?>
<script type="application/ld+json"><?= json_encode($structured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
