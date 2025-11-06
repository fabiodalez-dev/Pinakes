<!-- Report Integrità Dati -->
<div class="flex-1 overflow-x-hidden">
    <!-- Page Header -->
    <div class="bg-white/50 backdrop-blur-sm border-b border-gray-200/80 dark:bg-gray-900/50 dark:border-gray-800/80 sticky top-0 z-30">
        <div class="px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-gray-700 rounded-lg flex items-center justify-center">
                        <i class="fas fa-shield-alt text-white text-sm"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= __("Report Integrità Dati") ?></h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Verifica coerenza e integrità del database") ?></p>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <button onclick="performMaintenance()" class="bg-gray-800 hover:bg-gray-900 text-white font-medium py-2 px-4 rounded-lg transition-colors">
                        <i class="fas fa-tools mr-2"></i><?= __("Esegui Manutenzione") ?>
                    </button>
                    <button onclick="location.reload()" class="bg-gray-100 hover:bg-gray-200 text-gray-900 border border-gray-300 font-medium py-2 px-4 rounded-lg transition-colors">
                        <i class="fas fa-sync-alt mr-2"></i><?= __("Aggiorna") ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="p-6">
        <!-- Report Info -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Informazioni Report") ?></h2>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    <?= __("Generato il") ?> <?= date('d-m-Y H:i:s', strtotime($report['timestamp'])) ?>
                </span>
            </div>

            <!-- Statistics Grid -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-gray-50 dark:bg-gray-900/20 p-4 rounded-lg border border-gray-200">
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $report['statistics']['total_books'] ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400"><?= __('Totale Libri') ?></div>
                </div>
                <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg border border-green-200" role="alert">
                    <div class="text-2xl font-bold text-green-700 dark:text-green-400"><?= $report['statistics']['books_available'] ?></div>
                    <div class="text-sm text-green-700 dark:text-green-400"><?= __('Disponibili') ?></div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-900/20 p-4 rounded-lg border border-gray-200">
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $report['statistics']['books_unavailable'] ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400"><?= __('Non Disponibili') ?></div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-900/20 p-4 rounded-lg border border-gray-200">
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $report['statistics']['active_loans'] ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400"><?= __('Prestiti Attivi') ?></div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-900/20 p-4 rounded-lg border border-gray-200">
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $report['statistics']['overdue_loans'] ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400"><?= __('Prestiti Scaduti') ?></div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-900/20 p-4 rounded-lg border border-gray-200">
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?= $report['statistics']['total_loans'] ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400"><?= __('Totale Prestiti') ?></div>
                </div>
            </div>
        </div>

        <!-- Consistency Issues -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Problemi di Integrità") ?></h2>
                    <?php if (empty($report['consistency_issues'])): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                            <i class="fas fa-check-circle mr-2"></i><?= __("Nessun Problema") ?>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400">
                            <i class="fas fa-exclamation-triangle mr-2"></i><?= sprintf(__("%d Problemi"), count($report['consistency_issues'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-6">
                <?php if (empty($report['consistency_issues'])): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-check-circle text-4xl text-green-500 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-400 text-lg"><?= __("Tutti i controlli di integrità sono passati con successo!") ?></p>
                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-2"><?= __("Il database è coerente e non sono stati rilevati problemi.") ?></p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php
                        $typeIcons = [
                            'negative_copies' => 'fas fa-minus-circle text-red-500',
                            'excess_copies' => 'fas fa-plus-circle text-orange-500',
                            'orphan_loan' => 'fas fa-unlink text-yellow-500',
                            'missing_due_date' => 'fas fa-calendar-times text-purple-500',
                            'status_mismatch' => 'fas fa-exclamation-triangle text-blue-500'
                        ];

                        $typeLabels = [
                            'negative_copies' => __('Copie Negative'),
                            'excess_copies' => __('Copie Eccessive'),
                            'orphan_loan' => __('Prestiti Orfani'),
                            'missing_due_date' => __('Scadenza Mancante'),
                            'status_mismatch' => __('Stato Incongruente')
                        ];

                        foreach ($report['consistency_issues'] as $issue):
                            $icon = $typeIcons[$issue['type']] ?? 'fas fa-exclamation-circle text-gray-500';
                            $label = $typeLabels[$issue['type']] ?? ucfirst($issue['type']);
                        ?>
                            <div class="flex items-start space-x-3 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <i class="<?= $icon ?> mt-1"></i>
                                <div class="flex-1">
                                    <div class="font-medium text-gray-900 dark:text-white mb-1"><?= htmlspecialchars($label) ?></div>
                                    <div class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($issue['message']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-info-circle text-yellow-600 dark:text-yellow-400 mr-3"></i>
                            <div>
                                <div class="font-medium text-yellow-800 dark:text-yellow-200"><?= __('Sono stati rilevati problemi di integrità') ?></div>
                                <div class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                                    <?= __("Clicca su \"Esegui Manutenzione\" per correggere automaticamente i problemi riparabili.") ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4"><?= __("Azioni di Manutenzione") ?></h3>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <button onclick="recalculateAvailability()" class="p-4 border-2 border-dashed border-blue-300 dark:border-blue-700 rounded-lg hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                    <i class="fas fa-calculator text-blue-500 text-2xl mb-2"></i>
                    <div class="font-medium text-gray-900 dark:text-white"><?= __('Ricalcola Disponibilità') ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1"><?= __('Aggiorna il conteggio delle copie disponibili') ?></div>
                </button>

                <button onclick="fixIssues()" class="p-4 border-2 border-dashed border-green-300 dark:border-green-700 rounded-lg hover:border-green-500 hover:bg-green-50 dark:hover:bg-green-900/20 transition-colors">
                    <i class="fas fa-wrench text-green-500 text-2xl mb-2"></i>
                    <div class="font-medium text-gray-900 dark:text-white"><?= __('Correggi Problemi') ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1"><?= __('Ripara automaticamente gli errori rilevati') ?></div>
                </button>

                <button onclick="performMaintenance()" class="p-4 border-2 border-dashed border-purple-300 dark:border-purple-700 rounded-lg hover:border-purple-500 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition-colors">
                    <i class="fas fa-magic text-purple-500 text-2xl mb-2"></i>
                    <div class="font-medium text-gray-900 dark:text-white"><?= __('Manutenzione Completa') ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1"><?= __('Esegui tutte le operazioni di manutenzione') ?></div>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
async function recalculateAvailability() {
    try {
        const response = await csrfFetch('/admin/maintenance/recalculate-availability', {
            method: 'POST'
        });

        const result = await response.json();

        if (result.success) {
            alert(`✅ ${result.message}`);
            location.reload();
        } else {
            alert(`❌ ${result.message}`);
        }
    } catch (error) {
        alert(__('❌ Errore di comunicazione con il server'));
    }
}

async function fixIssues() {
    if (!confirm(__('Vuoi correggere automaticamente i problemi di integrità rilevati?'))) return;

    try {
        const response = await csrfFetch('/admin/maintenance/fix-issues', {
            method: 'POST'
        });

        const result = await response.json();

        if (result.success) {
            alert(`✅ ${result.message}`);
            location.reload();
        } else {
            alert(`❌ ${result.message}`);
        }
    } catch (error) {
        alert(__('❌ Errore di comunicazione con il server'));
    }
}

async function performMaintenance() {
    if (!confirm(__('Vuoi eseguire la manutenzione completa del sistema? Questa operazione potrebbe richiedere alcuni minuti.'))) return;

    try {
        const response = await csrfFetch('/admin/maintenance/perform', {
            method: 'POST'
        });

        const result = await response.json();

        if (result.success) {
            alert(`✅ ${result.message}`);
            location.reload();
        } else {
            alert(`❌ ${result.message}`);
        }
    } catch (error) {
        alert(__('❌ Errore di comunicazione con il server'));
    }
}
</script>