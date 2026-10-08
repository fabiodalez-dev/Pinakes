<?php
use App\Support\HtmlHelper;
use App\Support\ConfigStore;
use App\Support\AuthorName;

/**
 * Book Detail View
 *
 * Variables passed from controller:
 * @var array $book Book data with all fields
 * @var array $authors List of book authors
 * @var array $categories Book categories
 * @var array $serie Book series information
 * @var array $publishers Book publishers
 * @var array|null $reviewStats Review statistics (optional)
 * @var array $availableCopies Available copies data
 * @var array $userLoanStatus Current user's loan status
 * @var array $bookCopies All book copies
 * @var bool $canBorrow Whether user can borrow this book
 * @var bool $userHasActiveWish Whether user has active wishlist item
 * @var array $seriesBooks Other books in the same series (collana)
 * @var string $collana Series/collection name
 * @var int $defaultRequestLoanDays Effective configured default duration for loan requests
 */

// Check if catalogue-only mode is enabled (hides loans, reservations, wishlist)
$isCatalogueMode = ConfigStore::isCatalogueMode();
$edgeCacheEnabled = \App\Support\LiteSpeedCache::enabled();
if ($edgeCacheEnabled) {
    // Nothing rendering into shared HTML, including a plugin hook, may receive
    // a point-in-time availability snapshot. The browser hydrates it live.
    unset($book['copie_disponibili'], $book['copie_totali'], $book['stato']);
}

// Resolve tipo_media once for badge, labels, and Schema.org
$resolvedTipoMedia = \App\Support\MediaLabels::resolveTipoMedia($book['formato'] ?? null, $book['tipo_media'] ?? null);
$isMusic = $resolvedTipoMedia === 'disco';

// SEO ottimizzato
$bookTitle = html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8');
$bookAuthor = !empty($authors) ? html_entity_decode(AuthorName::display($authors[0]), ENT_QUOTES, 'UTF-8') : '';
$bookDescription = !empty($book['descrizione']) ? html_entity_decode($book['descrizione'], ENT_QUOTES, 'UTF-8') : '';
$bookPublisher = !empty($book['editore']) ? html_entity_decode($book['editore'], ENT_QUOTES, 'UTF-8') : '';
$bookYear = $book['anno_pubblicazione'] ?? '';
$bookISBN = $book['isbn13'] ?? $book['isbn10'] ?? '';
$bookPrice = !empty($book['prezzo']) ? number_format($book['prezzo'], 2) : '';
$bookPages = $book['numero_pagine'] ?? '';
$bookLanguage = $book['lingua'] ?? 'it';
// Build genre hierarchy string
$genreHierarchy = [];
$genreHierarchyIds = [];
if (!empty($book['genere_grandparent'])) {
    $genreHierarchy[] = $book['genere_grandparent'];
    $genreHierarchyIds[] = (int) $book['genere_grandparent_id'];
}
if (!empty($book['genere_parent'])) {
    $genreHierarchy[] = $book['genere_parent'];
    $genreHierarchyIds[] = (int) $book['genere_parent_id_resolved'];
}
if (!empty($book['genere'])) {
    $genreHierarchy[] = $book['genere'];
    $genreHierarchyIds[] = (int) $book['genere_id'];
}
if (!empty($book['sottogenere'])) {
    $genreHierarchy[] = $book['sottogenere'];
    $genreHierarchyIds[] = (int) $book['sottogenere_id'];
}
$bookGenre = !empty($genreHierarchy) ? implode(' > ', $genreHierarchy) : '';
$bookGenre = html_entity_decode($bookGenre, ENT_QUOTES, 'UTF-8');
$bookCover = ($book['copertina_url'] ?? '') ?: ($book['immagine_copertina'] ?? '') ?: '/uploads/copertine/placeholder.jpg';
$bookCover = url($bookCover);
$isAvailable = !$edgeCacheEnabled && ($book['copie_disponibili'] ?? 0) > 0;
// Every owned copy is out of circulation (maintenance, restoration, transfer,
// lost, damaged): the reservation queue counts loanable copies, so it would
// refuse the request the button invites (#426). Say so instead of inviting it.
$bookHoldings = $bookHoldings ?? null;
$nothingInCirculation = \App\Support\CopyHoldings::publishedTotal($bookHoldings, (int) ($book['copie_totali'] ?? 0)) > 0
    && (int) ($book['copie_totali'] ?? 0) === 0;
$authorNames = [];
foreach ($authors as $authorData) {
    $name = trim(html_entity_decode(AuthorName::display($authorData), ENT_QUOTES, 'UTF-8'));
    if ($name !== '') {
        $authorNames[] = $name;
    }
}
$authorNames = array_values(array_unique($authorNames));
$coverAltParts = [];
if ($bookTitle !== '') {
    $coverAltParts[] = __('Copertina del libro "%s"', $bookTitle);
}
if (!empty($authorNames)) {
    $coverAltParts[] = __('di %s', implode(', ', $authorNames));
}
$catalogRoute = route_path('catalog');
$loginRoute = route_path('login');
// H5: le route /api/libro|/api/book|... sono registrate per-locale ATTIVO in
// web.php; un path hardcoded italiano andrebbe in 404 su installazioni senza
// it_IT. route_path risolve la route per il locale corrente (stesso fallback
// '/api/book' usato in fase di registrazione) e include il base path.
$apiBookRoute = route_path('api_book');
if ($bookPublisher !== '') {
    $coverAltParts[] = __('Editore %s', $bookPublisher);
}
$coverAlt = trim(implode(' ', $coverAltParts));
if ($coverAlt === '') {
    $coverAlt = __('Copertina del libro');
}

// Meta title ottimizzato (max 60 caratteri). Il suffisso è il nome reale
// della biblioteca (app.name): è il brand riconoscibile nelle SERP, non il
// generico "Biblioteca".
$siteBrandName = (string) ConfigStore::get('app.name', 'Pinakes');
$title = $bookTitle;
if ($bookAuthor) {
    $title .= " " . __("di") . " " . $bookAuthor;
}
$title .= " - " . $siteBrandName;
$metaTitle = $title;

// Meta description ottimizzata (max 160 caratteri). Taglio multibyte: substr()
// byte-based può spezzare un carattere UTF-8 (accenti) a metà nel <head>.
$metaDescription = '';
if ($bookDescription) {
    $plainDescription = trim(strip_tags($bookDescription));
    $metaDescription = mb_substr($plainDescription, 0, 140);
    if (mb_strlen($plainDescription) > 140) {
        $metaDescription .= '...';
    }
} else {
    $metaDescription = __("Scopri \"%s\"", $bookTitle);
    if ($bookAuthor) {
        $metaDescription .= " " . __("di %s", $bookAuthor);
    }
    if ($bookPublisher) {
        $metaDescription .= " (" . $bookPublisher . ")";
    }
    $metaDescription .= " " . __("nella nostra biblioteca.");
    if ($isAvailable) {
        $metaDescription .= " " . __("Disponibile per il prestito.");
    }
}

// Canonical URL - Safe from Host header injection. Use the canonical book
// path computed by the controller: the current URL can carry tracking
// parameters (?utm_*) that must never appear in rel=canonical.
$canonicalUrl = isset($canonicalPath)
    ? absoluteUrl($canonicalPath)
    : HtmlHelper::getCurrentUrlWithoutQuery();

// Open Graph Image - Ensure absolute URLs
$baseUrl = HtmlHelper::getBaseUrl();
if ($bookCover) {
    // $bookCover already includes base path via url(), make it absolute
    $isAbsolute = preg_match('#^(https?:)?//#', $bookCover);
    $ogImage = $isAbsolute ? $bookCover : absoluteUrl($bookCover);
} else {
    $ogImage = absoluteUrl('/uploads/copertine/placeholder.jpg');
}

// Breadcrumb Schema
$breadcrumbSchema = [
    "@context" => "https://schema.org",
    "@type" => "BreadcrumbList",
    "itemListElement" => [
        [
            "@type" => "ListItem",
            "position" => 1,
            "name" => __("Home"),
            "item" => HtmlHelper::getBaseUrl()
        ],
        [
            "@type" => "ListItem",
            "position" => 2,
            "name" => __("Catalogo"),
            "item" => HtmlHelper::getBaseUrl() . \App\Support\RouteTranslator::route('catalog')
        ]
    ]
];

$breadcrumbSchema["itemListElement"][] = [
    "@type" => "ListItem",
    "position" => 3,
    "name" => $bookTitle
];

// Book Schema.org
$bookSchema = [
    "@context" => "https://schema.org",
    "@type" => \App\Support\MediaLabels::schemaOrgType($resolvedTipoMedia),
    "name" => $bookTitle,
    "url" => $canonicalUrl,
];

// sameAs: build real URLs from ISBN for external book databases
$sameAsLinks = [];
if ($bookISBN) {
    $isbn = preg_replace('/[^0-9X]/', '', strtoupper($bookISBN)) ?? '';
    if (strlen($isbn) === 13) {
        $sameAsLinks[] = 'https://openlibrary.org/isbn/' . $isbn;
        $sameAsLinks[] = 'https://books.google.com/books?vid=ISBN' . $isbn;
        $sameAsLinks[] = 'https://www.worldcat.org/isbn/' . $isbn;
    } elseif (strlen($isbn) === 10) {
        $sameAsLinks[] = 'https://openlibrary.org/isbn/' . $isbn;
        $sameAsLinks[] = 'https://www.worldcat.org/isbn/' . $isbn;
    }
}
// Add BIBFRAME instance persistent URI as sameAs identifier only when plugin is active
if (!empty($bibframePluginActive)) {
    $sameAsLinks[] = absoluteUrl('/id/instance/' . (int) $book['id']);
}
// FIX F012: skip empty sameAs to avoid noisy "sameAs": [] in JSON-LD
if (!empty($sameAsLinks)) {
    $bookSchema['sameAs'] = $sameAsLinks;
}

