<?php
use App\Support\HtmlHelper;

/**
 * Genre Carousel Section Template
 * Displays genre-based book carousels and optional events section
 */
$genreSectionContent = $section ?? [];
$genreSectionTitle = !empty($genreSectionContent['title'])
    ? $genreSectionContent['title']
    : __("Esplora i generi principali");
$genreSectionSubtitle = !empty($genreSectionContent['subtitle'])
    ? $genreSectionContent['subtitle']
    : __("Scopri le nostre radici tematiche e lasciati ispirare dai titoli disponibili.");
?>

<?php if (!empty($genres_with_books)): ?>
<!-- Genre Carousels Section -->
<section id="genre-carousels" class="section" data-section="genre_carousel">
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

    <?php
    $defaultCoverUrl = absoluteUrl('/uploads/copertine/placeholder.jpg');
    foreach ($genres_with_books as $index => $genreData):
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
                    <?php
                    foreach ($books as $book):
                        $bookDetailUrl = book_url($book);

                        // Use same image logic as home-books-grid.php
                        $coverUrl = ($book['copertina_url'] ?? '') ?: ($book['immagine_copertina'] ?? '') ?: '/uploads/copertine/placeholder.jpg';
                        $absoluteCoverUrl = absoluteUrl($coverUrl);
                    ?>
                    <a href="<?php echo htmlspecialchars($bookDetailUrl, ENT_QUOTES, 'UTF-8'); ?>" class="carousel-book-card">
                        <img src="<?php echo htmlspecialchars($absoluteCoverUrl, ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo htmlspecialchars($book['titolo'], ENT_QUOTES, 'UTF-8'); ?>"
                             class="carousel-book-cover"
                             loading="lazy"
                             onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($defaultCoverUrl, ENT_QUOTES, 'UTF-8'); ?>'">
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
