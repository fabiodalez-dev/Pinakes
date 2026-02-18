<?php
/** @var int $pendingCount */
/** @var array $pendingReviews */
/** @var array $approvedReviews */
/** @var array $rejectedReviews */
use App\Support\HtmlHelper;
?>

<!-- Main Content Area -->
<div class="flex-1 overflow-x-hidden">
    <!-- Content -->
    <div class="p-6">
        <div class="mb-8 fade-in">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-star text-yellow-600 mr-3"></i>
                        <?= __("Gestione Recensioni") ?>
                    </h1>
                    <p class="text-sm text-gray-600 mt-1"><?= __("Approva o rifiuta le recensioni degli utenti") ?></p>
                </div>
                <?php if ($pendingCount > 0): ?>
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm font-semibold" role="alert">
                    <i class="fas fa-clock"></i>
                    <?php echo $pendingCount; ?> <?= __("in attesa") ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Recensioni in Attesa -->
        <?php if (!empty($pendingReviews)): ?>
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-clock text-yellow-600 mr-2"></i>
                <?= __("In Attesa di Approvazione") ?>
            </h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($pendingReviews as $review): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow" data-review-card>
                    <div class="p-6">
                        <div class="flex gap-4">
                            <div class="flex-shrink-0">
                                <?php if (!empty($review['libro_copertina'])): ?>
                                <img src="<?php echo HtmlHelper::e($review['libro_copertina']); ?>"
                                     class="w-20 h-28 object-cover rounded-lg shadow-sm"
                                     alt="<?php echo HtmlHelper::e($review['libro_titolo']); ?>">
                                <?php else: ?>
                                <div class="w-20 h-28 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-book text-gray-400 text-2xl"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-gray-900 dark:text-white mb-2 line-clamp-2"><?php echo HtmlHelper::e($review['libro_titolo']); ?></h3>

                                <!-- Star Rating -->
                                <div class="flex items-center gap-1 mb-3">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="<?php echo $i <= $review['stelle'] ? 'fas' : 'far'; ?> fa-star text-yellow-500"></i>
                                    <?php endfor; ?>
                                    <span class="text-sm text-gray-600 dark:text-gray-400 ml-1">(<?php echo (int)$review['stelle']; ?>/5)</span>
                                </div>

                                <div class="space-y-1 text-sm">
                                    <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                        <i class="fas fa-user w-4 text-center mr-2 text-blue-500"></i>
                                        <?php echo HtmlHelper::e($review['utente_nome']); ?>
                                    </p>
                                    <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                        <i class="fas fa-envelope w-4 text-center mr-2 text-green-500"></i>
                                        <?php echo HtmlHelper::e($review['utente_email']); ?>
                                    </p>
                                </div>

                                <?php if (!empty($review['titolo'])): ?>
                                <div class="mt-2 text-sm font-medium text-gray-900 dark:text-white">
                                    "<?php echo HtmlHelper::e($review['titolo']); ?>"
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($review['descrizione'])): ?>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400 line-clamp-3">
                                    <?php echo nl2br(HtmlHelper::e($review['descrizione'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-4 flex gap-3">
                            <button type="button"
                                    class="flex-1 bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors approve-btn shadow-sm"
                                    data-review-id="<?php echo (int)$review['id']; ?>">
                                <i class="fas fa-check mr-2"></i><?= __("Approva") ?>
                            </button>
                            <button type="button"
                                    class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-4 rounded-lg transition-colors reject-btn shadow-sm"
                                    data-review-id="<?php echo (int)$review['id']; ?>">
                                <i class="fas fa-times mr-2"></i><?= __("Rifiuta") ?>
                            </button>
                        </div>
                    </div>
                    <div class="px-6 py-3 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
                        <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center">
                            <i class="fas fa-clock mr-2"></i>
                            <?= __("Recensione del") ?> <?php echo format_date($review['created_at'], true); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-center mb-8">
            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/40 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2"><?= __("Nessuna recensione in attesa") ?></h3>
            <p class="text-blue-600 dark:text-blue-400"><?= __("Non ci sono recensioni in attesa di approvazione.") ?></p>
        </div>
        <?php endif; ?>

        <!-- Recensioni Approvate (Collapsible) -->
        <div class="mb-6">
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden">
                <button onclick="toggleSection('approved')"
                        class="w-full px-4 py-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-check-circle text-green-600"></i>
                        <span class="font-semibold text-gray-900 dark:text-white"><?= __("Recensioni Approvate") ?></span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                            <?php echo count($approvedReviews); ?>
                        </span>
                    </div>
                    <i class="fas fa-chevron-down transition-transform" id="approved-icon"></i>
                </button>
                <div id="approved-section" class="hidden border-t border-gray-200 dark:border-gray-700">
                    <div class="p-4">
                        <?php if (empty($approvedReviews)): ?>
                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4"><?= __("Nessuna recensione approvata") ?></p>
                        <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($approvedReviews as $review): ?>
                            <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-3" data-review-card>
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0">
                                        <?php if (!empty($review['libro_copertina'])): ?>
                                        <img src="<?php echo HtmlHelper::e($review['libro_copertina']); ?>"
                                             class="w-12 h-18 object-cover rounded-lg"
                                             alt="<?php echo HtmlHelper::e($review['libro_titolo']); ?>">
                                        <?php else: ?>
                                        <div class="w-12 h-18 bg-gray-200 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-book text-gray-400 text-xs"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo HtmlHelper::e($review['libro_titolo']); ?>
                                        </h4>
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="<?php echo $i <= $review['stelle'] ? 'fas' : 'far'; ?> fa-star text-yellow-500 text-xs"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            <?php echo HtmlHelper::e($review['utente_nome']); ?>
                                            <span class="mx-1">•</span>
                                            <?= __("Approvata il") ?> <?php echo format_date($review['approved_at'], false, '/'); ?>
                                        </div>
                                        <?php if (!empty($review['titolo'])): ?>
                                        <div class="text-xs font-medium text-gray-800 dark:text-gray-200 mt-1">
                                            "<?php echo HtmlHelper::e($review['titolo']); ?>"
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="delete-btn text-red-600 hover:text-red-800 p-1" data-review-id="<?php echo (int)$review['id']; ?>" title="<?= __("Elimina") ?>" aria-label="<?= __("Elimina recensione") ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recensioni Rifiutate (Collapsible) -->
        <div class="mb-6">
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden">
                <button onclick="toggleSection('rejected')"
                        class="w-full px-4 py-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-times-circle text-red-600"></i>
                        <span class="font-semibold text-gray-900 dark:text-white"><?= __("Recensioni Rifiutate") ?></span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                            <?php echo count($rejectedReviews); ?>
                        </span>
                    </div>
                    <i class="fas fa-chevron-down transition-transform" id="rejected-icon"></i>
                </button>
                <div id="rejected-section" class="hidden border-t border-gray-200 dark:border-gray-700">
                    <div class="p-4">
                        <?php if (empty($rejectedReviews)): ?>
                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4"><?= __("Nessuna recensione rifiutata") ?></p>
                        <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($rejectedReviews as $review): ?>
                            <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-3" data-review-card>
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0">
                                        <?php if (!empty($review['libro_copertina'])): ?>
                                        <img src="<?php echo HtmlHelper::e($review['libro_copertina']); ?>"
                                             class="w-12 h-18 object-cover rounded-lg"
                                             alt="<?php echo HtmlHelper::e($review['libro_titolo']); ?>">
                                        <?php else: ?>
                                        <div class="w-12 h-18 bg-gray-200 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-book text-gray-400 text-xs"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo HtmlHelper::e($review['libro_titolo']); ?>
                                        </h4>
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="<?php echo $i <= $review['stelle'] ? 'fas' : 'far'; ?> fa-star text-yellow-500 text-xs"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            <?php echo HtmlHelper::e($review['utente_nome']); ?>
                                            <span class="mx-1">•</span>
                                            <?= __("Rifiutata il") ?> <?php echo format_date($review['approved_at'], false, '/'); ?>
                                        </div>
                                        <?php if (!empty($review['titolo'])): ?>
                                        <div class="text-xs font-medium text-gray-800 dark:text-gray-200 mt-1">
                                            "<?php echo HtmlHelper::e($review['titolo']); ?>"
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="delete-btn text-red-600 hover:text-red-800 p-1" data-review-id="<?php echo (int)$review['id']; ?>" title="<?= __("Elimina") ?>" aria-label="<?= __("Elimina recensione") ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSection(section) {
    const sectionEl = document.getElementById(section + '-section');
    const iconEl = document.getElementById(section + '-icon');
    sectionEl.classList.toggle('hidden');
    iconEl.classList.toggle('rotate-180');
}

(function() {
    // Pre-translated strings from PHP for proper i18n support
    const i18n = {
        conferma: <?= json_encode(__('Conferma')) ?>,
        annulla: <?= json_encode(__('Annulla')) ?>,
        confermiOperazione: <?= json_encode(__("Confermi l'operazione?")) ?>,
        operazioneCompletata: <?= json_encode(__('Operazione completata')) ?>,
        errore: <?= json_encode(__('Errore')) ?>,
        operazioneNonRiuscita: <?= json_encode(__('Operazione non riuscita')) ?>,
        erroreComunicazione: <?= json_encode(__('errore di comunicazione con il server')) ?>,
        // Approve
        recensioneApprovata: <?= json_encode(__('Recensione approvata')) ?>,
        recensioneApprovataText: <?= json_encode(__('La recensione è stata approvata e pubblicata con successo.')) ?>,
        approvaRecensione: <?= json_encode(__('Approva recensione')) ?>,
        approvaRecensioneText: <?= json_encode(__('Vuoi approvare questa recensione e renderla visibile sul sito?')) ?>,
        approva: <?= json_encode(__('Approva')) ?>,
        impossibileApprovare: <?= json_encode(__('Impossibile approvare la recensione')) ?>,
        // Reject
        recensioneRifiutata: <?= json_encode(__('Recensione rifiutata')) ?>,
        recensioneRifiutataText: <?= json_encode(__('La recensione è stata rifiutata e non sarà pubblicata.')) ?>,
        rifiutaRecensione: <?= json_encode(__('Rifiuta recensione')) ?>,
        rifiutaRecensioneText: <?= json_encode(__("Vuoi rifiutare questa recensione? L'utente verrà avvisato dell'esito.")) ?>,
        rifiuta: <?= json_encode(__('Rifiuta')) ?>,
        impossibileRifiutare: <?= json_encode(__('Impossibile rifiutare la recensione')) ?>,
        // Delete
        recensioneEliminata: <?= json_encode(__('Recensione eliminata')) ?>,
        recensioneEliminataText: <?= json_encode(__('La recensione è stata eliminata definitivamente.')) ?>,
        eliminaRecensione: <?= json_encode(__('Elimina recensione')) ?>,
        eliminaRecensioneText: <?= json_encode(__('Vuoi eliminare definitivamente questa recensione? Questa azione non può essere annullata.')) ?>,
        elimina: <?= json_encode(__('Elimina')) ?>,
        impossibileEliminare: <?= json_encode(__('Impossibile eliminare la recensione')) ?>
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const confirmAction = async (options) => {
        if (typeof Swal !== 'undefined') {
            const result = await Swal.fire({
                title: options.title,
                text: options.text,
                icon: options.icon || 'warning',
                showCancelButton: true,
                confirmButtonText: options.confirmText || i18n.conferma,
                cancelButtonText: options.cancelText || i18n.annulla,
                confirmButtonColor: '#111827',
                cancelButtonColor: '#6b7280'
            });
            return result.isConfirmed;
        }

        return window.confirm(options.text || options.title || i18n.confermiOperazione);
    };

    const showFeedback = (icon, title, text) => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon,
                title,
                text,
                confirmButtonColor: '#111827'
            });
        } else {
            window.alert(text || title || i18n.operazioneCompletata);
        }
    };

    const handleAction = (btn, { endpoint, method, successMessage, confirmTitle, confirmText, confirmButton, errorPrefix }) => {
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';

        btn.addEventListener('click', async function() {
            const reviewId = this.dataset.reviewId;
            const confirmed = await confirmAction({
                title: confirmTitle,
                text: confirmText,
                confirmText: confirmButton
            });

            if (!confirmed) {
                return;
            }

            // Disable button during request to prevent double-clicks
            this.disabled = true;

            try {
                const basePath = window.BASE_PATH || '';
                const url = endpoint
                    ? `${basePath}/admin/recensioni/${reviewId}/${endpoint}`
                    : `${basePath}/admin/recensioni/${reviewId}`;
                const response = await fetch(url, {
                    method: method || 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    }
                });

                const result = await response.json();

                if (result.success) {
                    const card = this.closest('[data-review-card]');
                    if (card) {
                        card.remove();
                    }

                    showFeedback('success', successMessage.title, successMessage.text);

                    if (!document.querySelector('.approve-btn')) {
                        location.reload();
                    }
                } else {
                    this.disabled = false; // Re-enable on error so user can retry
                    showFeedback('error', i18n.errore, `${errorPrefix}: ${result.message || i18n.operazioneNonRiuscita}`);
                }
            } catch (error) {
                this.disabled = false; // Re-enable on error so user can retry
                console.error('Review action error:', error);
                showFeedback('error', i18n.errore, `${errorPrefix}: ${i18n.erroreComunicazione}`);
            }
        });
    };

    const bindReviewActions = (context = document) => {
        context.querySelectorAll('.approve-btn').forEach(btn => {
            handleAction(btn, {
                endpoint: 'approve',
                successMessage: {
                    title: i18n.recensioneApprovata,
                    text: i18n.recensioneApprovataText
                },
                confirmTitle: i18n.approvaRecensione,
                confirmText: i18n.approvaRecensioneText,
                confirmButton: i18n.approva,
                errorPrefix: i18n.impossibileApprovare
            });
        });

        context.querySelectorAll('.reject-btn').forEach(btn => {
            handleAction(btn, {
                endpoint: 'reject',
                successMessage: {
                    title: i18n.recensioneRifiutata,
                    text: i18n.recensioneRifiutataText
                },
                confirmTitle: i18n.rifiutaRecensione,
                confirmText: i18n.rifiutaRecensioneText,
                confirmButton: i18n.rifiuta,
                errorPrefix: i18n.impossibileRifiutare
            });
        });

        // Delete button handler - uses handleAction with DELETE method and no endpoint suffix
        context.querySelectorAll('.delete-btn').forEach(btn => {
            handleAction(btn, {
                endpoint: '',
                method: 'DELETE',
                successMessage: {
                    title: i18n.recensioneEliminata,
                    text: i18n.recensioneEliminataText
                },
                confirmTitle: i18n.eliminaRecensione,
                confirmText: i18n.eliminaRecensioneText,
                confirmButton: i18n.elimina,
                errorPrefix: i18n.impossibileEliminare
            });
        });
    };

    if (!window.__reviewActionsInit) {
        window.__reviewActionsInit = bindReviewActions;
    }

    const run = () => {
        if (typeof window.__reviewActionsInit === 'function') {
            window.__reviewActionsInit();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();
</script>
