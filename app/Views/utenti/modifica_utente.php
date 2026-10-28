<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$csrfToken = Csrf::ensureToken();
$errorKey = (string)($_GET['error'] ?? '');
$utenteId = (int)($utente['id'] ?? 0);
$nome = HtmlHelper::e($utente['nome'] ?? '');
$cognome = HtmlHelper::e($utente['cognome'] ?? '');
$email = HtmlHelper::e($utente['email'] ?? '');
$telefono = HtmlHelper::e($utente['telefono'] ?? '');
$indirizzo = HtmlHelper::e($utente['indirizzo'] ?? '');
$cod_fiscale = HtmlHelper::e($utente['cod_fiscale'] ?? '');
$dataNascita = HtmlHelper::e($utente['data_nascita'] ?? '');
$sesso = (string)($utente['sesso'] ?? '');
$codiceTessera = HtmlHelper::e($utente['codice_tessera'] ?? '');
$dataScadenzaTessera = HtmlHelper::e($utente['data_scadenza_tessera'] ?? '');
$stato = (string)($utente['stato'] ?? 'attivo');
$ruolo = (string)($utente['tipo_utente'] ?? 'standard');
$note = HtmlHelper::e($utente['note_utente'] ?? '');
$photoRaw = (string) ($utente['foto_profilo_url'] ?? '');
$photoStored = \App\Support\PatronPhoto::absolutePath($photoRaw) !== null ? $photoRaw : null;
$photoUrl = $photoStored !== null ? url('/admin/users/' . $utenteId . '/photo') . '?v=' . substr(sha1($photoStored), 0, 10) : null;
$photoI18n = [
    'saving' => __('Salvataggio della foto...'),
    'saved' => __('Foto salvata.'),
    'deleted' => __('Foto eliminata.'),
    'confirmDelete' => __('La foto verrà eliminata definitivamente. Continuare?'),
    'deleteTitle' => __('Elimina foto'),
    'cancel' => __('Annulla'),
    'noCamera' => __('Fotocamera non disponibile: usa «Carica foto» oppure apri la pagina in HTTPS.'),
    'cameraDenied' => __('Accesso alla fotocamera negato o nessuna fotocamera trovata.'),
    'error' => __('Impossibile salvare la foto. Riprova più tardi.'),
];
$availableLocales = isset($availableLocales) && is_array($availableLocales)
    ? $availableLocales
    : \App\Support\I18n::getAvailableLocales();
$selectedLocale = isset($selectedLocale) && is_string($selectedLocale)
    ? $selectedLocale
    : \App\Support\I18n::getInstallationLocale();
