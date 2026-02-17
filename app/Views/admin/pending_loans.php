<!-- Main Content Area -->
<div class="flex-1 overflow-x-hidden">
    <!-- Page Header -->
    <div class="bg-white/50 backdrop-blur-sm border-b border-gray-200/80 dark:bg-gray-900/50 dark:border-gray-800/80 sticky top-0 z-30">
        <div class="px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-amber-500 to-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tasks text-white text-sm"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= __("Gestione Prestiti") ?></h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Panoramica completa di prestiti, ritiri e prenotazioni") ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            <?php
            // Explicit class mappings for Tailwind static scanner compatibility
            $colorClasses = [
                'red' => ['bg' => 'bg-red-100', 'dark_bg' => 'dark:bg-red-900/30', 'text' => 'text-red-600', 'dark_text' => 'dark:text-red-400'],
                'orange' => ['bg' => 'bg-orange-100', 'dark_bg' => 'dark:bg-orange-900/30', 'text' => 'text-orange-600', 'dark_text' => 'dark:text-orange-400'],
                'blue' => ['bg' => 'bg-blue-100', 'dark_bg' => 'dark:bg-blue-900/30', 'text' => 'text-blue-600', 'dark_text' => 'dark:text-blue-400'],
                'purple' => ['bg' => 'bg-purple-100', 'dark_bg' => 'dark:bg-purple-900/30', 'text' => 'text-purple-600', 'dark_text' => 'dark:text-purple-400'],
                'green' => ['bg' => 'bg-green-100', 'dark_bg' => 'dark:bg-green-900/30', 'text' => 'text-green-600', 'dark_text' => 'dark:text-green-400'],
                'indigo' => ['bg' => 'bg-indigo-100', 'dark_bg' => 'dark:bg-indigo-900/30', 'text' => 'text-indigo-600', 'dark_text' => 'dark:text-indigo-400'],
            ];
            $stats = [
                ['label' => __('In Ritardo'), 'count' => count($overdueLoans ?? []), 'color' => 'red', 'icon' => 'fa-exclamation-triangle'],
                ['label' => __('Da Ritirare'), 'count' => count($pickupLoans ?? []), 'color' => 'orange', 'icon' => 'fa-box'],
                ['label' => __('Da Approvare'), 'count' => count($pendingLoans ?? []), 'color' => 'blue', 'icon' => 'fa-hourglass-start'],
                ['label' => __('Programmati'), 'count' => count($scheduledLoans ?? []), 'color' => 'purple', 'icon' => 'fa-calendar-alt'],
                ['label' => __('In Corso'), 'count' => count($activeLoans ?? []), 'color' => 'green', 'icon' => 'fa-book-reader'],
                ['label' => __('Prenotazioni'), 'count' => count($activeReservations ?? []), 'color' => 'indigo', 'icon' => 'fa-bookmark'],
            ];
            foreach ($stats as $stat):
                $cc = $colorClasses[$stat['color']];
            ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700 flex items-center gap-3">
                <div class="w-10 h-10 <?= $cc['bg'] ?> <?= $cc['dark_bg'] ?> rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas <?= $stat['icon'] ?> <?= $cc['text'] ?> <?= $cc['dark_text'] ?>"></i>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stat['count'] ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?= $stat['label'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Content -->
    <div class="p-6 space-y-8">
        <?php $today = date('Y-m-d'); ?>

        <!-- Section 1: Overdue Loans (in_ritardo) - Most Urgent -->
        <?php if (!empty($overdueLoans)): ?>
        <div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 bg-gradient-to-br from-red-500 to-red-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Prestiti in Ritardo") ?></h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Richiedono attenzione immediata") ?></p>
                </div>
                <span class="ml-auto bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300 text-sm font-medium px-3 py-1 rounded-full">
                    <?= count($overdueLoans) ?>
                </span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($overdueLoans as $loan): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-red-300 dark:border-red-700 overflow-hidden hover:shadow-md transition-shadow">
                        <div class="p-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars(url($loan['copertina_url'] ?: '/uploads/copertine/placeholder.jpg'), ENT_QUOTES, 'UTF-8') ?>"
                                         class="w-16 h-22 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($loan['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mt-1 bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">
                                        <i class="fas fa-exclamation-circle text-[10px]"></i>
                                        <?= sprintf(__("%d giorni di ritardo"), $loan['giorni_ritardo']) ?>
                                    </span>
                                    <div class="space-y-1 text-xs mt-2">
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-user w-4 text-center mr-1 text-blue-500"></i>
                                            <?= htmlspecialchars($loan['utente_nome']) ?>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-calendar-times w-4 text-center mr-1 text-red-500"></i>
                                            <?= __("Scaduto:") ?> <?= format_date($loan['data_scadenza'], false, '/') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <a href="<?= htmlspecialchars(url('/admin/prestiti/modifica/' . $loan['id']), ENT_QUOTES, 'UTF-8') ?>" class="flex-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium py-2 px-3 rounded-lg transition-colors text-center text-sm">
                                    <i class="fas fa-edit mr-1"></i><?= __("Gestisci") ?>
                                </a>
                                <button type="button" class="flex-1 bg-green-600 hover:bg-green-500 text-white font-medium py-2 px-3 rounded-lg transition-colors return-btn text-sm" data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-undo mr-1"></i><?= __("Restituisci") ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 2: Pickups Ready (Da Ritirare) -->
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
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($pickupLoans as $loan): ?>
                    <?php
                    $isExpired = !empty($loan['pickup_deadline']) && $loan['pickup_deadline'] < $today;
                    $isExpiringSoon = !empty($loan['pickup_deadline']) && $loan['pickup_deadline'] === $today;
                    ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border <?= $isExpired ? 'border-red-300 dark:border-red-700' : ($isExpiringSoon ? 'border-amber-300 dark:border-amber-700' : 'border-gray-200 dark:border-gray-700') ?> overflow-hidden hover:shadow-md transition-shadow" data-pickup-card>
                        <div class="p-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars(url($loan['copertina_url'] ?: '/uploads/copertine/placeholder.jpg'), ENT_QUOTES, 'UTF-8') ?>"
                                         class="w-16 h-22 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($loan['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mt-1 bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                        <i class="fas fa-box text-[10px]"></i>
                                        <?= __("Da Ritirare") ?>
                                    </span>
                                    <div class="space-y-1 text-xs mt-2">
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-user w-4 text-center mr-1 text-blue-500"></i>
                                            <?= htmlspecialchars($loan['utente_nome']) ?>
                                        </p>
                                        <?php if (!empty($loan['pickup_deadline'])): ?>
                                        <p class="<?= $isExpired ? 'text-red-600 dark:text-red-400' : ($isExpiringSoon ? 'text-amber-600 dark:text-amber-400' : 'text-gray-600 dark:text-gray-400') ?> flex items-center">
                                            <i class="fas fa-hourglass-half w-4 text-center mr-1"></i>
                                            <?= __("Scadenza:") ?> <?= format_date($loan['pickup_deadline'], false, '/') ?>
                                            <?php if ($isExpired): ?>
                                                <span class="ml-1 text-[10px] bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 px-1.5 py-0.5 rounded"><?= __("Scaduto") ?></span>
                                            <?php elseif ($isExpiringSoon): ?>
                                                <span class="ml-1 text-[10px] bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 px-1.5 py-0.5 rounded"><?= __("Oggi") ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php endif; ?>
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-calendar w-4 text-center mr-1 text-green-500"></i>
                                            <?= format_date($loan['data_richiesta_inizio'], false, '/') ?> - <?= format_date($loan['data_richiesta_fine'], false, '/') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <?php if ($isExpired): ?>
                                <button type="button" class="w-full bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-3 rounded-lg transition-colors shadow-sm cancel-pickup-btn text-sm" data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-times mr-1"></i><?= __("Annulla Prestito Scaduto") ?>
                                </button>
                                <?php else: ?>
                                <button type="button" class="w-full bg-green-600 hover:bg-green-500 text-white font-medium py-2 px-3 rounded-lg transition-colors confirm-pickup-btn shadow-sm text-sm" data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-check-circle mr-1"></i><?= __("Conferma Ritiro") ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 3: Pending Approval -->
        <?php if (!empty($pendingLoans)): ?>
        <div>
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
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($pendingLoans as $loan): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow" data-loan-card>
                        <div class="p-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars(url($loan['copertina_url'] ?: '/uploads/copertine/placeholder.jpg'), ENT_QUOTES, 'UTF-8') ?>"
                                         class="w-16 h-22 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($loan['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <?php
                                    $origine = $loan['origine'] ?? 'richiesta';
                                    $origineBadge = match($origine) {
                                        'prenotazione' => ['bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300', 'fa-calendar-check', __('Da prenotazione')],
                                        'diretto' => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300', 'fa-hand-holding', __('Prestito diretto')],
                                        default => ['bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300', 'fa-paper-plane', __('Richiesta utente')],
                                    };
                                    ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mt-1 <?= $origineBadge[0] ?>">
                                        <i class="fas <?= $origineBadge[1] ?> text-[10px]"></i>
                                        <?= $origineBadge[2] ?>
                                    </span>
                                    <div class="space-y-1 text-xs mt-2">
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-user w-4 text-center mr-1 text-blue-500"></i>
                                            <?= htmlspecialchars($loan['utente_nome']) ?>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-calendar w-4 text-center mr-1 text-green-500"></i>
                                            <?= format_date($loan['data_richiesta_inizio'], false, '/') ?> - <?= format_date($loan['data_richiesta_fine'], false, '/') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <button type="button" class="flex-1 bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-3 rounded-lg transition-colors approve-btn shadow-sm text-sm" data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-check mr-1"></i><?= __("Approva") ?>
                                </button>
                                <button type="button" class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-3 rounded-lg transition-colors reject-btn shadow-sm text-sm" data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-times mr-1"></i><?= __("Rifiuta") ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 4: Scheduled Loans (Future) -->
        <?php if (!empty($scheduledLoans)): ?>
        <div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-violet-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Prestiti Programmati") ?></h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Approvati, iniziano in futuro") ?></p>
                </div>
                <span class="ml-auto bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300 text-sm font-medium px-3 py-1 rounded-full">
                    <?= count($scheduledLoans) ?>
                </span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($scheduledLoans as $loan): ?>
                    <?php
                    $daysUntil = (strtotime($loan['data_prestito']) - strtotime($today)) / 86400;
                    ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow">
                        <div class="p-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars(url($loan['copertina_url'] ?: '/uploads/copertine/placeholder.jpg'), ENT_QUOTES, 'UTF-8') ?>"
                                         class="w-16 h-22 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($loan['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mt-1 bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                                        <i class="fas fa-clock text-[10px]"></i>
                                        <?= sprintf(__("Tra %d giorni"), $daysUntil) ?>
                                    </span>
                                    <div class="space-y-1 text-xs mt-2">
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-user w-4 text-center mr-1 text-blue-500"></i>
                                            <?= htmlspecialchars($loan['utente_nome']) ?>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-calendar w-4 text-center mr-1 text-purple-500"></i>
                                            <?= format_date($loan['data_richiesta_inizio'], false, '/') ?> - <?= format_date($loan['data_richiesta_fine'], false, '/') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="<?= htmlspecialchars(url('/admin/prestiti/modifica/' . $loan['id']), ENT_QUOTES, 'UTF-8') ?>" class="block w-full bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium py-2 px-3 rounded-lg transition-colors text-center text-sm">
                                    <i class="fas fa-edit mr-1"></i><?= __("Gestisci") ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 5: Active Loans -->
        <?php if (!empty($activeLoans)): ?>
        <div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-book-reader text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Prestiti in Corso") ?></h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Libri attualmente in prestito") ?></p>
                </div>
                <span class="ml-auto bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300 text-sm font-medium px-3 py-1 rounded-full">
                    <?= count($activeLoans) ?>
                </span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($activeLoans as $loan): ?>
                    <?php
                    $daysLeft = (strtotime($loan['data_scadenza']) - strtotime($today)) / 86400;
                    $isExpiringSoon = $daysLeft <= 3 && $daysLeft >= 0;
                    ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border <?= $isExpiringSoon ? 'border-amber-300 dark:border-amber-700' : 'border-gray-200 dark:border-gray-700' ?> overflow-hidden hover:shadow-md transition-shadow">
                        <div class="p-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars(url($loan['copertina_url'] ?: '/uploads/copertine/placeholder.jpg'), ENT_QUOTES, 'UTF-8') ?>"
                                         class="w-16 h-22 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($loan['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2"><?= htmlspecialchars($loan['titolo']) ?></h3>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mt-1 <?= $isExpiringSoon ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' ?>">
                                        <i class="fas <?= $isExpiringSoon ? 'fa-exclamation-circle' : 'fa-check-circle' ?> text-[10px]"></i>
                                        <?php if ($isExpiringSoon): ?>
                                            <?= sprintf(__("Scade tra %d giorni"), max(0, $daysLeft)) ?>
                                        <?php else: ?>
                                            <?= __("In Corso") ?>
                                        <?php endif; ?>
                                    </span>
                                    <div class="space-y-1 text-xs mt-2">
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-user w-4 text-center mr-1 text-blue-500"></i>
                                            <?= htmlspecialchars($loan['utente_nome']) ?>
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-calendar-check w-4 text-center mr-1 text-green-500"></i>
                                            <?= __("Scadenza:") ?> <?= format_date($loan['data_scadenza'], false, '/') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <a href="<?= htmlspecialchars(url('/admin/prestiti/modifica/' . $loan['id']), ENT_QUOTES, 'UTF-8') ?>" class="flex-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium py-2 px-3 rounded-lg transition-colors text-center text-sm">
                                    <i class="fas fa-edit mr-1"></i><?= __("Gestisci") ?>
                                </a>
                                <button type="button" class="flex-1 bg-green-600 hover:bg-green-500 text-white font-medium py-2 px-3 rounded-lg transition-colors return-btn text-sm" data-loan-id="<?= $loan['id'] ?>">
                                    <i class="fas fa-undo mr-1"></i><?= __("Restituisci") ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 6: Active Reservations -->
        <?php if (!empty($activeReservations)): ?>
        <div>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-bookmark text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Prenotazioni Attive") ?></h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("In attesa di disponibilità") ?></p>
                </div>
                <span class="ml-auto bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300 text-sm font-medium px-3 py-1 rounded-full">
                    <?= count($activeReservations) ?>
                </span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($activeReservations as $reservation): ?>
                    <?php
                    $expiryDate = $reservation['data_scadenza_prenotazione'] ?? null;
                    $isExpiringSoon = $expiryDate && (strtotime($expiryDate) - strtotime($today)) / 86400 <= 3;
                    ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow">
                        <div class="p-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars(url($reservation['copertina_url'] ?: '/uploads/copertine/placeholder.jpg'), ENT_QUOTES, 'UTF-8') ?>"
                                         class="w-16 h-22 object-cover rounded-lg shadow-sm"
                                         alt="<?= htmlspecialchars($reservation['titolo']) ?>">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2"><?= htmlspecialchars($reservation['titolo']) ?></h3>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mt-1 bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                                        <i class="fas fa-hashtag text-[10px]"></i>
                                        <?= sprintf(__("Posizione %d in coda"), $reservation['posizione_coda']) ?>
                                    </span>
                                    <div class="space-y-1 text-xs mt-2">
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-user w-4 text-center mr-1 text-blue-500"></i>
                                            <?= htmlspecialchars($reservation['utente_nome']) ?>
                                        </p>
                                        <?php if ($reservation['data_inizio_richiesta'] && $reservation['data_fine_richiesta']): ?>
                                        <p class="text-gray-600 dark:text-gray-400 flex items-center">
                                            <i class="fas fa-calendar w-4 text-center mr-1 text-indigo-500"></i>
                                            <?= format_date($reservation['data_inizio_richiesta'], false, '/') ?> - <?= format_date($reservation['data_fine_richiesta'], false, '/') ?>
                                        </p>
                                        <?php endif; ?>
                                        <?php if ($expiryDate): ?>
                                        <p class="<?= $isExpiringSoon ? 'text-amber-600 dark:text-amber-400' : 'text-gray-600 dark:text-gray-400' ?> flex items-center">
                                            <i class="fas fa-hourglass-half w-4 text-center mr-1"></i>
                                            <?= __("Scadenza:") ?> <?= format_date($expiryDate, false, '/') ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <a href="<?= htmlspecialchars(url('/admin/prenotazioni'), ENT_QUOTES, 'UTF-8') ?>" class="flex-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium py-2 px-3 rounded-lg transition-colors text-center text-sm">
                                    <i class="fas fa-eye mr-1"></i><?= __("Dettagli") ?>
                                </a>
                                <button type="button" class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-3 rounded-lg transition-colors cancel-reservation-btn text-sm" data-reservation-id="<?= $reservation['id'] ?>">
                                    <i class="fas fa-times mr-1"></i><?= __("Annulla") ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Empty State -->
        <?php if (empty($overdueLoans) && empty($pickupLoans) && empty($pendingLoans) && empty($scheduledLoans) && empty($activeLoans) && empty($activeReservations)): ?>
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-8 text-center">
            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/40 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check-circle text-blue-600 dark:text-blue-400 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2"><?= __("Nessuna attività") ?></h3>
            <p class="text-blue-600 dark:text-blue-400"><?= __("Non ci sono prestiti o prenotazioni attive al momento.") ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
