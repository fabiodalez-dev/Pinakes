<?php
// Expects $content

use App\Support\ConfigStore;
use App\Support\HtmlHelper;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogo = (string)ConfigStore::get('app.logo', '');
if ($appLogo !== '') {
    $parsedLogoPath = parse_url($appLogo, PHP_URL_PATH) ?? $appLogo;
    $publicDir = realpath(dirname(__DIR__, 2) . '/public');
    if ($publicDir !== false) {
        $absoluteLogoPath = realpath($publicDir . $parsedLogoPath) ?: ($publicDir . $parsedLogoPath);
        if (!is_file($absoluteLogoPath)) {
            $appLogo = '';
        }
    }
}
$appInitial = mb_strtoupper(mb_substr($appName, 0, 1));
$footerDescription = (string)ConfigStore::get('app.footer_description', 'La tua biblioteca digitale per scoprire, esplorare e gestire la tua collezione di libri preferiti.');
$socialFacebook = (string)ConfigStore::get('app.social_facebook', '');
$socialTwitter = (string)ConfigStore::get('app.social_twitter', '');
$socialInstagram = (string)ConfigStore::get('app.social_instagram', '');
$socialLinkedin = (string)ConfigStore::get('app.social_linkedin', '');
$socialBluesky = (string)ConfigStore::get('app.social_bluesky', '');
?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo HtmlHelper::e($title ?? ($appName . ' - Area Utente')); ?></title>
    <meta name="csrf-token" content="<?php echo App\Support\Csrf::ensureToken(); ?>">

    <!-- Assets -->
    <link href="/assets/vendor.css" rel="stylesheet">
    <link href="/assets/main.css" rel="stylesheet">
    <link href="/assets/css/swal-theme.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #000000;
            --secondary-color: #333333;
            --accent-color: #666666;
            --text-color: #000000;
            --text-light: #666666;
            --text-muted: #999999;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            --card-shadow: 0 4px 20px rgba(0,0,0,0.08);
            --card-shadow-hover: 0 8px 30px rgba(0,0,0,0.12);
            --border-color: #e5e7eb;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.7;
            color: var(--text-color);
            background-color: var(--white);
            padding-top: 0;
            font-weight: 400;
            letter-spacing: -0.01em;
        }

        main {
            padding-top: 90px;
        }

        /* Minimalist Header */
        .header-container {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
        }

        .header-main {
            padding: 1.25rem 0;
        }

        .header-brand {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            letter-spacing: -0.02em;
            transition: all 0.3s ease;
        }

        .header-brand .logo-image {
            width: 2.5rem;
            height: 2.5rem;
            object-fit: contain;
        }

        .header-brand .brand-text {
            font-weight: 800;
            font-size: 1.35rem;
            letter-spacing: -0.03em;
        }

        .header-brand:hover {
            color: var(--secondary-color);
            text-decoration: none;
            transform: translateY(-1px);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 3rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .nav-links a {
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            padding: 0.75rem 0;
            position: relative;
            letter-spacing: -0.01em;
        }

        .nav-links a:hover, .nav-links a.active {
            color: var(--primary-color);
            font-weight: 600;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: var(--primary-color);
            transition: width 0.3s ease;
        }

        .nav-links a:hover::after, .nav-links a.active::after {
            width: 100%;
        }

        .search-form {
            flex: 1;
            max-width: 400px;
        }

        .search-input {
            border: 1px solid var(--border-color);
            border-radius: 50px;
            padding: 0.75rem 1.25rem;
            width: 100%;
            font-size: 0.95rem;
            background: var(--white);
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
            transform: translateY(-1px);
        }

        .btn-header {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: -0.01em;
            border: 1px solid transparent;
        }

        .btn-primary-header {
            background: var(--primary-color);
            color: white;
            border: 1px solid var(--primary-color);
        }

        .btn-primary-header:hover {
            background: var(--secondary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .btn-outline-header {
            background: transparent;
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }

        .btn-outline-header:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: rgba(0,0,0,0.02);
            transform: translateY(-1px);
        }

        .badge-notification {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(239,68,68,0.3);
        }

        /* Main Content */
        main {
            min-height: calc(100vh - 90px);
            background: var(--light-bg);
        }

        /* Page Content Styling */
        .page-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .page-header {
            background: var(--white);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
            letter-spacing: -0.02em;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--text-light);
            font-size: 1rem;
            font-weight: 400;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .alert-info {
            background: rgba(31, 41, 55, 0.1);
            color: #1f2937;
            border-color: rgba(31, 41, 55, 0.2);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            main {
                padding-top: 80px;
            }

            .header-main {
                padding: 1rem 0;
            }

            .header-content {
                flex-wrap: wrap;
            }

            .header-brand {
                order: 1;
                font-size: 1.3rem;
            }

            .mobile-menu-toggle {
                order: 2;
                margin-left: auto;
            }

            .user-menu {
                display: none !important;
            }

            .header-content .search-form {
                order: 3;
                width: 100%;
                max-width: 100%;
                flex: none;
            }

            .nav-links {
                display: none;
            }

            .btn-header {
                padding: 0.6rem 1.2rem;
                font-size: 0.85rem;
            }

            .page-container {
                padding: 1.5rem 1rem;
            }

            .page-header {
                padding: 1.5rem;
            }

            .page-title {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            main {
                padding-top: 75px;
            }

            .header-brand {
                font-size: 1.2rem;
                gap: 0.5rem;
            }

            .btn-header {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }

            .search-input {
                padding: 0.6rem 1rem;
                font-size: 0.9rem;
            }

            .page-container {
                padding: 1rem 0.75rem;
            }

            .page-header {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.25rem;
            }
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Utility Classes */
        .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 1rem; }
        .gap-4 { gap: 1.5rem; }

        /* Header Content */
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Mobile Menu Styles */
        .mobile-menu-toggle {
            background: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            color: var(--primary-color);
        }

        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .mobile-menu-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .mobile-menu-content {
            position: fixed;
            top: 0;
            right: 0;
            width: 80%;
            max-width: 320px;
            height: 100vh;
            background: white;
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.1);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .mobile-menu-overlay.active .mobile-menu-content {
            transform: translateX(0);
        }

        .mobile-menu-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            background: var(--light-bg);
        }

        .mobile-menu-header .brand-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .mobile-menu-close {
            background: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s ease;
        }

        .mobile-menu-close:hover {
            color: var(--primary-color);
        }

        .mobile-nav {
            padding: 1rem 0;
        }

        .mobile-nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: var(--text-color);
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .mobile-nav-link:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--primary-color);
        }

        .mobile-nav-link.active {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
        }

        .mobile-nav-link i {
            width: 24px;
            font-size: 1.1rem;
        }

        .mobile-menu-divider {
            margin: 0.5rem 1.5rem;
            border: none;
            border-top: 1px solid #e5e7eb;
        }

        .footer {
            background: #f8fafc;
            color: #0f172a;
            padding: 4rem 0 2rem;
            margin-top: 4rem;
            border-top: 1px solid #e5e7eb;
        }

        body.home .footer {
            margin-top: 0;
        }

        .footer h5 {
            color: #0f172a;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .footer-logo {
            max-height: 48px;
            width: auto;
            object-fit: contain;
            margin-bottom: 1rem;
        }

        .footer a {
            color: #1f2937;
            text-decoration: none;
            transition: color 0.2s ease;
            font-weight: 500;
        }

        .footer a:hover {
            color: #111827;
            text-decoration: none;
        }

        .footer .list-unstyled li {
            margin-bottom: 0.5rem;
        }

        .footer .social-links a {
            font-size: 1.2rem;
            color: #0f172a;
            background: #e2e8f0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .footer .social-links a:hover {
            color: #0f172a;
            background: #cbd5f5;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-wrap: wrap;
            }

            .header-brand {
                order: 1;
            }

            .mobile-menu-toggle {
                order: 2;
                margin-left: auto;
            }

            .user-menu {
                display: none !important;
            }

            .header-content .search-form {
                order: 3;
                width: 100%;
                max-width: 100%;
                flex: none;
            }
        }

        <?= $additional_css ?? '' ?>
    </style>