?>
<div class="py-8">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-2xl font-semibold text-gray-900"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= __("Modifica utente") ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

      <p class="text-sm text-gray-500 mt-1"><?= __("Aggiorna le informazioni del profilo selezionato.") ?></p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <?php if ($errorKey !== ''): ?>
      <div class="mb-6 border border-red-200 bg-red-50 text-red-700 rounded-lg px-4 py-3 text-sm" role="alert">
        <?php if ($errorKey === 'missing_fields'): ?>
          <?= __("Compila tutti i campi obbligatori prima di salvare.") ?>
        <?php elseif ($errorKey === 'email_exists'): ?>
          <?= __("Email già registrata") ?>
        <?php elseif ($errorKey === 'cf_exists'): ?>
          <?= __("Codice fiscale già registrato") ?>
        <?php elseif ($errorKey === 'tessera_exists'): ?>
          <?= __("Codice tessera già assegnato") ?>
        <?php elseif ($errorKey === 'db_error'): ?>
          <?= __("Impossibile aggiornare l'utente. Riprova più tardi.") ?>
        <?php elseif ($errorKey === 'csrf'): ?>
          <?= __("La sessione è scaduta. Aggiorna la pagina e riprova.") ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form action="<?= htmlspecialchars(url('/admin/users/update/' . $utenteId), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-6" id="user-form">
      <input type="hidden" name="csrf_token" value="<?= HtmlHelper::e($csrfToken); ?>">

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900"><?= __("Tipologia account") ?></h2>
        <?php require __DIR__ . '/permessi.php'; ?>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="tipo_utente" class="block text-sm font-medium text-gray-700"><?= __("Tipo utente") ?></label>
            <select id="tipo_utente" name="tipo_utente" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option value="standard" <?= $ruolo === 'standard' ? 'selected' : ''; ?>><?= __("Standard") ?></option>
              <option value="premium" <?= $ruolo === 'premium' ? 'selected' : ''; ?>><?= __("Premium") ?></option>
              <?php if (in_array($_SESSION['user']['tipo_utente'] ?? '', ['admin', 'staff'], true)): ?>
              <option value="staff" <?= $ruolo === 'staff' ? 'selected' : ''; ?>><?= __("Staff") ?></option>
              <?php endif; ?>
              <?php if (($_SESSION['user']['tipo_utente'] ?? '') === 'admin'): ?>
              <option value="admin" <?= $ruolo === 'admin' ? 'selected' : ''; ?>><?= __("Amministratore") ?></option>
              <?php endif; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1" id="role-hint"><?= __("Definisce i privilegi dell'utente.") ?></p>
          </div>
          <div>
            <label for="stato" class="block text-sm font-medium text-gray-700"><?= __("Stato") ?></label>
            <select id="stato" name="stato" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option value="attivo" <?= $stato === 'attivo' ? 'selected' : ''; ?>><?= __("Attivo") ?></option>
              <option value="sospeso" <?= $stato === 'sospeso' ? 'selected' : ''; ?>><?= __("Sospeso") ?></option>
              <option value="scaduto" <?= $stato === 'scaduto' ? 'selected' : ''; ?>><?= __("Scaduto") ?></option>
            </select>
          </div>
          <?php require __DIR__ . '/tipologia-lettore.php'; ?>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900"><?= __("Informazioni personali") ?></h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="nome" class="block text-sm font-medium text-gray-700"><?= __("Nome") ?> *</label>
            <input type="text" id="nome" name="nome" value="<?= $nome; ?>" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <div>
            <label for="cognome" class="block text-sm font-medium text-gray-700"><?= __("Cognome") ?><?= \App\Support\RegistrationFields::isRequired('cognome') ? ' *' : '' ?></label>
            <input type="text" id="cognome" name="cognome" value="<?= $cognome; ?>" <?= \App\Support\RegistrationFields::isRequired('cognome') ? 'required aria-required="true"' : '' ?> class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2" data-admin-hide>
          <div>
            <label for="data_nascita" class="block text-sm font-medium text-gray-700"><?= __("Data di nascita") ?></label>
            <input type="date" id="data_nascita" name="data_nascita" value="<?= $dataNascita; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <?php if (\App\Support\RegistrationFields::isVisible('sesso')): ?>
          <div>
            <label for="sesso" class="block text-sm font-medium text-gray-700"><?= __("Sesso") ?></label>
            <select id="sesso" name="sesso" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option value="" <?= empty($sesso) ? 'selected' : ''; ?>><?= __("-- Seleziona --") ?></option>
              <option value="M" <?= $sesso === 'M' ? 'selected' : ''; ?>><?= __("Maschio") ?></option>
              <option value="F" <?= $sesso === 'F' ? 'selected' : ''; ?>><?= __("Femmina") ?></option>
              <option value="Altro" <?= $sesso === 'Altro' ? 'selected' : ''; ?>><?= __("Altro") ?></option>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <div data-admin-hide>
          <label for="indirizzo" class="block text-sm font-medium text-gray-700"><?= __("Indirizzo completo") ?></label>
          <textarea id="indirizzo" name="indirizzo" rows="3" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= htmlspecialchars(__("Via, numero civico, città, CAP"), ENT_QUOTES, 'UTF-8') ?>"><?= $indirizzo; ?></textarea>
        </div>
        <?php if (\App\Support\RegistrationFields::isVisible('cod_fiscale')): ?>
        <div data-admin-hide>
          <label for="cod_fiscale" class="block text-sm font-medium text-gray-700"><?= __("Codice Fiscale") ?></label>
          <input type="text" id="cod_fiscale" name="cod_fiscale" maxlength="16" value="<?= $cod_fiscale; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= htmlspecialchars(__("es. RSSMRA80A01H501U"), ENT_QUOTES, 'UTF-8') ?>" style="text-transform: uppercase;">
          <p class="text-xs text-gray-500 mt-1"><?= __("Codice fiscale italiano (opzionale)") ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($customFieldValues)): ?>
          <div class="md:col-span-2">
            <p class="block text-sm font-medium text-gray-700"><?= __("Campi personalizzati") ?></p>
            <dl class="mt-2 grid gap-2 md:grid-cols-2">
              <?php foreach ($customFieldValues as $cfv): ?>
                <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                  <dt class="text-xs font-medium text-gray-500"><?= htmlspecialchars($cfv['etichetta'], ENT_QUOTES, 'UTF-8') ?></dt>
                  <dd class="text-sm text-gray-900 break-words"><?= $cfv['tipo'] === 'checkbox' ? __('Sì') : htmlspecialchars($cfv['valore'], ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
              <?php endforeach; ?>
            </dl>
            <p class="text-xs text-gray-500 mt-1"><?= __("L'utente li gestisce dal proprio profilo.") ?></p>
          </div>
        <?php endif; ?>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4" id="patron-photo-widget">
        <h2 class="text-lg font-medium text-gray-900"><?= __("Foto") ?></h2>
        <div class="flex flex-col sm:flex-row gap-6">
          <div class="shrink-0">
            <img id="photo-preview" <?= $photoUrl !== null ? 'src="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '"' : '' ?> alt="<?= htmlspecialchars(__('Foto dell\'utente'), ENT_QUOTES, 'UTF-8') ?>" class="w-32 h-40 object-cover rounded-lg border border-gray-200 <?= $photoUrl === null ? 'hidden' : '' ?>">
            <div id="photo-placeholder" class="w-32 h-40 rounded-lg border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center text-gray-400 <?= $photoUrl !== null ? 'hidden' : '' ?>">
              <i class="fas fa-user text-3xl" aria-hidden="true"></i>
            </div>
            <video id="photo-video" class="w-32 h-40 object-cover rounded-lg border border-gray-200 bg-gray-900 hidden" autoplay playsinline muted></video>
          </div>
          <div class="space-y-3 text-sm text-gray-600 flex-1">
            <p><?= __("Scatta la foto con la webcam o carica un'immagine: comparirà sulla tessera. È un dato personale, visibile solo al personale, e può essere eliminata in qualsiasi momento su richiesta dell'utente.") ?></p>
            <div class="flex flex-wrap items-center gap-3" id="photo-actions">
              <button type="button" id="photo-camera-start" class="btn-secondary"><i class="fas fa-camera mr-2"></i><?= __("Scatta foto") ?></button>
              <label for="photo-file" class="btn-secondary cursor-pointer"><i class="fas fa-upload mr-2"></i><?= __("Carica foto") ?></label>
              <input type="file" id="photo-file" accept="image/jpeg,image/png,image/webp" class="sr-only">
              <button type="button" id="photo-delete" class="btn-danger-outline px-4 py-2 rounded-lg inline-flex items-center <?= $photoUrl === null ? 'hidden' : '' ?>"><i class="fas fa-trash mr-2"></i><?= __("Elimina foto") ?></button>
            </div>
            <div class="flex flex-wrap items-center gap-3 hidden" id="photo-camera-actions">
              <button type="button" id="photo-capture" class="btn-primary"><i class="fas fa-circle mr-2"></i><?= __("Scatta") ?></button>
              <button type="button" id="photo-camera-cancel" class="btn-secondary"><?= __("Annulla") ?></button>
            </div>
            <p id="photo-status" class="text-sm" role="status" aria-live="polite"></p>
          </div>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900"><?= __("Contatti e accesso") ?></h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700"><?= __("Email") ?> *</label>
            <input type="email" id="email" name="email" autocomplete="username" value="<?= $email; ?>" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <div>
            <label for="telefono" class="block text-sm font-medium text-gray-700"><?= __("Telefono") ?></label>
            <input type="text" id="telefono" name="telefono" value="<?= $telefono; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= htmlspecialchars(__('+39 123 456 7890'), ENT_QUOTES, 'UTF-8') ?>">
            <p class="text-xs text-gray-500 mt-1"><?= __("Obbligatorio per utenti non amministratori.") ?></p>
          </div>
        </div>
        <div>
          <label for="password" class="block text-sm font-medium text-gray-700"><?= __("Nuova password") ?></label>
          <input type="password" autocomplete="new-password" id="password" name="password" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= htmlspecialchars(__("Lascia vuoto per non modificare"), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label for="locale" class="block text-sm font-medium text-gray-700"><?= __("Lingua dell'interfaccia") ?></label>
          <select id="locale" name="locale" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
            <?php foreach ($availableLocales as $code => $name): ?>
              <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>" <?= $code === $selectedLocale ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-gray-500 mt-1"><?= __("L'utente potrà cambiarla in qualsiasi momento.") ?></p>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4" data-admin-tessera>
        <h2 class="text-lg font-medium text-gray-900"><?= __("Tessera biblioteca") ?></h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="codice_tessera" class="block text-sm font-medium text-gray-700"><?= __("Codice tessera") ?></label>
            <input type="text" id="codice_tessera" name="codice_tessera" value="<?= $codiceTessera; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <div>
            <label for="data_scadenza_tessera" class="block text-sm font-medium text-gray-700"><?= __("Scadenza tessera") ?></label>
            <input type="date" id="data_scadenza_tessera" name="data_scadenza_tessera" value="<?= $dataScadenzaTessera; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" id="card_auto" name="card_auto" value="1" class="rounded border-gray-300">
              <?= __("Calcola la scadenza dalla durata tessera della tipologia lettore (se indicata)") ?>
            </label>
          </div>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6">
        <label for="note_utente" class="block text-sm font-medium text-gray-700"><?= __("Note interne") ?></label>
        <textarea id="note_utente" name="note_utente" rows="3" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= htmlspecialchars(__("Informazioni utili per il personale"), ENT_QUOTES, 'UTF-8') ?>"><?= $note; ?></textarea>
      </section>

      <div class="pk-form-actions flex items-center justify-end gap-3">
        <a href="<?= htmlspecialchars(url('/admin/users'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary"><?= __("Annulla") ?></a>
        <button type="submit" class="btn-primary"><?= __("Salva modifiche") ?></button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  // Patron photo: webcam (getUserMedia → canvas → JPEG) or picked file, both
  // sent to the same protected endpoint; deleting removes file and reference.
  const BASE = window.BASE_PATH || '';
  const USER_ID = <?= (int) $utenteId ?>;
  const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const T = <?= json_encode($photoI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const preview = document.getElementById('photo-preview');
  const placeholder = document.getElementById('photo-placeholder');
  const video = document.getElementById('photo-video');
  const status = document.getElementById('photo-status');
  const actions = document.getElementById('photo-actions');
  const cameraActions = document.getElementById('photo-camera-actions');
  const deleteBtn = document.getElementById('photo-delete');
  let stream = null;

  const say = (text, isError) => {
    status.textContent = text;
    status.className = 'text-sm ' + (isError ? 'text-red-600' : 'text-gray-600');
  };
  const stopCamera = () => {
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    video.srcObject = null;
    video.classList.add('hidden');
    cameraActions.classList.add('hidden');
    actions.classList.remove('hidden');
    if (preview.getAttribute('src')) { preview.classList.remove('hidden'); } else { placeholder.classList.remove('hidden'); }
  };
  const showPhoto = (url) => {
    preview.src = url;
    preview.classList.remove('hidden');
    placeholder.classList.add('hidden');
    deleteBtn.classList.remove('hidden');
  };
  async function upload(blob, name) {
    say(T.saving, false);
    const form = new FormData();
    form.append('photo', blob, name);
    form.append('csrf_token', CSRF);
    try {
      const res = await fetch(BASE + '/admin/users/' + USER_ID + '/photo', {
        method: 'POST', credentials: 'same-origin', body: form,
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || data.success !== true) { say((data && data.error) || T.error, true); return; }
      showPhoto(data.url);
      say(T.saved, false);
    } catch (e) {
      say(T.error, true);
    }
  }

  document.getElementById('photo-camera-start').addEventListener('click', async () => {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') { say(T.noCamera, true); return; }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 960 }, height: { ideal: 720 }, facingMode: 'user' }, audio: false });
    } catch (e) {
      say(T.cameraDenied, true);
      return;
    }
    video.srcObject = stream;
    preview.classList.add('hidden');
    placeholder.classList.add('hidden');
    video.classList.remove('hidden');
    actions.classList.add('hidden');
    cameraActions.classList.remove('hidden');
    say('', false);
  });
  document.getElementById('photo-camera-cancel').addEventListener('click', stopCamera);
  document.getElementById('photo-capture').addEventListener('click', () => {
    const w = video.videoWidth, h = video.videoHeight;
    if (!w || !h) { return; }
    // Portrait 4:5 crop from the centre of the frame, as on the card.
    const cropH = h, cropW = Math.min(w, Math.round(h * 0.8));
    const canvas = document.createElement('canvas');
    canvas.width = 480; canvas.height = 600;
    canvas.getContext('2d').drawImage(video, (w - cropW) / 2, 0, cropW, cropH, 0, 0, canvas.width, canvas.height);
    canvas.toBlob((blob) => {
      stopCamera();
      if (blob) { upload(blob, 'webcam.jpg'); }
    }, 'image/jpeg', 0.9);
  });
  document.getElementById('photo-file').addEventListener('change', (event) => {
    const file = event.target.files && event.target.files[0];
    if (file) { upload(file, file.name); }
    event.target.value = '';
  });
  deleteBtn.addEventListener('click', async () => {
    let confirmed;
    if (window.Swal && typeof window.Swal.fire === 'function') {
      const r = await window.Swal.fire({ title: T.deleteTitle, text: T.confirmDelete, icon: 'warning', showCancelButton: true, confirmButtonText: T.deleteTitle, cancelButtonText: T.cancel });
      confirmed = r.isConfirmed;
    } else {
      confirmed = window.confirm(T.confirmDelete);
    }
    if (!confirmed) { return; }
    try {
      const res = await fetch(BASE + '/admin/users/' + USER_ID + '/photo/delete', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ csrf_token: CSRF }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || data.success !== true) { say((data && data.error) || T.error, true); return; }
      preview.removeAttribute('src');
      preview.classList.add('hidden');
      placeholder.classList.remove('hidden');
      deleteBtn.classList.add('hidden');
      say(T.deleted, false);
    } catch (e) {
      say(T.error, true);
    }
  });
  window.addEventListener('pagehide', stopCamera);
})();

