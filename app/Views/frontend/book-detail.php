<?php
// SEO ottimizzato
$bookTitle = html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8');
$bookAuthor = !empty($authors) ? html_entity_decode($authors[0]['nome'] ?? '', ENT_QUOTES, 'UTF-8') : '';
$bookDescription = !empty($book['descrizione']) ? html_entity_decode($book['descrizione'] ?? '', ENT_QUOTES, 'UTF-8') : '';
$bookPublisher = !empty($book['editore']) ? html_entity_decode($book['editore'] ?? '', ENT_QUOTES, 'UTF-8') : '';
$bookYear = $book['anno_pubblicazione'] ?? '';
$bookISBN = $book['isbn13'] ?? $book['isbn10'] ?? '';
$bookPrice = !empty($book['prezzo']) ? number_format($book['prezzo'], 2) : '';
$bookPages = $book['numero_pagine'] ?? '';
$bookLanguage = $book['lingua'] ?? 'it';
// Build genre hierarchy string
$genreHierarchy = [];
if (!empty($book['genere_grandparent'])) {
    $genreHierarchy[] = $book['genere_grandparent'];
}
if (!empty($book['genere_parent'])) {
    $genreHierarchy[] = $book['genere_parent'];
}
if (!empty($book['genere'])) {
    $genreHierarchy[] = $book['genere'];
}
$bookGenre = !empty($genreHierarchy) ? implode(' > ', $genreHierarchy) : '';
$bookGenre = html_entity_decode($bookGenre, ENT_QUOTES, 'UTF-8');
$bookCover = $book['copertina_url'] ?? '';
$isAvailable = ($book['copie_disponibili'] ?? 0) > 0;
$authorNames = [];
foreach ($authors as $authorData) {
    $name = trim(html_entity_decode($authorData['nome'] ?? '', ENT_QUOTES, 'UTF-8'));
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

// Canonical URL
$canonicalUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Open Graph Image - Ensure absolute URLs
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
if ($bookCover) {
    // Se l'URL è relativo, renderlo assoluto
    $ogImage = (strpos($bookCover, 'http') === 0) ? $bookCover : $baseUrl . $bookCover;
} else {
    $ogImage = $baseUrl . '/uploads/copertine/default-cover.jpg';
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
            "item" => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
        ],
        [
            "@type" => "ListItem",
            "position" => 2,
            "name" => __("Catalogo"),
            "item" => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $catalogRoute
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
    "@type" => "Book",
    "name" => $bookTitle,
    "url" => $canonicalUrl,
    "sameAs" => []
];

if ($bookAuthor) {
    $bookSchema["author"] = [
        "@type" => "Person",
        "name" => $bookAuthor
    ];
}

if ($bookDescription) {
    $bookSchema["description"] = strip_tags($bookDescription);
}

if ($bookPublisher) {
    $bookSchema["publisher"] = [
        "@type" => "Organization",
        "name" => $bookPublisher
    ];
}

if ($bookYear) {
    $bookSchema["datePublished"] = $bookYear;
}

if ($bookISBN) {
    $bookSchema["isbn"] = $bookISBN;
}

if ($bookPages) {
    $bookSchema["numberOfPages"] = (int)$bookPages;
}

if ($bookLanguage) {
    $bookSchema["inLanguage"] = $bookLanguage;
}

if ($bookGenre) {
    $bookSchema["genre"] = $bookGenre;
}

if ($bookCover) {
    $bookSchema["image"] = $ogImage; // Use the absolute URL
}

// Availability
$bookSchema["offers"] = [
    "@type" => "Offer",
    "availability" => $isAvailable ? "https://schema.org/InStock" : "https://schema.org/OutOfStock",
    "itemCondition" => "https://schema.org/UsedCondition",
    "seller" => [
        "@type" => "Library",
        "name" => "Biblioteca"
    ]
];

if ($bookPrice) {
    $bookSchema["offers"]["price"] = $bookPrice;
    $bookSchema["offers"]["priceCurrency"] = "EUR";
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
    "url" => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'],
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
        background-image: url('" . htmlspecialchars($book['copertina_url']) . "');
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
        margin: auto;
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
        border-radius: 20px;
        box-shadow: none;
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    .book-cover-large:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: none;
    }

    /* Margine verticale copertina su desktop */
    .row.align-items-center.book-hero-content img {
        margin: 38px 0;
    }

    /* Layout senza tab - sezioni info */
    .book-description-section {
        background: white;
        padding: 3rem;
        border-radius: 24px;
        margin-bottom: 2rem;
        box-shadow: none;
        border: 1px solid var(--border-color);
        position: relative;
        z-index: 100;
    }

    .book-details-section {
        background: white;
        padding: 3rem;
        border-radius: 24px;
        margin-bottom: 2rem;
        box-shadow: none;
        border: 1px solid var(--border-color);
        position: relative;
        z-index: 90;
    }

    .book-reviews-section {
        background: white;
        padding: 3rem;
        border-radius: 24px;
        margin-bottom: 2rem;
        box-shadow: none;
        border: 1px solid var(--border-color);
        position: relative;
        z-index: 80;
    }

    .review-summary-column {
        flex: 0 0 100%;
        max-width: 100%;
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
        font-size: 1.5rem;
        font-weight: 700;
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
        font-size: clamp(1.75rem, 4vw, 2.5rem);
        font-weight: 900;
        letter-spacing: -0.02em;
        line-height: 1.2;
        margin-bottom: 1.5rem;
        text-shadow: 0 4px 20px rgba(0,0,0,0.8);
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
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .genre-tag {
        display: inline-flex;
        align-items: center;
        padding: 0.4rem 1rem;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 25px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #1a1a1a;
        text-decoration: none;
        transition: all 0.3s ease;
        backdrop-filter: blur(5px);
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
        border-radius: 50px;
        font-weight: 700;
        font-size: 0.95rem;
        margin-bottom: 2rem;
        backdrop-filter: blur(10px);
        border: 2px solid transparent;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.05em;
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
        background: white;
        padding: 3rem;
        border-radius: 24px;
        margin-top: -4rem;
        position: relative;
        z-index: 200;
        box-shadow: none;
        border: 1px solid var(--border-color);
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
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .meta-value {
        color: var(--text-light);
        font-size: 1.05rem;
        font-weight: 500;
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
        border-radius: 50px;
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
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-color: var(--primary-color);
    }

    .role-principale:hover {
        color: white;
        box-shadow: none;
    }

    .role-coautore {
        background: linear-gradient(135deg, var(--warning-color), #f97316);
        color: white;
        border-color: var(--warning-color);
    }

    .role-coautore:hover {
        color: white;
        box-shadow: none;
    }

    .role-traduttore {
        background: linear-gradient(135deg, #8b5cf6, #a855f7);
        color: white;
        border-color: #8b5cf6;
    }

    .role-traduttore:hover {
        color: white;
        box-shadow: none;
    }

    .description-content {
        line-height: 1.8;
        color: var(--text-light);
        font-size: 1.1rem;
        font-weight: 400;
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
        border-radius: 12px 12px 0 0;
    }

    .nav-tabs .nav-link.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
        background: var(--light-bg);
        font-weight: 700;
        border-radius: 12px 12px 0 0;
    }

    .nav-tabs .nav-link i {
        margin-right: 0.5rem;
        font-size: 1rem;
    }

    .related-books .book-card {
        margin-bottom: 2rem;
        transition: all 0.3s ease;
        border-radius: 12px;
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

    .action-buttons .btn {
        padding: 1rem 2.5rem;
        font-weight: 700;
        border-radius: 50px;
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

    .action-buttons .btn:hover {
        transform: translateY(-3px);
        box-shadow: none;
        text-decoration: none;
    }

    .action-buttons .btn-primary {
        background: #111827;
        border-color: #111827;
        color: #ffffff;
    }

    .action-buttons .btn-primary:hover {
        background: #000000;
        border-color: #000000;
        color: #ffffff;
        box-shadow: none;
    }

    .action-buttons .btn-outline-primary {
        color: #111827;
        border-color: #111827;
        background: transparent;
    }

    .action-buttons .btn-outline-primary:hover {
        background: #111827;
        border-color: #111827;
        color: #ffffff;
    }

    .action-buttons .btn-outline-secondary {
        color: var(--text-light);
        border-color: var(--border-color);
        background: transparent;
    }

    .action-buttons .btn-outline-secondary:hover {
        background: var(--text-light);
        border-color: var(--text-light);
        color: #1a1a1a;
    }

    .swal2-popup {
        width: min(720px, 95vw) !important;
        padding: 2.5rem 2.75rem 2.25rem !important;
        border-radius: 18px !important;
        background: #ffffff !important;
        color: #111827 !important;
        border: 1px solid rgba(17, 24, 39, 0.15) !important;
        box-shadow: 0 35px 120px rgba(17, 24, 39, 0.28) !important;
    }

    .swal2-popup .swal2-title,
    .swal2-popup .swal2-html-container,
    .swal2-popup label,
    .swal2-popup .text-muted {
        color: #111827 !important;
    }

    .swal2-popup .swal2-actions .swal2-styled {
        border-radius: 9999px;
        padding: 0.75rem 1.75rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        transition: transform 0.2s ease, background 0.2s ease;
        box-shadow: none !important;
    }

    .swal2-popup .swal2-styled.swal2-confirm {
        background: #111827 !important;
        border: 1px solid #111827 !important;
        color: #ffffff !important;
    }

    .swal2-popup .swal2-styled.swal2-confirm:hover,
    .swal2-popup .swal2-styled.swal2-confirm:focus {
        background: #000000 !important;
        border-color: #000000 !important;
        color: #ffffff !important;
    }

    .swal2-popup .swal2-styled.swal2-cancel {
        background: transparent !important;
        color: #111827 !important;
        border: 1px solid rgba(17, 24, 39, 0.25) !important;
    }

    .swal2-popup .swal2-styled.swal2-cancel:hover,
    .swal2-popup .swal2-styled.swal2-cancel:focus {
        background: rgba(17, 24, 39, 0.08) !important;
        border-color: rgba(17, 24, 39, 0.4) !important;
        color: #000000 !important;
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

    /* Social Icons */
    .social-icon-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        color: #1a1a1a;
        text-decoration: none;
        transition: all 0.3s ease;
        font-size: 1.25rem;
    }

    .social-icon-link:hover {
        color: #000;
        transform: scale(1.1);
    }

    /* Elegant Cards */
    .card {
        border: 1px solid var(--border-color);
        box-shadow: none;
        transition: all 0.3s ease;
        border-radius: 20px;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: none;
        transform: translateY(-2px);
    }

    .card-header {
        background: var(--light-bg);
        border-bottom: 1px solid var(--border-color);
        padding: 1.5rem;
    }

    .card-header h6 {
        color: var(--primary-color);
        font-weight: 700;
        font-size: 1rem;
        margin: 0;
        letter-spacing: -0.01em;
    }

    .card-body {
        padding: 1.5rem;
    }

    .badge {
        padding: 0.5rem 0.875rem;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
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
        .row.align-items-center.book-hero-content img {
            margin: 0 auto 1.5rem;
        }

        /* Padding ridotto per sezioni su mobile */
        .book-description-section,
        .book-details-section,
        .book-reviews-section {
            padding: 2rem;
        }

        /* Card contenuti full-bleed su mobile */
        .card {
            padding: 0;
        }

        .book-meta {
            margin-top: -1rem;
            padding: 1.5rem;
            border-radius: 20px 20px 0 0;
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
        }

        .action-buttons .btn {
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
        .row.align-items-center.book-hero-content img {
            margin: 0 auto;
        }

        /* Padding ridotto per sezioni su mobile */
        .book-description-section,
        .book-details-section,
        .book-reviews-section {
            padding: 2rem;
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

        .card-body {
            padding: 1rem;
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
        .row.align-items-center.book-hero-content img {
            margin: 0 auto;
        }

        /* Padding ridotto per sezioni su mobile */
        .book-description-section,
        .book-details-section,
        .book-reviews-section {
            padding: 1.5rem;
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
    .related-book-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.3s ease;
        border: 1px solid #e5e7eb;
        height: 100%;
        display: flex;
        flex-direction: column;
    }

    .related-book-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    }

    .related-book-image-container {
        position: relative;
        width: 100%;
        padding-top: 140%;
        overflow: hidden;
        background: #f3f4f6;
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
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        background: rgba(16, 185, 129, 0.95);
        color: white;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
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
        color: #1a1a1a;
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
        color: #6b7280;
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
        justify-content: center;
        width: 100%;
        padding: 0.75rem 1.5rem;
        background: #1f2937;
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        border: none;
    }

    .btn-related-view:hover {
        background: #111827;
        transform: translateY(-2px);
        color: white;
    }

    @media (max-width: 768px) {
        .related-book-card {
            max-width: 400px;
            margin: 0 auto;
        }
    }

    /* Favorites button custom styling */
    .btn-fav-custom {
        background-color: #ffffff !important;
        border: 1px solid #dee2e6 !important;
        color: #6c757d !important;
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
    background-color: white;
    }
    div#book-cover-container {
    max-width: 400px;
    margin: auto;
    }
";

ob_start();
?>

<!-- Book Hero Section -->
<section class="book-hero">
    <div class="container">
        <div class="row align-items-center book-hero-content" id="book-hero-content">
            <div class="col-lg-4 text-center mb-4 mb-lg-0" id="book-cover-container">
                <img src="<?= htmlspecialchars($book['copertina_url']) ?>"
                     alt="<?= htmlspecialchars($coverAlt, ENT_QUOTES, 'UTF-8') ?>"
                     class="book-cover-large img-fluid"
                     id="book-cover-image">
            </div>
            <div class="col-lg-8">
                <div class="hero-text">
                    <?php if (!empty($book['editore'])): ?>
                        <p class="mb-2 opacity-75">
                            <i class="fas fa-building me-2"></i>
                            <a href="/editore/<?= urlencode(html_entity_decode($book['editore'] ?? '', ENT_QUOTES, 'UTF-8')) ?>" class="text-decoration-none text-dark">
                                <?= htmlspecialchars(html_entity_decode($book['editore'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                            </a>
                        </p>
                    <?php endif; ?>

                    <h1 class="fw-bold mb-3" id="book-title" style="font-size: clamp(1.5rem, 3.5vw, 2.25rem);"><?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?></h1>

                    <div class="authors-list" id="book-authors-list">
                        <?php foreach($authors as $author): ?>
                            <a href="/autore/<?= urlencode(html_entity_decode($author['nome'] ?? '', ENT_QUOTES, 'UTF-8')) ?>" class="text-decoration-none">
                                <span class="author-item role-<?= $author['ruolo'] ?>">
                                    <?= htmlspecialchars(html_entity_decode($author['nome'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                                    <?php if ($author['ruolo'] !== 'principale'): ?>
                                        (<?= ucfirst($author['ruolo']) ?>)
                                    <?php endif; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($book['genere'])): ?>
                    <div class="genre-tags">
                        <a href="<?= $catalogRoute ?>?genere=<?= urlencode(html_entity_decode($book['genere'] ?? '', ENT_QUOTES, 'UTF-8')) ?>" class="genre-tag">
                            <i class="fas fa-tags me-1"></i><?= htmlspecialchars($bookGenre) ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <span class="availability-badge <?= ($book['copie_disponibili'] > 0) ? 'available' : 'unavailable' ?>">
                            <i class="fas fa-<?= ($book['copie_disponibili'] > 0) ? 'check-circle' : 'times-circle' ?> me-2"></i>
                            <?= ($book['copie_disponibili'] > 0)
                                ? ($book['copie_totali'] > 1
                                    ? "{$book['copie_disponibili']}/{$book['copie_totali']} " . __("Disponibili")
                                    : __("Disponibile"))
                                : __("Non Disponibile") ?>
                        </span>
                    </div>

                    <div class="mt-3">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb bg-transparent p-0 mb-0">
                                <li class="breadcrumb-item">
                                    <a href="/" class="text-dark"><?= __("Home") ?></a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="<?= $legacyCatalogRoute ?>" class="text-dark-50"><?= __("Catalogo") ?></a>
                                </li>
                                <li class="breadcrumb-item active text-dark" aria-current="page">
                                    <?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                                </li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Book Details Section -->
<section class="py-5" style="margin-top: 3rem; position: relative; z-index: 50;">
    <div class="container">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- Action Buttons -->
                <div class="action-buttons text-center mb-4" id="book-action-buttons">
                    <!-- Always show the calendar to choose dates -->
                    <button id="btn-request-loan" type="button" class="btn <?= ($book['copie_disponibili'] ?? 0) > 0 ? 'btn-primary' : 'btn-outline-primary' ?> btn-lg" data-libro-id="<?= (int)($book['id'] ?? 0) ?>">
                        <i class="fas fa-<?= ($book['copie_disponibili'] ?? 0) > 0 ? 'book-reader' : 'calendar-alt' ?> me-2"></i>
                        <?= ($book['copie_disponibili'] ?? 0) > 0 ? __('Richiedi Prestito') : __('Prenota Quando Disponibile') ?>
                    </button>
                    <?php $isLogged = !empty($_SESSION['user'] ?? null); ?>
                    <?php if ($isLogged): ?>
                      <button id="btn-fav" type="button" class="btn btn-light btn-lg btn-fav-custom" data-libro-id="<?= (int)($book['id'] ?? 0) ?>">
                        <i class="fas fa-heart me-2"></i><span><?= __("Aggiungi ai Preferiti") ?></span>
                      </button>
                    <?php else: ?>
                      <a href="<?= $loginRoute ?>" class="btn btn-light btn-lg btn-fav-custom">
                        <i class="fas fa-heart me-2"></i><?= __("Accedi per aggiungere ai Preferiti") ?>
                      </a>
                    <?php endif; ?>
                </div>

                <!-- Alerts Section -->
                <div id="book-alerts">
                    <?php if (!empty($_GET['loan_success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?= __("Prestito richiesto con successo.") ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php elseif (!empty($_GET['loan_error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php
                              $e = $_GET['loan_error'];
                              echo $e==='not_available' ? __('Libro non disponibile per il prestito.') : __('Errore nella richiesta di prestito.');
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($_GET['reserve_success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?= __("Prenotazione effettuata con successo") ?><?php if(!empty($_GET['reserve_date'])): ?> <?= __("per il giorno") ?> <strong><?= htmlspecialchars($_GET['reserve_date']) ?></strong><?php endif; ?>.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php elseif (!empty($_GET['reserve_error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php
                              $e = $_GET['reserve_error'];
                              echo $e==='duplicate' ? __('Hai già una prenotazione attiva per questo libro.') : ($e==='invalid_date' ? __('Data non valida.') : ($e==='past_date' ? __('La data non può essere nel passato.') : __('Errore nella prenotazione.')));
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Description Section -->
                <div class="book-description-section" id="book-description-section">
                    <h2 class="section-title">
                        <i class="fas fa-info-circle"></i>
                        <?= __("Descrizione") ?>
                    </h2>
                    <div class="description-content">
                        <?php if (!empty($book['descrizione'])): ?>
                            <p><?= nl2br(htmlspecialchars(html_entity_decode($book['descrizione'] ?? '', ENT_QUOTES, 'UTF-8'))) ?></p>
                        <?php else: ?>
                            <p class="text-muted"><?= __("Nessuna descrizione disponibile per questo libro.") ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Details Section -->
                <?php
                $detailFields = [
                    !empty($book['isbn13']),
                    !empty($book['isbn10']),
                    !empty($book['ean']),
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
                            <?php if (!empty($book['isbn13'])): ?>
                            <div class="meta-item">
                                <div class="meta-label">ISBN-13</div>
                                <div class="meta-value"><?= htmlspecialchars($book['isbn13']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['isbn10'])): ?>
                            <div class="meta-item">
                                <div class="meta-label">ISBN-10</div>
                                <div class="meta-value"><?= htmlspecialchars($book['isbn10']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['ean'])): ?>
                            <div class="meta-item">
                                <div class="meta-label">EAN</div>
                                <div class="meta-value"><?= htmlspecialchars($book['ean']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($bookGenre)): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Genere") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($bookGenre) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['lingua'])): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Lingua") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['lingua']) ?></div>
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
                                <div class="meta-label"><?= __("Anno di Pubblicazione") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['anno_pubblicazione']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['data_pubblicazione'])): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Data di Pubblicazione") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['data_pubblicazione']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['numero_pagine'])): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Numero di Pagine") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['numero_pagine']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['formato'])): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Formato") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['formato']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['dimensioni'])): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Dimensioni") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['dimensioni']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['peso'])): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Peso") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['peso']) ?> kg</div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($book['numero_inventario'])): ?>
                            <div class="meta-item">
                                <div class="meta-label"><?= __("Numero Inventario") ?></div>
                                <div class="meta-value"><?= htmlspecialchars($book['numero_inventario']) ?></div>
                            </div>
                            <?php endif; ?>
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
                        <span class="badge bg-primary rounded-pill"><?= count($reviews) ?></span>
                    </h2>

                    <!-- Review Statistics -->
                    <?php if ($reviewStats['total_reviews'] > 0): ?>
                        <div class="review-stats mb-4">
                            <div class="row align-items-center">
                                <div class="col-md-4 text-center mb-3 mb-md-0 review-summary-column">
                                    <div class="average-rating">
                                        <div class="display-4 fw-bold text-warning"><?= number_format($reviewStats['average_rating'], 1) ?></div>
                                        <div class="stars mb-2">
                                            <?php
                                            $avgRating = $reviewStats['average_rating'];
                                            for ($i = 1; $i <= 5; $i++):
                                                if ($i <= floor($avgRating)):
                                                    echo '<i class="fas fa-star text-warning"></i>';
                                                elseif ($i - 0.5 <= $avgRating):
                                                    echo '<i class="fas fa-star-half-alt text-warning"></i>';
                                                else:
                                                    echo '<i class="far fa-star text-warning"></i>';
                                                endif;
                                            endfor;
                                            ?>
                                        </div>
                                        <div class="text-muted small"><?= $reviewStats['total_reviews'] ?> <?= __("recensioni") ?></div>
                                    </div>
                                </div>
                                <div class="col-md-8 review-distribution-column">
                                    <div class="rating-bars">
                                        <?php
                                        $total = $reviewStats['total_reviews'];
                                        for ($stars = 5; $stars >= 1; $stars--):
                                            $count = $reviewStats[$stars === 1 ? 'one_star' : ($stars === 2 ? 'two_star' : ($stars === 3 ? 'three_star' : ($stars === 4 ? 'four_star' : 'five_star')))];
                                            $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                                        ?>
                                            <div class="rating-bar-row d-flex align-items-center">
                                                <div class="stars-label me-2">
                                                    <?php for ($i = 0; $i < $stars; $i++): ?>
                                                        <i class="fas fa-star text-warning small"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $percentage ?>%"></div>
                                                </div>
                                                <div class="count-label text-muted small" style="width: 40px;"><?= $count ?></div>
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
                            <div class="review-item border-bottom pb-4 mb-4">
                                <div class="review-header d-flex justify-content-between align-items-start mb-2">
                                    <div class="review-user-info d-flex align-items-center">
                                        <div class="avatar-placeholder bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3"
                                             style="width: 40px; height: 40px;">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($review['utente_nome']) ?></div>
                                            <div class="text-muted small">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?= date('d/m/Y', strtotime($review['approved_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="review-stars">
                                        <?php for ($i = 0; $i < 5; $i++): ?>
                                            <i class="<?= $i < $review['stelle'] ? 'fas' : 'far' ?> fa-star text-warning"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <?php if (!empty($review['titolo'])): ?>
                                    <h5 class="review-title fw-bold mb-2"><?= htmlspecialchars($review['titolo']) ?></h5>
                                <?php endif; ?>

                                <?php if (!empty($review['descrizione'])): ?>
                                    <p class="review-text mb-0"><?= nl2br(htmlspecialchars($review['descrizione'])) ?></p>
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
            <div class="col-lg-4" id="book-sidebar">
                <!-- Book Info Card -->
                <div class="card mb-4" style="position: relative; z-index: 100;" id="book-info-card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i><?= __("Informazioni Libro") ?></h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($book['editore'])): ?>
                        <div class="meta-item">
                            <div class="meta-label"><?= __("Editore") ?></div>
                            <div class="meta-value"><?= htmlspecialchars($book['editore'] ?? '') ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="meta-item">
                            <div class="meta-label"><?= __("Stato") ?></div>
                            <div class="meta-value">
                                <span class="badge <?= ($book['copie_disponibili'] > 0) ? 'bg-success' : 'bg-danger' ?>">
                                    <?= ($book['copie_disponibili'] > 0) ? __("Disponibile") : __("Non Disponibile") ?>
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
                            <div class="meta-value"><?= htmlspecialchars($book['collocazione']) ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="meta-item">
                            <div class="meta-label"><?= __("Aggiunto il") ?></div>
                            <div class="meta-value"><?= date('d/m/Y', strtotime($book['created_at'])) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Share Card -->
                <div class="card" id="book-share-card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-share-alt me-2"></i><?= __("Condividi") ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-around">
                            <!-- Facebook Share -->
                            <a href="#" class="social-icon-link" id="share-facebook" title="<?= __("Condividi su Facebook") ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fab fa-facebook"></i>
                            </a>
                            <!-- Twitter/X Share -->
                            <a href="#" class="social-icon-link" id="share-twitter" title="<?= __("Condividi su Twitter") ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <!-- WhatsApp Share -->
                            <a href="#" class="social-icon-link" id="share-whatsapp" title="<?= __("Condividi su WhatsApp") ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <!-- Copy Link -->
                            <button type="button" class="social-icon-link" id="copy-link" title="<?= __("Copia link negli appunti") ?>" style="border: none; background: none; padding: 0; cursor: pointer;">
                                <i class="fas fa-link"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Related Books Section -->
<?php if (!empty($related_books) && count($related_books) > 0): ?>
<section class="py-5" style="background: #f9fafb; margin-top: 3rem;">
    <div class="container">
        <h3 class="text-center mb-5" style="font-weight: 700; font-size: 2rem; color: #1a1a1a;"><?= __("Potrebbero interessarti") ?></h3>
        <div class="row g-4">
            <?php foreach($related_books as $related): ?>
            <div class="col-lg-4 col-md-6">
                <div class="related-book-card">
                    <div class="related-book-image-container">
                        <?php
                        $relatedTitle = html_entity_decode($related['titolo'] ?? '', ENT_QUOTES, 'UTF-8');
                        $relatedAuthorsRaw = html_entity_decode($related['autori'] ?? '', ENT_QUOTES, 'UTF-8');
                        $relatedAuthorsList = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$relatedAuthorsRaw)));
                        $relatedPublisher = html_entity_decode($related['editore'] ?? '', ENT_QUOTES, 'UTF-8');
                        $relatedAltParts = [];
                        if ($relatedTitle !== '') {
                            $relatedAltParts[] = 'Copertina del libro "' . $relatedTitle . '"';
                        }
                        if (!empty($relatedAuthorsList)) {
                            $relatedAltParts[] = 'di ' . implode(', ', $relatedAuthorsList);
                        }
                        if ($relatedPublisher !== '') {
                            $relatedAltParts[] = 'Editore ' . $relatedPublisher;
                        }
                        $relatedCoverAlt = trim(implode(' ', $relatedAltParts));
                        if ($relatedCoverAlt === '') {
                            $relatedCoverAlt = __("Copertina del libro");
                        }
                        ?>
                        <a href="<?= htmlspecialchars(book_url($related), ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?= htmlspecialchars($related['copertina_url'] ?? '/uploads/copertine/default-cover.jpg') ?>"
                                 alt="<?= htmlspecialchars($relatedCoverAlt, ENT_QUOTES, 'UTF-8') ?>"
                                 class="related-book-image">
                        </a>
                        <?php if (($related['copie_disponibili'] ?? 0) > 0): ?>
                        <span class="related-availability-badge available-badge">
                            <i class="fas fa-check-circle"></i>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="related-book-content">
                        <h5 class="related-book-title">
                            <a href="<?= htmlspecialchars(book_url($related), ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars($related['titolo']) ?>
                            </a>
                        </h5>
                        <p class="related-book-author">
                            <?= htmlspecialchars($related['autori'] ?? 'Autore sconosciuto') ?>
                        </p>
                        <div class="related-book-actions">
                            <a href="<?= htmlspecialchars(book_url($related), ENT_QUOTES, 'UTF-8'); ?>"
                               class="btn-related-view">
                                <i class="fas fa-eye me-2"></i><?= __("Vedi Dettagli") ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$isLoggedJs = !empty($_SESSION['user'] ?? null);
$libroIdJs = (int)($book['id'] ?? 0);

// Prepare SEO variables for layout
$seoTitle = $metaTitle;
$seoDescription = $metaDescription;
$seoImage = $ogImage;
$seoCanonical = $canonicalUrl;
$seoSchema = json_encode([$bookSchema, $breadcrumbSchema, $organizationSchema], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$content = ob_get_clean();
include 'layout.php';
?>
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

  fetch(`/api/user/wishlist/status?libro_id=${libroId}`)
    .then(r => r.ok ? r.json() : {favorite:false})
    .then(data => setFavUI(!!data.favorite))
    .catch(() => setFavUI(false));

  favBtn.addEventListener('click', async function() {
    try {
      const res = await fetch('/api/user/wishlist/toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf, libro_id: String(libroId) })
      });
      if (!res.ok) throw new Error('bad');
      const data = await res.json();
      setFavUI(!!data.favorite);
    } catch (e) {
      alert('<?= addslashes(__("Errore nell\'aggiornare i preferiti.")) ?>');
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
    const isLogged = <?php echo $isLoggedJs ? 'true' : 'false'; ?>;

    async function updateReservationsBadge() {
      const badge = document.getElementById('nav-res-count');
      if (!badge) return;
      try {
        const r = await fetch('/api/user/reservations/count');
        if (!r.ok) return;
        const data = await r.json();
        const c = parseInt(data.count || 0, 10);
        if (c > 0) { badge.textContent = String(c); badge.classList.remove('d-none'); }
        else { badge.classList.add('d-none'); }
      } catch(_) {}
    }

    requestBtn.addEventListener('click', async function(){
      // Check if user is logged in
      if (!isLogged) {
        if (window.Swal) {
          Swal.fire({
            icon: 'warning',
            title: __('Accesso Richiesto'),
            html: '<p class="mb-3">' + __('Per richiedere un prestito devi effettuare il login.') + '</p>',
            confirmButtonText: __('Vai al Login'),
            cancelButtonText: __('Annulla'),
            showCancelButton: true,
            customClass: {
              confirmButton: 'btn btn-dark',
              cancelButton: 'btn btn-outline-dark'
            }
          }).then((result) => {
            if (result.isConfirmed) {
              window.location.href = '<?= $loginRoute ?>?redirect=' + encodeURIComponent(window.location.pathname);
            }
          });
        } else {
          if (confirm(__('Per richiedere un prestito devi effettuare il login. Vuoi andare alla pagina di login?'))) {
            window.location.href = '<?= $loginRoute ?>?redirect=' + encodeURIComponent(window.location.pathname);
          }
        }
        return;
      }

      if (window.Swal) {
        // Fetch availability data for the calendar
        let disabledDates = [];
        let earliestAvailable = new Date();

        try {
          const availRes = await fetch(`/api/libro/${libroId}/availability`);
          if (availRes.ok) {
            const availData = await availRes.json();
            if (availData.success && availData.availability) {
              disabledDates = availData.availability.unavailable_dates || [];
              if (availData.availability.earliest_available) {
                earliestAvailable = new Date(availData.availability.earliest_available);
              }
            }
          }
        } catch(e) {
          console.error('Error fetching availability:', e);
        }

        const iso = (dt) => dt.toISOString().slice(0,10);
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
        const suggestedDate = iso(earliestAvailable);

        const { value: formValues } = await Swal.fire({
          title: __('Richiesta Prestito'),
          html:
            `<div class="text-start">`+
            `<label class="form-label">${__('Quando vuoi iniziare il prestito?')}</label>`+
            `<input id="swal-date-start" type="text" class="swal2-input" style="display:block; width:100%; max-width:100%; box-sizing:border-box" placeholder="<?= __('Data inizio') ?>">`+
            `<label class="form-label mt-3">${__('Fino a quando? (opzionale):')}</label>`+
            `<input id="swal-date-end" type="text" class="swal2-input" style="display:block; width:100%; max-width:100%; box-sizing:border-box" placeholder="<?= __('Lascia vuoto per 1 mese') ?>">`+
            `<div class="text-muted mt-2 small">`+
            `<i class="fas fa-info-circle me-1"></i>`+
            `${__('Le date rosse non sono disponibili. La richiesta verrà valutata da un amministratore.')}`+
            `</div>`+
            `</div>`,
          focusConfirm: false,
          showCancelButton: true,
          confirmButtonText: __('Invia Richiesta'),
          cancelButtonText: __('Annulla'),
          didOpen: () => {
            const startEl = document.getElementById('swal-date-start');
            const endEl = document.getElementById('swal-date-end');

            const baseOpts = {
              dateFormat: 'Y-m-d',
              altInput: true,
              altFormat: 'd-m-Y',
              minDate: suggestedDate,
              defaultDate: suggestedDate,
              disable: disabledDates
            };

            if (window.flatpickr) {
              let endPicker;

              const startPicker = window.flatpickr(startEl, {
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
                minDate: earliestAvailable
              });
            }
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

            const res = await fetch(`/api/libro/${libroId}/reservation`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
              },
              body: JSON.stringify(reqBody)
            });

            const result = await res.json();

            if (res.ok && result.success) {
              await updateReservationsBadge();
              Swal.fire({
                icon: 'success',
                title: __('Richiesta Inviata!'),
                html: `Richiesta di prestito dal <strong>${formatDateIT(formValues.startDate)}</strong>` +
                      (formValues.endDate ? ` al <strong>${formatDateIT(formValues.endDate)}</strong>` : __(' per 1 mese')) +
                      `<br><small><?= __("$1") ?></small>`
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
            const res = await fetch(`/api/libro/${libroId}/reservation`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
              },
              body: JSON.stringify({ start_date: date, csrf_token: csrf })
            });

            const result = await res.json();
            if (res.ok && result.success) {
              await updateReservationsBadge();
              alert(__('Prenotazione effettuata per ') + date);
            } else {
              alert(__('Errore: ') + (result.message || __('Impossibile creare la prenotazione')));
            }
          } catch(_) {
            alert(__('Errore nella prenotazione'));
          }
        }
      }
    });
  }
});
</script>

<!-- Social Share and Copy Link Handler -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Get the current page URL and book title
  const pageUrl = window.location.href;
  const bookTitle = document.getElementById('book-title');
  const bookTitleText = bookTitle ? bookTitle.textContent.trim() : 'Questo libro';

  // Create share text
  const shareText = `Scopri ${bookTitleText} su Biblioteca`;

  // Facebook Share
  const facebookBtn = document.getElementById('share-facebook');
  if (facebookBtn) {
    facebookBtn.href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(pageUrl)}`;
    facebookBtn.addEventListener('click', function(e) {
      e.preventDefault();
      window.open(this.href, 'facebook-share', 'width=600,height=400');
    });
  }

  // Twitter/X Share
  const twitterBtn = document.getElementById('share-twitter');
  if (twitterBtn) {
    twitterBtn.href = `https://twitter.com/intent/tweet?url=${encodeURIComponent(pageUrl)}&text=${encodeURIComponent(shareText)}`;
    twitterBtn.addEventListener('click', function(e) {
      e.preventDefault();
      window.open(this.href, 'twitter-share', 'width=550,height=420');
    });
  }

  // WhatsApp Share
  const whatsappBtn = document.getElementById('share-whatsapp');
  if (whatsappBtn) {
    whatsappBtn.href = `https://wa.me/?text=${encodeURIComponent(shareText + ' ' + pageUrl)}`;
    whatsappBtn.addEventListener('click', function(e) {
      e.preventDefault();
      window.open(this.href, 'whatsapp-share');
    });
  }

  // Copy Link to Clipboard
  const copyLinkBtn = document.getElementById('copy-link');
  if (copyLinkBtn) {
    copyLinkBtn.addEventListener('click', function(e) {
      e.preventDefault();

      // Use modern Clipboard API if available
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(pageUrl)
          .then(function() {
            showCopyNotification(copyLinkBtn, '<?= addslashes(__("Link copiato!")) ?>');
          })
          .catch(function(err) {
            console.error('Errore nella copia:', err);
            fallbackCopyToClipboard(pageUrl, copyLinkBtn);
          });
      } else {
        // Fallback for older browsers
        fallbackCopyToClipboard(pageUrl, copyLinkBtn);
      }
    });
  }

  // Fallback copy function for older browsers
  function fallbackCopyToClipboard(text, button) {
    try {
      const textArea = document.createElement('textarea');
      textArea.value = text;
      textArea.style.position = 'fixed';
      textArea.style.left = '-999999px';
      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();
      document.execCommand('copy');
      document.body.removeChild(textArea);
      showCopyNotification(button, '<?= addslashes(__("Link copiato!")) ?>');
    } catch (err) {
      console.error('Fallback copy error:', err);
      alert('Link: ' + text);
    }
  }

  // Show notification when link is copied
  function showCopyNotification(button, message) {
    const originalTitle = button.title;
    const originalInnerHTML = button.innerHTML;

    // Change button appearance temporarily
    button.title = message;
    button.innerHTML = '<i class="fas fa-check"></i>';
    button.style.color = '#10b981';

    // Restore after 2 seconds
    setTimeout(function() {
      button.title = originalTitle;
      button.innerHTML = originalInnerHTML;
      button.style.color = '';
    }, 2000);
  }
});
</script>
