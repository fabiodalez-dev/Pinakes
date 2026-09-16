<?php
/**
 * Rendered by DesiderataPlugin::home() and ::page().
 *
 * @var list<array<string,mixed>> $books
 * @var bool|null $standalone
 * @var array<string,mixed>|null $values
 * @var string|null $error
 */
$e = [DesiderataPlugin::class, 'e'];
$headingTag = !empty($standalone) ? 'h1' : 'h2';
$values = $values ?? [];
$value = static fn(string $key): string => is_scalar($values[$key] ?? '') ? $e($values[$key] ?? '') : '';
$success = !empty($_SESSION['desiderata_success']); unset($_SESSION['desiderata_success']);
?>
<section class="desiderata-section" aria-labelledby="desiderata-heading">
  <div class="container">
    <p class="dw-eyebrow"><?= __('Cresciamo insieme') ?></p>
    <<?= $headingTag ?> id="desiderata-heading"><?= __('I libri che cerchiamo') ?></<?= $headingTag ?>>
    <p><?= __('Aiutaci ad arricchire la biblioteca. Puoi offrire un libro richiesto oppure proporre un altro titolo.') ?></p>
    <label for="desiderata-search"><?= __('Cerca tra i desiderata') ?></label>
    <input id="desiderata-search" type="search" maxlength="120" class="form-input" placeholder="<?= $e(__('Titolo, autore o ISBN, almeno 3 caratteri')) ?>" aria-describedby="desiderata-search-status" autocomplete="off">
    <p id="desiderata-search-status" class="dw-muted" role="status"><?= __('Ultimi libri richiesti. Scrivi almeno 3 caratteri per cercare.') ?></p>
    <ul id="desiderata-results" class="dw-results">
      <?php foreach ($books as $b): ?>
      <li><div><strong><?= $e($b['titolo']) ?></strong><p class="dw-muted"><?= $e(implode(' · ', array_filter([$b['autore'], $b['editore'], $b['isbn13'] ?: $b['isbn10']]))) ?></p></div>
      <button type="button" class="btn btn-outline-primary dw-select" data-book="<?= $e(json_encode($b, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"><?= __('Ce l’ho, posso donarlo') ?></button></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$books): ?><p id="desiderata-empty"><?= __('Non ci sono richieste aperte. Puoi comunque proporre un libro da donare.') ?></p><?php endif; ?>
    <noscript><p><a href="<?= $e(url('/desiderata')) ?>"><?= __('Apri il modulo di donazione') ?></a></p></noscript>
    <div class="dw-form" id="donation-form">
      <h3><?= __('Proponi una donazione') ?></h3>
      <p><?= __('La biblioteca valuterà la proposta e ti contatterà per concordare la consegna. L’invio non aggiunge libri o copie al catalogo.') ?></p>
      <?php if ($success): ?><p role="status" class="alert alert-success"><?= __('Grazie! La proposta è stata inviata alla biblioteca.') ?></p><?php endif; ?>
      <?php if (!empty($error)): ?><p role="alert" class="alert alert-danger"><?= $e($error) ?></p><?php endif; ?>
      <form method="post" action="<?= $e(url('/desiderata/offers')) ?>" id="desiderata-offer">
        <input type="hidden" name="csrf_token" value="<?= $e(session_status() === PHP_SESSION_ACTIVE ? \App\Support\Csrf::ensureToken() : '') ?>">
        <input type="hidden" name="book_id" id="donation-book-id" value="<?= $value('book_id') ?>">
        <div hidden aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <p id="donation-match" role="status"></p>
        <button type="button" id="donation-clear" class="btn btn-outline-primary" hidden><?= __('Proponi un altro libro') ?></button>
        <div class="dw-fields">
          <?php foreach (['donor_name' => [__('Il tuo nome'), 150, 'text', true], 'donor_email' => [__('Email per essere contattato'), 254, 'email', true], 'title' => [__('Titolo del libro'), 255, 'text', true], 'author' => [__('Autore'), 255, 'text', false], 'publisher' => [__('Editore'), 255, 'text', false], 'isbn' => [__('ISBN (facoltativo)'), 20, 'text', false]] as $key => [$label, $max, $type, $required]): ?>
          <div><label for="donation-<?= $key ?>"><?= $e($label) ?><?= $required ? ' *' : '' ?></label><input class="form-input" id="donation-<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" maxlength="<?= $max ?>" value="<?= $value($key) ?>" <?= $required ? 'required' : '' ?>></div>
          <?php endforeach; ?>
        </div>
        <label for="donation-notes"><?= __('Condizioni del libro e note (facoltativo)') ?></label>
        <textarea class="form-input" id="donation-notes" name="notes" maxlength="2000" rows="3"><?= $value('notes') ?></textarea>
        <label class="dw-consent"><input type="checkbox" name="consent" value="1" required <?= ($values['consent'] ?? '') === '1' ? 'checked' : '' ?>> <span><?= __('Acconsento a essere contattato dalla biblioteca per questa proposta.') ?> <a href="<?= $e(route_path('privacy')) ?>"><?= __('Informativa privacy') ?></a></span></label>
        <button class="btn btn-primary" type="submit"><?= __('Invia la proposta') ?></button>
      </form>
    </div>
  </div>
