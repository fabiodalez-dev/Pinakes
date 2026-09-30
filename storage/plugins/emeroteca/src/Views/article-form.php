<?php
declare(strict_types=1);
$row=$row??[]; $error=$error??null;
$e=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');
$labels=['titolo'=>__('Titolo'),'sottotitolo'=>__('Sottotitolo'),'autori'=>__('Autori'),'contenitore_titolo'=>__('Titolo della pubblicazione'),'data_pubblicazione_testo'=>__('Data di pubblicazione'),'anno_pubblicazione'=>__('Anno'),'volume'=>__('Volume'),'numero'=>__('Numero'),'pagine'=>__('Pagine'),'keywords'=>__('Parole chiave'),'issn'=>'ISSN','doi'=>'DOI','collocazione'=>__('Collocazione'),'lingua'=>__('Lingua'),'paese'=>__('Paese'),'classificazione_schema'=>__('Schema di classificazione'),'classificazione'=>__('Classificazione'),'nota_possesso'=>__('Nota di possesso')];
?>
<div class="max-w-4xl mx-auto px-4 py-6">
<a class="underline" href="<?= $e(url('/admin/periodicals/articles')) ?>"><?= __('Articoli') ?></a>
<h1 class="text-3xl font-bold mt-4 mb-6"><?= empty($row['id'])?__('Aggiungi articolo'):__('Modifica articolo') ?></h1>
<?php if($error): ?><p role="alert" class="p-4 mb-4 bg-red-50 text-red-800 rounded"><?= $e($error) ?></p><?php endif; ?>
<form method="post" action="<?= $e(url('/admin/periodicals/articles/save')) ?>" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>"><input type="hidden" name="id" value="<?= (int)($row['id']??0) ?>"><input type="hidden" name="revision" value="<?= (int)($row['revision']??0) ?>">
<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
<?php foreach(array_diff_key($labels,array_flip(['issn','doi','collocazione','lingua','paese','classificazione_schema','classificazione','nota_possesso'])) as $key=>$label): ?><?php if($key==='autori'){ include __DIR__ . '/article-authors.php'; continue; } ?><div <?= in_array($key,['titolo','sottotitolo'],true)?'class="md:col-span-2"':'' ?>><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($label) ?><?= $key==='titolo'?' *':'' ?></label><input class="form-input" id="article-<?= $e($key) ?>" name="<?= $e($key) ?>" value="<?= $e($row[$key]??'') ?>" <?= $key==='titolo'?'required':'' ?> maxlength="<?= $key==='anno_pubblicazione'?4:(\App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS[$key]) ?>"><?php if($key==='data_pubblicazione_testo'): ?><p class="text-sm text-gray-600"><?= __('Per un giornale indica la data completa, per esempio 28-09-2026: la citazione riporta giorno e mese. Per una rivista bastano mese e anno, per esempio giugno 2019.') ?></p><?php endif; ?><?php /* The authors field above asks for a semicolon, so a cataloguer who read
     that instruction reaches this one and uses a semicolon here too. Keywords
     split on a COMMA everywhere in the plugin — public page, JSON-LD, RIS — so
     a semicolon silently yields one keyword containing it. Say so here. */ ?><?php if($key==='keywords'): ?><p class="text-sm text-gray-600"><?= __('Separa le parole chiave con una virgola, non con un punto e virgola come gli autori.') ?></p><?php endif; ?></div><?php endforeach; ?>
<?php foreach(['tipo_contributo'=>[__('Tipo di contributo'),\EmerotecaPlugin::TIPI_ARTICOLO],'contenitore_tipo'=>[__('Tipo di pubblicazione'),[''=>__('Non specificato')]+\EmerotecaPlugin::TIPI_CONTENITORE],'supporto'=>[__('Formato'),['cartaceo'=>'Cartaceo','digitale'=>'Digitale','entrambi'=>'Cartaceo e digitale']]] as $key=>[$label,$choices]): ?><div><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($label) ?></label><select id="article-<?= $e($key) ?>" class="form-input" name="<?= $e($key) ?>"><?php foreach($choices as $value=>$text): ?><option value="<?= $e($value) ?>" <?= ($row[$key]??($key==='supporto'?'cartaceo':'articolo'))===$value?'selected':'' ?>><?= $e(__($text)) ?></option><?php endforeach; ?></select><?php if($key==='supporto'): ?><p class="text-sm text-gray-600"><?= __('In quale forma la biblioteca possiede l’articolo: su carta, in digitale o in entrambe.') ?></p><?php endif; ?></div><?php endforeach; ?>
</div>
<?php /* A chapter in an anthology (#412): the host is a book, cited with its
         editors, publisher and place. Shown when "Anthology" is the
         publication type; without JavaScript it is simply always there. */ ?>
