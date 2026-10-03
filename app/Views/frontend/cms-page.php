<?php
/** @var string $title */
/** @var string|null $content */
/** @var string|null $image */

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
            <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
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
