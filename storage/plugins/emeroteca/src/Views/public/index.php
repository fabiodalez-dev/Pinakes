<?php
/**
 * Emeroteca — public index of mastheads, on the catalogue surface.
 *
 * Same anatomy as /catalogo (public/assets/catalog-pages.css): coloured hero,
 * facet sidebar (search, type, publisher, subject, A–Z), active-filter chips,
 * result count, a paginated card grid and the catalogue pagination; then the
 * latest articles — or, while searching, the articles answering the term.
 * Everything is a link or a GET form: no JavaScript is needed.
 *
 * Input $rows: list<array<string, mixed>>
 * Input $total: int
 * Input $page: int
 * Input $pages: int
 * Input $q: string
 * Input $tipo: string
 * Input $editore: int
 * Input $genere: int
 * Input $lettera: string
 * Input $editoreLabel: string
 * Input $genereLabel: string
 * Input $typeFacet: list<array{value: string, label: string, n: int}>
 * Input $editoreFacet: list<array{value: int, label: string, n: int}>
 * Input $genereFacet: list<array{value: int, label: string, n: int}>
 * Input $letterCounts: array<string, int>
 * Input $tipoLabels: array<string, string>
 * Input $articleResults: array{rows: array<int, array<string, mixed>>, total: int}
 */
declare(strict_types=1);

$rows = $rows ?? [];
$total = (int) ($total ?? count($rows));
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$q = $q ?? '';
$tipo = $tipo ?? '';
$editore = (int) ($editore ?? 0);
$genere = (int) ($genere ?? 0);
$lettera = $lettera ?? '';
$tipoLabels = $tipoLabels ?? [];
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$corePartials = dirname(__DIR__, 6) . '/app/Views/frontend/partials';

/** Current narrowing with $changes applied; '' / 0 removes a key. Page always resets. */
$state = ['q' => $q, 'tipo' => $tipo, 'editore' => $editore ?: '', 'genere' => $genere ?: '', 'lettera' => $lettera];
$stateUrl = static function (array $changes = []) use ($state): string {
    $query = array_filter($changes + $state, static fn(mixed $v): bool => (string) $v !== '' && (string) $v !== '0');
    return url(\App\Support\RouteTranslator::route('periodicals')) . ($query ? '?' . http_build_query($query) : '');
};
$narrowed = array_filter($state, static fn(mixed $v): bool => (string) $v !== '') !== [];

// Resolve a stored logo reference: absolute URLs pass through, site-relative
// paths go through url() (base-path aware).
$asset = static function (string $path): string {
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    return url($path[0] === '/' ? $path : '/' . $path);
};

// "Anni coperti": prefer the real annate range, fall back to the declared
// publication years of the masthead.
$yearsLabel = static function (array $t): string {
    $from = $t['anno_min'] ?? $t['anno_inizio'] ?? null;
    $to   = $t['anno_max'] ?? $t['anno_fine'] ?? null;
    if ($from === null && $to === null) {
        return '';
    }
    if ($from !== null && $to !== null && (int) $from !== (int) $to) {
        return (string) (int) $from . '–' . (string) (int) $to;
    }
    return (string) (int) ($from ?? $to);
};

$catalogPageStyles = true;
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.10.0')) ?>">

<?php
$heroTitle = __('Emeroteca');
$heroSubtitle = __('Riviste, giornali e periodici della biblioteca, con le annate, i fascicoli e gli articoli');
$breadcrumbItems = [['label' => __('Home'), 'href' => url('/')], ['label' => __('Emeroteca')]];
include $corePartials . '/catalog-hero.php';
?>

