<?php
/** @var array $libri */
/** @var array $utenti */
?>
<!-- Crea Prenotazione Admin -->
<div class="min-h-screen bg-gray-50 py-12">
    <div class="max-w-5xl mx-auto px-6 sm:px-8 lg:px-12">

        <!-- Header -->
        <div class="mb-12">


            <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-4xl font-bold text-gray-900 flex items-center"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>
                        <div class="w-16 h-16 bg-gray-900 rounded-2xl flex items-center justify-center mr-6">

                        </div><?= __("Crea Nuova Prenotazione") ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

                    <p class="text-lg text-gray-600 ml-22"><?= __("Registra una prenotazione per permettere ad un utente di riservare un libro specifico") ?></p>
                <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><a href="<?= htmlspecialchars(url('/admin/reservations'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center px-6 py-3 bg-white border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors shadow-sm">
                    <i class="fas fa-arrow-left mr-2"></i><?= __("Torna alle Prenotazioni") ?></a><?php
$workspaceHeader['back'] = (string) ob_get_clean();
ob_start();
?>


            <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
    <ol>
        <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a></li>
        <li><a href="<?= htmlspecialchars(url('/admin/reservations'), ENT_QUOTES, 'UTF-8') ?>"><?= __("Prenotazioni") ?></a></li>
        <li aria-current="page"><span><?= __("Crea Prenotazione") ?></span></li>
    </ol>
</nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>
        </div>

        <!-- Alert Messages -->
        <?php
        $error = $_GET['error'] ?? '';
        $success = $_GET['success'] ?? '';
        ?>

        <?php if ($error === 'csrf'): ?>
            <div class="mb-8 p-6 bg-red-50 border border-red-200 text-red-700 rounded-xl shadow-sm" role="alert">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold"><?= __("Errore di sicurezza") ?></h3>
                        <p class="text-sm mt-1"><?= __("Token di sicurezza non valido. Riprova.") ?></p>
                    </div>
                </div>
            </div>
        <?php elseif ($error === 'missing_data'): ?>
            <div class="mb-8 p-6 bg-red-50 border border-red-200 text-red-700 rounded-xl shadow-sm" role="alert">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold"><?= __("Dati mancanti") ?></h3>
                        <p class="text-sm mt-1"><?= __("Libro e utente sono campi obbligatori.") ?></p>
                    </div>
                </div>
            </div>
        <?php elseif ($error === 'save_failed'): ?>
            <div class="mb-8 p-6 bg-red-50 border border-red-200 text-red-700 rounded-xl shadow-sm" role="alert">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold"><?= __("Errore di salvataggio") ?></h3>
                        <p class="text-sm mt-1"><?= __("Si è verificato un errore durante il salvataggio della prenotazione.") ?></p>
                    </div>
                </div>
            </div>
        <?php elseif (in_array($error, ['not_loanable', 'reservations_disabled', 'reservation_limit_reached'], true)): ?>
            <div class="mb-8 p-6 bg-red-50 border border-red-200 text-red-700 rounded-xl shadow-sm" role="alert" data-testid="reservation-policy-error">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold"><?= __("Regole di circolazione del lettore") ?></h3>
                        <p class="text-sm mt-1"><?= htmlspecialchars(\App\Support\LoanEligibility::errorMessage($error), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($error !== 'not_loanable'): ?>
                        <p class="text-sm mt-1"><?= __("Per confermare comunque la prenotazione, spunta «Ignora i limiti di prenotazione» e salva di nuovo.") ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($error === 'capacity_full'): ?>
            <div class="mb-8 p-6 bg-red-50 border border-red-200 text-red-700 rounded-xl shadow-sm" role="alert">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold"><?= __("Nessuna disponibilità nel periodo") ?></h3>
                        <p class="text-sm mt-1"><?= htmlspecialchars(\App\Support\LoanWindow::explainFromParams($_GET) ?? __("Tutte le copie sono già impegnate (prestiti o prenotazioni) per il periodo richiesto. Scegli un altro periodo."), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
        <?php elseif (in_array($error, ['user_not_found', 'user_suspended', 'card_expired', 'debt_block'], true)): ?>
            <div class="mb-8 p-6 bg-red-50 border border-red-200 text-red-700 rounded-xl shadow-sm" role="alert">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold"><?= __("Utente non idoneo al prestito") ?></h3>
                        <p class="text-sm mt-1"><?= htmlspecialchars(\App\Support\LoanEligibility::errorMessage($error), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-lg">

            <!-- Form Header -->
            <div class="p-10 border-b border-gray-200">
                <h2 class="text-3xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-edit text-gray-600 mr-4 text-2xl"></i><?= __("Dati della Prenotazione") ?></h2>
                <p class="text-lg text-gray-600 mt-4"><?= __("Compila tutti i campi per creare una nuova prenotazione") ?></p>
            </div>

            <div class="p-10">
                <form method="POST" action="<?= htmlspecialchars(url('/admin/reservations/create'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-12">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">

                    <!-- Main Fields Section -->
                    <div class="space-y-12">

                        <!-- Selezione Libro -->
                        <div class="bg-blue-50 rounded-2xl p-8 space-y-6 border border-blue-100">
                            <div class="flex items-center space-x-4 mb-6">
                                <div class="w-14 h-14 bg-blue-100 rounded-2xl flex items-center justify-center">
                                    <i class="fas fa-book text-blue-600 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-gray-900"><?= __("Seleziona Libro") ?></h3>
                                    <p class="text-lg text-gray-600 mt-1"><?= __("Scegli il libro da prenotare dal catalogo") ?></p>
                                </div>
                            </div>
                            <div>
                                <label for="libro_id" class="block text-lg font-bold text-gray-700 mb-4">
                                    Libro da prenotare *
                                </label>
                                <select name="libro_id" id="libro_id" required aria-required="true"
                                        class="w-full px-6 py-5 text-xl border-2 border-gray-300 rounded-2xl focus:ring-4 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-lg transition-all">
                                    <option value=""><?= __("Seleziona un libro dalla lista...") ?></option>
                                    <?php foreach ($libri as $libro): ?>
                                        <option value="<?php echo (int)$libro['id']; ?>">
                                            <?php echo App\Support\HtmlHelper::e($libro['titolo']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Selezione Utente -->
                        <div class="bg-green-50 rounded-2xl p-8 space-y-6 border border-green-100">
                            <div class="flex items-center space-x-4 mb-6">
                                <div class="w-14 h-14 bg-green-100 rounded-2xl flex items-center justify-center">
                                    <i class="fas fa-user text-green-600 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-gray-900"><?= __("Seleziona Utente") ?></h3>
                                    <p class="text-lg text-gray-600 mt-1"><?= __("Scegli l\'utente che effettua la prenotazione") ?></p>
                                </div>
                            </div>
                            <div>
                                <label for="utente_id" class="block text-lg font-bold text-gray-700 mb-4">
                                    Utente prenotante *
                                </label>
                                <select name="utente_id" id="utente_id" required aria-required="true"
                                        class="w-full px-6 py-5 text-xl border-2 border-gray-300 rounded-2xl focus:ring-4 focus:ring-green-500 focus:border-green-500 bg-white shadow-lg transition-all">
                                    <option value=""><?= __("Seleziona un utente dalla lista...") ?></option>
                                    <?php foreach ($utenti as $utente): ?>
                                        <option value="<?php echo (int)$utente['id']; ?>">
                                            <?php echo App\Support\HtmlHelper::e($utente['nome_completo']); ?>
                                            — <?php echo App\Support\HtmlHelper::e($utente['email']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Date Settings -->
                        <div class="bg-purple-50 rounded-2xl p-8 space-y-8 border border-purple-100">
                            <div class="flex items-center space-x-4 mb-6">
                                <div class="w-14 h-14 bg-purple-100 rounded-2xl flex items-center justify-center">
                                    <i class="fas fa-calendar-alt text-purple-600 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-gray-900"><?= __("Impostazioni Date") ?></h3>
                                    <p class="text-lg text-gray-600 mt-1"><?= __("Configura le date della prenotazione") ?></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Data Prenotazione -->
                                <div>
                                    <label for="data_prenotazione" class="block text-lg font-bold text-gray-700 mb-4"><?= __("Data Prenotazione") ?></label>
                                    <input type="date" name="data_prenotazione" id="data_prenotazione"
                                           value="<?php echo htmlspecialchars(\App\Support\DateHelper::today(), ENT_QUOTES, 'UTF-8'); ?>"
                                           min="<?php echo htmlspecialchars(\App\Support\DateHelper::today(), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="w-full px-6 py-5 text-xl border-2 border-gray-300 rounded-2xl focus:ring-4 focus:ring-purple-500 focus:border-purple-500 bg-white shadow-lg transition-all">
                                    <p class="text-lg text-gray-500 mt-3"><?= __("Data di inizio della prenotazione (default: oggi)") ?></p>
                                </div>

                                <!-- Data Scadenza -->
                                <div>
                                    <label for="data_scadenza" class="block text-lg font-bold text-gray-700 mb-4"><?= __("Data Scadenza") ?></label>
                                    <input type="date" name="data_scadenza" id="data_scadenza"
                                           value="<?php echo htmlspecialchars(date('Y-m-d', strtotime(\App\Support\DateHelper::today() . ' +' . (int)($defaultLoanDays ?? 30) . ' days')), ENT_QUOTES, 'UTF-8'); ?>"
                                           min="<?php echo htmlspecialchars(\App\Support\DateHelper::today(), ENT_QUOTES, 'UTF-8'); ?>"
                                           class="w-full px-6 py-5 text-xl border-2 border-gray-300 rounded-2xl focus:ring-4 focus:ring-purple-500 focus:border-purple-500 bg-white shadow-lg transition-all">
                                    <p class="text-lg text-gray-500 mt-3"><?= sprintf(__('Data di scadenza della prenotazione (default: +%d giorni)'), (int)($defaultLoanDays ?? 30)) ?></p>
                                    <p id="data_scadenza_hint" class="text-lg text-amber-700 mt-3 hidden" role="status"></p>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Info Note -->
                    <div class="bg-blue-50 rounded-2xl p-8 border-2 border-blue-200">
                        <div class="flex items-start space-x-6">
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center shrink-0 mt-2">
                                <i class="fas fa-info-circle text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h4 class="text-2xl font-bold text-blue-900 mb-6"><?= __("Informazioni Importanti") ?></h4>
                                <div class="text-lg text-blue-800 space-y-4">
                                    <div class="flex items-start space-x-3">
                                        <i class="fas fa-check-circle text-blue-600 mt-1 text-lg"></i>
                                        <span><?= __("La posizione in coda sarà calcolata automaticamente in base alle prenotazioni esistenti") ?></span>
                                    </div>
                                    <div class="flex items-start space-x-3">
                                        <i class="fas fa-check-circle text-blue-600 mt-1 text-lg"></i>
                                        <span><?= __("Lo stato della prenotazione sarà impostato automaticamente come \"attiva\"") ?></span>
                                    </div>
                                    <div class="flex items-start space-x-3">
                                        <i class="fas fa-check-circle text-blue-600 mt-1 text-lg"></i>
                                        <span><?= __("L\'utente riceverà una notifica via email della prenotazione creata") ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (\App\Support\Features::enabled('circulation_rules')): // patron type limits exist only with the module on ?>
                    <label class="flex items-center gap-3 text-lg text-gray-700">
                        <input type="checkbox" name="force_limits" value="1" id="force_limits" class="rounded border-gray-300">
                        <?= __("Ignora i limiti di prenotazione della tipologia del lettore") ?>
                    </label>
                    <?php endif; ?>

                    <!-- Buttons -->
                    <div class="pk-form-actions flex items-center justify-between pt-12 border-t-2 border-gray-200">
                        <a href="<?= htmlspecialchars(url('/admin/reservations'), ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center px-8 py-4 text-lg border-2 border-gray-300 text-gray-700 rounded-2xl hover:bg-gray-50 transition-colors font-semibold shadow-md">
                            <i class="fas fa-times mr-3"></i>Annulla
                        </a>
                        <button type="submit"
                                class="inline-flex items-center px-12 py-4 text-lg bg-gray-900 text-white rounded-2xl hover:bg-gray-800 transition-colors font-semibold shadow-xl">
                            <i class="fas fa-save mr-3"></i><?= __("Crea Prenotazione") ?></button>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
  // The same values the other calendars use: the library's clock (not the
  // browser's), the configured duration, and the selected title's per-day
  // availability for the chosen borrower, so the proposed deadline stops the
  // day before a scheduled loan or another reservation instead of being
  // refused by the store (capacity_full) after the fact.
  const API_BOOK_BASE = <?= json_encode(route_path('api_book'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  const appToday = <?= json_encode(\App\Support\DateHelper::today(), JSON_HEX_TAG) ?>;
  const defaultLoanDays = <?= (int) ($defaultLoanDays ?? 30) ?>;
  const i18n = {
    loanLimit: <?= json_encode(__('Durata massima del prestito: %1$d giorni (restituzione entro il %2$s). Dal %3$s il libro è già prenotato.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    loanLimitOne: <?= json_encode(__('Durata massima del prestito: 1 giorno (restituzione entro il %1$s). Dal %2$s il libro è già prenotato.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    noWindow: <?= json_encode(__('Da questa data di inizio non è possibile alcun prestito: dal %s il libro è già prenotato. Scegli un\'altra data.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
  };
  const isEnglish = <?= json_encode(str_starts_with(\App\Support\I18n::getLocale(), 'en'), JSON_HEX_TAG) ?>;
  const startEl = document.getElementById('data_prenotazione');
  const endEl = document.getElementById('data_scadenza');
  const bookEl = document.getElementById('libro_id');
  const userEl = document.getElementById('utente_id');
  const hintEl = document.getElementById('data_scadenza_hint');
  let availabilityByDate = {};
  let availabilityRequestId = 0;
  // The deadline this code last proposed on its own: a value the operator typed
  // is kept, one proposed here follows the new subject.
  let autoProposedEnd = null;

  function addDaysToIso(isoDate, days) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate || '');
    if (!match) return isoDate;
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  }
  function daysBetweenIso(fromIso, toIso) {
    const parse = (iso) => {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
      return m ? Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : NaN;
    };
    const a = parse(fromIso); const b = parse(toIso);
    return (Number.isNaN(a) || Number.isNaN(b)) ? NaN : Math.round((b - a) / 86400000);
  }
  function formatDisplayDate(iso) {
    const parts = (iso || '').split('-');
    if (parts.length !== 3) return iso;
    return isEnglish ? `${parts[1]}-${parts[2]}-${parts[0]}` : `${parts[2]}-${parts[1]}-${parts[0]}`;
  }
  // Same rule as the public calendar and the server's LoanWindow.
  function loanWindowFrom(startIso) {
    let firstBlocked = null;
    for (let i = 1; i <= defaultLoanDays; i++) {
      const day = addDaysToIso(startIso, i);
      const info = availabilityByDate[day];
      if (info && info.state !== 'free') { firstBlocked = day; break; }
    }
    if (firstBlocked === null) {
      return { endDate: addDaysToIso(startIso, defaultLoanDays), maxDays: defaultLoanDays, firstBlocked: null, capped: false };
    }
    const endDate = addDaysToIso(firstBlocked, -1);
    return { endDate, maxDays: daysBetweenIso(startIso, endDate), firstBlocked, capped: true };
  }
  function applyLoanWindow(onlyShorten) {
    const startIso = startEl.value;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(startIso)) return;
    endEl.min = startIso;
    const loanWindow = loanWindowFrom(startIso);
    const current = endEl.value || '';
    const typedByOperator = current !== '' && current !== autoProposedEnd;
    if (!onlyShorten || !typedByOperator || current < startIso || (loanWindow.capped && current > loanWindow.endDate)) {
      endEl.value = loanWindow.endDate;
      autoProposedEnd = loanWindow.endDate;
    }
    if (!hintEl) return;
    let text = '';
    if (loanWindow.capped && loanWindow.maxDays < 1) {
      text = i18n.noWindow.replace('%s', formatDisplayDate(loanWindow.firstBlocked));
    } else if (loanWindow.capped && loanWindow.maxDays === 1) {
      text = i18n.loanLimitOne
        .replace('%1$s', formatDisplayDate(loanWindow.endDate))
        .replace('%2$s', formatDisplayDate(loanWindow.firstBlocked));
    } else if (loanWindow.capped) {
      text = i18n.loanLimit
        .replace('%1$d', String(loanWindow.maxDays))
        .replace('%2$s', formatDisplayDate(loanWindow.endDate))
        .replace('%3$s', formatDisplayDate(loanWindow.firstBlocked));
    }
    hintEl.textContent = text;
    hintEl.classList.toggle('hidden', text === '');
  }
  function loadAvailability() {
    const bookId = parseInt(bookEl ? bookEl.value : '', 10);
    availabilityByDate = {};
    const requestId = ++availabilityRequestId;
    if (!bookId || bookId <= 0) { applyLoanWindow(true); return; }
    // The reader's own reservations must not block their own request.
    const userId = parseInt(userEl ? userEl.value : '', 10);
    const url = API_BOOK_BASE + '/' + bookId + '/availability' + (userId > 0 ? '?for_user=' + userId : '');
    fetch(url, { credentials: 'same-origin' })
      .then((r) => r.ok ? r.json() : null)
      .then((data) => {
        if (requestId !== availabilityRequestId) return;
        if (data && data.success && data.availability) {
          (data.availability.days || []).forEach((day) => { if (day && day.date) availabilityByDate[day.date] = day; });
        }
        applyLoanWindow(true);
      })
      .catch(() => { if (requestId === availabilityRequestId) applyLoanWindow(true); });
  }

  startEl.min = appToday;
  endEl.min = appToday;
  autoProposedEnd = endEl.value || null;
  startEl.addEventListener('change', () => applyLoanWindow(false));
  if (bookEl) bookEl.addEventListener('change', loadAvailability);
  if (userEl) userEl.addEventListener('change', loadAvailability);
  loadAvailability();
})();
</script>
