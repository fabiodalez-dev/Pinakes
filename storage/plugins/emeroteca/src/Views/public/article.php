<?php $article=$article??[]; $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<main class="max-w-4xl mx-auto px-4 py-10"><a class="underline" href="<?= $e(url('/emeroteca/articoli')) ?>"><?= __('Articoli') ?></a><h1 class="text-3xl font-bold mt-5 mb-3"><?= $e($article['titolo']) ?></h1><p class="text-xl mb-6"><?= $e($article['autori']) ?></p>
<dl class="grid grid-cols-1 md:grid-cols-2 gap-5"><?php foreach(['contenitore_titolo'=>__('Pubblicazione'),'data_pubblicazione_testo'=>__('Data di pubblicazione'),'anno_pubblicazione'=>__('Anno'),'volume'=>__('Volume'),'numero'=>__('Numero'),'pagine'=>__('Pagine'),'issn'=>'ISSN','doi'=>'DOI','keywords'=>__('Parole chiave')] as $key=>$label): if(empty($article[$key]))continue; ?><div><dt class="text-sm text-gray-600"><?= $e($label) ?></dt><dd><?= $e($article[$key]) ?></dd></div><?php endforeach; ?></dl>
<?php if(!empty($article['abstract'])): ?><p class="whitespace-pre-line my-8"><?= $e($article['abstract']) ?></p><?php endif; ?>
<?php if(!empty($article['testata_id'])): ?><p class="my-5"><a class="underline" href="<?= $e(url('/emeroteca/'.(int)$article['testata_id'])) ?>"><?= __('Consulta la testata') ?></a></p><?php endif; ?>
<?php if(!empty($article['pdf_path']) && !empty($article['pdf_pubblico'])): ?><a class="btn-primary inline-flex mt-5" href="<?= $e(url('/emeroteca/articolo/'.(int)$article['id'].'/pdf')) ?>"><?= __('Leggi PDF') ?></a><?php endif; ?></main>

<?php
$structured=['@context'=>'https://schema.org','@type'=>'Article','headline'=>$article['titolo'],'url'=>absoluteUrl('/emeroteca/articolo/'.(int)$article['id'])];
if (!empty($article['autori'])) { $structured['author']=['@type'=>'Person','name'=>$article['autori']]; }
if (!empty($article['contenitore_titolo'])) { $structured['isPartOf']=array_filter(['@type'=>'Periodical','name'=>$article['contenitore_titolo'],'issn'=>$article['issn']??null]); }
if (!empty($article['pagine'])) { $structured['pagination']=$article['pagine']; }
if (!empty($article['doi'])) { $structured['identifier']=$article['doi']; }
?>
<script type="application/ld+json"><?= json_encode($structured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
