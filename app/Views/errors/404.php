<?php
/**
 * 404 Error Page - Page Not Found
 *
 * Displayed when user visits a non-existent URL
 * Uses frontend layout with header and footer for consistency
 */

$pageTitle = '404 - ' . __('Pagina Non Trovata');
$metaDescription = __('La pagina che stai cercando non esiste.');
$requestedPath ??= $_SERVER['REQUEST_URI'] ?? '';
$catalogRoute = route_path('catalog');
$wishlistRoute = route_path('wishlist');
$reservationsRoute = route_path('reservations');

ob_start();
?>

<style>
.error-404-container {
    min-height: calc(100vh - 400px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 3rem 1rem;
}

.error-404-content {
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
}

.error-404-number {
    font-size: 8rem;
    font-weight: 800;
    color: #111827;
    line-height: 1;
    margin-bottom: 1rem;
    opacity: 0.1;
}

.error-404-icon {
    font-size: 4rem;
    color: #111827;
    margin-bottom: 1.5rem;
}

.error-404-title {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 1rem;
}

.error-404-description {
    font-size: 1.125rem;
    color: #6b7280;
    margin-bottom: 2rem;
    line-height: 1.6;
}

.error-404-path {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 2rem;
    font-size: 0.875rem;
}

.error-404-path code {
    background: #fff;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-family: 'Courier New', Consolas, monospace;
    color: #ef4444;
}

.error-404-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-bottom: 3rem;
    flex-wrap: wrap;
}

.error-404-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1.75rem;
    font-size: 1rem;
    font-weight: 600;
    text-decoration: none;
    border-radius: 999px;
    transition: all 0.2s ease;
}

.error-404-btn-primary {
    background: #111827;
    color: white;
}

.error-404-btn-primary:hover {
    background: #000000;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.error-404-btn-secondary {
    background: #f8fafc;
    color: #111827;
    border: 1px solid #e2e8f0;
}

.error-404-btn-secondary:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.error-404-links {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    max-width: 500px;
    margin: 0 auto;
}

.error-404-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1.25rem;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    text-decoration: none;
    color: #111827;
    transition: all 0.2s ease;
}

.error-404-link:hover {
    background: #f8fafc;
    border-color: #111827;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.error-404-link i {
    font-size: 1.5rem;
    color: #111827;
}

.error-404-link span {
    font-size: 0.875rem;
    font-weight: 500;
}

@media (max-width: 640px) {
    .error-404-number {
        font-size: 5rem;
    }

    .error-404-icon {
        font-size: 3rem;
    }

    .error-404-title {
        font-size: 1.5rem;
    }

    .error-404-description {
        font-size: 1rem;
    }

    .error-404-actions {
        flex-direction: column;
        width: 100%;
    }

    .error-404-btn {
        width: 100%;
        justify-content: center;
    }

    .error-404-links {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="error-404-container">
    <div class="error-404-content">
        <div class="error-404-number">404</div>

        <div class="error-404-icon">
            <i class="fas fa-search"></i>
        </div>

        <h1 class="error-404-title"><?= __('Pagina Non Trovata') ?></h1>

        <p class="error-404-description">
            <?= __('La pagina che stai cercando non esiste o è stata spostata.') ?>
        </p>

        <?php if (!empty($requestedPath) && $requestedPath !== '/'): ?>
        <div class="error-404-path">
            <strong><?= __("Percorso richiesto:") ?></strong>
            <code><?php echo htmlspecialchars($requestedPath, ENT_QUOTES, 'UTF-8'); ?></code>
        </div>
        <?php endif; ?>

        <div class="error-404-actions">
            <button onclick="history.back()" class="error-404-btn error-404-btn-secondary">
                <i class="fas fa-arrow-left"></i>
                <?= __('Torna Indietro') ?>
            </button>
            <a href="/" class="error-404-btn error-404-btn-primary">
                <i class="fas fa-home"></i>
                <?= __('Vai alla Home') ?>
            </a>
        </div>

        <div class="error-404-links">
            <a href="<?= $catalogRoute ?>" class="error-404-link">
                <i class="fas fa-book"></i>
                <span><?= __('Catalogo') ?></span>
            </a>
            <a href="<?= $wishlistRoute ?>" class="error-404-link">
                <i class="fas fa-heart"></i>
                <span><?= __('Preferiti') ?></span>
            </a>
            <a href="<?= $reservationsRoute ?>" class="error-404-link">
                <i class="fas fa-bookmark"></i>
                <span><?= __('Prenotazioni') ?></span>
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../frontend/layout.php';
