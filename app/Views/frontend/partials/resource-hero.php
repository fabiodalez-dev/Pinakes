<?php
/**
 * Hero of a single-resource page, identical in structure to the book page's
 * (app/Views/frontend/book-detail.php): blurred-cover band, cover column, then
 * breadcrumb → kicker → title → subtitle → byline → extra.
 * The page must set $bookDetailStyles = true (public/assets/book-detail.css).
 *
 * @var string $resourceCover image URL ('' = no image: the band stays plain
 *      and the cover column is not rendered)
 * @var bool $resourceCoverBlur false to keep the band plain even with an image
 *      (e.g. an inherited masthead logo, which would read as a pattern)
 * @var string $resourceCoverAlt
 * @var string $resourceKickerHtml trusted HTML, already escaped by the caller
 * @var string $resourceTitle
 * @var string $resourceSubtitle optional
 * @var string $resourceBylineHtml trusted HTML, already escaped by the caller
 * @var string $resourceExtraHtml trusted HTML under the byline (badges, actions)
 * @var list<array{label: string, href?: string|null}> $breadcrumbItems
 */
$rhEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$rhCover = (string) ($resourceCover ?? '');
$rhBand = $rhCover !== '' && ($resourceCoverBlur ?? true)
    ? " style=\"--book-hero-cover: " . $rhEscape("url('" . str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", '', ''], $rhCover) . "')") . ";\""
    : '';
?>
<section class="book-hero resource-hero<?= $rhCover === '' ? ' resource-hero--plain' : '' ?>"<?= $rhBand ?>>
    <div class="container">
        <div class="book-hero-content">
            <?php if ($rhCover !== ''): ?>
            <div class="book-cover-column text-center">
                <img src="<?= $rhEscape($rhCover) ?>" alt="<?= $rhEscape($resourceCoverAlt ?? '') ?>" class="book-cover-large img-fluid" fetchpriority="high" decoding="async">
            </div>
            <?php endif; ?>
            <div class="book-info-column">
                <div class="hero-text">
                    <?php $breadcrumbVariant = 'book'; include __DIR__ . '/breadcrumb.php'; ?>
                    <?php if (($resourceKickerHtml ?? '') !== ''): ?>
                        <div class="book-kicker"><?= $resourceKickerHtml ?></div>
                    <?php endif; ?>
                    <h1 class="font-bold mb-3 resource-title"><?= $rhEscape($resourceTitle ?? '') ?></h1>
                    <?php if (($resourceSubtitle ?? '') !== ''): ?>
                        <p class="book-subtitle-hero mb-3"><?= $rhEscape($resourceSubtitle) ?></p>
                    <?php endif; ?>
                    <?php if (($resourceBylineHtml ?? '') !== ''): ?>
                        <div class="authors-list"><?= $resourceBylineHtml ?></div>
                    <?php endif; ?>
                    <?= $resourceExtraHtml ?? '' ?>
                </div>
            </div>
        </div>
    </div>
</section>
