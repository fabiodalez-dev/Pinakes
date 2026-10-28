<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
?>
<div class="min-h-screen bg-gray-50 py-6">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<section class="space-y-6">


    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-2xl font-bold text-gray-900 flex items-center gap-2"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

                <?= sprintf(__("Restituzione prestito #%s"), (int)($prestito['id'] ?? 0)) ?>
            <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500"><?= __("Gestione prestiti") ?></p>

        <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><a href="<?= htmlspecialchars(url('/admin/loans'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-full border border-gray-300 px-6 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 whitespace-nowrap">
            <i class="fas fa-arrow-left"></i>
            <span><?= __("Torna all'elenco") ?></span>
        </a><?php
$workspaceHeader['back'] = (string) ob_get_clean();
ob_start();
?>


    <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
        <ol>
            <li>
                <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
            </li>

            <li>
                <a href="<?= htmlspecialchars(url('/admin/loans'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= __("Prestiti") ?></a>
            </li>

            <li aria-current="page"><?= __("Gestisci restituzione") ?></li>
        </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <?php if (!empty($_GET['error'])): ?>
        <div class="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100" role="alert">
            <?php
            echo match ($_GET['error']) {
                'invalid_status'   => __('Stato prestito non valido.'),
                'invalid_penalty'  => __('Importo non valido: usa un valore positivo con al massimo due decimali.'),
                'waiver_reason_required' => __('Per condonare la multa serve una motivazione.'),
                'update_failed'    => __('Si è verificato un errore durante l\'aggiornamento del prestito.'),
                'concurrent_retry' => __('Un\'altra operazione stava aggiornando lo stesso libro: nessuna modifica salvata, riprova ora.'),
                default            => __('Impossibile completare l\'operazione. Riprova più tardi.')
            };
            ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars(url('/admin/loans/returned/' . (int)($prestito['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="space-y-6">
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
                        // Il ritardo NON è una scelta: è derivato automaticamente dalla
                        // scadenza (restituito + restituito_in_ritardo=1 se oltre termine, I4).
                        $options = [
                            'restituito'   => __('Restituito'),
                            'manutenzione' => __('Restituito — copia in manutenzione'),
                            'in_restauro'  => __('Restituito — copia in restauro'),
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
                        placeholder="<?= htmlspecialchars(__('Eventuali annotazioni sullo stato del libro...'), ENT_QUOTES, 'UTF-8') ?>"
                        class="rounded-lg border-2 border-gray-300 bg-white px-4 py-3 text-gray-900 placeholder-gray-400 focus:border-gray-900 focus:outline-none"
                    ><?= HtmlHelper::e($prestito['note'] ?? ''); ?></textarea>
                </label>

                <label id="sanzione-field" class="hidden flex-col gap-2">
                    <span class="text-sm font-bold text-gray-900"><?= __("Importo addebitato") ?></span>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-gray-500">€</span>
                        <input
                            id="sanzione"
                            name="sanzione"
                            type="text"
                            inputmode="decimal"
                            value="<?= htmlspecialchars((string) ($prestito['sanzione'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>"
                            pattern="(?:0|[1-9][0-9]{0,7})(?:[.,][0-9]{1,2})?"
                            class="w-full rounded-lg border-2 border-gray-300 bg-white py-3 pl-9 pr-4 text-gray-900 focus:border-gray-900 focus:outline-none"
                        >
                    </div>
                    <span class="text-xs text-gray-500"><?= __("Facoltativo; viene registrato e comunicato per copie perse o danneggiate.") ?></span>
                </label>
            </div>
        </div>

        <?php if (!empty($finePreview['enabled']) && (int) ($finePreview['cents'] ?? 0) > 0): ?>
        <!-- Multa per ritardo (plan §7) -->
        <div id="fine-preview" class="rounded-lg border border-yellow-300 bg-yellow-50 p-6 shadow-sm" data-fine-cents="<?= (int) $finePreview['cents'] ?>">
            <h3 class="text-lg font-bold text-gray-900 mb-2 flex items-center gap-2">
                <i class="fas fa-coins text-gray-600"></i><?= __("Multa per ritardo") ?></h3>
            <p class="text-sm text-yellow-900">
                <?= HtmlHelper::e(sprintf(
                    __('Restituito con %1$d giorni di ritardo: alla conferma verrà addebitata una multa di %2$s.'),
                    (int) $finePreview['days'],
                    \App\Services\FineParameters::format((int) $finePreview['cents'])
                )); ?>
            </p>
            <label class="mt-4 flex items-center gap-2 text-sm font-bold text-gray-900">
                <input type="checkbox" id="waive_fine" name="waive_fine" value="1">
                <span><?= __("Condona la multa") ?></span>
            </label>
            <label id="waive-reason-field" class="mt-3 hidden flex-col gap-2">
                <span class="text-sm font-bold text-gray-900"><?= __("Motivazione del condono") ?> *</span>
                <input id="waive_reason" name="waive_reason" type="text" maxlength="255" disabled
                       class="w-full rounded-lg border-2 border-gray-300 bg-white px-4 py-3 text-gray-900 focus:border-gray-900 focus:outline-none">
            </label>
        </div>
        <?php endif; ?>

        <!-- Azioni -->
        <div class="pk-form-actions flex flex-wrap gap-3">
            <button type="submit" class="inline-flex items-center justify-center gap-3 rounded-lg bg-gray-900 px-8 py-3.5 text-base font-bold text-white transition hover:bg-gray-700">
                <i class="fas fa-check text-lg"></i>
                <span class="whitespace-nowrap"><?= __("Conferma restituzione") ?></span>
            </button>
            <a href="<?= htmlspecialchars(url('/admin/loans'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center justify-center gap-3 rounded-lg border-2 border-gray-300 px-8 py-3.5 text-base font-bold text-gray-700 transition hover:bg-gray-100">
                <i class="fas fa-times text-lg"></i>
                <span class="whitespace-nowrap"><?= __("Annulla") ?></span>
            </a>
        </div>
    </form>
</section>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const status = document.getElementById('stato');
    const field = document.getElementById('sanzione-field');
    const input = document.getElementById('sanzione');
    const sync = function () {
        const visible = status && (status.value === 'perso' || status.value === 'danneggiato');
        field.classList.toggle('hidden', !visible);
        field.classList.toggle('flex', visible);
        input.disabled = !visible;
    };
    status.addEventListener('change', sync);
    sync();
});
document.addEventListener('DOMContentLoaded', function () {
    const status = document.getElementById('stato');
    const box = document.getElementById('fine-preview');
    const waive = document.getElementById('waive_fine');
    const reasonField = document.getElementById('waive-reason-field');
    const reason = document.getElementById('waive_reason');
    if (!box || !waive || !reasonField || !reason) {
        return;
    }
    const sync = function () {
        // The late fine applies to an actual return only, not to a copy closed lost/damaged.
        const returned = !status || (status.value !== 'perso' && status.value !== 'danneggiato');
        box.classList.toggle('hidden', !returned);
        const askReason = returned && waive.checked;
        reasonField.classList.toggle('hidden', !askReason);
        reasonField.classList.toggle('flex', askReason);
        reason.disabled = !askReason;
        reason.required = askReason;
        waive.disabled = !returned;
    };
    waive.addEventListener('change', sync);
    if (status) {
        status.addEventListener('change', sync);
    }
    sync();
});
</script>
