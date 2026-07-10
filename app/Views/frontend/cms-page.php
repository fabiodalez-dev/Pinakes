<?php
/** @var string $title */
/** @var string|null $content */
/** @var string|null $image */

$additional_css = "
<style>
    main {
        padding-top: 90px;
    }

    .cms-page {
        padding: 6rem 0;
        background: var(--white);
    }

    .cms-header {
        text-align: center;
        margin-bottom: 4rem;
    }

    .cms-title {
        font-size: clamp(2rem, 4vw, 2.75rem);
        font-weight: 800;
        color: var(--text-color);
        margin-bottom: 1rem;
        letter-spacing: -0.02em;
    }

    .cms-divider {
        width: 80px;
        height: 4px;
        background: var(--text-color);
        margin: 0 auto;
        border-radius: 2px;
    }

    

    .cms-image {
        width: 100%;
        max-height: 500px;
        object-fit: cover;
        border-radius: 16px;
        margin-bottom: 3rem;
        box-shadow: var(--card-shadow);
    }

    .cms-content {
        font-size: 1.0625rem;
        line-height: 1.8;
        color: var(--text-color);
    }

    .cms-content p {
        margin-bottom: 1.5rem;
    }

    .cms-content h2 {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-color);
        margin-top: 3rem;
        margin-bottom: 1.25rem;
    }

    .cms-content h3 {
        font-size: 1.375rem;
        font-weight: 600;
        color: var(--text-color);
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
        color: var(--text-color);
        text-decoration: underline;
        font-weight: 500;
        transition: color 0.2s ease;
    }

    .cms-content a:hover {
        color: var(--primary-color);
    }

    .cms-content img {
        max-width: 100%;
        height: auto;
        border-radius: 12px;
        margin: 2rem 0;
    }

    .cms-content blockquote {
        border-left: 4px solid var(--text-color);
        padding-left: 1.5rem;
        margin: 2rem 0;
        font-style: italic;
        color: var(--text-light);
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
                    <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                         class="cms-image">
                <?php endif; ?>

                <div class="cms-content">
                    <?= \App\Support\HtmlHelper::sanitizeHtml($content ?? '') ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
include 'layout.php';
?>
