<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\HtmlHelper;
use App\Support\ContentSanitizer;

$title = __("Eventi");
$appName = \App\Support\ConfigStore::get('app.name');
$baseUrl = \App\Support\ConfigStore::get('app.canonical_url');

// Variables are already set from controller
// $events, $page, $totalPages, $seoTitle, $seoDescription, $seoCanonical

// Open Graph defaults
$ogTitle = $seoTitle;
$ogDescription = $seoDescription;
$ogImage = $baseUrl . '/assets/social.jpg';
$ogUrl = $seoCanonical;
$ogType = 'website';

// Twitter Card defaults
$twitterCard = 'summary_large_image';
$twitterTitle = $seoTitle;
$twitterDescription = $seoDescription;
$twitterImage = $ogImage;

// Include main layout
include __DIR__ . '/layout.php';

function content(): void {
    global $events, $page, $totalPages, $baseUrl;
    ?>

    <!-- Events Hero Section -->
    <section class="bg-gradient-to-br from-purple-600 to-purple-800 text-white py-16">
        <div class="container mx-auto px-4">
            <div class="max-w-4xl mx-auto text-center">
                <h1 class="text-4xl md:text-5xl font-bold mb-4">
                    <i class="fas fa-calendar-alt mr-3"></i>
                    <?= __("Eventi") ?>
                </h1>
                <p class="text-xl text-purple-100">
                    <?= __("Scopri tutti gli eventi organizzati dalla nostra biblioteca") ?>
                </p>
            </div>
        </div>
    </section>

    <!-- Events Grid -->
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4">

            <?php if (empty($events)): ?>
                <!-- No Events -->
                <div class="max-w-2xl mx-auto text-center py-12">
                    <div class="w-24 h-24 bg-gray-200 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-calendar-times text-gray-400 text-4xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-3">
                        <?= __("Nessun evento in programma") ?>
                    </h2>
                    <p class="text-gray-600">
                        <?= __("Al momento non ci sono eventi programmati. Torna a visitare questa pagina per scoprire i prossimi appuntamenti.") ?>
                    </p>
                </div>
            <?php else: ?>
                <!-- Events Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-7xl mx-auto">
                    <?php foreach ($events as $event): ?>
                        <?php
                        // Extract excerpt from content
                        $contentPlain = strip_tags($event['content'] ?? '');
                        $excerpt = mb_substr($contentPlain, 0, 150);
                        if (mb_strlen($contentPlain) > 150) {
                            $excerpt .= '...';
                        }
                        ?>
                        <article class="bg-white rounded-2xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow duration-300">
                            <!-- Event Image -->
                            <a href="/events/<?= HtmlHelper::e($event['slug']) ?>" class="block">
                                <?php if ($event['featured_image']): ?>
                                    <img
                                        src="<?= HtmlHelper::e($event['featured_image']) ?>"
                                        alt="<?= HtmlHelper::e($event['title']) ?>"
                                        class="w-full h-56 object-cover"
                                    >
                                <?php else: ?>
                                    <div class="w-full h-56 bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center">
                                        <i class="fas fa-calendar-alt text-white text-6xl opacity-50"></i>
                                    </div>
                                <?php endif; ?>
                            </a>

                            <!-- Event Content -->
                            <div class="p-6">
                                <!-- Event Date -->
                                <div class="flex items-center gap-3 mb-4 text-sm text-purple-600 font-semibold">
                                    <i class="fas fa-calendar"></i>
                                    <time datetime="<?= HtmlHelper::e($event['event_date']) ?>">
                                        <?= strftime('%d %B %Y', strtotime($event['event_date'])) ?>
                                    </time>
                                    <?php if ($event['event_time']): ?>
                                        <span class="text-gray-400">•</span>
                                        <i class="fas fa-clock"></i>
                                        <time datetime="<?= HtmlHelper::e($event['event_time']) ?>">
                                            <?= date('H:i', strtotime($event['event_time'])) ?>
                                        </time>
                                    <?php endif; ?>
                                </div>

                                <!-- Event Title -->
                                <h2 class="text-xl font-bold text-gray-900 mb-3 line-clamp-2">
                                    <a href="/events/<?= HtmlHelper::e($event['slug']) ?>" class="hover:text-purple-600 transition-colors">
                                        <?= HtmlHelper::e($event['title']) ?>
                                    </a>
                                </h2>

                                <!-- Event Excerpt -->
                                <?php if (!empty($excerpt)): ?>
                                    <p class="text-gray-600 text-sm mb-4 line-clamp-3">
                                        <?= HtmlHelper::e($excerpt) ?>
                                    </p>
                                <?php endif; ?>

                                <!-- Read More Link -->
                                <a
                                    href="/events/<?= HtmlHelper::e($event['slug']) ?>"
                                    class="inline-flex items-center gap-2 text-purple-600 hover:text-purple-700 font-semibold text-sm transition-colors"
                                >
                                    <?= __("Scopri di più") ?>
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav class="mt-12 flex items-center justify-center" aria-label="<?= __("Paginazione eventi") ?>">
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a
                                    href="?page=<?= $page - 1 ?>"
                                    class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold"
                                >
                                    <i class="fas fa-chevron-left"></i>
                                    <?= __("Precedente") ?>
                                </a>
                            <?php endif; ?>

                            <div class="flex items-center gap-1">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <span class="px-4 py-2 bg-purple-600 text-white rounded-lg font-semibold">
                                            <?= $i ?>
                                        </span>
                                    <?php elseif ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
                                        <a
                                            href="?page=<?= $i ?>"
                                            class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold"
                                        >
                                            <?= $i ?>
                                        </a>
                                    <?php elseif (abs($i - $page) == 3): ?>
                                        <span class="px-2 text-gray-400">...</span>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </div>

                            <?php if ($page < $totalPages): ?>
                                <a
                                    href="?page=<?= $page + 1 ?>"
                                    class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold"
                                >
                                    <?= __("Successivo") ?>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </section>

    <?php
}

ob_start();
content();
$content = ob_get_clean();
?>
