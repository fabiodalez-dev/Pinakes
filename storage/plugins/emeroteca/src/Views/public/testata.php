<?php
/**
 * Emeroteca — public testata detail.
 *
 * Header (logo, titolo, ISSN, editore, periodicità, anni, "già"/"poi"
 * title chain, descrizione), year timeline and covers grid of the
 * fascicoli of the selected year (?anno=, default: most recent).
 *
 * @var array<string, mixed>            $testata
 * @var array<string, mixed>|null       $precedente
 * @var array<string, mixed>|null       $successiva
 * @var list<array<string, mixed>>      $years
 * @var int|null                        $selectedYear
 * @var list<array<string, mixed>>      $fascicoli
 * @var array<string, string>           $tipoLabels
 * @var array<string, string>           $periodicitaLabels
 * @var array<string, string>           $statoFascicoloLabels
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/Support/CodeLists.php';

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

$testataId = (int) $testata['id'];
$logo = $asset((string) ($testata['logo_url'] ?? ''));

$annoInizio = $testata['anno_inizio'] !== null ? (int) $testata['anno_inizio'] : null;
$annoFine   = $testata['anno_fine'] !== null ? (int) $testata['anno_fine'] : null;
$anniLabel = '';
if ($annoInizio !== null) {
    $anniLabel = (string) $annoInizio . '–' . ($annoFine !== null ? (string) $annoFine : __('oggi'));
} elseif ($annoFine !== null) {
    $anniLabel = (string) $annoFine;
}

// Possession states, twin of EmerotecaPlugin::STATI_FASCICOLO. Since the
// 1.4.0 split 'danneggiato'/'in_restauro' are physical CONDITIONS, not
// states: the ENUM can no longer hold them, so mapping them here only
// produced dead entries — while the two states that were added,
// 'reclamato' and 'scartato', fell through to the unlabelled grey
// fallback.

// 'scartato' = withdrawn on purpose: the library no longer holds the
// issue, so it is not advertised to the public — it is dropped from the
// markup here and excluded from the sitemap by
// EmerotecaPlugin::extendSitemapEntries(). Note that the per-year counts
// in the timeline below come from the controller and still include the
// withdrawn issues.
$fascicoli = array_values(array_filter(
    $fascicoli,
    static fn(array $f): bool => (string) ($f['stato'] ?? '') !== 'scartato'
));

// ── Schema.org Periodical (dedicated branch for SEO consumers) ────────
$canonicalSelf = rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . '/emeroteca/' . $testataId;
$temporalCoverage = null;
if ($annoInizio !== null) {
    $temporalCoverage = (string) $annoInizio . '/' . ($annoFine !== null ? (string) $annoFine : '..');
}
$schema = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Periodical',
    'name'          => (string) $testata['titolo'],
    'alternateName' => (string) ($testata['sottotitolo'] ?? ''),
    'issn'          => (string) ($testata['issn'] ?? ''),
    'url'           => $canonicalSelf,
    'description'   => (string) ($testata['descrizione'] ?? ''),
    'inLanguage'    => \App\Plugins\Emeroteca\Support\CodeLists::languageTag((string) ($testata['lingua'] ?? '')),
    'temporalCoverage' => $temporalCoverage,
];
if (!empty($testata['editore_nome'])) {
    $schema['publisher'] = [
        '@type' => 'Organization',
        'name'  => (string) $testata['editore_nome'],
    ];
}
if ($logo !== '') {
    $schema['image'] = absoluteUrl($logo);
}
$schema = array_filter($schema, static fn($v) => $v !== null && $v !== '');
$emerotecaSchema = json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/ld+json"><?= $emerotecaSchema ?: '{}' ?></script>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.10.0')) ?>">
<?php
$corePartials = dirname(__DIR__, 6) . '/app/Views/frontend/partials';
$catalogPageStyles = true;
$bookDetailStyles = true;
$q = $q ?? '';
$rawAnno = $rawAnno ?? '';
$testataUrl = url('/emeroteca/' . $testataId);
/** This page's URL with the article search / page / year changed; '' removes a key. */
$pageUrl = static function (array $changes = []) use ($testataUrl, $q, $selectedYear, $rawAnno): string {
    $state = ['anno' => $rawAnno !== '' ? (string) $selectedYear : '', 'q' => $q];
    $query = array_filter($changes + $state, static fn(mixed $v): bool => (string) $v !== '');
    return $testataUrl . ($query ? '?' . http_build_query($query) : '');
};

// ── Hero: the masthead as a "scheda", like a book's ────────────────────────
$kicker = '<span class="book-media-type"><i class="fas fa-newspaper mr-1" aria-hidden="true"></i>'
    . $e(__($tipoLabels[(string) $testata['tipo']] ?? (string) $testata['tipo'])) . '</span>';
