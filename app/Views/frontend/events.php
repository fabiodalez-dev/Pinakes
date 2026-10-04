<?php
/** @var string $seoCanonical */
/** @var string $seoTitle */
/** @var string $seoDescription */
/** @var int $totalPages */
/** @var int $page */

use App\Support\ConfigStore;
use App\Support\HtmlHelper;

$title = __("Eventi");
$appName = ConfigStore::get('app.name');
$baseUrl = ConfigStore::get('app.canonical_url');

// SEO meta values are provided by the controller:
// $events, $page, $totalPages, $seoTitle, $seoDescription, $seoCanonical

// Open Graph defaults
$ogTitle = $seoTitle;
$ogDescription = $seoDescription;
$ogImage = assetUrl('social.jpg');
$ogUrl = $seoCanonical;
$ogType = 'website';

// Twitter Card defaults
$twitterCard = 'summary_large_image';
$twitterTitle = $seoTitle;
$twitterDescription = $seoDescription;
$twitterImage = $ogImage;

// Session locale wins (unchanged); on the sessionless anonymous path
// (issue #387 step 6) fall back to the request locale resolved by
// public/index.php from the pinakes_locale cookie / install default.
$locale = $_SESSION['locale'] ?? (\App\Support\I18n::getLocale() ?: 'it_IT');
if (class_exists('IntlDateFormatter')) {
    $dateFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
    $timeFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);
} else {
    $dateFormatter = null;
    $timeFormatter = null;
}

$createDateTime = static function (?string $value, array $formats = []) {
    if (!$value) {
        return null;
    }

    foreach ($formats as $format) {
        $dateTime = \DateTime::createFromFormat($format, $value);
        if ($dateTime instanceof \DateTimeInterface) {
            return $dateTime;
        }
    }

    try {
        return new \DateTime($value);
    } catch (\Throwable $e) {
        return null;
    }
};

$fallbackDateFormat = match (strtolower(substr($locale, 0, 2))) {
    'de' => 'd.m.Y',
    'it' => 'd/m/Y',
    default => 'Y-m-d',
};

$formatDate = static function (?string $date) use ($dateFormatter, $createDateTime, $fallbackDateFormat) {
    $dateTime = $createDateTime($date, ['Y-m-d']);
    if (!$dateTime) {
        return (string)$date;
    }

    if ($dateFormatter) {
        $formatted = $dateFormatter->format($dateTime);
        if ($formatted !== false) {
            return $formatted;
        }
    }
    return $dateTime->format($fallbackDateFormat);
};

$formatTime = static function (?string $time) use ($timeFormatter, $createDateTime) {
    $dateTime = $createDateTime($time, ['H:i:s', 'H:i']);
    if (!$dateTime) {
        return (string)$time;
    }

    if ($timeFormatter) {
        $formatted = $timeFormatter->format($dateTime);
        if ($formatted !== false) {
            return $formatted;
        }
    }
    return $dateTime->format('H:i');
};

$catalogPageStyles = true;
$corePartials = __DIR__ . '/partials';

// Event cards are book cards with a landscape image box (events have posters,
// not 2:3 covers); everything else comes from catalog-pages.css.
$additional_css = "
    .events-listing { padding-top: 2.5rem; padding-bottom: 4rem; }
    .books-grid--events { grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr)); }
    .book-card--event .book-image-container { aspect-ratio: 4 / 3; display: flex; align-items: center; justify-content: center; }
    .book-card--event .book-image { object-fit: cover; }
    .book-card--event .book-image-icon { font-size: 3rem; color: var(--text-muted); opacity: 0.6; }
";

ob_start();

$heroTitle = __("Eventi");
$heroSubtitle = __("In questa pagina trovi tutti gli eventi, gli incontri e i laboratori organizzati dalla biblioteca.");
$breadcrumbItems = [['label' => __('Home'), 'href' => url('/')], ['label' => __("Eventi")]];
include $corePartials . '/catalog-hero.php';
?>

<div class="container events-listing">
    <?php if (empty($events)): ?>
        <?php
        $emptyIcon = 'fa-calendar-times';
        $emptyTitle = __("Nessun evento in programma");
        $emptyText = __("Al momento non ci sono eventi attivi. Continua a seguirci per restare aggiornato sui prossimi appuntamenti.");
        include $corePartials . '/empty-state.php';
        ?>
    <?php else: ?>
        <div class="books-grid books-grid--events">
            <?php foreach ($events as $event): ?>
                <?php
                $eventDateFormatted = $formatDate($event['event_date'] ?? '');
                $eventTimeFormatted = $formatTime($event['event_time'] ?? '');
                $eventMeta = trim($eventDateFormatted . ($eventTimeFormatted !== '' ? ' · ' . $eventTimeFormatted : ''));
                $eventUrl = htmlspecialchars(route_path('events') . '/' . rawurlencode($event['slug']), ENT_QUOTES, 'UTF-8');
                ?>
                <article class="book-card book-card--event">
                    <div class="book-image-container">
                        <a href="<?= $eventUrl ?>" tabindex="-1" aria-hidden="true" class="flex w-full h-full items-center justify-center">
                            <?php if (!empty($event['featured_image'])): ?>
                                <img class="book-image" src="<?= htmlspecialchars(url($event['featured_image']), ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
                            <?php else: ?>
                                <i class="fas fa-calendar-alt book-image-icon" aria-hidden="true"></i>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="book-content">
                        <h2 class="book-title"><a href="<?= $eventUrl ?>"><?= HtmlHelper::e($event['title']) ?></a></h2>
                        <?php if ($eventMeta !== ''): ?><p class="book-meta"><?= HtmlHelper::e($eventMeta) ?></p><?php endif; ?>
                        <div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $eventUrl ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __("Dettagli") ?></a></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php
        $paginationPage = (int) $page;
        $paginationPages = (int) $totalPages;
        $paginationUrl = static fn(int $p): string => route_path('events') . ($p > 1 ? '?page=' . $p : '');
        include $corePartials . '/pagination.php';
        ?>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