<fieldset class="mt-6" id="article-host-volume">
<legend class="font-semibold py-3"><?= __('Volume ospite (per un’antologia)') ?></legend>
<p class="text-sm text-gray-600 mb-3"><?= __('Il «Titolo della pubblicazione» è il titolo del volume. Questi dati servono alla citazione del capitolo.') ?></p>
<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
<?php foreach(['contenitore_curatori'=>[__('Curatori del volume'),__('Separa più curatori con un punto e virgola, per esempio Petersen, Hans Uwe.')],'contenitore_editore'=>[__('Editore'),''],'contenitore_luogo'=>[__('Luogo di pubblicazione'),''],'isbn'=>['ISBN',__('L’ISBN del volume.')]] as $key=>[$label,$hint]): ?><div<?= $key==='contenitore_curatori'?' class="md:col-span-2"':'' ?>><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($label) ?></label><input class="form-input" id="article-<?= $e($key) ?>" name="<?= $e($key) ?>" value="<?= $e($row[$key]??'') ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS[$key] ?>"><?php if($hint!==''): ?><p class="text-sm text-gray-600"><?= $e($hint) ?></p><?php endif; ?></div><?php endforeach; ?>
</div>
</fieldset>
<script>
(function () {
  var type = document.getElementById('article-contenitore_tipo');
  var host = document.getElementById('article-host-volume');
  if (!type || !host) { return; }
  function sync() { host.hidden = type.value !== 'antologia'; }
  type.addEventListener('change', sync);
  sync();
})();
</script>
<?php /* Uwe (#412) could not find "Associated publication" on this form: it is
         not a field here, because linking to a masthead record goes through a
         preview in the article list. Say what it is, and what it is now. */ ?>
<div class="mt-6 p-4 rounded-lg bg-gray-50 text-sm" id="article-host-record"><p class="font-semibold"><?= __('Testata associata') ?>: <?= !empty($hostTitle) ? $e($hostTitle) : __('Non associato') ?></p><p class="text-gray-600 mt-1"><?= __('«Titolo della pubblicazione» è il nome della rivista o del giornale come lo scrivi in questa scheda. La testata associata è la scheda di quella rivista nell’Emeroteca, con le sue annate e i suoi fascicoli: è facoltativa, e si collega dalla lista Articoli, selezionando l’articolo e aprendo «Associa gli articoli selezionati a una testata».') ?></p><p class="mt-2"><a class="underline" href="<?= $e(url('/admin/periodicals/articles')) ?>"><?= __('Apri la lista Articoli') ?></a></p></div>
<details class="mt-6"><summary class="font-semibold py-3 cursor-pointer"><?= __('Identificativi e collocazione') ?></summary><div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-3"><?php foreach(['issn'=>'ISSN','doi'=>'DOI','collocazione'=>__('Collocazione')] as $key=>$label): ?><div><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($label) ?></label><input class="form-input" id="article-<?= $e($key) ?>" name="<?= $e($key) ?>" value="<?= $e($row[$key]??'') ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS[$key] ?>"><?php if($key==='collocazione'): ?><p class="text-sm text-gray-600"><?= __('Dove si trova la copia: scatola d’archivio, faldone, cartella.') ?></p><?php endif; ?></div><?php endforeach; ?></div></details>
<?php /* The analytic apparatus, folded away. A library that only needs a
         citation never opens this; a librarian cataloguing an offprint finds
         the whole of danMARC2/MARC 21 008, 084 and the holdings note in one
         place. Every field here is optional — see the hints. */ ?>
<details class="mt-6"><summary class="font-semibold py-3 cursor-pointer"><?= __('Descrizione bibliografica avanzata (facoltativa)') ?></summary>
<p class="text-sm text-gray-600 mt-2"><?= __('Serve a chi cataloga secondo uno standard bibliografico. Lasciando tutto vuoto la scheda resta valida.') ?></p>
<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-3">
<?php /* Language, country and scheme are picked, not typed (#412): the columns
         hold ISO codes so that a record reads in each user's language, and
         the pickers list the names, in the language of whoever catalogues,
         searchable by name or by code. A stored code outside the lists (a
         two-letter "da" from before the pickers) stays selectable, so opening
         and saving a record never changes it behind the cataloguer's back. */