if (!empty($testata['editore_nome'])) {
    $kicker .= '<span class="book-kicker-separator" aria-hidden="true">·</span><span class="book-hero-publishers"><a href="'
        . $e(url('/emeroteca') . '?' . http_build_query(['editore' => (int) ($testata['editore_id'] ?? 0)])) . '">'
        . $e((string) $testata['editore_nome']) . '</a></span>';
}
$heroFacts = array_values(array_filter([
    !empty($testata['issn']) ? 'ISSN ' . $testata['issn'] : '',
    !empty($testata['periodicita']) ? (string) ($periodicitaLabels[(string) $testata['periodicita']] ?? $testata['periodicita']) : '',
    $anniLabel,
], static fn(string $v): bool => $v !== ''));
$extra = '';
if ($heroFacts !== []) {
    $extra .= '<p class="resource-placement">' . $e(implode(' · ', $heroFacts)) . '</p>';
}
if (!empty($testata['genere_nome'])) {
    $extra .= '<div class="genre-tags"><i class="fas fa-tags" aria-hidden="true"></i><a class="genre-tag" href="'
        . $e(url('/emeroteca') . '?' . http_build_query(['genere' => (int) ($testata['genere_id'] ?? 0)])) . '">'
        . $e((string) $testata['genere_nome']) . '</a></div>';
}
$chain = [];
if ($precedente !== null) {
    $chain[] = $e(__('Già:')) . ' <a href="' . $e(url('/emeroteca/' . (int) $precedente['id'])) . '">' . $e((string) $precedente['titolo']) . '</a>';
}
if ($successiva !== null) {
    $chain[] = $e(__('Poi:')) . ' <a href="' . $e(url('/emeroteca/' . (int) $successiva['id'])) . '">' . $e((string) $successiva['titolo']) . '</a>';
}
if ($chain !== []) {
    $extra .= '<p class="resource-placement">' . implode(' · ', $chain) . '</p>';
}
$resourceCover = $logo;
$resourceCoverBlur = false;
$resourceCoverKind = 'logo';
$resourceCoverAlt = (string) $testata['titolo'];
$resourceKickerHtml = $kicker;
$resourceTitle = (string) $testata['titolo'];
$resourceSubtitle = (string) ($testata['sottotitolo'] ?? '');
$resourceBylineHtml = '';
$resourceExtraHtml = $extra;
$breadcrumbItems = [
    ['label' => __('Home'), 'href' => url('/')],
    ['label' => __('Emeroteca'), 'href' => url('/emeroteca')],
    ['label' => (string) $testata['titolo']],
];
include $corePartials . '/resource-hero.php';
?>