<div id="emeroteca-index" class="container emeroteca-public">
    <div class="flex flex-wrap -mx-3">
        <?php
        $letterOptions = [];
        foreach ($letterCounts ?? [] as $letter => $n) {
            $letterOptions[] = ['label' => (string) $letter, 'count' => $n, 'href' => $stateUrl(['lettera' => $lettera === $letter ? '' : $letter]), 'active' => $lettera === $letter];
        }
        $filterSearch = [
            'action' => url(\App\Support\RouteTranslator::route('periodicals')),
            'value' => $q,
            'label' => __('Titolo, sottotitolo, ISSN o articolo…'),
            'hidden' => ['tipo' => $tipo, 'editore' => $editore ?: '', 'genere' => $genere ?: '', 'lettera' => $lettera],
        ];
        $filterSections = [
            ['title' => __('Tipologia'), 'icon' => 'fa-newspaper', 'options' => array_map(
                static fn(array $f): array => ['label' => $f['label'], 'count' => $f['n'], 'href' => $stateUrl(['tipo' => $tipo === $f['value'] ? '' : $f['value']]), 'active' => $tipo === $f['value']],
                $typeFacet ?? []
            )],
            ['title' => __('Editori'), 'icon' => 'fa-building', 'options' => array_map(
                static fn(array $f): array => ['label' => $f['label'], 'count' => $f['n'], 'href' => $stateUrl(['editore' => $editore === $f['value'] ? '' : $f['value']]), 'active' => $editore === $f['value']],
                $editoreFacet ?? []
            )],
            ['title' => __('Argomenti'), 'icon' => 'fa-tags', 'options' => array_map(
                static fn(array $f): array => ['label' => $f['label'], 'count' => $f['n'], 'href' => $stateUrl(['genere' => $genere === $f['value'] ? '' : $f['value']]), 'active' => $genere === $f['value']],
                $genereFacet ?? []
            )],
            ['title' => __('Iniziale'), 'icon' => 'fa-font', 'grid' => true, 'options' => $letterOptions],
        ];
        $filterClearHref = $narrowed ? url(\App\Support\RouteTranslator::route('periodicals')) : '';
        include $corePartials . '/filters-sidebar.php';
        ?>

        <div class="catalog-results-column w-full lg:w-2/3 px-3 xl:w-3/4">
            <?php
            $activeFilters = [];
            if ($q !== '') { $activeFilters[] = ['label' => __('Ricerca'), 'value' => $q, 'removeHref' => $stateUrl(['q' => ''])]; }
            if ($tipo !== '') { $activeFilters[] = ['label' => __('Tipologia'), 'value' => __($tipoLabels[$tipo] ?? $tipo), 'removeHref' => $stateUrl(['tipo' => ''])]; }
            if ($editore > 0) { $activeFilters[] = ['label' => __('Editore'), 'value' => (string) ($editoreLabel ?? ''), 'removeHref' => $stateUrl(['editore' => ''])]; }
            if ($genere > 0) { $activeFilters[] = ['label' => __('Argomento'), 'value' => (string) ($genereLabel ?? ''), 'removeHref' => $stateUrl(['genere' => ''])]; }
            if ($lettera !== '') { $activeFilters[] = ['label' => __('Iniziale'), 'value' => $lettera, 'removeHref' => $stateUrl(['lettera' => ''])]; }
            $resultsCount = $total;
            $resultsLabel = __n('testata', 'testate', $total);
            include $corePartials . '/results-header.php';
            ?>

            <?php if ($rows === []): ?>
                <?php
                $emptyIcon = 'fa-newspaper';
                $emptyTitle = $narrowed ? __('Nessun risultato trovato') : __('Nessuna testata pubblicata.');
                $emptyText = $narrowed ? __('Prova a modificare i filtri o la tua ricerca') : __("L'emeroteca non contiene ancora testate.");
                $emptyCtaHref = $narrowed ? url(\App\Support\RouteTranslator::route('periodicals')) : '';
                $emptyCtaLabel = $narrowed ? __('Mostra tutte le testate') : '';
                include $corePartials . '/empty-state.php';
                ?>
            <?php else: ?>
                <div class="books-grid emeroteca-testate-grid">
                    <?php foreach ($rows as $row):
                        $detailUrl = url(\App\Support\RouteTranslator::route('periodicals') . '/' . (int) $row['id']);
                        $logo = $asset((string) ($row['logo_url'] ?? ''));
                        $meta = array_values(array_filter([$yearsLabel($row), (string) ($row['issn'] ?? '') !== '' ? 'ISSN ' . $row['issn'] : ''], static fn(string $v): bool => $v !== ''));
                    ?>
                        <article class="book-card book-card--square">
                            <div class="book-image-container">
                                <a href="<?= $e($detailUrl) ?>" tabindex="-1" aria-hidden="true" class="flex w-full h-full items-center justify-center">
                                    <?php if ($logo !== ''): ?>
                                        <img class="book-image" src="<?= $e($logo) ?>" alt="" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <i class="fas fa-newspaper book-image-icon" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </a>
                                <span class="book-status-badge status-article"><?= $e(__($tipoLabels[(string) $row['tipo']] ?? (string) $row['tipo'])) ?></span>
                            </div>
                            <div class="book-content">
                                <h3 class="book-title"><a href="<?= $e($detailUrl) ?>"><?= $e((string) $row['titolo']) ?></a></h3>
                                <?php if (!empty($row['sottotitolo'])): ?><p class="book-subtitle"><?= $e((string) $row['sottotitolo']) ?></p><?php endif; ?>
                                <?php if (!empty($row['editore_nome'])): ?><p class="book-author"><?= $e((string) $row['editore_nome']) ?></p><?php endif; ?>
                                <?php if ($meta !== []): ?><p class="book-meta"><?= $e(implode(' · ', $meta)) ?></p><?php endif; ?>
                                <div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $e($detailUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __('Dettagli') ?></a></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            $paginationPage = $page;
            $paginationPages = $pages;
            $paginationUrl = static fn(int $p): string => $stateUrl(['page' => $p > 1 ? $p : '']);
            include $corePartials . '/pagination.php';
            ?>

            <?php if (($articleResults['rows'] ?? []) !== []): ?>
            <section class="listing-section mt-5" aria-labelledby="emeroteca-latest-articles">
                <h2 class="listing-section-title" id="emeroteca-latest-articles">
                    <span><?= $q !== '' ? $e(sprintf(__('Articoli per «%s»'), $q)) : __('Articoli recenti') ?></span>
                    <a href="<?= $e(url(\App\Support\RouteTranslator::route('periodicals') . '/articoli') . ($q !== '' ? '?' . http_build_query(['q' => $q]) : '')) ?>"><?= $q !== '' ? $e(sprintf(__('Tutti i %d articoli'), (int) ($articleResults['total'] ?? 0))) : __('Tutti gli articoli') ?> →</a>
                </h2>
                <?php $articleEmpty = null; require __DIR__ . '/article-results.php'; ?>
            </section>
            <?php endif; ?>
        </div>
    </div>
</div>
