<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\HtmlHelper;
use App\Support\ContentSanitizer;

$title = $event['title'];
$appName = \App\Support\ConfigStore::get('app.name');
$baseUrl = \App\Support\ConfigStore::get('app.canonical_url');

// SEO variables are already set from controller:
// $seoTitle, $seoDescription, $seoKeywords, $seoCanonical
// $ogTitle, $ogDescription, $ogType, $ogUrl, $ogImage
// $twitterCard, $twitterTitle, $twitterDescription, $twitterImage

// Include main layout
include __DIR__ . '/layout.php';

function content(): void {
    global $event, $baseUrl;

    $contentHtml = ContentSanitizer::normalizeExternalAssets($event['content'] ?? '');
    ?>

    <!-- Breadcrumbs -->
    <nav class="bg-gray-50 py-4 border-b border-gray-200" aria-label="<?= __("Breadcrumb") ?>">
        <div class="container mx-auto px-4">
            <ol class="flex items-center gap-2 text-sm">
                <li>
                    <a href="/" class="text-gray-600 hover:text-gray-900 transition-colors">
                        <i class="fas fa-home"></i>
                        <span class="sr-only"><?= __("Home") ?></span>
                    </a>
                </li>
                <li class="text-gray-400">/</li>
                <li>
                    <a href="/events" class="text-gray-600 hover:text-gray-900 transition-colors">
                        <?= __("Eventi") ?>
                    </a>
                </li>
                <li class="text-gray-400">/</li>
                <li class="text-gray-900 font-semibold truncate max-w-xs">
                    <?= HtmlHelper::e($event['title']) ?>
                </li>
            </ol>
        </div>
    </nav>

    <!-- Event Header -->
    <article class="py-12 bg-white">
        <div class="container mx-auto px-4">
            <div class="max-w-4xl mx-auto">

                <!-- Event Meta -->
                <div class="flex flex-wrap items-center gap-4 mb-6 text-purple-600">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-calendar"></i>
                        <time datetime="<?= HtmlHelper::e($event['event_date']) ?>" class="font-semibold">
                            <?= strftime('%d %B %Y', strtotime($event['event_date'])) ?>
                        </time>
                    </div>
                    <?php if ($event['event_time']): ?>
                        <span class="text-gray-400">•</span>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-clock"></i>
                            <time datetime="<?= HtmlHelper::e($event['event_time']) ?>" class="font-semibold">
                                <?= date('H:i', strtotime($event['event_time'])) ?>
                            </time>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Event Title -->
                <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-8 leading-tight">
                    <?= HtmlHelper::e($event['title']) ?>
                </h1>

                <!-- Featured Image -->
                <?php if ($event['featured_image']): ?>
                    <div class="mb-10 rounded-2xl overflow-hidden shadow-2xl">
                        <img
                            src="<?= HtmlHelper::e($event['featured_image']) ?>"
                            alt="<?= HtmlHelper::e($event['title']) ?>"
                            class="w-full h-auto"
                        >
                    </div>
                <?php endif; ?>

                <!-- Event Content -->
                <div class="prose prose-lg max-w-none mb-12">
                    <?= $contentHtml ?>
                </div>

                <!-- Back to Events -->
                <div class="pt-8 border-t border-gray-200">
                    <a
                        href="/events"
                        class="inline-flex items-center gap-2 text-purple-600 hover:text-purple-700 font-semibold transition-colors"
                    >
                        <i class="fas fa-arrow-left"></i>
                        <?= __("Torna agli eventi") ?>
                    </a>
                </div>

            </div>
        </div>
    </article>

    <!-- Related Events Section -->
    <?php
    // Get other upcoming events
    global $db;
    $currentId = $event['id'];
    $currentDate = $event['event_date'];

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
        <section class="py-16 bg-gray-50">
            <div class="container mx-auto px-4">
                <div class="max-w-6xl mx-auto">
                    <h2 class="text-3xl font-bold text-gray-900 mb-8 text-center">
                        <?= __("Altri eventi in programma") ?>
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                        <?php foreach ($relatedEvents as $relatedEvent): ?>
                            <article class="bg-white rounded-2xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow duration-300">
                                <a href="/events/<?= HtmlHelper::e($relatedEvent['slug']) ?>" class="block">
                                    <?php if ($relatedEvent['featured_image']): ?>
                                        <img
                                            src="<?= HtmlHelper::e($relatedEvent['featured_image']) ?>"
                                            alt="<?= HtmlHelper::e($relatedEvent['title']) ?>"
                                            class="w-full h-48 object-cover"
                                        >
                                    <?php else: ?>
                                        <div class="w-full h-48 bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center">
                                            <i class="fas fa-calendar-alt text-white text-5xl opacity-50"></i>
                                        </div>
                                    <?php endif; ?>
                                </a>

                                <div class="p-6">
                                    <div class="flex items-center gap-2 mb-3 text-sm text-purple-600 font-semibold">
                                        <i class="fas fa-calendar"></i>
                                        <time datetime="<?= HtmlHelper::e($relatedEvent['event_date']) ?>">
                                            <?= strftime('%d %b %Y', strtotime($relatedEvent['event_date'])) ?>
                                        </time>
                                        <?php if ($relatedEvent['event_time']): ?>
                                            <span class="text-gray-400">•</span>
                                            <time datetime="<?= HtmlHelper::e($relatedEvent['event_time']) ?>">
                                                <?= date('H:i', strtotime($relatedEvent['event_time'])) ?>
                                            </time>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="text-lg font-bold text-gray-900 mb-3 line-clamp-2">
                                        <a href="/events/<?= HtmlHelper::e($relatedEvent['slug']) ?>" class="hover:text-purple-600 transition-colors">
                                            <?= HtmlHelper::e($relatedEvent['title']) ?>
                                        </a>
                                    </h3>

                                    <a
                                        href="/events/<?= HtmlHelper::e($relatedEvent['slug']) ?>"
                                        class="inline-flex items-center gap-2 text-purple-600 hover:text-purple-700 font-semibold text-sm transition-colors"
                                    >
                                        <?= __("Scopri di più") ?>
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
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
        "image": "<?= addslashes($baseUrl . $event['featured_image']) ?>",
        <?php endif; ?>
        "description": "<?= addslashes(strip_tags($event['content'] ?? '')) ?>",
        "eventStatus": "https://schema.org/EventScheduled",
        "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
        "organizer": {
            "@type": "Organization",
            "name": "<?= addslashes(\App\Support\ConfigStore::get('app.name')) ?>",
            "url": "<?= addslashes($baseUrl) ?>"
        }
    }
    </script>

    <?php
}

ob_start();
content();
$content = ob_get_clean();
?>
