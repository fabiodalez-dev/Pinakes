<?php
/*
 * The article's authors, picked the way a book's are (#412): one field, a
 * Choices.js search over the shared author registry, Enter to add a name that
 * is not there yet. It replaces a free-text box at the top of the form and a
 * separate "authors from the registry" section further down, which a
 * cataloguer read as two different things.
 *
 * Every chip posts as one entry of credits[], the shape ArticleAuthorService
 * already resolves: an existing person carries autore_id, a new name carries
 * create=1 and becomes an author on save, and a credit that was only ever text
 * (records catalogued before shared authors existed) stays text until someone
 * replaces it. The first chip is the principal author.
 *
 * Without JavaScript the plain credit field is what the form sends, as before.
 */
$credits = $row['credits'] ?? $row['author_credits'] ?? [];
if (!is_array($credits)) { $credits = []; }
if ($credits === []) {
    foreach (\App\Plugins\Emeroteca\Services\ContributionService::authorList($row['autori'] ?? null) as $name) {
        $credits[] = ['autore_id'=>null, 'nome_credito'=>$name];
    }
}
$chips = [];
foreach (array_values($credits) as $i => $credit) {
    if (!is_array($credit)) { continue; }
    $name = trim((string)($credit['nome_credito'] ?? ''));
    $id = (int)($credit['autore_id'] ?? 0);
    if ($name === '' && $id === 0) { continue; }
    if ($id > 0) {
        $chips[] = ['value'=>(string)$id, 'label'=>(string)($credit['display_name'] ?? $name), 'credit'=>$name, 'kind'=>'linked'];
    } elseif (($credit['create'] ?? '') === '1' || ($credit['create'] ?? '') === 1) {
        $chips[] = ['value'=>'new:'.$i, 'label'=>$name, 'credit'=>$name, 'kind'=>'new'];
    } else {
        $chips[] = ['value'=>'text:'.$i, 'label'=>$name, 'credit'=>$name, 'kind'=>'text'];
    }
}
$autoriFallback = implode('; ', array_column($chips, 'credit'));
// The picker posts every chip as a credit, and ArticleAuthorService accepts at
// most 20 of them, each up to 255 characters. A record typed as text before
// shared authors (a long list of names, a corporate author) can exceed that;
// it keeps the text field, so opening and saving it never fails.
$pickerFits = count($chips) <= \App\Services\ArticleAuthorService::MAX_CREDITS;
foreach ($chips as $chip) {
    if (mb_strlen($chip['credit']) > \App\Services\ArticleAuthorService::MAX_CREDIT_LENGTH) { $pickerFits = false; }
}
?>
<div class="md:col-span-2" id="article-author-editor">
<label for="article-authors-select" class="form-label"><?= __('Autori') ?></label>
<div id="article-authors-picker" hidden>
<select id="article-authors-select" multiple></select>
<p class="text-xs text-gray-500 mt-1"><?= __('Cerca l’autore nell’anagrafica comune a libri e articoli; se non c’è, scrivi il nome e premi Invio per crearlo. Il primo autore è l’autore principale.') ?></p>
<p class="text-xs text-gray-500 mt-1" id="article-author-limit" role="status" hidden><?= __('Puoi collegare al massimo 20 autori a un articolo.') ?></p>
<p class="text-xs text-red-600 mt-1" id="article-author-search-error" role="alert" hidden><?= __('Ricerca non disponibile. Riprova.') ?></p>
</div>
<div id="article-credits"></div>
<div id="article-authors-fallback">
<input class="form-input" id="article-autori" name="autori" value="<?= htmlspecialchars($autoriFallback, ENT_QUOTES, 'UTF-8') ?>" maxlength="<?= \App\Plugins\Emeroteca\Services\ContributionService::TEXT_FIELDS['autori'] ?>">
<p class="text-xs text-gray-500 mt-1"><?= __('Separa più autori con un punto e virgola. La virgola resta parte del nome, per esempio Schweissinger, Marc J.') ?></p>
<?php if (!$pickerFits): ?><p class="text-xs text-gray-500 mt-1" id="article-authors-text-only"><?= __('Questo articolo ha più di 20 autori o un nome più lungo di 255 caratteri: gli autori si modificano qui come testo.') ?></p><?php endif; ?>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const select = document.getElementById('article-authors-select');
  const picker = document.getElementById('article-authors-picker');
  const fallback = document.getElementById('article-authors-fallback');
  const store = document.getElementById('article-credits');
  const limitNote = document.getElementById('article-author-limit');
  const searchError = document.getElementById('article-author-search-error');
  // Over the limits the record stays on the text field (see $pickerFits).
  const pickerFits = <?= $pickerFits ? 'true' : 'false' ?>;
  if (!select || typeof Choices === 'undefined' || !pickerFits) { return; }
  const MAX_AUTHORS = 20;
  const messages = <?= json_encode([
      'placeholder'=>__('Cerca autori esistenti o aggiungine di nuovi...'),
      'noChoices'=>__('Nessun autore trovato, premi Invio per aggiungerne uno nuovo'),
      'select'=>__('Clicca per selezionare'),
      'add'=>__('Aggiungi'), 'asNew'=>__('come nuovo autore'),
      'limit'=>__('Puoi collegare al massimo 20 autori a un articolo.'),
      'isNew'=>__('nuovo'),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const searchUrl = <?= json_encode(url('/api/search/autori'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  // value -> {kind, credit, id}: what each chip posts. Search results carry the
  // registry name; a chip that already existed keeps the credit it was saved with.
  const meta = new Map();
  const initial = <?= json_encode($chips, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const choice = new Choices(select, {
    searchEnabled: true,
    searchChoices: false,
    removeItemButton: true,
    addItems: true,
    duplicateItemsAllowed: false,
    maxItemCount: MAX_AUTHORS,
    maxItemText: () => messages.limit,
    placeholder: true,
    placeholderValue: messages.placeholder,
    noChoicesText: messages.noChoices,
    itemSelectText: messages.select,
    addItemText: (value) => `${messages.add} <b>"${value}"</b> ${messages.asNew}`,
    shouldSort: false,
    searchResultLimit: -1,
    searchFloor: 1
  });

  // The form posts what the chips say, in their order: the first is principal.
  function sync() {
    store.replaceChildren();
    const items = choice.getValue() || [];
    items.forEach((item, index) => {
      const info = meta.get(String(item.value)) || { kind: 'text', credit: String(item.label || '') };
      const fields = {
        nome_credito: info.credit,
        autore_id: info.kind === 'linked' ? String(item.value) : '',
        create: info.kind === 'new' ? '1' : '',
        ruolo: index === 0 ? 'principale' : 'co-autore'
      };
      Object.entries(fields).forEach(([key, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = `credits[${index}][${key}]`; input.value = value;
        store.append(input);
      });
    });
    const full = items.length >= MAX_AUTHORS;
    limitNote.hidden = !full;
  }

  // The saved credits become chips first. Only once they are all in place
  // does the credit list take over from the plain field: if loading them
  // failed half-way, the form must keep sending the text, never an empty list
  // that would delete the record's authors.
  try {
    initial.forEach((chip) => {
      meta.set(String(chip.value), { kind: chip.kind, credit: chip.credit });
      choice.setChoices([{ value: String(chip.value), label: chip.kind === 'new' ? `${chip.label} (${messages.isNew})` : chip.label, selected: true }], 'value', 'label', false);
    });
    if ((choice.getValue() || []).length !== initial.length) { throw new Error('author chips not loaded'); }
  } catch (error) {
    console.error(error);
    choice.destroy();
    return;
  }
  const present = document.createElement('input');
  present.type = 'hidden'; present.name = 'credits_present'; present.value = '1';
  picker.after(present);
  fallback.querySelectorAll('input').forEach((input) => { input.disabled = true; });
  fallback.hidden = true;
  picker.hidden = false;
  sync();

  select.addEventListener('addItem', sync);
  select.addEventListener('removeItem', sync);

  const wrapper = select.closest('.choices');
  const internalInput = wrapper ? wrapper.querySelector('.choices__input--cloned') : null;

  // Same comparison as the book form (#74): Enter on a highlighted suggestion
  // takes it only when it is the name that was typed.
  // A name typed in citation form ("van Gogh, Vincent") matches its
  // direct-order label ("Vincent van Gogh") too.
  function directOrder(text) {
    const at = text.indexOf(',');
    return at < 0 ? text : (text.slice(at + 1).trim() + ' ' + text.slice(0, at).trim()).replace(/\s+/g, ' ');
  }
  function matchesInput(label, typed) {
    const a = String(label || '').trim().toLowerCase().replace(/\s+\([^()]*\d[^()]*\)$/, '');
    const b = String(typed || '').trim().toLowerCase();
    const candidates = [b, directOrder(b)];
    if (candidates.includes(a)) return true;
    const m = a.match(/^(.+?)\s+\((.+)\)$/);
    return Boolean(m && candidates.some((c) => m[1].trim() === c || m[2].trim() === c));
  }
  function addNew(name) {
    const label = String(name || '').trim().replace(/;/g, ',');
    if (!label) return;
    const taken = (choice.getValue() || []).some((item) => (meta.get(String(item.value)) || {}).credit?.toLowerCase() === label.toLowerCase());
    if (taken || (choice.getValue() || []).length >= MAX_AUTHORS) { if (internalInput) internalInput.value = ''; choice.hideDropdown(); sync(); return; }
    const value = 'new:' + Date.now() + ':' + Math.floor(Math.random() * 1000);
    meta.set(value, { kind: 'new', credit: label });
    choice.setChoices([{ value, label: `${label} (${messages.isNew})`, selected: false }], 'value', 'label', false);
    choice.setChoiceByValue(value);
    if (internalInput) internalInput.value = '';
    if (typeof choice.clearInput === 'function') choice.clearInput();
    choice.hideDropdown();
    sync();
  }
  // Load-bearing, as in the book form: Choices.js stops the Enter event before
  // any listener added after it, so the instance's own handler is wrapped. See
  // the note on initializeChoicesJS() in app/Views/libri/partials/book_form.php.
  if (typeof choice._onEnterKey === 'function') {
    const original = choice._onEnterKey.bind(choice);
    choice._onEnterKey = function (event, hasActiveDropdown) {
      const typed = internalInput ? internalInput.value.trim() : '';
      if (!typed) return original(event, hasActiveDropdown);
      const dropdown = wrapper ? wrapper.querySelector('.choices__list--dropdown') : null;
      const highlighted = dropdown ? dropdown.querySelector('.choices__item--selectable.is-highlighted') : null;
      if (highlighted) {
        const nameEl = highlighted.querySelector('.choices__item-text') || highlighted.childNodes[0];
        if (matchesInput(nameEl ? nameEl.textContent : highlighted.textContent, typed)) return original(event, hasActiveDropdown);
      }
      event.preventDefault();
      addNew(typed);
    };
  }

  // Server-side search, as for books. Life dates tell two people with the same
  // name apart; they are shown in the list and never become part of the credit.
  let timer = null;
  select.addEventListener('search', function (event) {
    const query = (event.detail && event.detail.value) ? event.detail.value.trim() : '';
    clearTimeout(timer);
    if (query.length < 2) return;
    // A name typed in citation form ("van Gogh, Vincent") is the credit the
    // cataloguer wants; the server keeps it when it names the picked person
    // and falls back to the registry's citation form otherwise.
    const typedCredit = query.includes(',') ? query.replace(/;/g, ',') : '';
    timer = setTimeout(async () => {
      try {
        const response = await fetch(searchUrl + '?q=' + encodeURIComponent(query.replace(/,/g, ' ')));
        if (!response.ok) throw new Error('author search ' + response.status);
        const authors = await response.json();
        searchError.hidden = true;
        const chosen = new Set((choice.getValue(true) || []).map(String));
        const options = (authors || []).filter((a) => !chosen.has(String(a.id))).slice(0, 30).map((a) => {
          meta.set(String(a.id), { kind: 'linked', credit: typedCredit || a.label });
          const dates = [a.data_nascita, a.data_morte].filter(Boolean).join('–');
          return { value: String(a.id), label: dates ? `${a.label} (${dates})` : a.label, selected: false };
        });
        choice.setChoices(options, 'value', 'label', true, false);
      } catch (error) {
        // Say so: an empty list during an outage would read as "no such
        // author" and invite a duplicate. The typed name can still be added.
        console.error(error);
        searchError.hidden = false;
      }
    }, 300);
  });

  if (wrapper) {
    const inner = wrapper.querySelector('.choices__inner');
    if (inner) { inner.style.setProperty('flex-wrap', 'wrap', 'important'); }
  }
});
</script>
