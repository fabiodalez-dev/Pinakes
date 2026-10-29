<?php
use App\Support\HtmlHelper;

// Badge canonico degli stati prestito (#333): colore/icona/etichetta arrivano
// dal partial condiviso, niente mappa locale da tenere allineata.
require_once __DIR__ . '/../partials/loan-status-badge.php';

$id = (int)($utente['id'] ?? 0);
$name = trim(($utente['nome'] ?? '') . ' ' . ($utente['cognome'] ?? ''));
$email = $utente['email'] ?? '';
$telefono = $utente['telefono'] ?? '';
$indirizzo = $utente['indirizzo'] ?? '';
$dataNascita = $utente['data_nascita'] ?? '';
$sesso = $utente['sesso'] ?? '';
$cod_fiscale = $utente['cod_fiscale'] ?? '';
$codiceTessera = $utente['codice_tessera'] ?? '';
$dataScadenzaTessera = $utente['data_scadenza_tessera'] ?? '';
$dataUltimoAccesso = $utente['data_ultimo_accesso'] ?? '';
$stato = $utente['stato'] ?? 'attivo';
$ruolo = $utente['tipo_utente'] ?? 'standard';
$creatoIl = $utente['created_at'] ?? '';
$aggiornatoIl = $utente['updated_at'] ?? '';
$note = $utente['note_utente'] ?? '';
$photoRaw = (string) ($utente['foto_profilo_url'] ?? '');
$photoStored = \App\Support\PatronPhoto::absolutePath($photoRaw) !== null ? $photoRaw : null;
$photoUrl = $photoStored !== null ? url('/admin/users/' . $id . '/photo') . '?v=' . substr(sha1($photoStored), 0, 10) : null;

$statusLabels = [
    'attivo' => __('Attivo'),
    'sospeso' => __('Sospeso'),
    'scaduto' => __('Scaduto')
];

$roleLabels = [
    'admin' => __('Amministratore'),
    'staff' => __('Staff'),
    'premium' => __('Premium'),
    'standard' => __('Standard')
];

$genderLabels = [
    'M' => __('Maschio'),
    'F' => __('Femmina'),
    'Altro' => __('Altro'),
];

$sessoKey = trim((string)$sesso);
$sessoLabel = $genderLabels[$sessoKey] ?? $sessoKey;

$display = static function (?string $value, string $placeholder = '—'): string {
    $value = trim((string)$value);
    return $value !== ''
        ? htmlspecialchars(HtmlHelper::decode($value), ENT_QUOTES, 'UTF-8')
        : $placeholder;
};
?>