</section>
<style>
.desiderata-section [hidden]{display:none!important}.desiderata-section{padding:3.5rem 0;color:var(--text-primary,inherit)}.desiderata-section .container{max-width:1120px;margin:auto;padding:0 1.25rem}.desiderata-section #desiderata-heading{font-size:2rem;margin:0 0 1rem}.desiderata-section h3{font-size:1.5rem}.desiderata-section p{max-width:72ch;margin:.5rem 0 1rem}.dw-eyebrow{font-weight:600;color:var(--primary-color,inherit)}.dw-muted{color:var(--text-secondary,#59616c);font-size:.9rem}.desiderata-section label{display:block;font-weight:500;margin:1rem 0 .5rem}.desiderata-section .form-input{width:100%;min-height:44px;padding:.7rem;border:1px solid var(--border-color,#cbd0d6);border-radius:6px;background:var(--card-bg,#f9fafb);color:inherit;font:inherit}.dw-results{list-style:none;padding:0;margin:1rem 0 2rem}.dw-results li{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border-color,#d7dce0)}.dw-results strong{font-size:1.1rem}.dw-results p{margin:.25rem 0}.dw-results button{flex-shrink:0}.dw-form{border-top:1px solid var(--border-color,#d7dce0);padding-top:2rem}.dw-fields{display:grid;grid-template-columns:1fr 1fr;gap:0 1.5rem}.desiderata-section .dw-consent{display:flex;align-items:flex-start;gap:.7rem;margin:1.5rem 0}.dw-consent input{margin-top:.3rem;min-width:18px;min-height:18px}.desiderata-section .btn{min-height:44px;white-space:normal}.desiderata-section :focus-visible{outline:3px solid var(--primary-color,#406187);outline-offset:3px}@media(max-width:640px){.dw-fields{grid-template-columns:1fr}.dw-results li{align-items:flex-start;flex-direction:column}}
</style>
<script>
(() => {
  const root = document.querySelector('.desiderata-section');
  const search = root.querySelector('#desiderata-search'), results = root.querySelector('#desiderata-results'), status = root.querySelector('#desiderata-search-status');
  const form = root.querySelector('#desiderata-offer'), match = root.querySelector('#donation-match'), clear = root.querySelector('#donation-clear');
  const original = results.innerHTML;
  const text = <?= json_encode(['loading'=>__('Ricerca in corso…'), 'error'=>__('Ricerca non riuscita. Riprova tra poco.'), 'hint'=>__('Ultimi libri richiesti. Scrivi almeno 3 caratteri per cercare.'), 'empty'=>__('Nessun desiderata trovato. Puoi proporre un altro libro qui sotto.'), 'found'=>__('Libri trovati:'), 'offer'=>__('Ce l’ho, posso donarlo'), 'selected'=>__('Stai offrendo un libro richiesto:')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function select(book) {
    form.elements.book_id.value = book.id;
    for (const [field,value] of Object.entries({title:book.titolo,author:book.autore,publisher:book.editore,isbn:book.isbn13 || book.isbn10})) form.elements[field].value = value || '';
    form.elements.title.readOnly = true;
    match.textContent = text.selected + ' ' + book.titolo; clear.hidden = false;
    form.elements.donor_name.focus();
  }
  results.addEventListener('click', event => { const button = event.target.closest('.dw-select'); if(button) select(JSON.parse(button.dataset.book)); });
  clear.addEventListener('click', () => { form.elements.book_id.value=''; form.elements.title.readOnly=false; for(const key of ['title','author','publisher','isbn']) form.elements[key].value=''; match.textContent=''; clear.hidden=true; form.elements.title.focus(); });
  if(form.elements.book_id.value) { match.textContent=text.selected+' '+form.elements.title.value; clear.hidden=false; form.elements.title.readOnly=true; }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const submit=form.querySelector('button[type="submit"]'); submit.disabled=true;
    try {
      const response=await fetch(<?= json_encode(url('/csrf-token'), JSON_HEX_TAG) ?>,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      if(!response.ok) throw new Error(); const data=await response.json();
      if(typeof data.token!=='string' || !data.token) throw new Error();
      form.elements.csrf_token.value=data.token; HTMLFormElement.prototype.submit.call(form);
    } catch(error) { match.textContent=<?= json_encode(__('Invio non riuscito. I dati sono conservati: riprova.'), JSON_HEX_TAG) ?>; match.setAttribute('role','alert'); submit.disabled=false; }
  });
  let timer, controller, revision=0;
  search.addEventListener('input', () => {
    clearTimeout(timer); controller?.abort(); const current=++revision, q=search.value.trim();
    const empty=root.querySelector('#desiderata-empty'); if(empty) empty.hidden=q.length>=3;
    if([...q].length<3) { results.innerHTML=original; status.textContent=text.hint; results.removeAttribute('aria-busy'); return; }
    timer=setTimeout(async () => {
      controller=new AbortController(); status.textContent=text.loading; results.setAttribute('aria-busy','true');
      try {
        const response=await fetch(<?= json_encode(url('/desiderata/search'), JSON_HEX_TAG) ?>+'?q='+encodeURIComponent(q),{signal:controller.signal,headers:{Accept:'application/json'}});
        if(!response.ok) throw new Error(); const books=await response.json(); if(current!==revision) return;
        results.replaceChildren();
        for(const b of books) {
          const li=document.createElement('li'), info=document.createElement('div'), title=document.createElement('strong'), details=document.createElement('p'), button=document.createElement('button');
          title.textContent=b.titolo; details.className='dw-muted'; details.textContent=[b.autore,b.editore,b.isbn13||b.isbn10].filter(Boolean).join(' · ');
          button.type='button'; button.className='btn btn-outline-primary dw-select'; button.textContent=text.offer; button.dataset.book=JSON.stringify(b);
          info.append(title,details); li.append(info,button); results.append(li);
        }
        status.textContent=books.length?text.found+' '+books.length:text.empty;
      } catch(error) { if(error.name!=='AbortError' && current===revision) status.textContent=text.error; }
      finally { if(current===revision) results.removeAttribute('aria-busy'); }
    },250);
  });
})();
</script>
