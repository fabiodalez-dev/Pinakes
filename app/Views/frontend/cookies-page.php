<?php
/** @var string|null $pageContent */

use App\Support\HtmlHelper;

$title = 'Cookie Policy';

// Intestazione condivisa (catalog-hero.php): ne serve il foglio di stile.
$catalogPageStyles = true;

$additional_css = require __DIR__ . '/partials/static-page-css.php';

ob_start();
?>

<?php
$heroTitle = $title;
$breadcrumbItems = [['label' => __('Home'), 'href' => url('/')], ['label' => $heroTitle]];
include __DIR__ . '/partials/catalog-hero.php';
?>

<section class="static-page">
    <div class="container">
        <div class="static-content">
            <?= HtmlHelper::sanitizeHtml($pageContent ?? ''); ?>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
include 'layout.php';
