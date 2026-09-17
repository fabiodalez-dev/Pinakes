<?php
/**
 * Style and behaviour for every donation form on the page, printed once.
 *
 * `static $printed` would NOT work here: an included file is re-evaluated by
 * each require, and a static declared at its top level binds to the including
 * function, so the flag comes back false. The guard therefore lives on the
 * plugin class, whose statics really do last for the request.
 *
 * @var string|null $recaptchaSiteKey empty = this installation has no reCAPTCHA
 */
if (DesiderataPlugin::assetsAlreadyPrinted()) { return; }
$recaptchaSiteKey = is_string($recaptchaSiteKey ?? null) ? $recaptchaSiteKey : '';
?>
<?php if ($recaptchaSiteKey !== ''): ?>
<?php // No integrity= here, exactly like the contact form: Google serves a
      // dynamic loader whose bytes change, so an SRI hash would stop matching
      // and the widget would fail silently, taking the form with it. ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= rawurlencode($recaptchaSiteKey) ?>"></script>
<?php endif; ?>
<style>
.desiderata-section [hidden]{display:none!important}.desiderata-section{padding:3.5rem 0;color:var(--text-primary,inherit)}.desiderata-section .container{max-width:1120px;margin:auto;padding:0 1.25rem}.desiderata-section #desiderata-heading{font-size:2rem;margin:0 0 1rem}.desiderata-section h3{font-size:1.5rem}.desiderata-section p{max-width:72ch;margin:.5rem 0 1rem}.dw-eyebrow{font-weight:600;color:var(--primary-color,inherit)}.dw-muted{color:var(--text-secondary,#59616c);font-size:.9rem}.desiderata-section label{display:block;font-weight:500;margin:1rem 0 .5rem}.desiderata-section .form-input{width:100%;min-height:44px;padding:.7rem;border:1px solid var(--border-color,#cbd0d6);border-radius:6px;background:var(--card-bg,#f9fafb);color:inherit;font:inherit}.dw-results{list-style:none;padding:0;margin:1rem 0 2rem}.dw-results li{display:flex;align-items:center;gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border-color,#d7dce0)}.dw-results li>div{flex:1 1 auto}.dw-cover{width:48px;height:68px;object-fit:cover;border-radius:4px;flex-shrink:0;background:var(--border-color,#e4e8ec)}.dw-results strong{font-size:1.1rem}.dw-results p{margin:.25rem 0}.dw-results button{flex-shrink:0}.dw-on-book{padding:2rem 0 0}.dw-on-book .container{max-width:none;padding:0}.dw-on-book h2{font-size:1.5rem;margin:0 0 .5rem}.dw-form{border-top:1px solid var(--border-color,#d7dce0);padding-top:2rem}.dw-form [hidden]{display:none!important}.dw-fields{display:grid;grid-template-columns:1fr 1fr;gap:0 1.5rem}.desiderata-section .dw-consent{display:flex;align-items:flex-start;gap:.7rem;margin:1.5rem 0}.dw-consent input{margin-top:.3rem;min-width:18px;min-height:18px}.desiderata-section .btn{min-height:44px;white-space:normal}.desiderata-section :focus-visible{outline:3px solid var(--primary-color,#406187);outline-offset:3px}@media(max-width:640px){.dw-fields{grid-template-columns:1fr}.dw-results li{align-items:flex-start;flex-direction:column}}
</style>
<script>
(() => {
  const text = <?= json_encode(['loading'=>__('Ricerca in corso…'), 'error'=>__('Ricerca non riuscita. Riprova tra poco.'), 'hint'=>__('Ultimi libri richiesti. Scrivi almeno 3 caratteri per cercare.'), 'empty'=>__('Nessun desiderata trovato. Puoi proporre un altro libro qui sotto.'), 'found'=>__('Libri trovati:'), 'offer'=>__('Ce l’ho, posso donarlo'), 'selected'=>__('Stai offrendo un libro richiesto:'), 'failed'=>__('Invio non riuscito. I dati sono conservati: riprova.')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrfUrl = <?= json_encode(url('/csrf-token'), JSON_HEX_TAG) ?>, searchUrl = <?= json_encode(url('/desiderata/search'), JSON_HEX_TAG) ?>;
  // Covers are resolved server-side (DesiderataPlugin::wanted()), so nothing
  // here has to know the installation's base path.
  const placeholderCover = <?= json_encode(url(DesiderataPlugin::PLACEHOLDER_COVER), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const siteKey = <?= json_encode($recaptchaSiteKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  // One page may hold more than one form (homepage section + a book page):
  // every widget is wired against its own root instead of the first match.
  const wired = new Map();
  document.querySelectorAll('[data-desiderata-form]').forEach(root => {
    const form = root.querySelector('form');
    if (!form) return;
    const match = root.querySelector('.dw-match'), clear = root.querySelector('.dw-clear');
    const select = book => {
      form.elements.book_id.value = book.id;
      for (const [field,value] of Object.entries({title:book.titolo,author:book.autore,publisher:book.editore,isbn:book.isbn13 || book.isbn10})) form.elements[field].value = value || '';
      form.elements.title.readOnly = true;
      if (match) match.textContent = text.selected + ' ' + book.titolo;
      if (clear) clear.hidden = false;
      form.elements.donor_name.focus();
    };
    if (clear) clear.addEventListener('click', () => { form.elements.book_id.value=''; form.elements.title.readOnly=false; for(const key of ['title','author','publisher','isbn']) form.elements[key].value=''; if(match) match.textContent=''; clear.hidden=true; form.elements.title.focus(); });
    if (form.elements.book_id.value) { if(match) match.textContent=text.selected+' '+form.elements.title.value; if(clear) clear.hidden=false; form.elements.title.readOnly=true; }
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const submit=form.querySelector('button[type="submit"]'); submit.disabled=true;
      try {
        // Before the CSRF refresh, not after: the token this produces is
        // single-use and short-lived, and the network round trip for the CSRF
        // token would age it for nothing.
        if (siteKey && window.grecaptcha) {
          await new Promise(resolve => grecaptcha.ready(resolve));
          form.elements.recaptcha_token.value = await grecaptcha.execute(siteKey, {action:'desiderata_offer'});
        }
        const response=await fetch(csrfUrl,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
        if(!response.ok) throw new Error(); const data=await response.json();
        if(typeof data.token!=='string' || !data.token) throw new Error();
        form.elements.csrf_token.value=data.token; HTMLFormElement.prototype.submit.call(form);
      } catch(error) { if(match){ match.textContent=text.failed; match.setAttribute('role','alert'); } submit.disabled=false; }
    });
    wired.set(root, {select});
  });
  document.querySelectorAll('[data-desiderata-search]').forEach(root => {
    const search = root.querySelector('.dw-search-input'), results = root.querySelector('.dw-results'), status = root.querySelector('.dw-status');
    if (!search || !results || !status) return;
    // The form that belongs to this widget: the one in the same section.
    const scope = root.closest('.desiderata-section') || document;
    const formRoot = scope.querySelector('[data-desiderata-form]');
    const api = formRoot ? wired.get(formRoot) : null;
    const original = results.innerHTML;
    results.addEventListener('click', event => { const button = event.target.closest('.dw-select'); if(button && api) api.select(JSON.parse(button.dataset.book)); });
    let timer, controller, revision=0;
    search.addEventListener('input', () => {
      clearTimeout(timer); controller?.abort(); const current=++revision, q=search.value.trim();
      const empty=root.querySelector('.dw-empty'); if(empty) empty.hidden=q.length>=3;
      if([...q].length<3) { results.innerHTML=original; status.textContent=text.hint; results.removeAttribute('aria-busy'); return; }
      timer=setTimeout(async () => {
        controller=new AbortController(); status.textContent=text.loading; results.setAttribute('aria-busy','true');
        try {
          const response=await fetch(searchUrl+'?q='+encodeURIComponent(q),{signal:controller.signal,headers:{Accept:'application/json'}});
          if(!response.ok) throw new Error(); const books=await response.json(); if(current!==revision) return;
          results.replaceChildren();
          for(const b of books) {
            const li=document.createElement('li'), cover=document.createElement('img'), info=document.createElement('div'), title=document.createElement('strong'), details=document.createElement('p'), button=document.createElement('button');
            // Same row as the server-rendered one, placeholder fallback included.
            cover.className='dw-cover'; cover.alt=''; cover.loading='lazy'; cover.decoding='async';
            cover.src=b.cover||placeholderCover; cover.addEventListener('error',()=>{cover.src=placeholderCover},{once:true});
            title.textContent=b.titolo; details.className='dw-muted'; details.textContent=[b.autore,b.editore,b.isbn13||b.isbn10].filter(Boolean).join(' · ');
            button.type='button'; button.className='btn btn-outline-primary dw-select'; button.textContent=text.offer; button.dataset.book=JSON.stringify(b);
            info.append(title,details); li.append(cover,info,button); results.append(li);
          }
          status.textContent=books.length?text.found+' '+books.length:text.empty;
        } catch(error) { if(error.name!=='AbortError' && current===revision) status.textContent=text.error; }
        finally { if(current===revision) results.removeAttribute('aria-busy'); }
      },250);
    });
  });
})();
</script>
