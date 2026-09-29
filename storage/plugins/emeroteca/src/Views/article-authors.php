<?php
$credits = $row['credits'] ?? $row['author_credits'] ?? [];
if (!is_array($credits)) { $credits = []; }
if ($credits === []) {
    foreach (\App\Plugins\Emeroteca\Services\ContributionService::authorList($row['autori'] ?? null) as $i => $name) {
        $credits[] = ['autore_id'=>null, 'nome_credito'=>$name, 'ruolo'=>$i === 0 ? 'principale' : 'co-autore'];
    }
}
$hasLinkedAuthors = count(array_filter($credits, static fn($c) => is_array($c) && !empty($c['autore_id']))) > 0;
?>
<details class="mt-6" id="article-author-editor" <?= $hasLinkedAuthors ? 'open' : '' ?>>
<summary class="font-semibold py-3 cursor-pointer"><?= __('Autori dell’anagrafica') ?></summary>
<p class="text-sm text-gray-600"><?= __('Collega ogni nome a una persona dell’anagrafica comune a libri e articoli. Verifica gli omonimi prima di selezionare; puoi anche creare un nuovo autore o lasciare il credito senza collegamento.') ?></p>
<input type="hidden" name="credits_present" value="1" id="article-credits-present" disabled>
<div id="article-author-rows" class="space-y-3 mt-3"></div>
<button type="button" class="btn-secondary mt-3" id="article-author-add"><?= __('Aggiungi autore') ?></button>
<p class="text-sm text-gray-600" id="article-author-limit" role="status" hidden><?= __('Puoi collegare al massimo 20 autori a un articolo.') ?></p>
<noscript><p><?= __('Per collegare gli autori attiva JavaScript. I crediti testuali restano disponibili.') ?></p></noscript>
</details>
<script>
(function () {
  const root = document.getElementById('article-author-rows');
  const marker = document.getElementById('article-credits-present');
  const original = document.getElementById('article-autori');
  const messages = <?= json_encode([
      'name'=>__('Nome'), 'search'=>__('Cerca'), 'create'=>__('Crea nuovo autore'), 'remove'=>__('Rimuovi'),
      'principal'=>__('Autore principale'), 'coauthor'=>__('Coautore'), 'unlinked'=>__('Credito senza collegamento'),
      'created'=>__('Nuovo autore da creare al salvataggio'), 'linked'=>__('Autore collegato'), 'none'=>__('Nessun autore trovato'),
      'error'=>__('Ricerca non disponibile. Riprova.'), 'searchLabel'=>__('Cerca autori esistenti o aggiungine di nuovi...'),
      'limitReached'=>__('Puoi collegare al massimo 20 autori a un articolo.')
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const MAX_AUTHORS = 20;
  const addButton = document.getElementById('article-author-add');
  const limitNote = document.getElementById('article-author-limit');
  let next = 0;
  // The cap is visible: at 20 rows the add button is disabled and says why.
  function syncLimit() {
    const full = root.children.length >= MAX_AUTHORS;
    addButton.disabled = full;
    addButton.setAttribute('aria-disabled', full ? 'true' : 'false');
    // Inline, not utility classes: main.css is a purged Tailwind build.
    addButton.style.opacity = full ? '.5' : '';
    addButton.style.cursor = full ? 'not-allowed' : '';
    limitNote.textContent = messages.limitReached;
    limitNote.hidden = !full;
  }
  function changed() {
    marker.disabled = false;
    if (original) { original.readOnly = true; original.value = Array.from(root.querySelectorAll('input[name$="[nome_credito]"]')).map(input=>input.value.trim()).filter(Boolean).join('; '); }
  }
  function add(credit) {
    if (root.children.length >= MAX_AUTHORS) { syncLimit(); return; }
    const index = next++;
    const item = document.createElement('div'); item.className = 'border rounded p-3'; item.dataset.authorRow = '';
    const label = document.createElement('label'); label.className = 'form-label'; label.textContent = messages.name;
    const input = document.createElement('input'); input.className = 'form-input'; input.maxLength = 255;
    input.name = `credits[${index}][nome_credito]`; input.value = credit.nome_credito || ''; input.id = `article-credit-${index}`;
    input.placeholder = messages.searchLabel; label.htmlFor = input.id;
    const id = document.createElement('input'); id.type = 'hidden'; id.name = `credits[${index}][autore_id]`; id.value = credit.autore_id || '';
    const create = document.createElement('input'); create.type = 'hidden'; create.name = `credits[${index}][create]`; create.value = credit.create || '';
    const role = document.createElement('select'); role.className = 'form-input mt-2'; role.name = `credits[${index}][ruolo]`;
    role.setAttribute('aria-label', messages.principal + ' / ' + messages.coauthor);
    [['principale',messages.principal],['co-autore',messages.coauthor]].forEach(([value,text]) => {
      const option = document.createElement('option'); option.value = value; option.textContent = text;
      option.selected = (credit.ruolo || 'co-autore') === value; role.append(option);
    });
    const state = document.createElement('p'); state.className = 'text-sm mt-2'; state.setAttribute('role','status');
    function status() { state.textContent = id.value ? `${messages.linked}: ${input.value}` : create.value === '1' ? messages.created : messages.unlinked; }
    status();
    const results = document.createElement('div'); results.className = 'space-y-2 mt-2';
    const actions = document.createElement('div'); actions.className = 'flex flex-wrap gap-2 mt-2';
    function button(text, fn) { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn-secondary'; b.textContent = text; b.addEventListener('click',fn); actions.append(b); return b; }
    let request = 0;
    input.addEventListener('input', () => { request++; id.value = ''; create.value = ''; results.replaceChildren(); changed(); status(); });
    role.addEventListener('change', changed);
    const searchButton = button(messages.search, async () => {
      if (searchButton.disabled) return;
      const term = input.value.trim().replace(/,/g,' '); if (!term) return;
      const generation = ++request; results.replaceChildren();
      // In flight: no second request, and the busy state is announced.
      searchButton.disabled = true; searchButton.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(<?= json_encode(url('/api/search/autori'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '?q=' + encodeURIComponent(term));
        if (!response.ok) throw new Error('search');
        const authors = await response.json(); if (generation !== request) return;
        if (authors.length === 0) { state.textContent = messages.none; return; }
        authors.slice(0,30).forEach(author => {
          const choose = document.createElement('button'); choose.type = 'button'; choose.className = 'btn-secondary block';
          choose.textContent = `${author.label}${author.data_nascita ? ' · ' + author.data_nascita : ''}${author.data_morte ? ' – ' + author.data_morte : ''}`;
          // A typed inverted credit ("Surname, Forename") is the citation
          // form and is kept; otherwise the server derives it from the identity.
          choose.addEventListener('click', () => { id.value = String(author.id); if (!input.value.includes(',')) input.value = author.label; create.value = ''; results.replaceChildren(); changed(); status(); });
          results.append(choose);
        });
      } catch (_) { if (generation === request) state.textContent = messages.error; }
      finally { searchButton.disabled = false; searchButton.removeAttribute('aria-busy'); }
    });
    button(messages.create, () => { if (!input.value.trim()) { input.focus(); return; } id.value = ''; create.value = '1'; changed(); status(); });
    button(messages.remove, () => { request++; item.remove(); changed(); syncLimit(); });
    item.append(label,input,id,create,role,state,actions,results); root.append(item);
    syncLimit();
  }
  const initial = <?= json_encode(array_values($credits), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  initial.forEach(add);
  if (<?= $hasLinkedAuthors || isset($row['credits_present']) ? 'true' : 'false' ?>) changed();
  syncLimit();
  addButton.addEventListener('click', () => { if (root.children.length >= MAX_AUTHORS) { syncLimit(); return; } add({ruolo:root.children.length === 0 ? 'principale' : 'co-autore'}); changed(); });
  // Existing text-only workflows remain valid until identity editing is deliberately used.
  if (original) original.addEventListener('input', () => {
    if (marker.disabled) { root.replaceChildren(); syncLimit(); original.value.split(';').map(s=>s.trim()).filter(Boolean).forEach((name,i)=>add({nome_credito:name,ruolo:i===0?'principale':'co-autore'})); }
  });
})();
</script>
