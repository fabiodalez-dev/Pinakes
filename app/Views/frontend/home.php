<?php
$title = "Biblioteca Digitale - La tua biblioteca online";

// SEO Variables
$seoTitle = "Biblioteca Digitale - Scopri e Prenota i Tuoi Libri Preferiti";
$seoDescription = "Esplora il nostro vasto catalogo di libri, prenota i tuoi titoli preferiti e scopri nuove letture. Sistema di prestito moderno e intuitivo con ricerca avanzata e categorie organizzate.";
$seoCanonical = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
$seoImage = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/uploads/copertine/default-cover.jpg';

// Schema.org structured data
$seoSchema = json_encode([
    "@context" => "https://schema.org",
    "@type" => "Library",
    "name" => "Biblioteca Digitale",
    "description" => $seoDescription,
    "url" => $seoCanonical,
    "image" => $seoImage,
    "address" => [
        "@type" => "PostalAddress",
        "addressCountry" => "IT"
    ],
    "potentialAction" => [
        "@type" => "SearchAction",
        "target" => [
            "@type" => "EntryPoint",
            "urlTemplate" => $seoCanonical . "catalogo?q={search_term_string}"
        ],
        "query-input" => "required name=search_term_string"
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        margin-bottom: 4rem;
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
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
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
</style>
";

ob_start();
?>

<!-- Hero Section -->
<section class="hero-section" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.7) 0%, rgba(0, 0, 0, 0.7) 100%), url('<?php echo htmlspecialchars($homeContent['hero']['background_image'] ?? '/uploads/assets/books.jpg', ENT_QUOTES, 'UTF-8'); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat;">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="hero-title"><?php echo htmlspecialchars($homeContent['hero']['title'] ?? 'La Tua Biblioteca Digitale', ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="hero-subtitle">
                <?php echo htmlspecialchars($homeContent['hero']['subtitle'] ?? 'Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna.', ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <!-- Hero Search Bar -->
            <div class="hero-search-container">
                <form class="hero-search-form search-form" action="/catalogo" method="get">
                    <div class="hero-search-input-group">
                        <i class="fas fa-search hero-search-icon"></i>
                        <input type="search"
                               name="q"
                               class="hero-search-input search-input"
                               placeholder="Cerca libri, autori, editori..."
                               aria-label="Cerca nella biblioteca">
                        <button type="submit" class="hero-search-button">
                            Cerca
                        </button>
                    </div>
                </form>

                <!-- Quick links -->
                <div class="hero-quick-links">
                    <a href="#latest-books" class="hero-quick-link">
                        <i class="fas fa-book"></i>
                        Ultimi Arrivi
                    </a>
                    <a href="/catalogo" class="hero-quick-link">
                        <i class="fas fa-list"></i>
                        Sfoglia Catalogo
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
                    <span class="hero-stat-label">Libri Totali</span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-number" id="available-books">
                        <div class="spinner-border" role="status" style="width: 2rem; height: 2rem;">
                            <span class="visually-hidden"><?= __("Caricamento...") ?></span>
                        </div>
                    </span>
                    <span class="hero-stat-label">Disponibili</span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-number">12</span>
                    <span class="hero-stat-label">Categorie</span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-number">24/7</span>
                    <span class="hero-stat-label">Sempre Online</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<?php if (!empty($homeContent['features_title'])): ?>
<section class="section section-alt">
    <div class="container">
        <h2 class="section-title"><?php echo htmlspecialchars($homeContent['features_title']['title'] ?? 'Perché Scegliere la Nostra Biblioteca', ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle">
            <?php echo htmlspecialchars($homeContent['features_title']['subtitle'] ?? 'Un\'esperienza di lettura moderna, intuitiva e sempre a portata di mano', ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div class="feature-grid">
            <?php for ($i = 1; $i <= 4; $i++):
                $feature = $homeContent["feature_{$i}"] ?? [];
                $icon = $feature['content'] ?? 'fas fa-star';
                $title = $feature['title'] ?? "Feature {$i}";
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
        <h2 class="section-title"><?php echo htmlspecialchars($homeContent['latest_books_title']['title'] ?? 'Ultimi Libri Aggiunti', ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle">
            <?php echo htmlspecialchars($homeContent['latest_books_title']['subtitle'] ?? 'Scopri le ultime novità della nostra collezione', ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div id="latest-books-grid">
            <div class="loading-placeholder">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden"><?= __("Caricamento...") ?></span>
                </div>
                <p class="mt-3">Caricamento libri...</p>
            </div>
        </div>
        <div class="text-center mt-5">
            <button id="load-more-latest" class="btn-cta me-3" style="display: none;" type="button">
                <i class="fas fa-plus"></i>
                Carica Altri
            </button>
            <a href="/catalogo.php" class="btn-cta">
                <i class="fas fa-th-large"></i>
                Visualizza Tutto il Catalogo
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Categories Section -->
<div id="categories-sections">
    <div class="loading-placeholder">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden"><?= __("Caricamento...") ?></span>
        </div>
        <p class="mt-3">Caricamento categorie...</p>
    </div>
</div>

<!-- Call to Action Section -->
<?php if (!empty($homeContent['cta'])): ?>
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <h2 class="cta-title"><?php echo htmlspecialchars($homeContent['cta']['title'] ?? 'Inizia la Tua Avventura Letteraria', ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="cta-subtitle">
                <?php echo htmlspecialchars($homeContent['cta']['subtitle'] ?? 'Unisciti alla nostra community di lettori e scopri il piacere della lettura con la nostra piattaforma moderna.', ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="<?php echo htmlspecialchars($homeContent['cta']['button_link'] ?? '/register', ENT_QUOTES, 'UTF-8'); ?>" class="btn-cta">
                    <i class="fas fa-user-plus"></i>
                    <?php echo htmlspecialchars($homeContent['cta']['button_text'] ?? 'Registrati Ora', ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <a href="/contatti" class="btn-cta">
                    <i class="fas fa-envelope"></i>
                    Contattaci
                </a>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$additional_js = "
<script>
let currentLatestPage = 1;
let hasMoreLatestBooks = true;

// Load initial content
document.addEventListener('DOMContentLoaded', function() {
    loadStats();
    loadLatestBooks();
    loadCategories();
    initLoadMoreButton();
});

function loadStats() {
    const totalBooksEl = document.getElementById('total-books');
    const availableBooksEl = document.getElementById('available-books');

    // Only load stats if elements exist
    if (!totalBooksEl || !availableBooksEl) return;

    fetch('/api/catalogo')
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
        grid.innerHTML = '<div class=\"loading-placeholder\"><div class=\"spinner-border text-primary\" role=\"status\"><span class=\"visually-hidden\"><?= __("Caricamento...") ?></span></div><p class=\"mt-3\">Caricamento libri...</p></div>';
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
            grid.innerHTML = '<div class=\"col-12 text-center py-4\"><div class=\"alert alert-danger\">Errore nel caricamento dei libri</div></div>';
        });
}

function loadCategories() {
    const container = document.getElementById('categories-sections');

    // Only load if container exists
    if (!container) return;

    fetch('/api/catalogo')
        .then(response => response.json())
        .then(data => {
            container.innerHTML = '<section class=\"py-5\" style=\"background: var(--light-bg);\"><div class=\"container\"><h2 class=\"section-title\">Esplora per Categoria</h2><div class=\"text-center\"><a href=\"/catalogo.php\" class=\"btn-cta btn-cta-lg\"><i class=\"fas fa-th-large me-2\"></i>Visualizza Tutte le Categorie</a></div></div></section>';
        })
        .catch(error => {
            console.error('Error loading categories:', error);
            container.innerHTML = '';
        });
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
