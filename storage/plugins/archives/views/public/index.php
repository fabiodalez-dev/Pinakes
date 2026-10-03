<?php
/**
 * Archivio — public index, on the catalogue surface.
 *
 * Same anatomy as /catalogo and /emeroteca (public/assets/catalog-pages.css):
 * coloured hero with breadcrumb, facet sidebar (search, level, period),
 * active-filter chips, result count, the catalogue card grid and its
 * pagination. Browsing lists the root units; any filter searches the whole
 * hierarchy. Everything is a link or a GET form: no JavaScript is needed.
 *
 * Input $rows: list<array<string, mixed>>
 * Input $total: int
 * Input $page: int
 * Input $pages: int
 * Input $q: string
 * Input $level: string
 * Input $date_from: string
 * Input $date_to: string
 * Input $isSearch: bool
 * Input $levelFacet: array<string, int> level => units
 * Input $centuryFacet: array<int, int> first year of the century => units
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$corePartials = dirname(__DIR__, 5) . '/app/Views/frontend/partials';

$levelLabel = [
    'fonds'  => __('Fondo'),
    'series' => __('Serie'),
    'file'   => __('Fascicolo'),
    'item'   => __('Unità'),
];
$levelIcon = [
    'fonds'  => 'fa-archive',
    'series' => 'fa-folder-open',
    'file'   => 'fa-folder',
    'item'   => 'fa-file-alt',
];
$rows      = $rows ?? [];
$total     = (int) ($total ?? count($rows));
$page      = (int) ($page ?? 1);
$pages     = (int) ($pages ?? 1);
$q         = (string) ($q ?? '');
$level     = (string) ($level ?? '');
$dateFrom  = (string) ($date_from ?? '');
$dateTo    = (string) ($date_to ?? '');
$isSearch  = (bool) ($isSearch ?? false);
$archiveBase = \App\Support\RouteTranslator::route('archives') ?: '/archive';
$archiveHome = url($archiveBase);

/** Current narrowing with $changes applied; '' removes a key. Page always resets. */
$state = ['q' => $q, 'level' => $level, 'date_from' => $dateFrom, 'date_to' => $dateTo];
$stateUrl = static function (array $changes = []) use ($state, $archiveHome): string {
    $query = array_filter($changes + $state, static fn(mixed $v): bool => (string) $v !== '');
    return $archiveHome . ($query ? '?' . http_build_query($query) : '');
};
$dateLabel = static function (array $r): string {
    if (empty($r['date_start'])) {
        return '';
    }
    $label = (string) $r['date_start'];
    if (!empty($r['date_end']) && $r['date_end'] !== $r['date_start']) {
        $label .= '–' . (string) $r['date_end'];
    }
    return $label;
};

$catalogPageStyles = true;
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/archives/assets/css/archives-public.css')) ?>">

<?php
$heroTitle = __('Archivio');
$heroSubtitle = __('Consulta i fondi archivistici e le collezioni documentarie.');
$breadcrumbItems = [['label' => __('Home'), 'href' => url('/')], ['label' => __('Archivio')]];
include $corePartials . '/catalog-hero.php';
?>

