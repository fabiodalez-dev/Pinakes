<?php
/**
 * The catalogue's facet sidebar, server-rendered: every facet option is a real
 * link and the search box is a GET form, so it works without JavaScript. On
 * small screens it collapses behind its toggle exactly as /catalogo does.
 *
 * Input $filterSearch: array{action: string, name?: string, value?: string, placeholder?: string, label?: string, hidden?: array<string, string>}|null
 * Input $filterSections: list<array{title: string, icon: string, grid?: bool, options: list<array{label: string, count?: ?int, href: string, active?: bool}>}>
 *      Sections with no options are skipped. `grid` lays short labels (an
 *      A–Z index) out as a compact grid of tiles instead of a list.
 * Input $filterClearHref: string '' hides the "clear all" button
 */
$fsEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$filterSearch = $filterSearch ?? null;
$filterSections = $filterSections ?? [];
$filterClearHref = (string) ($filterClearHref ?? '');
?>
<div class="catalog-filters-column w-full lg:w-1/3 px-3 xl:w-1/4 mb-4">
    <div class="filters-panel">
        <div class="filters-header">
            <h2 class="filters-title"><i class="fas fa-filter" aria-hidden="true"></i> <?= __('Filtri') ?></h2>
            <button type="button" class="filters-mobile-toggle" id="catalog-filters-toggle" aria-controls="catalog-filters-content" aria-expanded="true" aria-label="<?= $fsEscape(__('Filtri')) ?>"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
        </div>
        <div class="filters-content" id="catalog-filters-content">
            <?php if ($filterSearch !== null): $fsName = $filterSearch['name'] ?? 'q'; $fsLabel = $filterSearch['label'] ?? __('Cerca'); ?>
            <div class="filter-section">
                <div class="filter-title"><i class="fas fa-search" aria-hidden="true"></i> <?= __('Ricerca') ?></div>
                <form class="search-box" method="get" action="<?= $fsEscape($filterSearch['action']) ?>" role="search">
                    <label for="filter-search-input" class="sr-only"><?= $fsEscape($fsLabel) ?></label>
                    <input id="filter-search-input" type="search" name="<?= $fsEscape($fsName) ?>" maxlength="200" value="<?= $fsEscape($filterSearch['value'] ?? '') ?>" placeholder="<?= $fsEscape($filterSearch['placeholder'] ?? $fsLabel) ?>">
                    <?php foreach (($filterSearch['hidden'] ?? []) as $fsKey => $fsValue): if ((string) $fsValue === '') { continue; } ?>
                        <input type="hidden" name="<?= $fsEscape($fsKey) ?>" value="<?= $fsEscape($fsValue) ?>">
                    <?php endforeach; ?>
                    <svg class="svg-inline--fa fa-magnifying-glass" role="img" viewBox="0 0 512 512" aria-hidden="true"><path fill="currentColor" d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376C296.3 401.1 253.9 416 208 416 93.1 416 0 322.9 0 208S93.1 0 208 0 416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"></path></svg>
                    <button type="submit" class="sr-only"><?= __('Cerca') ?></button>
                </form>
            </div>
            <?php endif; ?>

            <?php foreach ($filterSections as $fsSection): if (($fsSection['options'] ?? []) === []) { continue; } ?>
            <div class="filter-section">
                <div class="filter-title"><i class="fas <?= $fsEscape($fsSection['icon'] ?? 'fa-filter') ?>" aria-hidden="true"></i> <?= $fsEscape($fsSection['title']) ?></div>
                <div class="filter-options<?= !empty($fsSection['grid']) ? ' filter-options--grid' : '' ?>">
                    <?php foreach ($fsSection['options'] as $fsOption): $fsActive = !empty($fsOption['active']); ?>
                        <a class="filter-option count<?= $fsActive ? ' active' : '' ?>" href="<?= $fsEscape($fsOption['href']) ?>"<?= $fsActive ? ' aria-current="true"' : '' ?> title="<?= $fsEscape($fsOption['label']) ?>">
                            <span><?= $fsEscape($fsOption['label']) ?></span>
                            <?php if (isset($fsOption['count']) && empty($fsSection['grid'])): ?><span class="count-badge"><?= (int) $fsOption['count'] ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($filterClearHref !== ''): ?>
            <div class="filter-section">
                <a class="clear-all-btn" href="<?= $fsEscape($filterClearHref) ?>"><i class="fas fa-times" aria-hidden="true"></i> <?= __('Pulisci tutti i filtri') ?></a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
// Same small-screen behaviour as the catalogue sidebar: collapsed behind its
// toggle at <=768px, always open above. Without JS the panel simply stays open.
$additional_js = ($additional_js ?? '') . <<<'JS'
<script>
(() => {
    const toggle = document.getElementById('catalog-filters-toggle');
    const content = document.getElementById('catalog-filters-content');
    if (!toggle || !content) return;
    const mobile = window.matchMedia('(max-width: 768px)');
    const sync = () => {
        if (mobile.matches) {
            content.hidden = toggle.getAttribute('aria-expanded') !== 'true';
        } else {
            content.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }
    };
    if (mobile.matches) toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        content.hidden = expanded;
    });
    if (typeof mobile.addEventListener === 'function') mobile.addEventListener('change', sync); else mobile.addListener(sync);
    sync();
})();
</script>
JS;
