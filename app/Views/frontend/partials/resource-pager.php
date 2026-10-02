<?php
/**
 * Previous / up / next between sibling resources (issues of one year, articles
 * of one issue): three quiet text links on one line, under the hero.
 * Rendered only when there is a previous or a next item.
 *
 * @var array{href: string, label: string}|null $pagerPrev
 * @var array{href: string, label: string}|null $pagerNext
 * @var array{href: string, label: string}|null $pagerUp the parent (year, issue)
 * @var string $pagerLabel accessible name of the navigation
 */
$rpEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pagerPrev = $pagerPrev ?? null;
$pagerNext = $pagerNext ?? null;
$pagerUp = $pagerUp ?? null;
?>
<?php if ($pagerPrev !== null || $pagerNext !== null): ?>
<nav class="resource-pager" aria-label="<?= $rpEscape($pagerLabel ?? __('Navigazione')) ?>">
    <span class="resource-pager-prev"><?php if ($pagerPrev !== null): ?><a href="<?= $rpEscape($pagerPrev['href']) ?>" rel="prev"><i class="fas fa-chevron-left" aria-hidden="true"></i> <span><?= $rpEscape($pagerPrev['label']) ?></span></a><?php endif; ?></span>
    <span class="resource-pager-up"><?php if ($pagerUp !== null): ?><a href="<?= $rpEscape($pagerUp['href']) ?>"><?= $rpEscape($pagerUp['label']) ?></a><?php endif; ?></span>
    <span class="resource-pager-next"><?php if ($pagerNext !== null): ?><a href="<?= $rpEscape($pagerNext['href']) ?>" rel="next"><span><?= $rpEscape($pagerNext['label']) ?></span> <i class="fas fa-chevron-right" aria-hidden="true"></i></a><?php endif; ?></span>
</nav>
<?php endif; ?>