// Include ALL authors with proper Schema.org roles
$schemaAuthors = [];
$schemaTranslators = [];
$schemaIllustrators = [];
$schemaEditors = [];
$schemaContributors = [];
$validExternalSameAs = static function (mixed $uri): ?string {
    if (!is_string($uri)) {
        return null;
    }
    $uri = trim($uri);
    if ($uri === ''
        || filter_var($uri, FILTER_VALIDATE_URL) === false
        || !preg_match('#^https?://#i', $uri)
        || strpbrk($uri, "<>,\r\n") !== false) {
        return null;
    }
    return $uri;
};
foreach ($authors as $authorData) {
    $name = trim(html_entity_decode($authorData['nome'] ?? '', ENT_QUOTES, 'UTF-8'));
    if ($name === '') {
        continue;
    }
    $person = ["@type" => "Person", "name" => $name];
    // Add VIAF/ISNI sameAs when available (from viaf-authority plugin columns)
    $personSameAs = [];
    if (!empty($authorData['viaf_uri']) && ($viafUri = $validExternalSameAs($authorData['viaf_uri'])) !== null) {
        $personSameAs[] = $viafUri;
    } elseif (!empty($authorData['viaf_id']) && is_string($authorData['viaf_id'])) {
        $viafId = trim($authorData['viaf_id']);
        if (preg_match('/^\d+$/', $viafId)) {
            $personSameAs[] = 'https://viaf.org/viaf/' . $viafId;
        }
    }
    if (!empty($authorData['isni_uri']) && ($isniUri = $validExternalSameAs($authorData['isni_uri'])) !== null) {
        $personSameAs[] = $isniUri;
    } elseif (!empty($authorData['isni_id']) && is_string($authorData['isni_id'])) {
        $isniNorm = preg_replace('/\s+/', '', $authorData['isni_id']);
        if ($isniNorm !== null && preg_match('/^\d{15}[\dX]$/i', $isniNorm)) {
            $personSameAs[] = 'https://isni.org/isni/' . $isniNorm;
        }
    }
    if (!empty($personSameAs)) {
        $person['sameAs'] = count($personSameAs) === 1 ? $personSameAs[0] : $personSameAs;
    }
    $role = $authorData['ruolo'] ?? 'principale';
    switch ($role) {
        case 'traduttore':
            $schemaTranslators[] = $person;
            break;
        case 'illustratore':
            $schemaIllustrators[] = $person;
            break;
        case 'curatore':
            $schemaEditors[] = $person;
            break;
        case 'colorista':
            $schemaContributors[] = $person;
            break;
        default: // principale, co-autore
            $schemaAuthors[] = $person;
            break;
    }
}
// Contributors in the JSON-LD are emitted from libri_autori entities only, to
// match the visible book-detail page and the admin sheet (entity-only policy,
// #237). The legacy free-text columns (libri.traduttore/illustratore/curatore)
// are NOT used as a fallback here: they are a '; '-joined cache of ALL role
// entities (ContributorSync), so treating the whole column as one Person name
// produced bogus multi-name Persons in the Schema.org output.

if ($bookDescription) {
    $bookSchema["description"] = strip_tags($bookDescription);
}

if ($bookCover) {
    $bookSchema["image"] = $ogImage;
}

if ($bookGenre) {
    $bookSchema["genre"] = $bookGenre;
}

if ($bookLanguage) {
    $bookSchema["inLanguage"] = $bookLanguage;
}

if ($bookYear) {
    $bookSchema["datePublished"] = (string) $bookYear;
}

// Media-specific Schema.org properties
$schemaType = \App\Support\MediaLabels::schemaOrgType($resolvedTipoMedia);

if ($schemaType === 'MusicAlbum') {
    // MusicAlbum: use byArtist, recordLabel, numTracks
    if (!empty($schemaAuthors)) {
        $bookSchema["byArtist"] = count($schemaAuthors) === 1 ? $schemaAuthors[0] : $schemaAuthors;
    }
    if ($bookPublisher) {
        $bookSchema["recordLabel"] = ["@type" => "Organization", "name" => $bookPublisher];
    }
    if ($bookPages) {
        $bookSchema["numTracks"] = (int) $bookPages;
    }
    if (!empty($book['ean'])) {
        $bookSchema["identifier"] = [
            "@type" => "PropertyValue",
            "propertyID" => "EAN",
            "value" => $book['ean'],
        ];
    }
} elseif ($schemaType === 'Movie') {
    // Movie: use director, productionCompany, duration
    if (!empty($schemaAuthors)) {
        $bookSchema["director"] = count($schemaAuthors) === 1 ? $schemaAuthors[0] : $schemaAuthors;
    }
    if ($bookPublisher) {
        $bookSchema["productionCompany"] = ["@type" => "Organization", "name" => $bookPublisher];
    }
    if (!empty($book['ean'])) {
        $bookSchema["identifier"] = [
            "@type" => "PropertyValue",
            "propertyID" => "EAN",
            "value" => $book['ean'],
        ];
    }
} elseif ($schemaType === 'Audiobook') {
    // Audiobook: use author, publisher, readBy (translator as narrator)
    if (!empty($schemaAuthors)) {
        $bookSchema["author"] = count($schemaAuthors) === 1 ? $schemaAuthors[0] : $schemaAuthors;
    }
    if ($bookPublisher) {
        $bookSchema["publisher"] = ["@type" => "Organization", "name" => $bookPublisher];
    }
    if (!empty($schemaTranslators)) {
        $bookSchema["readBy"] = count($schemaTranslators) === 1 ? $schemaTranslators[0] : $schemaTranslators;
    }
    if ($bookISBN) {
        $bookSchema["isbn"] = $bookISBN;
    }
} elseif ($schemaType === 'CreativeWork') {
    // CreativeWork (altro): generic properties only — no Book-specific fields
    if (!empty($schemaAuthors)) {
        $bookSchema["author"] = count($schemaAuthors) === 1 ? $schemaAuthors[0] : $schemaAuthors;
    }
    if ($bookPublisher) {
        $bookSchema["publisher"] = ["@type" => "Organization", "name" => $bookPublisher];
    }
    if (!empty($book['ean'])) {
        $bookSchema["identifier"] = [
            "@type" => "PropertyValue",
            "propertyID" => "EAN",
            "value" => $book['ean'],
        ];
    }
} else {
    // Book (default): full book properties
    if (!empty($schemaAuthors)) {
        $bookSchema["author"] = count($schemaAuthors) === 1 ? $schemaAuthors[0] : $schemaAuthors;
    }
    if (!empty($schemaTranslators)) {
        $bookSchema["translator"] = count($schemaTranslators) === 1 ? $schemaTranslators[0] : $schemaTranslators;
    }
    if (!empty($schemaIllustrators)) {
        $bookSchema["illustrator"] = count($schemaIllustrators) === 1 ? $schemaIllustrators[0] : $schemaIllustrators;
    }
    if (!empty($schemaEditors)) {
        $bookSchema["editor"] = count($schemaEditors) === 1 ? $schemaEditors[0] : $schemaEditors;
    }
    if ($bookPublisher) {
        $bookSchema["publisher"] = ["@type" => "Organization", "name" => $bookPublisher];
    }
    if ($bookISBN) {
        $bookSchema["isbn"] = $bookISBN;
    }
    if (!empty($book['issn'])) {
        $bookSchema["identifier"] = [
            "@type" => "PropertyValue",
            "propertyID" => "ISSN",
            "value" => $book['issn'],
        ];
    }
    if ($bookPages) {
        $bookSchema["numberOfPages"] = (int) $bookPages;
    }
    $bookEdition = trim($book['edizione'] ?? '');
    if ($bookEdition !== '') {
        $bookSchema["bookEdition"] = $bookEdition;
    }
}

// Colorists do not have a dedicated Schema.org Book property; expose them as
// generic contributors for every supported media type instead of mislabelling
// them as primary authors.
if (!empty($schemaContributors)) {
    $bookSchema["contributor"] = count($schemaContributors) === 1 ? $schemaContributors[0] : $schemaContributors;
}

// Availability — only include Offer when the item has a price
if ($bookPrice) {
    $appName = (string) ConfigStore::get('app.name', __('Biblioteca'));
    $bookSchema["offers"] = [
        "@type" => "Offer",
        "price" => $bookPrice,
        "priceCurrency" => (string) ConfigStore::get('app.currency', 'EUR'),
        "seller" => [
            "@type" => "Library",
            "name" => $appName
        ]
    ];
    // Shared HTML must not publish a stale structured-data availability claim.
    if (!$edgeCacheEnabled) {
        $bookSchema["offers"]["availability"] = $isAvailable
            ? "https://schema.org/InStock"
            : "https://schema.org/OutOfStock";
    }
}

// Aggrega i rating se disponibili
if (!empty($reviewStats) && $reviewStats['total_reviews'] > 0) {
    $bookSchema["aggregateRating"] = [
        "@type" => "AggregateRating",
        "ratingValue" => (string)$reviewStats['average_rating'],
        "reviewCount" => (string)$reviewStats['total_reviews'],
        "bestRating" => "5",
        "worstRating" => "1"
    ];
}

// Organization Schema — the entity is the configured library, by its real name
$organizationSchema = [
    "@context" => "https://schema.org",
    "@type" => "Library",
    "name" => $siteBrandName,
    "url" => rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . '/',
    "description" => __("Biblioteca digitale con catalogo completo di libri disponibili per il prestito")
];
// The page surface lives in public/assets/book-detail.css so other single-
// resource pages (e.g. the emeroteca article, issue and periodical pages) can
// reuse it; the layout links it.
$bookDetailStyles = true;

ob_start();
?>

