<!-- Main Content Area -->
<div class="flex-1 overflow-x-hidden">
    <!-- Page Header -->
    <div class="bg-white/50 backdrop-blur-sm border-b border-gray-200/80 dark:bg-gray-900/50 dark:border-gray-800/80 sticky top-0 z-30">
        <div class="px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-amber-500 to-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-white text-sm"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Richieste di Prestito</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Approva o rifiuta le richieste degli utenti</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="p-6">

        <?php if (empty($pendingLoans)): ?>
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-center">
                <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/40 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 text-2xl"></i>
                </div>
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2">Nessuna richiesta in attesa</h3>
                <p class="text-blue-600 dark:text-blue-400">Non ci sono richieste di prestito in attesa di approvazione.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($pendingLoans as $loan): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow" data-loan-card>
                        <div class="p-6">
                            <div class="flex gap-4">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars($loan['copertina_url'] ?: '/assets/images/book-placeholder.jpg') ?>"
                                         class="w-20 h-28 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($loan['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white mb-3 line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <div class="space-y-1 text-sm">
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-user w-4 text-center mr-2 text-blue-500"></i>
                                            <?= htmlspecialchars($loan['utente_nome']) ?>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-envelope w-4 text-center mr-2 text-green-500"></i>
                                            <?= htmlspecialchars($loan['email']) ?>
                                        </p>
                                        <div class="space-y-2">
                                            <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                                <i class="fas fa-play w-4 text-center mr-2 text-green-500"></i>
                                                <span class="font-medium">Inizio:</span>
                                                <span class="ml-2"><?= date('d-m-Y', strtotime($loan['data_richiesta_inizio'])) ?></span>
                                            </p>
                                            <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                                <i class="fas fa-stop w-4 text-center mr-2 text-red-500"></i>
                                                <span class="font-medium">Fine:</span>
                                                <span class="ml-2"><?= date('d-m-Y', strtotime($loan['data_richiesta_fine'])) ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 flex gap-3">
                                <button type="button"
                                        class="flex-1 bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors approve-btn shadow-sm"
                                        data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-check mr-2"></i>Approva
                                </button>
                                <button type="button"
                                        class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-4 rounded-lg transition-colors reject-btn shadow-sm"
                                        data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-times mr-2"></i>Rifiuta
                                </button>
                            </div>
                        </div>
                        <div class="px-6 py-3 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
                            <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center">
                                <i class="fas fa-clock mr-2"></i>
                                Richiesta del <?= date('d-m-Y H:i', strtotime($loan['created_at'])) ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const bindLoanActions = (context = document) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        context.querySelectorAll('.approve-btn').forEach(btn => {
            if (btn.dataset.bound === '1') {
                return;
            }
            btn.dataset.bound = '1';
            btn.addEventListener('click', async function() {
                const loanId = this.dataset.loanId;

                if (!confirm(__('Sei sicuro di voler approvare questo prestito?'))) {
                    return;
                }

                try {
                    const response = await fetch('/admin/loans/approve', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify({ loan_id: parseInt(loanId, 10) })
                    });

                    const result = await response.json();

                    if (result.success) {
                        const card = this.closest('[data-loan-card]');
                        if (card) {
                            card.remove();
                        }

                        if (!document.querySelector('.approve-btn')) {
                            location.reload();
                        }
                    } else {
                        alert('Errore: ' + result.message);
                    }
                } catch (error) {
                    alert(__('Errore nella comunicazione con il server'));
                }
            });
        });

        context.querySelectorAll('.reject-btn').forEach(btn => {
            if (btn.dataset.bound === '1') {
                return;
            }
            btn.dataset.bound = '1';
            btn.addEventListener('click', async function() {
                const loanId = this.dataset.loanId;
                const reason = prompt('Motivo del rifiuto (opzionale):');
                if (reason === null) {
                    return;
                }

                try {
                    const response = await fetch('/admin/loans/reject', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify({
                            loan_id: parseInt(loanId, 10),
                            reason: reason
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        const card = this.closest('[data-loan-card]');
                        if (card) {
                            card.remove();
                        }

                        if (!document.querySelector('.approve-btn')) {
                            location.reload();
                        }
                    } else {
                        alert('Errore: ' + result.message);
                    }
                } catch (error) {
                    alert(__('Errore nella comunicazione con il server'));
                }
            });
        });
    };

    if (!window.__loanActionsInit) {
        window.__loanActionsInit = bindLoanActions;
    }

    const run = () => {
        if (typeof window.__loanActionsInit === 'function') {
            window.__loanActionsInit();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();
</script>
