<?php
/** @var string $pageContent */

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
    font-size: clamp(2rem, 4vw, 2.75rem);
    font-weight: 800;
    color: #111827;
    letter-spacing: -0.02em;
    margin-bottom: 1rem;
}

.privacy-divider {
    width: 80px;
    height: 4px;
    background: #1f2937;
    margin: 0 auto 1.5rem;
    border-radius: 999px;
}

.privacy-content {
    max-width: 900px;
    margin: 0 auto;
    line-height: 1.8;
    color: #374151;
    font-size: 1rem;
}

.privacy-content h2,
.privacy-content h3,
.privacy-content h4 {
    color: #111827;
    margin-top: 2rem;
    font-weight: 700;
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
            <?= HtmlHelper::sanitizeHtml($pageContent); ?>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
