<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
?>
<section class="space-y-6">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="flex items-center space-x-2 text-sm text-slate-500">
            <li>
                <a href="/admin/dashboard" class="flex items-center gap-1 hover:text-white transition-colors">
                    <i class="fas fa-home"></i>
                    Home
                </a>
            </li>
            <li><i class="fas fa-chevron-right text-xs"></i></li>
            <li>
                <a href="/admin/prestiti" class="flex items-center gap-1 hover:text-white transition-colors">
                    <i class="fas fa-handshake"></i>
                    Prestiti
                </a>
            </li>
            <li><i class="fas fa-chevron-right text-xs"></i></li>
            <li class="text-white font-medium">Gestisci restituzione</li>
        </ol>
    </nav>

    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Gestione prestiti</p>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-undo-alt text-gray-600"></i>
                Restituzione prestito #<?= (int)($prestito['id'] ?? 0); ?>
            </h1>
        </div>
        <a href="/admin/prestiti" class="inline-flex items-center gap-2 rounded-full border border-gray-300 px-6 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 whitespace-nowrap">
            <i class="fas fa-arrow-left"></i>
            <span>Torna all'elenco</span>
        </a>
    </header>

    <?php if (!empty($_GET['error'])): ?>
        <div class="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100" role="alert">
            <?php
            echo match ($_GET['error']) {
                'invalid_status'   => 'Stato prestito non valido.',
                'update_failed'    => 'Si è verificato un errore durante l\'aggiornamento del prestito.',
                default            => 'Impossibile completare l\'operazione. Riprova più tardi.'
            };
            ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/prestiti/restituito/<?= (int)($prestito['id'] ?? 0); ?>" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

        <!-- Dettagli prestito -->
        <div class="grid gap-4 md:grid-cols-2">
            <!-- Card Libro -->
            <div class="rounded-lg border border-gray-300 bg-white p-6 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-900 text-white">
                        <i class="fas fa-book"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Libro</p>
                        <p class="text-xs text-gray-400">ID #<?= (int)($prestito['libro_id'] ?? 0); ?></p>
                    </div>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-4">
                    <?= HtmlHelper::e($prestito['titolo'] ?? 'Titolo non disponibile'); ?>
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-calendar w-4"></i>
                        <span>Prestato il</span>
                        <strong class="text-gray-900"><?= HtmlHelper::e($prestito['data_prestito'] ?? '-'); ?></strong>
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-hourglass-end w-4"></i>
                        <span>Scadenza</span>
                        <strong class="text-gray-900"><?= HtmlHelper::e($prestito['data_scadenza'] ?? '-'); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Card Utente -->
            <div class="rounded-lg border border-gray-300 bg-white p-6 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-900 text-white">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">Utente</p>
                        <p class="text-xs text-gray-400">ID #<?= (int)($prestito['utente_id'] ?? 0); ?></p>
                    </div>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-4">
                    <?= HtmlHelper::e($prestito['utente_nome'] ?? 'Utente sconosciuto'); ?>
                </h3>
                <div class="space-y-2 text-sm">
                    <?php if (!empty($prestito['utente_email'])): ?>
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-envelope w-4"></i>
                            <a href="mailto:<?= HtmlHelper::e($prestito['utente_email']); ?>" class="text-gray-900 hover:underline">
                                <?= HtmlHelper::e($prestito['utente_email']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($prestito['utente_telefono'])): ?>
                        <div class="flex items-center gap-2 text-gray-600">
                            <i class="fas fa-phone w-4"></i>
                            <a href="tel:<?= HtmlHelper::e($prestito['utente_telefono']); ?>" class="text-gray-900 hover:underline">
                                <?= HtmlHelper::e($prestito['utente_telefono']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($prestito['note'])): ?>
                    <div class="mt-4 rounded-lg border border-yellow-300 bg-yellow-50 p-3" role="alert">
                        <p class="text-xs font-semibold uppercase tracking-wider text-yellow-700 mb-1">
                            <i class="fas fa-sticky-note"></i> Note
                        </p>
                        <p class="text-sm text-yellow-900"><?= HtmlHelper::e($prestito['note']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Form restituzione -->
        <div class="rounded-lg border border-gray-300 bg-white p-6 shadow-sm">
            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-edit text-gray-600"></i>
                Dettagli restituzione
            </h3>

            <div class="grid gap-5 md:grid-cols-2">
                <label class="flex flex-col gap-2">
                    <span class="text-sm font-bold text-gray-900">Stato prestito *</span>
                    <select
                        id="stato"
                        name="stato"
                        required aria-required="true"
                        class="rounded-lg border-2 border-gray-300 bg-white px-4 py-3 text-gray-900 font-medium focus:border-gray-900 focus:outline-none"
                    >
                        <?php
                        $options = [
                            'restituito'   => 'Restituito',
                            'in_ritardo'   => 'In ritardo',
                            'perso'        => 'Perso',
                            'danneggiato'  => 'Danneggiato',
                        ];
                        $currentStatus = (string)($prestito['stato'] ?? 'restituito');
                        foreach ($options as $value => $label):
                        ?>
                            <option value="<?= $value; ?>" <?= $currentStatus === $value ? 'selected' : ''; ?>>
                                <?= $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="flex flex-col gap-2">
                    <span class="text-sm font-bold text-gray-900">Note sulla restituzione</span>
                    <textarea
                        id="note"
                        name="note"
                        rows="4"
                        placeholder="<?= __('Eventuali annotazioni sullo stato del libro...') ?>"
                        class="rounded-lg border-2 border-gray-300 bg-white px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-gray-900 focus:outline-none"
                    ><?= HtmlHelper::e($prestito['note'] ?? ''); ?></textarea>
                </label>
            </div>
        </div>

        <!-- Azioni -->
        <div class="flex flex-wrap gap-3">
            <button type="submit" class="inline-flex items-center justify-center gap-3 rounded-lg bg-gray-900 px-8 py-3.5 text-base font-bold text-white transition hover:bg-gray-700">
                <i class="fas fa-check text-lg"></i>
                <span class="whitespace-nowrap">Conferma restituzione</span>
            </button>
            <a href="/admin/prestiti" class="inline-flex items-center justify-center gap-3 rounded-lg border-2 border-gray-300 px-8 py-3.5 text-base font-bold text-gray-700 transition hover:bg-gray-100">
                <i class="fas fa-times text-lg"></i>
                <span class="whitespace-nowrap">__("Annulla")</span>
            </a>
        </div>
    </form>
</section>
