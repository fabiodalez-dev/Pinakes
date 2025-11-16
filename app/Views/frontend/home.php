<?php
$title = __("Biblioteca Digitale - La tua biblioteca online");
$catalogRoute = route_path('catalog');
$legacyCatalogRoute = route_path('catalog_legacy');
$apiCatalogRoute = route_path('api_catalog');
$apiCatalogRouteJs = json_encode($apiCatalogRoute, JSON_UNESCAPED_SLASHES);
$registerRoute = route_path('register');

// SEO Variables are now passed from FrontendController::home()
// No need to override them here - the controller handles all SEO logic with proper fallbacks
$additional_css = "
    .hero-section {
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
        color: #ffffff;
        padding: 8rem 0 6rem;
        position: relative;
        overflow: hidden;
        min-height: 100vh;
        display: flex;
        align-items: center;
    }

    .hero-content {
        position: relative;
        z-index: 2;
    }

    .hero-title {
        font-size: 4rem;
        font-weight: 900;
        letter-spacing: -0.04em;
        line-height: 1.1;
        margin-bottom: 2rem;
        color: #ffffff !important;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    }

    .hero-subtitle {
        font-size: 1.4rem;
        font-weight: 300;
        opacity: 0.9;
        margin-bottom: 3rem;
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
        line-height: 1.6;
        color: #f8f9fa !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }

    .hero-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 2rem;
        margin-top: 4rem;
    }

    .hero-stat {
        text-align: center;
        padding: 2rem 1rem;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }

    .hero-stat:hover {
        transform: translateY(-4px);
        background: rgba(255, 255, 255, 0.12);
    }

    .hero-stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        display: block;
        margin-bottom: 0.5rem;
        letter-spacing: -0.02em;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }

    .hero-stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #f8f9fa !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }

    .section {
        padding: 6rem 0;
    }

    .section-alt {
        background: var(--light-bg);
    }

    .section-title {
        text-align: center;
        margin-bottom: 1rem;
        font-size: 3rem;
        font-weight: 800;
        color: var(--primary-color);
        letter-spacing: -0.03em;
        line-height: 1.2;
    }

    .section-subtitle {
        text-align: center;
        font-size: 1.2rem;
        color: var(--text-light);
        margin-bottom: 3rem;
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
        font-weight: 400;
    }

    .feature-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 3rem;
        margin-top: 4rem;
    }

    .feature-card {
        text-align: center;
        padding: 3rem 2rem;
        background: var(--white);
        border-radius: 20px;
        box-shadow: none;
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        border: 1px solid transparent;
        position: relative;
        overflow: hidden;
    }

    .feature-card:hover {
        transform: translateY(-8px);
        box-shadow: none;
        border-color: var(--border-color);
    }

    .feature-icon {
        width: 80px;
        height: 80px;
        margin: 0 auto 2rem;
        background: #d70161;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        color: white;
        box-shadow: none;
    }

    .feature-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 1rem;
        letter-spacing: -0.01em;
    }

    .feature-description {
        color: var(--text-light);
        line-height: 1.6;
        font-size: 1rem;
    }

    .cta-section {
        background: var(--light-bg);
        color: var(--primary-color);
        padding: 6rem 0;
        text-align: center;
        position: relative;
        overflow: hidden;
        border-top: 1px solid var(--border-color);
    }

    .cta-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><defs><pattern id=\"grain\" width=\"100\" height=\"100\" patternUnits=\"userSpaceOnUse\"><circle cx=\"50\" cy=\"50\" r=\"1\" fill=\"black\" opacity=\"0.02\"/></pattern></defs><rect width=\"100\" height=\"100\" fill=\"url(%23grain)\"/></svg>');
    }

    .cta-content {
        position: relative;
        z-index: 2;
    }

    .cta-title {
        font-size: 3rem;
        font-weight: 800;
        margin-bottom: 1.5rem;
        letter-spacing: -0.03em;
        color: var(--primary-color);
    }

    .cta-subtitle {
        font-size: 1.3rem;
        margin-bottom: 3rem;
        opacity: 0.8;
        font-weight: 400;
        max-width: 500px;
        margin-left: auto;
        margin-right: auto;
        color: var(--text-light);
    }

    /* Hero Search Styles */
    .hero-search-container {
        max-width: 1200px;
        margin: 0 auto 4rem;
    }

    .hero-search-form {
        margin-bottom: 3rem;
        position: relative;
    }
