<?php
declare(strict_types=1);

use App\Support\HtmlHelper;

/** @var array<string, list<array<string, mixed>>> $shelves */
$title = __('Film e video');
$seoCanonical = absoluteUrl(route_path('films'));
$catalogPageStyles = true;
$headings = [
    'latest' => __('Ultimi film aggiunti'),
    'popular' => __('Film più prestati'),
    'children' => __('Film per bambini e ragazzi'),
    'drama' => __('Film drammatici'),
];
ob_start();
?>
<div class="pk-wrap">
    <header class="pk-page-head py-4">
        <h1 class="pk-h1"><?= HtmlHelper::e($title) ?></h1>
        <a class="pk-btn pk-btn--ghost" href="<?= HtmlHelper::e(route_path('catalog') . '?tipo_media=dvd') ?>"><?= HtmlHelper::e(__('Tutti i film')) ?></a>
    </header>
    <?php if ($shelves['latest'] === []): ?>
        <p class="pk-lead pb-4"><?= HtmlHelper::e(__('Nessun film in catalogo.')) ?></p>
    <?php endif; ?>
    <?php foreach ($shelves as $key => $books): ?>
        <?php if ($books === []) { continue; } ?>
        <section class="pk-section" aria-labelledby="films-<?= $key ?>">
            <div class="pk-section-head"><h2 class="pk-h2" id="films-<?= $key ?>"><?= HtmlHelper::e($headings[$key]) ?></h2></div>
            <div class="books-grid pk-grid">
                <?php foreach ($books as $book) { include __DIR__ . '/partials/pk-book-card.php'; } ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
