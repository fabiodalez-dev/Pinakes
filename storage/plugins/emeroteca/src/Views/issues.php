<?php
/**
 * Emeroteca — annate + fascicoli management for one testata.
 *
 * Chrome copied from the Archives admin views (tables/cards/buttons)
 * and from app/Views/autori patterns.
 *
 * @var array<string, mixed> $testata
 * @var array<int, array<string, mixed>> $annate   each with ['fascicoli' => list<row>]
 * @var string $consistenza
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$testataId = (int) $testata['id'];
$manageUrl = $e(url('/admin/periodicals/' . $testataId . '/issues'));
$bulkUrl   = $e(url('/admin/periodicals/' . $testataId . '/issues/bulk'));
$kardexUrl = $e(url('/admin/periodicals/' . $testataId . '/kardex/generate'));
$csrf      = $e(\App\Support\Csrf::ensureToken());

$statoLabels = [
    'posseduto'   => __('Posseduto'),
    'mancante'    => __('Mancante'),
    'danneggiato' => __('Danneggiato'),
    'in_restauro' => __('In restauro'),
    'smarrito'    => __('Smarrito'),
    'atteso'      => __('Atteso'),
];
$statoBadge = [
    'posseduto'   => 'bg-green-100 text-green-800',
    'mancante'    => 'bg-red-100 text-red-800',
    'danneggiato' => 'bg-orange-100 text-orange-800',
    'in_restauro' => 'bg-yellow-100 text-yellow-800',
    'smarrito'    => 'bg-gray-100 text-gray-800',
    'atteso'      => 'bg-blue-100 text-blue-800',
];
$periodicitaLabels = [
    'quotidiano'   => __('Quotidiano'),
    'settimanale'  => __('Settimanale'),
    'quindicinale' => __('Quindicinale'),
    'mensile'      => __('Mensile'),
    'bimestrale'   => __('Bimestrale'),
    'trimestrale'  => __('Trimestrale'),
    'semestrale'   => __('Semestrale'),
    'annuale'      => __('Annuale'),
    'irregolare'   => __('Irregolare'),
];
$periodicita = (string) ($testata['periodicita'] ?? '');
$kardexKnown = $periodicita !== '' && $periodicita !== 'irregolare';
$logo = (string) ($testata['logo_url'] ?? '');
$logoSrc = $logo === '' ? '' : (str_starts_with($logo, '/') ? url($logo) : $logo);
$currentYear = (int) date('Y');
?>
<div class="p-6 max-w-7xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp; <?= $e($testata['titolo']) ?>
        </nav>
        <div class="bg-white shadow rounded-lg p-6">
            <div class="flex items-start">
                <?php if ($logoSrc !== ''): ?>
                    <img src="<?= $e($logoSrc) ?>" alt="<?= $e($testata['titolo']) ?>"
                         class="w-16 h-16 rounded object-cover mr-4">
                <?php else: ?>
                    <div class="flex items-center justify-center w-16 h-16 rounded-lg bg-gray-100 mr-4">
                        <i class="fas fa-newspaper text-gray-600"></i>
                    </div>
                <?php endif; ?>
                <div class="flex-1">
                    <h1 class="text-2xl font-bold text-gray-900"><?= $e($testata['titolo']) ?></h1>
                    <?php if (!empty($testata['sottotitolo'])): ?>
                        <p class="text-sm text-gray-600"><?= $e($testata['sottotitolo']) ?></p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-500 mt-1">
                        <?php if (!empty($testata['issn'])): ?>
                            <span class="font-mono text-xs"><?= __('ISSN') ?> <?= $e($testata['issn']) ?></span> ·
                        <?php endif; ?>
                        <?php if (!empty($testata['editore_nome'])): ?>
                            <?= $e($testata['editore_nome']) ?> ·
                        <?php endif; ?>
                        <?php if ($periodicita !== ''): ?>
                            <?= $e($periodicitaLabels[$periodicita] ?? $periodicita) ?> ·
                        <?php endif; ?>
                        <strong><?= __('Consistenza:') ?></strong> <?= $e($consistenza) ?>
                    </p>
                </div>
                <a href="<?= $e(url('/admin/periodicals/edit/' . $testataId)) ?>"
                   class="btn-secondary inline-flex items-center text-sm">
                    <?= __('Modifica testata') ?>
                </a>
            </div>
        </div>
    </div>

    <!-- ── Quick actions: add annata / bulk series / kardex ────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <form method="POST" action="<?= $manageUrl ?>"
              class="bg-white shadow rounded-lg p-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_annata">
            <div class="min-w-[100px]">
                <label for="ann-anno" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Anno') ?> *</label>
                <input id="ann-anno" type="number" name="anno" min="1400" max="2100" required
                       value="<?= $currentYear ?>"
                       class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div class="min-w-[90px]">
                <label for="ann-volume" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Volume') ?></label>
                <input id="ann-volume" type="text" name="volume" maxlength="50"
                       class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <label class="inline-flex items-center text-sm text-gray-700 mb-1">
                <input type="checkbox" name="rilegata" value="1" class="rounded border-gray-300 mr-1.5">
                <?= __('Rilegata') ?>
            </label>
            <button type="submit" class="btn-primary text-sm"><?= __('Aggiungi annata') ?></button>
        </form>

        <form method="POST" action="<?= $bulkUrl ?>"
              class="bg-white shadow rounded-lg p-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="min-w-[90px]">
                <label for="blk-anno" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Anno') ?> *</label>
                <input id="blk-anno" type="number" name="anno" min="1400" max="2100" required
                       value="<?= $currentYear ?>"
                       class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div class="min-w-[70px]">
                <label for="blk-da" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Dal n.') ?> *</label>
                <input id="blk-da" type="number" name="numero_da" min="1" required
                       class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div class="min-w-[70px]">
                <label for="blk-a" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Al n.') ?> *</label>
                <input id="blk-a" type="number" name="numero_a" min="1" required
                       class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <button type="submit" class="btn-primary text-sm"><?= __('Crea serie') ?></button>
        </form>

        <form method="POST" action="<?= $kardexUrl ?>"
              class="bg-white shadow rounded-lg p-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="min-w-[90px]">
                <label for="krd-anno" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Anno') ?> *</label>
                <input id="krd-anno" type="number" name="anno" min="1400" max="2100" required
                       value="<?= $currentYear ?>"
                       class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500"
                       <?= $kardexKnown ? '' : 'disabled' ?>>
            </div>
            <button type="submit" class="btn-secondary text-sm" <?= $kardexKnown ? '' : 'disabled' ?>>
                <?= __('Kardex: genera attesi') ?>
            </button>
            <?php if (!$kardexKnown): ?>
                <p class="w-full text-xs text-gray-500 mt-1">
                    <?= __('Disponibile solo con una periodicità nota (non irregolare).') ?>
                </p>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($annate)): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <p class="text-sm text-yellow-800">
                <strong><?= __("Nessuna annata registrata.") ?></strong>
                <?= __("Crea la prima annata per iniziare ad aggiungere fascicoli.") ?>
            </p>
        </div>
    <?php else: ?>
        <?php foreach ($annate as $annata): ?>
            <?php
            $annataId = (int) $annata['id'];
            $fascicoli = $annata['fascicoli'] ?? [];
            $nAttesi = 0;
            foreach ($fascicoli as $f) {
                if (($f['stato'] ?? '') === 'atteso') {
                    $nAttesi++;
                }
            }
            ?>
            <details class="bg-white shadow rounded-lg mb-4" open>
                <summary class="px-4 py-3 cursor-pointer text-sm font-medium text-gray-700 flex flex-wrap items-center gap-2">
                    <span class="text-base font-semibold text-gray-900"><?= (int) $annata['anno'] ?></span>
                    <?php if (!empty($annata['volume'])): ?>
                        <span class="text-gray-500"><?= __('vol.') ?> <?= $e($annata['volume']) ?></span>
                    <?php endif; ?>
                    <?php if ((int) ($annata['rilegata'] ?? 0) === 1): ?>
                        <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-indigo-100 text-indigo-800"><?= __('Rilegata') ?></span>
                    <?php endif; ?>
                    <span class="text-xs text-gray-500"><?= sprintf(__('%d fascicoli'), count($fascicoli)) ?></span>
                </summary>
                <div class="p-4 border-t">
                    <?php if ($nAttesi > 0): ?>
                        <form method="POST" action="<?= $manageUrl ?>" class="mb-3 text-right"
                              onsubmit="return confirm(<?= $e(json_encode(__('Marcare come mancanti tutti i fascicoli ancora attesi di questa annata?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="mark_missing">
                            <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                            <button type="submit" class="text-red-600 hover:underline text-xs">
                                <?= sprintf(__('Marca %d attesi come mancanti'), $nAttesi) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (empty($fascicoli)): ?>
                        <p class="text-sm text-gray-500 mb-3"><?= __('Nessun fascicolo in questa annata.') ?></p>
                    <?php else: ?>
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-4">
                            <?php foreach ($fascicoli as $f): ?>
                                <?php
                                $fid = (int) $f['id'];
                                $fUrl = $e(url('/admin/periodicals/issue/' . $fid));
                                $fStato = (string) ($f['stato'] ?? 'posseduto');
                                $cover = (string) ($f['copertina_url'] ?? '');
                                $coverSrc = $cover === '' ? '' : (str_starts_with($cover, '/') ? url($cover) : $cover);
                                ?>
                                <div class="border border-gray-200 rounded-lg p-2 hover:bg-gray-50">
                                    <a href="<?= $fUrl ?>" class="block">
                                        <?php if ($coverSrc !== ''): ?>
                                            <img src="<?= $e($coverSrc) ?>" alt="<?= $e(__('Copertina n.')) ?> <?= $e($f['numero']) ?>"
                                                 class="w-full h-28 rounded object-cover mb-2">
                                        <?php else: ?>
                                            <div class="flex items-center justify-center w-full h-28 rounded-lg bg-gray-100 mb-2">
                                                <i class="fas fa-newspaper text-gray-600"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="text-sm font-medium text-gray-900 truncate">
                                            <?= __('n.') ?> <?= $e($f['numero']) ?>
                                        </div>
                                        <?php if (!empty($f['data_pubblicazione'])): ?>
                                            <div class="text-xs text-gray-500"><?= $e($f['data_pubblicazione']) ?></div>
                                        <?php elseif (!empty($f['data_copertina'])): ?>
                                            <div class="text-xs text-gray-500"><?= $e($f['data_copertina']) ?></div>
                                        <?php endif; ?>
                                        <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $e($statoBadge[$fStato] ?? 'bg-gray-100 text-gray-800') ?>">
                                            <?= $e($statoLabels[$fStato] ?? $fStato) ?>
                                        </span>
                                    </a>
                                    <?php if ($fStato === 'atteso'): ?>
                                        <form method="POST" action="<?= $manageUrl ?>" class="mt-1.5">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="receive_issue">
                                            <input type="hidden" name="fascicolo_id" value="<?= $fid ?>">
                                            <button type="submit" class="text-blue-600 hover:underline text-xs">
                                                <?= __('Segna ricevuto') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $manageUrl ?>"
                          class="flex flex-wrap items-end gap-3 border-t pt-3">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="add_fascicolo">
                        <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                        <div class="min-w-[90px]">
                            <label for="fsc-num-<?= $annataId ?>" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Numero') ?> *</label>
                            <input id="fsc-num-<?= $annataId ?>" type="text" name="numero" maxlength="50" required
                                   class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div class="min-w-[150px]">
                            <label for="fsc-data-<?= $annataId ?>" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Data di pubblicazione') ?></label>
                            <input id="fsc-data-<?= $annataId ?>" type="date" name="data_pubblicazione"
                                   class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div class="min-w-[130px]">
                            <label for="fsc-stato-<?= $annataId ?>" class="block text-xs font-medium text-gray-600 mb-1"><?= __('Stato') ?></label>
                            <select id="fsc-stato-<?= $annataId ?>" name="stato"
                                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                                <?php foreach ($statoLabels as $value => $label): ?>
                                    <option value="<?= $e($value) ?>" <?= $value === 'posseduto' ? 'selected' : '' ?>><?= $e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-secondary text-sm"><?= __('Aggiungi fascicolo') ?></button>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
