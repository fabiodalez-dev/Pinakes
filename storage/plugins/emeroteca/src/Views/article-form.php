<?php
declare(strict_types=1);
/*
 * Article form (#412). The chrome follows the book form verbatim
 * (app/Views/libri/crea_libro.php + partials/book_form.php): page shell,
 * breadcrumb, header, one card per section, the action row at the bottom.
 * The optional sections are cards that fold (<details class="card">), so a
 * library that only needs a citation never meets the MARC worksheet.
 */
$row=$row??[]; $error=$error??null;
$e=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');
$labels=['titolo'=>__('Titolo'),'sottotitolo'=>__('Sottotitolo'),'autori'=>__('Autori'),'contenitore_titolo'=>__('Titolo della pubblicazione'),'data_pubblicazione_testo'=>__('Data di pubblicazione'),'anno_pubblicazione'=>__('Anno'),'volume'=>__('Volume'),'numero'=>__('Numero'),'pagine'=>__('Pagine'),'keywords'=>__('Parole chiave'),'issn'=>'ISSN','doi'=>'DOI','collocazione'=>__('Collocazione'),'lingua'=>__('Lingua'),'paese'=>__('Paese'),'classificazione_schema'=>__('Schema di classificazione'),'classificazione'=>__('Classificazione'),'nota_possesso'=>__('Nota di possesso')];
$isEdit=!empty($row['id']);
$max=static fn(string $key):int=>$key==='anno_pubblicazione'?4:\App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS[$key];
// One text field: label, input, optional hint. Same markup as the book form.
$field=static function(string $key,string $hint='',bool $wide=false,bool $required=false) use($row,$e,$labels,$max):void{
    ?><div<?= $wide?' class="md:col-span-2"':'' ?>><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($labels[$key]) ?><?php if($required): ?> <span class="text-red-500">*</span><?php endif; ?></label><input class="form-input" id="article-<?= $e($key) ?>" name="<?= $e($key) ?>" value="<?= $e($row[$key]??'') ?>" maxlength="<?= $max($key) ?>"<?= $required?' required':'' ?>><?php if($hint!==''): ?><p class="text-xs text-gray-500 mt-1"><?= $e($hint) ?></p><?php endif; ?></div><?php
};
$select=static function(string $key,string $label,array $choices,string $default,string $hint='') use($row,$e):void{
    ?><div><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($label) ?></label><select id="article-<?= $e($key) ?>" class="form-input" name="<?= $e($key) ?>"><?php foreach($choices as $value=>$text): ?><option value="<?= $e($value) ?>" <?= ($row[$key]??$default)===$value?'selected':'' ?>><?= $e(__($text)) ?></option><?php endforeach; ?></select><?php if($hint!==''): ?><p class="text-xs text-gray-500 mt-1"><?= $e($hint) ?></p><?php endif; ?></div><?php
};
$checkbox=static function(string $name,string $label,bool $checked,string $extra='') use($e):void{
    ?><label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" name="<?= $e($name) ?>" value="1" class="w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500"<?= $checked?' checked':'' ?><?= $extra ?>><span><?= $e($label) ?></span></label><?php
};
// Card header, used both as a plain header and as the <summary> of a folding card.
$cardTitle=static function(string $icon,string $title,string $subtitle='',bool $fold=false) use($e):void{
    ?><h2 class="form-section-title flex items-center gap-2"><i class="fas <?= $e($icon) ?> text-gray-900" aria-hidden="true"></i><?= $e($title) ?><?php if($fold): ?><i class="fas fa-chevron-down text-xs text-gray-500 ml-auto article-fold-chevron" aria-hidden="true"></i><?php endif; ?></h2><?php if($subtitle!==''): ?><p class="text-sm text-gray-600 mt-1"><?= $e($subtitle) ?></p><?php endif;
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
        <li class="text-gray-900 font-medium"><?= $isEdit?__('Modifica'):__('Nuovo') ?></li>
      </ol>
    </nav>

    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= $isEdit?__('Modifica articolo'):__('Aggiungi articolo') ?></h1>
      <p class="text-gray-600"><?= __('Un articolo di rivista o di giornale, o un capitolo di un’antologia, anche senza possedere la pubblicazione intera.') ?></p>
    </div>

    <?php if($error): ?>
    <div class="mb-6 p-4 rounded-xl border border-red-200 bg-red-50 text-red-700" role="alert"><i class="fas fa-exclamation-triangle mr-2"></i><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e(url('/admin/periodicals/articles/save')) ?>" enctype="multipart/form-data" class="space-y-8" id="article-form">
      <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>"><input type="hidden" name="id" value="<?= (int)($row['id']??0) ?>"><input type="hidden" name="revision" value="<?= (int)($row['revision']??0) ?>">

      <div class="card">
        <div class="card-header"><?php $cardTitle('fa-file-alt',__('Informazioni Base')); ?></div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <?php $field('titolo','',true,true); ?>
            <?php $field('sottotitolo','',true); ?>
            <?php include __DIR__ . '/article-authors.php'; ?>
            <?php /* The authors field asks for a semicolon, so a cataloguer who read
                     that instruction reaches this one and uses a semicolon here too.
                     Keywords split on a COMMA everywhere in the plugin (public page,
                     JSON-LD, RIS), so a semicolon silently yields one keyword. */ ?>
            <?php $field('keywords',__('Separa le parole chiave con una virgola, non con un punto e virgola come gli autori.')); ?>
            <?php $select('tipo_contributo',__('Tipo di contributo'),\EmerotecaPlugin::TIPI_ARTICOLO,'articolo'); ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><?php $cardTitle('fa-book-open',__('Pubblicazione'),__('La rivista, il giornale o il volume in cui l’articolo è apparso.')); ?></div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <?php $field('contenitore_titolo'); ?>
            <?php $select('contenitore_tipo',__('Tipo di pubblicazione'),[''=>__('Non specificato')]+\EmerotecaPlugin::TIPI_CONTENITORE,''); ?>
            <?php $field('data_pubblicazione_testo',__('Per un giornale indica la data completa, per esempio 28-09-2026: la citazione riporta giorno e mese. Per una rivista bastano mese e anno, per esempio giugno 2019.')); ?>
            <?php $field('anno_pubblicazione'); ?>
          </div>
          <div class="form-grid-3">
            <?php $field('volume'); ?>
            <?php $field('numero'); ?>
            <?php $field('pagine'); ?>
          </div>
          <div class="form-grid-2">
            <?php $select('supporto',__('Formato'),['cartaceo'=>'Cartaceo','digitale'=>'Digitale','entrambi'=>'Cartaceo e digitale'],'cartaceo',__('In quale forma la biblioteca possiede l’articolo: su carta, in digitale o in entrambe.')); ?>
          </div>

          <?php /* A chapter in an anthology (#412): the host is a book, cited with its
                   editors, publisher and place. Shown when "Anthology" is the
                   publication type; without JavaScript it is simply always there. */ ?>
          <div id="article-host-volume">
            <h3 class="text-lg font-semibold text-gray-900 mb-2"><?= __('Volume ospite (per un’antologia)') ?></h3>
            <p class="text-sm text-gray-600 mb-4"><?= __('Il «Titolo della pubblicazione» è il titolo del volume. Questi dati servono alla citazione del capitolo.') ?></p>
            <div class="form-grid-2">
              <?php foreach(['contenitore_curatori'=>[__('Curatori del volume'),__('Separa più curatori con un punto e virgola, per esempio Petersen, Hans Uwe.')],'contenitore_editore'=>[__('Editore'),''],'contenitore_luogo'=>[__('Luogo di pubblicazione'),''],'isbn'=>['ISBN',__('L’ISBN del volume.')]] as $key=>[$label,$hint]): ?><div<?= $key==='contenitore_curatori'?' class="md:col-span-2"':'' ?>><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($label) ?></label><input class="form-input" id="article-<?= $e($key) ?>" name="<?= $e($key) ?>" value="<?= $e($row[$key]??'') ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS[$key] ?>"><?php if($hint!==''): ?><p class="text-xs text-gray-500 mt-1"><?= $e($hint) ?></p><?php endif; ?></div><?php endforeach; ?>
            </div>
          </div>
          <script>
          (function () {
            var type = document.getElementById('article-contenitore_tipo');
            var host = document.getElementById('article-host-volume');
            if (!type || !host) { return; }
            // Hidden and disabled together: what the browser posts is what the
            // cataloguer can see (the server drops these fields too).
            function sync() {
              var off = type.value !== 'antologia';
              host.hidden = off;
              host.querySelectorAll('input, textarea, select').forEach(function (field) { field.disabled = off; });
            }
            type.addEventListener('change', sync);
            sync();
          })();
          </script>

          <?php /* Uwe (#412) could not find "Associated publication" on this form: it is
                   not a field here, because linking to a masthead record goes through a
                   preview in the article list. Say what it is, and what it is now. */ ?>
          <div class="p-3 bg-gray-50 rounded-lg border border-gray-200 text-sm" id="article-host-record">
            <p class="font-medium text-gray-900"><i class="fas fa-link mr-2 text-gray-900" aria-hidden="true"></i><?= __('Testata associata') ?>: <?= !empty($hostTitle) ? $e($hostTitle) : __('Non associato') ?></p>
            <p class="text-gray-600 mt-1"><?= __('«Titolo della pubblicazione» è il nome della rivista o del giornale come lo scrivi in questa scheda. La testata associata è la scheda di quella rivista nell’Emeroteca, con le sue annate e i suoi fascicoli: è facoltativa, e si collega dalla lista Articoli, selezionando l’articolo e aprendo «Associa gli articoli selezionati a una testata».') ?></p>
            <p class="mt-2"><a class="text-gray-900 hover:underline font-medium" href="<?= $e(url('/admin/periodicals/articles')) ?>"><?= __('Apri la lista Articoli') ?></a></p>
          </div>
        </div>
      </div>

      <details class="card article-fold">
        <summary class="card-header cursor-pointer"><?php $cardTitle('fa-barcode',__('Identificativi e collocazione'),'',true); ?></summary>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <?php $field('issn'); ?>
            <?php $field('doi'); ?>
            <?php $field('collocazione',__('Dove si trova la copia: scatola d’archivio, faldone, cartella.')); ?>
          </div>
        </div>
      </details>

      <?php /* The analytic apparatus, folded away. A library that only needs a
               citation never opens this; a librarian cataloguing an offprint finds
               the whole of danMARC2/MARC 21 008, 084 and the holdings note in one
               place. Every field here is optional. */ ?>
      <details class="card article-fold">
        <summary class="card-header cursor-pointer"><?php $cardTitle('fa-tags',__('Descrizione bibliografica avanzata (facoltativa)'),__('Serve a chi cataloga secondo uno standard bibliografico. Lasciando tutto vuoto la scheda resta valida.'),true); ?></summary>
        <div class="card-body form-section">
          <div class="form-grid-2">
<?php /* Language, country and scheme are picked, not typed (#412): the columns
         hold ISO codes so that a record reads in each user's language, and
         the pickers list the names, in the language of whoever catalogues,
         searchable by name or by code. A stored code outside the lists (a
         two-letter "da" from before the pickers) stays selectable, so opening
         and saving a record never changes it behind the cataloguer's back. */
$otherScheme=\App\Plugins\Emeroteca\Services\ContributionService::OTHER_SCHEME;
$schemes=['DDC'=>__('DDC — Classificazione decimale Dewey'),'DK5'=>__('DK5 — Classificazione decimale danese'),'UDC'=>__('UDC — Classificazione decimale universale'),'LCC'=>__('LCC — Classificazione della Library of Congress'),'RVK'=>__('RVK — Regensburger Verbundklassifikation')];
$scheme=trim((string)($row['classificazione_schema']??''));$schemeOther=trim((string)($row['classificazione_schema_altro']??''));
if($scheme!==''&&$scheme!==$otherScheme){if(isset($schemes[strtoupper($scheme)])){$scheme=strtoupper($scheme);}else{$schemeOther=$scheme;$scheme=$otherScheme;}}
?>
            <?php foreach(['lingua'=>['language',__('Scrivi il nome o il codice, per esempio «danese» o «dan».'),__('Codice ISO 639, per esempio dan, ita, eng.')],'paese'=>['country',__('Scrivi il nome o il codice, per esempio «Danimarca» o «DK».'),__('Codice ISO 3166, per esempio DK, IT.')]] as $key=>[$kind,$hint,$codeHint]): ?>
            <?php $pickerId='article-'.$key; $pickerName=$key; $pickerLabel=$labels[$key]; $pickerKind=$kind; $pickerCurrent=(string)($row[$key]??''); $pickerHint=$hint; $pickerCodeHint=$codeHint; $pickerMax=\App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS[$key]; $pickerError=''; $pickerEmpty=true; include __DIR__.'/code-picker.php'; ?>
            <?php endforeach; ?>
            <div><label for="article-classificazione_schema" class="form-label"><?= $e($labels['classificazione_schema']) ?></label><select class="form-input" id="article-classificazione_schema" name="classificazione_schema"><option value=""><?= __('Non specificato') ?></option><?php foreach($schemes as $code=>$name): ?><option value="<?= $e($code) ?>"<?= $scheme===$code?' selected':'' ?>><?= $e($name) ?></option><?php endforeach; ?><option value="<?= $e($otherScheme) ?>"<?= $scheme===$otherScheme?' selected':'' ?>><?= __('Altro schema') ?></option></select><p class="text-xs text-gray-500 mt-1"><?= __('Con la Dewey la notazione si sceglie dall’elenco, come per i libri.') ?></p></div>
            <div id="article-scheme-other"><label for="article-classificazione_schema_altro" class="form-label"><?= __('Nome dello schema') ?></label><input class="form-input" id="article-classificazione_schema_altro" name="classificazione_schema_altro" value="<?= $e($schemeOther) ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS['classificazione_schema'] ?>"><p class="text-xs text-gray-500 mt-1"><?= __('Solo se hai scelto «Altro schema».') ?></p></div>
            <?php /* The Dewey picker comes first in the page on purpose: both it and the
                     text box post "classificazione", and without JavaScript (which is
                     what switches one of them off) PHP keeps the last one, the text box
                     the operator can actually see and use. */ ?>
            <div class="md:col-span-2"><div id="article-class-dewey" hidden><?php $deweyFieldName='classificazione'; $deweyValue=(string)($row['classificazione']??''); include dirname(__DIR__, 5) . '/app/Views/partials/dewey-picker.php'; ?></div>
            <div id="article-class-text"><label for="article-classificazione" class="form-label"><?= $e($labels['classificazione']) ?></label><input class="form-input" id="article-classificazione" name="classificazione" value="<?= $e($row['classificazione']??'') ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS['classificazione'] ?>"><p class="text-xs text-gray-500 mt-1"><?= __('La notazione, per esempio 33.129.') ?></p></div></div>
            <?php $field('nota_possesso',__('Che cosa possiede la biblioteca di questo articolo, per esempio una fotocopia o un estratto, e non l’intera annata. Il formato, su carta o digitale, si sceglie più sopra.'),true); ?>
          </div>
          <script>
          document.addEventListener('DOMContentLoaded', function () {
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
            const looksDewey = (value) => /^[0-9]{3}(\.[0-9]+)?$/.test(value);
            // A stored DDC notation the picker cannot show ("823.914 BRO") stays
            // in the text box on load, visible and editable, rather than hiding
            // behind an empty picker. Choosing a scheme again ends that.
            let keepText = scheme.value === 'DDC' && text.value.trim() !== '' && !looksDewey(text.value.trim());
            function sync() {
              const isDewey = scheme.value === 'DDC' && !keepText;
              other.hidden = scheme.value !== <?= json_encode($otherScheme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              textBox.hidden = isDewey;
              text.disabled = isDewey;
              deweyBox.hidden = !isDewey;
              dewey.disabled = !isDewey;
              if (isDewey && !deweyStarted && typeof window.initializeDewey === 'function') {
                deweyStarted = true;
                // A notation typed under another scheme is not a Dewey code; one
                // deeper than the Dewey list (823.91409) is shown as it is.
                const typed = text.value.trim();
                const code = looksDewey(typed) ? typed : '';
                dewey.value = code;
                window.initializeDewey(code);
              }
            }
            // Choosing another scheme ends that, but coming back to Dewey with the
            // stored notation untouched brings it back: a round trip through the
            // select must not turn "823.914 BRO" into an empty picker and a NULL.
            const initialKeep = keepText;
            const initialText = text.value.trim();
            scheme.addEventListener('change', () => {
              keepText = initialKeep && scheme.value === 'DDC' && text.value.trim() === initialText;
              sync();
            });
            sync();
          });
          </script>
        </div>
      </details>

      <?php /* danMARC2 856 / MARC 21 856: where the digital copy lives, what the
               link should say, and who may open it. */ ?>
      <details class="card article-fold">
        <summary class="card-header cursor-pointer"><?php $cardTitle('fa-globe',__('Risorsa elettronica (facoltativa)'),'',true); ?></summary>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div class="md:col-span-2"><label for="article-risorsa_url" class="form-label"><?= __('Indirizzo o percorso della risorsa') ?></label><input class="form-input" id="article-risorsa_url" name="risorsa_url" value="<?= $e($row['risorsa_url']??'') ?>" maxlength="500"><p class="text-xs text-gray-500 mt-1"><?= __('Un indirizzo http o https diventa un collegamento; un percorso locale o un identificativo di archivio resta testo e non viene mai reso cliccabile.') ?></p></div>
            <div><label for="article-risorsa_testo" class="form-label"><?= __('Testo del collegamento') ?></label><input class="form-input" id="article-risorsa_testo" name="risorsa_testo" value="<?= $e($row['risorsa_testo']??'') ?>" maxlength="255"></div>
            <div><label for="article-risorsa_accesso" class="form-label"><?= __('Condizioni di accesso') ?></label><input class="form-input" id="article-risorsa_accesso" name="risorsa_accesso" value="<?= $e($row['risorsa_accesso']??'') ?>" maxlength="255"></div>
          </div>
          <?php $resourceMissing=trim((string)($row['risorsa_url']??''))===''; ?>
          <div>
            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" id="article-risorsa_pubblica" name="risorsa_pubblica" value="1" class="w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500" <?= !empty($row['risorsa_pubblica'])?'checked':'' ?><?= $resourceMissing?' disabled aria-disabled="true"':'' ?> aria-describedby="article-risorsa-note"><span><?= __('Mostra la risorsa elettronica nel catalogo pubblico') ?></span></label>
            <p class="text-xs text-gray-500 mt-1" id="article-risorsa-note"<?= $resourceMissing?'':' hidden' ?>><?= __('Indica prima l’indirizzo o il percorso della risorsa: senza, non c’è nulla da mostrare nel catalogo pubblico.') ?></p>
          </div>
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
        </div>
      </details>

      <?php /* Uploads use the plugin's Uppy mount (assets/js/emeroteca-upload.js),
               the same drop area as the book cover. The hidden inputs keep the
               names the controller reads. */ ?>
      <div class="card">
        <div class="card-header"><?php $cardTitle('fa-image',__('Immagine')); ?></div>
        <div class="card-body">
          <div id="article-cover-upload" data-emt-uppy="image" data-input="article-copertina" data-progress="article-cover-progress" data-preview="article-cover-preview" data-preview-image="article-cover-preview-image" data-note="<?= $e(__('Immagini JPG, PNG o WebP (max 5MB)')) ?>" data-drop="<?= $e(__("Trascina qui l'immagine o %{browse}")) ?>" data-browse="<?= $e(__('seleziona file')) ?>" class="mb-4"></div>
          <div id="article-cover-progress" class="mb-4"></div>
          <input id="article-copertina" type="file" name="copertina" accept="image/jpeg,image/png,image/webp" hidden>
          <?php $cover=(string)($row['copertina_url']??''); ?>
          <div id="article-cover-preview" class="mt-4 inline-flex flex-col items-start space-y-2">
            <img id="article-cover-preview-image" src="<?= $cover!==''?$e(url($cover)):'' ?>" alt="" class="max-h-48 object-contain border border-gray-200 rounded-lg shadow-sm"<?= $cover===''?' hidden':'' ?>>
            <?php if($cover!==''): ?><div class="flex items-center gap-2"><span class="text-xs text-gray-500"><?= __('Immagine attuale') ?></span></div><?php $checkbox('remove_copertina',__('Rimuovi immagine'),false); ?><?php endif; ?>
          </div>
        </div>
      </div>

      <details class="card article-fold">
        <summary class="card-header cursor-pointer"><?php $cardTitle('fa-file-pdf',__('Descrizione, note e PDF'),'',true); ?></summary>
        <div class="card-body form-section">
          <?php foreach(['abstract'=>__('Descrizione'),'note_private'=>__('Note private')] as $key=>$label): ?><div><label for="article-<?= $e($key) ?>" class="form-label"><?= $e($label) ?></label><textarea id="article-<?= $e($key) ?>" class="form-input" name="<?= $e($key) ?>" rows="4" maxlength="10000"><?= $e($row[$key]??'') ?></textarea></div><?php endforeach; ?>
          <div>
            <p class="form-label"><?= __('PDF (massimo 25 MB)') ?></p>
            <?php if(!empty($row['pdf_path'])): ?><div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-200 flex flex-col sm:flex-row sm:items-center gap-3"><div class="flex items-center gap-2 text-sm min-w-0"><i class="fas fa-file-pdf text-gray-900" aria-hidden="true"></i><a class="font-medium text-gray-900 hover:underline truncate" href="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/pdf')) ?>"><?= $e($row['pdf_nome_originale']) ?></a></div><?php $checkbox('remove_pdf',__('Rimuovi PDF'),false); ?></div><?php endif; ?>
            <div id="article-pdf-upload" data-emt-uppy="pdf" data-max-bytes="26214400" data-input="article-pdf" data-progress="article-pdf-progress" data-result="article-pdf-result" data-note="<?= $e(__('PDF (massimo 25 MB)')) ?>" data-drop="<?= $e(__('Trascina qui il PDF o %{browse}')) ?>" data-browse="<?= $e(__('seleziona file')) ?>" class="mb-4"></div>
            <div id="article-pdf-progress" class="mb-4"></div>
            <p id="article-pdf-result" class="flex items-center gap-2 text-sm text-gray-600" hidden></p>
            <input id="article-pdf" type="file" name="pdf" accept="application/pdf,.pdf" hidden>
          </div>
          <?php $checkbox('pdf_pubblico',__('Consenti la consultazione pubblica del PDF'),!empty($row['pdf_pubblico'])); ?>
        </div>
      </details>

      <div class="card">
        <div class="card-header"><?php $cardTitle('fa-eye',__('Visibilità')); ?></div>
        <div class="card-body">
          <?php $checkbox('pubblico',__('Mostra l’articolo nel catalogo pubblico'),!empty($row['pubblico'])); ?>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= $e(url('/admin/periodicals/articles')) ?>" class="btn-secondary order-2 sm:order-1"><i class="fas fa-times mr-2"></i><?= __('Annulla') ?></a>
        <button type="submit" class="btn-primary order-1 sm:order-2"><i class="fas fa-save mr-2"></i><?= __('Salva articolo') ?></button>
      </div>
    </form>

    <?php if($isEdit): ?>
    <div class="card mt-8">
      <div class="card-header"><?php $cardTitle('fa-quote-right',__('Citazione')); ?></div>
      <div class="card-body flex flex-col sm:flex-row gap-4">
        <a class="btn-secondary" href="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/citation.ris')) ?>"><i class="fas fa-download mr-2"></i><?= __('Scarica la citazione in formato RIS (EndNote, Mendeley, Zotero)') ?></a>
        <a class="btn-secondary" href="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/marc.xml')) ?>"><i class="fas fa-code mr-2"></i>MARCXML</a>
      </div>
    </div>
    <?php if(($_SESSION['user']['tipo_utente']??'')==='admin'): ?>
    <details class="card article-fold mt-8">
      <summary class="card-header cursor-pointer"><h2 class="form-section-title flex items-center gap-2 text-red-700"><i class="fas fa-trash text-red-700" aria-hidden="true"></i><?= __('Elimina articolo') ?><i class="fas fa-chevron-down text-xs text-gray-500 ml-auto article-fold-chevron" aria-hidden="true"></i></h2></summary>
      <div class="card-body">
        <p class="text-sm text-gray-600 mb-4"><?= __('L’articolo e il suo PDF saranno eliminati. La testata rimane disponibile.') ?></p>
        <form method="post" action="<?= $e(url('/admin/periodicals/articles/'.(int)$row['id'].'/delete')) ?>" onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo articolo e il suo PDF?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);"><input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>"><input type="hidden" name="revision" value="<?= (int)$row['revision'] ?>"><button type="submit" class="btn-danger"><i class="fas fa-trash mr-2"></i><?= __('Conferma eliminazione') ?></button></form>
      </div>
    </details>
    <?php endif; endif; ?>
  </div>
</div>
<style>
  /* A folded section is a card whose header is the <summary>: closed, the
     header must not keep the bottom margin and rule that separate it from a
     body that is not there. */
  #article-form ~ .article-fold:not([open]) > summary.card-header,
  #article-form .article-fold:not([open]) > summary.card-header { margin-bottom: -1.5rem; border-bottom: 0; }
  .article-fold > summary.card-header { list-style: none; }
  .article-fold:not([open]) > summary .form-section-title { border-bottom: 0; padding-bottom: 0; }
  .article-fold > summary.card-header::-webkit-details-marker { display: none; }
  .article-fold-chevron { transition: transform .2s ease; }
  .article-fold[open] > summary .article-fold-chevron { transform: rotate(180deg); }
  @media (prefers-reduced-motion: reduce) { .article-fold-chevron { transition: none; } }
  #article-host-volume[hidden], #article-cover-preview img[hidden] { display: none; }
</style>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-upload.js?v=1.3.0')) ?>" defer></script>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-code-picker.js?v=1.0.0')) ?>" defer></script>
