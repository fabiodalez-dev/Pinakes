<?php
/**
 * Server-rendered pagination with real hrefs (crawlable, works without JS),
 * styled by .pagination in public/assets/catalog-pages.css. Shows at most five
 * page numbers around the current one, plus previous/next arrows.
 *
 * Input $paginationPage: int current page, 1-based
 * Input $paginationPages: int total pages
 * Input $paginationUrl: callable(int): string builds the URL of a page
 */
$paginationPage = max(1, (int) ($paginationPage ?? 1));
$paginationPages = max(1, (int) ($paginationPages ?? 1));
$pgEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
if ($paginationPages > 1 && isset($paginationUrl) && is_callable($paginationUrl)):
    $pgEnd = min($paginationPages, max(1, $paginationPage - 2) + 4);
    $pgStart = max(1, $pgEnd - 4);
?>
<div class="mt-4">
    <nav aria-label="<?= $pgEscape(__('Navigazione pagine')) ?>"><ul class="pagination justify-center">
        <?php if ($paginationPage > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= $pgEscape($paginationUrl($paginationPage - 1)) ?>" rel="prev" title="<?= $pgEscape(__('Pagina precedente')) ?>" aria-label="<?= $pgEscape(__('Pagina precedente')) ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></a></li>
        <?php endif; ?>
        <?php for ($pgI = $pgStart; $pgI <= $pgEnd; $pgI++): ?>
            <li class="page-item<?= $pgI === $paginationPage ? ' active' : '' ?>"><a class="page-link" href="<?= $pgEscape($paginationUrl($pgI)) ?>"<?= $pgI === $paginationPage ? ' aria-current="page"' : '' ?>><?= $pgI ?></a></li>
        <?php endfor; ?>
        <?php if ($paginationPage < $paginationPages): ?>
            <li class="page-item"><a class="page-link" href="<?= $pgEscape($paginationUrl($paginationPage + 1)) ?>" rel="next" title="<?= $pgEscape(__('Pagina successiva')) ?>" aria-label="<?= $pgEscape(__('Pagina successiva')) ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></a></li>
        <?php endif; ?>
    </ul></nav>
</div>
<?php endif; ?>
