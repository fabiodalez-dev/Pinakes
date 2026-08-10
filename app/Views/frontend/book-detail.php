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
 */

// Check if catalogue-only mode is enabled (hides loans, reservations, wishlist)
$isCatalogueMode = ConfigStore::isCatalogueMode();

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
$isAvailable = ($book['copie_disponibili'] ?? 0) > 0;
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
$legacyCatalogRoute = route_path('catalog_legacy');
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

// Meta title ottimizzato (max 60 caratteri)
$title = $bookTitle;
if ($bookAuthor) {
    $title .= " " . __("di") . " " . $bookAuthor;
}
$title .= " - " . __("Biblioteca");
$metaTitle = $title;

// Meta description ottimizzata (max 160 caratteri)
$metaDescription = '';
if ($bookDescription) {
    $metaDescription = substr(strip_tags($bookDescription), 0, 140);
    if (strlen($bookDescription) > 140) {
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

// Canonical URL - Safe from Host header injection
$canonicalUrl = HtmlHelper::getCurrentUrl();

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
        "availability" => $isAvailable ? "https://schema.org/InStock" : "https://schema.org/OutOfStock",
        "price" => $bookPrice,
        "priceCurrency" => (string) ConfigStore::get('app.currency', 'EUR'),
        "seller" => [
            "@type" => "Library",
            "name" => $appName
        ]
    ];
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

// Organization Schema
$organizationSchema = [
    "@context" => "https://schema.org",
    "@type" => "Library",
    "name" => __("Biblioteca"),
    "url" => rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . '/',
    "description" => __("Biblioteca digitale con catalogo completo di libri disponibili per il prestito")
];
$additional_css = "
    .book-hero {
        position: relative;
        color: #1a1a1a;
        padding: 4rem 0 4rem;
        min-height: 600px;
        height: auto;
        display: flex;
        align-items: center;
        margin-top: 0;
        overflow: hidden;
    }

    .book-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: url('" . htmlspecialchars($bookCover, ENT_QUOTES, 'UTF-8') . "');
        background-size: cover;
        filter: blur(8px);
        opacity: 0.9;
        z-index: 0;
    }

    .book-hero::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(rgba(255,255,255,0.85), rgba(255,255,255,0.85));
        z-index: 1;
    }

    .book-hero-content {
        position: relative;
        z-index: 10;
        width: 100%;
        margin: 0 auto;
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
        align-items: center;
        gap: clamp(1.5rem, 4vw, 4rem);
    }

    #book-cover-container,
    .book-info-column {
        width: 100%;
        min-width: 0;
    }

    #book-cover-container {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    @media (max-width: 767px) {
        .book-hero-content {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    .book-publisher {
        font-size: 1.1rem;
        opacity: 0.9;
        margin-bottom: 1rem;
        font-weight: 500;
    }

    .genre-tag {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: #1a1a1a;
    }

    .genre-tag:hover {
        background: rgba(255, 255, 255, 0.3);
        color: #1a1a1a;
    }

    .book-cover-large {
        max-width: clamp(200px, 40vw, 350px);
        width: 100%;
        border-radius: 3px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    .book-cover-large:hover {
        transform: translateY(-4px);
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
    }

    /* Margine verticale copertina su desktop */
    .book-hero-content img {
        margin: 38px 0;
    }

    /* Layout senza tab - sezioni info */
    .book-description-section {
        background: transparent;
        padding: 3rem 0;
        border-radius: 0;
        margin-bottom: 0;
        box-shadow: none;
        border: none;
        position: relative;
        z-index: 100;
    }

    #book-title {
        font-family: var(--serif);
        font-weight: 460;
        letter-spacing: -0.02em;
        line-height: 1.15;
    }

    .book-details-section {
        background: transparent;
        padding: 3rem 0;
        border-radius: 0;
        margin-bottom: 0;
        box-shadow: none;
        border: none;
        border-top: 1px solid var(--border-color);
        position: relative;
        z-index: 90;
    }

    .keyword-chip {
        font-size: 0.85rem;
        transition: all 0.2s;
    }
    .keyword-chip:hover {
        background-color: #e9ecef !important;
    }
    .keyword-chip:focus-visible {
        background-color: #e9ecef !important;
        outline: 2px solid #495057;
        outline-offset: 2px;
    }

    .book-reviews-section {
        background: transparent;
        padding: 3rem 0;
        border-radius: 0;
        margin-bottom: 0;
        box-shadow: none;
        border: none;
        border-top: 1px solid var(--border-color);
        position: relative;
        z-index: 80;
    }

    .review-summary-column {
        flex: 0 0 100%;
        max-width: 100%;
    }

    .average-rating .display-4 {
        font-family: var(--serif);
        font-weight: 460;
        letter-spacing: -0.02em;
    }

    .review-distribution-column {
        flex: 0 0 100%;
        max-width: 100%;
    }

    @media (min-width: 768px) {
        .review-summary-column {
            flex: 0 0 20%;
            max-width: 20%;
        }

        .review-distribution-column {
            flex: 0 0 80%;
            max-width: 80%;
        }
    }

    .rating-bars .stars-label {
        width: 140px;
        min-width: 140px;
        white-space: nowrap;
    }

    .section-title {
        font-family: var(--serif);
        font-size: 1.85rem;
        font-weight: 460;
        letter-spacing: -0.02em;
        color: var(--primary-color);
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .section-title i {
        font-size: 1.25rem;
    }

    .details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
    }

    .book-title-hero {
        font-family: var(--serif);
        font-size: clamp(1.75rem, 4vw, 2.5rem);
        font-weight: 460;
        letter-spacing: -0.03em;
        line-height: 1.15;
        margin-bottom: 1.5rem;
        text-shadow: none;
        color: #1a1a1a;
    }

    .book-publisher {
        font-size: clamp(0.9rem, 2vw, 1.1rem);
        opacity: 0.9;
        margin-bottom: 1rem;
        font-weight: 500;
        color: #1a1a1a;
    }

    .genre-tags {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .genre-separator {
        display: inline-flex;
        align-items: center;
        color: rgba(26, 26, 26, 0.45);
        font-weight: 600;
        line-height: 1;
    }

    .genre-tag {
        display: inline-flex;
        align-items: center;
        padding: 0.4rem 1rem;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 2px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #1a1a1a;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .genre-tag:hover {
        background: rgba(255, 255, 255, 0.25);
        transform: translateY(-2px);
        box-shadow: none;
        color: #1a1a1a;
        text-decoration: none;
    }

    .breadcrumb-item a {
        color: rgba(45, 55, 72, 0.7);
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .breadcrumb-item a:hover {
        color: #2d3748;
    }

    .breadcrumb-item.active {
        color: #2d3748;
    }

    .availability-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        border-radius: 2px;
        font-weight: 700;
        font-size: 0.95rem;
        margin-bottom: 2rem;
        border: 2px solid transparent;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.12em;
    }

    .available {
        background: rgba(16, 185, 129, 0.9);
        color: #1a1a1a;
        border-color: rgba(16, 185, 129, 0.5);
        box-shadow: none;
    }

    .available:hover {
        background: rgba(16, 185, 129, 1);
        transform: translateY(-2px);
        box-shadow: none;
    }

    .unavailable {
        background: rgba(239, 68, 68, 0.9);
        color: #1a1a1a;
        border-color: rgba(239, 68, 68, 0.5);
        box-shadow: none;
    }

    .unavailable:hover {
        background: rgba(239, 68, 68, 1);
        transform: translateY(-2px);
        box-shadow: none;
    }

    .book-meta {
        background: transparent;
        padding: 3rem 0;
        border-radius: 0;
        margin-top: -4rem;
        position: relative;
        z-index: 200;
        box-shadow: none;
        border: none;
        border-top: 1px solid var(--border-color);
    }

    .card {
        position: relative;
        z-index: 200;
    }

    #book-info-card {
        position: relative;
        z-index: 20;
    }

    .meta-item {
        padding: 1.25rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .meta-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .meta-label {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.14em;
    }

    .meta-value {
        color: var(--text-light);
        font-size: 1.05rem;
        font-weight: 300;
    }

    .authors-list {
        margin-bottom: 2rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .author-item {
        display: inline-flex;
        align-items: center;
        background: rgba(59, 130, 246, 0.1);
        padding: 0.6rem 1.25rem;
        border-radius: 2px;
        font-size: 0.95rem;
        font-weight: 600;
        transition: all 0.3s ease;
        text-decoration: none;
        color: #2d3748;
        border: 2px solid transparent;
    }

    .author-item:hover {
        transform: translateY(-3px);
        box-shadow: none;
        text-decoration: none;
        color: #1a1a1a;
    }

    .role-principale {
        background: var(--primary-color);
        color: #fff;
        border-color: var(--primary-color);
    }

    .role-principale:hover {
        color: #fff;
        box-shadow: none;
    }

    .role-co-autore {
        background: #f97316;
        color: #fff;
        border-color: #f97316;
    }

    .role-co-autore:hover {
        color: #fff;
        box-shadow: none;
    }

    .role-traduttore {
        background: #8b5cf6;
        color: #fff;
        border-color: #8b5cf6;
    }

    .role-traduttore:hover {
        color: #fff;
        box-shadow: none;
    }

    .description-content {
        line-height: 1.8;
        color: var(--text-color);
        font-size: 1.1rem;
        font-weight: 400;
    }

    .description-content ul,
    .description-content ol {
        margin: 1em 0;
        padding-left: 2em;
    }

    .description-content ul {
        list-style-type: disc;
    }

    .description-content ol {
        list-style-type: decimal;
    }

    .description-content li {
        margin: 0.5em 0;
    }

    .description-content strong,
    .description-content b {
        font-weight: 700;
    }

    .description-content em,
    .description-content i {
        font-style: italic;
    }

    .description-content a {
        color: var(--primary-color, #3b82f6);
        text-decoration: underline;
    }

    .description-content a:hover {
        color: var(--primary-hover, #2563eb);
    }

    .tab-content {
        padding: 2.5rem 0;
    }

    .nav-tabs {
        border-bottom: 1px solid var(--border-color);
        margin-bottom: 2rem;
    }

    .nav-tabs .nav-link {
        color: var(--text-light);
        border: none;
        border-bottom: 3px solid transparent;
        font-weight: 600;
        font-size: 1rem;
        padding: 1rem 1.5rem;
        transition: all 0.3s ease;
        background: transparent;
        letter-spacing: -0.01em;
    }

    .nav-tabs .nav-link:hover {
        color: var(--primary-color);
        border-bottom-color: rgba(0,0,0,0.2);
        background: var(--light-bg);
        border-radius: 2px 2px 0 0;
    }

    .nav-tabs .nav-link.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
        background: var(--light-bg);
        font-weight: 700;
        border-radius: 2px 2px 0 0;
    }

    .nav-tabs .nav-link i {
        margin-right: 0.5rem;
        font-size: 1rem;
    }

    .related-books .book-card {
        margin-bottom: 2rem;
        transition: all 0.3s ease;
        border-radius: 2px;
        overflow: hidden;
        box-shadow: none;
    }

    .related-books .book-card:hover {
        transform: translateY(-5px);
        box-shadow: none;
    }

    .action-buttons {
        display: flex;
        justify-content: center;
        gap: 1rem;
        flex-wrap: wrap;
        margin: 0 0 3rem 0;
        position: relative;
        z-index: 50;
        padding: 0;
    }

    .action-buttons .ui-button {
        padding: 1rem 2.5rem;
        font-weight: 700;
        border-radius: 2px;
        font-size: 1rem;
        transition: all 0.3s ease;
        border-width: 2px;
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        letter-spacing: -0.01em;
        text-decoration: none;
        min-width: 200px;
        justify-content: center;
        position: relative;
        z-index: 1001;
    }

    .action-buttons .ui-button:hover {
        transform: translateY(-3px);
        box-shadow: none;
        text-decoration: none;
    }

    .action-buttons .btn-primary {
        background: var(--secondary-color);
        border-color: var(--secondary-color);
        color: #ffffff;
    }

    .action-buttons .btn-primary:hover {
        background: var(--secondary-hover);
        border-color: var(--secondary-hover);
        color: #ffffff;
        box-shadow: none;
    }

    .action-buttons .btn-outline-primary {
        color: var(--secondary-color);
        border-color: var(--secondary-color);
        background: transparent;
    }

    .action-buttons .btn-outline-primary:hover {
        background: var(--secondary-color);
        border-color: var(--secondary-color);
        color: #ffffff;
    }

    .action-buttons .btn-outline {
        color: var(--text-light);
        border-color: var(--border-color);
        background: transparent;
    }

    .action-buttons .btn-outline:hover {
        background: var(--text-light);
        border-color: var(--text-light);
        color: #1a1a1a;
    }

    .swal2-popup {
        width: min(720px, 95vw) !important;
        padding: 2.5rem 2.75rem 2.25rem !important;
        border-radius: 2px !important;
        background: var(--white) !important;
        color: var(--text-color) !important;
        border: 1px solid rgba(17, 24, 39, 0.15) !important;
        box-shadow: none !important;
    }

    .swal2-popup .swal2-title,
    .swal2-popup .swal2-html-container,
    .swal2-popup label,
    .swal2-popup .text-muted {
        color: var(--text-color) !important;
    }

    .swal2-popup .swal2-actions .swal2-styled {
        border-radius: 2px;
        padding: 0.75rem 1.75rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        transition: transform 0.2s ease, background 0.2s ease;
        box-shadow: none !important;
    }

    .swal2-popup .swal2-styled.swal2-confirm {
        background: var(--secondary-color) !important;
        border: 1px solid var(--secondary-color) !important;
        color: #ffffff !important;
    }

    .swal2-popup .swal2-styled.swal2-confirm:hover,
    .swal2-popup .swal2-styled.swal2-confirm:focus {
        background: var(--secondary-hover) !important;
        border-color: var(--secondary-hover) !important;
        color: #ffffff !important;
    }

    .swal2-popup .swal2-styled.swal2-cancel {
        background: transparent !important;
        color: var(--secondary-color) !important;
        border: 1px solid var(--secondary-color) !important;
        opacity: 0.6;
    }

    .swal2-popup .swal2-styled.swal2-cancel:hover,
    .swal2-popup .swal2-styled.swal2-cancel:focus {
        background: rgba(17, 24, 39, 0.08) !important;
        border-color: rgba(17, 24, 39, 0.4) !important;
        color: var(--text-color) !important;
    }

    .action-buttons .btn-danger {
        background: var(--danger-color);
        border-color: var(--danger-color);
        color: #1a1a1a;
    }

    .action-buttons .btn-danger:hover {
        background: #dc2626;
        border-color: #dc2626;
        color: #1a1a1a;
        box-shadow: none;
    }

    /* Elegant Cards */
    .card {
        background: transparent;
        border: none;
        border-top: 1px solid var(--border-color);
        box-shadow: none;
        transition: all 0.3s ease;
        border-radius: 0;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: none;
        transform: none;
    }

    .card-header {
        background: transparent;
        /* Explicit `none` (not just omitted) so it beats the global .card-header
           border in main.css. The header is just a small section label above the
           meta rows, not a boxed card head, so the hairline read as clutter.
           Inset the label with PADDING (not margin) so it always lines up with
           the .card-body content (1.5rem): main.css forces
           `.card-header { margin: 0 !important }`, so a margin-based inset only
           held while the inline styles happened to win the cascade. Padding is
           not overridden, so the label stays aligned regardless of load order. */
        border-bottom: none;
        margin: 0;
        padding: 0.5rem 1.5rem;
    }

    .card-header h6 {
        color: var(--primary-color);
        font-weight: 600;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.14em;
        line-height: 1.3;
        margin: 0;
    }

    .card-body {
        padding: 1.5rem;
    }

    /* Keep the share partial's horizontal padding aligned with this section;
       ID+class beats the utility so the social buttons sit at the same
       1.5rem inset as the header line and the meta rows above. */
    #book-share-card .card-body.px-3 {
        padding-left: 1.5rem !important;
        padding-right: 1.5rem !important;
    }

    .status-badge {
        padding: 0.5rem 0.875rem;
        border-radius: 2px;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.12em;
    }

    .bg-success {
        background: var(--success-color) !important;
    }

    .bg-danger {
        background: var(--danger-color) !important;
    }

    /* Responsive Improvements */
    @media (max-width: 1200px) {
        .book-hero {
            padding: 4rem 0 3rem;
        }
    }

    @media (max-width: 992px) {
        .book-hero {
            padding: 3rem 0 2.5rem;
            min-height: auto;
            text-align: center;
        }

        .book-cover-large {
            max-width: clamp(180px, 35vw, 280px);
            margin-bottom: 1.5rem;
        }

        .book-title-hero {
            font-size: clamp(1.8rem, 5vw, 2.5rem);
            margin-bottom: 1rem;
        }

        .authors-list {
            justify-content: center;
            margin-bottom: 1rem;
        }

        .availability-badge {
            display: inline-flex;
        }
    }

    @media (max-width: 768px) {
        .book-hero {
            padding: 3rem 0 3rem;
            text-align: center;
            min-height: 500px;
            height: auto;
            background-attachment: scroll;
        }

        section.py-5 {
            padding-top: 2rem !important;
            padding-bottom: 2rem !important;
            margin-top: 1.5rem !important;
        }

        .book-title-hero {
            font-size: clamp(1.5rem, 6vw, 2.2rem);
            margin-bottom: 1rem;
            word-break: break-word;
        }

        /* Breadcrumb responsive su mobile */
        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            text-align: center;
        }

        .breadcrumb-item {
            display: inline;
            word-break: break-word;
        }

        .breadcrumb-item + .breadcrumb-item::before {
            display: inline;
            padding-right: 0.25rem;
            padding-left: 0.25rem;
        }

        .breadcrumb-item.active {
            word-break: break-word;
            display: inline;
        }

        /* Copertina full-width su mobile */
        #book-cover-container {
            flex: 0 0 100%;
            max-width: 100%;
            padding-left: 0;
            padding-right: 0;
            margin-bottom: 2rem;
        }

        .book-cover-large {
            max-width: min(85vw, 500px);
            width: 100%;
            margin: 0 auto 1.5rem;
        }

        /* Rimuovi margine verticale su mobile */
        .book-hero-content img {
            margin: 0 auto 1.5rem;
        }

        /* Padding ridotto per sezioni su mobile */
        .book-description-section,
        .book-details-section,
        .book-reviews-section {
            /* Vertical padding only: horizontal spacing comes from the column
               container (`px-3`), so the info spans the same width as the title
               above instead of double-padding and looking narrow on mobile. */
            padding: 2rem 0;
        }

        /* Card contenuti full-bleed su mobile */
        .card {
            padding: 0;
        }

        .book-meta {
            margin-top: -1rem;
            padding: 1.5rem;
            border-radius: 0;
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
        }

        .action-buttons .ui-button {
            margin: 0;
            width: 100%;
            max-width: 300px;
            font-size: 0.9rem;
            padding: 0.8rem 1.5rem;
        }

        .genre-tags {
            justify-content: center;
            flex-wrap: wrap;
        }

        .genre-tag {
            font-size: 0.85rem;
            padding: 0.4rem 0.9rem;
        }

        .authors-list {
            justify-content: center;
        }

        .availability-badge {
            font-size: 0.85rem;
            padding: 0.6rem 1.2rem;
        }

        .meta-item {
            padding: 0.8rem 0;
        }

        .tab-content {
            padding: 1rem 0;
        }

        .nav-tabs {
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .nav-tabs .nav-link {
            white-space: nowrap;
            flex: 0 0 auto;
            font-size: 0.9rem;
            padding: 0.75rem 1rem;
        }

        /* Mobile review layout - stack elements vertically */
        .review-header {
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 0.75rem;
        }

        .review-user-info {
            width: 100%;
        }

        .review-stars {
            width: 100%;
            display: flex;
            gap: 0.25rem;
        }
    }

    @media (max-width: 576px) {
        .book-hero {
            padding: 3rem 0 2.5rem;
            min-height: 450px;
            height: auto;
            background-attachment: scroll;
        }

        section.py-5 {
            padding-top: 1.5rem !important;
            padding-bottom: 1.5rem !important;
            margin-top: 1rem !important;
        }

        .book-title-hero {
            font-size: clamp(1.3rem, 6vw, 1.8rem);
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        /* Copertina full-width su mobile piccolo */
        #book-cover-container {
            flex: 0 0 100%;
            max-width: 100%;
            padding-left: 0;
            padding-right: 0;
            margin-bottom: 1.5rem;
        }

        .book-cover-large {
            max-width: min(85vw, 450px);
            width: 100%;
            margin: 0 auto;
        }

        /* Rimuovi margine verticale su mobile */
        .book-hero-content img {
            margin: 0 auto;
        }

        /* Padding ridotto per sezioni su mobile */
        .book-description-section,
        .book-details-section,
        .book-reviews-section {
            /* Vertical padding only: horizontal spacing comes from the column
               container (`px-3`), so the info spans the same width as the title
               above instead of double-padding and looking narrow on mobile. */
            padding: 2rem 0;
        }

        .breadcrumb {
            font-size: 0.8rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
        }

        .breadcrumb-item {
            display: inline;
            word-break: break-word;
        }

        .breadcrumb-item + .breadcrumb-item::before {
            display: inline;
            padding-right: 0.25rem;
            padding-left: 0.25rem;
        }

        .author-item {
            display: inline-block;
            margin: 0.25rem 0.25rem 0.25rem 0;
            text-align: center;
            font-size: 0.85rem;
            padding: 0.5rem 1rem;
        }

        .authors-list {
            justify-content: center;
            gap: 0.5rem;
            flex-direction: column;
            align-items: center;
        }

        .book-meta {
            padding: 1rem;
            margin-top: -0.5rem;
        }

        .availability-badge {
            font-size: 0.8rem;
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
        }

        .genre-tags {
            gap: 0.4rem;
            justify-content: center;
        }

        .genre-tag {
            font-size: 0.8rem;
            padding: 0.3rem 0.7rem;
        }

        /* On phones the card sits in a full-width column whose content edge is
           already the page gutter, so any horizontal padding here pushes the
           card's rows further in than the title, the description and every other
           block on the page. Drop the horizontal padding (keep the vertical
           rhythm) so the header label, meta rows and social buttons line up flush
           with the rest of the sheet. */
        .card-body {
            padding: 1rem 0;
        }

        .card-header {
            padding-left: 0;
            padding-right: 0;
        }
    }

    @media (max-width: 400px) {
        .book-hero {
            padding: 3rem 0 2rem;
            min-height: 400px;
            height: auto;
            background-attachment: scroll;
        }

        section.py-5 {
            padding-top: 1rem !important;
            padding-bottom: 1rem !important;
            margin-top: 0.75rem !important;
        }

        .book-title-hero {
            font-size: clamp(1.2rem, 6vw, 1.5rem);
        }

        /* Copertina full-width su schermi molto piccoli */
        #book-cover-container {
            flex: 0 0 100%;
            max-width: 100%;
            padding-left: 0;
            padding-right: 0;
            margin-bottom: 1.5rem;
        }

        .book-cover-large {
            max-width: min(85vw, 400px);
            width: 100%;
            margin: 0 auto;
        }

        /* Rimuovi margine verticale su mobile */
        .book-hero-content img {
            margin: 0 auto;
        }

        /* Padding ridotto per sezioni su mobile */
        .book-description-section,
        .book-details-section,
        .book-reviews-section {
            /* Vertical padding only — see the note above; keeps the info full
               width (as wide as the title) on the smallest screens. */
            padding: 1.5rem 0;
        }

        .hero-text {
            padding: 0 0.5rem;
        }

        .authors-list {
            justify-content: center;
            flex-direction: column;
            align-items: center;
        }

        .availability-badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem;
        }
    }

    /* Related Books Section */
    /* Keep the (max 3) related cards grouped and centred instead of spread
       across the theme's ultra-wide container. ~3 cards of 280px + gutters.
       plain wrapper div so it stays independent from the surrounding grid. */
    .related-books-wrap {
        /* Widened from the old ~3-card (960px) cap so wide screens can show up
           to 6 related books in one row; the grid below decides how many
           actually fit at any given width. */
        max-width: 1320px;
        margin-left: auto;
        margin-right: auto;
    }

    /* Responsive related-books grid: one visible row, capped at 4 cards on
       wide/high-res screens and degrading to 3/2/1 as the viewport narrows —
       each column is at least a quarter of the row (minus the 3 inter-column
       gaps) OR 200px, whichever is larger, so auto-fill can never pack more
       than 4 columns even on a high-resolution laptop. grid-auto-rows:0 +
       overflow:hidden collapse every row after the first, so extra related
       books beyond the first 4 stay clipped rather than wrapping. */
    .related-books-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(max(200px, calc((100% - 3 * 1.5rem) / 4)), 1fr));
        /* row-gap:0 so the zero-height clipped rows don't add trailing
           whitespace below the single visible row (F002). Horizontal spacing
           between cards in the visible row is preserved via column-gap. */
        column-gap: 1.5rem;
        row-gap: 0;
        justify-content: center;
        grid-template-rows: auto;
        grid-auto-rows: 0;
        overflow: hidden;
    }

    .related-book-cell {
        min-width: 0;
    }

    /* Phones and small tablets: the desktop grid packs 2+ partial, cut-off cards.
       Switch to a horizontal snap-scroll strip that shows ONE whole book per view
       and snaps book-by-book, so all related books are reachable by swiping.
       Breakpoint raised to 767px so every phone (and small portrait tablet) gets
       the one-book carousel instead of clipped grid cards. */
    @media (max-width: 767px) {
        .related-books-grid {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
            /* CRITICAL: the base grid rule sets justify-content:center for the
               centred desktop grid. On an OVERFLOWING flex scroll strip that
               centres the cells, pushing the first one off-screen left so two
               half-cells show at scrollLeft:0. Reset to flex-start so the strip
               starts on the first whole card. */
            justify-content: flex-start;
            /* neutralize the desktop grid props so they don't interfere */
            grid-template-columns: none;
            grid-template-rows: none;
            grid-auto-rows: auto;
            column-gap: 1rem;
            row-gap: 0;
            /* hide the scrollbar aesthetically while keeping scrollability */
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .related-books-grid::-webkit-scrollbar {
            display: none;
        }
        .related-book-cell {
            /* One dominant whole book per view, with ~15% of the NEXT card
               peeking on the right as the scroll affordance (otherwise a
               full-width card gives no hint that the strip scrolls). start +
               scroll-snap-stop:always still land each swipe on exactly one card.
               The peek is one-sided (the base grid centering was reset to
               flex-start above), so it reads as more-to-the-right, not as two
               half-covers. */
            flex: 0 0 85%;
            scroll-snap-align: start;
            scroll-snap-stop: always;
        }
    }

    .related-book-card {
        background: none;
        border-radius: 0;
        overflow: visible;
        transition: transform 0.5s cubic-bezier(.22,1,.36,1);
        border: none;
        height: 100%;
        display: flex;
        flex-direction: column;
        /* Deliberate book-sane cap, centred in its column, so the cover (140%
           of the card width) never balloons on wide screens where each column
           is very wide. 280px is the catalog grid's minmax() floor; here it's a
           hard cap (the catalog stretches beyond it, this doesn't) — related
           cards read slightly narrower than catalog cards, which is fine. */
        max-width: 280px;
        margin-left: auto;
        margin-right: auto;
    }

    .related-book-card:hover {
        transform: translateY(-2px);
        box-shadow: none;
    }

    .related-book-image-container {
        position: relative;
        width: 100%;
        padding-top: 140%;
        overflow: hidden;
        background: var(--light-bg);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        transition: box-shadow 0.3s ease;
    }

    .related-book-card:hover .related-book-image-container {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
    }

    .related-book-image {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: contain;
        transition: transform 0.3s ease;
    }

    .related-book-card:hover .related-book-image {
        transform: scale(1.05);
    }

    .related-availability-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        min-width: 32px;
        height: 32px;
        padding: 0 9px;
        border-radius: 2px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        font-size: 1rem;
        line-height: 1;
        white-space: nowrap;
        background: var(--success-color); /* fallback for browsers without color-mix() */
        background: color-mix(in srgb, var(--success-color) 95%, transparent);
        color: white;
        box-shadow: none;
    }

    /* When a digital icon (eBook/audio) is injected next to the availability
       check, the pill grows to fit both — drop the icon's own left margin so
       the spacing is the flex gap alone (no overlap). */
    .related-availability-badge .digital-badge-icon {
        margin-left: 0;
    }

    .related-book-content {
        padding: 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .related-book-title {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        line-height: 1.4;
    }

    .related-book-title a {
        color: var(--text-color);
        text-decoration: none;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .related-book-title a:hover {
        color: #374151;
    }

    .related-book-author {
        font-size: 0.9rem;
        color: var(--text-light);
        margin-bottom: 1rem;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .related-book-actions {
        margin-top: auto;
    }

    .btn-related-view {
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        width: auto;
        padding: 0;
        background: none;
        color: var(--primary-color);
        text-decoration: none;
        border-radius: 0;
        font-weight: 600;
        font-size: 0.8rem;
        letter-spacing: 0.02em;
        gap: 0.4rem;
        transition: gap 0.3s cubic-bezier(.22,1,.36,1);
        border: none;
    }

    .btn-related-view:hover {
        background: none;
        transform: none;
        color: var(--primary-color);
        gap: 0.6rem;
    }

    /* No mobile-only max-width override: a wider cap on mobile (≤768px) than on
       desktop (280px) caused an inverted jump — widening the window past 768px
       *shrank* the card. The uniform 280px cap above keeps the transition
       monotonic across every breakpoint. */

    /* Favorites button custom styling */
    .btn-fav-custom {
        background-color: var(--white) !important;
        border: 1px solid var(--border-color) !important;
        color: var(--text-light) !important;
        transition: all 0.3s ease;
    }

    .btn-fav-custom:hover {
        background-color: #212529 !important;
        border-color: #212529 !important;
        color: #ffffff !important;
    }

    .btn-fav-custom:focus {
        background-color: #212529 !important;
        border-color: #212529 !important;
        color: #ffffff !important;
        box-shadow: 0 0 0 0.25rem rgba(33, 37, 41, 0.5);
    }
    .card {
    background-color: transparent;
    }
";

ob_start();
?>

<!-- Book Hero Section -->
<section class="book-hero">
    <div class="container">
        <div class="book-hero-content" id="book-hero-content">
            <div class="book-cover-column text-center" id="book-cover-container">
                <img src="<?= htmlspecialchars($bookCover, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($coverAlt, ENT_QUOTES, 'UTF-8') ?>"
                     class="book-cover-large img-fluid"
                     id="book-cover-image">
            </div>
            <div class="book-info-column">
                <div class="hero-text">
                    <?php
                    // Multi-publisher (issue #143): link every publisher, fallback to primary.
                    $heroPublishers = $book['editori'] ?? [];
                    if ($heroPublishers === [] && !empty($book['editore'])) {
                        $heroPublishers = [['nome' => $book['editore']]];
                    }
                    ?>
                    <nav class="book-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item">
                                <a href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= __("Home") ?></a>
                            </li>
                            <li class="breadcrumb-item">
                                <a href="<?= htmlspecialchars($legacyCatalogRoute, ENT_QUOTES, 'UTF-8') ?>"><?= __("Catalogo") ?></a>
                            </li>
                            <li class="breadcrumb-item active" aria-current="page">
                                <?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                            </li>
                        </ol>
                    </nav>

                    <div class="book-kicker">
                        <span class="book-media-type">
                            <i class="fas <?= htmlspecialchars(\App\Support\MediaLabels::icon($resolvedTipoMedia), ENT_QUOTES, 'UTF-8') ?> mr-1" aria-hidden="true"></i><?= \App\Support\MediaLabels::tipoMediaDisplayName($resolvedTipoMedia) ?>
                        </span>
                        <?php if ($heroPublishers !== []): ?>
                            <span class="book-kicker-separator" aria-hidden="true">·</span>
                            <span class="book-hero-publishers">
                                <?php foreach ($heroPublishers as $hpI => $hp):
                                    $hpName = html_entity_decode((string) ($hp['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    if ($hpName === '') { continue; }
                                ?><?= $hpI > 0 ? ', ' : '' ?><a href="<?= htmlspecialchars(route_path('publisher') . '/' . urlencode($hpName), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($hpName) ?></a><?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1 class="font-bold mb-3" id="book-title" style="font-size: clamp(1.5rem, 3.5vw, 2.25rem);">
                        <?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                    </h1>

                    <?php if (!empty($book['sottotitolo'])): ?>
                    <p class="book-subtitle-hero mb-3" id="book-subtitle">
                        <?= htmlspecialchars(html_entity_decode($book['sottotitolo'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                    <?php endif; ?>

                    <div class="authors-list" id="book-authors-list">
                        <?php foreach($authors as $author): ?>
                            <?php
                                // Pseudonym-aware display "Pseudonimo (Nome)"; the link still
                                // targets the real name (author page keys on nome). Issue #237.
                                $authorDisplay = \App\Support\AuthorName::display([
                                    'nome' => html_entity_decode($author['nome'] ?? '', ENT_QUOTES, 'UTF-8'),
                                    'pseudonimo' => html_entity_decode($author['pseudonimo'] ?? '', ENT_QUOTES, 'UTF-8'),
                                ]);
                            ?>
                            <a href="<?= htmlspecialchars(route_path('author') . '/' . urlencode(html_entity_decode($author['nome'] ?? '', ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8') ?>" class="no-underline">
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

                    <div class="mt-4">
                        <span class="availability-badge <?= ($book['copie_disponibili'] > 0) ? 'available' : 'unavailable' ?>">
                            <i class="fas fa-<?= ($book['copie_disponibili'] > 0) ? 'check-circle' : 'times-circle' ?> mr-2" aria-hidden="true"></i>
                            <?= ($book['copie_disponibili'] > 0)
                                ? ($book['copie_totali'] > 1
                                    ? "{$book['copie_disponibili']}/{$book['copie_totali']} " . __("Disponibili")
                                    : __("Disponibile"))
                                : __("Non disponibile oggi") /* lo snapshot è di OGGI: il calendario può mostrare giorni futuri liberi */ ?>
                        </span>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<!-- Book Details Section -->
<section class="py-5" style="margin-top: 3rem; position: relative; z-index: 50;">
    <div class="container">
        <div class="flex flex-wrap -mx-3">
            <!-- Main Content -->
            <div class="w-full lg:w-2/3 px-3">
                <!-- Action Buttons -->
                <?php if (!$isCatalogueMode): ?>
                <div class="action-buttons text-center mb-4" id="book-action-buttons">
                    <!-- Always show the calendar to choose dates -->
                    <button id="btn-request-loan" type="button" class="ui-button <?= ($book['copie_disponibili'] ?? 0) > 0 ? 'btn-primary' : 'btn-outline-primary' ?> px-8 py-4 text-base" data-libro-id="<?= (int)($book['id'] ?? 0) ?>">
                        <i class="fas fa-<?= ($book['copie_disponibili'] ?? 0) > 0 ? 'book-reader' : 'calendar-alt' ?> mr-2"></i>
                        <?= ($book['copie_disponibili'] ?? 0) > 0 ? __('Richiedi Prestito') : __('Prenota Quando Disponibile') ?>
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

                    <?php
                    // Hook: Allow plugins to add digital content buttons (e.g., Download eBook, Play Audio)
                    do_action('book.detail.digital_buttons', $book);
                    ?>
                </div>
                <?php endif; ?>

                <?php
                // Hook: Allow plugins to add digital content player (e.g., Green Audio Player)
                do_action('book.detail.digital_player', $book);
                ?>

                <!-- Alerts Section -->
                <div id="book-alerts">
                    <?php if (!empty($_GET['loan_request_success'])): ?>
                        <div class="alert alert-success relative pr-12 fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i><?= !empty($_GET['auto_approved'])
                              ? __("Prestito approvato. Il libro è in attesa di ritiro.")
                              : __("Prestito richiesto con successo.") ?>
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= __('Chiudi') ?>"></button>
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
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= __('Chiudi') ?>"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($_GET['reserve_success'])): ?>
                        <div class="alert alert-success relative pr-12 fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i><?= __("Prenotazione effettuata con successo") ?><?php if(!empty($_GET['reserve_date'])): ?> <?= __("per il giorno") ?> <strong><?= htmlspecialchars($_GET['reserve_date'], ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>.
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= __('Chiudi') ?>"></button>
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
                            <button type="button" class="alert-dismiss" data-dismiss-alert aria-label="<?= __('Chiudi') ?>"></button>
                        </div>
                    <?php endif; ?>
                </div>

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
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Genere") ?></div>
                                <div class="meta-value"><?php unset($genreLinkClass, $genreSeparator); include __DIR__ . '/partials/genre-breadcrumb.php'; ?></div>
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
                        <span class="status-badge bg-gray-900 rounded-full"><?= count($reviews) ?></span>
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
            <div class="w-full lg:w-1/3 px-3" id="book-sidebar">
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

                        <div class="meta-item">
                            <div class="meta-label"><?= __("Stato") ?></div>
                            <div class="meta-value">
                                <span class="book-status-inline <?= ($book['copie_disponibili'] > 0) ? 'is-available' : 'is-unavailable' ?>">
                                    <?= ($book['copie_disponibili'] > 0) ? __("Disponibile") : __("Non disponibile oggi") ?>
                                </span>
                            </div>
                        </div>

                        <div class="meta-item">
                            <div class="meta-label"><?= __("Copie Disponibili") ?></div>
                            <div class="meta-value"><?= $book['copie_disponibili'] ?> / <?= $book['copie_totali'] ?></div>
                        </div>

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
            </div>
        </div>
    </div>
</section>

<!-- Series Section (other volumes in the same collana) -->
<?php if (!empty($seriesBooks)): ?>
<section class="py-3" style="margin-top: 1.5rem;">
    <div class="container">
        <h3 class="text-center mb-3" style="font-weight: 600; font-size: 1.05rem;">
            <i class="fas fa-layer-group" style="color: var(--primary-color);"></i>
            <?= __("Nella stessa collana") ?>: <em><?= htmlspecialchars($collana, ENT_QUOTES, 'UTF-8') ?></em>
        </h3>
        <div class="flex flex-wrap justify-center gap-2">
            <?php foreach ($seriesBooks as $sb):
                $sbPath = book_path($sb);
            ?>
            <a href="<?= htmlspecialchars(url($sbPath), ENT_QUOTES, 'UTF-8') ?>" class="no-underline">
                <div class="flex items-center gap-2 px-2 py-1 rounded-full" style="background: color-mix(in srgb, var(--primary-color) 8%, transparent); border: 1px solid color-mix(in srgb, var(--primary-color) 25%, transparent); transition: all .2s;">
                    <?php if (!empty($sb['numero_serie'])): ?>
                    <span class="status-badge" style="background: var(--primary-color); color: #fff; font-size: 0.7rem;"><?= htmlspecialchars($sb['numero_serie'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <span style="color: var(--primary-color); font-weight: 500; font-size: 0.85rem;"><?= htmlspecialchars($sb['titolo'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Related Books Section -->
<?php if (!empty($related_books) && count($related_books) > 0): ?>
<section class="py-5" style="background: var(--light-bg); margin-top: 3rem;">
    <div class="container">
        <h2 class="section-title">
            <i class="fas fa-lightbulb"></i>
            <?= __("Potrebbero interessarti") ?>
        </h2>
        <div class="related-books-wrap">
        <div class="related-books-grid">
            <?php foreach($related_books as $related): ?>
            <div class="related-book-cell">
                <div class="related-book-card">
                    <div class="related-book-image-container">
                        <?php
                        $relatedTitle = html_entity_decode($related['titolo'] ?? '', ENT_QUOTES, 'UTF-8');
                        $relatedAuthorsRaw = html_entity_decode($related['autori'] ?? '', ENT_QUOTES, 'UTF-8');
                        $relatedAuthorsList = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$relatedAuthorsRaw)));
                        $relatedPublisher = html_entity_decode($related['editore'] ?? '', ENT_QUOTES, 'UTF-8');
                        $relatedTipoMedia = \App\Support\MediaLabels::resolveTipoMedia($related['formato'] ?? null, $related['tipo_media'] ?? null);
                        $relatedIsMusic = $relatedTipoMedia === 'disco';
                        $relatedAuthorDisplay = trim($relatedAuthorsRaw);
                        if ($relatedAuthorDisplay === '') {
                            $relatedAuthorDisplay = trim(html_entity_decode(
                                (string) ($related['autore_principale'] ?? $related['autore_principale_nome'] ?? ''),
                                ENT_QUOTES,
                                'UTF-8'
                            ));
                        }
                        if ($relatedAuthorDisplay === '') {
                            $relatedAuthorDisplay = __($relatedIsMusic ? 'Artista sconosciuto' : 'Autore sconosciuto');
                        }
                        if ($relatedAuthorsList === []) {
                            $relatedAuthorsList = [$relatedAuthorDisplay];
                        }
                        $relatedAltParts = [];
                        if ($relatedTitle !== '') {
                            $relatedAltParts[] = sprintf(__('Copertina del libro "%s"'), $relatedTitle);
                        }
                        // $relatedAuthorsList is guaranteed non-empty here (the
                        // guard above seeds it with $relatedAuthorDisplay, which
                        // always falls back to "Autore/Artista sconosciuto").
                        $relatedAltParts[] = sprintf(__('di %s'), implode(', ', $relatedAuthorsList));
                        if ($relatedPublisher !== '') {
                            $relatedAltParts[] = sprintf(__('Editore %s'), $relatedPublisher);
                        }
                        $relatedCoverAlt = trim(implode(' ', $relatedAltParts));
                        if ($relatedCoverAlt === '') {
                            $relatedCoverAlt = __("Copertina del libro");
                        }
                        ?>
                        <a href="<?= htmlspecialchars(book_url($related), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php $relatedCover = ($related['copertina_url'] ?? '') ?: ($related['immagine_copertina'] ?? '') ?: '/uploads/copertine/placeholder.jpg'; ?>
                            <img src="<?= htmlspecialchars(url($relatedCover), ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($relatedCoverAlt, ENT_QUOTES, 'UTF-8') ?>"
                                 class="related-book-image"
                                 loading="lazy">
                        </a>
                        <?php if (($related['copie_disponibili'] ?? 0) > 0): ?>
                        <span class="related-availability-badge available-badge">
                            <i class="fas fa-check-circle" aria-hidden="true"></i>
                            <?php
                            // Hook: Allow plugins to add icons to related book badge (e.g., eBook/audio icons)
                            do_action('book.badge.digital_icons', $related);
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="related-book-content">
                        <h5 class="related-book-title">
                            <a href="<?= htmlspecialchars(book_url($related), ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars($related['titolo'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h5>
                        <p class="related-book-author">
                            <?= htmlspecialchars($relatedAuthorDisplay, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="related-book-actions">
                            <a href="<?= htmlspecialchars(book_url($related), ENT_QUOTES, 'UTF-8'); ?>"
                               class="btn-related-view">
                                <i class="fas fa-eye mr-2"></i><?= __("Vedi Dettagli") ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div><!-- /.related-books-grid -->
        <noscript>
            <style>
                /* With JS off the inert script can't clip cards, so show them all
                   stacked — matching what assistive tech can reach. row-gap is
                   restored here because the base rule sets it to 0 for the
                   JS-driven single-row layout (F005). */
                .related-books-grid {
                    overflow: visible !important;
                    grid-auto-rows: auto !important;
                    row-gap: 1.5rem !important;
                }
            </style>
        </noscript>
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
    // Phone scroll mode (F003): the @media(max-width:480px) rule turns the grid
    // into a horizontal snap-scroll strip (overflow-x:auto), where every card is
    // reachable by swiping — nothing is clipped. Clear any inert/aria-hidden/
    // tabindex the desktop path may have set and bail out. The desktop clip mode
    // uses overflow:hidden (overflow-x:hidden), so overflowX==='auto' unambiguously
    // means scroll mode.
    if (window.getComputedStyle(grid).overflowX === 'auto') {
      cells.forEach(function (c) {
        c.removeAttribute('inert');
        c.removeAttribute('aria-hidden');
        Array.prototype.forEach.call(c.querySelectorAll('a'), function (a) {
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
      Array.prototype.forEach.call(c.querySelectorAll('a'), function (a) {
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
    const successOneMonthTpl = <?php echo json_encode(__('Richiesta di prestito dal <strong>%s</strong> per 1 mese'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const successFootnote = <?php echo json_encode(__('Riceverai una conferma via email appena la richiesta sarà approvata.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    // #301: con l'auto-approvazione attiva il server risponde auto_approved=true
    // e il prestito è GIÀ in attesa di ritiro — il copy "appena sarà approvata"
    // sarebbe falso e l'utente aspetterebbe un'approvazione già avvenuta.
    const approvedTitle = <?php echo json_encode(__('Prestito approvato!'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const approvedFootnote = <?php echo json_encode(__('La tua richiesta è stata approvata automaticamente: riceverai una email con le istruzioni per il ritiro.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

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

      // Note: Uses local timezone (getFullYear/getMonth/getDate) rather than UTC,
      // which is intentional — loan dates should reflect the user's local date.
      const iso = (dt) => {
        const y = dt.getFullYear();
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const d = String(dt.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
      };
      let earliestAvailable = new Date();
      let suggestedDate = iso(earliestAvailable);

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
              disabledDates = availData.availability.unavailable_dates || [];
              if (availData.availability.earliest_available) {
                const parts = String(availData.availability.earliest_available).split('-').map(Number);
                if (parts.length === 3 && parts[0] && parts[1] && parts[2]) {
                  earliestAvailable = new Date(parts[0], parts[1] - 1, parts[2]);
                } else {
                  earliestAvailable = new Date(); // fallback: today
                }
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

        suggestedDate = iso(earliestAvailable);
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
            `<input id="swal-date-start" type="text" class="loan-date-input" placeholder="<?= __('Data inizio') ?>">`+
            `</div>`+
            `<div class="loan-request-field">`+
            `<label class="loan-request-label" data-for-picker="end">${__('Fino a quando? (opzionale):')}</label>`+
            `<input id="swal-date-end" type="text" class="loan-date-input" placeholder="<?= __('Lascia vuoto per 1 mese') ?>">`+
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
              minDate: 'today',
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
                  if (selectedDates.length > 0) {
                    // Auto-set end date to 1 month after start date
                    const startDate = new Date(selectedDates[0]);
                    const endDate = new Date(startDate);
                    endDate.setMonth(endDate.getMonth() + 1);
                    if (endPicker) {
                      endPicker.setDate(endDate, true);
                      endPicker.set('minDate', startDate);
                    }
                  }
                }
              });

              endPicker = window.flatpickr(endEl, {
                ...baseOpts,
                minDate: earliestAvailable,
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
              const successHtml = formValues.endDate
                ? successRangeTpl.replace('%1$s', formatDateIT(formValues.startDate)).replace('%2$s', formatDateIT(formValues.endDate))
                : successOneMonthTpl.replace('%s', formatDateIT(formValues.startDate));

              const isAutoApproved = result.auto_approved === true;
              Swal.fire({
                icon: 'success',
                title: isAutoApproved ? approvedTitle : __('Richiesta Inviata!'),
                html: `${successHtml}<br><small>${isAutoApproved ? approvedFootnote : successFootnote}</small>`
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
