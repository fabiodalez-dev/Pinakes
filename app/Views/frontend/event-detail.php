<?php
/** @var \mysqli $db */
/** @var array $event */
/** @var string|null $eventImageLayout One of: full | banner | contained | thumb (default: contained); may be null on defensive-fallback rendering paths */

use App\Support\ConfigStore;
use App\Support\HtmlHelper;
use App\Support\ContentSanitizer;

$title = $event['title'];
$appName = ConfigStore::get('app.name');
$baseUrl = ConfigStore::get('app.canonical_url');

// SEO variables are set in the controller:
// $seoTitle, $seoDescription, $seoKeywords, $seoCanonical
// $ogTitle, $ogDescription, $ogType, $ogUrl, $ogImage
// $twitterCard, $twitterTitle, $twitterDescription, $twitterImage

$contentHtml = ContentSanitizer::normalizeExternalAssets($event['content'] ?? '');

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

$eventDateFormatted = $formatDate($event['event_date'] ?? null);
$eventTimeFormatted = $formatTime($event['event_time'] ?? null);


$catalogPageStyles = true;
$bookDetailStyles = true;
$corePartials = __DIR__ . '/partials';

// $eventImageLayout is set by the controller and re-validated there against
// the allow-list; re-narrow here as defense in depth. 'contained' shows the
// image as the hero cover; wide layouts (full, banner) keep the hero plain and
// show the image across the body, in a <figure class="event-cover
// event-cover--full|--banner">. 'thumb' is no longer offered in the settings
// and the controllers read it as 'contained'; it is still accepted here so a
// stale value can never fall through to a body figure.
$coverAllowed    = ['full', 'banner', 'contained', 'thumb'];
$requestedLayout = (string)($eventImageLayout ?? 'contained');
$coverLayout     = in_array($requestedLayout, $coverAllowed, true) ? $requestedLayout : 'contained';
$hasCoverImage   = !empty($event['featured_image']);
$coverInHero     = $hasCoverImage && in_array($coverLayout, ['contained', 'thumb'], true);
$eventPlace      = trim((string) ConfigStore::get('app.address', ''));

// Only what the shared sheets do not cover: a wide image in the body.
$additional_css = "
    .event-cover { margin: 0 0 2rem; border-radius: 3px; overflow: hidden; }
    .event-cover img { display: block; width: 100%; height: auto; }
    .event-cover--banner img { height: 220px; object-fit: cover; }
    .books-grid--events { grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr)); }
    .book-card--event .book-image-container { aspect-ratio: 4 / 3; display: flex; align-items: center; justify-content: center; }
    .book-card--event .book-image { object-fit: cover; }
    .book-card--event .book-image-icon { font-size: 3rem; color: var(--text-muted); opacity: 0.6; }
    @media (max-width: 767px) { .event-cover--banner img { height: 160px; } }
";

ob_start();

$resourceCover = $coverInHero ? url($event['featured_image']) : '';
$resourceCoverAlt = (string) $event['title'];
$resourceKickerHtml = '<span class="book-media-type"><i class="fas fa-calendar-alt mr-1" aria-hidden="true"></i>' . HtmlHelper::e(__("Evento della biblioteca")) . '</span>';
$resourceTitle = (string) $event['title'];
$breadcrumbItems = [
    ['label' => __('Home'), 'href' => url('/')],
    ['label' => __('Eventi'), 'href' => route_path('events')],
    ['label' => (string) $event['title']],
];
include $corePartials . '/resource-hero.php';
?>

