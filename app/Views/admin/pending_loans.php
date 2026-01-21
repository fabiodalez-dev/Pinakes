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
    <div class="p-6 space-y-8">

        <!-- Section: Pickups Ready (Da Ritirare) -->
        <?php if (!empty($pickupLoans)): ?>
        <div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 bg-gradient-to-br from-orange-500 to-amber-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-box text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Ritiri da Confermare") ?></h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Prestiti pronti per il ritiro") ?></p>
                </div>
                <span class="ml-auto bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300 text-sm font-medium px-3 py-1 rounded-full">
                    <?= count($pickupLoans) ?>
                </span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($pickupLoans as $loan): ?>
                    <?php
                    $isExpired = !empty($loan['pickup_deadline']) && $loan['pickup_deadline'] < date('Y-m-d');
                    $isExpiringSoon = !empty($loan['pickup_deadline']) && $loan['pickup_deadline'] === date('Y-m-d');
                    ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border <?= $isExpired ? 'border-red-300 dark:border-red-700' : ($isExpiringSoon ? 'border-amber-300 dark:border-amber-700' : 'border-gray-200 dark:border-gray-700') ?> overflow-hidden hover:shadow-md transition-shadow" data-pickup-card>
                        <div class="p-6">
                            <div class="flex gap-4">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars($loan['copertina_url'] ?: '/assets/images/book-placeholder.jpg') ?>"
                                         class="w-20 h-28 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($loan['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white mb-2 line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                        <i class="fas fa-box text-[10px]"></i>
                                        <?= __("Da Ritirare") ?>
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
                                        <?php if (!empty($loan['pickup_deadline'])): ?>
                                        <p class="<?= $isExpired ? 'text-red-600 dark:text-red-400' : ($isExpiringSoon ? 'text-amber-600 dark:text-amber-400' : 'text-gray-600 dark:text-gray-400') ?> flex items-center">
                                            <i class="fas fa-hourglass-half w-4 text-center mr-2"></i>
                                            <span class="font-medium"><?= __("Scadenza ritiro:") ?></span>
                                            <span class="ml-2"><?= format_date($loan['pickup_deadline'], false, '/') ?></span>
                                            <?php if ($isExpired): ?>
                                                <span class="ml-2 text-xs bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 px-2 py-0.5 rounded"><?= __("Scaduto") ?></span>
                                            <?php elseif ($isExpiringSoon): ?>
                                                <span class="ml-2 text-xs bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 px-2 py-0.5 rounded"><?= __("Oggi") ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php endif; ?>
                                        <div class="space-y-2 mt-2">
                                            <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                                <i class="fas fa-play w-4 text-center mr-2 text-green-500"></i>
                                                <span class="font-medium"><?= __("Inizio:") ?></span>
                                                <span class="ml-2"><?= format_date($loan['data_richiesta_inizio'], false, '/') ?></span>
                                            </p>
                                            <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                                <i class="fas fa-stop w-4 text-center mr-2 text-red-500"></i>
                                                <span class="font-medium"><?= __("Fine:") ?></span>
                                                <span class="ml-2"><?= format_date($loan['data_richiesta_fine'], false, '/') ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <?php if ($isExpired): ?>
                                <button type="button"
                                        class="w-full bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-4 rounded-lg transition-colors shadow-sm cancel-pickup-btn"
                                        data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-times mr-2"></i><?= __("Annulla Prestito Scaduto") ?>
                                </button>
                                <?php else: ?>
                                <button type="button"
                                        class="w-full bg-green-600 hover:bg-green-500 text-white font-medium py-2 px-4 rounded-lg transition-colors confirm-pickup-btn shadow-sm"
                                        data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-check-circle mr-2"></i><?= __("Conferma Ritiro") ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="px-6 py-3 bg-amber-50 dark:bg-amber-900/20 border-t border-amber-200 dark:border-amber-800">
                            <p class="text-xs text-amber-700 dark:text-amber-300 flex items-center">
                                <i class="fas fa-info-circle mr-2"></i>
                                <?= __("Approvato il %s", format_date($loan['updated_at'] ?? $loan['created_at'], true, '/')) ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section: Pending Approval -->
        <div>
            <?php if (!empty($pickupLoans)): ?>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-hourglass-start text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Richieste in Attesa") ?></h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Da approvare o rifiutare") ?></p>
                </div>
                <span class="ml-auto bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 text-sm font-medium px-3 py-1 rounded-full">
                    <?= count($pendingLoans) ?>
                </span>
            </div>
            <?php endif; ?>

            <?php if (empty($pendingLoans) && empty($pickupLoans)): ?>
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-center">
                    <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/40 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2"><?= __("Nessuna richiesta in attesa") ?></h3>
                    <p class="text-blue-600 dark:text-blue-400"><?= __("Non ci sono richieste di prestito in attesa di approvazione.") ?></p>
                </div>
            <?php elseif (empty($pendingLoans)): ?>
                <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-6 text-center">
                    <div class="w-16 h-16 bg-green-100 dark:bg-green-900/40 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-green-900 dark:text-green-100 mb-2"><?= __("Nessuna richiesta da approvare") ?></h3>
                    <p class="text-green-600 dark:text-green-400"><?= __("Tutte le richieste sono state gestite.") ?></p>
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
                                                    <span class="ml-2"><?= format_date($loan['data_richiesta_inizio'], false, '/') ?></span>
                                                </p>
                                                <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                                    <i class="fas fa-stop w-4 text-center mr-2 text-red-500"></i>
                                                    <span class="font-medium"><?= __("Fine:") ?></span>
                                                    <span class="ml-2"><?= format_date($loan['data_richiesta_fine'], false, '/') ?></span>
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
                                    <?= __("Richiesta del %s", format_date($loan['created_at'], true, '/')) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../partials/loan-actions-swal.php';
unset($loanActionTranslations);
?>
