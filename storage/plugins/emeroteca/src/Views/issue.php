<?php
/**
 * Emeroteca — fascicolo detail: all fields + cover upload + spoglio
 * (article rows added/removed with a small vanilla-JS helper).
 *
 * Chrome copied from the Archives admin form view.
 *
 * @var array<string, mixed> $fascicolo   includes anno, volume, testata_id, testata_titolo
 * @var array<int, array<string, mixed>> $articoli
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($fascicolo[$k] ?? ''));

$fid       = (int) $fascicolo['id'];
$testataId = (int) $fascicolo['testata_id'];
$issuesUrl = $e(url('/admin/periodicals/' . $testataId . '/issues'));
$formAction = $e(url('/admin/periodicals/issue/' . $fid));
$deleteAction = $e(url('/admin/periodicals/issue/' . $fid . '/delete'));
$csrf = $e(\App\Support\Csrf::ensureToken());

$statoLabels = [
    'posseduto'   => __('Posseduto'),
    'mancante'    => __('Mancante'),
    'danneggiato' => __('Danneggiato'),
    'in_restauro' => __('In restauro'),
    'smarrito'    => __('Smarrito'),
    'atteso'      => __('Atteso'),
];
$tipoArticoloLabels = [
    'articolo'   => __('Articolo'),
    'editoriale' => __('Editoriale'),
    'recensione' => __('Recensione'),
    'intervista' => __('Intervista'),
    'dossier'    => __('Dossier'),
    'rubrica'    => __('Rubrica'),
];
$cover = (string) ($fascicolo['copertina_url'] ?? '');
$coverSrc = $cover === '' ? '' : (str_starts_with($cover, '/') ? url($cover) : $cover);
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $issuesUrl ?>" class="hover:underline"><?= $e($fascicolo['testata_titolo']) ?></a>
            &nbsp;&raquo;&nbsp;
            <?= (int) $fascicolo['anno'] ?> · <?= __('n.') ?> <?= $e($fascicolo['numero']) ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900">
            <?= $e($fascicolo['testata_titolo']) ?> — <?= (int) $fascicolo['anno'] ?>, <?= __('n.') ?> <?= $e($fascicolo['numero']) ?>
        </h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __("Scheda del fascicolo: dati, copertina e spoglio degli articoli.") ?>
        </p>
    </div>

    <form method="POST" action="<?= $formAction ?>" enctype="multipart/form-data"
          class="bg-white shadow rounded-lg p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <!-- ── Dati del fascicolo ──────────────────────────────────── -->
        <div class="border-l-4 border-gray-300 pl-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Dati del fascicolo") ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="numero" class="form-label">
                        <?= __("Numero") ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="numero" id="numero"
                           value="<?= $val('numero') ?>" maxlength="50" required
                           class="form-input">
                </div>
                <div>
                    <label for="numero_progressivo" class="form-label">
                        <?= __("Numero progressivo") ?>
                    </label>
                    <input type="text" name="numero_progressivo" id="numero_progressivo"
                           value="<?= $val('numero_progressivo') ?>" maxlength="50"
                           class="form-input">
                </div>
                <div>
                    <label for="stato" class="form-label">
                        <?= __("Stato") ?>
                    </label>
                    <select name="stato" id="stato" class="form-input">
                        <?php foreach ($statoLabels as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ((string) ($fascicolo['stato'] ?? 'posseduto')) === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <label for="titolo_fascicolo" class="form-label">
                    <?= __("Titolo del fascicolo") ?>
                    <span class="text-xs text-gray-500 font-normal">(<?= __("per numeri monografici") ?>)</span>
                </label>
                <input type="text" name="titolo_fascicolo" id="titolo_fascicolo"
                       value="<?= $val('titolo_fascicolo') ?>" maxlength="255"
                       class="form-input">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label for="data_copertina" class="form-label">
                        <?= __("Data di copertina") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("testo libero, es. \"Marzo 1998\"") ?>)</span>
                    </label>
                    <input type="text" name="data_copertina" id="data_copertina"
                           value="<?= $val('data_copertina') ?>" maxlength="100"
                           class="form-input">
                </div>
                <div>
                    <label for="data_pubblicazione" class="form-label">
                        <?= __("Data di pubblicazione") ?>
                    </label>
                    <input type="date" name="data_pubblicazione" id="data_pubblicazione"
                           value="<?= $val('data_pubblicazione') ?>"
                           class="form-input">
                </div>
                <div>
                    <label for="pagine" class="form-label">
                        <?= __("Pagine") ?>
                    </label>
                    <input type="number" name="pagine" id="pagine"
                           value="<?= $val('pagine') ?>" min="0" max="32767"
                           class="form-input">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label for="numero_inventario" class="form-label">
                        <?= __("Numero di inventario") ?>
                    </label>
                    <input type="text" name="numero_inventario" id="numero_inventario"
                           value="<?= $val('numero_inventario') ?>" maxlength="100"
                           class="form-input">
                </div>
                <div>
                    <label for="supplementi" class="form-label">
                        <?= __("Supplementi / allegati") ?>
                    </label>
                    <input type="text" name="supplementi" id="supplementi"
                           value="<?= $val('supplementi') ?>" maxlength="500"
                           class="form-input">
                </div>
            </div>

            <div class="mt-4">
                <label for="note" class="form-label">
                    <?= __("Note") ?>
                </label>
                <textarea name="note" id="note" rows="3"
                          class="form-input"><?= $val('note') ?></textarea>
            </div>
        </div>

        <!-- ── Copertina ───────────────────────────────────────────── -->
        <div class="border-l-4 border-gray-300 pl-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Copertina") ?>
            </h2>
            <div class="flex items-start gap-4">
                <?php if ($coverSrc !== ''): ?>
                    <img src="<?= $e($coverSrc) ?>" alt="<?= $e(__('Copertina del fascicolo')) ?>"
                         class="w-24 h-36 rounded object-cover">
                <?php else: ?>
                    <div class="flex items-center justify-center w-24 h-36 rounded-lg bg-gray-100">
                        <i class="fas fa-newspaper text-gray-600"></i>
                    </div>
                <?php endif; ?>
                <div class="flex-1">
                    <label for="copertina" class="form-label">
                        <?= __("Carica nuova copertina") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("JPG, PNG o WebP, max 5MB") ?>)</span>
                    </label>
                    <input type="file" name="copertina" id="copertina"
                           accept="image/jpeg,image/png,image/webp"
                           class="form-input text-sm">
                    <p class="mt-1 text-xs text-gray-500">
                        <?= __("L'immagine viene salvata in /uploads/emeroteca/ e sostituisce la copertina attuale.") ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- ── Spoglio (articoli) ──────────────────────────────────── -->
        <div class="border-l-4 border-gray-300 pl-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Spoglio degli articoli") ?>
                <span class="text-xs font-normal text-gray-400 normal-case ml-1"><?= __("(salvato insieme al fascicolo)") ?></span>
            </h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" id="emt-spoglio-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Titolo") ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Autori") ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Pagg. da–a") ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipo") ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="emt-spoglio-body">
                        <?php foreach ($articoli as $art): ?>
                            <tr class="emt-art-row">
                                <td class="px-4 py-2">
                                    <input type="text" name="art_titolo[]" maxlength="500"
                                           value="<?= $e($art['titolo']) ?>"
                                           class="form-input text-sm">
                                    <input type="text" name="art_keywords[]" maxlength="500"
                                           value="<?= $e($art['keywords'] ?? '') ?>"
                                           placeholder="<?= $e(__('Parole chiave (opzionali)')) ?>"
                                           class="form-input text-sm mt-1">
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" name="art_autori[]" maxlength="500"
                                           value="<?= $e($art['autori'] ?? '') ?>"
                                           class="form-input text-sm">
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <input type="number" name="art_pag_da[]" min="0" max="32767"
                                           value="<?= $e($art['pagina_inizio'] ?? '') ?>"
                                           class="form-input text-sm w-20 inline-block">
                                    –
                                    <input type="number" name="art_pag_a[]" min="0" max="32767"
                                           value="<?= $e($art['pagina_fine'] ?? '') ?>"
                                           class="form-input text-sm w-20 inline-block">
                                </td>
                                <td class="px-4 py-2">
                                    <select name="art_tipo[]" class="form-input text-sm">
                                        <?php foreach ($tipoArticoloLabels as $value => $label): ?>
                                            <option value="<?= $e($value) ?>" <?= ((string) ($art['tipo'] ?? 'articolo')) === $value ? 'selected' : '' ?>>
                                                <?= $e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 text-right text-sm whitespace-nowrap">
                                    <button type="button" class="text-red-600 hover:underline emt-art-remove"><?= __('rimuovi') ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                <?= __("Le righe con titolo vuoto vengono ignorate al salvataggio.") ?>
            </p>
            <button type="button" id="emt-art-add" class="btn-secondary text-sm mt-2">
                <?= __("Aggiungi articolo") ?>
            </button>

            <template id="emt-art-template">
                <tr class="emt-art-row">
                    <td class="px-4 py-2">
                        <input type="text" name="art_titolo[]" maxlength="500" value=""
                               class="form-input text-sm">
                        <input type="text" name="art_keywords[]" maxlength="500" value=""
                               placeholder="<?= $e(__('Parole chiave (opzionali)')) ?>"
                               class="form-input text-sm mt-1">
                    </td>
                    <td class="px-4 py-2">
                        <input type="text" name="art_autori[]" maxlength="500" value=""
                               class="form-input text-sm">
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">
                        <input type="number" name="art_pag_da[]" min="0" max="32767" value=""
                               class="form-input text-sm w-20 inline-block">
                        –
                        <input type="number" name="art_pag_a[]" min="0" max="32767" value=""
                               class="form-input text-sm w-20 inline-block">
                    </td>
                    <td class="px-4 py-2">
                        <select name="art_tipo[]" class="form-input text-sm">
                            <?php foreach ($tipoArticoloLabels as $value => $label): ?>
                                <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="px-4 py-2 text-right text-sm whitespace-nowrap">
                        <button type="button" class="text-red-600 hover:underline emt-art-remove"><?= __('rimuovi') ?></button>
                    </td>
                </tr>
            </template>
        </div>

        <div class="flex items-center justify-end space-x-3 pt-4 border-t">
            <a href="<?= $issuesUrl ?>"
               class="btn-secondary">
                <?= __("Annulla") ?>
            </a>
            <button type="submit"
                    class="btn-primary">
                <?= __("Salva fascicolo") ?>
            </button>
        </div>
    </form>

    <div class="mt-4 text-right">
        <form method="POST" action="<?= $deleteAction ?>" class="inline"
              onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo fascicolo e il suo spoglio?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" class="text-red-600 hover:underline text-sm">
                <?= __("Elimina fascicolo") ?>
            </button>
        </form>
    </div>
</div>

<script>
// Spoglio: add/remove article rows (vanilla JS, no libraries).
(function () {
    var body = document.getElementById('emt-spoglio-body');
    var tpl = document.getElementById('emt-art-template');
    var addBtn = document.getElementById('emt-art-add');
    if (!body || !tpl || !addBtn) {
        return;
    }
    addBtn.addEventListener('click', function () {
        body.appendChild(tpl.content.cloneNode(true));
    });
    document.getElementById('emt-spoglio-table').addEventListener('click', function (ev) {
        var btn = ev.target.closest('.emt-art-remove');
        if (!btn) {
            return;
        }
        var row = btn.closest('tr.emt-art-row');
        if (row) {
            row.remove();
        }
    });
})();
</script>
