<?php
/**
 * Dewey classification picker: a chip for the chosen code, a box to type any
 * code, and the class/division/section menus to browse for one. The book form
 * and the Emeroteca article form (#412) share it, so a cataloguer meets the
 * same control in both.
 *
 * Variables the including view sets:
 *   $deweyFieldName  name of the hidden input that is posted (default classificazione_dewey)
 *   $deweyValue      the stored code, to show on load
 * The including view calls initializeDewey(code) once the page has loaded;
 * the element ids are fixed, so a page holds one picker.
 */
declare(strict_types=1);

$deweyFieldName = (string) ($deweyFieldName ?? 'classificazione_dewey');
$deweyValue = (string) ($deweyValue ?? '');
?>
<input type="hidden" name="<?= htmlspecialchars($deweyFieldName, ENT_QUOTES, 'UTF-8') ?>" id="classificazione_dewey" value="<?= htmlspecialchars($deweyValue, ENT_QUOTES, 'UTF-8') ?>" />

<!-- Chip Dewey selezionato -->
<div id="dewey_chip_container" class="mb-4" style="display: none;">
  <label class="form-label"><?= __("Classificazione selezionata:") ?></label>
  <div id="dewey_chip" class="inline-flex items-center gap-2 bg-blue-100 text-blue-800 px-3 py-2 rounded-lg">
    <span class="font-mono font-bold" id="dewey_chip_code"></span>
    <span class="text-sm" id="dewey_chip_name"></span>
    <button type="button" id="dewey_chip_remove" class="text-gray-800 hover:text-blue-900" aria-label="<?= htmlspecialchars(__('Rimuovi classificazione Dewey'), ENT_QUOTES, 'UTF-8') ?>">
      <i class="fas fa-times"></i>
    </button>
  </div>
</div>

<!-- Input manuale Dewey -->
<div class="mb-4">
  <label for="dewey_manual_input" class="form-label"><?= __("Codice Dewey") ?></label>
  <div class="flex gap-2">
    <div class="relative flex-1">
      <input type="text" id="dewey_manual_input" class="form-input" placeholder="<?= htmlspecialchars(__('Cerca per codice o argomento, es. 599.9 o mammiferi'), ENT_QUOTES, 'UTF-8') ?>" role="combobox" aria-autocomplete="list" aria-controls="dewey_suggest" aria-expanded="false" autocomplete="off" />
      <ul id="dewey_suggest" role="listbox" aria-label="<?= htmlspecialchars(__('Classificazione Dewey'), ENT_QUOTES, 'UTF-8') ?>" hidden></ul>
    </div>
    <button type="button" id="dewey_add_btn" class="ui-button btn-primary">
      <i class="fas fa-plus"></i> <?= __("Aggiungi") ?>
    </button>
  </div>
  <p class="text-xs text-gray-500 mt-1"><?= __("Inserisci qualsiasi codice Dewey (anche se non presente nell'elenco)") ?></p>
</div>

<!-- Navigazione per categorie (opzionale) -->
<details class="mb-4">
  <summary class="cursor-pointer text-sm font-semibold text-gray-700 hover:text-gray-800">
    <?= __("Oppure naviga per categorie") ?>
  </summary>
  <div class="mt-3 p-3 bg-gray-50 rounded">
    <div id="dewey_breadcrumb" class="text-xs text-gray-600 mb-2 flex items-center gap-1">
      <i class="fas fa-home"></i>
      <span><?= __("Nessuna selezione") ?></span>
    </div>
    <div id="dewey_levels_container" class="space-y-2">
      <!-- I select verranno aggiunti dinamicamente -->
    </div>
  </div>
