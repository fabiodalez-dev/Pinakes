<?php
/**
 * GoodLib badges — external source links for book detail pages.
 *
 * Uses the same chip/badge style as the rest of the app:
 * rounded-full, bg-gray-100, text-gray-700, hover:bg-gray-200
 *
 * @var array<string, array{label: string, icon: string, url: string}> $sources
 * @var string $query Search query (title + author)
 * @var string $context 'frontend' or 'admin' (defaults to 'frontend')
 */
$context = $context ?? 'frontend';
$encodedQuery = urlencode($query);
?>
<div class="text-base text-gray-600 <?= $context === 'admin' ? '' : 'mt-2' ?>">
  <i class="fas fa-external-link-alt text-gray-400 mr-2"></i>
  <span class="font-medium"><?= __("Cerca su:") ?></span>
  <div class="mt-2 flex flex-wrap gap-2">
    <?php foreach ($sources as $key => $source): ?>
      <a href="<?= htmlspecialchars(sprintf($source['url'], $encodedQuery), ENT_QUOTES, 'UTF-8') ?>"
         target="_blank"
         rel="noopener noreferrer"
         class="inline-flex items-center px-2 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 transition"
         title="<?= htmlspecialchars(sprintf(__('Cerca "%s" su %s'), $query, $source['label']), ENT_QUOTES, 'UTF-8') ?>">
        <i class="<?= htmlspecialchars($source['icon'], ENT_QUOTES, 'UTF-8') ?> mr-1"></i><?= htmlspecialchars($source['label'], ENT_QUOTES, 'UTF-8') ?>
        <i class="fas fa-external-link-alt ml-1 text-gray-400" style="font-size: 0.6rem;"></i>
      </a>
    <?php endforeach; ?>
  </div>
</div>
