<?php
/** @var bool $patronTypes */
/** @var array{preview_id:string, name:string, processed:int, total:int}|null $resumeImport */
/** @var array{name:string, processed:int, total:int, import_id:?string}|null $expiredImport */
use App\Support\Csrf;

$csrfToken = Csrf::ensureToken();
$importI18n = [
    'ignore' => __('— Ignora colonna —'),
    'uploading' => __('Caricamento del file...'),
    'rows' => __('%d righe da importare'),
    'processing' => __('Importazione in corso...'),
    'progress' => __('%d di %d righe elaborate'),
    'invalidResponse' => __('Risposta non valida dal server'),
    'networkError' => __('Errore di rete. Riprova.'),
    'stale' => __('Questa anteprima è stata sostituita da un altro file caricato: carica di nuovo il file per importarlo.'),
    'line' => __('Riga'),
    'name' => __('Utente'),
    'type' => __('Tipo'),
    'message' => __('Messaggio'),
    'types' => [
        'duplicate' => __('Duplicato'),
        'validation' => __('Riga non valida'),
        'database' => __('Errore del database'),
        'email' => __('Email'),
        'warning' => __('Avviso'),
        'truncated' => __('Report troncato'),
    ],
];
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6">
    <?php
// Shared page anatomy, as in the book import page.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= __("Importa utenti da CSV") ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?><a href="<?= htmlspecialchars(url('/admin/users'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
        <i class="fas fa-arrow-left mr-2"></i>
        <?= __("Torna agli utenti") ?>
      </a><?php
