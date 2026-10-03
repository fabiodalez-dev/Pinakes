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

// Possession states, twin of EmerotecaPlugin::STATI_FASCICOLO. Since the
// 1.4.0 split 'danneggiato'/'in_restauro' are physical CONDITIONS, not
// states — the ENUM cannot hold them any more — while 'reclamato' and
// 'scartato' were added and used to fall through to the unlabelled grey
// fallback.

// Physical condition (1.4.0): recorded separately from possession and,
// until now, shown nowhere on the public side — so a reader could not
// tell that the issue they are about to request is damaged or away for
// restoration. Only meaningful on an issue the library actually holds.
$condizione = trim((string) ($fascicolo['condizione'] ?? ''));
$condizioneLabels = class_exists('EmerotecaPlugin') ? \EmerotecaPlugin::COND_FASCICOLO : [];
$condizioneLabel = $condizione !== ''
    ? __($condizioneLabels[$condizione] ?? $condizione)
    : '';

// 'scartato' = withdrawn on purpose: the issue is no longer part of the
// collection. It is already excluded from the testata grid and from the
// sitemap; reached directly it still renders (the URL may be bookmarked
// or linked) but says so plainly and publishes no structured data, so
// search engines are not fed a holding the library no longer has.
$isScartato = $stato === 'scartato';

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
<?php if (!$isScartato): ?>
<script type="application/ld+json"><?= $emerotecaSchema ?: '{}' ?></script>
<?php endif; ?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.10.0')) ?>">
<?php
$corePartials = dirname(__DIR__, 6) . '/app/Views/frontend/partials';
$catalogPageStyles = true;
$bookDetailStyles = true;
$contributi = $contributi ?? [];
$testataUrl = url('/emeroteca/' . $testataId);
$annataUrl = $testataUrl . '?' . http_build_query(['anno' => $anno]) . '#emeroteca-fascicoli';
$testataLogo = $asset((string) ($fascicolo['testata_logo_url'] ?? ''));
$hasPdf = (int) ($fascicolo['pdf_pubblico'] ?? 0) === 1 && !empty($fascicolo['pdf_path']);

// ── Hero ───────────────────────────────────────────────────────────────────
$kicker = '<span class="book-media-type"><i class="far fa-newspaper mr-1" aria-hidden="true"></i>' . $e(__('Fascicolo')) . '</span>'
    . '<span class="book-kicker-separator" aria-hidden="true">·</span><span class="book-hero-publishers"><a href="' . $e($testataUrl) . '">'
    . $e((string) $fascicolo['testata_titolo']) . '</a></span>';
$facts = array_values(array_filter([
    $dataLabel,
    !empty($fascicolo['volume']) ? sprintf(__('vol. %s'), (string) $fascicolo['volume']) : '',
    ($fascicolo['pagine'] !== null && (int) $fascicolo['pagine'] > 0) ? sprintf(__('%d pagine'), (int) $fascicolo['pagine']) : '',
], static fn(string $v): bool => $v !== ''));
$extra = $facts !== [] ? '<p class="resource-placement">' . $e(implode(' · ', $facts)) . '</p>' : '';
$statusClass = $stato === 'posseduto' ? 'is-available' : 'is-unavailable';
$extra .= '<div class="mt-4"><span class="book-status-inline ' . $statusClass . '">' . $e(__($statoFascicoloLabels[$stato] ?? $stato)) . '</span>'
    . ($condizioneLabel !== '' ? ' <span class="resource-placement">· ' . $e($condizioneLabel) . '</span>' : '')
    . (!empty($fascicolo['rilegata']) ? ' <span class="resource-placement">· ' . $e(__('Annata rilegata')) . '</span>' : '')
    . '</div>';

// The issue's own cover; else the masthead's logo, shown as a logo.
$resourceCover = $cover !== '' ? $cover : $testataLogo;
$resourceCoverKind = $cover !== '' ? 'cover' : 'logo';
$resourceCoverBlur = $cover !== '';
$resourceCoverAlt = (string) $fascicolo['testata_titolo'] . ' — ' . $issueLabel;
$resourceKickerHtml = $kicker;
$resourceTitle = (string) $fascicolo['testata_titolo'] . ', ' . $issueLabel . ' (' . $anno . ')';
$resourceSubtitle = (string) ($fascicolo['titolo_fascicolo'] ?? '');
$resourceBylineHtml = '';
$resourceExtraHtml = $extra;
$breadcrumbItems = [
    ['label' => __('Home'), 'href' => url('/')],
    ['label' => __('Emeroteca'), 'href' => url('/emeroteca')],
    ['label' => (string) $fascicolo['testata_titolo'], 'href' => $testataUrl],
    ['label' => $issueLabel . ' (' . $anno . ')'],
];
include $corePartials . '/resource-hero.php';
?>

