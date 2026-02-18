<?php
/** @var string $title */
/** @var string $content */
/** @var string|null $image */

$additional_css = "
<style>
    main {
        padding-top: 90px;
    }

    .cms-page {
        padding: 6rem 0;
        background: white;
    }

    .cms-header {
        text-align: center;
        margin-bottom: 4rem;
    }

    .cms-title {
        font-size: clamp(2rem, 4vw, 2.75rem);
        font-weight: 800;
        color: #111827;
        margin-bottom: 1rem;
        letter-spacing: -0.02em;
    }

    .cms-divider {
        width: 80px;
        height: 4px;
        background: #1f2937;
        margin: 0 auto;
        border-radius: 2px;
    }

    

    .cms-image {
        width: 100%;
        max-height: 500px;
        object-fit: cover;
        border-radius: 16px;
        margin-bottom: 3rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    }

    .cms-content {
        font-size: 1.0625rem;
        line-height: 1.8;
        color: #374151;
    }

    .cms-content p {
        margin-bottom: 1.5rem;
    }

    .cms-content h2 {
        font-size: 1.75rem;
        font-weight: 700;
        color: #111827;
        margin-top: 3rem;
        margin-bottom: 1.25rem;
    }

    .cms-content h3 {
        font-size: 1.375rem;
        font-weight: 600;
        color: #111827;
        margin-top: 2.5rem;
        margin-bottom: 1rem;
    }

    .cms-content ul, .cms-content ol {
        margin-bottom: 1.5rem;
        padding-left: 1.5rem;
    }

    .cms-content li {
        margin-bottom: 0.5rem;
    }

    .cms-content a {
        color: #1f2937;
        text-decoration: underline;
        font-weight: 500;
        transition: color 0.2s ease;
    }

    .cms-content a:hover {
        color: #3b82f6;
    }

    .cms-content img {
        max-width: 100%;
        height: auto;
        border-radius: 12px;
        margin: 2rem 0;
    }

    .cms-content blockquote {
        border-left: 4px solid #1f2937;
        padding-left: 1.5rem;
        margin: 2rem 0;
        font-style: italic;
        color: #6b7280;
    }

    @media (max-width: 768px) {
        .cms-page {
            padding: 4rem 0;
        }

        .cms-header {
            margin-bottom: 3rem;
        }

        .cms-image {
            margin-bottom: 3rem;
        }

        .cms-content {
            font-size: 1rem;
        }
    }
</style>
";

ob_start();
?>

<section class="cms-page">
    <div class="container">
        <div class="cms-header">
            <h1 class="cms-title"><?= htmlspecialchars($title) ?></h1>
            <div class="cms-divider"></div>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-10">
                <?php if (!empty($image)): ?>
                    <img src="<?= htmlspecialchars($image) ?>"
                         alt="<?= htmlspecialchars($title) ?>"
                         class="cms-image">
                <?php endif; ?>

                <div class="cms-content">
                    <?= \App\Support\HtmlHelper::sanitizeHtml($content) ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
include 'layout.php';
?>