<?php // The layout owns the page's one <main> landmark. ?>
<div class="container">
    <div class="flex flex-wrap -mx-3">
        <div class="w-full lg:w-2/3 px-3">
            <div class="book-description-section">
                <?php if ($hasCoverImage && !$coverInHero): ?>
                    <figure class="event-cover event-cover--<?= htmlspecialchars($coverLayout, ENT_QUOTES, 'UTF-8') ?>" data-event-cover-layout="<?= htmlspecialchars($coverLayout, ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars(url($event['featured_image']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>">
                    </figure>
                <?php endif; ?>
                <div class="description-content"><?= $contentHtml ?></div>
            </div>
        </div>

        <aside class="w-full lg:w-1/3 px-3" aria-label="<?= HtmlHelper::e(__("Informazioni")) ?>">
            <div class="card mb-4 resource-info-card">
                <div class="card-header"><h2 class="mb-0 resource-info-title"><i class="fas fa-calendar-alt mr-2" aria-hidden="true"></i><?= __("Informazioni") ?></h2></div>
                <div class="card-body">
                    <?php if ($eventDateFormatted): ?>
                        <div class="meta-item"><div class="meta-label"><?= __("Data") ?></div><div class="meta-value"><time datetime="<?= HtmlHelper::e($event['event_date']) ?>"><?= HtmlHelper::e($eventDateFormatted) ?></time></div></div>
                    <?php endif; ?>
                    <?php if ($eventTimeFormatted): ?>
                        <div class="meta-item"><div class="meta-label"><?= __("Orario") ?></div><div class="meta-value"><time datetime="<?= HtmlHelper::e($event['event_time']) ?>"><?= HtmlHelper::e($eventTimeFormatted) ?></time></div></div>
                    <?php endif; ?>
                    <div class="meta-item"><div class="meta-label"><?= __("Luogo") ?></div><div class="meta-value"><?= HtmlHelper::e((string) $appName) ?><?= $eventPlace !== '' ? '<br>' . HtmlHelper::e($eventPlace) : '' ?></div></div>
                    <a class="ui-button btn-outline resource-back" href="<?= HtmlHelper::e(route_path('events')) ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> <?= __("Torna alla panoramica eventi") ?></a>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php /** @var array $relatedEvents Related upcoming events (from controller) */ ?>

<?php if (!empty($relatedEvents)): ?>
    <section class="resource-related">
        <div class="container">
            <div class="listing-section">
                <h2 class="listing-section-title"><span><?= __("Altri eventi in programma") ?></span></h2>
                <div class="books-grid books-grid--events">
                    <?php foreach ($relatedEvents as $relatedEvent): ?>
                        <?php
                        $relatedMeta = trim($formatDate($relatedEvent['event_date'] ?? null) . ($formatTime($relatedEvent['event_time'] ?? null) !== '' ? ' · ' . $formatTime($relatedEvent['event_time'] ?? null) : ''));
                        $relatedUrl = HtmlHelper::e(route_path('events') . '/' . rawurlencode($relatedEvent['slug']));
                        ?>
                        <article class="book-card book-card--event">
                            <div class="book-image-container">
                                <a href="<?= $relatedUrl ?>" tabindex="-1" aria-hidden="true" class="flex w-full h-full items-center justify-center">
                                    <?php if (!empty($relatedEvent['featured_image'])): ?>
                                        <img class="book-image" src="<?= HtmlHelper::e(url($relatedEvent['featured_image'])) ?>" alt="" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <i class="fas fa-calendar-alt book-image-icon" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="book-content">
                                <h3 class="book-title"><a href="<?= $relatedUrl ?>"><?= HtmlHelper::e($relatedEvent['title']) ?></a></h3>
                                <?php if ($relatedMeta !== ''): ?><p class="book-meta"><?= HtmlHelper::e($relatedMeta) ?></p><?php endif; ?>
                                <div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $relatedUrl ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __("Dettagli") ?></a></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- JSON-LD Structured Data for Event -->
<?php
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Event',
    'name' => $event['title'],
    'description' => strip_tags($event['content'] ?? ''),
    'eventStatus' => 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'organizer' => [
        '@type' => 'Organization',
        'name' => ConfigStore::get('app.name'),
        'url' => (string)($baseUrl ?? ''),
    ],
    'location' => [
        '@type' => 'Place',
        'name' => (string) ConfigStore::get('app.name', __('Biblioteca')),
        'address' => (string) ConfigStore::get('app.address', ''),
    ],
];
if (!empty($event['event_date'])) {
    $startDate = $event['event_date'];
    if (!empty($event['event_time'])) {
        $startDate .= 'T' . $event['event_time'];
    }
    // Append timezone offset for ISO 8601 compliance
    $tz = new \DateTimeZone(date_default_timezone_get());
    $now = new \DateTimeImmutable('now', $tz);
    $startDate .= $now->format('P');
    $jsonLd['startDate'] = $startDate;
}
if (!empty($event['featured_image'])) {
    $jsonLd['image'] = absoluteUrl($event['featured_image']);
}
?>
<script type="application/ld+json">
<?= json_encode($jsonLd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