$codeLocale=\App\Support\I18n::getLocale();
$otherScheme=\App\Plugins\Emeroteca\Services\ContributionService::OTHER_SCHEME;
$codePickers=['lingua'=>[\App\Plugins\Emeroteca\Support\CodeLists::languages($codeLocale),false,__('Scrivi il nome o il codice, per esempio «danese» o «dan».'),__('Codice ISO 639, per esempio dan, ita, eng.')],'paese'=>[\App\Plugins\Emeroteca\Support\CodeLists::countries($codeLocale),true,__('Scrivi il nome o il codice, per esempio «Danimarca» o «DK».'),__('Codice ISO 3166, per esempio DK, IT.')]];
$schemes=['DDC'=>__('DDC — Classificazione decimale Dewey'),'DK5'=>__('DK5 — Classificazione decimale danese'),'UDC'=>__('UDC — Classificazione decimale universale'),'LCC'=>__('LCC — Classificazione della Library of Congress'),'RVK'=>__('RVK — Regensburger Verbundklassifikation')];
$scheme=trim((string)($row['classificazione_schema']??''));$schemeOther=trim((string)($row['classificazione_schema_altro']??''));
if($scheme!==''&&$scheme!==$otherScheme){if(isset($schemes[strtoupper($scheme)])){$scheme=strtoupper($scheme);}else{$schemeOther=$scheme;$scheme=$otherScheme;}}
?>
<?php foreach($codePickers as $key=>[$names,$region,$hint,$codeHint]): $current=trim((string)($row[$key]??'')); ?><div><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($labels[$key]) ?></label><?php if($names===[]): /* no intl, so no names to list: the code is typed */ ?><input class="form-input" id="article-<?= $e($key) ?>" name="<?= $e($key) ?>" value="<?= $e($current) ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS[$key] ?>"><p class="text-sm text-gray-600"><?= $e($codeHint) ?></p><?php else: ?><select class="form-input" id="article-<?= $e($key) ?>" name="<?= $e($key) ?>" data-code-picker><option value=""><?= __('Non specificato') ?></option><?php if($current!==''&&!isset($names[$current])): ?><option value="<?= $e($current) ?>" selected><?= $e(\App\Plugins\Emeroteca\Support\CodeLists::name($current,$codeLocale,$region)) ?> (<?= $e($current) ?>)</option><?php endif; ?><?php foreach($names as $code=>$name): ?><option value="<?= $e($code) ?>"<?= $current===(string)$code?' selected':'' ?>><?= $e($name) ?> (<?= $e($code) ?>)</option><?php endforeach; ?></select><p class="text-sm text-gray-600"><?= $e($hint) ?></p><?php endif; ?></div><?php endforeach; ?>
<div><label for="article-classificazione_schema" class="form-label"><?= $e($labels['classificazione_schema']) ?></label><select class="form-input" id="article-classificazione_schema" name="classificazione_schema"><option value=""><?= __('Non specificato') ?></option><?php foreach($schemes as $code=>$name): ?><option value="<?= $e($code) ?>"<?= $scheme===$code?' selected':'' ?>><?= $e($name) ?></option><?php endforeach; ?><option value="<?= $e($otherScheme) ?>"<?= $scheme===$otherScheme?' selected':'' ?>><?= __('Altro schema') ?></option></select><p class="text-sm text-gray-600"><?= __('Con la Dewey la notazione si sceglie dall’elenco, come per i libri.') ?></p>
<div class="mt-3" id="article-scheme-other"><label for="article-classificazione_schema_altro" class="form-label"><?= __('Nome dello schema') ?></label><input class="form-input" id="article-classificazione_schema_altro" name="classificazione_schema_altro" value="<?= $e($schemeOther) ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS['classificazione_schema'] ?>"><p class="text-sm text-gray-600"><?= __('Solo se hai scelto «Altro schema».') ?></p></div></div>
<?php /* The Dewey picker comes first in the page on purpose: both it and the
         text box post "classificazione", and without JavaScript (which is
         what switches one of them off) PHP keeps the last one — the text box
         the operator can actually see and use. */ ?>
