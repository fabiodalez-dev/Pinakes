<?php
/**
 * Emeroteca — annate + fascicoli management for one testata.
 *
 * Chrome copied verbatim from the core book pages (app/Views/libri);
 * only the Kardex tile grid keeps the plugin stylesheet.
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
$currentYear = (int) date('Y');
$metaParts = [];
if (!empty($testata['issn'])) {
    $metaParts[] = __('ISSN') . ' ' . (string) $testata['issn'];
}
if (!empty($testata['editore_nome'])) {
    $metaParts[] = (string) $testata['editore_nome'];
}
if ($periodicita !== '') {
    $metaParts[] = (string) ($periodicitaLabels[$periodicita] ?? $periodicita);
}
$checkboxClass = 'w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500';
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<style>
    /* The Kardex tiles keep the plugin stylesheet, which is scoped to
       .emeroteca-admin: here that scope wraps only the tile grid, so it must
       not bring its own page width and padding. */
    #emeroteca-admin-issues .emeroteca-admin { max-width: none; margin: 0; padding: 0; }
    #emeroteca-admin-issues details.card > summary { list-style: none; }
    #emeroteca-admin-issues details.card > summary::-webkit-details-marker { display: none; }
    @media (min-width: 769px) {
        #emeroteca-admin-issues details.card:not([open]) > summary.card-header { margin-bottom: -1.5rem; border-bottom: 0; }
    }
    #emt-scan-result { margin: .5rem 0; color: #374151; }
    #emt-scan-result.is-ok { color: #166534; }
    #emt-scan-result.is-error { color: #b91c1c; }
