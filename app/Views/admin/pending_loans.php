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
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= __("Richieste di Prestito") ?></h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Approva o rifiuta le richieste degli utenti") ?></p>
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
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2"><?= __("Nessuna richiesta in attesa") ?></h3>
                <p class="text-blue-600 dark:text-blue-400"><?= __("Non ci sono richieste di prestito in attesa di approvazione.") ?></p>
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
                                    <h3 class="font-semibold text-gray-900 dark:text-white mb-2 line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <?php
                                    $origine = $loan['origine'] ?? 'richiesta';
                                    $origineBadge = match($origine) {
                                        'prenotazione' => ['bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300', 'fa-calendar-check', __('Da prenotazione')],
                                        'diretto' => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300', 'fa-hand-holding', __('Prestito diretto')],
                                        default => ['bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300', 'fa-paper-plane', __('Richiesta manuale')],
                                    };
                                    ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 <?= $origineBadge[0] ?>">
                                        <i class="fas <?= $origineBadge[1] ?> text-[10px]"></i>
                                        <?= $origineBadge[2] ?>
                                    </span>
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
                                                <span class="font-medium"><?= __("Inizio:") ?></span>
                                                <span class="ml-2"><?= format_date($loan['data_richiesta_inizio']) ?></span>
                                            </p>
                                            <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                                <i class="fas fa-stop w-4 text-center mr-2 text-red-500"></i>
                                                <span class="font-medium"><?= __("Fine:") ?></span>
                                                <span class="ml-2"><?= format_date($loan['data_richiesta_fine']) ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 flex gap-3">
                                <button type="button"
                                        class="flex-1 bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors approve-btn shadow-sm"
                                        data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-check mr-2"></i><?= __("Approva") ?>
                                </button>
                                <button type="button"
                                        class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-4 rounded-lg transition-colors reject-btn shadow-sm"
                                        data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-times mr-2"></i><?= __("Rifiuta") ?>
                                </button>
                            </div>
                        </div>
                        <div class="px-6 py-3 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
                            <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center">
                                <i class="fas fa-clock mr-2"></i>
                                <?= __("Richiesta del %s", format_date($loan['created_at'], true)) ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require __DIR__ . '/../partials/loan-actions-swal.php';
unset($loanActionTranslations);
?>
