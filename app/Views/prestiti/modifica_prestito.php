<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrf = Csrf::ensureToken();
?>
<section class="space-y-6">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="flex items-center space-x-2 text-sm text-gray-600">
            <li>
                <a href="/admin/dashboard" class="hover:text-gray-900 transition-colors flex items-center gap-1">
                    <i class="fas fa-home"></i>
                    Home
                </a>
            </li>
            <li><i class="fas fa-chevron-right text-xs"></i></li>
            <li>
                <a href="/admin/prestiti" class="hover:text-gray-900 transition-colors flex items-center gap-1">
                    <i class="fas fa-handshake"></i>
                    Prestiti
                </a>
            </li>
            <li><i class="fas fa-chevron-right text-xs"></i></li>
            <li class="text-gray-900 font-medium">Modifica prestito</li>
        </ol>
    </nav>

    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Gestione prestiti</p>
            <h1 class="text-2xl font-bold text-gray-900">Modifica prestito #<?= (int)($prestito['id'] ?? 0); ?></h1>
        </div>
        <a href="/admin/prestiti" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors shadow-sm">
            <i class="fas fa-arrow-left"></i>
            Torna all'elenco
        </a>
    </header>

    <form method="post" action="/admin/prestiti/update/<?= (int)($prestito['id'] ?? 0); ?>" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="utente_id" value="<?= (int)($prestito['utente_id'] ?? 0); ?>">
        <input type="hidden" name="libro_id" value="<?= (int)($prestito['libro_id'] ?? 0); ?>">

        <div class="grid gap-5 md:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-5">
                <span class="text-xs uppercase tracking-widest text-gray-500">Utente</span>
                <div class="mt-3 flex items-start gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-900 text-white">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p class="text-lg font-semibold text-gray-900 leading-tight">
                            <?= HtmlHelper::e($prestito['utente'] ?? 'Utente sconosciuto'); ?>
                        </p>
                        <p class="text-sm text-gray-600 mt-1">ID utente: #<?= (int)($prestito['utente_id'] ?? 0); ?></p>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-5">
                <span class="text-xs uppercase tracking-widest text-gray-500">Libro</span>
                <div class="mt-3 flex items-start gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-900 text-white">
                        <i class="fas fa-book"></i>
                    </div>
                    <div>
                        <p class="text-lg font-semibold text-gray-900 leading-tight">
                            <?= HtmlHelper::e($prestito['libro'] ?? 'Libro non disponibile'); ?>
                        </p>
                        <p class="text-sm text-gray-600 mt-1">ID libro: #<?= (int)($prestito['libro_id'] ?? 0); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 grid gap-5 lg:grid-cols-2">
            <label class="flex flex-col gap-2">
                <span class="text-sm font-medium text-gray-700">Data prestito</span>
                <input
                    type="date"
                    name="data_prestito"
                    value="<?= HtmlHelper::e($prestito['data_prestito'] ?? date('Y-m-d')); ?>"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                >
            </label>

            <label class="flex flex-col gap-2">
                <span class="text-sm font-medium text-gray-700">Data scadenza prevista</span>
                <input
                    type="date"
                    name="data_scadenza"
                    value="<?= HtmlHelper::e($prestito['data_scadenza'] ?? date('Y-m-d', strtotime('+14 days'))); ?>"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                >
            </label>
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800">
                <i class="fas fa-save"></i>
                Salva modifiche
            </button>

            <?php if ((int)($prestito['attivo'] ?? 0) === 1 && empty($prestito['data_restituzione'])): ?>
            <a href="/admin/prestiti/restituito/<?= (int)($prestito['id'] ?? 0); ?>" class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-green-700">
                <i class="fas fa-check-circle"></i>
                Registra Restituzione
            </a>
            <?php endif; ?>

            <a href="/admin/prestiti" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-6 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors shadow-sm">
                <i class="fas fa-times"></i>
                Annulla
            </a>
        </div>
    </form>
</section>
