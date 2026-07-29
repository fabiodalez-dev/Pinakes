<?php
/** @var string|null $pageContent */

use App\Support\HtmlHelper;

$title = trim((string)($pageTitle ?? ''));
if ($title === '' || strcasecmp($title, 'test') === 0) {
    $title = 'Privacy Policy';
}

$additional_css = "
<style>
.privacy-page {
    padding: 4rem 0;
}

.privacy-header {
    text-align: center;
    margin-bottom: 3rem;
}

.privacy-header h1 {
    font-family: var(--serif);
    font-size: clamp(2rem, 4vw, 2.75rem);
    font-weight: 420;
    color: var(--text-color);
    letter-spacing: -0.03em;
    margin-bottom: 1rem;
}

.privacy-divider {
    width: 48px;
    height: 1px;
    background: var(--primary-color);
    margin: 0 auto 1.5rem;
    border-radius: 0;
}

.privacy-content {
    max-width: 900px;
    margin: 0 auto;
    line-height: 1.8;
    color: var(--text-color);
    font-size: 1rem;
}

.privacy-content h2,
.privacy-content h3,
.privacy-content h4 {
    font-family: var(--serif);
    color: var(--text-color);
    letter-spacing: -0.02em;
    margin-top: 2rem;
    font-weight: 460;
}

.privacy-content p {
    margin-bottom: 1.25rem;
}
</style>
";

ob_start();
?>

<section class="privacy-page">
    <div class="container">
        <div class="privacy-header">
            <h1><?= HtmlHelper::e($title); ?></h1>
            <div class="privacy-divider"></div>
        </div>
        <div class="privacy-content">
            <?= HtmlHelper::sanitizeHtml($pageContent ?? ''); ?>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
