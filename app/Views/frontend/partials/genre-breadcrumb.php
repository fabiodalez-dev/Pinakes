<?php
/**
 * Genre breadcrumb links partial
 *
 * Expected variables:
 * @var string[] $genreHierarchy    Genre names in hierarchy order
 * @var int[]    $genreHierarchyIds Genre IDs parallel to $genreHierarchy
 * @var string   $catalogRoute      Catalog page URL
 *
 * Optional (set defaults below if not provided by caller):
 *   $genreLinkClass  — CSS class for links (default '')
 *   $genreSeparator  — Separator markup (default ' &gt; ')
 */
declare(strict_types=1);

if (!isset($genreLinkClass)) {
    $genreLinkClass = '';
}
if (!isset($genreSeparator)) {
    $genreSeparator = ' &gt; ';
}

foreach ($genreHierarchy as $i => $genreName):
    if ($i > 0): ?><?= $genreSeparator ?><?php endif;
    ?><a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>?genere_id=<?= $genreHierarchyIds[$i] ?>"<?php if ($genreLinkClass !== ''): ?> class="<?= htmlspecialchars($genreLinkClass, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars(html_entity_decode($genreName, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a><?php
endforeach;
