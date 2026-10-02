<?php
/** @var string|null $pageContent */

use App\Support\HtmlHelper;

$title = trim((string)($pageTitle ?? ''));
if ($title === '' || strcasecmp($title, 'test') === 0) {
    $title = 'Privacy Policy';
}

// Intestazione condivisa (catalog-hero.php): ne serve il foglio di stile.
$catalogPageStyles = true;

$additional_css = "
.static-page {
    padding: 3rem 0 4rem;
    background: var(--white);
}

.static-content {
    max-width: 72ch;
    margin: 0;
    line-height: 1.8;
    color: var(--text-color);
    font-size: 1.0625rem;
}

.static-content p {
    margin-bottom: 1.25rem;
}

.static-content h2,
.static-content h3,
.static-content h4 {
    font-family: var(--serif);
    color: var(--text-color);
    letter-spacing: -0.02em;
    font-weight: 460;
    margin: 2.5rem 0 1rem;
}

.static-content h2 { font-size: 1.75rem; }
.static-content h3 { font-size: 1.375rem; }

.static-content ul,
.static-content ol {
    margin-bottom: 1.25rem;
    padding-left: 1.5rem;
}

.static-content li {
    margin-bottom: 0.5rem;
}

.static-content a {
    color: var(--text-color);
    border-bottom: 1px solid var(--border-color);
    text-decoration: none;
}

.static-content a:hover {
    color: var(--primary-color);
    border-color: var(--primary-color);
}

.static-content img {
    max-width: 100%;
    height: auto;
    border-radius: 3px;
}

.static-content blockquote {
    border-left: 1px solid var(--primary-color);
    padding-left: 1.5rem;
    margin: 2rem 0;
    color: var(--text-light);
}
";

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
require __DIR__ . '/layout.php';