<div class="md:col-span-2"><div id="article-class-dewey" hidden><?php $deweyFieldName='classificazione'; $deweyValue=(string)($row['classificazione']??''); include dirname(__DIR__, 5) . '/app/Views/partials/dewey-picker.php'; ?></div>
<div id="article-class-text"><label for="article-classificazione" class="form-label"><?= $e($labels['classificazione']) ?></label><input class="form-input" id="article-classificazione" name="classificazione" value="<?= $e($row['classificazione']??'') ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS['classificazione'] ?>"><p class="text-sm text-gray-600"><?= __('La notazione, per esempio 33.129.') ?></p></div></div>
<div class="md:col-span-2"><label for="article-nota_possesso" class="form-label"><?= $e($labels['nota_possesso']) ?></label><input class="form-input" id="article-nota_possesso" name="nota_possesso" value="<?= $e($row['nota_possesso']??'') ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS['nota_possesso'] ?>"><p class="text-sm text-gray-600"><?= __('Che cosa possiede la biblioteca di questo articolo, per esempio una fotocopia o un estratto, e non l’intera annata. Il formato, su carta o digitale, si sceglie più sopra.') ?></p></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Language and country: the same searchable picker as the authors.
  if (typeof window.Choices === 'function') {
    document.querySelectorAll('select[data-code-picker]').forEach(function (select) {
      new Choices(select, {
        searchEnabled: true,
        shouldSort: false,
        searchResultLimit: -1,
        itemSelectText: '',
        allowHTML: false,
        noResultsText: <?= json_encode(__('Nessun risultato'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        searchPlaceholderValue: <?= json_encode(__('Cerca per nome o codice'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        fuseOptions: { threshold: 0.3 }
      });
    });
  }
  // Scheme: "Other" asks for its name; Dewey swaps the text box for the
  // Dewey picker. Only one of the two "classificazione" inputs is enabled.
  const scheme = document.getElementById('article-classificazione_schema');
  const other = document.getElementById('article-scheme-other');
  const textBox = document.getElementById('article-class-text');
  const text = document.getElementById('article-classificazione');
  const deweyBox = document.getElementById('article-class-dewey');
  const dewey = document.getElementById('classificazione_dewey');
  if (!scheme || !other || !textBox || !text || !deweyBox || !dewey) return;
  let deweyStarted = false;
  function sync() {
    const isDewey = scheme.value === 'DDC';
    other.hidden = scheme.value !== <?= json_encode($otherScheme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    textBox.hidden = isDewey;
    text.disabled = isDewey;
    deweyBox.hidden = !isDewey;
    dewey.disabled = !isDewey;
    if (isDewey && !deweyStarted && typeof window.initializeDewey === 'function') {
      deweyStarted = true;
      // A notation typed under another scheme is not a Dewey code.
      const typed = text.value.trim();
      window.initializeDewey(/^[0-9]{3}(\.[0-9]{1,4})?$/.test(typed) ? typed : '');
    }
  }
  scheme.addEventListener('change', sync);
  sync();
});
</script>
</details>
<?php /* danMARC2 856 / MARC 21 856: where the digital copy lives, what the
         link should say, and who may open it. */ ?>
<details class="mt-6"><summary class="font-semibold py-3 cursor-pointer"><?= __('Risorsa elettronica (facoltativa)') ?></summary>
<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-3">
<div class="md:col-span-2"><label for="article-risorsa_url" class="form-label"><?= __('Indirizzo o percorso della risorsa') ?></label><input class="form-input" id="article-risorsa_url" name="risorsa_url" value="<?= $e($row['risorsa_url']??'') ?>" maxlength="500"><p class="text-sm text-gray-600"><?= __('Un indirizzo http o https diventa un collegamento; un percorso locale o un identificativo di archivio resta testo e non viene mai reso cliccabile.') ?></p></div>
<div><label for="article-risorsa_testo" class="form-label"><?= __('Testo del collegamento') ?></label><input class="form-input" id="article-risorsa_testo" name="risorsa_testo" value="<?= $e($row['risorsa_testo']??'') ?>" maxlength="255"></div>
<div><label for="article-risorsa_accesso" class="form-label"><?= __('Condizioni di accesso') ?></label><input class="form-input" id="article-risorsa_accesso" name="risorsa_accesso" value="<?= $e($row['risorsa_accesso']??'') ?>" maxlength="255"></div>
</div>
<?php $resourceMissing=trim((string)($row['risorsa_url']??''))===''; ?>
<label class="block my-4"><input type="checkbox" id="article-risorsa_pubblica" name="risorsa_pubblica" value="1" <?= !empty($row['risorsa_pubblica'])?'checked':'' ?><?= $resourceMissing?' disabled aria-disabled="true"':'' ?> aria-describedby="article-risorsa-note"> <?= __('Mostra la risorsa elettronica nel catalogo pubblico') ?></label>
<p class="text-sm text-gray-600" id="article-risorsa-note"<?= $resourceMissing?'':' hidden' ?>><?= __('Indica prima l’indirizzo o il percorso della risorsa: senza, non c’è nulla da mostrare nel catalogo pubblico.') ?></p>
<script>
(function () {
  // A public switch with no address publishes nothing (the server forces it
  // off too): keep it disabled, with the reason beside it, until one is typed.
  const url = document.getElementById('article-risorsa_url');
  const flag = document.getElementById('article-risorsa_pubblica');
  const note = document.getElementById('article-risorsa-note');
  if (!url || !flag || !note) return;
  function sync() {
    const empty = url.value.trim() === '';
    flag.disabled = empty;
    if (empty) { flag.checked = false; }
    flag.setAttribute('aria-disabled', empty ? 'true' : 'false');
    note.hidden = !empty;
  }
  url.addEventListener('input', sync);
  sync();
})();
</script>
</details>
<div class="mt-6"><label for="article-copertina" class="form-label"><?= __('Immagine dell’articolo (JPG, PNG o WebP, massimo 5 MB)') ?></label><input id="article-copertina" type="file" name="copertina" accept="image/jpeg,image/png,image/webp" class="form-input">
<?php if(!empty($row['copertina_url'])): ?><p class="my-3"><img src="<?= $e(url((string)$row['copertina_url'])) ?>" alt="" style="width:110px;height:150px;object-fit:cover;border-radius:.25rem;"></p><label><input type="checkbox" name="remove_copertina" value="1"> <?= __('Rimuovi immagine') ?></label><?php endif; ?></div>
<details class="mt-6"><summary class="font-semibold py-3 cursor-pointer"><?= __('Descrizione, note e PDF') ?></summary>
<?php foreach(['abstract'=>__('Descrizione'),'note_private'=>__('Note private')] as $key=>$label): ?><label for="article-<?= $e($key) ?>" class="form-label mt-4"><?= $e($label) ?></label><textarea id="article-<?= $e($key) ?>" class="form-input" name="<?= $e($key) ?>" rows="4" maxlength="10000"><?= $e($row[$key]??'') ?></textarea><?php endforeach; ?>
<label for="article-pdf" class="form-label mt-4"><?= __('PDF (massimo 25 MB)') ?></label><input id="article-pdf" type="file" name="pdf" accept="application/pdf,.pdf" class="form-input">
<?php if(!empty($row['pdf_path'])): ?><p class="my-3"><a class="underline" href="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/pdf')) ?>"><?= $e($row['pdf_nome_originale']) ?></a></p><label><input type="checkbox" name="remove_pdf" value="1"> <?= __('Rimuovi PDF') ?></label><?php endif; ?>
<label class="block my-4"><input type="checkbox" name="pdf_pubblico" value="1" <?= !empty($row['pdf_pubblico'])?'checked':'' ?>> <?= __('Consenti la consultazione pubblica del PDF') ?></label>
</details>
<label class="block my-6"><input type="checkbox" name="pubblico" value="1" <?= !empty($row['pubblico'])?'checked':'' ?>> <?= __('Mostra l’articolo nel catalogo pubblico') ?></label>
<div class="flex gap-3"><button class="btn-primary" type="submit"><?= __('Salva articolo') ?></button><a class="btn-secondary" href="<?= $e(url('/admin/periodicals/articles')) ?>"><?= __('Annulla') ?></a></div>
</form>
<?php if(!empty($row['id'])): ?><p class="mt-6"><a class="underline" href="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/citation.ris')) ?>"><?= __('Scarica la citazione in formato RIS (EndNote, Mendeley, Zotero)') ?></a> · <a class="underline" href="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/marc.xml')) ?>">MARCXML</a></p>
<?php if(($_SESSION['user']['tipo_utente']??'')==='admin'): ?><details class="mt-8"><summary class="cursor-pointer text-red-700"><?= __('Elimina articolo') ?></summary><p class="my-3"><?= __('L’articolo e il suo PDF saranno eliminati. La testata rimane disponibile.') ?></p><form method="post" action="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/delete')) ?>" onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo articolo e il suo PDF?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);"><input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>"><input type="hidden" name="revision" value="<?= (int)$row['revision'] ?>"><button type="submit" class="btn-secondary"><?= __('Conferma eliminazione') ?></button></form></details><?php endif; endif; ?>
</div>
