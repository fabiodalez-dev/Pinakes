<!-- Crea Prenotazione Admin -->
<div class="min-h-screen bg-gray-50 py-12">
    <div class="max-w-5xl mx-auto px-6 sm:px-8 lg:px-12">

        <!-- Header -->
        <div class="mb-12">
            <nav class="flex text-sm text-gray-500 mb-8 pb-4 border-b border-gray-200">
                <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-900 transition-colors">Dashboard</a>
                <span class="mx-3 text-gray-300">/</span>
                <a href="<?= htmlspecialchars(url('/admin/prenotazioni'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-900 transition-colors"><?= __("Prenotazioni") ?></a>
                <span class="mx-3 text-gray-300">/</span>
                <span class="text-gray-900 font-medium"><?= __("Crea Prenotazione") ?></span>
            </nav>

            <div class="flex items-start justify-between">
                <div class="space-y-4">
                    <h1 class="text-4xl font-bold text-gray-900 flex items-center">
                        <div class="w-16 h-16 bg-gray-900 rounded-2xl flex items-center justify-center mr-6">
                            <i class="fas fa-calendar-plus text-white text-2xl"></i>
                        </div><?= __("Crea Nuova Prenotazione") ?></h1>
                    <p class="text-lg text-gray-600 ml-22"><?= __("Registra una prenotazione per permettere ad un utente di riservare un libro specifico") ?></p>
                </div>
                <a href="<?= htmlspecialchars(url('/admin/prenotazioni'), ENT_QUOTES, 'UTF-8') ?>" class="px-6 py-3 bg-white border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors shadow-sm">
                    <i class="fas fa-arrow-left mr-2"></i><?= __("Torna alle Prenotazioni") ?></a>
            </div>
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
                <form method="POST" action="<?= htmlspecialchars(url('/admin/prenotazioni/crea'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-12">
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
                                           value="<?php echo date('Y-m-d'); ?>"
                                           class="w-full px-6 py-5 text-xl border-2 border-gray-300 rounded-2xl focus:ring-4 focus:ring-purple-500 focus:border-purple-500 bg-white shadow-lg transition-all">
                                    <p class="text-lg text-gray-500 mt-3"><?= __("Data di inizio della prenotazione (default: oggi)") ?></p>
                                </div>

                                <!-- Data Scadenza -->
                                <div>
                                    <label for="data_scadenza" class="block text-lg font-bold text-gray-700 mb-4"><?= __("Data Scadenza") ?></label>
                                    <input type="date" name="data_scadenza" id="data_scadenza"
                                           value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                                           class="w-full px-6 py-5 text-xl border-2 border-gray-300 rounded-2xl focus:ring-4 focus:ring-purple-500 focus:border-purple-500 bg-white shadow-lg transition-all">
                                    <p class="text-lg text-gray-500 mt-3"><?= __("Data di scadenza della prenotazione (default: +30 giorni)") ?></p>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Info Note -->
                    <div class="bg-blue-50 rounded-2xl p-8 border-2 border-blue-200">
                        <div class="flex items-start space-x-6">
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0 mt-2">
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

                    <!-- Buttons -->
                    <div class="flex items-center justify-between pt-12 border-t-2 border-gray-200">
                        <a href="<?= htmlspecialchars(url('/admin/prenotazioni'), ENT_QUOTES, 'UTF-8') ?>"
                           class="px-8 py-4 text-lg border-2 border-gray-300 text-gray-700 rounded-2xl hover:bg-gray-50 transition-colors font-semibold shadow-md">
                            <i class="fas fa-times mr-3"></i>Annulla
                        </a>
                        <button type="submit"
                                class="px-12 py-4 text-lg bg-gray-900 text-white rounded-2xl hover:bg-gray-800 transition-colors font-semibold shadow-xl">
                            <i class="fas fa-save mr-3"></i><?= __("Crea Prenotazione") ?></button>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>

<script>
// Auto-set minimum date for data_scadenza based on data_prenotazione
document.getElementById('data_prenotazione').addEventListener('change', function() {
    const prenotazioneDate = this.value;
    const scadenzaInput = document.getElementById('data_scadenza');

    if (prenotazioneDate) {
        // Set minimum scadenza date to prenotazione date
        scadenzaInput.min = prenotazioneDate;

        // If current scadenza is before prenotazione, update it
        if (scadenzaInput.value && scadenzaInput.value < prenotazioneDate) {
            const nextMonth = new Date(prenotazioneDate);
            nextMonth.setMonth(nextMonth.getMonth() + 1);
            scadenzaInput.value = nextMonth.toISOString().split('T')[0];
        }
    }
});

// Initialize minimum date on page load
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('data_prenotazione').min = today;
    document.getElementById('data_scadenza').min = today;
});
</script>