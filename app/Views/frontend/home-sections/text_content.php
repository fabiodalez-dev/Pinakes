<?php
/**
 * Text Content Section (2026 design): the dark band. The CMS title is split
 * into the large word and its eyebrow when it reads 'Πίνακες (Pinakes) -
 * "Le Tavole"' (big title before the dash, eyebrow after it, the bracketed
 * gloss left out of the display). A figure is lifted out beside it, labelled
 * "anni di tradizione", only when the text itself says so: a number of years
 * in the same sentence as "tradizione" ("…quella tradizione millenaria di
 * 2.268 anni"). Any other title shows as written, and the body is the CMS
 * HTML, sanitised as before.
 */
$textData = $section ?? [];
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$textTitle = trim((string) ($textData['title'] ?? ''));
$textBody = \App\Support\HtmlHelper::sanitizeHtml($textData['content'] ?? '');
$textEyebrow = '';
$textBig = $textTitle;
if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $textTitle, $m)) {
    $textBig = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $m[1]));
    $textEyebrow = trim($m[2]);
}
$textFigure = null;
$textPlain = html_entity_decode(strip_tags($textBody), ENT_QUOTES, 'UTF-8');
if (preg_match('/tradi(?:zione|tion)[^.!?]{0,80}?(\d{1,3}(?:[.,\x{00A0}\x{202F} ]\d{3})+|\d{3,})\s+(?:anni|years|Jahren?|ans|år)(?![\p{L}])/iu', $textPlain, $f)) {
    $textFigure = $f[1];
}
$textIsWord = $textBig !== '' && mb_strlen($textBig) <= 16;
?>

<!-- Text Content Section -->
<section class="section section-alt pk-story<?= $textIsWord ? '' : ' pk-story--plain' ?>" data-section="text_content">
    <div class="pk-story__inner">
        <?php if ($textTitle !== ''): ?>
        <div class="pk-story__aside">
            <?php // The eyebrow and the big word are pieces of the title: screen readers get the whole title once, from the heading. ?>
            <?php if ($textEyebrow !== ''): ?><div class="pk-story__eyebrow" aria-hidden="true"><?= $e($textEyebrow) ?></div><?php endif; ?>
            <h2 class="section-title pk-story__title" title="<?= $e($textTitle) ?>"><?php if ($textBig !== $textTitle): ?><span aria-hidden="true"><?= $e($textBig) ?></span><span class="sr-only"><?= $e($textTitle) ?></span><?php else: ?><?= $e($textBig) ?><?php endif; ?></h2>
            <?php if ($textFigure !== null): ?>
            <div class="pk-story__figure"><strong><?= $e($textFigure) ?></strong><span><?= $e(__('anni di tradizione')) ?></span></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="text-content-body pk-story__body"><?= $textBody ?></div>
    </div>
</section>
