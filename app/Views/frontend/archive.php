<?php
/** @var string $archive_type */
/** @var array $archive_info */
/** @var array $books */
/** @var int $totalBooks */
/** @var int|float $totalPages */
/** @var int $page */

use App\Support\AuthorName;
use App\Support\HtmlHelper;

$catalogRoute = route_path('catalog');
$authorRoute = route_path('author');
$publisherRoute = route_path('publisher');
$genreRoute = route_path('genre');
$homeRoute = absoluteUrl('/');
$archivePageStyles = true;

$archiveDisplayName = $archive_type === 'autore'
    ? AuthorName::display($archive_info)
    : (string) ($archive_info['nome'] ?? '');

$typeLabel = match ($archive_type) {
    'autore' => __('Autore'),
    'editore' => __('Casa Editrice'),
    default => __('Genere'),
};
$sectionTitle = match ($archive_type) {
    'autore' => __('Opere'),
    'editore' => __('Pubblicazioni'),
    default => __('Libri'),
};
$entityIcon = match ($archive_type) {
    'autore' => 'fa-user',
    'editore' => 'fa-building',
    default => 'fa-tags',
};

$authorPhoto = '';
$authorLinks = [];
if ($archive_type === 'autore') {
    $photo = trim((string) ($archive_info['foto'] ?? ''));
    if (str_starts_with($photo, '/uploads/')) {
        $authorPhoto = url($photo);
    } elseif (filter_var($photo, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $photo) === 1) {
        $authorPhoto = $photo;
    }

    $decodedLinks = json_decode((string) ($archive_info['collegamenti'] ?? ''), true);
    if (is_array($decodedLinks)) {
        foreach ($decodedLinks as $link) {
            if (!is_array($link)) {
                continue;
            }
            $linkUrl = HtmlHelper::sanitizePublicHttpUrl((string) ($link['url'] ?? ''));
            if ($linkUrl === '') {
                continue;
            }
            $authorLinks[] = [
                'label' => trim((string) ($link['etichetta'] ?? '')) ?: $linkUrl,
                'url' => $linkUrl,
            ];
        }
    }
}

$authorWebsite = $archive_type === 'autore'
    ? HtmlHelper::sanitizePublicHttpUrl((string) ($archive_info['sito_web'] ?? ''))
    : '';
$publisherWebsite = $archive_type === 'editore'
    ? HtmlHelper::sanitizePublicHttpUrl((string) ($archive_info['sito_web'] ?? ''))
    : '';
$hasArchiveDetails = ($archive_type === 'autore' && (!empty($archive_info['biografia']) || $authorWebsite !== '' || $authorLinks !== []))
    || ($archive_type === 'editore' && (!empty($archive_info['indirizzo']) || $publisherWebsite !== ''));

$createBookUrl = static fn(array $book): string => book_url($book);
$defaultCoverUrl = absoluteUrl('/uploads/copertine/placeholder.jpg');

// ── SEO: shared by ALL archive types (author, publisher, genre) and by both
// the name- and id-based routes. The id route renders the same page, so the
// canonical always points at the name-based URL to collapse the duplicate.
$appName = (string) \App\Support\ConfigStore::get('app.name', 'Pinakes');
$archiveEntityName = $archive_type === 'autore'
    ? (string) ($archive_info['nome'] ?? $archiveDisplayName)
    : $archiveDisplayName;
$archiveBaseRoute = match ($archive_type) {
    'autore' => $authorRoute,
    'editore' => $publisherRoute,
    default => $genreRoute,
};
$seoTitle = match ($archive_type) {
    'autore' => __('Libri di %s', $archiveDisplayName),
    'editore' => __("Libri dell'editore %s", $archiveDisplayName),
    default => __('Libri del genere %s', $archiveDisplayName),
};
$seoDescription = match ($archive_type) {
    'autore' => __('Tutte le opere di %s presenti in biblioteca: sfoglia i titoli disponibili e richiedili in prestito.', $archiveDisplayName),
    'editore' => __('Tutti i libri pubblicati da %s presenti nella nostra biblioteca, disponibili per il prestito.', $archiveDisplayName),
    default => __('Esplora i libri del genere %s disponibili nella nostra biblioteca per il prestito.', $archiveDisplayName),
};
$seoCanonical = absoluteUrl($archiveBaseRoute . '/' . rawurlencode($archiveEntityName));
if ((int) ($page ?? 1) > 1) {
    // Paginated pages self-canonicalize: page 2 is not a duplicate of page 1.
    $seoCanonical .= '?page=' . (int) $page;
    $seoTitle .= ' — ' . __('Pagina %d', (int) $page);
}
$seoTitle .= ' | ' . $appName;

$archiveSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $seoTitle,
    'description' => $seoDescription,
    'url' => $seoCanonical,
];
if ($archive_type === 'autore') {
    $archivePerson = ['@type' => 'Person', 'name' => $archiveDisplayName];
    if ($authorPhoto !== '') {
        $archivePerson['image'] = $authorPhoto;
    }
    $archiveSameAs = array_values(array_filter(array_map(
        static fn(array $l): string => (string) ($l['url'] ?? ''),
        $authorLinks
    )));
    if ($authorWebsite !== '') {
        array_unshift($archiveSameAs, $authorWebsite);
    }
    if ($archiveSameAs !== []) {
        $archivePerson['sameAs'] = count($archiveSameAs) === 1 ? $archiveSameAs[0] : $archiveSameAs;
    }
    $archiveSchema['mainEntity'] = $archivePerson;
}
$archiveBreadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home'), 'item' => $homeRoute],
        ['@type' => 'ListItem', 'position' => 2, 'name' => __('Catalogo'), 'item' => absoluteUrl($catalogRoute)],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $archiveDisplayName],
    ],
];
$seoSchema = json_encode(
    [$archiveSchema, $archiveBreadcrumbSchema],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
);
$title = $seoTitle;

ob_start();
?>

<div class="archive-page archive-page-<?= htmlspecialchars($archive_type, ENT_QUOTES, 'UTF-8') ?>">
    <section class="archive-hero" aria-labelledby="archive-title">
        <div class="container archive-hero-inner">
            <nav class="archive-breadcrumb" aria-label="<?= htmlspecialchars(__('Breadcrumb'), ENT_QUOTES, 'UTF-8') ?>">
                <a href="<?= htmlspecialchars($homeRoute, ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
                <span aria-hidden="true">›</span>
                <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>"><?= __('Catalogo') ?></a>
                <span aria-hidden="true">›</span>
                <span aria-current="page"><?= htmlspecialchars($archiveDisplayName, ENT_QUOTES, 'UTF-8') ?></span>
            </nav>

            <div class="archive-identity">
                <div class="archive-avatar" aria-hidden="true">
                    <?php if ($archive_type === 'autore' && $authorPhoto !== ''): ?>
                        <img src="<?= htmlspecialchars($authorPhoto, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <i class="fas <?= htmlspecialchars($entityIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="archive-heading">
                    <p class="archive-kicker"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></p>
                    <h1 class="archive-title" id="archive-title"><?= htmlspecialchars($archiveDisplayName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="archive-count">
                        <i class="fas fa-book" aria-hidden="true"></i>
                        <span><?= (int) $totalBooks ?> <?= __n('libro', 'libri', (int) $totalBooks) ?></span>
                        <?php if ((int) $totalPages > 1): ?>
                            <span aria-hidden="true">·</span>
                            <span><?= (int) $totalPages ?> <?= __n('pagina', 'pagine', (int) $totalPages) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <div class="container archive-content">
        <?php if ($hasArchiveDetails): ?>
            <section class="archive-details" aria-label="<?= htmlspecialchars(__('Informazioni'), ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($archive_type === 'autore'): ?>
                    <?php if (!empty($archive_info['biografia'])): ?>
                        <div class="archive-biography">
                            <?= nl2br(htmlspecialchars((string) $archive_info['biografia'], ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($authorWebsite !== '' || $authorLinks !== []): ?>
                        <div class="archive-links">
                            <?php if ($authorWebsite !== ''): ?>
                                <a href="<?= htmlspecialchars($authorWebsite, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-globe" aria-hidden="true"></i><?= __('Sito web') ?>
                                </a>
                            <?php endif; ?>
                            <?php foreach ($authorLinks as $link): ?>
                                <a href="<?= htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="publisher-details">
                        <?php if (!empty($archive_info['indirizzo'])): ?>
                            <p><i class="fas fa-location-dot" aria-hidden="true"></i><span><?= htmlspecialchars((string) $archive_info['indirizzo'], ENT_QUOTES, 'UTF-8') ?></span></p>
                        <?php endif; ?>
                        <?php if ($publisherWebsite !== ''): ?>
                            <a href="<?= htmlspecialchars($publisherWebsite, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-globe" aria-hidden="true"></i><span><?= htmlspecialchars($publisherWebsite, ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="archive-books" aria-labelledby="archive-books-title">
            <header class="archive-section-header">
                <div>
                    <p class="archive-section-kicker"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 id="archive-books-title"><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>" class="archive-catalog-link">
                    <?= __('Esplora Catalogo') ?><i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </header>

            <?php if ($books !== []): ?>
                <div class="archive-books-grid">
                    <?php foreach ($books as $book): ?>
                        <?php
                        $bookUrl = $createBookUrl($book);
                        $coverUrl = absoluteUrl(($book['copertina_url'] ?? '') ?: '/uploads/copertine/placeholder.jpg');
                        $available = (int) ($book['copie_disponibili'] ?? 0) > 0;
                        $state = (string) ($book['stato'] ?? '');
                        if ($available) {
                            $statusClass = 'is-available';
                            $statusIcon = 'fa-check';
                            $statusLabel = __('Disponibile');
                        } elseif ($state === 'prenotato') {
                            $statusClass = 'is-reserved';
                            $statusIcon = 'fa-bookmark';
                            $statusLabel = __('Prenotato');
                        } elseif ($state === 'prestato') {
                            $statusClass = 'is-borrowed';
                            $statusIcon = 'fa-clock';
                            $statusLabel = __('In prestito');
                        } else {
                            $statusClass = 'is-unavailable';
                            $statusIcon = 'fa-minus';
                            $statusLabel = __('Non disponibile');
                        }
                        $authorName = trim(html_entity_decode((string) ($book['autore'] ?? ''), ENT_QUOTES, 'UTF-8'));
                        $authorCanonicalName = trim(html_entity_decode((string) ($book['autore_principale_nome'] ?? ''), ENT_QUOTES, 'UTF-8'));
                        ?>
                        <article class="archive-book-card">
                            <a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') ?>" class="archive-book-cover">
                                <img src="<?= htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars((string) ($book['titolo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                     loading="lazy"
                                     onerror="this.onerror=null;this.src=<?= htmlspecialchars(json_encode($defaultCoverUrl), ENT_QUOTES, 'UTF-8') ?>">
                                <span class="archive-book-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas <?= htmlspecialchars($statusIcon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                    <?php do_action('book.badge.digital_icons', $book); ?>
                                </span>
                            </a>
                            <div class="archive-book-copy">
                                <h3><a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(html_entity_decode((string) ($book['titolo'] ?? ''), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a></h3>
                                <?php if ($authorName !== ''): ?>
                                    <p class="archive-book-author">
                                        <?php if ($authorCanonicalName !== ''): ?>
                                            <a href="<?= htmlspecialchars($authorRoute . '/' . urlencode($authorCanonicalName), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <div class="archive-book-meta">
                                    <?php if (!empty($book['genere']) && $archive_type !== 'genere'): ?>
                                        <a href="<?= htmlspecialchars($genreRoute . '/' . urlencode(html_entity_decode((string) $book['genere'], ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(html_entity_decode((string) $book['genere'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php endif; ?>
                                    <?php if (!empty($book['editore']) && $archive_type !== 'editore'): ?>
                                        <a href="<?= htmlspecialchars($publisherRoute . '/' . urlencode(html_entity_decode((string) $book['editore'], ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(html_entity_decode((string) $book['editore'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ((int) $totalPages > 1): ?>
                    <nav class="archive-pagination" aria-label="<?= htmlspecialchars(__('Navigazione pagine'), ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>" aria-label="<?= htmlspecialchars(__('Pagina precedente'), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min((int) $totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?= $i ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($page < (int) $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>" aria-label="<?= htmlspecialchars(__('Pagina successiva'), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="archive-empty">
                    <i class="fas fa-book-open" aria-hidden="true"></i>
                    <h3><?= __('Nessun libro trovato') ?></h3>
                    <p><?= $archive_type === 'autore' ? __('Non sono stati trovati libri di questo autore.') : ($archive_type === 'editore' ? __('Non sono stati trovati libri di questo editore.') : __('Non sono stati trovati libri di questo genere.')) ?></p>
                    <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>"><?= __('Esplora Catalogo') ?></a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>