<div id="emeroteca-testata" class="container emeroteca-public">
    <div class="flex flex-wrap -mx-3">
        <?php
        $filterSearch = [
            'action' => $testataUrl,
            'value' => $q,
            'label' => __('Cerca negli articoli di questa testata'),
            'hidden' => ['anno' => $rawAnno !== '' ? (string) $selectedYear : ''],
        ];
        $filterSections = [[
            'title' => __('Annate'),
            'icon' => 'fa-calendar-alt',
            'options' => array_map(static fn(array $y): array => [
                'label' => (string) (int) $y['anno'],
                'count' => (int) $y['num_fascicoli'],
                'href' => $pageUrl(['anno' => (string) (int) $y['anno']]) . '#emeroteca-fascicoli',
                'active' => $selectedYear !== null && (int) $y['anno'] === $selectedYear,
            ], array_reverse($years)),
        ]];
        $filterClearHref = $q !== '' ? $pageUrl(['q' => '']) : '';
        include $corePartials . '/filters-sidebar.php';
        ?>

        <div class="catalog-results-column w-full lg:w-2/3 px-3 xl:w-3/4">
            <?php if (!empty($testata['descrizione'])): ?>
                <section class="listing-section">
                    <h2 class="listing-section-title"><span><?= __('Descrizione') ?></span></h2>
                    <p class="emeroteca-pre-wrap"><?= $e((string) $testata['descrizione']) ?></p>
                </section>
            <?php endif; ?>

            <section class="listing-section" id="emeroteca-fascicoli" aria-labelledby="emeroteca-fascicoli-title">
                <h2 class="listing-section-title" id="emeroteca-fascicoli-title">
                    <span><?= $selectedYear !== null ? $e(sprintf(__('Fascicoli %d'), (int) $selectedYear)) : __('Fascicoli') ?></span>
                </h2>
                <?php if (empty($years)): ?>
                    <?php
                    $emptyIcon = 'fa-calendar-alt';
                    $emptyTitle = __('Nessuna annata registrata.');
                    $emptyText = __('Questa testata non ha ancora annate o fascicoli catalogati.');
                    $emptyCtaHref = '';
                    $emptyCtaLabel = '';
                    include $corePartials . '/empty-state.php';
                    ?>
                <?php elseif (empty($fascicoli)): ?>
                    <?php
                    $emptyIcon = 'fa-calendar-alt';
                    $emptyTitle = __('Nessun fascicolo registrato per questa annata.');
                    $emptyText = '';
                    $emptyCtaHref = '';
                    $emptyCtaLabel = '';
                    include $corePartials . '/empty-state.php';
                    ?>
                <?php else: ?>
                    <div class="books-grid">
                        <?php foreach ($fascicoli as $f):
                            $stato = (string) $f['stato'];
                            $posseduto = $stato === 'posseduto';
                            $cover = $asset((string) ($f['copertina_url'] ?? ''));
                            $issueUrl = url('/emeroteca/fascicolo/' . (int) $f['id']);
                            $numeroLabel = sprintf(__('n. %s'), (string) $f['numero']);
                            $issueMeta = array_values(array_filter([
                                (string) ($f['data_copertina'] ?? ''),
                                !empty($f['volume']) ? sprintf(__('vol. %s'), (string) $f['volume']) : '',
                            ], static fn(string $v): bool => $v !== ''));
                        ?>
                            <article class="book-card">
                                <div class="book-image-container">
                                    <?php if ($posseduto): ?><a href="<?= $e($issueUrl) ?>" tabindex="-1" aria-hidden="true" class="flex w-full h-full items-center justify-center"><?php else: ?><div class="flex w-full h-full items-center justify-center"><?php endif; ?>
                                        <?php if ($cover !== ''): ?>
                                            <img class="book-image" src="<?= $e($cover) ?>" alt="" loading="lazy" decoding="async">
                                        <?php else: ?>
                                            <i class="far <?= $stato === 'mancante' ? 'fa-circle-xmark' : 'fa-newspaper' ?> book-image-icon" aria-hidden="true"></i>
                                        <?php endif; ?>
                                    <?php if ($posseduto): ?></a><?php else: ?></div><?php endif; ?>
                                    <?php if (!$posseduto): ?>
                                        <span class="book-status-badge status-article"><?= $e(__($statoFascicoloLabels[$stato] ?? $stato)) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="book-content">
                                    <h3 class="book-title"><?php if ($posseduto): ?><a href="<?= $e($issueUrl) ?>"><?= $e($numeroLabel) ?></a><?php else: ?><?= $e($numeroLabel) ?><?php endif; ?></h3>
                                    <?php if (!empty($f['titolo_fascicolo'])): ?><p class="book-subtitle"><?= $e((string) $f['titolo_fascicolo']) ?></p><?php endif; ?>
                                    <?php if ($issueMeta !== []): ?><p class="book-meta"><?= $e(implode(' · ', $issueMeta)) ?></p><?php endif; ?>
                                    <?php if ($posseduto): ?><div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $e($issueUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __('Sfoglia') ?></a></div><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="listing-section" id="emeroteca-articoli-testata" aria-labelledby="emeroteca-articoli-testata-title">
                <h2 class="listing-section-title" id="emeroteca-articoli-testata-title">
                    <span><?= __('Articoli') ?></span>
                    <?php if ((int) ($articleResults['total'] ?? 0) > 0): ?>
                        <a href="<?= $e(url('/emeroteca/articoli') . '?' . http_build_query(['testata' => $testataId])) ?>"><?= __('Cerca con tutti i filtri') ?> →</a>
                    <?php endif; ?>
                </h2>
                <?php
                $activeFilters = $q !== '' ? [['label' => __('Ricerca'), 'value' => $q, 'removeHref' => $pageUrl(['q' => ''])]] : [];
                $resultsCount = (int) ($articleResults['total'] ?? 0);
                $resultsLabel = __n('articolo trovato', 'articoli trovati', $resultsCount);
                $filterClearHref = '';
                include $corePartials . '/results-header.php';
                $articleEmpty = $q !== ''
                    ? ['title' => __('Nessun risultato trovato'), 'text' => __('Prova a modificare i filtri o la tua ricerca'), 'ctaHref' => $pageUrl(['q' => '']), 'ctaLabel' => __('Mostra tutti gli articoli')]
                    : ['title' => __('Nessun articolo catalogato per questa testata.')];
                require __DIR__ . '/article-results.php';
                $paginationPage = (int) ($articleResults['page'] ?? 1);
                $paginationPages = (int) ($articleResults['pages'] ?? 1);
                $paginationUrl = static fn(int $p): string => $pageUrl(['page' => $p > 1 ? (string) $p : '']) . '#emeroteca-articoli-testata';
                include $corePartials . '/pagination.php';
                ?>
            </section>
        </div>
    </div>
</div>
