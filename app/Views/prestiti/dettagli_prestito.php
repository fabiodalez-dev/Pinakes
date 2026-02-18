<?php
/** @var array $prestito */
$prestito = $prestito ?? [];
// Helper function to generate a human-readable status string
function formatLoanStatus($status) {
    return match ($status) {
        'pendente' => __('In Attesa di Approvazione'),
        'prenotato' => __('Prenotato'),
        'da_ritirare' => __('Da Ritirare'),
        'in_corso' => __('In Corso'),
        'in_ritardo' => __('In Ritardo'),
        'restituito' => __('Restituito'),
        'perso' => __('Perso'),
        'danneggiato' => __('Danneggiato'),
        default => __('Sconosciuto'),
    };
}
?>
<section class="space-y-4 p-6">
  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-2">
    <ol class="flex items-center space-x-2 text-sm">
      <li>
        <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
          <i class="fas fa-home mr-1"></i><?= __("Home") ?>
        </a>
      </li>
      <li>
        <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
      </li>
      <li>
        <a href="<?= htmlspecialchars(url('/admin/prestiti'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
          <i class="fas fa-handshake mr-1"></i><?= __("Prestiti") ?></a>
      </li>
      <li>
        <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
      </li>
      <li class="text-gray-900 font-medium"><?= __("Dettagli") ?></li>
    </ol>
  </nav>
  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold"><?= __("Dettagli del Prestito") ?></h1>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
      <div>
        <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2"><?= __("Informazioni Prestito") ?></h3>
        <div class="space-y-3">
          <div>
            <span class="font-semibold text-gray-600"><?= __("ID Prestito:") ?></span>
            <span class="text-gray-800"><?= App\Support\HtmlHelper::e($prestito['id']); ?></span>
          </div>
          <div>
            <span class="font-semibold text-gray-600"><?= __("Libro:") ?></span>
            <a href="<?= htmlspecialchars(url('/admin/libri/modifica/' . (int)($prestito['libro_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="text-blue-600 underline hover:text-blue-800 transition-colors">
              <?= App\Support\HtmlHelper::e($prestito['libro_titolo'] ?? __('Non disponibile')); ?>
            </a>
          </div>
          <div>
            <span class="font-semibold text-gray-600"><?= __("Utente:") ?></span>
            <span class="text-gray-800">
              <?= App\Support\HtmlHelper::e($prestito['utente_nome'] ?? __('Non disponibile')); ?>
              <?php if (!empty($prestito['utente_email'])): ?>
                <br><small class="text-gray-500"><?= App\Support\HtmlHelper::e($prestito['utente_email']); ?></small>
              <?php endif; ?>
            </span>
          </div>
        </div>
      </div>

      <div>
        <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2"><?= __("Date") ?></h3>
        <div class="space-y-3">
          <div>
            <span class="font-semibold text-gray-600"><?= __("Data Prestito:") ?></span>
            <span class="text-gray-800"><?= format_date($prestito['data_prestito'], false, '/'); ?></span>
          </div>
          <div>
            <span class="font-semibold text-gray-600"><?= __("Data Scadenza:") ?></span>
            <span class="text-gray-800"><?= format_date($prestito['data_scadenza'] ?? '', false, '/'); ?></span>
          </div>
          <div>
            <span class="font-semibold text-gray-600"><?= __("Data Restituzione:") ?></span>
            <span class="text-gray-800"><?= !empty($prestito['data_restituzione']) ? format_date($prestito['data_restituzione'], false, '/') : __("Non ancora restituito") ?></span>
          </div>
        </div>
      </div>

      <div>
        <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2"><?= __("Stato e Gestione") ?></h3>
        <div class="space-y-3">
          <div>
            <span class="font-semibold text-gray-600"><?= __("Stato:") ?></span>
            <span class="inline-block px-2 py-1 rounded text-sm <?php
              echo match($prestito['stato'] ?? '') {
                'pendente' => 'bg-orange-100 text-orange-800',
                'prenotato' => 'bg-purple-100 text-purple-800',
                'da_ritirare' => 'bg-amber-100 text-amber-800',
                'restituito' => 'bg-green-100 text-green-800',
                'in_corso' => 'bg-blue-100 text-blue-800',
                'in_ritardo' => 'bg-yellow-100 text-yellow-800',
                'perso', 'danneggiato' => 'bg-red-100 text-red-800',
                default => 'bg-gray-100 text-gray-800'
              };
            ?>"><?= App\Support\HtmlHelper::e(formatLoanStatus($prestito['stato'] ?? 'N/D')); ?></span>
          </div>
          <div>
            <span class="font-semibold text-gray-600"><?= __("Attivo:") ?></span>
            <span class="text-gray-800"><?= ((int)($prestito['attivo'] ?? 0)) ? __('Sì') : __('No'); ?></span>
          </div>
          <div>
            <span class="font-semibold text-gray-600"><?= __("Rinnovi Effettuati:") ?></span>
            <span class="text-gray-800"><?= App\Support\HtmlHelper::e($prestito['renewals'] ?? '0'); ?></span>
          </div>
        </div>
      </div>

    </div>

    <?php if (!empty($prestito['note'])): ?>
      <div class="mt-6 pt-4 border-t">
        <h3 class="text-lg font-semibold mb-2"><?= __("Note") ?></h3>
        <p class="text-gray-700 prose max-w-none"><?= nl2br(App\Support\HtmlHelper::e($prestito['note'])); ?></p>
      </div>
    <?php endif; ?>

    <div class="mt-8 pt-6 border-t border-gray-200 flex items-center gap-3">
      <?php if (($prestito['stato'] ?? '') === 'pendente'): ?>
        <button type="button" class="px-4 py-2 bg-gray-900 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center approve-btn" data-loan-id="<?= (int)$prestito['id']; ?>">
          <i class="fas fa-check mr-2"></i>
          <?= __("Approva") ?>
        </button>
        <button type="button" class="px-4 py-2 bg-red-600 text-white hover:bg-red-500 rounded-lg transition-colors duration-200 inline-flex items-center reject-btn" data-loan-id="<?= (int)$prestito['id']; ?>">
          <i class="fas fa-times mr-2"></i>
          <?= __("Rifiuta") ?>
        </button>
      <?php endif; ?>
      <?php if ((int)($prestito['attivo'] ?? 0) === 1 && ($prestito['stato'] ?? '') !== 'pendente'): ?>
        <a href="<?= htmlspecialchars(url('/admin/prestiti/restituito/' . (int)$prestito['id']), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-undo-alt mr-2"></i><?= __("Gestisci Restituzione") ?></a>
        <a href="<?= htmlspecialchars(url('/admin/prestiti/modifica/' . (int)$prestito['id']), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
            <i class="fas fa-pencil-alt mr-2"></i>
            <?= __("Modifica") ?>
        </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(url('/admin/prestiti/' . (int)$prestito['id'] . '/pdf'), ENT_QUOTES, 'UTF-8') ?>"
         class="px-4 py-2 bg-red-600 text-white hover:bg-red-500 rounded-lg transition-colors duration-200 inline-flex items-center">
          <i class="fas fa-file-pdf mr-2"></i>
          <?= __("Scarica Ricevuta PDF") ?>
      </a>
      <a href="<?= htmlspecialchars(url('/admin/prestiti'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-white text-gray-900 hover:bg-gray-100 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
        <i class="fas fa-arrow-left mr-2"></i><?= __("Torna ai Prestiti") ?></a>
    </div>
  </div>
