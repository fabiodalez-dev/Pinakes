<?php
/**
 * The catalogue's "nothing here" block (.empty-state in catalog-pages.css).
 *
 * @var string $emptyTitle
 * @var string $emptyText optional
 * @var string $emptyIcon optional Font Awesome class, default 'fa-search'
 * @var string $emptyCtaHref optional; with $emptyCtaLabel renders the way back
 * @var string $emptyCtaLabel optional
 */
$esEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="empty-state">
    <i class="fas <?= $esEscape($emptyIcon ?? 'fa-search') ?> empty-state-icon" aria-hidden="true"></i>
    <h2 class="empty-state-title"><?= $esEscape($emptyTitle ?? __('Nessun risultato trovato')) ?></h2>
    <?php if (($emptyText ?? '') !== ''): ?><p class="empty-state-text"><?= $esEscape($emptyText) ?></p><?php endif; ?>
    <?php if (($emptyCtaHref ?? '') !== '' && ($emptyCtaLabel ?? '') !== ''): ?>
        <a class="btn-cta btn-cta-sm" href="<?= $esEscape($emptyCtaHref) ?>"><i class="fas fa-redo mr-2" aria-hidden="true"></i><?= $esEscape($emptyCtaLabel) ?></a>
    <?php endif; ?>
</div>
