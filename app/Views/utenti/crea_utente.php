<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$csrfToken = Csrf::ensureToken();
$errorKey = (string)($_GET['error'] ?? '');
?>
<div class="py-8">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="mb-8">
      <h1 class="text-2xl font-semibold text-gray-900">Nuovo utente</h1>
      <p class="text-sm text-gray-500 mt-1">Crea un nuovo profilo amministratore o lettore.</p>
    </div>

    <?php if ($errorKey !== ''): ?>
      <div class="mb-6 border border-red-200 bg-red-50 text-red-700 rounded-lg px-4 py-3 text-sm" role="alert">
        <?php if ($errorKey === 'missing_fields'): ?>
          Compila tutti i campi obbligatori prima di salvare.
        <?php elseif ($errorKey === 'db_error'): ?>
          Impossibile salvare l'utente. Riprova più tardi.
        <?php elseif ($errorKey === 'csrf'): ?>
          La sessione è scaduta. Aggiorna la pagina e riprova.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form action="/admin/utenti/store" method="post" class="space-y-6" id="user-form">
      <input type="hidden" name="csrf_token" value="<?= HtmlHelper::e($csrfToken); ?>">

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900">Tipologia account</h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="tipo_utente" class="block text-sm font-medium text-gray-700">Tipo utente</label>
            <select id="tipo_utente" name="tipo_utente" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option value="standard" selected>Standard</option>
              <option value="premium">Premium</option>
              <option value="staff">Staff</option>
              <option value="admin">Amministratore</option>
            </select>
            <p class="text-xs text-gray-500 mt-1" id="role-hint">Definisce i privilegi dell'utente.</p>
          </div>
          <div>
            <label for="stato" class="block text-sm font-medium text-gray-700">__("Stato")</label>
            <select id="stato" name="stato" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option value="attivo" selected>__("Attivo")</option>
              <option value="sospeso">Sospeso</option>
              <option value="scaduto">__("Scaduto")</option>
            </select>
          </div>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900">Informazioni personali</h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="nome" class="block text-sm font-medium text-gray-700">Nome *</label>
            <input type="text" id="nome" name="nome" required aria-required="true" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="Nome">
          </div>
          <div>
            <label for="cognome" class="block text-sm font-medium text-gray-700">Cognome *</label>
            <input type="text" id="cognome" name="cognome" required aria-required="true" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="Cognome">
          </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2" data-admin-hide>
          <div>
            <label for="data_nascita" class="block text-sm font-medium text-gray-700">Data di nascita</label>
            <input type="date" id="data_nascita" name="data_nascita" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
          <div>
            <label for="sesso" class="block text-sm font-medium text-gray-700">Sesso</label>
            <select id="sesso" name="sesso" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
              <option value="">-- Seleziona --</option>
              <option value="M">Maschio</option>
              <option value="F">Femmina</option>
              <option value="Altro">Altro</option>
            </select>
          </div>
        </div>
        <div data-admin-hide>
          <label for="indirizzo" class="block text-sm font-medium text-gray-700">Indirizzo completo</label>
          <textarea id="indirizzo" name="indirizzo" rows="3" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="Via, numero civico, città, CAP"></textarea>
        </div>
        <div data-admin-hide>
          <label for="cod_fiscale" class="block text-sm font-medium text-gray-700">Codice Fiscale</label>
          <input type="text" id="cod_fiscale" name="cod_fiscale" maxlength="16" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="es. RSSMRA80A01H501U" style="text-transform: uppercase;">
          <p class="text-xs text-gray-500 mt-1">Codice fiscale italiano (opzionale)</p>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-medium text-gray-900">Contatti e accesso</h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
            <input type="email" id="email" name="email" required aria-required="true" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="utente@example.com">
            <p class="text-xs text-gray-500 mt-1">Usata per login e comunicazioni.</p>
          </div>
          <div>
            <label for="telefono" class="block text-sm font-medium text-gray-700">__("Telefono")</label>
            <input type="text" id="telefono" name="telefono" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="+39 123 456 7890">
            <p class="text-xs text-gray-500 mt-1">Obbligatorio per utenti non amministratori.</p>
          </div>
        </div>
        <div>
          <label for="password" class="block text-sm font-medium text-gray-700">Password iniziale</label>
          <input type="password" autocomplete="new-password" id="password" name="password" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="Lascia vuoto per inviare un link di impostazione">
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6 space-y-4" data-admin-tessera>
        <h2 class="text-lg font-medium text-gray-900">Tessera biblioteca</h2>
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="codice_tessera" class="block text-sm font-medium text-gray-700">Codice tessera</label>
            <input type="text" id="codice_tessera" name="codice_tessera" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="Lascia vuoto per generare automaticamente">
          </div>
          <div>
            <label for="data_scadenza_tessera" class="block text-sm font-medium text-gray-700">Scadenza tessera</label>
            <input type="date" id="data_scadenza_tessera" name="data_scadenza_tessera" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
          </div>
        </div>
      </section>

      <section class="bg-white border border-gray-200 rounded-lg p-6">
        <label for="note_utente" class="block text-sm font-medium text-gray-700">Note interne</label>
        <textarea id="note_utente" name="note_utente" rows="3" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="Informazioni utili per il personale"></textarea>
      </section>

      <div class="flex items-center justify-end gap-3">
        <a href="/admin/utenti" class="btn-secondary">__("Annulla")</a>
        <button type="submit" class="btn-primary">Salva utente</button>
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
  const dataScadenzaTesseraField = document.getElementById('data_scadenza_tessera');

  // Precompila la scadenza tessera a 5 anni da oggi
  if (dataScadenzaTesseraField && !dataScadenzaTesseraField.value) {
    const today = new Date();
    const fiveYearsLater = new Date(today.getFullYear() + 5, today.getMonth(), today.getDate());
    const year = fiveYearsLater.getFullYear();
    const month = String(fiveYearsLater.getMonth() + 1).padStart(2, '0');
    const day = String(fiveYearsLater.getDate()).padStart(2, '0');
    dataScadenzaTesseraField.value = `${year}-${month}-${day}`;
    dataScadenzaTesseraField.dataset.originalValue = dataScadenzaTesseraField.value;
  }

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
      phoneField.placeholder = isAdmin ? 'Opzionale per amministratori' : '+39 123 456 7890';
    }

    adminBlocks.forEach((section) => {
      section.style.display = isAdmin ? 'none' : (section.dataset.originalDisplay || '');
      section.querySelectorAll('input, textarea, select').forEach((el) => {
        if (isAdmin) {
          if (el.type === 'checkbox' || el.type === 'radio') {
            el.checked = false;
          } else {
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
          el.disabled = true;
          el.dataset.originalValue = el.value;
          el.value = '';
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
        ? 'Gli amministratori non richiedono tessera e riceveranno un invito per impostare la password.'
        : 'Definisce i privilegi dell\'utente.';
    }
  }

  roleField.addEventListener('change', applyRoleState);
  applyRoleState();
})();
</script>
