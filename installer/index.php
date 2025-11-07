<?php
/**
 * Installer - Sistema Biblioteca
 * Main Router
 */

// Reset session if requested OR if starting from step 0 (language selection)
if (isset($_GET['reset']) || (!isset($_GET['step']) || $_GET['step'] == 0)) {
    session_start();
    // Only destroy if reset is explicitly requested OR if there's an old installation_complete flag
    if (isset($_GET['reset']) || isset($_SESSION['installation_complete'])) {
        session_destroy();
        session_start(); // Start a fresh session
    }
} else {
    session_start();
}

// Simple translation function for installer
function __($key) {
    static $translations = null;

    // Get locale from session (defaults to Italian)
    $locale = $_SESSION['app_locale'] ?? 'it';

    // Italian is the default - return key as-is
    if ($locale === 'it') {
        return $key;
    }

    // Load English translations only when needed
    if ($translations === null && $locale === 'en_US') {
        $translationFile = dirname(__DIR__) . '/locale/en_US.json';
        if (file_exists($translationFile)) {
            $json = file_get_contents($translationFile);
            $translations = json_decode($json, true) ?? [];
        } else {
            $translations = [];
        }
    }

    // Return translation if exists, otherwise return original key (Italian)
    return $translations[$key] ?? $key;
}

// Load helper classes
require_once __DIR__ . '/classes/Installer.php';
require_once __DIR__ . '/classes/Validator.php';

// Initialize
$baseDir = dirname(__DIR__);
$installer = new Installer($baseDir);
$validator = new Validator();

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'detect_socket') {
    header('Content-Type: application/json');

    $socketPaths = [
        '/tmp/mysql.sock',
        '/var/run/mysqld/mysqld.sock',
        '/usr/local/var/mysql/mysql.sock',
        '/opt/homebrew/var/mysql/mysql.sock'
    ];

    $detectedSocket = '';
    foreach ($socketPaths as $path) {
        if (file_exists($path)) {
            $detectedSocket = $path;
            break;
        }
    }

    echo json_encode(['socket' => $detectedSocket]);
    exit;
}

