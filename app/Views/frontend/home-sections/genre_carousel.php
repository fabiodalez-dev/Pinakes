<?php
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
<!-- Events Section (if enabled) -->
<?php if ($homeEventsEnabled && !empty($homeEvents)): ?>
    <?php
    $homeEventsLocale = $_SESSION['locale'] ?? 'it_IT';
    $homeEventsDateFormatter = new \IntlDateFormatter($homeEventsLocale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
    $homeEventsCreateDateTime = static function (?string $value) {
        if (!$value) {
            return null;
        }

        $dateTime = \DateTime::createFromFormat('Y-m-d', $value);
        if ($dateTime instanceof \DateTimeInterface) {
            return $dateTime;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception $e) {
            return null;
        }
    };
    $homeEventsFormatDate = static function (?string $value) use ($homeEventsDateFormatter, $homeEventsCreateDateTime) {
        $dateTime = $homeEventsCreateDateTime($value);
        if (!$dateTime) {
            return (string)$value;
        }
        return $homeEventsDateFormatter->format($dateTime);
    };
    ?>
    <section class="home-events" aria-label="<?= __("Eventi") ?>">
        <div class="container">
            <div class="home-events__header">
                <div>
                    <p class="page-hero__eyebrow"><?= __("Calendario eventi") ?></p>
                    <h2 class="home-events__title"><?= __("Gli appuntamenti della biblioteca") ?></h2>
                    <p class="home-events__subtitle">
                        <?= __("In questa pagina trovi tutti gli eventi, gli incontri e i laboratori organizzati dalla biblioteca.") ?>
                    </p>
                </div>
                <a href="/events" class="home-events__all-link">
                    <?= __("Vedi tutti gli eventi") ?>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="home-events-grid">
                <?php foreach ($homeEvents as $event): ?>
                    <?php $eventDateText = $homeEventsFormatDate($event['event_date'] ?? ''); ?>
                    <article class="event-card">
                        <a href="/events/<?= htmlspecialchars($event['slug'], ENT_QUOTES, 'UTF-8') ?>" class="event-card__thumb">
                            <?php if (!empty($event['featured_image'])): ?>
                                <img src="<?= htmlspecialchars($event['featured_image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php else: ?>
                                <div class="event-card__placeholder">
                                    <i class="fas fa-calendar"></i>
                                </div>
                            <?php endif; ?>
                        </a>
                        <div class="event-card__body">
                            <div class="event-card__meta">
                                <?= htmlspecialchars($eventDateText, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <h3 class="event-card__title">
                                <a href="/events/<?= htmlspecialchars($event['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </h3>
                            <a href="/events/<?= htmlspecialchars($event['slug'], ENT_QUOTES, 'UTF-8') ?>" class="event-card__button">
                                <?= __("Scopri l'evento") ?>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

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
                        $coverUrl = $book['copertina_url'] ?? $book['immagine_copertina'] ?? '/uploads/copertine/placeholder.jpg';
                        $absoluteCoverUrl = (strpos($coverUrl, 'http') === 0) ? $coverUrl : ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $coverUrl);
                        $defaultCoverUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/uploads/copertine/placeholder.jpg';
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
