<?php
/**
 * Hero Section Template
 * Main landing section with search and stats
 */
$heroData = $section ?? [];
$catalogRoute = $catalogRoute ?? route_path('catalog');
$heroButtonText = trim((string)($heroData['button_text'] ?? ''));
$heroButtonText = $heroButtonText !== '' ? $heroButtonText : __('Sfoglia Catalogo');
$heroButtonPath = trim((string)($heroData['button_link'] ?? ''));
$heroButtonLink = $heroButtonPath !== '' ? url($heroButtonPath) : $catalogRoute;
$latestBooksText = trim((string)($homeContent['latest_books_title']['title'] ?? ''));
$latestBooksText = $latestBooksText !== '' ? $latestBooksText : __('Ultimi Arrivi');
?>

<!-- Hero Section -->
<?php
$heroBgImage = $heroData['background_image'] ?? '';
$heroBgUrl = $heroBgImage !== '' ? url($heroBgImage) : assetUrl('books.jpg');
?>
<section class="hero-section" data-section="hero" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.7) 0%, rgba(0, 0, 0, 0.7) 100%), url('<?php echo htmlspecialchars($heroBgUrl, ENT_QUOTES, 'UTF-8'); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat;">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="hero-title"><?php echo htmlspecialchars($heroData['title'] ?? __("La Tua Biblioteca Digitale"), ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="hero-subtitle">
                <?php echo htmlspecialchars($heroData['subtitle'] ?? __("Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna."), ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <!-- Hero Search Bar -->
            <div class="hero-search-container">
                <form class="hero-search-form search-form" action="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>" method="get">
                    <div class="hero-search-input-group">
                        <i class="fas fa-search hero-search-icon"></i>
                        <input type="search"
                               name="q"
                               class="hero-search-input search-input"
                               placeholder="<?= __("Cerca libri...") ?>"
                               aria-label="<?= __("Cerca nella biblioteca") ?>">
                        <button type="submit" class="hero-search-button">
                            <?= __("Cerca") ?>
                        </button>
                    </div>
                </form>

                <!-- Quick links -->
                <div class="hero-quick-links">
                    <a href="#latest-books" class="hero-quick-link">
                        <i class="fas fa-book"></i>
                        <?= htmlspecialchars($latestBooksText, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="<?= htmlspecialchars($heroButtonLink, ENT_QUOTES, 'UTF-8') ?>" class="hero-quick-link">
                        <i class="fas fa-list"></i>
                        <?= htmlspecialchars($heroButtonText, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>
            <?php
            // Counters are precomputed (and cached) server-side by
            // FrontendController::home(); render the real numbers immediately.
            // The client-side loadStats() fetch only runs as a fallback when
            // the values are missing (data-server-rendered absent).
            $heroStatsServerRendered = isset($heroTotalBooks, $heroAvailableBooks);
            $edgeCacheEnabled = \App\Support\LiteSpeedCache::enabled();
            ?>
            <div class="hero-stats">
                <div class="hero-stat">
                    <span class="hero-stat-number" id="total-books"<?= $heroStatsServerRendered ? ' data-server-rendered="1"' : '' ?>>
                        <?php if ($heroStatsServerRendered): ?>
                            <?= (int) $heroTotalBooks ?>
                        <?php else: ?>
                        <div class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" role="status" style="width: 2rem; height: 2rem;">
                            <span class="sr-only"><?= __("Caricamento...") ?></span>
                        </div>
                        <?php endif; ?>
                    </span>
                    <span class="hero-stat-label"><?= __("Libri Totali") ?></span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-number" id="available-books"<?= $heroStatsServerRendered ? ' data-server-rendered="1"' : '' ?><?= $edgeCacheEnabled ? ' data-live-stat="available_books" data-live-pending="1"' : '' ?>>
                        <?php if ($edgeCacheEnabled): ?>
                            <?php // Intentionally empty: availability never enters shared HTML. ?>
                        <?php elseif ($heroStatsServerRendered): ?>
                            <?= (int) $heroAvailableBooks ?>
                        <?php else: ?>
                        <div class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" role="status" style="width: 2rem; height: 2rem;">
                            <span class="sr-only"><?= __("Caricamento...") ?></span>
                        </div>
                        <?php endif; ?>
                    </span>
                    <span class="hero-stat-label"><?= __("Disponibili") ?></span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-number">12</span>
                    <span class="hero-stat-label"><?= __("Categorie") ?></span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-number">24/7</span>
                    <span class="hero-stat-label"><?= __("Sempre Online") ?></span>
                </div>
            </div>
        </div>
    </div>
</section>