// Check if already installed
if ($installer->isInstalled() && !isset($_GET['force'])) {
    // Try to verify installation status
    $installationStatus = [];
    $installationStatus['env_exists'] = file_exists($baseDir . '/.env');
    $installationStatus['lock_exists'] = $installer->isInstalled();
    $installationStatus['db_verified'] = false;
    $installationStatus['db_error'] = null;

    // Try to verify database
    try {
        $installer->loadEnvConfig();
        $installer->verifyInstallation();
        $installationStatus['db_verified'] = true;
    } catch (Exception $e) {
        $installationStatus['db_error'] = $e->getMessage();
    }

    // If database verification failed, show detailed error
    if (!$installationStatus['db_verified']) {
        die('
            <!DOCTYPE html>
            <html>
            <head>
                <title>' . __("Errore Installazione") . '</title>
                <link rel="stylesheet" href="/installer/assets/style.css">
            </head>
            <body>
                <div class="installer-container">
                    <div class="installer-header">
                        <h1>⚠️ ' . __("Errore nella Verifica dell'Installazione") . '</h1>
                    </div>
                    <div class="installer-body">
                        <div class="alert alert-danger">
                            <strong>' . __("L'installazione non è completa o valida.") . '</strong>
                        </div>

                        <h3>' . __("Stato dell'installazione:") . '</h3>
                        <ul>
                            <li>' . __("File .env:") . ' ' . ($installationStatus['env_exists'] ? '✓ ' . __("Trovato") : '✗ ' . __("Mancante")) . '</li>
                            <li>' . __("File .installed:") . ' ' . ($installationStatus['lock_exists'] ? '✓ ' . __("Trovato") : '✗ ' . __("Mancante")) . '</li>
                            <li>' . __("Database:") . ' ' . ($installationStatus['db_verified'] ? '✓ ' . __("Verificato") : '✗ ' . __("Errore")) . '</li>
                        </ul>

                        ' . (!empty($installationStatus['db_error']) ? '<div class="alert alert-warning mt-3"><strong>' . __("Errore database:") . '</strong><br><code>' . htmlspecialchars($installationStatus['db_error']) . '</code></div>' : '') . '

                        <p class="mt-4">
                            <strong>' . __("Possibili soluzioni:") . '</strong>
                        </p>
                        <ul>
                            <li>' . __("Verifica che il database sia accessibile e configurato correttamente nel file .env") . '</li>
                            <li>' . __("Verifica che le credenziali del database nel file .env siano corrette") . '</li>
                            <li>' . __("Se hai modificato il database manualmente, elimina il file .installed (nella root del progetto) e prova di nuovo da zero") . '</li>
                        </ul>

                        <p class="text-center mt-4">
                            <a href="?force=1" class="btn btn-warning">' . __("Reinstalla da Capo") . '</a>
                            <a href="/" class="btn btn-secondary">' . __("Torna all'Applicazione") . '</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
        ');
    }

    // Installation is complete
    die('
        <!DOCTYPE html>
        <html>
        <head>
            <title>' . __("Già Installato") . '</title>
            <link rel="stylesheet" href="/installer/assets/style.css">
        </head>
        <body>
            <div class="installer-container">
                <div class="installer-header">
                    <h1>✓ ' . __("Applicazione Già Installata") . '</h1>
                </div>
                <div class="installer-body">
                    <div class="alert alert-success">
                        ' . __("L'applicazione è stata installata correttamente e tutte le verifiche sono andate a buon fine.") . '
                    </div>
                    <p class="mt-4">' . __("Se desideri reinstallare, puoi farlo in due modi:") . '</p>
                    <ol>
                        <li>' . __("Elimina il file .installed dalla root del progetto e riprova") . '</li>
                        <li>' . __("Accedi a /installer/?force=1 per forzare una reinstallazione") . '</li>
                    </ol>
                    <p class="text-center mt-4">
                        <a href="/" class="btn btn-primary">' . __("Vai all'Applicazione") . '</a>
                    </p>
                </div>
            </div>
        </body>
        </html>
    ');
}

// Get current step (start from 0 for language selection)
$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;

// Validate step progression (prevent skipping steps)
if (!isset($_SESSION['completed_steps'])) {
    $_SESSION['completed_steps'] = [];
}

// If trying to access step > 0, check if previous steps are completed
if ($step > 0 && !in_array($step - 1, $_SESSION['completed_steps'])) {
    $step = 0; // Force back to step 0 (language selection)
}

// Maximum steps
if ($step > 7) {
    $step = 7;
}

// Helper function to mark step as completed
function completeStep($stepNumber) {
    if (!in_array($stepNumber, $_SESSION['completed_steps'])) {
        $_SESSION['completed_steps'][] = $stepNumber;
    }
}

// Helper function to render header
function renderHeader($currentStep, $stepTitle) {
    $steps = [
        0 => __('Lingua'),
        1 => __('Benvenuto'),
        2 => __('Database'),
        3 => __('Installazione'),
        4 => __('Admin'),
        5 => __('Impostazioni'),
        6 => __('Email'),
        7 => __('Completato')
    ];

    $lang = $_SESSION['app_locale'] ?? 'it';
    $htmlLang = $lang === 'en_US' ? 'en' : 'it';
    ?>
    <!DOCTYPE html>
    <html lang="<?= $htmlLang ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($stepTitle) ?> - <?= __("Installer Sistema Biblioteca") ?></title>
        <link rel="stylesheet" href="/installer/assets/style.css">
        <link rel="stylesheet" href="/assets/vendor.css">
    </head>
    <body>
        <div class="installer-container">
            <div class="installer-header">
                <h1>📚 <?= __("Sistema Pinakes") ?></h1>
                <p><?= __("Installazione Guidata") ?></p>
            </div>

            <div class="installer-progress">
                <?php foreach ($steps as $num => $label): ?>
                    <div class="progress-step <?= $num === $currentStep ? 'active' : '' ?> <?= in_array($num, $_SESSION['completed_steps'] ?? []) ? 'completed' : '' ?>">
                        <?= htmlspecialchars($label) ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="installer-body">
    <?php
}

// Helper function to render footer
function renderFooter() {
    ?>
            </div>
        </div>
        <script src="/installer/assets/installer.js"></script>
    </body>
    </html>
    <?php
}

// Route to appropriate step
$stepFile = __DIR__ . "/steps/step{$step}.php";

if (file_exists($stepFile)) {
    require_once $stepFile;
} else {
    die("Step file not found: {$stepFile}");
}
