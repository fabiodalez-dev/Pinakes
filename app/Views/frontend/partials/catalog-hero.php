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
<section class="catalog-header pk-catalog-head">
    <div class="pk-wrap">
        <?php $breadcrumbVariant = 'hero'; include __DIR__ . '/breadcrumb.php'; ?>
        <div class="catalog-header-content pk-page-head">
            <div class="pk-page-head__text">
                <h1 class="catalog-title pk-h1"><?= $heroEscape($heroTitle ?? '') ?></h1>
                <?php if (($heroSubtitle ?? '') !== ''): ?>
                    <p class="catalog-subtitle pk-lead"><?= $heroEscape($heroSubtitle) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
