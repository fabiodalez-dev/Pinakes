<?php
/**
 * 500 Error Page - Internal Server Error
 *
 * Displayed when an unexpected server error occurs (only in production mode)
 * Uses frontend layout with header and footer for consistency
 */

$pageTitle = '500 - Errore del Server';
$metaDescription = 'Si è verificato un errore imprevisto.';

ob_start();
?>

<style>
.error-500-container {
    min-height: calc(100vh - 400px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 3rem 1rem;
}

.error-500-content {
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
}

.error-500-number {
    font-size: 8rem;
    font-weight: 800;
    color: #111827;
    line-height: 1;
    margin-bottom: 1rem;
    opacity: 0.1;
}

.error-500-icon {
    font-size: 4rem;
    color: #dc2626;
    margin-bottom: 1.5rem;
}

.error-500-title {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 1rem;
}

.error-500-description {
    font-size: 1.125rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
    line-height: 1.6;
}

.error-500-subdescription {
    font-size: 1rem;
    color: #9ca3af;
    margin-bottom: 2.5rem;
    line-height: 1.6;
}

.error-500-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-bottom: 3rem;
    flex-wrap: wrap;
}

.error-500-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1.75rem;
    font-size: 1rem;
    font-weight: 600;
    text-decoration: none;
    border-radius: 999px;
    transition: all 0.2s ease;
    cursor: pointer;
    border: none;
}

.error-500-btn-primary {
    background: #111827;
    color: white;
}

.error-500-btn-primary:hover {
    background: #000000;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.error-500-btn-secondary {
    background: #f8fafc;
    color: #111827;
    border: 1px solid #e2e8f0;
}

.error-500-btn-secondary:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.error-500-info {
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 2rem;
    display: flex;
    align-items: start;
    gap: 0.75rem;
    text-align: left;
}

.error-500-info i {
    color: #f59e0b;
    font-size: 1.25rem;
    flex-shrink: 0;
    margin-top: 0.125rem;
}

.error-500-info-text {
    font-size: 0.9375rem;
    color: #92400e;
    line-height: 1.5;
}

.error-500-links {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    max-width: 500px;
    margin: 0 auto;
}

.error-500-link {
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

.error-500-link:hover {
    background: #f8fafc;
    border-color: #111827;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.error-500-link i {
    font-size: 1.5rem;
    color: #111827;
}

.error-500-link span {
    font-size: 0.875rem;
    font-weight: 500;
}

@media (max-width: 640px) {
    .error-500-number {
        font-size: 5rem;
    }

    .error-500-icon {
        font-size: 3rem;
    }

    .error-500-title {
        font-size: 1.5rem;
    }

    .error-500-description {
        font-size: 1rem;
    }

    .error-500-subdescription {
        font-size: 0.9375rem;
    }

    .error-500-actions {
        flex-direction: column;
        width: 100%;
    }

    .error-500-btn {
        width: 100%;
        justify-content: center;
    }

    .error-500-links {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="error-500-container">
    <div class="error-500-content">
        <div class="error-500-number">500</div>

        <div class="error-500-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>

        <h1 class="error-500-title">Ops, qualcosa è andato storto</h1>

        <p class="error-500-description">
            Si è verificato un errore imprevisto sul server.
        </p>

        <p class="error-500-subdescription">
            Il nostro team è stato notificato e sta lavorando per risolvere il problema.
        </p>

        <div class="error-500-info">
            <i class="fas fa-lightbulb"></i>
            <div class="error-500-info-text">
                <strong>Cosa puoi fare:</strong><br>
                Prova a ricaricare la pagina o torna alla home. Se il problema persiste, riprova tra qualche minuto.
            </div>
        </div>

        <div class="error-500-actions">
            <button onclick="location.reload()" class="error-500-btn error-500-btn-secondary">
                <i class="fas fa-sync-alt"></i>
                Ricarica Pagina
            </button>
            <a href="/" class="error-500-btn error-500-btn-primary">
                <i class="fas fa-home"></i>
                Vai alla Home
            </a>
        </div>

        <div class="error-500-links">
            <a href="/catalogo" class="error-500-link">
                <i class="fas fa-book"></i>
                <span>Catalogo</span>
            </a>
            <a href="/contatti" class="error-500-link">
                <i class="fas fa-envelope"></i>
                <span>Contatti</span>
            </a>
            <a href="/supporto" class="error-500-link">
                <i class="fas fa-question-circle"></i>
                <span>Supporto</span>
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../frontend/layout.php';
