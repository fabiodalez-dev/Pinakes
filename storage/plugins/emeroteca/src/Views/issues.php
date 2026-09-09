<?php
/**
 * Emeroteca — annate + fascicoli management for one testata.
 *
 * Chrome copied from the Archives admin views (tables/cards/buttons)
 * and from app/Views/autori patterns.
 *
 * @var array<string, mixed> $testata
 * @var array<int, array<string, mixed>> $annate   each with ['fascicoli' => list<row>]
 *      plus 'n_fascicoli'/'n_attesi' counters; only the open annata has
 *      its fascicoli loaded (lazy per annata, review #140)
 * @var int|null $open_annata_id   id of the annata rendered expanded
 * @var string $consistenza
 * @var array<int, string>|null $collocazioni   mensola id => label (may be empty)
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$testataId = (int) $testata['id'];
$openAnnataId = (int) ($open_annata_id ?? 0);
$manageUrl = $e(url('/admin/periodicals/' . $testataId . '/issues'));
$bulkUrl   = $e(url('/admin/periodicals/' . $testataId . '/issues/bulk'));
$kardexUrl = $e(url('/admin/periodicals/' . $testataId . '/kardex/generate'));
$labelsUrl = $e(url('/admin/periodicals/' . $testataId . '/issues/labels'));
$csrf      = $e(\App\Support\Csrf::ensureToken());
// Camera scanner: the CORE bundle (self-hosted zxing-wasm, copy-tracking
// #238). Included only when it is actually present, so an install without
// the built asset degrades to the "type or paste the code" field instead of
// requesting a 404 script.
$scannerPartial = __DIR__ . '/../../../../../app/Views/partials/copy-scanner-i18n.php';
$scannerBundle  = __DIR__ . '/../../../../../public/assets/copy-scanner.bundle.js';
$hasScanner     = is_file($scannerPartial) && is_file($scannerBundle);

// Possession states (1.4.0): physical condition moved to its own field, and
// the Kardex added 'reclamato'/'scartato'.
$statoLabels = [
    'posseduto' => __('Posseduto'),
    'mancante'  => __('Mancante'),
    'atteso'    => __('Atteso'),
    'smarrito'  => __('Smarrito'),
    'reclamato' => __('Reclamato'),
    'scartato'  => __('Scartato'),
];
// The stylesheet ships dot colours for the pre-1.4.0 states only. Rather than
// emit an unstyled modifier, the two new states borrow an existing one:
// 'reclamato' the warning amber of in_restauro, 'scartato' the neutral grey
// of dismessa. Only classes already present in emeroteca.css are used.
$statoDotClass = [
    'posseduto' => 'posseduto',
    'mancante'  => 'mancante',
    'atteso'    => 'atteso',
    'smarrito'  => 'smarrito',
    'reclamato' => 'in_restauro',
    'scartato'  => 'dismessa',
];
$collocazioni = $collocazioni ?? [];
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
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
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
            <div class="flex flex-wrap items-center gap-2">
                <a href="<?= $e(url('/admin/periodicals/export/kbart') . '?testata=' . $testataId) ?>"
                   class="btn-secondary inline-flex items-center gap-2 text-sm"
                   title="<?= $e(__('Esporta le consistenze in formato KBART (TSV) per i cataloghi collettivi')) ?>">
                    <i class="fas fa-file-export" aria-hidden="true"></i>
                    <?= __('Esporta KBART') ?>
                </a>
                <a href="<?= $e(url('/admin/periodicals/export/acnp') . '?testata=' . $testataId) ?>"
                   class="btn-secondary inline-flex items-center gap-2 text-sm"
                   title="<?= $e(__('Esporta le consistenze in formato ACNP (CSV)')) ?>">
                    <i class="fas fa-file-csv" aria-hidden="true"></i>
                    <?= __('Esporta ACNP') ?>
                </a>
                <a href="<?= $e(url('/admin/periodicals/edit/' . $testataId)) ?>"
                   class="btn-secondary inline-flex items-center gap-2 text-sm">
                    <i class="fas fa-pen" aria-hidden="true"></i>
                    <?= __('Modifica testata') ?>
                </a>
            </div>
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
                <div class="emt-field--volume">
                    <label for="ann-serie" class="form-label"><?= __('Serie') ?></label>
                    <input id="ann-serie" type="text" name="serie" maxlength="50" class="form-input"
                           placeholder="<?= $e(__('es. nuova serie')) ?>">
                </div>
                <?php if ($collocazioni !== []): ?>
                    <div class="emt-field--status">
                        <label for="ann-collocazione" class="form-label"><?= __('Collocazione') ?></label>
                        <select id="ann-collocazione" name="collocazione_id" class="form-input">
                            <option value=""><?= __('Nessuna') ?></option>
                            <?php foreach ($collocazioni as $mensolaId => $mensolaLabel): ?>
                                <option value="<?= (int) $mensolaId ?>"><?= $e($mensolaLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <label class="emt-checkbox-label">
                    <input type="checkbox" name="rilegata" value="1" class="form-checkbox">
                    <span><?= __('Rilegata') ?></span>
                </label>
                <button type="submit" class="btn-primary text-sm"><?= __('Aggiungi annata') ?></button>
            </div>
            <div class="emt-inline-fields">
                <div class="emt-field--volume" style="flex:1 1 100%">
                    <label for="ann-consistenza" class="form-label"><?= __('Consistenza dichiarata') ?></label>
                    <input id="ann-consistenza" type="text" name="consistenza_dichiarata" maxlength="255"
                           class="form-input" placeholder="<?= $e(__('es. 1998: nn. 1-12, manca il n. 7')) ?>">
                </div>
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

        <!--
            Kardex scan. The lookup is a read-only GET; receiving reuses the
            receive_issue action of this same page, so there is one single
            code path that marks an issue as held.
        -->
        <div class="emt-quick-form" id="emt-scan-panel">
            <h3><?= __('Scansiona un fascicolo') ?></h3>
            <div class="emt-inline-fields">
                <div class="emt-field--volume">
                    <label for="emt-scan-code" class="form-label"><?= __('Codice a barre') ?></label>
                    <input id="emt-scan-code" type="text" inputmode="numeric" maxlength="18"
                           class="form-input" autocomplete="off"
                           placeholder="<?= $e(__('EAN-13 del fascicolo o della testata')) ?>">
                </div>
                <?php if ($hasScanner): ?>
                    <button type="button" class="btn-secondary text-sm"
                            data-copy-scan data-copy-scan-target="emt-scan-code">
                        <i class="fas fa-camera mr-1" aria-hidden="true"></i>
                        <?= __('Scansiona') ?>
                    </button>
                <?php endif; ?>
                <button type="button" id="emt-scan-lookup" class="btn-secondary text-sm">
                    <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i>
                    <?= __('Cerca il codice') ?>
                </button>
            </div>
            <p id="emt-scan-result" class="emt-scan-result text-sm" role="status" aria-live="polite" hidden></p>
            <div class="emt-inline-fields">
                <a id="emt-scan-open" class="text-gray-700 hover:underline text-xs" hidden>
                    <?= __('Apri il fascicolo') ?>
                </a>
                <form id="emt-scan-receive" method="POST" action="<?= $manageUrl ?>" hidden>
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="receive_issue">
                    <input type="hidden" name="fascicolo_id" value="">
                    <button type="submit" class="btn-primary text-sm">
                        <?= __('Segna ricevuto') ?>
                    </button>
                </form>
            </div>
            <?php if (!$hasScanner): ?>
                <p class="text-xs text-gray-500">
                    <?= __('Fotocamera non disponibile su questa installazione: digita o incolla il codice a barre.') ?>
                </p>
            <?php endif; ?>
        </div>
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
            $nFascicoli = (int) ($annata['n_fascicoli'] ?? count($fascicoli));
            $nAttesi = (int) ($annata['n_attesi'] ?? 0);
            $openHref = $e(url('/admin/periodicals/' . $testataId . '/issues')
                . '?annata=' . $annataId . '#annata-' . $annataId);
            ?>
            <?php if ($annataId !== $openAnnataId): ?>
            <!-- Collapsed annata: fascicoli load server-side via ?annata=ID -->
            <details id="annata-<?= $annataId ?>" class="card emt-year mb-6">
                <summary class="cursor-pointer text-sm font-medium text-gray-700">
                    <a href="<?= $openHref ?>" class="flex flex-wrap items-center gap-3"
                       title="<?= $e(__('Mostra i fascicoli di questa annata')) ?>">
                        <span class="text-base font-semibold text-gray-900"><?= (int) $annata['anno'] ?></span>
                        <?php if (!empty($annata['volume'])): ?>
                            <span class="text-gray-500"><?= __('vol.') ?> <?= $e($annata['volume']) ?></span>
                        <?php endif; ?>
                        <?php if ((int) ($annata['rilegata'] ?? 0) === 1): ?>
                            <span class="emt-bound-label"><i class="fas fa-book" aria-hidden="true"></i><?= __('Rilegata') ?></span>
                        <?php endif; ?>
                        <span class="text-xs text-gray-500"><?= sprintf(__('%d fascicoli'), $nFascicoli) ?></span>
                    </a>
                </summary>
                <div class="emt-year__body">
                    <p class="text-sm text-gray-500">
                        <a href="<?= $openHref ?>" class="hover:underline">
                            <?= __('Mostra i fascicoli di questa annata') ?>
                        </a>
                    </p>
                </div>
            </details>
            <?php continue; ?>
            <?php endif; ?>
            <details id="annata-<?= $annataId ?>" class="card emt-year mb-6" open>
                <summary class="cursor-pointer text-sm font-medium text-gray-700 flex flex-wrap items-center gap-3">
                    <span class="text-base font-semibold text-gray-900"><?= (int) $annata['anno'] ?></span>
                    <?php if (!empty($annata['volume'])): ?>
                        <span class="text-gray-500"><?= __('vol.') ?> <?= $e($annata['volume']) ?></span>
                    <?php endif; ?>
                    <?php if ((int) ($annata['rilegata'] ?? 0) === 1): ?>
                        <span class="emt-bound-label"><i class="fas fa-book" aria-hidden="true"></i><?= __('Rilegata') ?></span>
                    <?php endif; ?>
                    <span class="text-xs text-gray-500"><?= sprintf(__('%d fascicoli'), $nFascicoli) ?></span>
                </summary>
                <div class="emt-year__body">
                    <form method="POST" action="<?= $manageUrl ?>" class="emt-quick-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="update_annata">
                        <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                        <h3><?= __('Dati dell\'annata') ?></h3>
                        <div class="emt-inline-fields">
                            <div class="emt-field--volume">
                                <label for="ann-serie-<?= $annataId ?>" class="form-label"><?= __('Serie') ?></label>
                                <input id="ann-serie-<?= $annataId ?>" type="text" name="serie" maxlength="50"
                                       value="<?= $e($annata['serie'] ?? '') ?>" class="form-input">
                            </div>
                            <?php if ($collocazioni !== []): ?>
                                <div class="emt-field--status">
                                    <label for="ann-coll-<?= $annataId ?>" class="form-label"><?= __('Collocazione') ?></label>
                                    <select id="ann-coll-<?= $annataId ?>" name="collocazione_id" class="form-input">
                                        <option value=""><?= __('Nessuna') ?></option>
                                        <?php foreach ($collocazioni as $mensolaId => $mensolaLabel): ?>
                                            <option value="<?= (int) $mensolaId ?>"
                                                <?= (int) ($annata['collocazione_id'] ?? 0) === (int) $mensolaId ? 'selected' : '' ?>>
                                                <?= $e($mensolaLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <label class="emt-checkbox-label">
                                <input type="checkbox" name="rilegata" value="1" class="form-checkbox"
                                    <?= (int) ($annata['rilegata'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span><?= __('Rilegata') ?></span>
                            </label>
                            <button type="submit" class="btn-secondary text-sm"><?= __('Salva annata') ?></button>
                        </div>
                        <div class="emt-inline-fields">
                            <div class="emt-field--volume" style="flex:1 1 100%">
                                <label for="ann-cons-<?= $annataId ?>" class="form-label"><?= __('Consistenza dichiarata') ?></label>
                                <input id="ann-cons-<?= $annataId ?>" type="text" name="consistenza_dichiarata" maxlength="255"
                                       value="<?= $e($annata['consistenza_dichiarata'] ?? '') ?>" class="form-input">
                            </div>
                        </div>
                    </form>
                    <?php if ($nAttesi > 0): ?>
                        <div class="mb-3 text-right">
                            <form method="POST" action="<?= $manageUrl ?>" class="inline"
                                  onsubmit="return confirm(<?= $e(json_encode(__('Sollecitare al fornitore tutti i fascicoli attesi già scaduti di questa annata?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="claim_overdue">
                                <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                                <button type="submit" class="text-gray-700 hover:underline text-xs">
                                    <?= __('Sollecita gli attesi scaduti') ?>
                                </button>
                            </form>
                            <form method="POST" action="<?= $manageUrl ?>" class="inline ml-3"
                                  onsubmit="return confirm(<?= $e(json_encode(__('Marcare come mancanti tutti i fascicoli ancora attesi di questa annata?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="mark_missing">
                                <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                                <button type="submit" class="text-red-600 hover:underline text-xs">
                                    <?= sprintf(__('Marca %d attesi come mancanti'), $nAttesi) ?>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($fascicoli)): ?>
                        <p class="text-sm text-gray-500 mb-3"><?= __('Nessun fascicolo in questa annata.') ?></p>
                    <?php else: ?>
                        <!--
                            Label batch. The tiles already contain the
                            receive/claim POST forms, so the checkboxes join
                            this one through the HTML `form` attribute
                            instead of nesting forms.
                        -->
                        <?php $labelsFormId = 'emt-labels-' . $annataId; ?>
                        <form id="<?= $e($labelsFormId) ?>" method="POST" action="<?= $labelsUrl ?>"
                              target="_blank" class="emt-inline-fields mb-3">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                            <label class="emt-checkbox-label">
                                <input type="checkbox" class="form-checkbox"
                                       data-emt-select-all="<?= $e($labelsFormId) ?>">
                                <span><?= __('Seleziona tutti') ?></span>
                            </label>
                            <button type="submit" class="btn-secondary text-sm">
                                <i class="fas fa-tags mr-1" aria-hidden="true"></i>
                                <?= __('Stampa etichette') ?>
                            </button>
                        </form>
                        <div class="emt-issue-grid">
                            <?php foreach ($fascicoli as $f): ?>
                                <?php
                                $fid = (int) $f['id'];
                                $fUrl = $e(url('/admin/periodicals/issue/' . $fid));
                                $fStato = (string) ($f['stato'] ?? 'posseduto');
                                $nReclami = (int) ($f['n_reclami'] ?? 0);
                                $reclamatoIl = (string) ($f['reclamato_il'] ?? '');
                                $cover = (string) ($f['copertina_url'] ?? '');
                                $coverSrc = $cover === '' ? '' : (str_starts_with($cover, '/') ? url($cover) : $cover);
                                ?>
                                <article class="emt-issue-tile">
                                    <label class="emt-checkbox-label emt-issue-select">
                                        <input type="checkbox" name="ids[]" value="<?= $fid ?>"
                                               form="<?= $e($labelsFormId) ?>" class="form-checkbox">
                                        <span class="sr-only">
                                            <?= $e(sprintf(__('Seleziona il fascicolo n. %s per la stampa etichette'), (string) $f['numero'])) ?>
                                        </span>
                                    </label>
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
                                                <span class="emt-status emt-status--<?= $e($statoDotClass[$fStato] ?? $fStato) ?>">
                                                    <i aria-hidden="true"></i><?= $e($statoLabels[$fStato] ?? $fStato) ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($f['data_pubblicazione'])): ?>
                                                <span class="emt-issue-date"><?= $e($f['data_pubblicazione']) ?></span>
                                            <?php elseif (!empty($f['data_copertina'])): ?>
                                                <span class="emt-issue-date"><?= $e($f['data_copertina']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($nReclami > 0): ?>
                                                <span class="emt-issue-date"
                                                      title="<?= $e($reclamatoIl !== '' ? sprintf(__('Ultimo sollecito: %s'), $reclamatoIl) : __('Solleciti inviati al fornitore')) ?>">
                                                    <i class="fas fa-bell" aria-hidden="true"></i>
                                                    <?= sprintf(__('solleciti: %d'), $nReclami) ?><?= $reclamatoIl !== '' ? ' · ' . $e($reclamatoIl) : '' ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                    <?php if ($fStato === 'atteso' || $fStato === 'reclamato'): ?>
                                        <form method="POST" action="<?= $manageUrl ?>" class="emt-receive-form">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="receive_issue">
                                            <input type="hidden" name="fascicolo_id" value="<?= $fid ?>">
                                            <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                                            <button type="submit" class="text-gray-700 hover:underline text-xs">
                                                <?= __('Segna ricevuto') ?>
                                            </button>
                                        </form>
                                        <form method="POST" action="<?= $manageUrl ?>" class="emt-receive-form">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="claim_issue">
                                            <input type="hidden" name="fascicolo_id" value="<?= $fid ?>">
                                            <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                                            <button type="submit" class="text-gray-700 hover:underline text-xs">
                                                <?= __('Sollecita al fornitore') ?>
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
<script>
    window.emerotecaScan = {
        lookupUrl: <?= json_encode(url('/admin/periodicals/scan-lookup'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        testataId: <?= $testataId ?>,
        i18n: {
            empty: <?= json_encode(__('Inserisci o scansiona un codice a barre.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            searching: <?= json_encode(__('Ricerca in corso...'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            notFound: <?= json_encode(__('Nessuna corrispondenza per questo codice a barre.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            error: <?= json_encode(__('Errore durante la ricerca del codice.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        }
    };
</script>
<?php if ($hasScanner): ?>
    <?php include $scannerPartial; ?>
    <script src="<?= $e(assetUrl('copy-scanner.bundle.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-scan.js?v=1.4.0')) ?>" defer></script>
