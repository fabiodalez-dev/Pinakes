<?php
/**
 * Events Section Template
 * Renders the events section on the homepage
 */

// Ensure variables are available
$homeEvents = $homeEvents ?? [];
$homeEventsEnabled = $homeEventsEnabled ?? false;

if ($homeEventsEnabled && !empty($homeEvents)):
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
            return (string) $value;
        }
        return $homeEventsDateFormatter->format($dateTime);
    };
    ?>
    <section class="home-events" aria-label="<?= __("Eventi") ?>">
        <div class="container">
            <div class="home-events__header">
                <div>
                    <p class="page-hero__eyebrow"><?= __("Calendario eventi") ?></p>
                    <h2 class="home-events__title">
                        <?= !empty($section['title']) ? \App\Support\HtmlHelper::e($section['title']) : __("Gli appuntamenti della biblioteca") ?>
                    </h2>
                    <p class="home-events__subtitle">
                        <?= !empty($section['subtitle']) ? \App\Support\HtmlHelper::e($section['subtitle']) : __("In questa pagina trovi tutti gli eventi, gli incontri e i laboratori organizzati dalla biblioteca.") ?>
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
                        <a href="/events/<?= \App\Support\HtmlHelper::e($event['slug']) ?>" class="event-card__thumb">
                            <?php if (!empty($event['featured_image'])): ?>
                                <img src="<?= \App\Support\HtmlHelper::e($event['featured_image']) ?>"
                                    alt="<?= \App\Support\HtmlHelper::e($event['title']) ?>">
                            <?php else: ?>
                                <div class="event-card__placeholder">
                                    <i class="fas fa-calendar"></i>
                                </div>
                            <?php endif; ?>
                        </a>
                        <div class="event-card__body">
                            <div class="event-card__meta">
                                <?= \App\Support\HtmlHelper::e($eventDateText) ?>
                            </div>
                            <h3 class="event-card__title">
                                <a href="/events/<?= \App\Support\HtmlHelper::e($event['slug']) ?>">
                                    <?= \App\Support\HtmlHelper::e($event['title']) ?>
                                </a>
                            </h3>
                            <a href="/events/<?= \App\Support\HtmlHelper::e($event['slug']) ?>" class="event-card__button">
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