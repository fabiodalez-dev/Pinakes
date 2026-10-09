<?php
/** @var string $title */
/** @var string|null $content */
/** @var string|null $image */

// Intestazione condivisa (catalog-hero.php): ne serve il foglio di stile.
$catalogPageStyles = true;

$additional_css = (require __DIR__ . '/partials/static-page-css.php') . "
.static-image {
    display: block;
    width: 100%;
    max-width: 72ch;
    max-height: 500px;
    object-fit: cover;
    border-radius: 3px;
    margin: 0 0 2.5rem;
}
";

ob_start();
?>

<?php
$heroTitle = (string) $title;
$breadcrumbItems = [['label' => __('Home'), 'href' => url('/')], ['label' => $heroTitle]];
include __DIR__ . '/partials/catalog-hero.php';
?>

<section class="static-page">
    <div class="container">
        <?php if (!empty($image)): ?>
            <img src="<?= htmlspecialchars(url((string) $image), ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                 class="static-image">
        <?php endif; ?>

        <div class="static-content">
            <?= \App\Support\HtmlHelper::sanitizeHtml($content ?? '') ?>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
include 'layout.php';
