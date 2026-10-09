<?php
/**
 * Hero (2026 design): the CMS title and subtitle, the search, two quick links
 * and a fan of book covers, then the counters strip. The covers are the latest
 * catalogued ones, or the books picked in the CMS (FrontendController::
 * heroCovers()). The last word of the title is set in italics in the accent
 * colour, as the design does with "digitale".
 */
$heroData = $section ?? [];
$catalogRoute = $catalogRoute ?? route_path('catalog');
$heroButtonText = trim((string)($heroData['button_text'] ?? ''));
$heroButtonText = $heroButtonText !== '' ? $heroButtonText : __('Sfoglia Catalogo');
$heroButtonPath = trim((string)($heroData['button_link'] ?? ''));
$heroButtonLink = $heroButtonPath !== '' ? url($heroButtonPath) : $catalogRoute;
$latestBooksText = trim((string)($homeContent['latest_books_title']['title'] ?? ''));
$latestBooksText = $latestBooksText !== '' ? $latestBooksText : __('Ultimi Arrivi');
$heroTitle = trim((string)($heroData['title'] ?? '')) !== '' ? (string) $heroData['title'] : __("La Tua Biblioteca Digitale");
$heroSubtitle = trim((string)($heroData['subtitle'] ?? '')) !== '' ? (string) $heroData['subtitle'] : __("Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna.");
$heroCovers = $heroCovers ?? [];
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

// "La tua biblioteca digitale" -> "La tua biblioteca <em>digitale</em>".
$heroWords = preg_split('/\s+/u', trim($heroTitle)) ?: [];
$heroTitleHtml = count($heroWords) > 1
    ? $e(implode(' ', array_slice($heroWords, 0, -1))) . ' <em>' . $e((string) end($heroWords)) . '</em>'
    : $e($heroTitle);

// Counters are precomputed (and cached) server-side by FrontendController::
// home(); the client-side loadStats() fetch only runs as a fallback when the
// values are missing (data-server-rendered absent).
$heroStatsServerRendered = isset($heroTotalBooks, $heroAvailableBooks);
$edgeCacheEnabled = \App\Support\LiteSpeedCache::enabled();
// Thousands grouped as the visitor's language writes them (1.234 / 1,234 / 1 234).
$heroLocale = strtolower(substr(\App\Support\I18n::getLocale(), 0, 2));
$heroThousands = match ($heroLocale) {
    'en' => ',',
    'fr' => "\u{202F}",
    default => '.',
};
$heroNumber = static fn (int $n): string => number_format($n, 0, ',', $heroThousands);
$spinner = '<span class="inline-block animate-spin rounded-full border-2 border-current border-r-transparent" role="status" style="width:1.6rem;height:1.6rem;"><span class="sr-only">' . $e(__("Caricamento...")) . '</span></span>';
?>
<section class="hero-section pk-hero" data-section="hero">
    <div class="pk-hero__inner hero-content">
        <div class="pk-hero__text">
            <?php if ($heroStatsServerRendered): ?>
            <div class="pk-hero__badge"><?= $e(sprintf(__('%s titoli in catalogo'), $heroNumber((int) $heroTotalBooks))) ?></div>
            <?php endif; ?>
            <h1 class="hero-title pk-hero__title"><?= $heroTitleHtml ?></h1>
            <p class="hero-subtitle pk-hero__subtitle"><?= $e($heroSubtitle) ?></p>

            <div class="hero-search-container">
                <form class="hero-search-form search-form" action="<?= $e($catalogRoute) ?>" method="get">
                    <div class="hero-search-input-group pk-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                        <input type="search" name="q" class="hero-search-input search-input"
                               placeholder="<?= $e(__("Titolo, autore, editore o ISBN")) ?>"
                               aria-label="<?= $e(__("Cerca nella biblioteca")) ?>">
                        <button type="submit" class="hero-search-button"><?= __("Cerca") ?></button>
                    </div>
                </form>
            </div>

            <div class="hero-quick-links pk-hero__links">
                <a href="#latest-books" class="hero-quick-link"><i class="fas fa-book" aria-hidden="true"></i><?= $e($latestBooksText) ?> <span aria-hidden="true">↓</span></a>
                <a href="<?= $e($heroButtonLink) ?>" class="hero-quick-link"><i class="fas fa-list" aria-hidden="true"></i><?= $e($heroButtonText) ?> <span aria-hidden="true">→</span></a>
            </div>
        </div>

        <?php if ($heroCovers !== []): ?>
        <div class="pk-fan" role="group" aria-label="<?= $e(__('Copertine in evidenza')) ?>">
            <?php foreach (array_slice($heroCovers, 0, 4) as $cover): ?>
                <?php $coverTitle = html_entity_decode((string) ($cover['titolo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                <a class="pk-fan__book" href="<?= $e(book_url($cover)) ?>" title="<?= $e($coverTitle) ?>">
                    <span class="pk-book__blank" aria-hidden="true"><span class="pk-book__blank-title"><?= $e($coverTitle) ?></span><span class="pk-book__rule"></span></span>
                    <img class="pk-book__img" src="<?= $e(absoluteUrl((string) $cover['copertina_url'])) ?>" alt="<?= $e($coverTitle) ?>" decoding="async" onerror="this.onerror=null;this.classList.add('is-missing')">
                    <span class="pk-book__spine" aria-hidden="true"></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="pk-stats hero-stats" aria-label="<?= $e(__('La biblioteca in cifre')) ?>">
    <div class="pk-stats__inner">
        <div class="pk-stat hero-stat">
            <span class="pk-stat__n hero-stat-number" id="total-books"<?= $heroStatsServerRendered ? ' data-server-rendered="1"' : '' ?>><?= $heroStatsServerRendered ? (int) $heroTotalBooks : $spinner ?></span>
            <span class="pk-stat__l hero-stat-label"><?= __("Libri Totali") ?></span>
        </div>
        <div class="pk-stat hero-stat">
            <span class="pk-stat__n pk-stat__n--accent hero-stat-number" id="available-books"<?= $heroStatsServerRendered && !$edgeCacheEnabled ? ' data-server-rendered="1"' : '' ?><?= $edgeCacheEnabled ? ' data-live-stat="available_books" data-live-pending="1"' : '' ?>><?= $edgeCacheEnabled || !$heroStatsServerRendered ? $spinner : (int) $heroAvailableBooks ?></span>
            <span class="pk-stat__l hero-stat-label"><?= __("Disponibili") ?></span>
        </div>
        <?php if (isset($heroTotalGenres)): ?>
        <div class="pk-stat hero-stat">
            <span class="pk-stat__n hero-stat-number"><?= (int) $heroTotalGenres ?></span>
            <span class="pk-stat__l hero-stat-label"><?= __("Categorie") ?></span>
        </div>
        <?php endif; ?>
        <div class="pk-stat hero-stat">
            <span class="pk-stat__n hero-stat-number">24/7</span>
            <span class="pk-stat__l hero-stat-label"><?= __("Sempre Online") ?></span>
        </div>
    </div>
</section>
