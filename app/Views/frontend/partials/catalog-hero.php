<?php
/**
 * The coloured listing hero of /catalogo (.catalog-header in
 * public/assets/catalog-pages.css): title, subtitle and breadcrumb.
 * The page must set $catalogPageStyles = true for the layout to load the sheet.
 *
 * Input $heroTitle: string
 * Input $heroSubtitle: string optional
 * Input $breadcrumbItems: list<array{label: string, href?: string|null}>
 */
$heroEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<section class="catalog-header">
    <div class="container">
        <div class="catalog-header-content text-center">
            <h1 class="catalog-title"><?= $heroEscape($heroTitle ?? '') ?></h1>
            <?php if (($heroSubtitle ?? '') !== ''): ?>
                <p class="catalog-subtitle"><?= $heroEscape($heroSubtitle) ?></p>
            <?php endif; ?>
            <?php $breadcrumbVariant = 'hero'; include __DIR__ . '/breadcrumb.php'; ?>
        </div>
    </div>
</section>
