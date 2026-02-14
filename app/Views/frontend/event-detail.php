<?php
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

$locale = $_SESSION['locale'] ?? 'it_IT';
$dateFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
$timeFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);

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
    } catch (\Exception $e) {
        return null;
    }
};

$formatDate = static function (?string $date) use ($dateFormatter, $createDateTime) {
    $dateTime = $createDateTime($date, ['Y-m-d']);
    if (!$dateTime) {
        return (string)$date;
    }

    return $dateFormatter->format($dateTime);
};

$formatTime = static function (?string $time) use ($timeFormatter, $createDateTime) {
    $dateTime = $createDateTime($time, ['H:i:s', 'H:i']);
    if (!$dateTime) {
        return (string)$time;
    }

    return $timeFormatter->format($dateTime);
};

$eventDateFormatted = $formatDate($event['event_date'] ?? null);
$eventTimeFormatted = $formatTime($event['event_time'] ?? null);

$additional_css = "
<style>
    main {
        padding-top: 120px;
    }

    @media (max-width: 576px) {
        main {
            padding-top: 110px;
        }
    }

    .event-hero {
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
        padding: 4.5rem 0 3.5rem;
        margin-bottom: 1.5rem;
    }

    .event-breadcrumb {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.95rem;
        color: #6b7280;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .event-breadcrumb a {
        color: inherit;
        text-decoration: none;
    }

    .event-label {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.9rem;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #fff;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 1rem;
        color: #6b7280;
    }

    .event-title {
        font-size: clamp(2rem, 4vw, 3.25rem);
        font-weight: 800;
        color: #111827;
        letter-spacing: -0.02em;
        margin-bottom: 1.25rem;
    }

    .event-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1.25rem;
        font-weight: 600;
        color: #374151;
    }

    .event-meta__item {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
    }

    .event-section {
        background: #fff;
        padding: 3rem 0 4rem;
    }

    .event-card {
        border: none;
        border-radius: 24px;
        padding: clamp(1.75rem, 4vw, 3rem);
        background: #fff;
    }

    .event-cover {
        border-radius: 20px;
        overflow: hidden;
        margin-bottom: 2rem;
        border: 1px solid #f1f5f9;
    }

    .event-cover img {
        width: 100%;
        display: block;
    }

    .event-body {
        font-size: 1.05rem;
        line-height: 1.8;
        color: #1f2937;
    }

    .event-back {
        margin-top: 2.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }

    .event-back a {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--primary-color, #d70161);
        font-weight: 600;
        text-decoration: none;
    }

    .related-events {
        background: #f8fafc;
        padding: 3rem 0 4rem;
        border-top: 1px solid #e5e7eb;
    }

    .related-heading {
        text-align: center;
        margin-bottom: 2rem;
    }

    .related-heading h2 {
        font-size: 2rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 0.5rem;
    }

    .related-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.5rem;
    }

    @media (max-width: 1024px) {
        .related-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .related-grid {
            grid-template-columns: 1fr;
        }
    }

    .related-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 18px;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .related-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 20px 30px rgba(15, 23, 42, 0.08);
    }

    .related-thumb {
        height: 170px;
        background: #f3f4f6;
        display: block;
    }

    .related-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .related-body {
        padding: 1.25rem;
    }

    .related-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        font-size: 0.9rem;
        font-weight: 600;
        color: #6b7280;
        margin-bottom: 0.5rem;
    }

    .related-title {
        font-size: 1.05rem;
        font-weight: 700;
        margin-bottom: 0.75rem;
        color: #111827;
    }

    .related-title a {
        color: inherit;
        text-decoration: none;
    }

    .related-title a:hover {
        color: var(--primary-color, #d70161);
    }

    .related-link {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-weight: 600;
        color: var(--primary-color, #d70161);
        text-decoration: none;
    }

    @media (max-width: 768px) {
        .event-meta {
            flex-direction: column;
            gap: 0.75rem;
        }

        .event-card {
            padding: 1.5rem;
        }
    }
</style>
";

ob_start();
?>

<section class="event-hero">
    <div class="container">
        <div class="event-breadcrumb" aria-label="<?= __("Percorso di navigazione") ?>">
            <a href="<?= url('/') ?>"><?= __("Home") ?></a>
            <span>/</span>
            <a href="<?= url('/events') ?>"><?= __("Eventi") ?></a>
            <span>/</span>
            <span><?= HtmlHelper::e($event['title']) ?></span>
        </div>

        <div class="event-label">
            <i class="fas fa-bookmark"></i>
            <?= __("Evento della biblioteca") ?>
        </div>

        <h1 class="event-title"><?= HtmlHelper::e($event['title']) ?></h1>

        <div class="event-meta">
            <?php if ($eventDateFormatted): ?>
                <div class="event-meta__item">
                    <i class="fas fa-calendar-alt"></i>
                    <time datetime="<?= HtmlHelper::e($event['event_date']) ?>">
                        <?= HtmlHelper::e($eventDateFormatted) ?>
                    </time>
                </div>
            <?php endif; ?>
            <?php if ($eventTimeFormatted): ?>
                <div class="event-meta__item">
                    <i class="fas fa-clock"></i>
                    <time datetime="<?= HtmlHelper::e($event['event_time']) ?>">
                        <?= HtmlHelper::e($eventTimeFormatted) ?>
                    </time>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="event-section">
    <div class="container">
        <article class="event-card">
            <?php if (!empty($event['featured_image'])): ?>
                <figure class="event-cover">
                    <img src="<?= HtmlHelper::e(url($event['featured_image'])) ?>" alt="<?= HtmlHelper::e($event['title']) ?>">
                </figure>
            <?php endif; ?>

            <div class="event-body">
                <?= $contentHtml ?>
            </div>

            <div class="event-back">
                <a href="<?= url('/events') ?>">
                    <i class="fas fa-arrow-left"></i>
                    <?= __("Torna alla panoramica eventi") ?>
                </a>
            </div>
        </article>
    </div>
</section>

<?php
// Related events
$currentId = $event['id'];
$stmt = $db->prepare("
    SELECT id, title, slug, event_date, event_time, featured_image
    FROM events
    WHERE is_active = 1 AND id != ? AND event_date >= CURDATE()
    ORDER BY event_date ASC
    LIMIT 3
");
$stmt->bind_param('i', $currentId);
$stmt->execute();
$result = $stmt->get_result();

$relatedEvents = [];
while ($row = $result->fetch_assoc()) {
    $relatedEvents[] = $row;
}
$stmt->close();
?>

<?php if (!empty($relatedEvents)): ?>
    <section class="related-events">
        <div class="container">
            <div class="related-heading">
                <h2><?= __("Altri eventi in programma") ?></h2>
                <p class="text-muted"><?= __("Segna in agenda anche questi appuntamenti imminenti.") ?></p>
            </div>
            <div class="related-grid">
                <?php foreach ($relatedEvents as $relatedEvent): ?>
                    <?php
                    $relatedDateFormatted = $formatDate($relatedEvent['event_date'] ?? null);
                    $relatedTimeFormatted = $formatTime($relatedEvent['event_time'] ?? null);
                    ?>
                    <article class="related-card">
                        <a href="<?= url('/events/' . $relatedEvent['slug']) ?>" class="related-thumb">
                            <?php if (!empty($relatedEvent['featured_image'])): ?>
                                <img src="<?= HtmlHelper::e(url($relatedEvent['featured_image'])) ?>" alt="<?= HtmlHelper::e($relatedEvent['title']) ?>">
                            <?php endif; ?>
                        </a>
                        <div class="related-body">
                            <div class="related-meta">
                                <?php if ($relatedDateFormatted): ?>
                                    <span><i class="fas fa-calendar-alt"></i> <?= HtmlHelper::e($relatedDateFormatted) ?></span>
                                <?php endif; ?>
                                <?php if ($relatedTimeFormatted): ?>
                                    <span><i class="fas fa-clock"></i> <?= HtmlHelper::e($relatedTimeFormatted) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="related-title">
                                <a href="<?= url('/events/' . $relatedEvent['slug']) ?>">
                                    <?= HtmlHelper::e($relatedEvent['title']) ?>
                                </a>
                            </h3>
                            <a href="<?= url('/events/' . $relatedEvent['slug']) ?>" class="related-link">
                                <?= __("Dettagli evento") ?>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- JSON-LD Structured Data for Event -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Event",
    "name": "<?= addslashes(HtmlHelper::e($event['title'])) ?>",
    "startDate": "<?= HtmlHelper::e($event['event_date']) ?><?= $event['event_time'] ? 'T' . HtmlHelper::e($event['event_time']) : '' ?>",
    <?php if ($event['featured_image']): ?>
    "image": "<?= addslashes(absoluteUrl($event['featured_image'])) ?>",
    <?php endif; ?>
    "description": "<?= addslashes(strip_tags($event['content'] ?? '')) ?>",
    "eventStatus": "https://schema.org/EventScheduled",
    "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
    "organizer": {
        "@type": "Organization",
        "name": "<?= addslashes(ConfigStore::get('app.name')) ?>",
        "url": "<?= addslashes($baseUrl) ?>"
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
