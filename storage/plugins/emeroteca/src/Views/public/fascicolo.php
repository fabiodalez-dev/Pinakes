<?php
/**
 * Emeroteca — public fascicolo (issue) detail.
 *
 * Big cover (or placeholder), data sheet (numero, data, pagine,
 * supplementi, collocazione), spoglio (article TOC) and prev/next
 * navigation within the annata.
 *
 * @var array<string, mixed>            $fascicolo
 * @var list<array<string, mixed>>      $articoli
 * @var array<string, mixed>|null       $collocazione
 * @var array<string, mixed>|null       $prev
 * @var array<string, mixed>|null       $next
 * @var array<string, string>           $statoFascicoloLabels
 * @var array<string, string>           $tipoArticoloLabels
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$asset = static function (string $path): string {
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    return url($path[0] === '/' ? $path : '/' . $path);
};

$fascicoloId = (int) $fascicolo['id'];
$testataId   = (int) $fascicolo['testata_id'];
$anno        = (int) $fascicolo['anno'];
$stato       = (string) $fascicolo['stato'];
$cover       = $asset((string) ($fascicolo['copertina_url'] ?? ''));

$statoBadgeClass = [
    'posseduto'   => 'bg-emerald-100 text-emerald-800',
    'mancante'    => 'bg-slate-100 text-slate-700',
    'danneggiato' => 'bg-amber-50 text-amber-900',
    'in_restauro' => 'bg-sky-100 text-sky-800',
    'smarrito'    => 'bg-slate-100 text-slate-700',
    'atteso'      => 'bg-gray-100 text-gray-800',
];
$badge = $statoBadgeClass[$stato] ?? 'bg-gray-100 text-gray-800';

// Display date: prefer the free-text cover date, else the publication
// date formatted with the core helper when available.
$dataLabel = trim((string) ($fascicolo['data_copertina'] ?? ''));
if ($dataLabel === '' && !empty($fascicolo['data_pubblicazione'])) {
    $dataLabel = function_exists('format_date')
        ? (string) format_date((string) $fascicolo['data_pubblicazione'], false, '/')
        : (string) $fascicolo['data_pubblicazione'];
}

// Collocazione label: core model "scaffale.livello" (scaffali → mensole),
// same shape as LibriController::resolveCollocazione.
$collocazioneLabel = '';
if ($collocazione !== null) {
    $parts = [];
    if (!empty($collocazione['scaffale_codice'])) {
        $parts[] = (string) $collocazione['scaffale_codice'];
    } elseif (!empty($collocazione['scaffale_nome'])) {
        $parts[] = (string) $collocazione['scaffale_nome'];
    }
    if (isset($collocazione['numero_livello'])) {
        $parts[] = (string) (int) $collocazione['numero_livello'];
    }
    $collocazioneLabel = implode('.', $parts);
    if (!empty($collocazione['mensola_descrizione'])) {
        $collocazioneLabel .= ' — ' . (string) $collocazione['mensola_descrizione'];
    }
}

$issueLabel = sprintf(__('n. %s'), (string) $fascicolo['numero']);
$pagesLabel = static function (array $a): string {
    $start = $a['pagina_inizio'] !== null ? (int) $a['pagina_inizio'] : null;
    $end   = $a['pagina_fine'] !== null ? (int) $a['pagina_fine'] : null;
    if ($start === null) {
        return '';
    }
    if ($end !== null && $end !== $start) {
        return sprintf(__('pp. %d–%d'), $start, $end);
    }
    return sprintf(__('p. %d'), $start);
};

// ── Schema.org PublicationIssue with isPartOf Periodical ──────────────
$baseAbs = rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/');
$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'PublicationIssue',
    'issueNumber' => (string) $fascicolo['numero'],
    'name'        => (string) $fascicolo['testata_titolo'] . ' — ' . $issueLabel . ' (' . $anno . ')',
    'url'         => $baseAbs . '/emeroteca/fascicolo/' . $fascicoloId,
    'datePublished' => (string) ($fascicolo['data_pubblicazione'] ?? ''),
    'isPartOf'    => array_filter([
        '@type' => 'Periodical',
        'name'  => (string) $fascicolo['testata_titolo'],
        'issn'  => (string) ($fascicolo['testata_issn'] ?? ''),
        'url'   => $baseAbs . '/emeroteca/' . $testataId,
    ], static fn($v) => $v !== ''),
];
if ($fascicolo['pagine'] !== null && (int) $fascicolo['pagine'] > 0) {
    $schema['numberOfPages'] = (int) $fascicolo['pagine'];
}
if ($cover !== '') {
    $schema['image'] = absoluteUrl($cover);
}
if ((int) ($fascicolo['pdf_pubblico'] ?? 0) === 1 && !empty($fascicolo['pdf_path'])) {
    $schema['associatedMedia'] = [
        '@type' => 'MediaObject',
        'encodingFormat' => 'application/pdf',
        'contentUrl' => $baseAbs . '/emeroteca/fascicolo/' . $fascicoloId . '/pdf',
    ];
}
$schema = array_filter($schema, static fn($v) => $v !== '');
$emerotecaSchema = json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/ld+json"><?= $emerotecaSchema ?: '{}' ?></script>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.2.4')) ?>">

<main id="emeroteca-fascicolo" class="container emeroteca-public">
    <nav aria-label="breadcrumb" class="mb-3 text-sm text-gray-500">
        <a href="<?= $e(url('/')) ?>"><?= __('Home') ?></a>
        <span aria-hidden="true">/</span>
        <a href="<?= $e(url('/emeroteca')) ?>"><?= __('Emeroteca') ?></a>
        <span aria-hidden="true">/</span>
        <a href="<?= $e(url('/emeroteca/' . $testataId . '?anno=' . $anno)) ?>"><?= $e((string) $fascicolo['testata_titolo']) ?></a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $e($issueLabel . ' (' . $anno . ')') ?></span>
    </nav>

    <div class="emeroteca-issue-layout">
        <!-- Copertina -->
        <div>
            <div class="emeroteca-cover-large">
                <?php if ($cover !== ''): ?>
                    <img src="<?= $e($cover) ?>"
                         alt="<?= $e((string) $fascicolo['testata_titolo'] . ' — ' . $issueLabel) ?>">
                <?php elseif ($stato === 'mancante'): ?>
                    <i class="far fa-circle-xmark" aria-hidden="true"></i>
                    <span class="emeroteca-cover-missing"><?= __('Mancante') ?></span>
                <?php else: ?>
                    <i class="far fa-newspaper" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
        </div>

        <!-- Scheda -->
        <div>
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <span class="status-badge <?= $e($badge) ?>">
                    <?= $e($statoFascicoloLabels[$stato] ?? $stato) ?>
                </span>
                <?php if (!empty($fascicolo['rilegata'])): ?>
                    <span class="status-badge bg-gray-100 text-gray-800"><?= __('Annata rilegata') ?></span>
                <?php endif; ?>
            </div>
            <h1 class="text-2xl font-semibold mb-1">
                <a href="<?= $e(url('/emeroteca/' . $testataId)) ?>" class="underline underline-offset-2">
                    <?= $e((string) $fascicolo['testata_titolo']) ?>
                </a>
                — <?= $e($issueLabel . ' (' . $anno . ')') ?>
            </h1>
            <?php if (!empty($fascicolo['titolo_fascicolo'])): ?>
                <p class="text-gray-500 italic mb-3"><?= $e((string) $fascicolo['titolo_fascicolo']) ?></p>
            <?php endif; ?>

            <div class="emeroteca-data-sheet">
                    <dl class="emeroteca-dl mb-0">
                        <dt><?= __('Numero') ?></dt>
                        <dd><?= $e((string) $fascicolo['numero']) ?><?php if (!empty($fascicolo['numero_progressivo'])): ?>
                            <span class="text-gray-500">(<?= $e(sprintf(__('progressivo %s'), (string) $fascicolo['numero_progressivo'])) ?>)</span>
                        <?php endif; ?></dd>
                        <?php if ($dataLabel !== ''): ?>
                            <dt><?= __('Data') ?></dt>
                            <dd><?= $e($dataLabel) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($fascicolo['volume'])): ?>
                            <dt><?= __('Volume') ?></dt>
                            <dd><?= $e((string) $fascicolo['volume']) ?></dd>
                        <?php endif; ?>
                        <?php if ($fascicolo['pagine'] !== null && (int) $fascicolo['pagine'] > 0): ?>
                            <dt><?= __('Pagine') ?></dt>
                            <dd><?= $e((string) (int) $fascicolo['pagine']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($fascicolo['supplementi'])): ?>
                            <dt><?= __('Supplementi') ?></dt>
                            <dd><?= $e((string) $fascicolo['supplementi']) ?></dd>
                        <?php endif; ?>
                        <?php if ($collocazioneLabel !== ''): ?>
                            <dt><?= __('Collocazione') ?></dt>
                            <dd><?= $e($collocazioneLabel) ?></dd>
                        <?php endif; ?>
                    </dl>
            </div>

            <!-- Navigazione fascicoli dentro l'annata -->
            <div class="flex flex-wrap items-center gap-2">
                <?php if ((int) ($fascicolo['pdf_pubblico'] ?? 0) === 1 && !empty($fascicolo['pdf_path'])): ?>
                    <a class="ui-button btn-primary"
                       href="<?= $e(url('/emeroteca/fascicolo/' . $fascicoloId . '/pdf')) ?>"
                       target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-file-pdf mr-2" aria-hidden="true"></i><?= __('Consulta PDF') ?>
                    </a>
                <?php endif; ?>
                <?php if ($prev !== null): ?>
                    <a class="ui-button btn-outline" href="<?= $e(url('/emeroteca/fascicolo/' . (int) $prev['id'])) ?>">
                        <i class="fas fa-chevron-left mr-2" aria-hidden="true"></i><?= $e(sprintf(__('n. %s'), (string) $prev['numero'])) ?>
                    </a>
                <?php endif; ?>
                <a class="ui-button btn-outline" href="<?= $e(url('/emeroteca/' . $testataId . '?anno=' . $anno)) ?>">
                    <?= $e(sprintf(__('Annata %d'), $anno)) ?>
                </a>
                <?php if ($next !== null): ?>
                    <a class="ui-button btn-outline" href="<?= $e(url('/emeroteca/fascicolo/' . (int) $next['id'])) ?>">
                        <?= $e(sprintf(__('n. %s'), (string) $next['numero'])) ?><i class="fas fa-chevron-right ml-2" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sommario (spoglio) -->
    <?php if (!empty($articoli)): ?>
        <section class="emeroteca-toc">
                <header>
                    <h2 class="mb-0 text-base font-semibold">
                        <i class="fas fa-list mr-2" aria-hidden="true"></i>
                        <?= sprintf(__('Sommario (%d)'), count($articoli)) ?>
                    </h2>
                </header>
                <ul class="divide-y divide-gray-200">
                    <?php foreach ($articoli as $art):
                        $pp = $pagesLabel($art);
                        $tipoArt = (string) $art['tipo'];
                    ?>
                        <li class="py-3 px-4 flex items-center gap-2">
                            <?php if ($tipoArt !== 'articolo'): ?>
                                <span class="status-badge bg-gray-100 text-gray-800">
                                    <?= $e($tipoArticoloLabels[$tipoArt] ?? $tipoArt) ?>
                                </span>
                            <?php endif; ?>
                            <span class="emeroteca-toc-main">
                                <span class="font-medium"><?= $e((string) $art['titolo']) ?></span>
                                <?php if (!empty($art['autori'])): ?>
                                    <span class="text-gray-500 text-sm"> — <?= $e((string) $art['autori']) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($pp !== ''): ?>
                                <span class="emeroteca-toc-pages"><?= $e($pp) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
        </section>
    <?php endif; ?>
</main>