</details>
<script>
// Initialize Dewey with chip-based selection
async function initializeDewey(initialValue) {
  const container = document.getElementById('dewey_levels_container');
  const breadcrumb = document.getElementById('dewey_breadcrumb');
  const hidden = document.getElementById('classificazione_dewey');
  const manualInput = document.getElementById('dewey_manual_input');
  const addBtn = document.getElementById('dewey_add_btn');
  const chipContainer = document.getElementById('dewey_chip_container');
  const chipCode = document.getElementById('dewey_chip_code');
  const chipName = document.getElementById('dewey_chip_name');
  const chipRemove = document.getElementById('dewey_chip_remove');

  let currentDeweyCode = '';
  let currentDeweyName = '';

  // Valida formato codice Dewey (3 cifre principali + opzionale parte decimale).
  // Nessun limite ai decimali: un codice più profondo dell'elenco Dewey
  // (823.91409) si può salvare e mostrare, quindi si deve poter anche riscrivere.
  // Il percorso gerarchico si ferma all'ultimo livello noto (/api/dewey/path).
  const validateDeweyCode = (code) => {
    return /^[0-9]{3}(\.[0-9]+)?$/.test(code);
  };

  // Ottieni il codice parent (es. 599.1 → 599, 599.93 → 599.9)
  const getParentCode = (code) => {
    if (!code.includes('.')) return null; // Nessun parent se non ha decimali

    const parts = code.split('.');
    const intPart = parts[0]; // 599
    const decPart = parts[1]; // 1 oppure 93

    if (decPart.length === 1) {
      // 599.1 → parent è 599
      return intPart;
    } else {
      // 599.93 → parent è 599.9
      return `${intPart}.${decPart.substring(0, decPart.length - 1)}`;
    }
  };

  // Fetch the full hierarchical path for a Dewey code via API
  // Returns { codes: "100 > 110 > 116", names: "Filosofia > Metafisica > Cambiamento" } or null
  const fetchDeweyPath = async (code) => {
    try {
      const response = await fetch(`${window.BASE_PATH}/api/dewey/path?code=${encodeURIComponent(code)}`, {
        credentials: 'same-origin'
      });
      if (!response.ok) return null;
      const pathItems = await response.json();
      if (Array.isArray(pathItems) && pathItems.length > 0) {
        return {
          codes: pathItems.map(item => item.code).join(' > '),
          names: pathItems.map(item => item.name).join(' > ')
        };
      }
    } catch (e) {
      // Silently fail
    }
    return null;
  };

  // Imposta il codice Dewey corrente
  const setDeweyCode = async (code, name = null) => {
    if (!code) {
      clearDeweyCode();
      return;
    }

    currentDeweyCode = code;
    currentDeweyName = '';
    // Submit the selection immediately; the request only enriches its label.
    hidden.value = code;
    const requestCode = code;

    // Fetch full hierarchy (codes + names) from API
    const pathData = await fetchDeweyPath(code);
    if (requestCode !== currentDeweyCode) return; // stale response
    let chipCodeText = code;
    if (pathData) {
      chipCodeText = pathData.codes;
      currentDeweyName = pathData.names;
    } else if (name) {
      // Fallback: use the provided leaf name only
      currentDeweyName = name;
    }

    // Aggiorna UI - hidden field saves only the leaf code
    hidden.value = currentDeweyCode;
    chipCode.textContent = chipCodeText;
    chipName.textContent = currentDeweyName ? `— ${currentDeweyName}` : '';
    chipContainer.style.display = 'block';
    manualInput.value = '';
  };

  // Expose to global scope for scraping handler
  window.setDeweyCode = setDeweyCode;

  // Rimuovi il codice Dewey corrente
  const clearDeweyCode = () => {
    currentDeweyCode = '';
    currentDeweyName = '';
    hidden.value = '';
    chipContainer.style.display = 'none';
    chipCode.textContent = '';
    chipName.textContent = '';
    manualInput.value = '';

    // Reset navigazione
    container.innerHTML = '';
    breadcrumb.innerHTML = `<i class="fas fa-home"></i> <span>${<?= json_encode(__("Nessuna selezione"), JSON_HEX_TAG) ?>}</span>`;
    loadLevel(null, 0);
  };

  // Gestione pulsante "Aggiungi"
  addBtn.addEventListener('click', async () => {
    const code = manualInput.value.trim();

    if (!code) {
      if (window.Toast) {
        window.Toast.fire({
          icon: 'warning',
          title: <?= json_encode(__("Inserisci un codice Dewey"), JSON_HEX_TAG) ?>
        });
      }
      return;
    }

    if (!validateDeweyCode(code)) {
      if (window.Toast) {
        window.Toast.fire({
          icon: 'error',
          title: <?= json_encode(__("Formato codice non valido"), JSON_HEX_TAG) ?>,
          text: <?= json_encode(__("Usa formato: 599 oppure 599.9 oppure 599.93"), JSON_HEX_TAG) ?>
        });
      }
      return;
    }

    await setDeweyCode(code);
  });

  // Gestione rimozione chip
  chipRemove.addEventListener('click', () => {
    clearDeweyCode();
  });

  // Autocomplete: typing a code prefix or a word of the subject lists the
  // matching classes (GET /api/dewey/autocomplete); picking one moves the
  // category menus to it. Enter with nothing highlighted keeps the old
  // behaviour: add the typed code, even one that is not in the list.
  const suggest = document.getElementById('dewey_suggest');
  let suggestItems = [];
  let suggestIndex = -1;
  let suggestTimer = null;
  let suggestRequest = 0;
  const closeSuggest = () => {
    // A closed list also drops what is still in flight, so a late answer
    // cannot reopen it with results for text that is no longer there.
    clearTimeout(suggestTimer);
    suggestRequest++;
    suggest.hidden = true;
    suggest.replaceChildren();
    suggestItems = [];
    suggestIndex = -1;
    manualInput.setAttribute('aria-expanded', 'false');
    manualInput.removeAttribute('aria-activedescendant');
  };
  const highlight = (index) => {
    suggestIndex = index;
    Array.from(suggest.children).forEach((li, i) => li.setAttribute('aria-selected', i === index ? 'true' : 'false'));
    if (index >= 0 && suggest.children[index]) {
      manualInput.setAttribute('aria-activedescendant', suggest.children[index].id);
      suggest.children[index].scrollIntoView({ block: 'nearest' });
    } else {
      manualInput.removeAttribute('aria-activedescendant');
    }
  };
  const pickSuggestion = async (item) => {
    closeSuggest();
    manualInput.value = '';
    await navigateToCode(item.code);
  };
  const renderSuggest = (items) => {
    suggest.replaceChildren();
    suggestItems = items;
    suggestIndex = -1;
    if (!items.length) { closeSuggest(); return; }
    items.forEach((item, i) => {
      const li = document.createElement('li');
      li.id = 'dewey_suggest_' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      const code = document.createElement('span');
      code.className = 'font-mono font-bold';
      code.textContent = item.code;
      li.appendChild(code);
      li.appendChild(document.createTextNode(' — ' + item.name));
      // mousedown, not click: the input must not lose the list to its blur first.
      li.addEventListener('mousedown', (event) => { event.preventDefault(); pickSuggestion(item); });
      suggest.appendChild(li);
    });
    suggest.hidden = false;
    manualInput.setAttribute('aria-expanded', 'true');
  };
  manualInput.addEventListener('input', () => {
    const query = manualInput.value.trim();
    if (query.length < 2) { closeSuggest(); return; }
    const request = ++suggestRequest;
    suggestTimer = setTimeout(async () => {
      if (request !== suggestRequest) return;
      try {
        const response = await fetch(`${window.BASE_PATH}/api/dewey/autocomplete?q=${encodeURIComponent(query)}`, { credentials: 'same-origin' });
        if (request !== suggestRequest) return;
        // An error answer must not leave the previous query's suggestions open,
        // where Enter or a click would pick a code that no longer matches.
        if (!response.ok) { closeSuggest(); return; }
        const items = await response.json();
        if (request !== suggestRequest) return;
        renderSuggest(Array.isArray(items) ? items : []);
      } catch (e) {
        closeSuggest();
      }
    }, 200);
  });
  manualInput.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' && suggestItems.length) {
      e.preventDefault();
      highlight(Math.min(suggestIndex + 1, suggestItems.length - 1));
    } else if (e.key === 'ArrowUp' && suggestItems.length) {
      e.preventDefault();
      highlight(Math.max(suggestIndex - 1, 0));
    } else if (e.key === 'Escape' && !suggest.hidden) {
      e.preventDefault();
      closeSuggest();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (suggestIndex >= 0 && suggestItems[suggestIndex]) {
        pickSuggestion(suggestItems[suggestIndex]);
      } else {
        closeSuggest();
        addBtn.click();
      }
    }
  });
  // Close a moment after leaving the box (a click on a suggestion lands first),
  // but not if the cataloguer is already back in it and typing: a stale timer
  // would cancel the search they just started.
  manualInput.addEventListener('blur', () => {
    setTimeout(() => { if (document.activeElement !== manualInput) closeSuggest(); }, 150);
  });

  // Build breadcrumb from all currently selected dropdowns
  const updateBreadcrumbFromDropdowns = () => {
    const icon = document.createElement('i');
    icon.className = 'fas fa-home';
    breadcrumb.textContent = '';
    breadcrumb.appendChild(icon);
    breadcrumb.appendChild(document.createTextNode(' '));

    const selects = container.querySelectorAll('select');
    let hasSelection = false;
    selects.forEach((sel, i) => {
      if (!sel.value) return;
      const opt = sel.selectedOptions[0];
      if (!opt) return;
      hasSelection = true;
      if (i > 0) {
        const sep = document.createElement('span');
        sep.className = 'text-gray-400 mx-1';
        sep.textContent = '>';
        breadcrumb.appendChild(sep);
      }
      const span = document.createElement('span');
      span.className = 'text-gray-500';
      span.textContent = sel.value;
      span.title = opt.dataset.name || '';
      breadcrumb.appendChild(span);
    });

    if (!hasSelection) {
      const noSel = document.createElement('span');
      noSel.textContent = <?= json_encode(__("Nessuna selezione"), JSON_HEX_TAG) ?>;
      breadcrumb.appendChild(noSel);
    }
  };

  // Carica livelli Dewey per navigazione
  const loadLevel = async (parentCode = null, levelIndex = 0) => {
    try {
      const apiUrl = parentCode
        ? `${window.BASE_PATH}/api/dewey/children?parent_code=${encodeURIComponent(parentCode)}`
        : window.BASE_PATH + '/api/dewey/children';

      const response = await fetch(apiUrl, { credentials: 'same-origin' });
      if (!response.ok) {
        console.error('Dewey children API error:', response.status);
        return null;
      }
      const items = await response.json();

      if (!Array.isArray(items) || items.length === 0) return null;

      // Rimuovi tutti i select dopo questo livello
      while (container.children.length > levelIndex) {
        container.removeChild(container.lastChild);
      }

      // Crea nuovo select
      const selectWrapper = document.createElement('div');
      const select = document.createElement('select');
      select.className = 'form-input';
      select.dataset.level = levelIndex;

      const opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = <?= json_encode(__("Seleziona..."), JSON_HEX_TAG) ?>;
      select.appendChild(opt0);

      items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.code;
        opt.dataset.hasChildren = item.has_children;
        opt.dataset.name = item.name;
        opt.textContent = `${item.code} — ${item.name}`;
        select.appendChild(opt);
      });

      select.addEventListener('change', async (e) => {
        const selectedOption = e.target.selectedOptions[0];
        const code = e.target.value;

        if (!code) {
          // Rimuovi select successivi
          while (container.children.length > levelIndex + 1) {
            container.removeChild(container.lastChild);
          }
          updateBreadcrumbFromDropdowns();
          return;
        }

        const name = selectedOption.dataset.name;
        const hasChildren = selectedOption.dataset.hasChildren === 'true';

        // Rimuovi select successivi
        while (container.children.length > levelIndex + 1) {
          container.removeChild(container.lastChild);
        }

        // Aggiorna breadcrumb con tutto il percorso selezionato
        updateBreadcrumbFromDropdowns();

        // Imposta sempre il chip al livello corrente
        await setDeweyCode(code, name);

        // Se ha figli, carica anche il livello successivo
        if (hasChildren) {
          await loadLevel(code, levelIndex + 1);
        }
      });

      selectWrapper.appendChild(select);
      container.appendChild(selectWrapper);

      return select;
    } catch (e) {
      console.error('Dewey level error:', e);
    }
  };

  // Calcola il percorso gerarchico per un codice Dewey
  // es. "133.5" → ["100", "130", "133", "133.5"]
  const getCodePath = (code) => {
    const path = [];

    // Prima parte: classe principale (X00)
    const mainClass = code.substring(0, 1) + '00';
    path.push(mainClass);

    // Se il codice è solo la classe principale, restituisci
    if (code === mainClass) return path;

    // Seconda parte: divisione (XX0) se diversa dalla classe
    const division = code.substring(0, 2) + '0';
    if (division !== mainClass) {
      path.push(division);
    }

    // Terza parte: sezione (XXX) se non è una divisione
    const intPart = code.split('.')[0];
    if (intPart.length === 3 && intPart !== division && intPart !== mainClass) {
      path.push(intPart);
    }

    // Parti decimali (XXX.X, XXX.XX, etc.)
    if (code.includes('.')) {
      const [base, decimal] = code.split('.');
      // Aggiungi la parte intera se non già presente
      if (!path.includes(base)) {
        path.push(base);
      }
      // Aggiungi ogni livello decimale
      for (let i = 1; i <= decimal.length; i++) {
        const partial = base + '.' + decimal.substring(0, i);
        path.push(partial);
      }
    }

    return path;
  };

  // Naviga ai dropdown fino al codice specificato
  const navigateToCode = async (targetCode) => {
    const path = getCodePath(targetCode);
    // Start again from the main classes: menus left from the previous code
    // (500 > 590 > 599 before picking 100) would otherwise stay on screen
    // and could still write a code of the old path into the field.
    while (container.children.length > 1) {
      container.removeChild(container.lastChild);
    }
    let lastFoundCode = null;
    let lastFoundName = null;

    // Per ogni codice nel percorso, carica il livello e seleziona
    for (let i = 0; i < path.length; i++) {
      const code = path[i];
      const parentCode = i === 0 ? null : path[i - 1];

      // Assicurati che il dropdown per questo livello esista. Se loadLevel
      // ritorna null (parent non trovato nel JSON o API vuota) interrompi
      // la navigazione: codici Dewey più specifici del JSON (es. '305.42097'
      // legacy) sono trattati come custom — il fallback sotto mostrerà il
      // codice nel breadcrumb senza tentare altri livelli.
      if (container.children.length <= i) {
        const loadedSelect = await loadLevel(parentCode, i);
        if (!loadedSelect) {
          break;
        }
      }

      // Trova e seleziona l'opzione nel dropdown
      const select = container.children[i]?.querySelector('select');
      if (select) {
        // Cerca l'opzione con questo codice
        const option = Array.from(select.options).find(opt => opt.value === code);
        if (option) {
          select.value = code;
          lastFoundCode = code;
          lastFoundName = option.dataset.name;

          // Se ha figli e non è l'ultimo nel percorso, carica il prossimo livello
          const hasChildren = option.dataset.hasChildren === 'true';
          const isLast = i === path.length - 1;

          if (hasChildren && !isLast) {
            await loadLevel(code, i + 1);
          } else if (isLast) {
            // Ultimo elemento: aggiorna breadcrumb con percorso completo
            updateBreadcrumbFromDropdowns();
            await setDeweyCode(code, option.dataset.name);
            return; // Successfully navigated to target
          }
        } else {
          // Codice non trovato nel dropdown - è un codice personalizzato
          break;
        }
      }
    }

    // Se non abbiamo raggiunto il targetCode, mostra comunque il chip
    // Questo gestisce i codici personalizzati non presenti nel JSON (es. 708.2)
    if (targetCode !== lastFoundCode) {
      // Aggiorna breadcrumb con percorso dai dropdown + codice custom
      updateBreadcrumbFromDropdowns();
      // Aggiungi il codice custom al breadcrumb
      const sep = document.createElement('span');
      sep.className = 'text-gray-400 mx-1';
      sep.textContent = '>';
      breadcrumb.appendChild(sep);
      const codeSpan = document.createElement('span');
      codeSpan.className = 'text-gray-500';
      codeSpan.textContent = targetCode;
      breadcrumb.appendChild(codeSpan);
      // setDeweyCode fetch full hierarchy name via /api/dewey/path
      await setDeweyCode(targetCode, null);
    }
  };

  // Carica primo livello (classi principali)
  await loadLevel(null, 0);

  // Carica valore iniziale se presente e naviga fino ad esso
  const initialCode = String(initialValue || '').trim();
  if (initialCode) {
    // Se è nel vecchio formato (300-340-347), prendi solo l'ultimo valore
    const parts = initialCode.split('-');
    const finalCode = parts.length > 1 ? parts[parts.length - 1] : initialCode;

    // Naviga ai dropdown fino al codice
    await navigateToCode(finalCode);
  }
}
</script>
<style>
  /* The suggestion list of the Dewey box: the look of the Choices.js dropdown
     in admin-ui.css (white, grey border, highlighted row #dbeafe). */
  #dewey_suggest { position: absolute; z-index: 100; left: 0; right: 0; top: 100%; margin: 4px 0 0; padding: 0; list-style: none; background: #fff; border: 1px solid #d1d5db; border-radius: .375rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -1px rgba(0,0,0,.06); max-height: 18rem; overflow-y: auto; }
  #dewey_suggest[hidden] { display: none; }
  #dewey_suggest li { padding: 8px 12px; font-size: .875rem; color: #111827; cursor: pointer; }
  #dewey_suggest li:hover { background: #f3f4f6; }
  #dewey_suggest li[aria-selected="true"] { background: #dbeafe; }
</style>
