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

        <!-- Configuration Issues -->
        <?php
        $configIssues = array_filter($report['consistency_issues'] ?? [], function($issue) {
            return in_array($issue['type'] ?? '', ['missing_canonical_url', 'empty_canonical_url', 'invalid_canonical_url']);
        });
        $dbIssues = array_filter($report['consistency_issues'] ?? [], function($issue) {
            return !in_array($issue['type'] ?? '', ['missing_canonical_url', 'empty_canonical_url', 'invalid_canonical_url']);
        });
        ?>
        <?php if (!empty($configIssues)): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Problemi di Configurazione") ?></h2>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400">
                        <i class="fas fa-exclamation-triangle mr-2"></i><?= sprintf(__("%d Problemi"), count($configIssues)) ?>
                    </span>
                </div>
            </div>

            <div class="p-6">
                <div class="space-y-4">
                    <?php
                    $configIcons = [
                        'missing_canonical_url' => 'fas fa-link-slash text-orange-500',
                        'empty_canonical_url' => 'fas fa-unlink text-yellow-500',
                        'invalid_canonical_url' => 'fas fa-exclamation-circle text-red-500'
                    ];

                    $configLabels = [
                        'missing_canonical_url' => __('URL Canonico Mancante'),
                        'empty_canonical_url' => __('URL Canonico Vuoto'),
                        'invalid_canonical_url' => __('URL Canonico Non Valido')
                    ];

                    foreach ($configIssues as $issue):
                        $icon = $configIcons[$issue['type']] ?? 'fas fa-exclamation-circle text-gray-500';
                        $label = $configLabels[$issue['type']] ?? ucfirst($issue['type']);

                        // Estrai il valore suggerito dalla fix_suggestion
                        preg_match('/APP_CANONICAL_URL=(.+)$/', $issue['fix_suggestion'] ?? '', $matches);
                        $suggestedUrl = $matches[1] ?? '';
                    ?>
                        <div class="flex items-start space-x-3 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border-l-4 <?= $issue['severity'] === 'error' ? 'border-red-500' : 'border-yellow-500' ?>">
                            <i class="<?= $icon ?> mt-1"></i>
                            <div class="flex-1">
                                <div class="font-medium text-gray-900 dark:text-white mb-1"><?= htmlspecialchars($label) ?></div>
                                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2"><?= htmlspecialchars($issue['message']) ?></div>
                                <div class="text-xs text-gray-500 dark:text-gray-500 mb-3">
                                    <i class="fas fa-lightbulb mr-1"></i><?= htmlspecialchars($issue['fix_suggestion'] ?? '') ?>
                                </div>
                                <?php if (!empty($suggestedUrl)): ?>
                                <button
                                    onclick="applyConfigFix('<?= htmlspecialchars($issue['type'], ENT_QUOTES) ?>', '<?= htmlspecialchars($suggestedUrl, ENT_QUOTES) ?>')"
                                    class="inline-flex items-center px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                                    <i class="fas fa-magic mr-2"></i><?= __("Applica Fix") ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Database Consistency Issues -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Problemi di Integrità Database") ?></h2>
                    <?php if (empty($dbIssues)): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                            <i class="fas fa-check-circle mr-2"></i><?= __("Nessun Problema") ?>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400">
                            <i class="fas fa-exclamation-triangle mr-2"></i><?= sprintf(__("%d Problemi"), count($dbIssues)) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-6">
                <?php if (empty($dbIssues)): ?>
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
                            'status_mismatch' => 'fas fa-exclamation-triangle text-blue-500',
                            'overlap_reservation_loan' => 'fas fa-calendar-minus text-red-500',
                            'overlap_reservation_reservation' => 'fas fa-calendar-exclamation text-orange-500'
                        ];

                        $typeLabels = [
                            'negative_copies' => __('Copie Negative'),
                            'excess_copies' => __('Copie Eccessive'),
                            'orphan_loan' => __('Prestiti Orfani'),
                            'missing_due_date' => __('Scadenza Mancante'),
                            'status_mismatch' => __('Stato Incongruente'),
                            'overlap_reservation_loan' => __('Prenotazione sovrapposta a prestito'),
                            'overlap_reservation_reservation' => __('Prenotazioni sovrapposte')
                        ];

                        foreach ($dbIssues as $issue):
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

        <!-- Missing Indexes Section -->
        <?php $missingIndexes = $report['missing_indexes'] ?? []; ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        <i class="fas fa-database mr-2 text-indigo-500"></i><?= __("Ottimizzazione Indici Database") ?>
                    </h2>
                    <?php if (empty($missingIndexes)): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                            <i class="fas fa-check-circle mr-2"></i><?= __("Ottimizzato") ?>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400">
                            <i class="fas fa-exclamation-triangle mr-2"></i><?= sprintf(__("%d Indici Mancanti"), count($missingIndexes)) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-6">
                <?php if (empty($missingIndexes)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-rocket text-4xl text-green-500 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-400 text-lg"><?= __("Il database è già ottimizzato!") ?></p>
                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-2"><?= __("Tutti gli indici di performance sono presenti.") ?></p>
                    </div>
                <?php else: ?>
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                            <div>
                                <p class="text-blue-800 dark:text-blue-200 font-medium"><?= __("Perché servono questi indici?") ?></p>
                                <p class="text-sm text-blue-700 dark:text-blue-300 mt-1">
                                    <?= __("Gli indici migliorano significativamente le performance delle query, specialmente su tabelle con molti record. Le installazioni recenti li includono già, ma le installazioni più vecchie potrebbero non averli.") ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Tabella") ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Nome Indice") ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Colonne") ?></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($missingIndexes as $index): ?>
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded"><?= htmlspecialchars($index['table']) ?></code>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                        <?= htmlspecialchars($index['index_name']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                        <?php
                                        $cols = array_map(function($col) use ($index) {
                                            $prefix = isset($index['prefix_length']) ? '(' . htmlspecialchars((string)$index['prefix_length'], ENT_QUOTES, 'UTF-8') . ')' : '';
                                            return "<code class='bg-gray-100 dark:bg-gray-700 px-1 rounded'>" . htmlspecialchars($col, ENT_QUOTES, 'UTF-8') . $prefix . "</code>";
                                        }, $index['columns']);
                                        echo implode(', ', $cols);
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <button onclick="createMissingIndexes()" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition-colors">
                            <i class="fas fa-bolt mr-2"></i><?= __("Crea Indici Automaticamente") ?>
                        </button>
                        <a href="/admin/maintenance/indexes-sql" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white font-medium rounded-lg transition-colors">
                            <i class="fas fa-download mr-2"></i><?= __("Scarica Script SQL") ?>
                        </a>
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
    const processingTitle = '<?= __("Elaborazione...") ?>';
    const doneTitle = '<?= __("Operazione completata") ?>';
    const failTitle = '<?= __("Operazione fallita") ?>';
    const commErr = '<?= __("Errore di comunicazione con il server") ?>';

    Swal.fire({
        title: processingTitle,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    try {
        const response = await csrfFetch('/admin/maintenance/recalculate-availability', { method: 'POST' });
        const result = await response.json();
        Swal.close();
        Swal.fire({
            icon: result.success ? 'success' : 'error',
            title: result.success ? doneTitle : failTitle,
            text: result.message || ''
        }).then(() => {
            if (result.success) location.reload();
        });
    } catch (error) {
        Swal.close();
        Swal.fire({ icon: 'error', title: failTitle, text: commErr });
    }
}

async function fixIssues() {
    const processingTitle = '<?= __("Elaborazione...") ?>';
    const doneTitle = '<?= __("Operazione completata") ?>';
    const failTitle = '<?= __("Operazione fallita") ?>';
    const commErr = '<?= __("Errore di comunicazione con il server") ?>';

    const confirmTitle = '<?= __("Confermi?") ?>';
    const confirmText = '<?= __("Vuoi correggere automaticamente i problemi di integrità rilevati?") ?>';
    const confirmResult = await Swal.fire({
        title: confirmTitle,
        text: confirmText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= __("Sì, correggi") ?>',
        cancelButtonText: '<?= __("Annulla") ?>'
    });
    if (!confirmResult.isConfirmed) return;

    Swal.fire({
        title: processingTitle,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const response = await csrfFetch('/admin/maintenance/fix-issues', { method: 'POST' });
        const result = await response.json();
        Swal.close();
        Swal.fire({
            icon: result.success ? 'success' : 'error',
            title: result.success ? doneTitle : failTitle,
            text: result.message || ''
        }).then(() => {
            if (result.success) location.reload();
        });
    } catch (error) {
        Swal.close();
        Swal.fire({ icon: 'error', title: failTitle, text: commErr });
    }
}

async function performMaintenance() {
    const processingTitle = '<?= __("Elaborazione...") ?>';
    const doneTitle = '<?= __("Operazione completata") ?>';
    const failTitle = '<?= __("Operazione fallita") ?>';
    const commErr = '<?= __("Errore di comunicazione con il server") ?>';

    const confirmTitle = '<?= __("Confermi?") ?>';
    const confirmText = '<?= __("Vuoi eseguire la manutenzione completa del sistema? Questa operazione potrebbe richiedere alcuni minuti.") ?>';
    const confirmResult = await Swal.fire({
        title: confirmTitle,
        text: confirmText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= __("Sì, esegui") ?>',
        cancelButtonText: '<?= __("Annulla") ?>'
    });
    if (!confirmResult.isConfirmed) return;

    Swal.fire({
        title: processingTitle,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const response = await csrfFetch('/admin/maintenance/perform', { method: 'POST' });
        const result = await response.json();
        Swal.close();
        Swal.fire({
            icon: result.success ? 'success' : 'error',
            title: result.success ? doneTitle : failTitle,
            text: result.message || ''
        }).then(() => {
            if (result.success) location.reload();
        });
    } catch (error) {
        Swal.close();
        Swal.fire({ icon: 'error', title: failTitle, text: commErr });
    }
}

async function applyConfigFix(issueType, fixValue) {
    const processingTitle = '<?= __("Applicazione del fix...") ?>';
    const doneTitle = '<?= __("Fix applicato") ?>';
    const failTitle = '<?= __("Operazione fallita") ?>';
    const commErr = '<?= __("Errore di comunicazione con il server") ?>';

    const confirmTitle = '<?= __("Confermi?") ?>';
    const confirmTextTemplate = '<?= __("Vuoi impostare APP_CANONICAL_URL a:") ?>';
    const confirmText = `${confirmTextTemplate}\n${fixValue}`;
    const confirmResult = await Swal.fire({
        title: confirmTitle,
        text: confirmText,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<?= __("Sì, applica") ?>',
        cancelButtonText: '<?= __("Annulla") ?>'
    });
    if (!confirmResult.isConfirmed) return;

    Swal.fire({
        title: processingTitle,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const response = await csrfFetch('/admin/maintenance/apply-config-fix', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                issue_type: issueType,
                fix_value: fixValue
            })
        });
        const result = await response.json();
        Swal.close();
        Swal.fire({
            icon: result.success ? 'success' : 'error',
            title: result.success ? doneTitle : failTitle,
            text: result.message || ''
        }).then(() => {
            if (result.success) location.reload();
        });
    } catch (error) {
        Swal.close();
        Swal.fire({ icon: 'error', title: failTitle, text: commErr });
    }
}

async function createMissingIndexes() {
    const processingTitle = '<?= addslashes(__("Creazione indici...")) ?>';
    const doneTitle = '<?= addslashes(__("Operazione completata")) ?>';
    const failTitle = '<?= addslashes(__("Operazione fallita")) ?>';
    const commErr = '<?= addslashes(__("Errore di comunicazione con il server")) ?>';

    const confirmTitle = '<?= addslashes(__("Confermi?")) ?>';
    const confirmText = '<?= addslashes(__("Vuoi creare gli indici mancanti? Questa operazione migliorerà le performance del database.")) ?>';
    const confirmResult = await Swal.fire({
        title: confirmTitle,
        text: confirmText,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<?= addslashes(__("Sì, crea indici")) ?>',
        cancelButtonText: '<?= addslashes(__("Annulla")) ?>'
    });
    if (!confirmResult.isConfirmed) return;

    Swal.fire({
        title: processingTitle,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const response = await csrfFetch('/admin/maintenance/create-indexes', { method: 'POST' });
        const result = await response.json();
        Swal.close();

        let message = result.message || '';
        if (result.success && result.created && result.created.length > 0) {
            message += '\n\n<?= addslashes(__("Indici creati:")) ?> ' + result.created.length;
        }
        if (result.errors && result.errors.length > 0) {
            message += '\n\n<?= addslashes(__("Errori:")) ?> ' + result.errors.length;
        }

        Swal.fire({
            icon: result.success ? 'success' : 'error',
            title: result.success ? doneTitle : failTitle,
            text: message
        }).then(() => {
            if (result.success) location.reload();
        });
    } catch (error) {
        Swal.close();
        Swal.fire({ icon: 'error', title: failTitle, text: commErr });
    }
}

// Expose functions to global scope for inline handlers
window.recalculateAvailability = recalculateAvailability;
window.fixIssues = fixIssues;
window.performMaintenance = performMaintenance;
window.applyConfigFix = applyConfigFix;
window.createMissingIndexes = createMissingIndexes;
</script>