form.hero-search-form {
    max-width: 90%;
    margin: auto;
}
    input.hero-search-input.search-input {
    box-shadow: none;
}
    .hero-search-input-group {
        position: relative;
        display: flex;
        align-items: center;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 50px;
        padding: 0.75rem 1.5rem;
        box-shadow: none;
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
    }

    .hero-search-input-group:focus-within {
        background: white;
        box-shadow: none;
        transform: translateY(-2px);
    }

    .hero-search-icon {
        color: var(--primary-color);
        font-size: 1.125rem;
        margin-right: 1rem;
        opacity: 0.7;
    }

    .hero-search-input {
        flex: 1;
        border: none;
        background: transparent;
        font-size: 1.125rem;
        color: var(--primary-color);
        font-weight: 500;
        outline: none;
        padding: 0.5rem 0;
    }

    .hero-search-input:focus {
        border: none;
        outline: none;
        box-shadow: none;
    }

    .hero-search-input::placeholder {
        color: rgba(44, 62, 80, 0.6);
        font-weight: 400;
    }

    .hero-search-button {
        background: var(--primary-color);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 25px;
        font-weight: 600;
        font-size: 0.875rem;
        letter-spacing: 0.025em;
        transition: all 0.3s ease;
        margin-left: 1rem;
    }

    .hero-search-button:hover {
        background: var(--secondary-color);
        transform: translateY(-1px);
        box-shadow: none;
    }

    .hero-quick-links {
        display: flex;
        justify-content: center;
        gap: 2rem;
        flex-wrap: wrap;
        margin-top: 2rem; /* added spacing between search bar and quick links */
    }

    .hero-quick-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #495057 !important;
        text-decoration: none;
        font-weight: 500;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(0, 0, 0, 0.1);
    }

    .hero-quick-link:hover {
        color: #2c3e50 !important;
        background: rgba(255, 255, 255, 0.95);
        transform: translateY(-1px);
        text-decoration: none;
        border-color: #2c3e50;
        box-shadow: none;
    }

    .hero-quick-link i {
        font-size: 0.75rem;
        opacity: 0.8;
    }

    .loading-placeholder {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-muted);
    }

    .loading-placeholder .spinner-border {
        width: 3rem;
        height: 3rem;
        border-width: 3px;
    }

    /* Responsive adjustments */
    /* Tablet: 2 columns */
    @media (max-width: 1024px) {
        .feature-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 2.5rem;
        }
    }

    /* Mobile: 1 column */
    @media (max-width: 768px) {
        .hero-section {
            padding: 6rem 0 4rem;
            min-height: 85vh;
            background-attachment: scroll;
        }

        .hero-title {
            font-size: 2.8rem;
        }

        .hero-subtitle {
            font-size: 1.2rem;
        }

        .section-title {
            font-size: 2.2rem;
        }

        .cta-title {
            font-size: 2.2rem;
        }

        .feature-grid {
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        .hero-stats {
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
    }

    @media (max-width: 480px) {
        .hero-title {
            font-size: 2.2rem;
        }

        .section-title {
            font-size: 1.8rem;
        }

        .cta-title {
            font-size: 1.8rem;
        }

        .hero-stats {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .hero-search-container {
            max-width: 100%;
            margin-bottom: 3rem;
        }

        .hero-search-form {
            margin-bottom: 2rem;
        }

        .hero-search-input-group {
            padding: 0.625rem 1.25rem;
        }

        .hero-search-input {
            font-size: 1rem;
        }

        .hero-search-button {
            padding: 0.625rem 1.25rem;
            font-size: 0.75rem;
        }

        .hero-quick-links {
            gap: 1rem;
        }

        .hero-quick-link {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
        }
    }
        h6.search-section-title {
    text-align: left;
}

    /* Genre Carousel Styles */
    .genre-carousel-section {
        padding: 4rem 0;
        background: var(--white);
    }

    .genre-carousel-section:nth-child(even) {
        background: var(--light-bg);
    }

    .genre-carousel-header {
        margin-bottom: 2.5rem;
        text-align: center;
    }

    .genre-carousel-title {
        font-size: 2rem;
        font-weight: 800;
        color: var(--primary-color);
        margin: 0;
        letter-spacing: -0.02em;
        text-align: center;
    }

    .carousel-container {
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 1rem;
        width: 100%;
    }

    .carousel-wrapper {
        overflow: hidden;
        width: 100%;
        grid-column: 2;
    }

    .carousel-nav-btn {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        border: none;
        background: #c0c0c0;
        color: white;
        font-size: 1.25rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0.8;
    }

    .carousel-nav-btn[data-direction=\"prev\"] {
        justify-self: end;
    }

    .carousel-nav-btn[data-direction=\"next\"] {
        justify-self: start;
    }

    .carousel-nav-btn:hover:not(:disabled) {
        background: #a0a0a0;
        opacity: 1;
        transform: scale(1.1);
    }

    .carousel-nav-btn:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    .carousel-track {
        display: flex;
        gap: 1.5rem;
        transition: transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        will-change: transform;
    }

    .carousel-book-card {
        flex: 0 0 280px;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .carousel-book-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.12);
        text-decoration: none;
    }

    .carousel-book-cover {
        width: 100%;
        height: 380px;
        object-fit: cover;
        background: var(--light-bg);
    }

    .carousel-book-info {
        padding: 1rem;
    }

    .carousel-book-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .carousel-book-author {
        font-size: 0.875rem;
        color: var(--text-light);
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .carousel-book-year {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    @media (max-width: 768px) {
        .genre-carousel-section {
            padding: 3rem 0;
        }

        .genre-carousel-title {
            font-size: 1.5rem;
        }

        .carousel-container {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            grid-template-areas:
                \"wrapper wrapper\"
                \"prev next\";
            row-gap: 1.5rem;
        }

        .carousel-wrapper {
            grid-area: wrapper;
        }

        .carousel-nav-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
        }

        .carousel-nav-btn[data-direction=\"prev\"] {
            grid-area: prev;
            justify-self: start;
        }

        .carousel-nav-btn[data-direction=\"next\"] {
            grid-area: next;
            justify-self: end;
        }

        .carousel-book-card {
            flex: 0 0 calc(100% - 1.5rem);
            max-width: 360px;
            margin: 0 auto;
        }

        .carousel-book-cover {
            height: 380px;
        }
    }

    @media (max-width: 480px) {
        .carousel-track {
            gap: 1rem;
        }

        .carousel-book-card {
            flex: 0 0 100%;
            max-width: 320px;
        }

        .carousel-book-cover {
            height: 380px;
        }

        .carousel-book-info {
            padding: 0.75rem;
        }
    }
</style>
";

ob_start();
?>

<!-- Hero Section -->
<section class="hero-section" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.7) 0%, rgba(0, 0, 0, 0.7) 100%), url('<?php echo htmlspecialchars($homeContent['hero']['background_image'] ?? '/assets/books.jpg', ENT_QUOTES, 'UTF-8'); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat;">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="hero-title"><?php echo htmlspecialchars($homeContent['hero']['title'] ?? __("La Tua Biblioteca Digitale"), ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="hero-subtitle">
                <?php echo htmlspecialchars($homeContent['hero']['subtitle'] ?? __("Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna."), ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <!-- Hero Search Bar -->
            <div class="hero-search-container">
                <form class="hero-search-form search-form" action="<?= $catalogRoute ?>" method="get">
                    <div class="hero-search-input-group">
                        <i class="fas fa-search hero-search-icon"></i>
                        <input type="search"
                               name="q"
                               class="hero-search-input search-input"
                               placeholder="<?= __("Cerca libri, autori, editori...") ?>"
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

<!-- Features Section -->
<?php if (!empty($homeContent['features_title'])): ?>
<section class="section section-alt">
    <div class="container">
        <h2 class="section-title"><?php echo htmlspecialchars($homeContent['features_title']['title'] ?? __("Perché Scegliere la Nostra Biblioteca"), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle">
            <?php echo htmlspecialchars($homeContent['features_title']['subtitle'] ?? __("Un'esperienza di lettura moderna, intuitiva e sempre a portata di mano"), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div class="feature-grid">
            <?php for ($i = 1; $i <= 4; $i++):
                $feature = $homeContent["feature_{$i}"] ?? [];
                $icon = $feature['content'] ?? 'fas fa-star';
                $title = $feature['title'] ?? sprintf(__("Feature %d"), $i);
                $desc = $feature['subtitle'] ?? '';
            ?>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                </div>
                <h3 class="feature-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="feature-description">
                    <?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Text Content Section -->
<?php if (!empty($homeContent['text_content'])): ?>
<section class="section section-alt">
    <div class="container">
        <?php if (!empty($homeContent['text_content']['title'])): ?>
        <h2 class="section-title"><?php echo htmlspecialchars($homeContent['text_content']['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php endif; ?>
        <div class="text-content-body" style="margin: 0 auto; font-size: 1.1rem; line-height: 1.8;">
            <?php echo $homeContent['text_content']['content'] ?? ''; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Latest Books Section -->
<?php if (!empty($homeContent['latest_books_title'])): ?>
<section id="latest-books" class="section">
    <div class="container">
        <h2 class="section-title"><?php echo htmlspecialchars($homeContent['latest_books_title']['title'] ?? __("Ultimi Libri Aggiunti"), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle">
            <?php echo htmlspecialchars($homeContent['latest_books_title']['subtitle'] ?? __("Scopri le ultime novità della nostra collezione"), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div id="latest-books-grid">
            <div class="loading-placeholder">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden"><?= __("Caricamento...") ?></span>
                </div>
                <p class="mt-3"><?= __("Caricamento libri...") ?></p>
            </div>
        </div>
        <div class="text-center mt-5">
            <button id="load-more-latest" class="btn-cta me-3" style="display: none;" type="button">
                <i class="fas fa-plus"></i>
                <?= __("Carica Altri") ?>
            </button>
            <a href="<?= $legacyCatalogRoute ?>" class="btn-cta">
                <i class="fas fa-th-large"></i>
                <?= __("Visualizza Tutto il Catalogo") ?>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Genre Carousels Section -->
<?php if (!empty($genres_with_books) && (!isset($genreCarouselEnabled) || $genreCarouselEnabled)): ?>
<?php
    $genreSectionContent = $homeContent['genre_carousel'] ?? [];
    $genreSectionTitle = !empty($genreSectionContent['title'])
        ? $genreSectionContent['title']
        : __("Esplora i generi principali");
    $genreSectionSubtitle = !empty($genreSectionContent['subtitle'])
        ? $genreSectionContent['subtitle']
        : __("Scopri le nostre radici tematiche e lasciati ispirare dai titoli disponibili.");
?>
<section id="genre-carousels" class="section">
    <div class="container text-center mb-5">
        <h2 class="section-title">
            <?= htmlspecialchars($genreSectionTitle, ENT_QUOTES, 'UTF-8'); ?>
        </h2>
        <?php if (!empty($genreSectionSubtitle)): ?>
        <p class="section-subtitle">
            <?= htmlspecialchars($genreSectionSubtitle, ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <?php endif; ?>
    </div>

    <?php foreach ($genres_with_books as $index => $genreData):
        $genre = $genreData['genre'];
        $books = $genreData['books'];

        if (empty($books)) continue;

        $carouselId = 'carousel-' . $genre['id'];
    ?>
    <div class="genre-carousel-section">
        <div class="container">
            <div class="genre-carousel-header">
                <h2 class="genre-carousel-title">
                    <?php echo htmlspecialchars($genre['nome'], ENT_QUOTES, 'UTF-8'); ?>
                </h2>
            </div>

            <div class="carousel-container">
                <button class="carousel-nav-btn" data-carousel="<?php echo $carouselId; ?>" data-direction="prev" aria-label="<?php echo __("Precedente"); ?>">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <div class="carousel-wrapper">
                    <div class="carousel-track" id="<?php echo $carouselId; ?>">
                    <?php foreach ($books as $book):
                        $bookDetailUrl = book_url($book);

                        // Use same image logic as home-books-grid.php
                        $coverUrl = $book['copertina_url'] ?? $book['immagine_copertina'] ?? '/uploads/copertine/default-cover.jpg';
                        $absoluteCoverUrl = (strpos($coverUrl, 'http') === 0) ? $coverUrl : ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $coverUrl);
                        $defaultCoverUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/uploads/copertine/default-cover.jpg';
                    ?>
                    <a href="<?php echo $bookDetailUrl; ?>" class="carousel-book-card">
                        <img src="<?php echo htmlspecialchars($absoluteCoverUrl, ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo htmlspecialchars($book['titolo'], ENT_QUOTES, 'UTF-8'); ?>"
                             class="carousel-book-cover"
                             loading="lazy"
                             onerror="this.src='<?php echo htmlspecialchars($defaultCoverUrl, ENT_QUOTES, 'UTF-8'); ?>'">
                        <div class="carousel-book-info">
                            <h3 class="carousel-book-title">
                                <?php echo htmlspecialchars($book['titolo'], ENT_QUOTES, 'UTF-8'); ?>
                            </h3>
                            <?php if (!empty($book['autore'])): ?>
                            <p class="carousel-book-author">
                                <?php echo htmlspecialchars($book['autore'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!empty($book['anno_pubblicazione'])): ?>
                            <p class="carousel-book-year">
                                <?php echo htmlspecialchars($book['anno_pubblicazione'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <button class="carousel-nav-btn" data-carousel="<?php echo $carouselId; ?>" data-direction="next" aria-label="<?php echo __("Successivo"); ?>">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- Call to Action Section -->
<?php if (!empty($homeContent['cta'])): ?>
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <h2 class="cta-title"><?php echo htmlspecialchars($homeContent['cta']['title'] ?? __("Inizia la Tua Avventura Letteraria"), ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="cta-subtitle">
                <?php echo htmlspecialchars($homeContent['cta']['subtitle'] ?? __("Unisciti alla nostra community di lettori e scopri il piacere della lettura con la nostra piattaforma moderna."), ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="<?php echo htmlspecialchars($homeContent['cta']['button_link'] ?? $registerRoute, ENT_QUOTES, 'UTF-8'); ?>" class="btn-cta">
                    <i class="fas fa-user-plus"></i>
                    <?php echo htmlspecialchars($homeContent['cta']['button_text'] ?? __("Registrati Ora"), ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <a href="/contatti" class="btn-cta">
                    <i class="fas fa-envelope"></i>
                    <?= __("Contattaci") ?>
                </a>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$additional_js = "
<script>
// Traduzioni per JavaScript
const i18n = {
    loading: '" . addslashes(__("Caricamento...")) . "',
    loadingBooks: '" . addslashes(__("Caricamento libri...")) . "',
    loadingCategories: '" . addslashes(__("Caricamento categorie...")) . "',
    errorLoadingBooks: '" . addslashes(__("Errore nel caricamento dei libri")) . "',
    exploreByCategory: '" . addslashes(__("Esplora per Categoria")) . "',
    viewAllCategories: '" . addslashes(__("Visualizza Tutte le Categorie")) . "'
};

let currentLatestPage = 1;
let hasMoreLatestBooks = true;
const API_CATALOG_ROUTE = {$apiCatalogRouteJs};

// Load initial content
document.addEventListener('DOMContentLoaded', function() {
    loadStats();
    loadLatestBooks();
    initCarousels();
    initLoadMoreButton();
});

function loadStats() {
    const totalBooksEl = document.getElementById('total-books');
    const availableBooksEl = document.getElementById('available-books');

    // Only load stats if elements exist
    if (!totalBooksEl || !availableBooksEl) return;

    fetch(API_CATALOG_ROUTE)
        .then(response => response.json())
        .then(data => {
            totalBooksEl.innerHTML = data.pagination.total_books;
            availableBooksEl.innerHTML = '📚';
        })
        .catch(error => {
            console.error('Error loading stats:', error);
            totalBooksEl.innerHTML = '📚';
            availableBooksEl.innerHTML = '✓';
        });
}

function loadLatestBooks(page = 1) {
    const grid = document.getElementById('latest-books-grid');

    // Only load if grid exists (section is active)
    if (!grid) return;

    if (page === 1) {
        grid.innerHTML = '<div class=\"loading-placeholder\"><div class=\"spinner-border text-primary\" role=\"status\"><span class=\"visually-hidden\">' + i18n.loading + '</span></div><p class=\"mt-3\">' + i18n.loadingBooks + '</p></div>';
    }

    fetch('/api/home/latest?page=' + page)
        .then(response => response.json())
        .then(data => {
            if (page === 1) {
                grid.innerHTML = data.html;
            } else {
                grid.innerHTML += data.html;
            }

            currentLatestPage = data.pagination.current_page;
            hasMoreLatestBooks = data.pagination.current_page < data.pagination.total_pages;

            const loadMoreBtn = document.getElementById('load-more-latest');
            if (loadMoreBtn) {
                if (hasMoreLatestBooks) {
                    loadMoreBtn.style.display = 'inline-flex';
                } else {
                    loadMoreBtn.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error loading latest books:', error);
            grid.innerHTML = '<div class=\"col-12 text-center py-4\"><div class=\"alert alert-danger\">' + i18n.errorLoadingBooks + '</div></div>';
        });
}

// Initialize carousels
function initCarousels() {
    const AUTO_SCROLL_DELAY = 5000;
    const carousels = document.querySelectorAll('.carousel-track');

    carousels.forEach(carousel => {
        const carouselId = carousel.id;
        const prevBtn = document.querySelector('[data-carousel=\"' + carouselId + '\"][data-direction=\"prev\"]');
        const nextBtn = document.querySelector('[data-carousel=\"' + carouselId + '\"][data-direction=\"next\"]');
        const container = prevBtn ? prevBtn.closest('.carousel-container') : null;
        const wrapper = container ? container.querySelector('.carousel-wrapper') : null;

        if (!prevBtn || !nextBtn || !container || !wrapper) return;

        const cards = carousel.querySelectorAll('.carousel-book-card');
        if (cards.length === 0) return;

        let currentIndex = 0;
        let autoplayTimer = null;
        let metrics = calculateMetrics();

        function calculateMetrics() {
            const computed = window.getComputedStyle(carousel);
            const gapValue = parseFloat(computed.gap || computed.columnGap || 0) || 0;
            const cardWidth = cards[0].offsetWidth || 0;
            const step = (cardWidth + gapValue) || wrapper.offsetWidth || 1;
            const wrapperWidth = wrapper.offsetWidth || 0;
            const visibleCount = step > 0 ? Math.max(1, Math.round(wrapperWidth / step)) : 1;
            const maxIndex = Math.max(0, cards.length - visibleCount);
            return { step, maxIndex };
        }

        function goTo(index) {
            if (metrics.maxIndex === 0) {
                currentIndex = 0;
            } else if (index > metrics.maxIndex) {
                currentIndex = 0;
            } else if (index < 0) {
                currentIndex = metrics.maxIndex;
            } else {
                currentIndex = index;
            }

            const offset = -(currentIndex * metrics.step);
            carousel.style.transform = 'translateX(' + offset + 'px)';
        }

        function handleNext() {
            goTo(currentIndex + 1);
        }

        function handlePrev() {
            goTo(currentIndex - 1);
        }

        function startAutoplay() {
            if (cards.length <= 1) return;
            stopAutoplay();
            autoplayTimer = setInterval(() => goTo(currentIndex + 1), AUTO_SCROLL_DELAY);
        }

        function stopAutoplay() {
            if (autoplayTimer) {
                clearInterval(autoplayTimer);
                autoplayTimer = null;
            }
        }

        function restartAutoplay() {
            stopAutoplay();
            startAutoplay();
        }

        prevBtn.addEventListener('click', () => {
            handlePrev();
            restartAutoplay();
        });

        nextBtn.addEventListener('click', () => {
            handleNext();
            restartAutoplay();
        });

        container.addEventListener('mouseenter', stopAutoplay);
        container.addEventListener('mouseleave', startAutoplay);
        container.addEventListener('touchstart', stopAutoplay, { passive: true });
        container.addEventListener('touchend', startAutoplay);
        container.addEventListener('focusin', stopAutoplay);
        container.addEventListener('focusout', startAutoplay);

        const recalibrate = debounce(() => {
            metrics = calculateMetrics();
            goTo(currentIndex);
        }, 200);

        window.addEventListener('resize', recalibrate);

        goTo(0);
        startAutoplay();
    });
}

function debounce(fn, delay) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), delay);
    };
}
// Initialize load more button listener
function initLoadMoreButton() {
    const loadMoreBtn = document.getElementById('load-more-latest');

    // Only attach listener if button exists (section is active)
    if (!loadMoreBtn) return;

    loadMoreBtn.addEventListener('click', function() {
        if (hasMoreLatestBooks) {
            loadLatestBooks(currentLatestPage + 1);
        }
    });
}
</script>
";

$content = ob_get_clean();
include 'layout.php';
?>