$workspaceHeader['back'] = (string) ob_get_clean();
ob_start();
?>
      <p class="text-sm text-gray-600"><?= __("Carica un file CSV, controlla l'anteprima e associa le colonne ai campi dell'utente.") ?></p>
    <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a></li>
        <li><a href="<?= htmlspecialchars(url('/admin/users'), ENT_QUOTES, 'UTF-8') ?>"><?= __("Utenti") ?></a></li>
        <li aria-current="page"><?= __("Import CSV") ?></li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <div id="user-import-alert" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start hidden" role="alert">
      <i class="fas fa-exclamation-circle text-red-600 mt-0.5 mr-3"></i>
      <div class="flex-1"><p class="text-red-800 font-medium" id="user-import-alert-text"></p></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">

        <?php if ($expiredImport !== null): ?>
        <!-- An interrupted run whose staged file expired: closed into the history -->
        <div class="p-4 bg-amber-50 text-amber-800 rounded-lg border border-amber-200 text-sm" id="user-import-expired" role="status">
          <?php if (($expiredImport['import_id'] ?? null) === ''): // closed, but its history entry could not be written ?>
          <i class="fas fa-info-circle mr-1"></i><?= htmlspecialchars(sprintf(__('Il file dell\'import interrotto «%s» non era più disponibile: l\'import è stato chiuso dopo %d di %d righe, ma lo storico import non è stato aggiornato. Gli utenti già creati restano; per completarlo carica di nuovo il file.'), $expiredImport['name'], $expiredImport['processed'], $expiredImport['total']), ENT_QUOTES, 'UTF-8') ?>
          <?php else: ?>
          <i class="fas fa-info-circle mr-1"></i><?= htmlspecialchars(sprintf(__('Il file dell\'import interrotto «%s» non era più disponibile: l\'import è stato chiuso dopo %d di %d righe e registrato nello storico import. Gli utenti già creati restano; per completarlo carica di nuovo il file.'), $expiredImport['name'], $expiredImport['processed'], $expiredImport['total']), ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
          <?php if (\App\Support\Permissions::can('catalog.manage')): // the history page belongs to the catalogue permission ?>
          <a class="underline" href="<?= htmlspecialchars(url('/admin/imports-history'), ENT_QUOTES, 'UTF-8') ?>"><?= __("storico import") ?></a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($resumeImport !== null): ?>
        <!-- An interrupted run: resume it or close it -->
        <div class="bg-white rounded-lg shadow-sm border border-amber-200 p-6" id="user-import-step-resume">
          <h2 class="text-xl font-semibold text-gray-900 mb-2 flex items-center">
            <i class="fas fa-pause-circle text-amber-600 mr-2"></i>
            <?= __("Import interrotto") ?>
          </h2>
          <p class="text-sm text-gray-600"><?= htmlspecialchars(sprintf(__('%s: %d di %d righe elaborate. Puoi riprenderlo dal punto in cui si è fermato oppure chiuderlo: gli utenti già creati restano.'), $resumeImport['name'], $resumeImport['processed'], $resumeImport['total']), ENT_QUOTES, 'UTF-8') ?></p>
          <div class="pk-form-actions mt-6 flex justify-end gap-3">
            <button type="button" id="user-import-abandon" class="btn-secondary"><?= __("Annulla import") ?></button>
            <button type="button" id="user-import-resume" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
              <i class="fas fa-play mr-2"></i>
              <?= __("Riprendi import") ?>
            </button>
          </div>
        </div>
        <?php endif; ?>

        <!-- Step 1: upload -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6<?= $resumeImport !== null ? ' hidden' : '' ?>" id="user-import-step-upload">
          <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-upload text-gray-600 mr-2"></i>
            <?= __("Carica File CSV") ?>
          </h2>
          <form id="user-import-upload-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <label for="user_import_file" class="block text-sm font-medium text-gray-700 mb-2"><?= __("File CSV degli utenti") ?></label>
            <input type="file" name="csv_file" id="user_import_file" accept=".csv,.tsv,.txt,text/csv" required class="block w-full text-sm text-gray-700 border border-gray-300 rounded-lg bg-gray-50 p-2">
            <label for="user_import_delimiter" class="block text-sm font-medium text-gray-700 mb-2 mt-4"><?= __("Separatore") ?></label>
            <select name="delimiter" id="user_import_delimiter" class="form-input">
              <option value=""><?= __("Automatico") ?></option>
              <option value=";">;</option>
              <option value=",">,</option>
              <option value="tab">TAB</option>
            </select>
            <div class="pk-form-actions mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
              <div class="text-sm text-gray-600">
                <div class="flex items-center">
                  <i class="fas fa-info-circle mr-1"></i>
                  <?= __("Separatore %s, %s o TAB • Max 5MB", '<code class="bg-gray-100 px-2 py-0.5 rounded">;</code>', '<code class="bg-gray-100 px-2 py-0.5 rounded">,</code>') ?>
                </div>
              </div>
              <button type="submit" id="user-import-upload-btn" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                <i class="fas fa-eye mr-2"></i>
                <?= __("Carica e mostra anteprima") ?>
              </button>
            </div>
          </form>
        </div>

        <!-- Step 2: preview and mapping -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hidden" id="user-import-step-mapping">
          <h2 class="text-xl font-semibold text-gray-900 mb-1 flex items-center">
            <i class="fas fa-columns text-gray-600 mr-2"></i>
            <?= __("Anteprima e mappatura colonne") ?>
          </h2>
          <p class="text-sm text-gray-600 mb-4"><span id="user-import-file-name" class="font-medium"></span> — <span id="user-import-row-count"></span></p>
          <div id="user-import-notice" class="mb-4 p-3 bg-amber-50 text-amber-800 rounded-lg border border-amber-200 text-sm hidden" role="status">
            <i class="fas fa-info-circle mr-1"></i><span id="user-import-notice-text"></span>
          </div>
          <div class="overflow-x-auto border border-gray-200 rounded-lg">
            <table class="w-full text-sm" id="user-import-preview">
              <thead class="bg-gray-50 border-b border-gray-200"></thead>
              <tbody class="divide-y divide-gray-100"></tbody>
            </table>
          </div>
          <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
            <label class="flex items-start cursor-pointer">
              <div class="flex items-center h-5">
                <input type="checkbox" id="user_import_send_invites" value="1" class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2">
              </div>
              <div class="ml-3 text-sm">
                <div class="font-medium text-gray-900">
                  <i class="fas fa-envelope mr-1 text-gray-600"></i>
                  <?= __("Invia a ogni utente importato l'email per impostare la password") ?>
                </div>
                <p class="text-gray-600 mt-1"><?= __("Le password non vengono mai importate: senza invito l'utente può comunque usare «Password dimenticata».") ?></p>
              </div>
            </label>
          </div>
          <div class="pk-form-actions mt-6 flex justify-end gap-3">
            <button type="button" id="user-import-cancel" class="btn-secondary"><?= __("Annulla") ?></button>
            <button type="button" id="user-import-start" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
              <i class="fas fa-cloud-upload-alt mr-2"></i>
              <?= __("Avvia import") ?>
            </button>
          </div>
        </div>

        <!-- Step 3: progress and result -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hidden" id="user-import-step-result">
          <div id="user-import-progress" class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-medium text-gray-700" id="user-import-progress-label"><?= __("Importazione in corso...") ?></span>
              <span id="user-import-progress-percent" class="text-sm font-bold text-gray-900">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5 mb-2">
              <div id="user-import-progress-bar" class="bg-gray-800 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
            <div class="text-xs text-gray-600" id="user-import-progress-status"></div>
          </div>
          <div id="user-import-summary" class="hidden mt-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
              <i class="fas fa-check-circle text-green-600 mr-2"></i>
              <?= __("Import completato") ?>
            </h2>
            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg"><dt class="text-sm text-gray-500"><?= __("Utenti creati") ?></dt><dd class="text-2xl font-bold text-gray-900" data-import-count="created">0</dd></div>
              <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg"><dt class="text-sm text-gray-500"><?= __("Duplicati saltati") ?></dt><dd class="text-2xl font-bold text-gray-900" data-import-count="duplicates">0</dd></div>
              <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg"><dt class="text-sm text-gray-500"><?= __("Righe con errori") ?></dt><dd class="text-2xl font-bold text-gray-900" data-import-count="failed">0</dd></div>
              <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg"><dt class="text-sm text-gray-500"><?= __("Inviti inviati") ?></dt><dd class="text-2xl font-bold text-gray-900" data-import-count="invited">0</dd></div>
            </dl>
            <div id="user-import-errors" class="hidden mt-6 overflow-x-auto border border-gray-200 rounded-lg">
              <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200"><tr>
                  <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Riga") ?></th>
                  <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Utente") ?></th>
                  <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Tipo") ?></th>
                  <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Messaggio") ?></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100"></tbody>
              </table>
            </div>
            <div class="pk-form-actions mt-6 flex flex-wrap justify-end gap-3">
              <a id="user-import-download-errors" href="#" class="px-6 py-2 bg-gray-100 text-gray-800 hover:bg-gray-200 rounded-lg transition-colors inline-flex items-center hidden">
                <i class="fas fa-file-download mr-2"></i>
                <?= __("Scarica errori (CSV)") ?>
              </a>
              <a href="<?= htmlspecialchars(url('/admin/users/import'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary"><?= __("Nuovo import") ?></a>
              <a href="<?= htmlspecialchars(url('/admin/users'), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary"><?= __("Vai agli utenti") ?></a>
            </div>
          </div>
        </div>

        <!-- Example -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-download text-gray-600 mr-2"></i>
            <?= __("File di Esempio") ?>
          </h2>
          <p class="text-gray-600 mb-4"><?= __("Scarica un CSV di esempio con le colonne riconosciute automaticamente.") ?></p>
          <a href="<?= htmlspecialchars(url('/admin/users/import/example'), ENT_QUOTES, 'UTF-8') ?>" class="px-6 py-2 bg-gray-100 text-gray-800 hover:bg-gray-200 rounded-lg transition-colors inline-flex items-center">
            <i class="fas fa-file-download mr-2"></i>
            <?= __("Scarica esempio_import_utenti.csv") ?>
          </a>
        </div>
      </div>

      <!-- Rules -->
      <div class="space-y-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-info-circle text-gray-600 mr-2"></i>
            <?= __("Come funziona") ?>
          </h2>
          <ul class="space-y-3 text-sm text-gray-600">
            <li class="flex items-start"><i class="fas fa-check text-green-600 mt-0.5 mr-2"></i><span><?= __("Nome ed email sono obbligatori; le altre colonne sono facoltative.") ?></span></li>
            <li class="flex items-start"><i class="fas fa-check text-green-600 mt-0.5 mr-2"></i><span><?= __("Una riga con un'email o un codice tessera già presenti viene saltata come duplicato: gli utenti esistenti non vengono mai modificati.") ?></span></li>
            <li class="flex items-start"><i class="fas fa-check text-green-600 mt-0.5 mr-2"></i><span><?= __("Senza codice tessera ne viene generato uno nuovo.") ?></span></li>
            <li class="flex items-start"><i class="fas fa-check text-green-600 mt-0.5 mr-2"></i><span><?= __("Le date possono essere scritte come 2026-12-31, 31/12/2026 o 31.12.2026.") ?></span></li>
            <li class="flex items-start"><i class="fas fa-check text-green-600 mt-0.5 mr-2"></i><span><?= __("I campi personalizzati della registrazione compaiono tra le colonne da associare.") ?></span></li>
            <?php if ($patronTypes): ?>
            <li class="flex items-start"><i class="fas fa-check text-green-600 mt-0.5 mr-2"></i><span><?= __("La tipologia lettore si indica con il suo codice o con il suo nome.") ?></span></li>
            <li class="flex items-start"><i class="fas fa-check text-green-600 mt-0.5 mr-2"></i><span><?= __("Senza scadenza tessera nel file, la data viene calcolata dalla durata tessera della tipologia lettore (quella indicata o la predefinita); una scadenza presente nel CSV ha la precedenza.") ?></span></li>
            <?php endif; ?>
            <li class="flex items-start"><i class="fas fa-lock text-gray-500 mt-0.5 mr-2"></i><span><?= __("Le password non vengono mai importate.") ?></span></li>
          </ul>
          <?php if (\App\Support\Permissions::can('catalog.manage')): // the history page belongs to the catalogue permission ?>
          <p class="text-xs text-gray-500 mt-4">
            <?= __("Il riepilogo di ogni import, con gli errori da scaricare, resta nello") ?>
            <a class="underline" href="<?= htmlspecialchars(url('/admin/imports-history'), ENT_QUOTES, 'UTF-8') ?>"><?= __("storico import") ?></a>.
          </p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const BASE = window.BASE_PATH || '';
  const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const T = <?= json_encode($importI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const RESUME = <?= json_encode($resumeImport, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (s, ...args) => { let i = 0; return String(s).replace(/%d/g, () => String(args[i++] ?? '')); };
  let state = { headers: [], fields: {} };
  // Every request names the preview it comes from (preview_id). An upload in
  // another tab replaces the server state: this tab's Start (or Resume) is
  // then disabled, and the server refuses a stale id with 409 anyway.
  let previewId = RESUME ? RESUME.preview_id : null;
  let channel = null;
  try {
    channel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('pinakes-user-import') : null;
  } catch (e) {
    channel = null;
  }
  function markStale() {
    previewId = null;
    ['user-import-start', 'user-import-resume'].forEach((id) => { const b = $(id); if (b) { b.disabled = true; } });
    $('user-import-notice-text').textContent = T.stale;
    $('user-import-notice').classList.remove('hidden');
    // The notice lives in the mapping step; while the resume panel or the
    // result step is showing, the reason goes in the always-visible alert.
    if ($('user-import-step-mapping').classList.contains('hidden')) { showError(T.stale); }
  }
  if (channel) {
    channel.onmessage = (event) => {
      const id = event && event.data ? event.data.preview_id : null;
      if (previewId && id && id !== previewId) { markStale(); }
    };
  }

  function showError(message) {
    $('user-import-alert-text').textContent = message;
    $('user-import-alert').classList.remove('hidden');
  }
  function clearError() { $('user-import-alert').classList.add('hidden'); }

  async function postJson(path, payload) {
    const res = await fetch(BASE + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload),
    });
    let data = null;
    try { data = await res.json(); } catch (e) {
      // Not our JSON: a proxy or gateway answered (timeout), the request may
      // still have run on the server.
      const err = new Error(T.invalidResponse);
      err.transient = true;
      throw err;
    }
    if (!res.ok || !data || data.success !== true) {
      const err = new Error((data && data.error) || T.invalidResponse);
      err.status = res.status;
      err.stale = !!(data && data.stale);
      err.processed = data && Number.isInteger(data.processed) ? data.processed : null;
      err.total = data && Number.isInteger(data.total_rows) ? data.total_rows : null;
      throw err;
    }
    return data;
  }

  function renderMapping(data) {
    previewId = data.preview_id;
    $('user-import-start').disabled = false;
    state.headers = data.headers;
    state.fields = data.fields;
    $('user-import-file-name').textContent = data.file_name;
    $('user-import-row-count').textContent = fmt(T.rows, data.total_rows);
    $('user-import-notice-text').textContent = data.notice || '';
    $('user-import-notice').classList.toggle('hidden', !data.notice);
    const thead = document.querySelector('#user-import-preview thead');
    const tbody = document.querySelector('#user-import-preview tbody');
    let head = '<tr>';
    data.headers.forEach((h) => { head += '<th class="px-4 py-2 text-left font-semibold text-gray-700">' + esc(h) + '</th>'; });
    head += '</tr><tr>';
    data.headers.forEach((h, i) => {
      let options = '<option value="">' + esc(T.ignore) + '</option>';
      Object.entries(data.fields).forEach(([key, label]) => {
        const selected = data.mapping[i] === key ? ' selected' : '';
        options += '<option value="' + esc(key) + '"' + selected + '>' + esc(label) + '</option>';
      });
      head += '<th class="px-4 py-2 text-left"><select class="form-input text-sm" data-import-column="' + i + '" aria-label="' + esc(h) + '">' + options + '</select></th>';
    });
    head += '</tr>';
    thead.innerHTML = head;
    tbody.innerHTML = data.preview.map((row) => '<tr>' + data.headers.map((_, i) => '<td class="px-4 py-3 text-gray-600">' + esc(row[i] ?? '') + '</td>').join('') + '</tr>').join('');
    $('user-import-step-mapping').classList.remove('hidden');
  }

  $('user-import-upload-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    clearError();
    const fileInput = $('user_import_file');
    if (!fileInput.files || fileInput.files.length === 0) { return; }
    const button = $('user-import-upload-btn');
    button.disabled = true;
    try {
      const form = new FormData($('user-import-upload-form'));
      const res = await fetch(BASE + '/admin/users/import/upload', {
        method: 'POST', credentials: 'same-origin', body: form,
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
      });
      let data = null;
      try { data = await res.json(); } catch (e) { throw new Error(T.invalidResponse); }
      if (!res.ok || !data || data.success !== true) { throw new Error((data && data.error) || T.invalidResponse); }
      renderMapping(data);
      // The import this upload replaced was closed without its history entry.
      if (data.history_warning) { showError(data.history_warning); }
      if (channel) { try { channel.postMessage({ preview_id: data.preview_id }); } catch (e) { /* another tab simply is not told */ } }
    } catch (err) {
      showError(err.message || T.networkError);
    } finally {
      button.disabled = false;
    }
  });

  $('user-import-cancel').addEventListener('click', () => { window.location.href = BASE + '/admin/users/import'; });

  $('user-import-start').addEventListener('click', async () => {
    clearError();
    const mapping = {};
    document.querySelectorAll('[data-import-column]').forEach((select) => {
      if (select.value !== '') { mapping[select.dataset.importColumn] = select.value; }
    });
    const startBtn = $('user-import-start');
    if (!previewId) { return; }
    startBtn.disabled = true;
    let started;
    try {
      started = await postJson('/admin/users/import/start', { preview_id: previewId, mapping, send_invites: $('user_import_send_invites').checked });
    } catch (err) {
      // Another upload replaced this preview: never import its file under this mapping.
      if (err.stale) { markStale(); showError(err.message || T.stale); return; }
      // Already started (the answer to a first click was lost): the server
      // says where the run is, and it goes on from there.
      if (err.status === 409 && err.processed !== null && err.total !== null) {
        runImport(err.total, err.processed);
        return;
      }
      startBtn.disabled = false;
      showError(err.message || T.networkError);
      return;
    }
    runImport(started.total_rows, 0);
  });

  if (RESUME) {
    $('user-import-resume').addEventListener('click', () => {
      clearError();
      if (!previewId) { return; }
      runImport(RESUME.total, RESUME.processed);
    });
    $('user-import-abandon').addEventListener('click', async () => {
      clearError();
      const button = $('user-import-abandon');
      button.disabled = true;
      let closed;
      try {
        closed = await postJson('/admin/users/import/cancel', { preview_id: previewId || '' });
      } catch (err) {
        button.disabled = false;
        showError(err.message || T.networkError);
        return;
      }
      if (closed.history_warning) {
        // Closed, but not written to the history: say it here, a reload
        // would show the empty upload form as if nothing had happened.
        previewId = null;
        $('user-import-step-resume').classList.add('hidden');
        showError(closed.history_warning);
        return;
      }
      window.location.href = BASE + '/admin/users/import';
    });
  }

  async function runImport(total, from) {
    const resumePanel = $('user-import-step-resume');
    if (resumePanel) { resumePanel.classList.add('hidden'); }
    $('user-import-step-upload').classList.add('hidden');
    $('user-import-step-mapping').classList.add('hidden');
    $('user-import-step-result').classList.remove('hidden');
    const runPreviewId = previewId;
    let start = from;
    let last = null;
    let retries = 0;
    try {
      while (true) {
        try {
          last = await postJson('/admin/users/import/chunk', { preview_id: runPreviewId, start });
        } catch (err) {
          if (err.stale) { markStale(); throw err; }
          // A block that failed in transit may have been written anyway: the
          // server answers 409 with the position it reached, and the import
          // resumes from there. A network error retries the same block,
          // which the server then accepts or answers with that 409.
          const resumable = err.status === 409 && err.processed !== null;
          const transient = err.transient === true || err instanceof TypeError;
          if ((!resumable && !transient) || retries >= 3) { throw err; }
          retries++;
          if (resumable) {
            start = err.processed;
          } else {
            await new Promise((resolve) => setTimeout(resolve, 1000 * retries));
          }
          continue;
        }
        retries = 0;
        start = last.processed;
        const pct = total > 0 ? Math.min(100, Math.round(start * 100 / total)) : 100;
        $('user-import-progress-bar').style.width = pct + '%';
        $('user-import-progress-percent').textContent = pct + '%';
        $('user-import-progress-status').textContent = fmt(T.progress, start, total);
        if (last.done) { break; }
      }
    } catch (err) {
      showError(err.message || T.networkError);
      return;
    }
    // The run is over: an upload in another tab from now on starts a new
    // import and does not make this summary stale.
    previewId = null;
    $('user-import-progress').classList.add('hidden');
    $('user-import-summary').classList.remove('hidden');
    ['created', 'duplicates', 'failed', 'invited'].forEach((key) => {
      document.querySelector('[data-import-count="' + key + '"]').textContent = String(last[key] ?? 0);
    });
    const errors = Array.isArray(last.errors) ? last.errors : [];
    if (errors.length > 0) {
      document.querySelector('#user-import-errors tbody').innerHTML = errors.map((e) =>
        '<tr data-import-error="' + esc(e.type) + '"><td class="px-4 py-3 text-gray-600">' + esc(e.line) + '</td><td class="px-4 py-3 text-gray-900">' + esc(e.title)
        + '</td><td class="px-4 py-3 text-gray-600">' + esc(T.types[e.type] || e.type) + '</td><td class="px-4 py-3 text-gray-600">' + esc(e.message) + '</td></tr>').join('');
      $('user-import-errors').classList.remove('hidden');
    }
    if (last.log_error) { showError(last.log_error); }
    if (last.import_id && errors.length > 0) {
      const link = $('user-import-download-errors');
      link.href = BASE + '/admin/users/import/errors?import_id=' + encodeURIComponent(last.import_id);
      link.classList.remove('hidden');
    }
  }
})();
</script>
