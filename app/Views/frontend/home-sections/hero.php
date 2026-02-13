<?php
/**
 * Hero Section Template
 * Main landing section with search and stats
 */
$heroData = $section ?? [];
$catalogRoute = $catalogRoute ?? route_path('catalog');
?>

<!-- Hero Section -->
<section class="hero-section" data-section="hero" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.7) 0%, rgba(0, 0, 0, 0.7) 100%), url('<?php echo htmlspecialchars($heroData['background_image'] ?? assetUrl('books.jpg'), ENT_QUOTES, 'UTF-8'); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat;">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="hero-title"><?php echo htmlspecialchars($heroData['title'] ?? __("La Tua Biblioteca Digitale"), ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="hero-subtitle">
                <?php echo htmlspecialchars($heroData['subtitle'] ?? __("Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna."), ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <!-- Hero Search Bar -->
            <div class="hero-search-container">
                <form class="hero-search-form search-form" action="<?= $catalogRoute ?>" method="get">
                    <div class="hero-search-input-group">
                        <i class="fas fa-search hero-search-icon"></i>
                        <input type="search"
                               name="q"
                               class="hero-search-input search-input"
                               placeholder="<?= __("Cerca libri, autori, ISBN...") ?>"
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
                        <?= __("Ultimi Arrivi") ?>
                    </a>
                    <a href="<?= $catalogRoute ?>" class="hero-quick-link">
                        <i class="fas fa-list"></i>
                        <?= __("Sfoglia Catalogo") ?>
                    </a>
                </div>
            </div>
            <div class="hero-stats">
                <div class="hero-stat">
                    <span class="hero-stat-number" id="total-books">
                        <div class="spinner-border" role="status" style="width: 2rem; height: 2rem;">
                            <span class="visually-hidden"><?= __("Caricamento...") ?></span>
                        </div>
                    </span>
                    <span class="hero-stat-label"><?= __("Libri Totali") ?></span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-number" id="available-books">
                        <div class="spinner-border" role="status" style="width: 2rem; height: 2rem;">
                            <span class="visually-hidden"><?= __("Caricamento...") ?></span>
                        </div>
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
