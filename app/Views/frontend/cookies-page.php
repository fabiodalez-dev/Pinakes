<?php

use App\Support\HtmlHelper;

$title = 'Cookie Policy';

$additional_css = "
<style>
main {
    padding-top: 90px;
}

.cookie-page {
    padding: 6rem 0 4rem 0;
}

.cookie-header {
    text-align: center;
    margin-bottom: 3rem;
}

.cookie-header h1 {
    font-size: clamp(2rem, 4vw, 2.75rem);
    font-weight: 800;
    color: #111827;
    letter-spacing: -0.02em;
    margin-bottom: 1rem;
}

.cookie-divider {
    width: 80px;
    height: 4px;
    background: #1f2937;
    margin: 0 auto 1.5rem;
    border-radius: 999px;
}

.cookie-content {
    max-width: 900px;
    margin: 0 auto;
    line-height: 1.8;
    color: #374151;
    font-size: 1rem;
}

.cookie-content h2,
.cookie-content h3,
.cookie-content h4 {
    color: #111827;
    margin-top: 2rem;
    font-weight: 700;
}

.cookie-content p {
    margin-bottom: 1.25rem;
}
</style>
";

ob_start();
?>

<section class="cookie-page">
    <div class="container">
        <div class="cookie-header">
            <h1><?= HtmlHelper::e($title); ?></h1>
            <div class="cookie-divider"></div>
        </div>
        <div class="cookie-content">
            <?= $pageContent; ?>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
include 'layout.php';
