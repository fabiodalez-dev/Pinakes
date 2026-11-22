<?php
declare(strict_types=1);

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
        <h1 class="pk-h1"><?= htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') ?></h1>
        <a class="pk-btn pk-btn--ghost" href="<?= htmlspecialchars((string) (route_path('catalog') . '?tipo_media=dvd'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(__('Tutti i film'), ENT_QUOTES, 'UTF-8') ?></a>
    </header>
    <?php if ($shelves['latest'] === []): ?>
        <p class="pk-lead pb-4"><?= htmlspecialchars(__('Nessun film in catalogo.'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php foreach ($shelves as $key => $books): ?>
        <?php if ($books === []) { continue; } ?>
        <section class="pk-section" aria-labelledby="films-<?= $key ?>">
            <div class="pk-section-head"><h2 class="pk-h2" id="films-<?= $key ?>"><?= htmlspecialchars((string) ($headings[$key]), ENT_QUOTES, 'UTF-8') ?></h2></div>
            <div class="books-grid pk-grid">
                <?php foreach ($books as $book) { include __DIR__ . '/partials/pk-book-card.php'; } ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