</style>
<div id="emeroteca-admin-issues" class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= $e(url('/admin/periodicals')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-newspaper mr-1"></i><?= __('Emeroteca') ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium" aria-current="page">
          <?= $e($testata['titolo']) ?>
        </li>
      </ol>
    </nav>

    <!-- Header with Actions -->
    <div class="mb-6">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div class="min-w-0 md:flex-1">
          <h1 class="text-3xl font-bold text-gray-900"><?= $e($testata['titolo']) ?></h1>
          <?php if (!empty($testata['sottotitolo'])): ?>
            <p class="text-sm text-gray-600 mt-1"><?= $e($testata['sottotitolo']) ?></p>
          <?php endif; ?>
          <?php if ($metaParts !== []): ?>
            <p class="text-sm text-gray-600 mt-1"><?= $e(implode(' · ', $metaParts)) ?></p>
          <?php endif; ?>
          <p class="text-sm text-gray-600 mt-1">
            <span class="font-medium text-gray-900"><?= __('Consistenza:') ?></span> <?= $e($consistenza) ?>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 md:justify-end md:w-1/2">
          <a href="<?= $e(url('/admin/periodicals/export/kbart') . '?testata=' . $testataId) ?>"
             class="btn-secondary"
             title="<?= $e(__('Esporta le consistenze in formato KBART (TSV) per i cataloghi collettivi')) ?>">
            <i class="fas fa-file-export mr-2" aria-hidden="true"></i><?= __('Esporta KBART') ?>
          </a>
          <a href="<?= $e(url('/admin/periodicals/export/acnp') . '?testata=' . $testataId) ?>"
             class="btn-secondary"
             title="<?= $e(__('Esporta le consistenze in formato ACNP (CSV)')) ?>">
            <i class="fas fa-file-csv mr-2" aria-hidden="true"></i><?= __('Esporta ACNP') ?>
          </a>
          <a href="<?= $e(url('/admin/periodicals/articles?testata=' . $testataId)) ?>" class="btn-secondary">
            <i class="fas fa-list-alt mr-2" aria-hidden="true"></i><?= __('Articoli associati alla testata') ?>
          </a>
          <a href="<?= $e(url('/admin/periodicals/articles?destination=' . $testataId)) ?>" class="btn-secondary">
            <i class="fas fa-link mr-2" aria-hidden="true"></i><?= __('Aggiungi articoli esistenti') ?>
          </a>
          <a href="<?= $e(url('/admin/periodicals/edit/' . $testataId)) ?>" class="btn-secondary">
            <i class="fas fa-edit mr-2" aria-hidden="true"></i><?= __('Modifica testata') ?>
          </a>
        </div>
      </div>
    </div>

    <div class="space-y-8">
      <!-- Quick actions: add annata / bulk series / kardex / scan -->
      <section class="card" aria-label="<?= $e(__('Azioni')) ?>">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-bolt text-gray-900" aria-hidden="true"></i>
            <?= __('Azioni') ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <form method="POST" action="<?= $manageUrl ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_annata">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><?= __('Aggiungi annata') ?></h3>
            <div class="form-section">
              <div class="form-grid-3">
                <div>
                  <label for="ann-anno" class="form-label"><?= __('Anno') ?> <span class="text-red-500">*</span></label>
                  <input id="ann-anno" type="number" name="anno" min="1400" max="2100" required
                         value="<?= $currentYear ?>" class="form-input">
                </div>
                <div>
                  <label for="ann-volume" class="form-label"><?= __('Volume') ?></label>
                  <input id="ann-volume" type="text" name="volume" maxlength="50" class="form-input">
                </div>
                <div>
                  <label for="ann-serie" class="form-label"><?= __('Serie') ?></label>
                  <input id="ann-serie" type="text" name="serie" maxlength="50" class="form-input"
                         placeholder="<?= $e(__('es. nuova serie')) ?>">
                </div>
              </div>
              <div class="form-grid-2">
                <?php if ($collocazioni !== []): ?>
                  <div>
                    <label for="ann-collocazione" class="form-label"><?= __('Collocazione') ?></label>
                    <select id="ann-collocazione" name="collocazione_id" class="form-input">
                      <option value=""><?= __('Nessuna') ?></option>
                      <?php foreach ($collocazioni as $mensolaId => $mensolaLabel): ?>
                        <option value="<?= (int) $mensolaId ?>"><?= $e($mensolaLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
                <div>
                  <label for="ann-consistenza" class="form-label"><?= __('Consistenza dichiarata') ?></label>
                  <input id="ann-consistenza" type="text" name="consistenza_dichiarata" maxlength="255"
                         class="form-input" placeholder="<?= $e(__('es. 1998: nn. 1-12, manca il n. 7')) ?>">
                </div>
              </div>
              <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <label class="flex items-center space-x-2 text-sm cursor-pointer">
                  <input type="checkbox" name="rilegata" value="1" class="<?= $checkboxClass ?>">
                  <span class="text-gray-700"><?= __('Rilegata') ?></span>
                </label>
                <button type="submit" class="btn-primary">
                  <i class="fas fa-plus mr-2" aria-hidden="true"></i><?= __('Aggiungi annata') ?>
                </button>
              </div>
            </div>
          </form>

          <form method="POST" action="<?= $bulkUrl ?>" class="pt-6 border-t border-gray-200">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><?= __('Crea serie') ?></h3>
            <div class="form-section">
              <div class="form-grid-3">
                <div>
                  <label for="blk-anno" class="form-label"><?= __('Anno') ?> <span class="text-red-500">*</span></label>
                  <input id="blk-anno" type="number" name="anno" min="1400" max="2100" required
                         value="<?= $currentYear ?>" class="form-input">
                </div>
                <div>
                  <label for="blk-da" class="form-label"><?= __('Dal n.') ?> <span class="text-red-500">*</span></label>
                  <input id="blk-da" type="number" name="numero_da" min="1" required class="form-input">
                </div>
                <div>
                  <label for="blk-a" class="form-label"><?= __('Al n.') ?> <span class="text-red-500">*</span></label>
                  <input id="blk-a" type="number" name="numero_a" min="1" required class="form-input">
                </div>
              </div>
              <div class="flex flex-col sm:flex-row gap-4 justify-end">
                <button type="submit" class="btn-secondary">
                  <i class="fas fa-layer-group mr-2" aria-hidden="true"></i><?= __('Crea serie') ?>
                </button>
              </div>
            </div>
          </form>

          <form method="POST" action="<?= $kardexUrl ?>" class="pt-6 border-t border-gray-200">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><?= __('Kardex: genera attesi') ?></h3>
            <div class="form-grid-2">
              <div>
                <label for="krd-anno" class="form-label"><?= __('Anno') ?> <span class="text-red-500">*</span></label>
                <input id="krd-anno" type="number" name="anno" min="1400" max="2100" required
                       value="<?= $currentYear ?>" class="form-input" <?= $kardexKnown ? '' : 'disabled' ?>>
                <?php if (!$kardexKnown): ?>
                  <p class="text-xs text-gray-500 mt-1">
                    <?= __('Disponibile solo con una periodicità nota (non irregolare).') ?>
                  </p>
                <?php endif; ?>
              </div>
              <div class="flex items-end">
                <button type="submit" class="btn-secondary w-full" <?= $kardexKnown ? '' : 'disabled' ?>>
                  <i class="fas fa-calendar-check mr-2" aria-hidden="true"></i><?= __('Kardex: genera attesi') ?>
                </button>
              </div>
            </div>
          </form>

          <!--
              Kardex scan. The lookup is a read-only GET; receiving reuses the
              receive_issue action of this same page, so there is one single
              code path that marks an issue as held.
          -->
          <div id="emt-scan-panel" class="pt-6 border-t border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><?= __('Scansiona un fascicolo') ?></h3>
            <div class="form-grid-2">
              <div>
                <label for="emt-scan-code" class="form-label"><i class="fas fa-barcode mr-1" aria-hidden="true"></i><?= __('Codice a barre') ?></label>
                <input id="emt-scan-code" type="text" inputmode="numeric" maxlength="18"
                       class="form-input" autocomplete="off"
                       placeholder="<?= $e(__('EAN-13 del fascicolo o della testata')) ?>">
                <?php if (!$hasScanner): ?>
                  <p class="text-xs text-gray-500 mt-1">
                    <?= __('Fotocamera non disponibile su questa installazione: digita o incolla il codice a barre.') ?>
                  </p>
                <?php endif; ?>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 items-end">
                <?php if ($hasScanner): ?>
                  <button type="button" class="btn-secondary"
                          data-copy-scan data-copy-scan-target="emt-scan-code">
                    <i class="fas fa-camera mr-2" aria-hidden="true"></i><?= __('Scansiona') ?>
                  </button>
                <?php endif; ?>
                <button type="button" id="emt-scan-lookup" class="btn-secondary">
                  <i class="fas fa-search mr-2" aria-hidden="true"></i><?= __('Cerca il codice') ?>
                </button>
              </div>
            </div>
            <p id="emt-scan-result" class="emt-scan-result text-sm" role="status" aria-live="polite" hidden></p>
            <div class="flex flex-wrap items-center gap-4">
              <a id="emt-scan-open" class="text-xs text-gray-700 hover:underline" hidden>
                <?= __('Apri il fascicolo') ?>
              </a>
              <form id="emt-scan-receive" method="POST" action="<?= $manageUrl ?>" hidden>
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="receive_issue">
                <input type="hidden" name="fascicolo_id" value="">
                <button type="submit" class="btn-primary">
                  <i class="fas fa-check mr-2" aria-hidden="true"></i><?= __('Segna ricevuto') ?>
                </button>
              </form>
            </div>
          </div>
        </div>
      </section>

      <?php if (empty($annate)): ?>
        <div class="card" role="status">
          <div class="text-center py-8">
            <i class="fas fa-newspaper text-3xl text-gray-400 mb-3" aria-hidden="true"></i>
            <p class="font-medium text-gray-900"><?= __("Nessuna annata registrata.") ?></p>
            <p class="text-sm text-gray-600 mt-1"><?= __("Crea la prima annata per iniziare ad aggiungere fascicoli.") ?></p>
          </div>
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
          $isOpen = $annataId === $openAnnataId;
          ?>
          <details id="annata-<?= $annataId ?>" class="card group"<?= $isOpen ? ' open' : '' ?>>
            <summary class="card-header flex items-center justify-between gap-4 cursor-pointer hover:bg-gray-50 transition-colors">
              <?php if (!$isOpen): ?>
                <!-- Collapsed annata: fascicoli load server-side via ?annata=ID -->
                <a href="<?= $openHref ?>" class="flex flex-1 items-center justify-between gap-4 min-w-0"
                   title="<?= $e(__('Mostra i fascicoli di questa annata')) ?>">
              <?php endif; ?>
                <h2 class="form-section-title flex flex-wrap items-center gap-2 min-w-0">
                  <i class="fas fa-calendar-alt text-gray-900" aria-hidden="true"></i>
                  <?= (int) $annata['anno'] ?>
                  <?php if (!empty($annata['volume'])): ?>
                    <span class="text-sm font-normal text-gray-500"><?= __('vol.') ?> <?= $e($annata['volume']) ?></span>
                  <?php endif; ?>
                  <?php if ((int) ($annata['rilegata'] ?? 0) === 1): ?>
                    <span class="status-badge bg-gray-100 text-gray-700"><i class="fas fa-book" aria-hidden="true"></i><?= __('Rilegata') ?></span>
                  <?php endif; ?>
                </h2>
                <span class="flex items-center gap-3">
                  <span class="status-badge bg-gray-100 text-gray-700"><?= sprintf(__('%d fascicoli'), $nFascicoli) ?></span>
                  <i class="fas fa-chevron-down text-gray-600 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
                </span>
              <?php if (!$isOpen): ?>
                </a>
              <?php endif; ?>
            </summary>
            <?php if (!$isOpen): ?>
              <div class="card-body">
                <p class="text-sm text-gray-500">
                  <a href="<?= $openHref ?>" class="hover:underline">
                    <?= __('Mostra i fascicoli di questa annata') ?>
                  </a>
                </p>
              </div>
            <?php else: ?>
            <div class="card-body form-section">
              <form method="POST" action="<?= $manageUrl ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="update_annata">
                <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                <h3 class="text-sm font-semibold text-gray-700 mb-3"><?= __('Dati dell\'annata') ?></h3>
                <div class="form-section">
                  <div class="form-grid-3">
                    <div>
                      <label for="ann-serie-<?= $annataId ?>" class="form-label"><?= __('Serie') ?></label>
                      <input id="ann-serie-<?= $annataId ?>" type="text" name="serie" maxlength="50"
                             value="<?= $e($annata['serie'] ?? '') ?>" class="form-input">
                    </div>
                    <?php if ($collocazioni !== []): ?>
                      <div>
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
                    <div>
                      <label for="ann-cons-<?= $annataId ?>" class="form-label"><?= __('Consistenza dichiarata') ?></label>
                      <input id="ann-cons-<?= $annataId ?>" type="text" name="consistenza_dichiarata" maxlength="255"
                             value="<?= $e($annata['consistenza_dichiarata'] ?? '') ?>" class="form-input">
                    </div>
                  </div>
                  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <label class="flex items-center space-x-2 text-sm cursor-pointer">
                      <input type="checkbox" name="rilegata" value="1" class="<?= $checkboxClass ?>"
                        <?= (int) ($annata['rilegata'] ?? 0) === 1 ? 'checked' : '' ?>>
                      <span class="text-gray-700"><?= __('Rilegata') ?></span>
                    </label>
                    <button type="submit" class="btn-secondary">
                      <i class="fas fa-save mr-2" aria-hidden="true"></i><?= __('Salva annata') ?>
                    </button>
                  </div>
                </div>
              </form>

              <div class="pt-6 border-t border-gray-200">
                <?php if ($nAttesi > 0): ?>
                  <div class="flex flex-wrap justify-end gap-4 mb-4">
                    <form method="POST" action="<?= $manageUrl ?>" class="inline"
                          onsubmit="return confirm(<?= $e(json_encode(__('Sollecitare al fornitore tutti i fascicoli attesi già scaduti di questa annata?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                      <input type="hidden" name="action" value="claim_overdue">
                      <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                      <button type="submit" class="text-xs text-gray-700 hover:underline">
                        <i class="fas fa-bell mr-1" aria-hidden="true"></i><?= __('Sollecita gli attesi scaduti') ?>
                      </button>
                    </form>
                    <form method="POST" action="<?= $manageUrl ?>" class="inline"
                          onsubmit="return confirm(<?= $e(json_encode(__('Marcare come mancanti tutti i fascicoli ancora attesi di questa annata?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                      <input type="hidden" name="action" value="mark_missing">
                      <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                      <button type="submit" class="text-xs text-red-600 hover:text-red-800 hover:underline">
                        <?= sprintf(__('Marca %d attesi come mancanti'), $nAttesi) ?>
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
                <?php if (empty($fascicoli)): ?>
                  <p class="text-sm text-gray-500"><?= __('Nessun fascicolo in questa annata.') ?></p>
                <?php else: ?>
                  <!--
                      Label batch. The tiles already contain the
                      receive/claim POST forms, so the checkboxes join
                      this one through the HTML `form` attribute
                      instead of nesting forms.
                  -->
                  <?php $labelsFormId = 'emt-labels-' . $annataId; ?>
                  <form id="<?= $e($labelsFormId) ?>" method="POST" action="<?= $labelsUrl ?>"
                        target="_blank" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                    <label class="flex items-center space-x-2 text-sm cursor-pointer">
                      <input type="checkbox" class="<?= $checkboxClass ?>"
                             data-emt-select-all="<?= $e($labelsFormId) ?>">
                      <span class="text-gray-700"><?= __('Seleziona tutti') ?></span>
                    </label>
                    <button type="submit" class="btn-secondary">
                      <i class="fas fa-tags mr-2" aria-hidden="true"></i><?= __('Stampa etichette') ?>
                    </button>
                  </form>
                  <!-- Kardex grid: plugin widget, styled by emeroteca.css -->
                  <div class="emeroteca-admin">
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
                          <label class="emt-issue-select">
                            <input type="checkbox" name="ids[]" value="<?= $fid ?>"
                                   form="<?= $e($labelsFormId) ?>" class="<?= $checkboxClass ?>">
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
                                <i class="fas fa-newspaper text-gray-400" aria-hidden="true"></i>
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
                              <button type="submit" class="text-xs text-gray-700 hover:underline">
                                <?= __('Segna ricevuto') ?>
                              </button>
                            </form>
                            <form method="POST" action="<?= $manageUrl ?>" class="emt-receive-form">
                              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                              <input type="hidden" name="action" value="claim_issue">
                              <input type="hidden" name="fascicolo_id" value="<?= $fid ?>">
                              <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                              <button type="submit" class="text-xs text-gray-700 hover:underline">
                                <?= __('Sollecita al fornitore') ?>
                              </button>
                            </form>
                          <?php endif; ?>
                        </article>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>

              <form method="POST" action="<?= $manageUrl ?>" class="pt-6 border-t border-gray-200">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add_fascicolo">
                <input type="hidden" name="annata_id" value="<?= $annataId ?>">
                <h3 class="text-sm font-semibold text-gray-700 mb-3"><?= __('Aggiungi fascicolo') ?></h3>
                <div class="form-section">
                  <div class="form-grid-3">
                    <div>
                      <label for="fsc-num-<?= $annataId ?>" class="form-label"><?= __('Numero') ?> <span class="text-red-500">*</span></label>
                      <input id="fsc-num-<?= $annataId ?>" type="text" name="numero" maxlength="50" required
                             class="form-input">
                    </div>
                    <div>
                      <label for="fsc-data-<?= $annataId ?>" class="form-label"><?= __('Data di pubblicazione') ?></label>
                      <input id="fsc-data-<?= $annataId ?>" type="date" name="data_pubblicazione"
                             class="form-input">
                    </div>
                    <div>
                      <label for="fsc-stato-<?= $annataId ?>" class="form-label"><?= __('Stato') ?></label>
                      <select id="fsc-stato-<?= $annataId ?>" name="stato" class="form-input">
                        <?php foreach ($statoLabels as $value => $label): ?>
                          <option value="<?= $e($value) ?>" <?= $value === 'posseduto' ? 'selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="flex flex-col sm:flex-row gap-4 justify-end">
                    <button type="submit" class="btn-secondary">
                      <i class="fas fa-plus mr-2" aria-hidden="true"></i><?= __('Aggiungi fascicolo') ?>
                    </button>
                  </div>
                </div>
              </form>
            </div>
            <?php endif; ?>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
    window.emerotecaScan = {
        lookupUrl: <?= json_encode(url('/admin/periodicals/scan-lookup'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        testataId: <?= $testataId ?>,
        i18n: {
            empty: <?= json_encode(__('Inserisci o scansiona un codice a barre.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            searching: <?= json_encode(__('Ricerca in corso...'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            ambiguous: <?= json_encode(__('Codice ambiguo: risolvi dalla testata.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
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

