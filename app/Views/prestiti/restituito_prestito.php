<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
?>
<div class="min-h-screen bg-gray-50 py-6">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<section class="space-y-6">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="flex items-center space-x-2 text-sm text-slate-500">
            <li>
                <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-1 hover:text-white transition-colors">
                    <i class="fas fa-home"></i>
                    <?= __("Home") ?>
                </a>
            </li>
            <li><i class="fas fa-chevron-right text-xs"></i></li>
            <li>
                <a href="<?= htmlspecialchars(url('/admin/prestiti'), ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-1 hover:text-white transition-colors">
                    <i class="fas fa-handshake"></i><?= __("Prestiti") ?></a>
            </li>
            <li><i class="fas fa-chevron-right text-xs"></i></li>
            <li class="text-white font-medium"><?= __("Gestisci restituzione") ?></li>
        </ol>
    </nav>

    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500"><?= __("Gestione prestiti") ?></p>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-undo-alt text-gray-600"></i>
                <?= sprintf(__("Restituzione prestito #%s"), (int)($prestito['id'] ?? 0)) ?>
            </h1>
        </div>
        <a href="<?= htmlspecialchars(url('/admin/prestiti'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-full border border-gray-300 px-6 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 whitespace-nowrap">
            <i class="fas fa-arrow-left"></i>
            <span><?= __("Torna all'elenco") ?></span>
        </a>
    </header>

    <?php if (!empty($_GET['error'])): ?>
        <div class="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100" role="alert">
            <?php
            echo match ($_GET['error']) {
                'invalid_status'   => __('Stato prestito non valido.'),
                'update_failed'    => __('Si è verificato un errore durante l\'aggiornamento del prestito.'),
                default            => __('Impossibile completare l\'operazione. Riprova più tardi.')
            };
            ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars(url('/admin/prestiti/restituito/' . (int)($prestito['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="space-y-6">
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
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500"><?= __("Libro") ?></p>
                        <p class="text-xs text-gray-400">ID #<?= (int)($prestito['libro_id'] ?? 0); ?></p>
                    </div>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-4">
                    <?= HtmlHelper::e($prestito['titolo'] ?? 'Titolo non disponibile'); ?>
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-calendar w-4"></i>
                        <span><?= __("Prestato il") ?></span>
                        <strong class="text-gray-900"><?= HtmlHelper::e(format_date($prestito['data_prestito'] ?? '', false, '/') ?: '-'); ?></strong>
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-hourglass-end w-4"></i>
                        <span><?= __("Scadenza") ?></span>
                        <strong class="text-gray-900"><?= HtmlHelper::e(format_date($prestito['data_scadenza'] ?? '', false, '/') ?: '-'); ?></strong>
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
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500"><?= __("Utente") ?></p>
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
                            <i class="fas fa-sticky-note"></i> <?= __("Note") ?>
                        </p>
                        <p class="text-sm text-yellow-900"><?= HtmlHelper::e($prestito['note']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Form restituzione -->
        <div class="rounded-lg border border-gray-300 bg-white p-6 shadow-sm">
            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-edit text-gray-600"></i><?= __("Dettagli restituzione") ?></h3>

            <div class="grid gap-5 md:grid-cols-2">
                <label class="flex flex-col gap-2">
                    <span class="text-sm font-bold text-gray-900"><?= __("Stato prestito") ?> *</span>
                    <select
                        id="stato"
                        name="stato"
                        required aria-required="true"
                        class="rounded-lg border-2 border-gray-300 bg-white px-4 py-3 text-gray-900 font-medium focus:border-gray-900 focus:outline-none"
                    >
                        <?php
                        $options = [
                            'restituito'   => __('Restituito regolarmente'),
                            'in_ritardo'   => __('Restituito in ritardo'),
                            'perso'        => __('Perso'),
                            'danneggiato'  => __('Danneggiato'),
                        ];
                        // Use POST value if form resubmitted, otherwise default to 'restituito'
                        $defaultStatus = $_POST['stato'] ?? 'restituito';
                        // Validate against allowed options
                        if (!array_key_exists($defaultStatus, $options)) {
                            $defaultStatus = 'restituito';
                        }
                        foreach ($options as $value => $label):
                        ?>
                            <option value="<?= $value; ?>" <?= $defaultStatus === $value ? 'selected' : ''; ?>>
                                <?= $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="flex flex-col gap-2">
                    <span class="text-sm font-bold text-gray-900"><?= __("Note sulla restituzione") ?></span>
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
                <span class="whitespace-nowrap"><?= __("Conferma restituzione") ?></span>
            </button>
            <a href="<?= htmlspecialchars(url('/admin/prestiti'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center justify-center gap-3 rounded-lg border-2 border-gray-300 px-8 py-3.5 text-base font-bold text-gray-700 transition hover:bg-gray-100">
                <i class="fas fa-times text-lg"></i>
                <span class="whitespace-nowrap"><?= __("Annulla") ?></span>
            </a>
        </div>
    </form>
</section>
</div>
</div>
