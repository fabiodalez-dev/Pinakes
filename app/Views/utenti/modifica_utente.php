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
?>
<div class="py-8">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="mb-8">
      <h1 class="text-2xl font-semibold text-gray-900"><?= __("Modifica utente") ?></h1>
      <p class="text-sm text-gray-500 mt-1"><?= __("Aggiorna le informazioni del profilo selezionato.") ?></p>
    </div>

    <?php if ($errorKey !== ''): ?>
      <div class="mb-6 border border-red-200 bg-red-50 text-red-700 rounded-lg px-4 py-3 text-sm" role="alert">
        <?php if ($errorKey === 'missing_fields'): ?>
          <?= __("Compila tutti i campi obbligatori prima di salvare.") ?>
        <?php elseif ($errorKey === 'db_error'): ?>
          <?= __("Impossibile aggiornare l'utente. Riprova più tardi.") ?>
        <?php elseif ($errorKey === 'csrf'): ?>
          <?= __("La sessione è scaduta. Aggiorna la pagina e riprova.") ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form action="/admin/utenti/update/<?= $utenteId; ?>" method="post" class="space-y-6" id="user-form">
      <input type="hidden" name="csrf_token" value="<?= HtmlHelper::e($csrfToken); ?>">

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900"><?= __("Tipologia account") ?></h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="tipo_utente" class="block text-sm font-medium text-gray-700"><?= __("Tipo utente") ?></label>
            <select id="tipo_utente" name="tipo_utente" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option value="standard" <?= $ruolo === 'standard' ? 'selected' : ''; ?>><?= __("Standard") ?></option>
              <option value="premium" <?= $ruolo === 'premium' ? 'selected' : ''; ?>><?= __("Premium") ?></option>
              <option value="staff" <?= $ruolo === 'staff' ? 'selected' : ''; ?>><?= __("Staff") ?></option>
              <option value="admin" <?= $ruolo === 'admin' ? 'selected' : ''; ?>><?= __("Amministratore") ?></option>
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
            <label for="cognome" class="block text-sm font-medium text-gray-700"><?= __("Cognome") ?> *</label>
            <input type="text" id="cognome" name="cognome" value="<?= $cognome; ?>" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2" data-admin-hide>
          <div>
            <label for="data_nascita" class="block text-sm font-medium text-gray-700"><?= __("Data di nascita") ?></label>
            <input type="date" id="data_nascita" name="data_nascita" value="<?= $dataNascita; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <div>
            <label for="sesso" class="block text-sm font-medium text-gray-700"><?= __("Sesso") ?></label>
            <select id="sesso" name="sesso" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option><?= __("-- Seleziona --") ?></option>
              <option value="M" <?= $sesso === 'M' ? 'selected' : ''; ?>><?= __("Maschio") ?></option>
              <option value="F" <?= $sesso === 'F' ? 'selected' : ''; ?>><?= __("Femmina") ?></option>
              <option value="Altro" <?= $sesso === 'Altro' ? 'selected' : ''; ?>><?= __("Altro") ?></option>
            </select>
          </div>
        </div>
        <div data-admin-hide>
          <label for="indirizzo" class="block text-sm font-medium text-gray-700"><?= __("Indirizzo completo") ?></label>
          <textarea id="indirizzo" name="indirizzo" rows="3" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= __("Via, numero civico, città, CAP") ?>"><?= $indirizzo; ?></textarea>
        </div>
        <div data-admin-hide>
          <label for="cod_fiscale" class="block text-sm font-medium text-gray-700"><?= __("Codice Fiscale") ?></label>
          <input type="text" id="cod_fiscale" name="cod_fiscale" maxlength="16" value="<?= $cod_fiscale; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= __("es. RSSMRA80A01H501U") ?>" style="text-transform: uppercase;">
          <p class="text-xs text-gray-500 mt-1"><?= __("Codice fiscale italiano (opzionale)") ?></p>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900"><?= __("Contatti e accesso") ?></h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700"><?= __("Email") ?> *</label>
            <input type="email" id="email" name="email" value="<?= $email; ?>" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <div>
            <label for="telefono" class="block text-sm font-medium text-gray-700"><?= __("Telefono") ?></label>
            <input type="text" id="telefono" name="telefono" value="<?= $telefono; ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= __('+39 123 456 7890') ?>">
            <p class="text-xs text-gray-500 mt-1"><?= __("Obbligatorio per utenti non amministratori.") ?></p>
          </div>
        </div>
        <div>
          <label for="password" class="block text-sm font-medium text-gray-700"><?= __("Nuova password") ?></label>
          <input type="password" autocomplete="new-password" id="password" name="password" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= __("Lascia vuoto per non modificare") ?>">
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
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6">
        <label for="note_utente" class="block text-sm font-medium text-gray-700"><?= __("Note interne") ?></label>
        <textarea id="note_utente" name="note_utente" rows="3" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= __("Informazioni utili per il personale") ?>"><?= $note; ?></textarea>
      </section>

      <div class="flex items-center justify-end gap-3">
        <a href="/admin/utenti" class="btn-secondary"><?= __("Annulla") ?></a>
        <button type="submit" class="btn-primary"><?= __("Salva modifiche") ?></button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  const roleField = document.getElementById('tipo_utente');
  const phoneField = document.getElementById('telefono');
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
      phoneField.required = !isAdmin;
      phoneField.placeholder = isAdmin ? __('Opzionale per amministratori') : '+39 123 456 7890';
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
