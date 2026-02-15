<?php
use App\Support\HtmlHelper;

// Helper function to generate status badges for the loan status
function getLoanStatusBadge($status) {
    $baseClasses = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium';
    switch ($status) {
        case 'pendente':
            return "<span class='$baseClasses bg-amber-100 text-amber-800'><i class='fas fa-hourglass-half mr-2'></i>" . __("In attesa") . "</span>";
        case 'prenotato':
            return "<span class='$baseClasses bg-purple-100 text-purple-800'><i class='fas fa-bookmark mr-2'></i>" . __("Prenotato") . "</span>";
        case 'in_corso':
            return "<span class='$baseClasses bg-blue-100 text-blue-800'><i class='fas fa-clock mr-2'></i>" . __("In Corso") . "</span>";
        case 'in_ritardo':
            return "<span class='$baseClasses bg-yellow-100 text-yellow-800'><i class='fas fa-exclamation-triangle mr-2'></i>" . __("In Ritardo") . "</span>";
        case 'restituito':
            return "<span class='$baseClasses bg-green-100 text-green-800'><i class='fas fa-check-circle mr-2'></i>" . __("Restituito") . "</span>";
        case 'perso':
            return "<span class='$baseClasses bg-red-100 text-red-800'><i class='fas fa-times-circle mr-2'></i>" . __("Perso") . "</span>";
        case 'danneggiato':
            return "<span class='$baseClasses bg-red-100 text-red-800'><i class='fas fa-times-circle mr-2'></i>" . __("Danneggiato") . "</span>";
        default:
            return "<span class='$baseClasses bg-gray-100 text-gray-800'><i class='fas fa-question-circle mr-2'></i>" . __("Sconosciuto") . "</span>";
    }
}

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

$display = static function (?string $value, string $placeholder = '—'): string {
    $value = trim((string)$value);
    return $value !== '' ? HtmlHelper::e($value) : $placeholder;
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

  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
      <a href="<?= url('/admin/utenti') ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700">
        <i class="fas fa-arrow-left mr-2"></i> <?= __("Torna alla lista") ?>
      </a>
      <h1 class="mt-2 text-2xl font-bold text-gray-900"><?= $display($name, __("Utente senza nome")); ?></h1>
      <p class="text-sm text-gray-500"><?= __("Ruolo:") ?> <?= $display($roleLabels[$ruolo] ?? ucfirst($ruolo)); ?></p>
    </div>
    <div class="flex items-center gap-3 flex-shrink-0">
        <a href="<?= url('/admin/prestiti/crea?utente_id=' . $id) ?>" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
            <i class="fas fa-handshake mr-2"></i>
            <?= __("Nuovo Prestito") ?>
        </a>
        <a href="<?= url('/admin/utenti/modifica/' . $id) ?>" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-pencil-alt mr-2"></i>
            <?= __("Modifica Utente") ?>
        </a>
    </div>
  </div>

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
      <div>
        <dt class="text-sm text-gray-500"><?= __("Sesso") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= $display($sesso); ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Codice Fiscale") ?></dt>
        <dd class="text-sm text-gray-900 mt-1 font-mono"><?= $display($cod_fiscale); ?></dd>
      </div>
      <div>
        <dt class="text-sm text-gray-500"><?= __("Indirizzo") ?></dt>
        <dd class="text-sm text-gray-900 mt-1"><?= $display($indirizzo); ?></dd>
      </div>
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
    </dl>
  </section>

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
      <form method="POST" action="<?= url('/admin/utenti/' . $id . '/approve-and-send-activation') ?>" class="flex-1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="w-full px-4 py-3 bg-gray-900 text-white hover:bg-blue-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center gap-2 border border-blue-700">
          <i class="fas fa-envelope"></i>
          <span class="font-medium"><?= __("Approva e Invia Email Attivazione") ?></span>
        </button>
        <p class="text-xs text-gray-600 mt-2">
          <?= __("L'utente riceverà un'email con link di verifica (valido 7 giorni) e potrà attivare autonomamente l'account.") ?>
        </p>
      </form>

      <form method="POST" action="<?= url('/admin/utenti/' . $id . '/activate-directly') ?>" class="flex-1" onsubmit="return confirm('<?= addslashes(__('Confermi di voler attivare direttamente questo utente senza richiedere verifica email?')) ?>')">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="w-full px-4 py-3 bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center gap-2 border border-green-700">
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
                                <?= getLoanStatusBadge($prestito['stato']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <a href="<?= url('/admin/prestiti/dettagli/' . (int)$prestito['id']) ?>" class="p-2 text-gray-500 hover:bg-gray-200 rounded-full transition-colors" title="Dettagli Prestito">
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

  <?php if ($note !== ''): ?>
    <section class="bg-white border border-gray-200 rounded-lg p-6 mt-6">
      <h2 class="text-lg font-medium text-gray-900"><?= __("Note interne") ?></h2>
      <p class="mt-3 text-sm text-gray-900 whitespace-pre-line"><?= nl2br(HtmlHelper::e($note)); ?></p>
    </section>
  <?php endif; ?>

</div>
