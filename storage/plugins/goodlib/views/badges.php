<?php
/**
 * GoodLib badges — external source links for book detail pages.
 *
 * Uses the shared plugin-action contract so frontend layout variants and the
 * admin shell can style the links without changing this plugin's behaviour.
 *
 * @var array<string, array{label: string, icon: string, url: string}> $sources
 * @var string $query Search query (title + author)
 * @var string $isbn ISBN for precise search (empty if unavailable)
 * @var string $context 'frontend' or 'admin' (defaults to 'frontend')
 */
$context = $context ?? 'frontend';
/** @var string $isbn */
?>
<div class="plugin-source-search plugin-source-search--<?= $context === 'admin' ? 'admin' : 'frontend' ?> text-base text-gray-600">
  <div class="plugin-source-search__heading inline-flex items-center gap-2 font-medium">
    <i class="fas fa-external-link-alt" aria-hidden="true"></i>
    <span><?= htmlspecialchars(__("Cerca su:"), ENT_QUOTES, 'UTF-8') ?></span>
  </div>
  <div class="plugin-source-search__links mt-2 flex flex-wrap items-center gap-2">
    <?php foreach ($sources as $key => $source): ?>
      <?php
        $sourceLabel = __($source['label']);
        // Anna's Archive and Z-Library: prefer ISBN for exact edition match
        // Gutenberg: always use title+author (ISBN search not supported)
        $useIsbn = $isbn !== '' && ($key === 'anna' || $key === 'zlib');
        $searchTerm = $useIsbn ? $isbn : $query;
        $encodedTerm = urlencode($searchTerm);
      ?>
      <a href="<?= htmlspecialchars(sprintf($source['url'], $encodedTerm), ENT_QUOTES, 'UTF-8') ?>"
         target="_blank"
         rel="noopener noreferrer"
         class="ui-button btn-outline plugin-source-link"
         title="<?= htmlspecialchars(sprintf(__('Cerca "%s" su %s'), $searchTerm, $sourceLabel), ENT_QUOTES, 'UTF-8') ?>">
        <i class="<?= htmlspecialchars($source['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <i class="fas fa-arrow-up-right-from-square plugin-source-link__external" aria-hidden="true"></i>
      </a>
    <?php endforeach; ?>
  </div>
</div>
