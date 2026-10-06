<?php
/**
 * Text Content Section (2026 design): the dark band. The CMS title is split
 * into the large word and its eyebrow when it reads 'Πίνακες (Pinakes) -
 * "Le Tavole"' (big title before the dash, eyebrow after it, the bracketed
 * gloss left out of the display), and a figure such as "2.268 anni" found in
 * the text is lifted out beside it. Any other title shows as written, and the
 * body is the CMS HTML, sanitised as before.
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
if (preg_match('/(\d{1,3}(?:[.,]\d{3})+|\d{3,})\s+(anni|years|Jahre|ans|år)\b/iu', strip_tags($textBody), $f)) {
    $textFigure = $f[1];
}
$textIsWord = $textBig !== '' && mb_strlen($textBig) <= 16;
?>

<!-- Text Content Section -->
<section class="section section-alt pk-story<?= $textIsWord ? '' : ' pk-story--plain' ?>" data-section="text_content">
    <div class="pk-story__inner">
        <?php if ($textTitle !== ''): ?>
        <div class="pk-story__aside">
            <?php if ($textEyebrow !== ''): ?><div class="pk-story__eyebrow"><?= $e($textEyebrow) ?></div><?php endif; ?>
            <h2 class="section-title pk-story__title" title="<?= $e($textTitle) ?>"><?= $e($textBig) ?></h2>
            <?php if ($textFigure !== null): ?>
            <div class="pk-story__figure"><strong><?= $e($textFigure) ?></strong><span><?= $e(__('anni di tradizione')) ?></span></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="text-content-body pk-story__body"><?= $textBody ?></div>
    </div>
</section>