</section>

<?php
$jsTranslationKeys = [
    'Approva prestito?',
    'Sei sicuro di voler approvare questa richiesta di prestito?',
    'Sì, approva',
    'Annulla',
    'Approvato!',
    'Il prestito è stato approvato con successo.',
    'OK',
    'Errore',
    'Errore durante l\'approvazione',
    'Errore nella comunicazione con il server',
    'Rifiuta prestito',
    'Motivo del rifiuto (opzionale)',
    'Inserisci il motivo del rifiuto...',
    'Rifiuta',
    'Rifiutato',
    'Il prestito è stato rifiutato.',
    'Errore durante il rifiuto'
];
$jsTranslations = [];
foreach ($jsTranslationKeys as $key) {
    $jsTranslations[$key] = __($key);
}
?>
<script>
(function() {
    const translations = <?= json_encode($jsTranslations, JSON_UNESCAPED_UNICODE); ?>;
    window.APP_TRANSLATIONS = Object.assign(window.APP_TRANSLATIONS || {}, translations);
    if (typeof window.__ !== 'function') {
        window.__ = function(key) {
            const dict = window.APP_TRANSLATIONS || translations;
            return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
        };
    }
})();
</script>
<script>
(function() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Approve button handler
    const approveBtn = document.querySelector('.approve-btn');
    if (approveBtn) {
        approveBtn.addEventListener('click', async function() {
            const loanId = this.dataset.loanId;

            const result = await Swal.fire({
                title: __('Approva prestito?'),
                text: __('Sei sicuro di voler approvare questa richiesta di prestito?'),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: __('Sì, approva'),
                cancelButtonText: __('Annulla'),
                confirmButtonColor: '#111827',
                cancelButtonColor: '#6b7280'
            });

            if (!result.isConfirmed) {
                return;
            }

            try {
                const response = await fetch(window.BASE_PATH + '/admin/loans/approve', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({ loan_id: parseInt(loanId, 10) })
                });

                const data = await response.json();

                if (data.success) {
                    await Swal.fire({
                        title: __('Approvato!'),
                        text: __('Il prestito è stato approvato con successo.'),
                        icon: 'success',
                        confirmButtonText: __('OK'),
                        confirmButtonColor: '#111827'
                    });
                    window.location.href = window.BASE_PATH + '/admin/prestiti';
                } else {
                    Swal.fire({
                        title: __('Errore'),
                        text: data.message || __('Errore durante l\'approvazione'),
                        icon: 'error',
                        confirmButtonText: __('OK'),
                        confirmButtonColor: '#111827'
                    });
                }
            } catch (error) {
                Swal.fire({
                    title: __('Errore'),
                    text: __('Errore nella comunicazione con il server'),
                    icon: 'error',
                    confirmButtonText: __('OK'),
                    confirmButtonColor: '#111827'
                });
            }
        });
    }

    // Reject button handler
    const rejectBtn = document.querySelector('.reject-btn');
    if (rejectBtn) {
        rejectBtn.addEventListener('click', async function() {
            const loanId = this.dataset.loanId;

            const { value: reason } = await Swal.fire({
                title: __('Rifiuta prestito'),
                input: 'textarea',
                inputLabel: __('Motivo del rifiuto (opzionale)'),
                inputPlaceholder: __('Inserisci il motivo del rifiuto...'),
                showCancelButton: true,
                confirmButtonText: __('Rifiuta'),
                cancelButtonText: __('Annulla'),
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                inputValidator: (value) => {
                    // Allow empty value (optional)
                    return null;
                }
            });

            if (reason === undefined) {
                // User cancelled
                return;
            }

            try {
                const response = await fetch(window.BASE_PATH + '/admin/loans/reject', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        loan_id: parseInt(loanId, 10),
                        reason: reason || ''
                    })
                });

                const data = await response.json();

                if (data.success) {
                    await Swal.fire({
                        title: __('Rifiutato'),
                        text: __('Il prestito è stato rifiutato.'),
                        icon: 'success',
                        confirmButtonText: __('OK'),
                        confirmButtonColor: '#111827'
                    });
                    window.location.href = window.BASE_PATH + '/admin/prestiti';
                } else {
                    Swal.fire({
                        title: __('Errore'),
                        text: data.message || __('Errore durante il rifiuto'),
                        icon: 'error',
                        confirmButtonText: __('OK'),
                        confirmButtonColor: '#111827'
                    });
                }
            } catch (error) {
                Swal.fire({
                    title: __('Errore'),
                    text: __('Errore nella comunicazione con il server'),
                    icon: 'error',
                    confirmButtonText: __('OK'),
                    confirmButtonColor: '#111827'
                });
            }
        });
    }
})();
</script>