<div id="emeroteca-fascicolo" class="container emeroteca-public">
    <?php if ($isScartato): ?>
        <div class="resource-notice" role="status">
            <strong><?= __('Fascicolo scartato') ?></strong>
            <?= __('Questo fascicolo non fa più parte della raccolta della biblioteca.') ?>
        </div>
    <?php endif; ?>

    <?php
    $pagerLabel = __('Fascicoli della stessa annata');
    $pagerPrev = $prev !== null ? ['href' => url('/emeroteca/fascicolo/' . (int) $prev['id']), 'label' => sprintf(__('n. %s'), (string) $prev['numero'])] : null;
    $pagerNext = $next !== null ? ['href' => url('/emeroteca/fascicolo/' . (int) $next['id']), 'label' => sprintf(__('n. %s'), (string) $next['numero'])] : null;
    $pagerUp = ['href' => $annataUrl, 'label' => sprintf(__('Annata %d'), $anno)];
    include $corePartials . '/resource-pager.php';
    ?>

    <div class="flex flex-wrap -mx-3">
        <div class="w-full lg:w-2/3 px-3">
            <?php if ($hasPdf): ?>
            <div class="action-buttons resource-action-buttons">
                <a class="ui-button btn-primary" href="<?= $e(url('/emeroteca/fascicolo/' . $fascicoloId . '/pdf')) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-file-pdf" aria-hidden="true"></i> <?= __('Consulta PDF') ?></a>
            </div>
            <?php endif; ?>
            <section class="listing-section" id="emeroteca-sommario" aria-labelledby="emeroteca-sommario-title">
                <h2 class="listing-section-title" id="emeroteca-sommario-title">
                    <span><?= __('Articoli in questo fascicolo') ?></span>
                    <?php if (count($contributi) > 0): ?>
                        <a href="<?= $e(url('/emeroteca/articoli') . '?' . http_build_query(['fascicolo' => $fascicoloId])) ?>"><?= __('Cerca in questo fascicolo') ?> →</a>
                    <?php endif; ?>
                </h2>
                <?php
                $articleResults = ['rows' => $contributi];
                $articleEmpty = $articoli === [] ? ['title' => __('Nessun articolo catalogato per questo fascicolo.')] : null;
                require __DIR__ . '/article-results.php';
                ?>
            </section>

            <?php if (!empty($articoli)): ?>
            <section class="listing-section" aria-labelledby="emeroteca-indice-title">
                <h2 class="listing-section-title" id="emeroteca-indice-title"><span><?= sprintf(__('Sommario (%d)'), count($articoli)) ?></span></h2>
                <ol class="resource-toc">
                    <?php foreach ($articoli as $art):
                        $pp = $pagesLabel($art);
                        $tipoArt = (string) $art['tipo'];
                    ?>
                        <li>
                            <span class="resource-toc-main">
                                <?php if ($tipoArt !== 'articolo'): ?><span class="resource-toc-kind"><?= $e($tipoArticoloLabels[$tipoArt] ?? $tipoArt) ?></span><?php endif; ?>
                                <span class="resource-toc-title"><?= $e((string) $art['titolo']) ?></span>
                                <?php if (!empty($art['autori'])): ?><span class="resource-toc-authors"><?= $e((string) $art['autori']) ?></span><?php endif; ?>
                            </span>
                            <?php if ($pp !== ''): ?><span class="resource-toc-pages"><?= $e($pp) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <?php endif; ?>
        </div>

        <aside class="w-full lg:w-1/3 px-3" aria-label="<?= $e(__('Informazioni fascicolo')) ?>">
            <div class="card mb-4 resource-info-card">
                <div class="card-header"><h2 class="mb-0 resource-info-title"><i class="fas fa-info-circle mr-2" aria-hidden="true"></i><?= __('Informazioni fascicolo') ?></h2></div>
                <div class="card-body">
                    <div class="meta-item"><div class="meta-label"><?= __('Testata') ?></div><div class="meta-value"><a href="<?= $e($testataUrl) ?>"><?= $e((string) $fascicolo['testata_titolo']) ?></a></div></div>
                    <?php if (!empty($fascicolo['testata_issn'])): ?><div class="meta-item"><div class="meta-label">ISSN</div><div class="meta-value"><?= $e((string) $fascicolo['testata_issn']) ?></div></div><?php endif; ?>
                    <div class="meta-item"><div class="meta-label"><?= __('Numero') ?></div><div class="meta-value"><?= $e((string) $fascicolo['numero']) ?><?php if (!empty($fascicolo['numero_progressivo'])): ?> (<?= $e(sprintf(__('progressivo %s'), (string) $fascicolo['numero_progressivo'])) ?>)<?php endif; ?></div></div>
                    <?php if ($dataLabel !== ''): ?><div class="meta-item"><div class="meta-label"><?= __('Data') ?></div><div class="meta-value"><?= $e($dataLabel) ?></div></div><?php endif; ?>
                    <div class="meta-item"><div class="meta-label"><?= __('Annata') ?></div><div class="meta-value"><a href="<?= $e($annataUrl) ?>"><?= $e((string) $anno) ?></a><?php if (!empty($fascicolo['volume'])): ?> · <?= $e(sprintf(__('vol. %s'), (string) $fascicolo['volume'])) ?><?php endif; ?></div></div>
                    <?php if ($fascicolo['pagine'] !== null && (int) $fascicolo['pagine'] > 0): ?><div class="meta-item"><div class="meta-label"><?= __('Pagine') ?></div><div class="meta-value"><?= $e((string) (int) $fascicolo['pagine']) ?></div></div><?php endif; ?>
                    <div class="meta-item"><div class="meta-label"><?= __('Stato') ?></div><div class="meta-value"><span class="book-status-inline <?= $statusClass ?>"><?= $e(__($statoFascicoloLabels[$stato] ?? $stato)) ?></span></div></div>
                    <?php if ($condizioneLabel !== ''): ?><div class="meta-item"><div class="meta-label"><?= __('Condizione') ?></div><div class="meta-value"><?= $e($condizioneLabel) ?></div></div><?php endif; ?>
                    <?php if (!empty($fascicolo['supplementi'])): ?><div class="meta-item"><div class="meta-label"><?= __('Supplementi') ?></div><div class="meta-value"><?= $e((string) $fascicolo['supplementi']) ?></div></div><?php endif; ?>
                    <?php if ($collocazioneLabel !== ''): ?><div class="meta-item"><div class="meta-label"><?= __('Collocazione') ?></div><div class="meta-value"><?= $e($collocazioneLabel) ?></div></div><?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>
