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
                        <?= !empty($section['title']) ? htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') : __("Gli appuntamenti della biblioteca") ?>
                    </h2>
                    <p class="home-events__subtitle">
                        <?= !empty($section['subtitle']) ? htmlspecialchars($section['subtitle'], ENT_QUOTES, 'UTF-8') : __("In questa pagina trovi tutti gli eventi, gli incontri e i laboratori organizzati dalla biblioteca.") ?>
                    </p>
                </div>
                <a href="<?= htmlspecialchars(route_path('events'), ENT_QUOTES, 'UTF-8') ?>" class="home-events__all-link">
                    <?= __("Vedi tutti gli eventi") ?>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="home-events-grid">
                <?php foreach ($homeEvents as $event): ?>
                    <?php $eventDateText = $homeEventsFormatDate($event['event_date'] ?? ''); ?>
                    <article class="event-card">
                        <a href="<?= htmlspecialchars(route_path('events') . '/' . rawurlencode($event['slug']), ENT_QUOTES, 'UTF-8') ?>" class="event-card__thumb">
                            <?php if (!empty($event['featured_image'])): ?>
                                <img src="<?= htmlspecialchars(url($event['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                                    alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>">
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
                                <a href="<?= htmlspecialchars(route_path('events') . '/' . rawurlencode($event['slug']), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </h3>
                            <a href="<?= htmlspecialchars(route_path('events') . '/' . rawurlencode($event['slug']), ENT_QUOTES, 'UTF-8') ?>" class="event-card__button">
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