<div class="max-w-7xl py-6 mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
  <?php
  // Success and error messages
  $success = $_GET['success'] ?? '';
  $error = $_GET['error'] ?? '';
  ?>

  <?php if ($success === 'approved_email_sent'): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-start gap-3" role="alert">
      <i class="fas fa-envelope text-blue-600 mt-0.5"></i>
      <div>
        <p class="font-medium text-blue-900"><?= __("Utente approvato con successo!") ?></p>
        <p class="text-sm text-blue-700 mt-1"><?= __("L'email di attivazione è stata inviata. L'utente potrà verificare il proprio account cliccando il link ricevuto (valido 7 giorni).") ?></p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($success === 'activated_directly'): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-4 flex items-start gap-3" role="alert">
      <i class="fas fa-check-circle text-green-600 mt-0.5"></i>
      <div>
        <p class="font-medium text-green-900"><?= __("Utente attivato direttamente!") ?></p>
        <p class="text-sm text-green-700 mt-1"><?= __("L'utente è stato attivato e può già effettuare il login. È stata inviata un'email di benvenuto.") ?></p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($error === 'user_not_found'): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 flex items-start gap-3" role="alert">
      <i class="fas fa-exclamation-circle text-red-600 mt-0.5"></i>
      <div>
        <p class="font-medium text-red-900"><?= __("Errore: Utente non trovato") ?></p>
        <p class="text-sm text-red-700 mt-1"><?= __("L'utente richiesto non esiste nel database.") ?></p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($error === 'not_suspended'): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-start gap-3">
      <i class="fas fa-info-circle text-amber-600 mt-0.5"></i>
      <div>
        <p class="font-medium text-amber-900"><?= __("Operazione non consentita") ?></p>
        <p class="text-sm text-amber-700 mt-1"><?= __("L'utente non è in stato sospeso. Solo gli utenti sospesi richiedono approvazione.") ?></p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($error === 'db_error'): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 flex items-start gap-3" role="alert">
      <i class="fas fa-exclamation-triangle text-red-600 mt-0.5"></i>
      <div>
        <p class="font-medium text-red-900"><?= __("Errore del database") ?></p>
        <p class="text-sm text-red-700 mt-1"><?= __("Si è verificato un errore durante l'operazione. Riprova più tardi.") ?></p>
      </div>
    </div>
  <?php endif; ?>

  <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title mt-2 text-2xl font-bold text-gray-900"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= $display($name, __("Utente senza nome")); ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?><?= $display($name, __("Utente senza nome")); ?>

    <div class="flex items-center gap-3 shrink-0">
        <?php if (\App\Support\Features::enabled('patron_cards')): ?>
        <a href="<?= htmlspecialchars(url('/admin/users/' . $id . '/card-pdf'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" id="print-card" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
            <i class="fas fa-id-card mr-2"></i>
            <?= __("Stampa tessera") ?>
        </a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(url('/admin/loans/create?utente_id=' . $id), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
            <i class="fas fa-handshake mr-2"></i>
            <?= __("Nuovo Prestito") ?>
        </a>
        <a href="<?= htmlspecialchars(url('/admin/users/edit/' . $id), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-pencil-alt mr-2"></i>
            <?= __("Modifica Utente") ?>
        </a>
    </div>
  <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><a href="<?= htmlspecialchars(url('/admin/users'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700">
        <i class="fas fa-arrow-left mr-2"></i> <?= __("Torna alla lista") ?>
      </a><?php
$workspaceHeader['back'] = (string) ob_get_clean();
ob_start();
?>


      <p class="text-sm text-gray-500"><?= __("Ruolo:") ?> <?= $display($roleLabels[$ruolo] ?? ucfirst($ruolo)); ?></p>
    <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

  <?php if (\App\Support\Features::enabled('patron_photos')): // photos are independent of printing ?>
  <section class="bg-white border border-gray-200 rounded-lg p-6" id="patron-card-section">
    <h2 class="text-lg font-medium text-gray-900"><?= __("Foto e tessera") ?></h2>
    <div class="mt-4 flex flex-col sm:flex-row gap-6">
      <div class="shrink-0">
        <?php if ($photoUrl !== null): ?>
          <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(__('Foto di %s', $name), ENT_QUOTES, 'UTF-8') ?>" id="patron-photo" class="w-32 h-40 object-cover rounded-lg border border-gray-200">
        <?php else: ?>
          <div id="patron-photo-empty" class="w-32 h-40 rounded-lg border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center text-gray-400">
            <i class="fas fa-user text-3xl" aria-hidden="true"></i>
            <span class="sr-only"><?= __("Nessuna foto") ?></span>
          </div>
        <?php endif; ?>
      </div>
      <div class="space-y-3 text-sm text-gray-600">
        <p><?= __("La foto compare sulla tessera stampata. È un dato personale: è visibile solo al personale e può essere eliminata in qualsiasi momento.") ?></p>
        <div class="flex flex-wrap items-center gap-3">
          <?php if (\App\Support\Features::enabled('patron_cards')): ?>
          <a href="<?= htmlspecialchars(url('/admin/users/' . $id . '/card-pdf?format=single'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn-secondary">
            <i class="fas fa-id-card mr-2"></i><?= __("Tessera singola (CR80)") ?>
          </a>
          <a href="<?= htmlspecialchars(url('/admin/users/' . $id . '/card-pdf?format=sheet'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn-secondary">
            <i class="fas fa-th mr-2"></i><?= __("Foglio A4") ?>
          </a>
          <?php endif; ?>
          <a href="<?= htmlspecialchars(url('/admin/users/edit/' . $id) . '#patron-photo-widget', ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
            <i class="fas fa-camera mr-2"></i><?= $photoUrl !== null ? __("Cambia foto") : __("Aggiungi foto") ?>
          </a>
          <?php if ($photoUrl !== null): ?>
          <form method="POST" action="<?= htmlspecialchars(url('/admin/users/' . $id . '/photo/delete'), ENT_QUOTES, 'UTF-8') ?>" id="delete-photo-form"
                data-swal-confirm="<?= htmlspecialchars(__('La foto verrà eliminata definitivamente. Continuare?'), ENT_QUOTES, 'UTF-8') ?>"
                data-swal-confirm-title="<?= htmlspecialchars(__('Elimina foto'), ENT_QUOTES, 'UTF-8') ?>"
                data-swal-confirm-button="<?= htmlspecialchars(__('Elimina foto'), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn-danger-outline px-4 py-2 rounded-lg inline-flex items-center">
              <i class="fas fa-trash mr-2"></i><?= __("Elimina foto") ?>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="bg-white border border-gray-200 rounded-lg p-6">
    <h2 class="text-lg font-medium text-gray-900"><?= __("Informazioni Personali") ?></h2>
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
      <div>
        <dt class="text-sm text-gray-500"><?= __("Nome completo") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= $display($name); ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Email") ?></dt>
        <dd class="text-sm text-gray-900 mt-1">
          <?php if ($email !== ''): ?>
            <a href="mailto:<?= HtmlHelper::e($email); ?>" class="text-blue-600 hover:underline"><?= HtmlHelper::e($email); ?></a>
          <?php else: ?>
            —
          <?php endif; ?>
        </dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Telefono") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= $display($telefono); ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Data di nascita") ?></dt>
        <dd class="text-sm text-gray-900 mt-1">
          <?= !empty($dataNascita) ? format_date($dataNascita, false, '/') : '—'; ?>
        </dd>
      </div>
      <?php if (\App\Support\RegistrationFields::isVisible('sesso')): ?>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Sesso") ?></dt>
        <dd class="text-sm text-gray-900 mt-1" data-field="sesso"><?= $display($sessoLabel); ?></dd>
      </div>
      <?php endif; ?>
      <?php if (\App\Support\RegistrationFields::isVisible('cod_fiscale')): ?>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Codice Fiscale") ?></dt>
        <dd class="text-sm text-gray-900 mt-1 font-mono"><?= $display($cod_fiscale); ?></dd>
      </div>
      <?php endif; ?>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Indirizzo") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= $display($indirizzo); ?></dd>
      </div>
      <?php foreach (($customFieldValues ?? []) as $cfv): ?>
        <div>
          <dt class="text-sm text-gray-500"><?= htmlspecialchars($cfv['etichetta'], ENT_QUOTES, 'UTF-8') ?></dt>
          <dd class="text-sm text-gray-900 mt-1 break-words">
            <?= $cfv['tipo'] === 'checkbox' ? __('Sì') : htmlspecialchars($cfv['valore'], ENT_QUOTES, 'UTF-8') ?>
          </dd>
        </div>
      <?php endforeach; ?>
    </dl>
  </section>

  <section class="bg-white border border-gray-200 rounded-lg p-6">
    <h2 class="text-lg font-medium text-gray-900"><?= __("Dati Account") ?></h2>
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
      <div>
        <dt class="text-sm text-gray-500"><?= __("Stato") ?></dt>
        <dd class="mt-1 text-sm text-gray-900"><?= HtmlHelper::e($statusLabels[$stato] ?? ucfirst($stato)); ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Codice Tessera") ?></dt>
        <dd class="text-sm text-gray-900 mt-1 font-mono"><?= $display($codiceTessera); ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Registrato il") ?></dt>
        <dd class="text-sm text-gray-900 mt-1">
          <?= !empty($creatoIl) ? format_date($creatoIl, true, '/') : '—'; ?>
        </dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Ultimo aggiornamento") ?></dt>
        <dd class="text-sm text-gray-900 mt-1">
          <?= !empty($aggiornatoIl) ? format_date($aggiornatoIl, true, '/') : '—'; ?>
        </dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Scadenza tessera") ?></dt>
        <dd class="text-sm text-gray-900 mt-1">
          <?= !empty($dataScadenzaTessera) ? format_date($dataScadenzaTessera, false, '/') : '—'; ?>
        </dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Ultimo accesso") ?></dt>
        <dd class="text-sm text-gray-900 mt-1">
          <?= !empty($dataUltimoAccesso) ? format_date($dataUltimoAccesso, true, '/') : '—'; ?>
        </dd>
      </div>
      <?php if (\App\Support\Features::enabled('circulation_rules')): // patron types module ?>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Tipologia lettore") ?></dt>
        <dd class="text-sm text-gray-900 mt-1" data-testid="patron-type-name">
          <?= htmlspecialchars((string) ($patronTypeName ?? __('Standard')), ENT_QUOTES, 'UTF-8'); ?>
          <?php if (empty($patronTypeIsOwn)): ?><span class="text-xs text-gray-500">(<?= __("predefinita") ?>)</span><?php endif; ?>
        </dd>
      </div>
      <?php endif; ?>
    </dl>
  </section>

  <?php if (isset($patronPolicy) && in_array($ruolo, ['standard', 'premium'], true)): ?>
  <section class="bg-white border border-gray-200 rounded-lg p-6" data-testid="patron-effective-rules">
    <h2 class="text-lg font-medium text-gray-900"><?= __("Regole di circolazione") ?></h2>
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
      <div>
        <dt class="text-sm text-gray-500"><?= __("Prestiti attivi massimi") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= $patronPolicy->maxActiveLoans > 0 ? (int) $patronPolicy->maxActiveLoans : __('Nessun limite') ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Durata prestito (giorni)") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= (int) $patronPolicy->durationDays ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Durata massima richiedibile (giorni)") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= (int) $patronPolicy->maxRequestDays ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Rinnovi massimi") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= (int) $patronPolicy->maxRenewals ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Prenotazioni") ?></dt>
        <dd class="text-sm text-gray-900 mt-1">
          <?= !$patronPolicy->user->reservationsEnabled
              ? __('Prenotazioni disattivate')
              : ($patronPolicy->user->maxReservations > 0 ? sprintf(__('Massimo %d'), $patronPolicy->user->maxReservations) : __('Nessun limite')) ?>
        </dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Durata tessera (giorni)") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= $patronPolicy->user->cardDurationDays !== null ? (int) $patronPolicy->user->cardDurationDays : '—' ?></dd>
      </div>
    </dl>
    <p class="text-xs text-gray-500 mt-3"><?= __("Le regole per tipo di materiale (Impostazioni → Circolazione) possono ridurre questi valori per singoli materiali.") ?></p>
    <form method="POST" action="<?= htmlspecialchars(url('/admin/users/' . $id . '/renew-card'), ENT_QUOTES, 'UTF-8') ?>" class="mt-4"
          data-swal-confirm="<?= htmlspecialchars(sprintf(__('Rinnovare la tessera fino al %s?'), format_date($cardRenewalPreview ?? \App\Support\PatronTypeAssignment::todayPlus($patronPolicy->user->cardDurationDays ?? \App\Support\PatronTypeAssignment::DEFAULT_RENEWAL_DAYS), false, '/')), ENT_QUOTES, 'UTF-8') ?>"
          data-swal-confirm-title="<?= htmlspecialchars(__('Rinnova tessera'), ENT_QUOTES, 'UTF-8') ?>"
          data-swal-confirm-button="<?= htmlspecialchars(__('Rinnova tessera'), ENT_QUOTES, 'UTF-8') ?>"
          data-swal-confirm-kind="action">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <button type="submit" data-testid="renew-card" class="px-4 py-3 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center gap-2 border border-gray-800">
        <i class="fas fa-id-card"></i>
        <span class="font-medium"><?= __("Rinnova tessera") ?></span>
      </button>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($stato === 'sospeso'): ?>
  <!-- Approval Actions -->
  <section class="bg-amber-50 border border-amber-200 rounded-lg p-6">
    <div class="flex items-center gap-2 mb-4">
      <i class="fas fa-user-clock text-amber-600"></i>
      <h2 class="text-lg font-medium text-gray-900"><?= __("Azioni di Approvazione") ?></h2>
    </div>

    <div class="mb-4 p-4 bg-white border border-amber-200 rounded-lg">
      <p class="text-sm text-gray-700">
        <i class="fas fa-info-circle text-amber-600 mr-2"></i>
        <?= __("Questo utente è in stato <strong>sospeso</strong> e richiede approvazione. Scegli un'opzione:") ?>
      </p>
    </div>

    <div class="flex flex-col sm:flex-row gap-3">
      <form method="POST" action="<?= htmlspecialchars(url('/admin/users/' . $id . '/approve-and-send-activation'), ENT_QUOTES, 'UTF-8') ?>" class="flex-1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="w-full px-4 py-3 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center gap-2 border border-gray-800">
          <i class="fas fa-envelope"></i>
          <span class="font-medium"><?= __("Approva e Invia Email Attivazione") ?></span>
        </button>
        <p class="text-xs text-gray-600 mt-2">
          <?= __("L'utente riceverà un'email con link di verifica (valido 7 giorni) e potrà attivare autonomamente l'account.") ?>
        </p>
      </form>

      <form method="POST" action="<?= htmlspecialchars(url('/admin/users/' . $id . '/activate-directly'), ENT_QUOTES, 'UTF-8') ?>" class="flex-1"
            data-swal-confirm="<?= htmlspecialchars(__('Confermi di voler attivare direttamente questo utente senza richiedere verifica email?'), ENT_QUOTES, 'UTF-8') ?>"
            data-swal-confirm-title="<?= htmlspecialchars(__('Conferma attivazione'), ENT_QUOTES, 'UTF-8') ?>"
            data-swal-confirm-button="<?= htmlspecialchars(__('Attiva utente'), ENT_QUOTES, 'UTF-8') ?>"
            data-swal-confirm-kind="action">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="w-full px-4 py-3 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center gap-2 border border-gray-800">
          <i class="fas fa-user-check"></i>
          <span class="font-medium"><?= __("Attiva Direttamente") ?></span>
        </button>
        <p class="text-xs text-gray-600 mt-2">
          <?= __("L'utente sarà attivato immediatamente e riceverà un'email di benvenuto. Potrà accedere subito.") ?>
        </p>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <!-- Loan History -->
  <div class="bg-white shadow-sm rounded-2xl border border-slate-200">
    <div class="px-6 py-4 border-b border-slate-200">
        <h2 class="text-lg font-semibold text-gray-800"><?= __("Storico Prestiti") ?></h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left font-medium"><?= __("Libro") ?></th>
                    <th scope="col" class="px-6 py-3 text-left font-medium"><?= __("Periodo") ?></th>
                    <th scope="col" class="px-6 py-3 text-center font-medium"><?= __("Stato") ?></th>
                    <th scope="col" class="px-6 py-3 text-right font-medium"><?= __("Azioni") ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php if (empty($prestiti)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-10 text-gray-500">
                            <i class="fas fa-book-reader fa-2x mb-2"></i>
                            <p><?= __("Questo utente non ha mai effettuato prestiti.") ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($prestiti as $prestito): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-semibold text-gray-900"><?= HtmlHelper::e($prestito['libro_titolo'] ?? 'N/D'); ?></div>
                                <div class="text-gray-500"><?= __("ID Prestito:") ?> <?= $prestito['id']; ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-700">
                                <div>
                                    <span class="font-semibold"><?= __("Dal:") ?></span> <?= format_date($prestito['data_prestito'], false, '/') ?>
                                </div>
                                <div>
                                    <span class="font-semibold"><?= __("Al:") ?></span> <?= format_date(!empty($prestito['data_restituzione']) ? $prestito['data_restituzione'] : $prestito['data_scadenza'], false, '/') ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <?= loan_status_badge($prestito['stato'] ?? null); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <a href="<?= htmlspecialchars(url('/admin/loans/details/' . (int)$prestito['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn-icon btn-secondary p-2 text-gray-500 hover:bg-gray-200 rounded-full transition-colors" title="Dettagli Prestito" aria-label="Dettagli Prestito">
                                    <i class="fas fa-eye w-4 h-4"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
  </div>

  <?php if (!empty($ledgerEnabled)) { require __DIR__ . '/_movimenti.php'; } ?>

  <?php if ($note !== ''): ?>
    <section class="bg-white border border-gray-200 rounded-lg p-6 mt-6">
      <h2 class="text-lg font-medium text-gray-900"><?= __("Note interne") ?></h2>
      <p class="mt-3 text-sm text-gray-900 whitespace-pre-line"><?= nl2br(HtmlHelper::e($note)); ?></p>
    </section>
  <?php endif; ?>

</div>