<div id="archive-index" class="container archive-public">
    <div class="flex flex-wrap -mx-3">
        <?php
        $levelOptions = [];
        foreach ($levelLabel as $lvl => $label) {
            if (empty($levelFacet[$lvl])) {
                continue;
            }
            $levelOptions[] = ['label' => $label, 'count' => (int) $levelFacet[$lvl], 'href' => $stateUrl(['level' => $level === $lvl ? '' : $lvl]), 'active' => $level === $lvl];
        }
        $periodOptions = [];
        foreach ($centuryFacet ?? [] as $from => $n) {
            $to = (int) $from + 99;
            $active = $dateFrom === (string) $from && $dateTo === (string) $to;
            $periodOptions[] = ['label' => $from . '–' . $to, 'count' => (int) $n, 'href' => $stateUrl($active ? ['date_from' => '', 'date_to' => ''] : ['date_from' => (string) $from, 'date_to' => (string) $to]), 'active' => $active];
        }
        $filterSearch = [
            'action' => $archiveHome,
            'value' => $q,
            'label' => __('Titolo, reference code, descrizione…'),
            'hidden' => ['level' => $level, 'date_from' => $dateFrom, 'date_to' => $dateTo],
        ];
        $filterSections = [
            ['title' => __('Livello'), 'icon' => 'fa-sitemap', 'options' => $levelOptions],
            ['title' => __('Periodo'), 'icon' => 'fa-calendar-alt', 'options' => $periodOptions],
        ];
        $filterClearHref = $isSearch ? $archiveHome : '';
        include $corePartials . '/filters-sidebar.php';
        ?>

        <div class="catalog-results-column w-full lg:w-2/3 px-3 xl:w-3/4">
            <?php
            $activeFilters = [];
            if ($q !== '') { $activeFilters[] = ['label' => __('Ricerca'), 'value' => $q, 'removeHref' => $stateUrl(['q' => ''])]; }
            if ($level !== '') { $activeFilters[] = ['label' => __('Livello'), 'value' => $levelLabel[$level] ?? $level, 'removeHref' => $stateUrl(['level' => ''])]; }
            if ($dateFrom !== '') { $activeFilters[] = ['label' => __('Anno dal'), 'value' => $dateFrom, 'removeHref' => $stateUrl(['date_from' => ''])]; }
            if ($dateTo !== '') { $activeFilters[] = ['label' => __('Anno al'), 'value' => $dateTo, 'removeHref' => $stateUrl(['date_to' => ''])]; }
            $resultsCount = $total;
            $resultsLabel = __n('unità archivistica', 'unità archivistiche', $total);
            include $corePartials . '/results-header.php';
            ?>

            <?php if ($rows === []): ?>
                <?php
                $emptyIcon = 'fa-archive';
                $emptyTitle = $isSearch ? __('Nessun risultato trovato') : __('Nessun fondo pubblicato.');
                $emptyText = $isSearch ? __('Prova a modificare i filtri o la tua ricerca') : __("L'archivio non contiene ancora unità di primo livello.");
                $emptyCtaHref = $isSearch ? $archiveHome : '';
                $emptyCtaLabel = $isSearch ? __('Mostra tutto') : '';
                include $corePartials . '/empty-state.php';
                ?>
            <?php else: ?>
                <div class="books-grid archive-units-grid">
                    <?php foreach ($rows as $row):
                        $lvl = (string) $row['level'];
                        $title = (string) $row['constructed_title'];
                        $detailUrl = url($archiveBase . '/' . slugify_text($title) . '-' . (int) $row['id']);
                        $cover = !empty($row['cover_image_path']) ? url((string) $row['cover_image_path']) : '';
                        $date = $dateLabel($row);
                    ?>
                        <article class="book-card archive-unit-card">
                            <div class="book-image-container">
                                <a href="<?= $e($detailUrl) ?>" tabindex="-1" aria-hidden="true" class="archive-unit-card-media">
                                    <?php if ($cover !== ''): ?>
                                        <img class="book-image" src="<?= $e($cover) ?>" alt="" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <i class="fas <?= $e($levelIcon[$lvl] ?? 'fa-archive') ?> book-image-icon" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </a>
                                <span class="book-status-badge status-article"><?= $e($levelLabel[$lvl] ?? $lvl) ?></span>
                            </div>
                            <div class="book-content">
                                <h3 class="book-title"><a href="<?= $e($detailUrl) ?>"><?= $e($title) ?></a></h3>
                                <?php if ($date !== ''): ?><p class="book-author"><?= $e($date) ?></p><?php endif; ?>
                                <p class="book-meta"><span class="archive-ref"><?= $e((string) $row['reference_code']) ?></span><?php if (!empty($row['extent'])): ?> · <?= $e((string) $row['extent']) ?><?php endif; ?></p>
                                <div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $e($detailUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __('Dettagli') ?></a></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            $paginationPage = $page;
            $paginationPages = $pages;
            $paginationUrl = static fn(int $p): string => $stateUrl(['page' => $p > 1 ? (string) $p : '']);
            include $corePartials . '/pagination.php';
            ?>
        </div>
    </div>
</div>
