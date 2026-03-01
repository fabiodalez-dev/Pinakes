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

$locale = $_SESSION['locale'] ?? 'it_IT';
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
    } catch (\Exception $e) {
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

    .page-hero {
        padding: 5rem 0 4rem;
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 1.5rem;
    }

    .page-hero__content {
        max-width: 760px;
    }

    .page-hero__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.35rem 0.85rem;
        border-radius: 999px;
        background: #fff;
        border: 1px solid #e5e7eb;
        font-size: 0.85rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin-bottom: 1rem;
        color: #6b7280;
    }

    .page-hero__title {
        font-size: clamp(2rem, 4vw, 3rem);
        font-weight: 800;
        color: #111827;
        margin-bottom: 0.75rem;
        letter-spacing: -0.02em;
    }

    .page-hero__subtitle {
        color: #4b5563;
        font-size: 1.125rem;
        max-width: 640px;
    }

    .events-wrapper {
        padding: 3rem 0 4rem;
        background: #fff;
    }

    .events-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.5rem;
    }

    @media (max-width: 1200px) {
        .events-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .events-grid {
            grid-template-columns: 1fr;
        }
    }

    .event-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        height: 100%;
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }

    .event-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    }

    .event-card__thumb {
        display: block;
        height: 230px;
        background: #f3f4f6;
    }

    .event-card__thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .event-card__placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        color: #9ca3af;
        font-size: 2rem;
        height: 100%;
    }

    .event-card__body {
        padding: 1.25rem 1.5rem 1.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        flex: 1;
    }

    .event-card__title {
        font-size: 1.15rem;
        font-weight: 700;
        color: #111827;
        margin: 0;
    }

    .event-card__title a {
        color: inherit;
        text-decoration: none;
    }

    .event-card__title a:hover {
        color: var(--primary-color, #d70161);
    }

    .event-card__meta {
        font-size: 0.95rem;
        font-weight: 600;
        color: #4b5563;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .event-card__actions {
        margin-top: auto;
    }

    .event-card__button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        gap: 0.4rem;
        padding: 0.65rem 1rem;
        border-radius: 999px;
        border: 1px solid #111827;
        color: #111827;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .event-card__button:hover {
        background: #111827;
        color: #fff;
    }

    .events-empty {
        max-width: 560px;
        margin: 2rem auto 0;
        padding: 2.5rem 2rem;
        border-radius: 20px;
        border: 1px solid #e5e7eb;
        text-align: center;
        background: #f9fafb;
    }

    .events-empty__icon {
        width: 64px;
        height: 64px;
        border-radius: 16px;
        background: #fff;
        border: 1px solid #e5e7eb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--primary-color, #d70161);
        font-size: 1.5rem;
        margin-bottom: 1rem;
    }

    .events-pagination {
        margin-top: 2.5rem;
        display: flex;
        justify-content: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .events-pagination a,
    .events-pagination span {
        min-width: 44px;
        padding: 0.6rem 0.9rem;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        text-align: center;
        font-weight: 600;
        text-decoration: none;
        color: #111827;
    }

    .events-pagination a:hover {
        border-color: var(--primary-color, #d70161);
        color: var(--primary-color, #d70161);
    }

    .events-pagination .is-active {
        background: #111827;
        border-color: #111827;
        color: #fff;
    }
</style>
";

ob_start();
?>

<section class="page-hero">
    <div class="container">
        <div class="page-hero__content">
            <div class="page-hero__eyebrow">
                <i class="fas fa-calendar-alt"></i>
                <?= __("Calendario eventi") ?>
            </div>
            <h1 class="page-hero__title"><?= __("Gli appuntamenti della biblioteca") ?></h1>
            <p class="page-hero__subtitle">
                <?= __("In questa pagina trovi tutti gli eventi, gli incontri e i laboratori organizzati dalla biblioteca.") ?>
            </p>
        </div>
    </div>
</section>

<section class="events-wrapper">
    <div class="container">
        <?php if (empty($events)): ?>
            <div class="events-empty">
                <div class="events-empty__icon">
                    <i class="fas fa-calendar-times"></i>
                </div>
                <h2><?= __("Nessun evento in programma") ?></h2>
                <p><?= __("Al momento non ci sono eventi attivi. Continua a seguirci per restare aggiornato sui prossimi appuntamenti.") ?></p>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($events as $event): ?>
                    <?php
                    $eventDateFormatted = $formatDate($event['event_date'] ?? '');
                    $eventTimeFormatted = $formatTime($event['event_time'] ?? '');

                    ?>
                    <article class="event-card">
                        <a href="<?= htmlspecialchars(url('/events/' . $event['slug']), ENT_QUOTES, 'UTF-8') ?>" class="event-card__thumb">
                            <?php if (!empty($event['featured_image'])): ?>
                                <img src="<?= htmlspecialchars(url($event['featured_image']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= HtmlHelper::e($event['title']) ?>">
                            <?php else: ?>
                                <div class="event-card__placeholder">
                                    <i class="fas fa-calendar"></i>
                                </div>
                            <?php endif; ?>
                        </a>
                        <div class="event-card__body">
                            <div class="event-card__meta">
                                <?= HtmlHelper::e($eventDateFormatted) ?>
                            </div>
                            <h2 class="event-card__title">
                                <a href="<?= htmlspecialchars(url('/events/' . $event['slug']), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= HtmlHelper::e($event['title']) ?>
                                </a>
                            </h2>
                            <div class="event-card__actions">
                                <a href="<?= htmlspecialchars(url('/events/' . $event['slug']), ENT_QUOTES, 'UTF-8') ?>" class="event-card__button">
                                    <?= __("Scopri l'evento") ?>
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="events-pagination" aria-label="<?= __("Paginazione eventi") ?>">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>">
                            <?= __("Precedente") ?>
                        </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="is-active"><?= $i ?></span>
                        <?php elseif ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
                            <a href="?page=<?= $i ?>"><?= $i ?></a>
                        <?php elseif (abs($i - $page) == 3): ?>
                            <span>…</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>">
                            <?= __("Successivo") ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