(function() {
  const roleField = document.getElementById('tipo_utente');
  const phoneField = document.getElementById('telefono');
  const addressField = document.getElementById('indirizzo');
  // Requirement toggles (issue #255): the client mirrors the server checks in
  // UsersController, which apply only to non-admin users.
  const REQUIRE_TELEFONO = <?= \App\Support\RegistrationFields::isRequired('telefono') ? 'true' : 'false' ?>;
  const REQUIRE_INDIRIZZO = <?= \App\Support\RegistrationFields::isRequired('indirizzo') ? 'true' : 'false' ?>;
  const tesseraSection = document.querySelector('[data-admin-tessera]');
  const adminBlocks = document.querySelectorAll('[data-admin-hide]');
  const roleHint = document.getElementById('role-hint');

  const storeOriginalState = (container) => {
    container.dataset.originalDisplay = container.dataset.originalDisplay || container.style.display || '';
    container.querySelectorAll('input, textarea, select').forEach((el) => {
      if (el.dataset.originalValue === undefined) {
        el.dataset.originalValue = el.value;
      }
      if (el.type === 'checkbox' || el.type === 'radio') {
        el.dataset.originalChecked = el.checked ? '1' : '0';
      }
    });
  };

  adminBlocks.forEach(storeOriginalState);
  if (tesseraSection) storeOriginalState(tesseraSection);

  function applyRoleState() {
    const isAdmin = roleField.value === 'admin';
    if (phoneField) {
      phoneField.required = !isAdmin && REQUIRE_TELEFONO;
      phoneField.placeholder = isAdmin ? __('Opzionale per amministratori') : '+39 123 456 7890';
    }
    if (addressField) {
      addressField.required = !isAdmin && REQUIRE_INDIRIZZO;
    }

    adminBlocks.forEach((section) => {
      section.style.display = isAdmin ? 'none' : (section.dataset.originalDisplay || '');
      section.querySelectorAll('input, textarea, select').forEach((el) => {
        if (isAdmin) {
          if (el.type === 'checkbox' || el.type === 'radio') {
            el.dataset.originalChecked = el.checked ? '1' : '0';
            el.checked = false;
          } else {
            el.dataset.originalValue = el.value;
            el.value = '';
          }
          el.disabled = true;
        } else {
          el.disabled = false;
          if (el.dataset.originalValue !== undefined && el.value === '') {
            el.value = el.dataset.originalValue;
          }
          if (el.dataset.originalChecked !== undefined) {
            el.checked = el.dataset.originalChecked === '1';
          }
        }
      });
    });

    if (tesseraSection) {
      tesseraSection.style.display = isAdmin ? 'none' : (tesseraSection.dataset.originalDisplay || '');
      tesseraSection.querySelectorAll('input').forEach((el) => {
        if (isAdmin) {
          el.dataset.originalValue = el.value;
          el.value = '';
          el.disabled = true;
        } else {
          el.disabled = false;
          if (el.dataset.originalValue !== undefined && el.value === '') {
            el.value = el.dataset.originalValue;
          }
        }
      });
    }

    if (roleHint) {
      roleHint.textContent = isAdmin
        ? __('Gli amministratori non richiedono tessera e riceveranno un invito per impostare la password.')
        : __('Definisce i privilegi dell\'utente.');
    }
  }

  roleField.addEventListener('change', applyRoleState);
  applyRoleState();
})();
</script>
