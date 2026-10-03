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
$catalogPageStyles = true;
$corePartials = __DIR__ . '/partials';

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

$createBookUrl = static fn(array $book): string => ($book['_record_kind'] ?? '') === 'article' ? url('/emeroteca/articolo/' . (int)$book['id']) : book_url($book);
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
    'autore' => ($totalArticles ?? 0) > 0 ? __('Opere di %s', $archiveDisplayName) : __('Libri di %s', $archiveDisplayName),
    'editore' => __("Libri dell'editore %s", $archiveDisplayName),
    default => __('Libri del genere %s', $archiveDisplayName),
};
$seoDescription = match ($archive_type) {
    'autore' => __('Tutte le opere di %s presenti in biblioteca: sfoglia i titoli disponibili e richiedili in prestito.', $archiveDisplayName),
    'editore' => __('Tutti i libri pubblicati da %s presenti nella nostra biblioteca, disponibili per il prestito.', $archiveDisplayName),
    default => __('Esplora i libri del genere %s disponibili nella nostra biblioteca per il prestito.', $archiveDisplayName),
};
$seoCanonical = absoluteUrl($archiveBaseRoute . '/' . rawurlencode($archiveEntityName));
if ((int) $page > 1) {
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
        static fn(array $l): string => (string) $l['url'],
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
            <?php
            $breadcrumbItems = [
                ['label' => __('Home'), 'href' => $homeRoute],
                ['label' => __('Catalogo'), 'href' => $catalogRoute],
                ['label' => $archiveDisplayName],
            ];
            $breadcrumbVariant = 'book';
            include $corePartials . '/breadcrumb.php';
            ?>

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
                        <span><?= (int) $totalBooks ?> <?= ($totalArticles ?? 0) > 0 ? __('Risultati') : __n('libro', 'libri', (int) $totalBooks) ?></span>
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
                    <h2 id="archive-books-title"><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>" class="archive-catalog-link">
                    <?= __('Esplora Catalogo') ?><i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </header>

            <?php if ($books !== []): ?>
                <div class="books-grid archive-books-grid">
                    <?php foreach ($books as $book): ?>
                        <?php
                        if (($book['_record_kind'] ?? '') === 'article') {
                            include $corePartials . '/catalog-article-card.php';
                            continue;
                        }
                        $bookUrl = $createBookUrl($book);
                        $coverUrl = absoluteUrl(($book['copertina_url'] ?? '') ?: '/uploads/copertine/placeholder.jpg');
                        $available = (int) ($book['copie_disponibili'] ?? 0) > 0;
                        $state = (string) ($book['stato'] ?? '');
                        if ($available) {
                            $statusClass = 'status-available';
                            $statusLabel = __('Disponibile');
                        } elseif ($state === 'prenotato') {
                            $statusClass = 'status-reserved';
                            $statusLabel = __('Prenotato');
                        } elseif ($state === 'prestato') {
                            $statusClass = 'status-borrowed';
                            $statusLabel = __('In prestito');
                        } else {
                            $statusClass = 'status-unavailable';
                            $statusLabel = __('Non disponibile');
                        }
                        $authorName = trim(html_entity_decode((string) ($book['autore'] ?? ''), ENT_QUOTES, 'UTF-8'));
                        $authorCanonicalName = trim(html_entity_decode((string) ($book['autore_principale_nome'] ?? ''), ENT_QUOTES, 'UTF-8'));
                        ?>
                        <article class="book-card">
                            <div class="book-image-container">
                                <a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') ?>" tabindex="-1" aria-hidden="true">
                                    <img class="book-image" src="<?= htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8') ?>"
                                         alt="" loading="lazy" decoding="async"
                                         onerror="this.onerror=null;this.src=<?= htmlspecialchars(json_encode($defaultCoverUrl), ENT_QUOTES, 'UTF-8') ?>">
                                </a>
                                <span class="book-status-badge <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><span><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span><?php do_action('book.badge.digital_icons', $book); ?></span>
                            </div>
                            <div class="book-content">
                                <h3 class="book-title"><a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(html_entity_decode((string) ($book['titolo'] ?? ''), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a></h3>
                                <?php if ($authorName !== ''): ?>
                                    <p class="book-author">
                                        <?php if ($authorCanonicalName !== ''): ?>
                                            <a href="<?= htmlspecialchars($authorRoute . '/' . urlencode($authorCanonicalName), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <?php
                                $metaGenre = $archive_type !== 'genere' ? html_entity_decode((string) ($book['genere'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
                                $metaPublisher = $archive_type !== 'editore' ? html_entity_decode((string) ($book['editore'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
                                ?>
                                <?php if ($metaGenre !== '' || $metaPublisher !== ''): ?>
                                    <p class="book-meta">
                                        <?php if ($metaGenre !== ''): ?><a href="<?= htmlspecialchars($genreRoute . '/' . urlencode($metaGenre), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($metaGenre, ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                                        <?= $metaGenre !== '' && $metaPublisher !== '' ? ' · ' : '' ?>
                                        <?php if ($metaPublisher !== ''): ?><a href="<?= htmlspecialchars($publisherRoute . '/' . urlencode($metaPublisher), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($metaPublisher, ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <div class="book-actions">
                                    <a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-cta btn-cta-sm"><i class="fas fa-eye" aria-hidden="true"></i> <?= __('Dettagli') ?></a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php
                $paginationPage = (int) $page;
                $paginationPages = (int) $totalPages;
                $paginationUrl = static fn(int $p): string => '?page=' . $p;
                include $corePartials . '/pagination.php';
                ?>
            <?php else: ?>
                <?php
                $emptyTitle = __('Nessun libro trovato');
                $emptyText = $archive_type === 'autore' ? __('Non sono stati trovati libri di questo autore.') : ($archive_type === 'editore' ? __('Non sono stati trovati libri di questo editore.') : __('Non sono stati trovati libri di questo genere.'));
                $emptyIcon = 'fa-book-open';
                $emptyCtaHref = $catalogRoute;
                $emptyCtaLabel = __('Esplora Catalogo');
                include $corePartials . '/empty-state.php';
                ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>
