<?php
/**
 * Above a listing: the active filters as removable chips (only when there are
 * any), then the result count with a "clear filters" shortcut.
 *
 * Input $resultsCount: int
 * Input $resultsLabel: string the noun after the number, already pluralised
 * Input $activeFilters: list<array{label: string, value: string, removeHref: string}>
 * Input $filterClearHref: string '' hides the shortcut
 */
$rhdEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$activeFilters = $activeFilters ?? [];
$filterClearHref = (string) ($filterClearHref ?? '');
?>
<?php if ($activeFilters !== []): ?>
<div class="active-filters">
    <div class="active-filters-title"><?= __('Filtri attivi:') ?></div>
    <div class="filter-tags">
        <?php foreach ($activeFilters as $rhdChip): ?>
            <span class="filter-tag"><?= $rhdEscape($rhdChip['label'] . ': ' . $rhdChip['value']) ?> <a class="filter-tag-remove" href="<?= $rhdEscape($rhdChip['removeHref']) ?>" aria-label="<?= $rhdEscape(__('Rimuovi filtro') . ' ' . $rhdChip['label']) ?>" title="<?= $rhdEscape(__('Rimuovi filtro')) ?>">&times;</a></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<div class="results-header">
    <div class="results-info"><strong><?= number_format((int) ($resultsCount ?? 0)) ?></strong> <span><?= $rhdEscape($resultsLabel ?? '') ?></span></div>
    <?php if ($filterClearHref !== ''): ?><a class="clear-filters-top-btn" href="<?= $rhdEscape($filterClearHref) ?>" title="<?= $rhdEscape(__('Rimuovi tutti i filtri')) ?>"><i class="fas fa-filter-circle-xmark" aria-hidden="true"></i> <span class="clear-filters-text"><?= __('Pulisci filtri') ?></span></a><?php endif; ?>
</div>
