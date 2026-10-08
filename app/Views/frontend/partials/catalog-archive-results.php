<?php
/** @var array<int, array<string, mixed>> $archiveResults */
if (empty($archiveResults)) { return; }
$escapeArchive = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<section class="mt-4 p-3 rounded border" aria-label="<?= $escapeArchive(__("Archivio")) ?>">
    <h3 class="text-sm font-semibold mb-2"><?= $escapeArchive(__("Trovato anche nell'archivio:")) ?></h3>
    <ul class="mb-0 list-none">
        <?php foreach ($archiveResults as $archiveResult): ?>
            <?php
            $archiveHref = (string) ($archiveResult['url'] ?? '');
            if (!str_starts_with($archiveHref, '/') || str_starts_with($archiveHref, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $archiveHref)) { continue; }
            ?>
            <li class="mb-2"><a href="<?= $escapeArchive($archiveHref) ?>">
                <?= $escapeArchive($archiveResult['label'] ?? '') ?>
                <?php if (!empty($archiveResult['reference_code'])): ?><span class="text-sm">(<?= $escapeArchive($archiveResult['reference_code']) ?>)</span><?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <a href="<?= $escapeArchive(url(route_path('archives')) . '?q=' . rawurlencode($searchTerm ?? '')) ?>"><?= $escapeArchive(__("Vedi tutti i risultati")) ?></a>
</section>