</head>
<body>
    <!-- Minimalist Header -->
    <div class="header-container">
        <div class="header-main">
            <div class="container">
                <div class="header-content">
                    <a class="header-brand" href="/">
                        <?php if ($appLogo !== ''): ?>
                            <img src="<?= HtmlHelper::e($appLogo) ?>" alt="<?= HtmlHelper::e($appName) ?>" class="logo-image">
                        <?php else: ?>
                            <span class="brand-text"><?= HtmlHelper::e($appName) ?></span>
                        <?php endif; ?>
                    </a>

                    <ul class="nav-links d-none d-md-flex">
                        <li><a href="/catalogo" class="<?= strpos($_SERVER['REQUEST_URI'], '/catalogo') !== false ? 'active' : '' ?>"><?= __("Catalogo") ?></a></li>
                    </ul>

                    <!-- Mobile Menu Toggle -->
                    <button class="mobile-menu-toggle d-md-none" id="mobileMenuToggle" aria-label="Toggle menu">
                        <i class="fas fa-bars"></i>
                    </button>

                    <form class="search-form d-none d-md-block" action="/catalogo" method="get">
                        <input class="search-input" type="search" name="q" placeholder="<?= __('Cerca libri, autori...') ?>" aria-label="Search">
                    </form>

                    <div class="user-menu d-none d-md-flex">
                        <?php $isLogged = !empty($_SESSION['user'] ?? null); ?>

                        <?php if ($isLogged): ?>
                            <div class="d-flex align-items-center gap-2">
                                <a class="btn btn-outline-header" href="/prenotazioni">
                                    <i class="fas fa-bookmark"></i>
                                    <span class="d-none d-sm-inline"><?= __("Prenotazioni") ?></span>
                                    <span id="nav-res-count" class="badge-notification d-none">0</span>
                                </a>
                                <a class="btn btn-outline-header" href="/wishlist">
                                    <i class="fas fa-heart"></i>
                                    <span class="d-none d-sm-inline"><?= __("Preferiti") ?></span>
                                </a>
                                <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                                <a class="btn btn-primary-header" href="/admin/dashboard">
                                    <i class="fas fa-user-shield"></i>
                                    <span class="d-none d-md-inline"><?= __("Admin") ?></span>
                                </a>
                                <?php else: ?>
                                <a class="btn btn-primary-header" href="/profilo">
                                    <i class="fas fa-user"></i>
                                    <span class="d-none d-md-inline"><?= HtmlHelper::safe($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? 'Profilo') ?></span>
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2">
                                <a class="btn btn-outline-header" href="/login">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <span class="d-none d-sm-inline"><?= __("Accedi") ?></span>
                                </a>
                                <a class="btn btn-primary-header" href="/register">
                                    <i class="fas fa-user-plus"></i>
                                    <span class="d-none d-sm-inline"><?= __("Registrati") ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form class="search-form d-md-none w-100" action="/catalogo" method="get">
                        <input class="search-input" type="search" name="q" placeholder="<?= __('Cerca libri...') ?>" aria-label="Search">
                    </form>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Overlay -->
        <div class="mobile-menu-overlay d-md-none" id="mobileMenuOverlay">
            <div class="mobile-menu-content">
                <div class="mobile-menu-header">
                    <span class="brand-text"><?= HtmlHelper::e($appName) ?></span>
                    <button class="mobile-menu-close" id="mobileMenuClose" aria-label="Close menu">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <nav class="mobile-nav">
                    <a href="/catalogo" class="mobile-nav-link <?= strpos($_SERVER['REQUEST_URI'], '/catalogo') !== false ? 'active' : '' ?>">
                        <i class="fas fa-book me-2"></i><?= __("Catalogo") ?>
                    </a>
                    <?php if ($isLogged): ?>
                    <hr class="mobile-menu-divider">
                    <a href="/user/dashboard" class="mobile-nav-link">
                        <i class="fas fa-tachometer-alt me-2"></i><?= __("Dashboard") ?>
                    </a>
                    <a href="/prenotazioni" class="mobile-nav-link">
                        <i class="fas fa-bookmark me-2"></i><?= __("Prenotazioni") ?>
                    </a>
                    <a href="/wishlist" class="mobile-nav-link">
                        <i class="fas fa-heart me-2"></i><?= __("Preferiti") ?>
                    </a>
                    <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                    <a href="/admin/dashboard" class="mobile-nav-link">
                        <i class="fas fa-user-shield me-2"></i><?= __("Admin") ?>
                    </a>
                    <?php else: ?>
                    <a href="/profilo" class="mobile-nav-link">
                        <i class="fas fa-user me-2"></i><?= __("Profilo") ?>
                    </a>
                    <?php endif; ?>
                    <?php else: ?>
                    <hr class="mobile-menu-divider">
                    <a href="/login" class="mobile-nav-link">
                        <i class="fas fa-sign-in-alt me-2"></i><?= __("Accedi") ?>
                    </a>
                    <a href="/register" class="mobile-nav-link">
                        <i class="fas fa-user-plus me-2"></i><?= __("Registrati") ?>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="container">
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="container">
                <div class="alert alert-danger fade-in">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['error_message'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="page-container">
            <?= $content ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php if ($appLogo !== ''): ?>
                        <img src="<?= HtmlHelper::e($appLogo) ?>" alt="<?= HtmlHelper::e($appName) ?>" class="footer-logo">
                    <?php else: ?>
                        <h5><i class="fas fa-book-open me-2"></i><?= HtmlHelper::e($appName) ?></h5>
                    <?php endif; ?>
                    <p><?= HtmlHelper::e($footerDescription) ?></p>
                </div>
                <div class="col-lg-3">
                    <h5>Menu</h5>
                    <ul class="list-unstyled">
                        <li><a href="/catalogo"><?= __("Catalogo") ?></a></li>
                        <li><a href="/chi-siamo"><?= __("Chi Siamo") ?></a></li>
                        <li><a href="/contatti"><?= __("Contatti") ?></a></li>
                        <li><a href="/privacy-policy"><?= __("Privacy Policy") ?></a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5>Account</h5>
                    <ul class="list-unstyled">
                        <li><a href="/user/dashboard"><?= __("Dashboard") ?></a></li>
                        <li><a href="/profilo"><?= __("Profilo") ?></a></li>
                        <li><a href="/wishlist"><?= __("Preferiti") ?></a></li>
                        <li><a href="/prenotazioni"><?= __("Prenotazioni") ?></a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5><?= __("Seguici") ?></h5>
                    <div class="d-flex gap-3 social-links">
                        <?php if ($socialFacebook !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialFacebook) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-facebook"></i></a>
                        <?php endif; ?>
                        <?php if ($socialTwitter !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialTwitter) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-twitter"></i></a>
                        <?php endif; ?>
                        <?php if ($socialInstagram !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialInstagram) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-instagram"></i></a>
                        <?php endif; ?>
                        <?php if ($socialLinkedin !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialLinkedin) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-linkedin"></i></a>
                        <?php endif; ?>
                        <?php if ($socialBluesky !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialBluesky) ?>" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-bluesky"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="text-center">
                <p>&copy; <?= date('Y') ?> <?= HtmlHelper::e($appName) ?>. Tutti i diritti riservati.</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="/assets/vendor.bundle.js" defer></script>
    <script src="/assets/main.bundle.js" defer></script>
    <script src="/assets/js/swal-config.js" defer></script>

    <script>
    (function() {
        const searchInputs = document.querySelectorAll('.search-input');
        if (searchInputs.length === 0) {
            return;
        }

        let searchTimeout;
        let currentSearchInput = null;

        const escapeHtml = (value) => {
            if (value === undefined || value === null) {
                return '';
            }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        };

        const sanitizeUrl = (value) => {
            if (typeof value !== 'string') {
                return '#';
            }
            const trimmed = value.trim();
            if (trimmed.startsWith('javascript:')) {
                return '#';
            }
            if (trimmed.startsWith('/') || trimmed.startsWith('http')) {
                return trimmed;
            }
            return '#';
        };

        function hideSearchResults() {
            document.querySelectorAll('.search-results').forEach(container => {
                container.style.display = 'none';
            });
        }

        function performSearch(query, resultsContainer) {
            fetch('/api/search/preview?q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    displaySearchResults(data, resultsContainer);
                })
                .catch(error => {
                    console.error('Search error:', error);
                    hideSearchResults();
                });
        }

        function displaySearchResults(results, container) {
            if (!Array.isArray(results) || results.length === 0) {
                container.innerHTML = '<div class="search-no-results" style="padding: 1rem; text-align: center; color: #9ca3af;">Nessun risultato trovato</div>';
                container.style.display = 'block';
                return;
            }

            let html = '';

            const books = results.filter(r => r.type === 'book');
            const authors = results.filter(r => r.type === 'author');
            const publishers = results.filter(r => r.type === 'publisher');

            if (books.length > 0) {
                html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">Libri</h6>';
                books.forEach(book => {
                    const bookUrl = sanitizeUrl(book.url ?? '#');
                    const coverUrl = sanitizeUrl(book.cover ?? '');
                    const bookTitle = escapeHtml(book.title ?? '');
                    const bookAuthor = escapeHtml(book.author ?? '');
                    const bookYear = escapeHtml(book.year ?? '');

                    html += '<a href="' + bookUrl + '" class="search-result-item book-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: #000000; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#f9fafb\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<img src="' + coverUrl + '" alt="' + bookTitle + '" class="search-book-cover" style="width: 40px; height: 60px; object-fit: cover; border-radius: 0.25rem; margin-right: 0.75rem;">' +
                            '<div class="search-book-info">' +
                                '<div class="search-book-title" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; line-height: 1.2; color: #000000;">' + bookTitle + '</div>' +
                                (book.author ? '<div class="search-book-author" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.125rem;">' + bookAuthor + '</div>' : '') +
                                (book.year ? '<div class="search-book-year" style="font-size: 0.75rem; color: #9ca3af;">' + bookYear + '</div>' : '') +
                            '</div>' +
                        '</a>';
                });
                html += '</div>';
            }

            if (authors.length > 0) {
                html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">Autori</h6>';
                authors.forEach(author => {
                    const authorUrl = sanitizeUrl(author.url ?? '#');
                    const authorName = escapeHtml(author.name ?? '');
                    const authorBooks = escapeHtml(author.book_count ?? '0') + ' libri';
                    const authorBio = escapeHtml(author.biography ?? '');

                    html += '<a href="' + authorUrl + '" class="search-result-item author-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: #000000; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#f9fafb\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<div class="search-author-icon" style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: #6b7280;"><i class="fas fa-user"></i></div>' +
                            '<div class="search-author-info">' +
                                '<div class="search-author-name" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; color: #000000;">' + authorName + '</div>' +
                                '<div class="search-author-books" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.125rem;">' + authorBooks + '</div>' +
                                (author.biography ? '<div class="search-author-bio" style="font-size: 0.75rem; color: #9ca3af; line-height: 1.2;">' + authorBio + '</div>' : '') +
                            '</div>' +
                        '</a>';
                });
                html += '</div>';
            }

            if (publishers.length > 0) {
                html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">Editori</h6>';
                publishers.forEach(publisher => {
                    const publisherUrl = sanitizeUrl(publisher.url ?? '#');
                    const publisherName = escapeHtml(publisher.name ?? '');
                    const publisherBooks = escapeHtml(publisher.book_count ?? '0') + ' libri';
                    const publisherDesc = escapeHtml(publisher.description ?? '');

                    html += '<a href="' + publisherUrl + '" class="search-result-item publisher-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: #000000; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#f9fafb\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<div class="search-publisher-icon" style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: #6b7280;"><i class="fas fa-building"></i></div>' +
                            '<div class="search-publisher-info">' +
                                '<div class="search-publisher-name" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; color: #000000;">' + publisherName + '</div>' +
                                '<div class="search-publisher-books" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.125rem;">' + publisherBooks + '</div>' +
                                (publisher.description ? '<div class="search-publisher-desc" style="font-size: 0.75rem; color: #9ca3af; line-height: 1.2;">' + publisherDesc + '</div>' : '') +
                            '</div>' +
                        '</a>';
                });
                html += '</div>';
            }

            html += '<div class="search-section" style="padding: 0.75rem 1rem;">' +
                    '<a href="/catalogo?search=' + encodeURIComponent(currentSearchInput ? currentSearchInput.value : '') + '"' +
                       ' class="search-view-all" style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; background: #f3f4f6; border-radius: 0.375rem; text-decoration: none; color: #000000; font-weight: 500; font-size: 0.875rem; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#e5e7eb\'" onmouseout="this.style.backgroundColor=\'#f3f4f6\'">' +
                        'Vedi tutti i risultati <i class="fas fa-arrow-right" style="margin-left: 0.5rem; font-size: 0.75rem;"></i>' +
                    '</a>' +
                '</div>';

            container.innerHTML = html;
            container.style.display = 'block';
        }

        function updateSearchResultsSize() {
            const isMobile = window.innerWidth <= 768;
            document.querySelectorAll('.search-results').forEach(container => {
                container.style.left = isMobile ? '-10px' : '-20px';
                container.style.right = isMobile ? '-10px' : '-20px';
                container.style.maxHeight = isMobile ? '70vh' : '600px';
                if (!isMobile) {
                    container.style.minWidth = '500px';
                } else {
                    container.style.minWidth = '';
                }
            });
        }

        searchInputs.forEach(input => {
            const searchContainer = input.closest('.search-form');
            if (!searchContainer) {
                return;
            }

            const resultsContainer = document.createElement('div');
            resultsContainer.className = 'search-results';
            const isMobile = window.innerWidth <= 768;
            resultsContainer.style.cssText =
                'position: absolute;' +
                'top: 100%;' +
                (isMobile ? 'left: -10px; right: -10px;' : 'left: -20px; right: -20px;') +
                'background: white;' +
                'border: 1px solid #e5e7eb;' +
                'border-radius: 0.75rem;' +
                'box-shadow: none;' +
                (isMobile ? 'max-height: 70vh;' : 'max-height: 600px;') +
                'overflow-y: auto;' +
                'z-index: 1000;' +
                'display: none;' +
                (isMobile ? '' : 'min-width: 500px;');

            if (!['relative', 'absolute', 'fixed'].includes(getComputedStyle(searchContainer).position)) {
                searchContainer.style.position = 'relative';
            }
            searchContainer.appendChild(resultsContainer);

            input.addEventListener('input', function(e) {
                const query = e.target.value.trim();
                currentSearchInput = input;

                clearTimeout(searchTimeout);

                if (query.length < 2) {
                    hideSearchResults();
                    return;
                }

                searchTimeout = setTimeout(() => {
                    performSearch(query, resultsContainer);
                }, 300);
            });

            const form = input.closest('form');
            if (form) {
                form.addEventListener('submit', function() {
                    hideSearchResults();
                });
            }

            document.addEventListener('click', function(e) {
                if (!searchContainer.contains(e.target)) {
                    hideSearchResults();
                }
            });
        });

        window.addEventListener('resize', updateSearchResultsSize);
    })();

    // Keyboard shortcuts
    function initializeKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // ESC to close all popups
            if (e.key === 'Escape') {
                // Close SweetAlert2 if open
                if (window.Swal && typeof window.Swal.close === 'function') {
                    window.Swal.close();
                }

                // Blur focused input/button
                if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'BUTTON')) {
                    document.activeElement.blur();
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize keyboard shortcuts
        initializeKeyboardShortcuts();

        // Update reservations badge
        updateReservationsBadge();

        // Initialize tooltips and other Bootstrap components
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

    });

    async function updateReservationsBadge() {
        const badge = document.getElementById('nav-res-count');
        const mobileBadge = document.getElementById('mobile-res-count');

        try {
            const response = await fetch('/api/user/reservations/count');
            if (!response.ok) return;

            const data = await response.json();
            const count = parseInt(data.count || 0, 10);

            if (count > 0) {
                if (badge) {
                    badge.textContent = String(count);
                    badge.classList.remove('d-none');
                }
                if (mobileBadge) {
                    mobileBadge.textContent = String(count);
                    mobileBadge.classList.remove('d-none');
                }
            } else {
                if (badge) badge.classList.add('d-none');
                if (mobileBadge) mobileBadge.classList.add('d-none');
            }
        } catch(error) {
            console.error('Error updating reservations badge:', error);
        }
    }

    // Make updateReservationsBadge globally available
    window.updateReservationsBadge = updateReservationsBadge;
    </script>

    <!-- Mobile Menu Script -->
    <script>
        (function() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenuClose = document.getElementById('mobileMenuClose');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (mobileMenuToggle && mobileMenuClose && mobileMenuOverlay) {
                // Open menu
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenuOverlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });

                // Close menu
                mobileMenuClose.addEventListener('click', function() {
                    mobileMenuOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                });

                // Close on overlay click
                mobileMenuOverlay.addEventListener('click', function(e) {
                    if (e.target === mobileMenuOverlay) {
                        mobileMenuOverlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            }
        })();
    </script>
</body>
</html>
