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
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.2.4')) ?>">
<div id="emeroteca-admin-issues" class="emeroteca-admin">
    <header class="emt-page-header">
        <nav aria-label="breadcrumb" class="text-sm text-gray-500 mb-4">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp; <?= $e($testata['titolo']) ?>
        </nav>
        <div class="emt-page-header__main">
            <div class="min-w-0">
                <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                    <?php if ($logoSrc !== ''): ?>
                        <img src="<?= $e($logoSrc) ?>" alt="" class="emt-title-logo">
                    <?php else: ?>
                        <i class="fas fa-newspaper text-gray-600" aria-hidden="true"></i>
                    <?php endif; ?>
                    <span><?= $e($testata['titolo']) ?></span>
                </h1>
                <?php if (!empty($testata['sottotitolo'])): ?>
                    <p class="text-gray-600 mt-2"><?= $e($testata['sottotitolo']) ?></p>
                <?php endif; ?>
                <div class="emt-meta-line">
                    <?php if (!empty($testata['issn'])): ?>
                        <span class="font-mono"><?= __('ISSN') ?> <?= $e($testata['issn']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($testata['editore_nome'])): ?>
                        <span><?= $e($testata['editore_nome']) ?></span>
                    <?php endif; ?>
                    <?php if ($periodicita !== ''): ?>
                        <span><?= $e($periodicitaLabels[$periodicita] ?? $periodicita) ?></span>
                    <?php endif; ?>
                    <span><strong><?= __('Consistenza:') ?></strong> <?= $e($consistenza) ?></span>
                </div>
            </div>
            <a href="<?= $e(url('/admin/periodicals/edit/' . $testataId)) ?>"
               class="btn-secondary inline-flex items-center gap-2 text-sm">
                <i class="fas fa-pen" aria-hidden="true"></i>
                <?= __('Modifica testata') ?>
            </a>
        </div>
    </header>

    <!-- ── Quick actions: add annata / bulk series / kardex ────────── -->
    <section class="card emt-actions-panel mb-6" aria-label="<?= $e(__('Azioni')) ?>">
        <div class="card-header">
            <h2 class="form-section-title flex items-center gap-2">
                <i class="fas fa-bolt text-gray-600" aria-hidden="true"></i>
                <?= __('Azioni') ?>
            </h2>
        </div>
        <form method="POST" action="<?= $manageUrl ?>"
              class="emt-quick-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_annata">
            <h3><?= __('Aggiungi annata') ?></h3>
            <div class="emt-inline-fields">
                <div class="emt-field--year">
                    <label for="ann-anno" class="form-label"><?= __('Anno') ?> *</label>
                    <input id="ann-anno" type="number" name="anno" min="1400" max="2100" required
                           value="<?= $currentYear ?>" class="form-input">
                </div>
                <div class="emt-field--volume">
                    <label for="ann-volume" class="form-label"><?= __('Volume') ?></label>
                    <input id="ann-volume" type="text" name="volume" maxlength="50" class="form-input">
                </div>
                <label class="emt-checkbox-label">
                    <input type="checkbox" name="rilegata" value="1" class="form-checkbox">
                    <span><?= __('Rilegata') ?></span>
                </label>
                <button type="submit" class="btn-primary text-sm"><?= __('Aggiungi annata') ?></button>
            </div>
        </form>

        <form method="POST" action="<?= $bulkUrl ?>"
              class="emt-quick-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <h3><?= __('Crea serie') ?></h3>
            <div class="emt-inline-fields">
                <div class="emt-field--year">
                    <label for="blk-anno" class="form-label"><?= __('Anno') ?> *</label>
                    <input id="blk-anno" type="number" name="anno" min="1400" max="2100" required
                           value="<?= $currentYear ?>" class="form-input">
                </div>
                <div class="emt-field--number">
                    <label for="blk-da" class="form-label"><?= __('Dal n.') ?> *</label>
                    <input id="blk-da" type="number" name="numero_da" min="1" required class="form-input">
                </div>
                <div class="emt-field--number">
                    <label for="blk-a" class="form-label"><?= __('Al n.') ?> *</label>
                    <input id="blk-a" type="number" name="numero_a" min="1" required class="form-input">
                </div>
                <button type="submit" class="btn-primary text-sm"><?= __('Crea serie') ?></button>
            </div>
        </form>

        <form method="POST" action="<?= $kardexUrl ?>"
              class="emt-quick-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <h3><?= __('Kardex: genera attesi') ?></h3>
            <div class="emt-inline-fields">
                <div class="emt-field--year">
                    <label for="krd-anno" class="form-label"><?= __('Anno') ?> *</label>
                    <input id="krd-anno" type="number" name="anno" min="1400" max="2100" required
                           value="<?= $currentYear ?>" class="form-input" <?= $kardexKnown ? '' : 'disabled' ?>>
                </div>
                <button type="submit" class="btn-secondary text-sm" <?= $kardexKnown ? '' : 'disabled' ?>>
                    <?= __('Kardex: genera attesi') ?>
                </button>
                <?php if (!$kardexKnown): ?>
                    <p class="text-xs text-gray-500">
                        <?= __('Disponibile solo con una periodicità nota (non irregolare).') ?>
                    </p>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if (empty($annate)): ?>
        <div class="emt-notice" role="status">
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
            <details class="card emt-year mb-6" open>
                <summary class="cursor-pointer text-sm font-medium text-gray-700 flex flex-wrap items-center gap-3">
                    <span class="text-base font-semibold text-gray-900"><?= (int) $annata['anno'] ?></span>
                    <?php if (!empty($annata['volume'])): ?>
                        <span class="text-gray-500"><?= __('vol.') ?> <?= $e($annata['volume']) ?></span>
                    <?php endif; ?>
                    <?php if ((int) ($annata['rilegata'] ?? 0) === 1): ?>
                        <span class="emt-bound-label"><i class="fas fa-book" aria-hidden="true"></i><?= __('Rilegata') ?></span>
                    <?php endif; ?>
                    <span class="text-xs text-gray-500"><?= sprintf(__('%d fascicoli'), count($fascicoli)) ?></span>
                </summary>
                <div class="emt-year__body">
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
                        <div class="emt-issue-grid">
                            <?php foreach ($fascicoli as $f): ?>
                                <?php
                                $fid = (int) $f['id'];
                                $fUrl = $e(url('/admin/periodicals/issue/' . $fid));
                                $fStato = (string) ($f['stato'] ?? 'posseduto');
                                $cover = (string) ($f['copertina_url'] ?? '');
                                $coverSrc = $cover === '' ? '' : (str_starts_with($cover, '/') ? url($cover) : $cover);
                                ?>
                                <article class="emt-issue-tile">
                                    <a href="<?= $fUrl ?>" class="emt-issue-link">
                                        <?php if ($coverSrc !== ''): ?>
                                            <img src="<?= $e($coverSrc) ?>" alt="<?= $e(__('Copertina n.')) ?> <?= $e($f['numero']) ?>"
                                                 class="emt-issue-cover">
                                        <?php else: ?>
                                            <div class="emt-issue-cover emt-issue-cover--empty">
                                                <i class="fas fa-newspaper text-gray-600"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="emt-issue-caption">
                                            <div class="emt-issue-caption__top">
                                                <strong><?= __('n.') ?> <?= $e($f['numero']) ?></strong>
                                                <span class="emt-status emt-status--<?= $e($fStato) ?>">
                                                    <i aria-hidden="true"></i><?= $e($statoLabels[$fStato] ?? $fStato) ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($f['data_pubblicazione'])): ?>
                                                <span class="emt-issue-date"><?= $e($f['data_pubblicazione']) ?></span>
                                            <?php elseif (!empty($f['data_copertina'])): ?>
                                                <span class="emt-issue-date"><?= $e($f['data_copertina']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                    <?php if ($fStato === 'atteso'): ?>
                                        <form method="POST" action="<?= $manageUrl ?>" class="emt-receive-form">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="receive_issue">
                                            <input type="hidden" name="fascicolo_id" value="<?= $fid ?>">
                                            <button type="submit" class="text-gray-700 hover:underline text-xs">
                                                <?= __('Segna ricevuto') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $manageUrl ?>" class="emt-add-issue">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="add_fascicolo">
                        <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                        <div class="emt-field--number">
                            <label for="fsc-num-<?= $annataId ?>" class="form-label"><?= __('Numero') ?> *</label>
                            <input id="fsc-num-<?= $annataId ?>" type="text" name="numero" maxlength="50" required
                                   class="form-input">
                        </div>
                        <div class="emt-field--date">
                            <label for="fsc-data-<?= $annataId ?>" class="form-label"><?= __('Data di pubblicazione') ?></label>
                            <input id="fsc-data-<?= $annataId ?>" type="date" name="data_pubblicazione"
                                   class="form-input">
                        </div>
                        <div class="emt-field--status">
                            <label for="fsc-stato-<?= $annataId ?>" class="form-label"><?= __('Stato') ?></label>
                            <select id="fsc-stato-<?= $annataId ?>" name="stato" class="form-input">
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
