<?php
/**
 * Session Expired / CSRF Error Page
 *
 * Displayed when a form submission fails due to expired session or CSRF token.
 * Uses a standalone layout (no header/footer dependencies to avoid auth issues).
 */

$pageTitle = $pageTitle ?? __('Sessione Scaduta');
$loginUrl = $loginUrl ?? '/login';
?>
<!DOCTYPE html>
<html lang="<?= \App\Support\I18n::getLocale() === 'en_US' ? 'en' : 'it' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/main.css">
    <link rel="stylesheet" href="/assets/vendor.css">
    <style>
        .session-expired-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }

        .session-expired-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            padding: 3rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }

        .session-expired-icon {
            width: 80px;
            height: 80px;
            background: #fef3c7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }

        .session-expired-icon i {
            font-size: 2.5rem;
            color: #f59e0b;
        }

        .session-expired-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.75rem;
        }

        .session-expired-description {
            font-size: 1rem;
            color: #6b7280;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .session-expired-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .session-expired-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }

        .session-expired-btn-primary {
            background: #111827;
            color: white;
        }

        .session-expired-btn-primary:hover {
            background: #000000;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .session-expired-btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .session-expired-btn-secondary:hover {
            background: #e5e7eb;
        }

        .session-expired-info {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .session-expired-info p {
            font-size: 0.875rem;
            color: #9ca3af;
            margin: 0;
        }

        .session-expired-info i {
            color: #d1d5db;
            margin-right: 0.25rem;
        }

        @media (max-width: 480px) {
            .session-expired-card {
                padding: 2rem;
                margin: 1rem;
            }

            .session-expired-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="session-expired-container">
        <div class="session-expired-card">
            <div class="session-expired-icon">
                <i class="fas fa-clock"></i>
            </div>

            <h1 class="session-expired-title"><?= __('Sessione Scaduta') ?></h1>

            <p class="session-expired-description">
                <?= __('Per motivi di sicurezza, la tua sessione è scaduta. Effettua nuovamente l\'accesso per continuare.') ?>
            </p>

            <div class="session-expired-actions">
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="session-expired-btn session-expired-btn-primary">
                    <i class="fas fa-sign-in-alt"></i>
                    <?= __('Accedi') ?>
                </a>
                <a href="/" class="session-expired-btn session-expired-btn-secondary">
                    <i class="fas fa-home"></i>
                    <?= __('Torna alla Home') ?>
                </a>
            </div>

            <div class="session-expired-info">
                <p>
                    <i class="fas fa-shield-alt"></i>
                    <?= __('Le sessioni scadono automaticamente per proteggere i tuoi dati.') ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