<!-- Book Hero Section -->
<?php
$pkHasCover = !str_contains($bookCover, 'placeholder');
$pkYear = trim((string) ($book['anno_pubblicazione'] ?? ''));
// "1 copia disponibile su 1": what the library owns, as the sidebar counts it (#426).
$pkPublishedTotal = \App\Support\CopyHoldings::publishedTotal($bookHoldings ?? null, (int) ($book['copie_totali'] ?? 0));
$pkAvailable = (int) ($book['copie_disponibili'] ?? 0);
// Quick facts under the availability box, as in the design.
// A book reads "Anno" and "Pagine" as in the design; other media keep their own labels (tracks, duration…).
$pkIsBook = $resolvedTipoMedia === 'libro';
$pkQuick = array_values(array_filter([
    [$pkIsBook ? __('Anno') : \App\Support\MediaLabels::label('anno_pubblicazione', $book['formato'] ?? null, $book['tipo_media'] ?? null), $pkYear],
    [$pkIsBook ? __('Pagine') : \App\Support\MediaLabels::label('numero_pagine', $book['formato'] ?? null, $book['tipo_media'] ?? null), trim((string) ($book['numero_pagine'] ?? ''))],
    [__('Formato'), !empty($book['formato']) ? \App\Support\MediaLabels::formatDisplayName((string) $book['formato']) : ''],
    ['ISBN', trim((string) ($book['isbn13'] ?? '')) !== '' ? (string) $book['isbn13'] : trim((string) ($book['isbn10'] ?? ''))],
], static fn (array $f): bool => $f[1] !== ''));
ob_start();
do_action('book.detail.digital_player', $book);
$pkDigitalPlayer = trim((string) ob_get_clean());
// The plugins' buttons (digital files, "search on" links) go under the quick
// facts, as in the design, not in the availability box. Same gate as before:
// nothing for a catalogue-only install or a book the library does not own.
$pkDigitalButtons = '';
if (!$isCatalogueMode && empty($book['is_desiderata'])) {
    ob_start();
    do_action('book.detail.digital_buttons', $book);
    $pkDigitalButtons = trim((string) ob_get_clean());
}
// "Contenuti digitali" heads the block only when there is a digital file;
// the "search on" links alone stand without it.
$pkHasDigitalFiles = $pkDigitalPlayer !== '' || str_contains($pkDigitalButtons, 'digital-attachments') || str_contains($pkDigitalButtons, 'plugin-book-actions');
?>
<section class="book-hero pk-bookhero">
    <div class="pk-wrap">
                    <?php
                    // Multi-publisher (issue #143): link every publisher, fallback to primary.
                    $heroPublishers = $book['editori'] ?? [];
                    if ($heroPublishers === [] && !empty($book['editore'])) {
                        $heroPublishers = [['nome' => $book['editore']]];
                    }
                    ?>
        <nav class="book-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
            <ol class="breadcrumb pk-crumbs">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= __("Home") ?></a></li>
                <li class="pk-crumbs__sep" aria-hidden="true">/</li>
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>"><?= __("Catalogo") ?></a></li>
                <li class="pk-crumbs__sep" aria-hidden="true">/</li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?></li>
            </ol>
        </nav>
        <div class="book-hero-content pk-bookhero__grid" id="book-hero-content">
            <div class="book-cover-column pk-bookhero__cover" id="book-cover-container">
                <div class="pk-bigcover">
                    <div class="pk-book__blank" aria-hidden="true">
                        <div class="pk-book__blank-title"><?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?></div>
                        <div class="pk-book__rule"></div>
                    </div>
                    <img src="<?= htmlspecialchars($bookCover, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($coverAlt, ENT_QUOTES, 'UTF-8') ?>"
                         class="book-cover-large img-fluid pk-book__img<?= $pkHasCover ? '' : ' is-missing' ?>"
                         fetchpriority="high" decoding="async" data-pk-tone
                         onerror="this.onerror=null;this.classList.add('is-missing')"
                         id="book-cover-image">
                    <div class="pk-book__spine" aria-hidden="true"></div>
                </div>
            </div>
            <div class="book-info-column pk-bookhero__info">
                <div class="hero-text">
                    <div class="book-kicker pk-kicker">
                        <span class="book-media-type pk-kicker__type">
                            <i class="fas <?= htmlspecialchars(\App\Support\MediaLabels::icon($resolvedTipoMedia), ENT_QUOTES, 'UTF-8') ?> mr-1" aria-hidden="true"></i><?= \App\Support\MediaLabels::tipoMediaDisplayName($resolvedTipoMedia) ?>
                        </span>
                        <?php if ($heroPublishers !== []): ?>
                            <span class="book-hero-publishers pk-kicker__pub">
                                <?php foreach ($heroPublishers as $hpI => $hp):
                                    $hpName = html_entity_decode((string) ($hp['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    if ($hpName === '') { continue; }
                                ?><?= $hpI > 0 ? ', ' : '' ?><a href="<?= htmlspecialchars(route_path('publisher') . '/' . urlencode($hpName), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($hpName) ?></a><?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($pkYear !== ''): ?><span class="pk-kicker__year">· <?= htmlspecialchars($pkYear, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </div>
                    <h1 class="pk-bookhero__title" id="book-title"><?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?></h1>

                    <?php if (!empty($book['sottotitolo'])): ?>
                    <p class="book-subtitle-hero pk-bookhero__subtitle" id="book-subtitle"><?= htmlspecialchars(html_entity_decode($book['sottotitolo'], ENT_QUOTES, 'UTF-8')) ?></p>
                    <?php endif; ?>

                    <div class="authors-list pk-authors" id="book-authors-list">
                        <?php foreach($authors as $author): ?>
                            <?php
                                // Pseudonym-aware display "Pseudonimo (Nome)". Issue #237.
                                // The link targets the author id, not the name: homonyms
                                // are legitimate, so a name URL could open another
                                // person's archive that does not list this book.
                                $authorHrefId = (int) ($author['id'] ?? 0);
                                $authorHref = $authorHrefId > 0
                                    ? route_path('author') . '/' . $authorHrefId
                                    : route_path('author') . '/' . rawurlencode(html_entity_decode($author['nome'] ?? '', ENT_QUOTES, 'UTF-8'));
                                $authorDisplay = \App\Support\AuthorName::display([
                                    'nome' => html_entity_decode($author['nome'] ?? '', ENT_QUOTES, 'UTF-8'),
                                    'pseudonimo' => html_entity_decode($author['pseudonimo'] ?? '', ENT_QUOTES, 'UTF-8'),
                                ]);
                            ?>
                            <?php
                                $authorInitials = '';
                                foreach (array_slice(preg_split('/[\s,]+/u', trim($authorDisplay)) ?: [], 0, 2) as $authorWord) {
                                    $authorInitials .= mb_strtoupper(mb_substr($authorWord, 0, 1));
                                }
                            ?>
                            <a href="<?= htmlspecialchars($authorHref, ENT_QUOTES, 'UTF-8') ?>" class="no-underline pk-author">
                                <span class="pk-author__initials" aria-hidden="true"><?= htmlspecialchars($authorInitials, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="author-item role-<?= htmlspecialchars($author['ruolo'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($authorDisplay, ENT_QUOTES, 'UTF-8') ?><?php if ($author['ruolo'] !== 'principale'): ?> <span class="contributor-role-sep">·</span> <?= htmlspecialchars(\App\Support\ContributorRoles::label($author['ruolo']), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($genreHierarchy)): ?>
                    <div class="genre-tags" aria-label="<?= htmlspecialchars(__('Generi'), ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fas fa-tags" aria-hidden="true"></i><?php $genreLinkClass = 'genre-tag'; $genreSeparator = ' <span class="genre-separator" aria-hidden="true">›</span> '; include __DIR__ . '/partials/genre-breadcrumb.php'; ?>
                    </div>
                    <?php endif; ?>

                    <div class="pk-availbox">
                        <div class="pk-availbox__status">
                        <?php if (!empty($book['is_desiderata'])): ?>
                        <?php
                        // No data-live-* attributes on purpose: this book has
                        // no copies to hydrate, and the edge-cache availability
                        // script only rewrites badges that carry them.
                        ?>
                        <span class="availability-badge wanted"><i class="fas fa-hand-holding-heart mr-2" aria-hidden="true"></i><span><?= htmlspecialchars(__('Cercato dalla biblioteca'), ENT_QUOTES, 'UTF-8') ?></span></span>
                        <?php else: ?>
                        <span class="availability-badge <?= $edgeCacheEnabled ? 'availability-pending' : (($book['copie_disponibili'] > 0) ? 'available' : 'unavailable') ?>"<?= $edgeCacheEnabled ? ' data-live-book-id="' . (int) $book['id'] . '" data-live-role="detail-badge" data-live-pending="1"' : '' ?>>
                            <i class="fas fa-<?= $edgeCacheEnabled ? 'circle-notch' : (($book['copie_disponibili'] > 0) ? 'check-circle' : 'times-circle') ?> mr-2" aria-hidden="true"></i>
                            <span data-live-label><?= $edgeCacheEnabled ? htmlspecialchars(__("Verifica disponibilità"), ENT_QUOTES, 'UTF-8') : (($book['copie_disponibili'] > 0)
                                ? ($book['copie_totali'] > 1
                                    ? "{$book['copie_disponibili']}/{$book['copie_totali']} " . __("Disponibili")
                                    : __("Disponibile"))
                                : __("Non disponibile oggi")) /* lo snapshot è di OGGI: il calendario può mostrare giorni futuri liberi */ ?></span>
                        </span>
                        <?php endif; ?>
                            <?php if (empty($book['is_desiderata']) && !$edgeCacheEnabled && $pkPublishedTotal > 0): ?>
                            <div class="pk-availbox__copies"><?= htmlspecialchars(sprintf($pkAvailable === 1 ? __('%1$d copia disponibile su %2$d') : __('%1$d copie disponibili su %2$d'), $pkAvailable, $pkPublishedTotal), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                <!-- Action Buttons -->
                <?php // No loan, reservation or favourite for a book the library does not own. ?>
                <?php if (!$isCatalogueMode && empty($book['is_desiderata'])): ?>
                <div class="action-buttons pk-availbox__actions" id="book-action-buttons">
                    <!-- Always show the calendar to choose dates -->
                    <button id="btn-request-loan" type="button" class="ui-button <?= !$edgeCacheEnabled && ($book['copie_disponibili'] ?? 0) > 0 ? 'btn-primary' : 'btn-outline-primary' ?> px-8 py-4 text-base" data-libro-id="<?= (int)($book['id'] ?? 0) ?>"<?= !$edgeCacheEnabled && $nothingInCirculation ? ' disabled' : '' ?><?= $edgeCacheEnabled ? ' data-live-book-id="' . (int) $book['id'] . '" data-live-role="action" data-live-pending="1"' : '' ?>>
                        <i class="fas fa-<?= $edgeCacheEnabled ? 'circle-notch' : ((($book['copie_disponibili'] ?? 0) > 0) ? 'book-reader' : ($nothingInCirculation ? 'ban' : 'calendar-alt')) ?> mr-2"></i>
                        <span data-live-label><?= $edgeCacheEnabled ? __('Verifica disponibilità') : ((($book['copie_disponibili'] ?? 0) > 0) ? __('Richiedi Prestito') : ($nothingInCirculation ? __('Momentaneamente non prenotabile') : __('Prenota Quando Disponibile'))) ?></span>
                    </button>
                    <?php $isLogged = !empty($_SESSION['user'] ?? null); ?>
                    <?php if ($isLogged): ?>
                      <button id="btn-fav" type="button" class="ui-button btn-secondary px-8 py-4 text-base btn-fav-custom" data-libro-id="<?= (int)($book['id'] ?? 0) ?>">
                        <i class="fas fa-heart mr-2"></i><span><?= __("Aggiungi ai Preferiti") ?></span>
                      </button>
                    <?php else: ?>
                      <a href="<?= htmlspecialchars($loginRoute, ENT_QUOTES, 'UTF-8') ?>" class="ui-button btn-secondary px-8 py-4 text-base btn-fav-custom">
                        <i class="fas fa-heart mr-2"></i><?= __("Accedi per aggiungere ai Preferiti") ?>
                      </a>
                    <?php endif; ?>

                </div>
                <?php endif; ?>



                    </div>

                    <?php if ($pkQuick !== []): ?>
                    <dl class="pk-quick">
                        <?php foreach ($pkQuick as [$pkK, $pkV]): ?>
                        <div class="pk-quick__item"><dt><?= htmlspecialchars((string) $pkK, ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars((string) $pkV, ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <?php endif; ?>

                    <?php if ($pkDigitalButtons !== '' || $pkDigitalPlayer !== ''): ?>
                    <div class="pk-digital">
                        <?php if ($pkHasDigitalFiles): ?><div class="pk-label"><?= __("Contenuti digitali") ?></div><?php endif; ?>
                        <?php // Hook book.detail.digital_buttons: plugins add digital content buttons (eBook, audiobook) and external search links. ?>
                        <?= $pkDigitalButtons ?>
                        <?= $pkDigitalPlayer ?>
                    </div>
                    <?php endif; ?>

                <!-- Alerts Section -->
                <div id="book-alerts">
                    <?php if (!empty($_GET['loan_request_success'])): ?>
                        <div class="alert alert-success relative pr-12 fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i><?php
                              // Branch on the persisted state (F009): a future-dated
                              // auto-approved loan is 'prenotato' (scheduled), not
                              // awaiting pickup.
                              $loanStateRaw = $_GET['loan_state'] ?? '';
                              $loanStateKey = is_scalar($loanStateRaw) ? (string) $loanStateRaw : '';
                              if (!empty($_GET['auto_approved'])) {
                                  echo $loanStateKey === 'prenotato'
                                      ? __("Prestito prenotato: riceverai le istruzioni per il ritiro quando la data di inizio si avvicina.")
                                      : __("Prestito approvato. Il libro è in attesa di ritiro.");
                              } else {
                                  echo __("Prestito richiesto con successo.");
                              }
                            ?>
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= htmlspecialchars(__('Chiudi'), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </div>
                    <?php elseif (!empty($_GET['loan_error'])): ?>
                        <div class="alert alert-error relative pr-12 fade show" role="alert">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php
                              // Guardia is_scalar come per reserve_error: con ?loan_error[]=x
                              // il cast diretto (string) genererebbe un warning in PHP 8.
                              $loanErrorRaw = $_GET['loan_error'];
                              $loanErrorKey = is_scalar($loanErrorRaw) ? (string) $loanErrorRaw : '';
                            ?>
                            <?php if ($loanErrorKey === 'not_available'): ?>
                              <?= __('Nessuna copia disponibile per il periodo richiesto.') ?>
                            <?php elseif ($loanErrorKey === 'max_loans_reached'): ?>
                              <?= __('Hai raggiunto il numero massimo di prestiti attivi consentiti') ?>
                            <?php else: ?>
                              <?= __('Errore nella richiesta di prestito.') ?>
                            <?php endif; ?>
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= htmlspecialchars(__('Chiudi'), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($_GET['reserve_success'])): ?>
                        <div class="alert alert-success relative pr-12 fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i><?= __("Prenotazione effettuata con successo") ?><?php if(!empty($_GET['reserve_date'])): ?> <?= __("per il giorno") ?> <strong><?= htmlspecialchars($_GET['reserve_date'], ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>.
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= htmlspecialchars(__('Chiudi'), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </div>
                    <?php elseif (!empty($_GET['reserve_error'])): ?>
                        <div class="alert alert-error relative pr-12 fade show" role="alert">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php
                              $reserveErrorMessages = [
                                  'duplicate' => __('Hai già una prenotazione attiva per questo libro.'),
                                  'invalid_date' => __('Data non valida.'),
                                  'past_date' => __('La data non può essere nel passato.'),
                                  'not_available' => __('Nessuna copia disponibile per il periodo richiesto.')
                              ];
                              // Defense-in-depth: the values are __() translations from
                              // the i18n catalog (app-controlled), and unknown keys fall
                              // back to a static literal — but escape on output anyway so
                              // a future contributor adding a free-form message to the map
                              // (or a translation containing markup) can't break HTML
                              // context here. We're inside the !empty($_GET['reserve_error'])
                              // branch; reject non-scalar input (e.g. ?reserve_error[]=)
                              // before stringifying — direct (string) cast on an array
                              // still raises an "Array to string conversion" warning in
                              // PHP 8.x.
                              $reserveErrorRaw = $_GET['reserve_error'];
                              $reserveErrorKey = is_scalar($reserveErrorRaw) ? (string) $reserveErrorRaw : '';
                              echo htmlspecialchars(
                                  $reserveErrorMessages[$reserveErrorKey] ?? __('Errore nella prenotazione.'),
                                  ENT_QUOTES,
                                  'UTF-8'
                              );
                            ?>
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= htmlspecialchars(__('Chiudi'), ENT_QUOTES, 'UTF-8') ?>"></button>
                        </div>
                    <?php endif; ?>
                </div>

                </div>
            </div>
        </div>
    </div>
</section>

<!-- Book Details Section -->
<section class="pk-bookbody">
    <div class="pk-wrap">
        <div class="pk-bookbody__grid">
            <!-- Main Content -->
            <div class="pk-bookbody__main">
                <!-- Description / Tracklist Section -->
                <div class="book-description-section" id="book-description-section">
                    <h2 class="section-title">
                        <i class="fas <?= $isMusic ? 'fa-music' : 'fa-info-circle' ?>"></i>
                        <?= \App\Support\MediaLabels::label('descrizione', $book['formato'] ?? null, $book['tipo_media'] ?? null) ?>
                    </h2>
                    <div class="description-content">
                        <?php if (!empty($book['descrizione'])): ?>
                            <?php if ($isMusic): ?>
                                <?php $musicDescription = (string) $book['descrizione']; ?>
                                <div class="prose prose-sm max-w-none">
                                    <?= str_contains($musicDescription, '<li')
                                        ? \App\Support\HtmlHelper::sanitizeHtml($musicDescription)
                                        : \App\Support\MediaLabels::formatTracklist($musicDescription) ?>
                                </div>
                            <?php else: ?>
                                <div class="prose prose-sm max-w-none"><?= \App\Support\HtmlHelper::bookDescription($book['descrizione']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-gray-500"><?= __("Nessuna descrizione disponibile per questo libro.") ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- LibraryThing Fields Section -->
                <?php
                // Parse LibraryThing visibility settings
                $ltVisibility = [];
                if (!empty($book['lt_fields_visibility'])) {
                    $ltVisibility = json_decode($book['lt_fields_visibility'], true) ?: [];
                }

                // Privacy-sensitive fields that should NEVER be shown in frontend
                // Administrative/metadata fields are now controlled by visibility checkboxes
                $privateFields = [
                    'private_comment',  // Private comments
                    'lending_patron',   // Chi ha preso in prestito (privacy)
                    'lending_status',   // Stato prestito (dati prestiti sensibili)
                    'lending_start',    // Date prestito (privacy)
                    'lending_end',      // Date prestito (privacy)
                ];

                // Filter to show only visible, public fields that have values
                $visibleLtFields = [];
                $ltFieldLabels = \App\Support\LibraryThingInstaller::getLibraryThingFields();

                foreach ($ltVisibility as $fieldName => $isVisible) {
                    // Skip if field is private/administrative
                    if (in_array($fieldName, $privateFields)) {
                        continue;
                    }

                    // Show only if visible, has value, and has label
                    // Note: Don't use empty() as it excludes numeric zero values
                    if ($isVisible && isset($book[$fieldName]) && $book[$fieldName] !== '' && isset($ltFieldLabels[$fieldName])) {
                        $visibleLtFields[$fieldName] = [
                            'label' => $ltFieldLabels[$fieldName],
                            'value' => $book[$fieldName]
                        ];
                    }
                }
                ?>
                <?php if (!empty($visibleLtFields)): ?>
                <div class="book-details-section" id="librarything-fields-section">
                    <h2 class="section-title">
                        <i class="fas fa-info-circle"></i>
                        <?= __("Informazioni Aggiuntive") ?>
                    </h2>
                    <div class="details-grid">
                        <?php
                        // Split fields into two columns
                        $half = (int) ceil(count($visibleLtFields) / 2);
                        $column1 = array_slice($visibleLtFields, 0, $half, true);
                        $column2 = array_slice($visibleLtFields, $half, null, true);
                        ?>
                        <div class="details-column">
                            <?php foreach ($column1 as $fieldName => $field): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="meta-value">
                                    <?php if (in_array($fieldName, ['rating'])): ?>
                                        <?php
                                        $rating = (int)$field['value'];
                                        for ($i = 1; $i <= 5; $i++):
                                            if ($i <= $rating):
                                                echo '<i class="fas fa-star text-amber-600"></i>';
                                            else:
                                                echo '<i class="far fa-star text-gray-500"></i>';
                                            endif;
                                        endfor;
                                        ?>
                                    <?php elseif (in_array($fieldName, ['date_started', 'date_read', 'lending_start', 'lending_end'])): ?>
                                        <?php
                                        $timestamp = strtotime($field['value']);
                                        echo ($timestamp && $timestamp > 0)
                                            ? htmlspecialchars(date('d/m/Y', $timestamp), ENT_QUOTES, 'UTF-8')
                                            : '-';
                                        ?>
                                    <?php elseif (in_array($fieldName, ['value'])): ?>
                                        € <?= number_format((float)$field['value'], 2) ?>
                                    <?php elseif (in_array($fieldName, ['review', 'comment'])): ?>
                                        <div class="prose prose-sm max-w-none"><?= \App\Support\HtmlHelper::sanitizeHtml(nl2br($field['value'], false)) ?></div>
                                    <?php else: ?>
                                        <?= htmlspecialchars($field['value'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="details-column">
                            <?php foreach ($column2 as $fieldName => $field): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="meta-value">
                                    <?php if (in_array($fieldName, ['rating'])): ?>
                                        <?php
                                        $rating = (int)$field['value'];
                                        for ($i = 1; $i <= 5; $i++):
                                            if ($i <= $rating):
                                                echo '<i class="fas fa-star text-amber-600"></i>';
                                            else:
                                                echo '<i class="far fa-star text-gray-500"></i>';
                                            endif;
                                        endfor;
                                        ?>
                                    <?php elseif (in_array($fieldName, ['date_started', 'date_read', 'lending_start', 'lending_end'])): ?>
                                        <?php
                                        $timestamp = strtotime($field['value']);
                                        echo ($timestamp && $timestamp > 0)
                                            ? htmlspecialchars(date('d/m/Y', $timestamp), ENT_QUOTES, 'UTF-8')
                                            : '-';
                                        ?>
                                    <?php elseif (in_array($fieldName, ['value'])): ?>
                                        € <?= number_format((float)$field['value'], 2) ?>
                                    <?php elseif (in_array($fieldName, ['review', 'comment'])): ?>
                                        <div class="prose prose-sm max-w-none"><?= \App\Support\HtmlHelper::sanitizeHtml(nl2br($field['value'], false)) ?></div>
                                    <?php else: ?>
                                        <?= htmlspecialchars($field['value'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Reviews Section -->
                <?php if (!empty($reviews) && count($reviews) > 0): ?>
                <div class="book-reviews-section" id="book-reviews-section">
                    <h2 class="section-title">
                        <i class="fas fa-star"></i>
                        <?= __("Recensioni") ?>
                        <span class="status-badge bg-gray-900"><?= count($reviews) ?></span>
                    </h2>

                    <!-- Review Statistics -->
                    <?php if ($reviewStats['total_reviews'] > 0): ?>
                        <div class="review-stats mb-4">
                            <div class="flex flex-wrap -mx-3 items-center">
                                <div class="w-full md:w-1/3 px-3 text-center mb-3 mb-md-0 review-summary-column">
                                    <div class="average-rating">
                                        <div class="display-4 font-bold text-amber-600"><?= number_format($reviewStats['average_rating'], 1) ?></div>
                                        <div class="stars mb-2">
                                            <?php
                                            $avgRating = $reviewStats['average_rating'];
                                            for ($i = 1; $i <= 5; $i++):
                                                if ($i <= floor($avgRating)):
                                                    echo '<i class="fas fa-star text-amber-600"></i>';
                                                elseif ($i - 0.5 <= $avgRating):
                                                    echo '<i class="fas fa-star-half-alt text-amber-600"></i>';
                                                else:
                                                    echo '<i class="far fa-star text-amber-600"></i>';
                                                endif;
                                            endfor;
                                            ?>
                                        </div>
                                        <div class="text-gray-500 text-sm"><?= $reviewStats['total_reviews'] ?> <?= __("recensioni") ?></div>
                                    </div>
                                </div>
                                <div class="w-full md:w-2/3 px-3 review-distribution-column">
                                    <div class="rating-bars">
                                        <?php
                                        $total = $reviewStats['total_reviews'];
                                        for ($stars = 5; $stars >= 1; $stars--):
                                            $count = $reviewStats[$stars === 1 ? 'one_star' : ($stars === 2 ? 'two_star' : ($stars === 3 ? 'three_star' : ($stars === 4 ? 'four_star' : 'five_star')))];
                                            $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                                        ?>
                                            <div class="rating-bar-row flex items-center">
                                                <div class="stars-label mr-2">
                                                    <?php for ($i = 0; $i < $stars; $i++): ?>
                                                        <i class="fas fa-star text-amber-600 text-sm"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <div class="progress grow mr-2" style="height: 8px;">
                                                    <div class="progress-bar bg-amber-500" role="progressbar" style="width: <?= $percentage ?>%"></div>
                                                </div>
                                                <div class="count-label text-gray-500 text-sm" style="width: 40px;"><?= $count ?></div>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Individual Reviews -->
                    <div class="reviews-list">
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-item border-b pb-4 mb-4">
                                <div class="review-header flex justify-between items-start mb-2">
                                    <div class="review-user-info flex items-center">
                                        <div class="avatar-placeholder bg-gray-900 text-white rounded-full flex items-center justify-center mr-3"
                                             style="width: 40px; height: 40px;">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold"><?= htmlspecialchars($review['utente_nome'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-gray-500 text-sm">
                                                <i class="fas fa-calendar mr-1"></i>
                                                <?= format_date($review['approved_at'], false, '/') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="review-stars">
                                        <?php for ($i = 0; $i < 5; $i++): ?>
                                            <i class="<?= $i < $review['stelle'] ? 'fas' : 'far' ?> fa-star text-amber-600"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <?php if (!empty($review['titolo'])): ?>
                                    <h5 class="review-title font-bold mb-2"><?= htmlspecialchars($review['titolo'], ENT_QUOTES, 'UTF-8') ?></h5>
                                <?php endif; ?>

                                <?php if (!empty($review['descrizione'])): ?>
                                    <p class="review-text mb-0"><?= nl2br(htmlspecialchars($review['descrizione'], ENT_QUOTES, 'UTF-8')) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Plugin hook: Additional content in book detail page (frontend)
                \App\Support\Hooks::do('book.frontend.details', [$book, $book['id'] ?? null]);
                ?>
            </div>

            <!-- Sidebar -->
            <aside class="pk-bookbody__aside" id="book-sidebar">
                <!-- Book Info Card -->
                <div class="card mb-4" style="position: relative; z-index: 100;" id="book-info-card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle mr-2"></i><?= __("Informazioni Libro") ?></h6>
                    </div>
                    <div class="card-body">
                        <?php
                        $metaPublishers = $book['editori'] ?? [];
                        if ($metaPublishers === [] && !empty($book['editore'])) {
                            $metaPublishers = [['nome' => $book['editore']]];
                        }
                        $metaPublisherNames = array_filter(array_map(static fn($p) => trim((string) ($p['nome'] ?? '')), $metaPublishers));
                        ?>
                        <?php if ($metaPublisherNames !== []): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= \App\Support\MediaLabels::label('editore', $book['formato'] ?? null, $book['tipo_media'] ?? null) ?></div>
                            <div class="meta-value"><?= htmlspecialchars(implode(', ', $metaPublisherNames), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (trim((string) ($book['luogo_pubblicazione'] ?? '')) !== ''): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Luogo di pubblicazione") ?></div>
                            <div class="meta-value"><?= App\Support\HtmlHelper::e((string) $book['luogo_pubblicazione']) ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="meta-item">
                            <div class="meta-label"><?= __("Stato") ?></div>
                            <div class="meta-value">
                                <?php if (!empty($book['is_desiderata'])): ?>
                                <span class="book-status-inline is-wanted">
                                    <?= htmlspecialchars(__('Cercato dalla biblioteca'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php else: ?>
                                <span class="book-status-inline <?= $edgeCacheEnabled ? 'availability-pending' : (($book['copie_disponibili'] > 0) ? 'is-available' : 'is-unavailable') ?>"<?= $edgeCacheEnabled ? ' data-live-book-id="' . (int) $book['id'] . '" data-live-role="status" data-live-pending="1"' : '' ?>>
                                    <?= $edgeCacheEnabled ? __("Verifica disponibilità") : (($book['copie_disponibili'] > 0) ? __("Disponibile") : __("Non disponibile oggi")) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (empty($book['is_desiderata'])): ?>
                        <?php
                        // A wanted book owns no copy by definition: the status line
                        // above already says the library is looking for it, and a
                        // "0 / 0" underneath contradicted that invitation — while,
                        // with the edge cache on, it also hydrated live availability
                        // for copies that do not exist. Holdings only.
                        //
                        // The denominator is what the library owns, not what is in
                        // circulation: with the only copy under maintenance the old
                        // "0 / 0" read as "not in this library" (#426).
                        $holdings = $bookHoldings ?? null;
                        $publishedTotal = \App\Support\CopyHoldings::publishedTotal($holdings, (int) ($book['copie_totali'] ?? 0));
                        $outNote = \App\Support\CopyHoldings::outOfCirculationNote($holdings);
                        ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Copie Disponibili") ?></div>
                            <div class="meta-value <?= $edgeCacheEnabled ? 'availability-pending' : '' ?>"<?= $edgeCacheEnabled ? ' data-live-book-id="' . (int) $book['id'] . '" data-live-role="count" data-live-pending="1"' : '' ?>><?= $edgeCacheEnabled ? __("Verifica disponibilità") : ((int) $book['copie_disponibili'] . ' / ' . $publishedTotal) ?></div>
                            <?php if (!$edgeCacheEnabled && $outNote !== ''): ?>
                                <div class="meta-note"><?= htmlspecialchars(__('Copie non in circolazione'), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($outNote, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if ($edgeCacheEnabled): ?>
                                <div class="meta-note" data-live-book-id="<?= (int) $book['id'] ?>" data-live-role="count-note" hidden></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['collocazione'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Collocazione") ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['collocazione'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="meta-item">
                            <div class="meta-label"><?= __("Aggiunto il") ?></div>
                            <div class="meta-value"><?= format_date($book['created_at'], false, '/') ?></div>
                        </div>
                    </div>
                </div>

                <!-- Share Card (configurable via Settings > Sharing) -->
                <?php include __DIR__ . '/partials/social-sharing.php'; ?>

            </aside>
        </div>

        <!-- Details and keywords: out of the column, across the whole width, so a
             label and its value stay on one line. -->
        <div class="pk-bookbody__full">
            <!-- Details Section -->
            <?php
            $detailFields = [
                !empty($book['isbn13']),
                !empty($book['isbn10']),
                !empty($book['ean']),
                !empty($book['issn']),
                !empty($bookGenre),
                !empty($book['lingua']),
                !empty($book['prezzo']),
                !empty($book['anno_pubblicazione']),
                !empty($book['data_pubblicazione']),
                !empty($book['numero_pagine']),
                !empty($book['formato']),
                !empty($book['dimensioni']),
                !empty($book['peso']),
                !empty($book['numero_inventario'])
            ];
            ?>
            <?php if (in_array(true, $detailFields, true)): ?>
            <div class="book-details-section" id="book-details-section">
                <h2 class="section-title">
                    <i class="fas fa-list"></i>
                    <?= __("Dettagli Libro") ?>
                </h2>
                <div class="details-grid">
                    <div class="details-column">
                        <?php if (!empty($book['isbn13']) && !($isMusic && !empty($book['ean']))): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= \App\Support\MediaLabels::label('isbn13', $book['formato'] ?? null, $book['tipo_media'] ?? null) ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['isbn13'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!$isMusic && !empty($book['isbn10'])): ?>
                        <div class="meta-item">
                            <div class="meta-label">ISBN-10</div>
                            <div class="meta-value"><?= htmlspecialchars($book['isbn10'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['ean'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= $isMusic ? __('Barcode') : 'EAN' ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['ean'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['issn'])): ?>
                        <div class="meta-item">
                            <div class="meta-label">ISSN</div>
                            <div class="meta-value"><?= htmlspecialchars($book['issn'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($genreHierarchy)): ?>
                        <div class="meta-item meta-item--genre">
                            <div class="meta-label"><?= __("Genere") ?></div>
                            <div class="meta-value genre-path"><?php $genreLinkClass = 'genre-path__link'; $genreSeparator = '<span class="genre-path__sep" aria-hidden="true">›</span>'; include __DIR__ . '/partials/genre-breadcrumb.php'; unset($genreLinkClass, $genreSeparator); ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['lingua'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Lingua") ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['lingua'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['prezzo'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Prezzo") ?></div>
                            <div class="meta-value">€ <?= number_format($book['prezzo'], 2) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="details-column">
                        <?php if (!empty($book['anno_pubblicazione'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= \App\Support\MediaLabels::label('anno_pubblicazione', $book['formato'] ?? null, $book['tipo_media'] ?? null) ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['anno_pubblicazione'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['data_pubblicazione'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Data di Pubblicazione") ?></div>
                            <div class="meta-value"><?= App\Support\HtmlHelper::e(format_date($book['data_pubblicazione'], false, '/')) ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['numero_pagine'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= \App\Support\MediaLabels::label('numero_pagine', $book['formato'] ?? null, $book['tipo_media'] ?? null) ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['numero_pagine'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['formato'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Formato") ?></div>
                            <div class="meta-value"><?= htmlspecialchars(\App\Support\MediaLabels::formatDisplayName($book['formato']), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['dimensioni'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Dimensioni") ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['dimensioni'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['peso'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Peso") ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['peso'], ENT_QUOTES, 'UTF-8') ?> kg</div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($book['numero_inventario'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Numero Inventario") ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['numero_inventario'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            $keywords = !empty($book['parole_chiave'])
                ? array_unique(array_filter(array_map('trim', explode(',', $book['parole_chiave'])), function ($k) { return $k !== ''; }))
                : [];
            ?>
            <?php if (!empty($keywords)): ?>
            <div class="book-details-section">
                <h2 class="section-title">
                    <i class="fas fa-tags"></i>
                    <?= __("Parole Chiave") ?>
                </h2>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($keywords as $keyword): ?>
                    <a href="<?= htmlspecialchars($catalogRoute . '?q=' . urlencode($keyword), ENT_QUOTES, 'UTF-8') ?>"
                       class="status-badge bg-gray-100 text-gray-900 border px-3 py-2 no-underline keyword-chip">
                        <i class="fas fa-tag mr-1 text-gray-500"></i><?= HtmlHelper::e($keyword) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Cite Section -->
<section class="pk-cite">
    <div class="pk-wrap">
                <?php
                // "Cite" (#412): the book as the shared citation styles read it;
                // the RIS download starts from the same input.
                $citeCitations = \App\Support\CitationStyles::all(\App\Support\BookCitation::input($book, $authors));
                $citeTitle = $bookTitle;
                $citeDownloads = [
                    ['label' => __('Scarica la citazione in formato RIS (EndNote, Mendeley, Zotero)'), 'url' => url('/books/' . (int) $book['id'] . '/citation.ris')],
                ];
                ?>
                <div class="card" id="book-cite-card">
                  <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-quote-left mr-2"></i><?= htmlspecialchars(__('Cita questo libro'), ENT_QUOTES, 'UTF-8') ?></h6>
                  </div>
                  <div class="card-body py-2 px-3">
                    <?php
                    // The RIS file is already the inline "Download RIS" button: the
                    // dialog keeps its styles and copy buttons, not a second link.
                    // Its "Cite" button joins the inline actions row (cite-inline.php).
                    $citeDownloadsInline = $citeDownloads;
                    $citeDownloads = [];
                    ob_start();
                    include dirname(__DIR__) . '/partials/cite-dialog.php';
                    $pkCiteDialog = (string) ob_get_clean();
                    $citeDownloads = $citeDownloadsInline;
                    include dirname(__DIR__) . '/partials/cite-inline.php';
                    ?>
                  </div>
                </div>
    </div>
</section>

<!-- Series Section (other volumes in the same collana) -->
<?php if (!empty($seriesBooks)): ?>
<section class="py-3 book-series-section">
    <div class="container">
        <h3 class="book-series-title">
            <i class="fas fa-layer-group" aria-hidden="true"></i>
            <?= __("Nella stessa collana") ?>: <em><?= htmlspecialchars($collana, ENT_QUOTES, 'UTF-8') ?></em>
        </h3>
        <div class="flex flex-wrap justify-center gap-2">
            <?php foreach ($seriesBooks as $sb):
                $sbPath = book_path($sb);
            ?>
            <a href="<?= htmlspecialchars(url($sbPath), ENT_QUOTES, 'UTF-8') ?>" class="book-series-chip">
                <?php if (!empty($sb['numero_serie'])): ?>
                <span class="book-series-number"><?= htmlspecialchars($sb['numero_serie'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <span><?= htmlspecialchars($sb['titolo'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Related Books Section -->
<?php $pkMainBook = $book; ?>
<?php if (!empty($related_books) && count($related_books) > 0): ?>
<section class="pk-related">
    <div class="pk-wrap">
        <h2 class="section-title pk-h2 pk-related__title"><i class="fas fa-lightbulb" aria-hidden="true"></i><?= __("Potrebbero interessarti") ?></h2>
        <div class="related-books-wrap">
        <div class="related-books-grid">
            <?php foreach ($related_books as $related): ?>
                <?php
                // The shared card reads `autore`; related rows carry the full
                // author list in `autori`, else the principal author's name.
                $relatedAuthorDisplay = trim(html_entity_decode((string) ($related['autori'] ?? ''), ENT_QUOTES, 'UTF-8'));
                if ($relatedAuthorDisplay === '') {
                    $relatedAuthorDisplay = trim(html_entity_decode((string) ($related['autore_principale'] ?? $related['autore_principale_nome'] ?? ''), ENT_QUOTES, 'UTF-8'));
                }
                $book = $related + ['autore' => $relatedAuthorDisplay];
                $book['copertina_url'] = ($related['copertina_url'] ?? '') ?: ($related['immagine_copertina'] ?? '');
                $pkCardClass = 'related-book-card';
                $pkCardMeta = false;
                ?>
                <div class="related-book-cell"><?php include __DIR__ . '/partials/pk-book-card.php'; ?></div>
            <?php endforeach; ?>
            <?php unset($pkCardClass, $pkCardMeta); $book = $pkMainBook; ?>
        </div><!-- /.related-books-grid -->
        </div><!-- /.related-books-wrap -->
    </div>
</section>
<script>
// Accessibility: the grid clips every row after the first with overflow:hidden
// + grid-auto-rows:0, but clipped cards stay in the DOM. Take them out of the
// tab order and the accessibility tree so keyboard/screen-reader users can't
// reach cards they cannot see. A clipped cell sits below the first row, so its
// offsetTop is greater than the first row's. Recomputed on resize. `inert` does
// not change layout, so reading offsetTop stays accurate without oscillation.
(function () {
  var grid = document.querySelector('.related-books-grid');
  if (!grid) { return; }
  var cells = Array.prototype.slice.call(grid.querySelectorAll('.related-book-cell'));
  if (!cells.length) { return; }
  function sync() {
    // Scroll mode: the @media(max-width:1023.98px) rule turns the grid into a
    // horizontal snap-scroll strip (overflow-x:auto), where every card is
    // reachable by swiping — nothing is clipped. Clear any inert/aria-hidden/
    // tabindex the desktop path may have set and bail out. The desktop clip mode
    // uses overflow:hidden (overflow-x:hidden), so overflowX==='auto' unambiguously
    // means scroll mode.
    if (window.getComputedStyle(grid).overflowX === 'auto') {
      cells.forEach(function (c) {
        c.removeAttribute('inert');
        c.removeAttribute('aria-hidden');
        Array.prototype.forEach.call(c.querySelectorAll('a:not(.pk-card__link)'), function (a) {
          a.removeAttribute('tabindex');
        });
      });
      return;
    }
    var firstTop = Math.min.apply(null, cells.map(function (c) { return c.offsetTop; }));
    cells.forEach(function (c) {
      var clipped = c.offsetTop > firstTop + 1;
      if (clipped) {
        c.setAttribute('inert', '');
        c.setAttribute('aria-hidden', 'true');
      } else {
        c.removeAttribute('inert');
        c.removeAttribute('aria-hidden');
      }
      // Fallback for browsers without `inert`: keep the links off the tab order.
      // The card's cover link is aria-hidden and always out of it (tabindex=-1).
      Array.prototype.forEach.call(c.querySelectorAll('a:not(.pk-card__link)'), function (a) {
        if (clipped) { a.setAttribute('tabindex', '-1'); }
        else { a.removeAttribute('tabindex'); }
      });
    });
  }
  sync();
  var t;
  window.addEventListener('resize', function () {
    clearTimeout(t);
    t = setTimeout(sync, 150);
  });
})();
</script>
<?php endif; ?>

<?php
$isLoggedJs = !empty($_SESSION['user'] ?? null);
$libroIdJs = (int)($book['id'] ?? 0);

// FAIR Signposting <link> elements for HTML discovery (complement to HTTP Link headers)
$headLinks = [
    [
        'rel'  => 'type',
        'href' => 'https://schema.org/' . \App\Support\MediaLabels::schemaOrgType($resolvedTipoMedia),
    ],
];
// Only add BIBFRAME describedby link when the plugin is active
if (!empty($bibframePluginActive)) {
    $bibframeBookPath = str_replace('{id}', (string) (int) $book['id'], \App\Support\RouteTranslator::route('bibframe.book'));
    array_unshift($headLinks, [
        'rel'  => 'describedby',
        'type' => 'application/ld+json',
        'href' => absoluteUrl($bibframeBookPath),
    ]);
}

// Prepare SEO variables for layout
$seoTitle = $metaTitle;
$seoDescription = $metaDescription;
$seoImage = $ogImage;
$seoCanonical = $canonicalUrl;
$seoSchema = json_encode([$bookSchema, $breadcrumbSchema, $organizationSchema], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

// Open Graph — explicit overrides for book detail
$ogTitle = $bookTitle . ($bookAuthor ? ' — ' . $bookAuthor : '');
$ogDescription = $metaDescription;
$ogUrl = absoluteUrl(book_url($book));
$ogType = 'book';

// Book-specific OG meta (rendered by layout.php)
$ogBookMeta = [];
if ($bookISBN) {
    $ogBookMeta[] = ['property' => 'book:isbn', 'content' => $bookISBN];
}
if ($bookAuthor) {
    $ogBookMeta[] = ['property' => 'book:author', 'content' => $bookAuthor];
}
if ($bookYear) {
    $ogBookMeta[] = ['property' => 'book:release_date', 'content' => $bookYear];
}

$content = ob_get_clean();
include 'layout.php';
?>
<?php
$jsTranslationKeys = [
    'Aggiungi ai Preferiti',
    'Rimuovi dai Preferiti',
    'Accesso Richiesto',
    'Per richiedere un prestito devi effettuare il login.',
    'Per richiedere un prestito devi effettuare il login. Vuoi andare alla pagina di login?',
    'Vai al Login',
    'Annulla',
    'Richiesta Prestito',
    'Quando vuoi iniziare il prestito?',
    'Fino a quando? (opzionale):',
    'Lascia vuoto per 1 mese',
    'Le date rosse o arancioni non sono disponibili. La richiesta verrà valutata da un amministratore.',
    'Seleziona una data di inizio',
    'Richiesta Inviata!',
    'Invia Richiesta',
    'Errore',
    'Impossibile creare la prenotazione',
    'Inserisci la data di inizio (YYYY-MM-DD)',
    'Prenotazione effettuata per ',
    'Errore: ',
    'Errore nella prenotazione',
    'Tutte le copie in prestito',
    'Tutte le copie prenotate',
    'Copie disponibili'
];
$jsTranslations = [];
foreach ($jsTranslationKeys as $key) {
    $jsTranslations[$key] = __($key);
}
?>
<script>
(function() {
  const newTranslations = <?= json_encode($jsTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
  window.APP_TRANSLATIONS = Object.assign(window.APP_TRANSLATIONS || {}, newTranslations);
  window.__ = function(key) {
    const dict = window.APP_TRANSLATIONS || newTranslations;
    return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
  };
})();
</script>
<?php if ($isLoggedJs): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const favBtn = document.getElementById('btn-fav');
  if (!favBtn) return;
  const libroId = <?php echo (int)$libroIdJs; ?>;
  const meta = document.querySelector('meta[name="csrf-token"]');
  const csrf = meta ? meta.getAttribute('content') : '';

  function setFavUI(isFav) {
    const span = favBtn.querySelector('span');
    const icon = favBtn.querySelector('i');
    if (isFav) {
      favBtn.classList.remove('btn-outline-secondary');
      favBtn.classList.add('btn-danger');
      span.textContent = __('Rimuovi dai Preferiti');
    } else {
      favBtn.classList.add('btn-outline-secondary');
      favBtn.classList.remove('btn-danger');
      span.textContent = __('Aggiungi ai Preferiti');
    }
  }

  fetch(`${window.BASE_PATH}/api/user/wishlist/status?libro_id=${libroId}`)
    .then(r => r.ok ? r.json() : {favorite:false})
    .then(data => setFavUI(!!data.favorite))
    .catch(() => setFavUI(false));

  favBtn.addEventListener('click', async function() {
    try {
      const res = await fetch(window.BASE_PATH + '/api/user/wishlist/toggle', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf, libro_id: String(libroId) })
      });
      if (!res.ok) throw new Error('bad');
      const data = await res.json();
      setFavUI(!!data.favorite);
    } catch (e) {
      window.SwalApp.error(undefined, <?= json_encode(__("Errore nell'aggiornare i preferiti."), JSON_HEX_TAG) ?>);
    }
  });
});
</script>
<?php endif; ?>

<!-- Loan/Reserve request handler (works for all users) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Loan/Reserve request enhancement - unified flow
  const requestBtn = document.getElementById('btn-request-loan');
  if (requestBtn) {
    const libroId = <?php echo (int)$libroIdJs; ?>;
    // Path localizzato (base path incluso): non prefissare con window.BASE_PATH.
    const API_BOOK_BASE = <?= json_encode($apiBookRoute, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    const isLogged = <?php echo $isLoggedJs ? 'true' : 'false'; ?>;
    const meta = document.querySelector('meta[name="csrf-token"]');
    const csrf = meta ? meta.getAttribute('content') : '';
    const successRangeTpl = <?php echo json_encode(__('Richiesta di prestito dal <strong>%1$s</strong> al <strong>%2$s</strong>'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const successFootnote = <?php echo json_encode(__('Riceverai una conferma via email appena la richiesta sarà approvata.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    // #384: the unified calendar creates a real waitlist reservation when no
    // physical copy is assignable now. Keep that outcome explicit instead of
    // describing it as a loan request or an already-scheduled loan.
    const reservationRangeTpl = <?php echo json_encode(__('Prenotazione dal <strong>%1$s</strong> al <strong>%2$s</strong>'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const reservationTitle = <?php echo json_encode(__('Prenotazione effettuata con successo'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const reservationFootnote = <?php echo json_encode(__('La prenotazione resterà in coda finché una copia non sarà effettivamente disponibile.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    // #301: con l'auto-approvazione attiva il server risponde auto_approved=true
    // e il prestito è GIÀ in attesa di ritiro — il copy "appena sarà approvata"
    // sarebbe falso e l'utente aspetterebbe un'approvazione già avvenuta.
    const approvedTitle = <?php echo json_encode(__('Prestito approvato!'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const approvedFootnote = <?php echo json_encode(__('La tua richiesta è stata approvata automaticamente: riceverai una email con le istruzioni per il ritiro.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    // #301/F009: a future-dated request is auto-approved into stato 'prenotato'
    // (scheduled, no pickup deadline yet) — the "pickup instructions" copy above
    // would be false, so the scheduled case gets its own title/footnote.
    const scheduledTitle = <?php echo json_encode(__('Prestito prenotato con successo'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const scheduledFootnote = <?php echo json_encode(__('Prestito prenotato: riceverai le istruzioni per il ritiro quando la data di inizio si avvicina.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    // F040: chi ha già una prenotazione attiva su questo libro vedrebbe un
    // calendario tutto verde (il picker esclude la sua stessa prenotazione) ma
    // la guardia anti-duplicato di createReservation rifiuterebbe ogni data.
    // Meglio spiegarlo subito invece di aprire un picker destinato a fallire.
    const alreadyReservedMsg = <?php echo json_encode(__('Hai già una prenotazione attiva per questo libro. Attendi che venga evasa prima di richiederne una nuova.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const appToday = <?php echo json_encode(\App\Support\DateHelper::today(), JSON_HEX_TAG); ?>;
    const defaultRequestLoanDays = <?= (int) $defaultRequestLoanDays ?>;

    function addDaysToIso(isoDate, days) {
      const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate || '');
      if (!match) return isoDate;
      const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
      date.setUTCDate(date.getUTCDate() + days);
      return date.toISOString().slice(0, 10);
    }

    async function updateReservationsBadge() {
      const badge = document.getElementById('nav-res-count');
      if (!badge) return;
      try {
        const r = await fetch(window.BASE_PATH + '/api/user/reservations/count');
        if (!r.ok) return;
        const data = await r.json();
        const c = parseInt(data.count || 0, 10);
        if (c > 0) { badge.textContent = String(c); badge.classList.remove('hidden'); }
        else { badge.classList.add('hidden'); }
      } catch(_) {}
    }

    requestBtn.addEventListener('click', async function(){
      // Check if user is logged in
      if (!isLogged) {
        const result = await window.SwalApp.confirm({
          title: __('Accesso Richiesto'),
          text:  __('Per richiedere un prestito devi effettuare il login. Vuoi andare alla pagina di login?'),
          icon:  'warning',
          confirmText: __('Vai al Login')
        });
        if (result.isConfirmed) {
          window.location.href = <?= json_encode($loginRoute, JSON_HEX_TAG) ?> + '?redirect=' + encodeURIComponent(window.location.pathname);
        }
        return;
      }

      // Loan dates follow the library's configured clock, including for users
      // whose browser is in a different timezone near the day boundary.
      let suggestedDate = appToday;

      if (window.Swal) {
        // Fetch availability data for the calendar
        let disabledDates = [];
        let availabilityByDate = {};

        let maxAvailableDate = null;
        try {
          const availRes = await fetch(`${API_BOOK_BASE}/${libroId}/availability`);
          if (availRes.ok) {
            const availData = await availRes.json();
            if (availData.success && availData.availability) {
              // F040: dead-end guard — a fully-green picker whose every date the
              // duplicate guard rejects. Explain and stop instead of opening it.
              if (availData.availability.has_active_reservation === true) {
                await Swal.fire({
                  icon: 'info',
                  title: __('Richiesta Prestito'),
                  text: alreadyReservedMsg,
                  confirmButtonText: __('OK')
                });
                return;
              }
              disabledDates = availData.availability.unavailable_dates || [];
              if (availData.availability.earliest_available) {
                const earliest = String(availData.availability.earliest_available);
                suggestedDate = /^\d{4}-\d{2}-\d{2}$/.test(earliest) ? earliest : appToday;
              }
              if (Array.isArray(availData.availability.days)) {
                availabilityByDate = availData.availability.days.reduce((acc, day) => {
                  if (day && day.date) {
                    acc[day.date] = day;
                  }
                  return acc;
                }, {});
                // Get the last date in the availability data to set maxDate
                if (availData.availability.days.length > 0) {
                  const lastDay = availData.availability.days[availData.availability.days.length - 1];
                  if (lastDay && lastDay.date) {
                    maxAvailableDate = lastDay.date;
                  }
                }
              }
            }
          }
        } catch(e) {
          console.error('Error fetching availability:', e);
        }

        const formatDateIT = (dateStr) => {
          if (!dateStr) { return ''; }
          const parts = dateStr.split('-');
          if (parts.length !== 3) { return dateStr; }
          const [year, month, day] = parts.map(Number);
          if (!year || !month || !day) { return dateStr; }
          const formatter = new Intl.DateTimeFormat('it-IT', {
            day: '2-digit', month: '2-digit', year: 'numeric'
          });
          return formatter.format(new Date(year, month - 1, day));
        };

        const tooltipTexts = {
          borrowed: __('Tutte le copie in prestito'),
          reserved: __('Tutte le copie prenotate'),
          free: __('Copie disponibili')
        };

        const infoText = __('Le date rosse o arancioni non sono disponibili. La richiesta verrà valutata da un amministratore.');

        let startPicker = null;
        let endPicker = null;

        const { value: formValues } = await Swal.fire({
          title: __('Richiesta Prestito'),
          html:
            `<div class="loan-request-form">`+
            `<div class="loan-request-field">`+
            `<label class="loan-request-label" data-for-picker="start">${__('Quando vuoi iniziare il prestito?')}</label>`+
            `<input id="swal-date-start" type="text" class="loan-date-input" placeholder="<?= htmlspecialchars(__('Data inizio'), ENT_QUOTES, 'UTF-8') ?>">`+
            `</div>`+
            `<div class="loan-request-field">`+
            `<label class="loan-request-label" data-for-picker="end">${__('Fino a quando? (opzionale):')}</label>`+
            `<input id="swal-date-end" type="text" class="loan-date-input" placeholder="<?= htmlspecialchars(__('Durata predefinita prestito') . ': ' . __('%d giorni', (int) $defaultRequestLoanDays), ENT_QUOTES, 'UTF-8') ?>">`+
            `</div>`+
            `<p class="loan-request-note">`+
            `<i class="fas fa-info-circle mr-1"></i>`+
            `${infoText}`+
            `</p>`+
            `</div>`,
          focusConfirm: false,
          heightAuto: false,
          scrollbarPadding: false,
          showCancelButton: true,
          confirmButtonText: __('Invia Richiesta'),
          cancelButtonText: __('Annulla'),
          customClass: {
            popup: 'loan-request-popup',
            htmlContainer: 'loan-request-content',
            actions: 'loan-request-actions'
          },
          didOpen: () => {
            const startEl = document.getElementById('swal-date-start');
            const endEl = document.getElementById('swal-date-end');

            const pageLang = '<?= strtolower(str_replace('_', '-', \App\Support\I18n::getLocale())) ?>';
            const fpLocale = (window.flatpickr && window.flatpickr.l10ns)
              ? (window.flatpickr.l10ns[pageLang] || window.flatpickr.l10ns[pageLang.split('-')[0]] || null)
              : null;
            const forceEn = pageLang.startsWith('en');

            const baseOpts = {
              dateFormat: 'Y-m-d',
              // Force flatpickr's own calendar on mobile too: the native
              // Android/iOS date picker ignores `disable`, so it would let the
              // user pick fully-booked days and never show availability.
              disableMobile: true,
              altInput: true,
              altFormat: forceEn ? 'm-d-Y' : 'd-m-Y',
              minDate: appToday,
              maxDate: maxAvailableDate || undefined,
              defaultDate: suggestedDate,
              locale: forceEn ? 'en' : (fpLocale || 'default'),
              disable: disabledDates,
              showMonths: 1,
              // Keep the calendar inside the modal's document flow. This
              // prevents it from covering the next field and makes the popup,
              // not the page underneath, the only scrollable surface.
              static: true,
              onDayCreate: function(dObj, dStr, fp, dayElem) {
                if (!dayElem || !dayElem.dateObj) return;
                if (dayElem.classList.contains('prevMonthDay') || dayElem.classList.contains('nextMonthDay')) return;
                const isoDate = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                const info = availabilityByDate[isoDate];

                if (info) {
                  dayElem.classList.add(`${info.state}-day`);
                  if (info.state !== 'free') {
                    dayElem.classList.add('flatpickr-disabled');
                    dayElem.setAttribute('aria-disabled', 'true');
                    dayElem.tabIndex = -1;
                  }
                  if (tooltipTexts[info.state]) {
                    dayElem.setAttribute('title', tooltipTexts[info.state]);
                  }
                  // Inline fallback to enforce colors over theme collisions
                  if (info.state === 'borrowed') {
                    dayElem.style.backgroundColor = '#fef2f2';
                    dayElem.style.borderColor = '#fecaca';
                    dayElem.style.color = '#b91c1c';
                  } else if (info.state === 'reserved') {
                    dayElem.style.backgroundColor = '#f59e0b';
                    dayElem.style.borderColor = '#d97706';
                    dayElem.style.color = '#ffffff';
                  } else if (info.state === 'free') {
                    dayElem.style.backgroundColor = '#f0fdf4';
                    dayElem.style.borderColor = '#bbf7d0';
                    dayElem.style.color = '#166534';
                  }
                } else if (Object.keys(availabilityByDate).length > 0) {
                  dayElem.classList.add('available-day');
                  if (tooltipTexts.free) {
                    dayElem.setAttribute('title', tooltipTexts.free);
                  }
                }
              }
            };

            if (window.flatpickr) {
              startPicker = window.flatpickr(startEl, {
                ...baseOpts,
                onChange: function(selectedDates, dateStr, instance) {
                  if (selectedDates.length > 0 && dateStr) {
                    const endDate = addDaysToIso(dateStr, defaultRequestLoanDays);
                    if (endPicker) {
                      endPicker.setDate(endDate, true);
                      endPicker.set('minDate', dateStr);
                    }
                  }
                }
              });

              endPicker = window.flatpickr(endEl, {
                ...baseOpts,
                minDate: suggestedDate,
                maxDate: undefined // End date can extend beyond availability range
              });

              if (startPicker.altInput) {
                startPicker.altInput.id = 'swal-date-start-display';
                document.querySelector('[data-for-picker="start"]')?.setAttribute('for', startPicker.altInput.id);
              }
              if (endPicker.altInput) {
                endPicker.altInput.id = 'swal-date-end-display';
                document.querySelector('[data-for-picker="end"]')?.setAttribute('for', endPicker.altInput.id);
              }
            }
          },
          willClose: () => {
            startPicker?.destroy();
            endPicker?.destroy();
            startPicker = null;
            endPicker = null;
          },
          preConfirm: () => {
            const startDate = (document.getElementById('swal-date-start').value || '').trim();
            const endDate = (document.getElementById('swal-date-end').value || '').trim();

            if (!startDate) {
              Swal.showValidationMessage(__('Seleziona una data di inizio'));
              return false;
            }
            return { startDate, endDate };
          }
        });

        if (formValues && formValues.startDate) {
          try {
            const reqBody = {
              start_date: formValues.startDate,
              csrf_token: csrf
            };
            if (formValues.endDate) {
              reqBody.end_date = formValues.endDate;
            }

            const res = await fetch(`${API_BOOK_BASE}/${libroId}/reservation`, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
              },
              body: JSON.stringify(reqBody)
            });

            const result = await res.json();

            if (res.ok && result.success) {
              await updateReservationsBadge();
              const effectiveEndDate = formValues.endDate || addDaysToIso(formValues.startDate, defaultRequestLoanDays);
              const isWaitlistReservation = result.status === 'reserved';
              const rangeTemplate = isWaitlistReservation ? reservationRangeTpl : successRangeTpl;
              const successHtml = rangeTemplate
                .replace('%1$s', formatDateIT(formValues.startDate))
                .replace('%2$s', formatDateIT(effectiveEndDate));

              const isAutoApproved = result.auto_approved === true;
              // A scheduled ('prenotato') loan is not awaiting pickup yet: branch
              // the footnote so it doesn't promise imminent pickup instructions.
              const isScheduled = result.loan_state === 'prenotato';
              const approvedFootnoteText = isScheduled ? scheduledFootnote : approvedFootnote;
              Swal.fire({
                icon: 'success',
                title: isWaitlistReservation
                  ? reservationTitle
                  : (isAutoApproved ? (isScheduled ? scheduledTitle : approvedTitle) : __('Richiesta Inviata!')),
                html: `${successHtml}<br><small>${isWaitlistReservation ? reservationFootnote : (isAutoApproved ? approvedFootnoteText : successFootnote)}</small>`
              });
              return;
            } else {
              Swal.fire({
                icon: 'error',
                title: __('Errore'),
                text: result.message || __('Impossibile creare la prenotazione')
              });
            }
          } catch(e) {
            console.error('Reservation error:', e);
            Swal.fire({ icon:'error', title: __('Errore'), text: __('Impossibile creare la prenotazione') });
          }
        }
      } else {
        // Fallback for browsers without SweetAlert
        const date = prompt(__('Inserisci la data di inizio (YYYY-MM-DD)'), suggestedDate);
        if (date) {
          try {
            const res = await fetch(`${API_BOOK_BASE}/${libroId}/reservation`, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
              },
              body: JSON.stringify({ start_date: date, csrf_token: csrf })
            });

            const result = await res.json();
            if (res.ok && result.success) {
              await updateReservationsBadge();
              window.SwalApp.success(undefined, <?= json_encode(__("Prenotazione effettuata per "), JSON_HEX_TAG) ?> + date);
            } else {
              window.SwalApp.error(undefined, (result.message || <?= json_encode(__("Impossibile creare la prenotazione"), JSON_HEX_TAG) ?>));
            }
          } catch(_) {
            window.SwalApp.error(undefined, <?= json_encode(__("Errore nella prenotazione"), JSON_HEX_TAG) ?>);
          }
        }
      }
    });
  }
});
</script>
