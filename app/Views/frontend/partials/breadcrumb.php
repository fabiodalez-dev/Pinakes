<?php
/**
 * Shared breadcrumb.
 *
 * One markup for every public page, so the separator, the current-page marker
 * and the accessible name are the same everywhere (the per-layout styling in
 * public/assets/frontend-layouts.css targets `.breadcrumb`).
 *
 * Input $breadcrumbItems: list<array{label: string, href?: string|null}>
 *      In order from Home; the LAST item is the current page and is never a link.
 * Input $breadcrumbVariant: string 'hero' — white, centred under a coloured
 *      .catalog-header; 'book' — the .book-breadcrumb of a single-resource hero.
 */
$breadcrumbItems = $breadcrumbItems ?? [];
$breadcrumbVariant = $breadcrumbVariant ?? 'hero';
$bcEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$bcLast = count($breadcrumbItems) - 1;
?>
<?php if ($breadcrumbItems !== []): ?>
<?php if ($breadcrumbVariant === 'book'): ?>
<nav class="book-breadcrumb" aria-label="<?= $bcEscape(__('Percorso di navigazione')) ?>">
    <ol class="breadcrumb">
        <?php foreach ($breadcrumbItems as $bcIndex => $bcItem): ?>
            <?php if ($bcIndex === $bcLast || ($bcItem['href'] ?? '') === ''): ?>
                <li class="breadcrumb-item active"<?= $bcIndex === $bcLast ? ' aria-current="page"' : '' ?>><?= $bcEscape($bcItem['label']) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= $bcEscape($bcItem['href']) ?>"><?= $bcEscape($bcItem['label']) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
<?php else: ?>
<nav aria-label="<?= $bcEscape(__('Percorso di navigazione')) ?>">
    <ol class="breadcrumb flex flex-wrap items-center gap-2 justify-center bg-transparent p-0 mb-0">
        <?php foreach ($breadcrumbItems as $bcIndex => $bcItem): ?>
            <?php if ($bcIndex === $bcLast || ($bcItem['href'] ?? '') === ''): ?>
                <li class="breadcrumb-item text-white active"<?= $bcIndex === $bcLast ? ' aria-current="page"' : '' ?>><?= $bcEscape($bcItem['label']) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= $bcEscape($bcItem['href']) ?>" class="text-white opacity-75"><?= $bcEscape($bcItem['label']) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>
<?php endif; ?>