require __DIR__ . '/../partials/loan-actions-swal.php';
unset($loanActionTranslations);
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get CSRF token for protected requests
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Return loan button handler
    document.querySelectorAll('.return-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const loanId = this.dataset.loanId;
            Swal.fire({
                title: '<?= __("Conferma Restituzione") ?>',
                text: '<?= __("Confermi la restituzione di questo libro?") ?>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10B981',
                cancelButtonColor: '#6B7280',
                confirmButtonText: '<?= __("Restituisci") ?>',
                cancelButtonText: '<?= __("Annulla") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(window.BASE_PATH + '/admin/loans/return', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({ _csrf: csrfToken, loan_id: loanId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: '<?= __("Libro Restituito") ?>',
                                text: data.message || '<?= __("Il prestito è stato chiuso con successo.") ?>',
                                icon: 'success'
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('<?= __("Errore") ?>', data.message || '<?= __("Errore durante la restituzione") ?>', 'error');
                        }
                    })
                    .catch(() => {
                        Swal.fire('<?= __("Errore") ?>', '<?= __("Errore di connessione") ?>', 'error');
                    });
                }
            });
        });
    });

    // Cancel reservation button handler
    document.querySelectorAll('.cancel-reservation-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const reservationId = this.dataset.reservationId;
            Swal.fire({
                title: '<?= __("Annulla Prenotazione") ?>',
                text: '<?= __("Sei sicuro di voler annullare questa prenotazione?") ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#EF4444',
                cancelButtonColor: '#6B7280',
                confirmButtonText: '<?= __("Annulla Prenotazione") ?>',
                cancelButtonText: '<?= __("Chiudi") ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(window.BASE_PATH + '/admin/loans/cancel-reservation', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({ _csrf: csrfToken, reservation_id: reservationId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: '<?= __("Prenotazione Annullata") ?>',
                                icon: 'success'
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('<?= __("Errore") ?>', data.message || '<?= __("Errore durante l\'annullamento") ?>', 'error');
                        }
                    })
                    .catch(() => {
                        Swal.fire('<?= __("Errore") ?>', '<?= __("Errore di connessione") ?>', 'error');
                    });
                }
            });
        });
    });
});
</script>
