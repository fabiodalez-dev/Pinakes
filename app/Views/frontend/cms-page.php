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
        font-family: var(--serif);
        font-size: clamp(2rem, 4vw, 2.75rem);
        font-weight: 420;
        color: var(--text-color);
        margin-bottom: 1rem;
        letter-spacing: -0.03em;
    }

    .cms-divider {
        width: 48px;
        height: 1px;
        background: var(--primary-color);
        margin: 0 auto;
        border-radius: 0;
    }



    .cms-image {
        width: 100%;
        max-height: 500px;
        object-fit: cover;
        border-radius: 3px;
        margin-bottom: 3rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
        transition: box-shadow 0.3s ease, transform 0.3s ease;
    }

    .cms-image:hover {
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.14);
        transform: translateY(-2px);
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
        font-family: var(--serif);
        font-size: 1.75rem;
        font-weight: 460;
        color: var(--text-color);
        letter-spacing: -0.02em;
        margin-top: 3rem;
        margin-bottom: 1.25rem;
    }

    .cms-content h3 {
        font-family: var(--serif);
        font-size: 1.375rem;
        font-weight: 460;
        color: var(--text-color);
        letter-spacing: -0.01em;
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
        text-decoration: none;
        border-bottom: 1px solid var(--border-color);
        font-weight: 500;
        transition: color 0.2s ease, border-color 0.2s ease;
    }

    .cms-content a:hover {
        color: var(--primary-color);
        border-color: var(--primary-color);
    }

    .cms-content img {
        max-width: 100%;
        height: auto;
        border-radius: 3px;
        margin: 2rem 0;
    }

    .cms-content blockquote {
        border-left: 2px solid var(--primary-color);
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

        <div class="flex flex-wrap -mx-3 justify-center">
            <div class="w-full lg:w-5/6 px-3">
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
