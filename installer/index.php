<?php
/**
 * Installer - Pinakes
 * Main Router
 */

// Session management — use same save path as main app to avoid cookie conflicts
$sessionPath = dirname(__DIR__) . '/storage/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0775, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    ini_set('session.save_path', $sessionPath);
}

session_start();

// Only destroy session on explicit reset request
if (isset($_GET['reset'])) {
    session_destroy();
    session_start(); // Start a fresh session
}

// Normalize locale to canonical form (it_IT, en_US, de_DE)
function normalizeInstallerLocale(string $locale): string {
    $locale = str_replace('-', '_', strtolower($locale));
    return match($locale) {
        'en', 'en_us' => 'en_US',
        'de', 'de_de' => 'de_DE',
        default => 'it_IT',
    };
}

// Simple translation function for installer
function __(string $key, mixed ...$args): string {
    static $translations = null;

    // Get locale from session (defaults to Italian)
    $locale = $_SESSION['app_locale'] ?? 'it';

    $message = $key;

    // Load translations when not Italian (Italian strings are the keys themselves)
    if ($locale !== 'it' && $locale !== 'it_IT') {
        if ($translations === null) {
            $localeCode = normalizeInstallerLocale((string)$locale);
            $translationFile = dirname(__DIR__) . '/locale/' . $localeCode . '.json';
            if (file_exists($translationFile)) {
                $json = file_get_contents($translationFile);
                $translations = json_decode($json, true) ?? [];
            } else {
                $translations = [];
            }
        }
        $message = $translations[$key] ?? $key;
    }

    // Apply sprintf formatting if args provided
    if (!empty($args)) {
        return sprintf($message, ...$args);
    }

    return $message;
}

// Load helper classes
require_once __DIR__ . '/classes/Installer.php';
require_once __DIR__ . '/classes/Validator.php';

// Initialize
$baseDir = dirname(__DIR__);
$installer = new Installer($baseDir);
$validator = new Validator();

// Base path detection for subfolder installations
$installerBasePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
if ($installerBasePath === '.' || $installerBasePath === DIRECTORY_SEPARATOR) $installerBasePath = '';
$installerBasePath = htmlspecialchars($installerBasePath, ENT_QUOTES, 'UTF-8');

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

// Security: If force parameter is used, require admin authentication
if (isset($_GET['force']) && $installer->isInstalled()) {
    // Load .env to get database credentials
    $envFile = $baseDir . '/.env';
    if (file_exists($envFile)) {
        $envContent = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($envContent as $line) {
            if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
        }
    }

    $forceAuthenticated = false;

    // Check if admin session exists from main app
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        $forceAuthenticated = true;
    }

    // Allow login via POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_email'], $_POST['admin_password'])) {
        $email = trim($_POST['admin_email']);
        $password = $_POST['admin_password'];

        // Connect to database and verify admin credentials
        try {
            $dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $dbUser = $_ENV['DB_USER'] ?? '';
            $dbPass = $_ENV['DB_PASS'] ?? '';
            $dbName = $_ENV['DB_NAME'] ?? '';
            $dbPort = $_ENV['DB_PORT'] ?? '3306';

            $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int)$dbPort);
            if (!$mysqli->connect_error) {
                $stmt = $mysqli->prepare("SELECT id, password FROM utenti WHERE email = ? AND ruolo = 'admin' LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        if (password_verify($password, $row['password'])) {
                            $forceAuthenticated = true;
                            $_SESSION['installer_admin_verified'] = true;
                            $_SESSION['user_id'] = $row['id'];
                            $_SESSION['user_role'] = 'admin';
                        }
                    }
                    $stmt->close();
                }
                $mysqli->close();
            }
        } catch (Exception $e) {
            // Silently fail - will show login form
        }
    }

    // Check if previously authenticated in this session
    if (isset($_SESSION['installer_admin_verified']) && $_SESSION['installer_admin_verified'] === true) {
        $forceAuthenticated = true;
    }

    // If not authenticated, show login form
    if (!$forceAuthenticated) {
        $loginError = ($_SERVER['REQUEST_METHOD'] === 'POST') ? __('Credenziali non valide o utente non admin') : '';
        die('
            <!DOCTYPE html>
            <html>
            <head>
                <title>' . __("Autenticazione Richiesta") . '</title>
                <link rel="stylesheet" href="' . $installerBasePath . '/assets/vendor.css">
                <link rel="stylesheet" href="' . $installerBasePath . '/installer/assets/style.css">
            </head>
            <body>
                <div class="installer-container">
                    <div class="installer-header">
                        <h1>' . __("Autenticazione Admin Richiesta") . '</h1>
                        <p>' . __("Per reinstallare l'applicazione è necessario autenticarsi come amministratore.") . '</p>
                    </div>
                    <div class="installer-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-shield-alt"></i>
                            ' . __("Questa operazione cancellerà tutti i dati esistenti. Assicurati di avere un backup.") . '
                        </div>
                        ' . ($loginError ? '<div class="alert alert-danger">' . htmlspecialchars($loginError) . '</div>' : '') . '
                        <form method="POST" action="' . $installerBasePath . '/installer/?force=1">
                            <div class="form-group">
                                <label for="admin_email">' . __("Email Admin") . '</label>
                                <input type="email" name="admin_email" id="admin_email" class="form-control" required placeholder="admin@example.com">
                            </div>
                            <div class="form-group">
                                <label for="admin_password">' . __("Password") . '</label>
                                <input type="password" name="admin_password" id="admin_password" class="form-control" required>
                            </div>
                            <div class="form-group text-center mt-4">
                                <button type="submit" class="btn btn-warning">' . __("Accedi e Procedi") . '</button>
                                <a href="' . $installerBasePath . '/" class="btn btn-secondary">' . __("Annulla") . '</a>
                            </div>
                        </form>
                    </div>
                </div>
            </body>
            </html>
        ');
    }
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
                <link rel="stylesheet" href="' . $installerBasePath . '/assets/vendor.css">
                <link rel="stylesheet" href="' . $installerBasePath . '/installer/assets/style.css">
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
                            <a href="' . $installerBasePath . '/installer/?force=1" class="btn btn-warning">' . __("Reinstalla da Capo") . '</a>
                            <a href="' . $installerBasePath . '/" class="btn btn-secondary">' . __("Torna all'Applicazione") . '</a>
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
            <link rel="stylesheet" href="' . $installerBasePath . '/assets/vendor.css">
            <link rel="stylesheet" href="' . $installerBasePath . '/installer/assets/style.css">
        </head>
        <body>
            <div class="installer-container">
                <div class="installer-header">
                    <h1>' . __("Applicazione già installata") . '</h1>
                    <p>' . __("L'applicazione risulta correttamente configurata.") . '</p>
                </div>
                <div class="installer-body">
                    <div class="alert alert-success">
                        ' . __("L'installazione è stata completata correttamente e tutte le verifiche sono andate a buon fine.") . '
                    </div>
                    <p class="mt-4">' . __("Se desideri reinstallare puoi:") . '</p>
                    <ol>
                        <li>' . __("Eliminare il file .installed dalla root del progetto e rieseguire l'installer") . '</li>
                        <li>' . __("Oppure accedere a /installer/?force=1 per forzare una nuova procedura") . '</li>
                    </ol>
                    <p class="text-center mt-4">
                        <a href="' . $installerBasePath . '/" class="btn btn-primary">' . __("Vai all'applicazione") . '</a>
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
    global $installerBasePath;
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

    $localeCode = normalizeInstallerLocale((string)($_SESSION['app_locale'] ?? 'it'));
    $htmlLang = match($localeCode) {
        'en_US' => 'en',
        'de_DE' => 'de',
        default => 'it',
    };
    $versionFile = dirname(__DIR__) . '/version.json';
    $versionData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : null;
    $appVersion = $versionData['version'] ?? '0.1.0';
    $completedSteps = $_SESSION['completed_steps'] ?? [];
    ?>
    <!DOCTYPE html>
    <html lang="<?= $htmlLang ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($stepTitle) ?> - <?= __("Installer Pinakes") ?></title>
        <link rel="stylesheet" href="<?= $installerBasePath ?>/assets/vendor.css">
        <link rel="stylesheet" href="<?= $installerBasePath ?>/installer/assets/style.css">
    </head>
    <body>
        <div class="installer-container">
            <div class="installer-hero">
                <div class="brand-lockup">
                    <img src="<?= $installerBasePath ?>/assets/brand/logo.png" alt="Pinakes logo" width="90" height="90" loading="lazy">
                    <div class="brand-copy">
                        <p class="brand-title">Pinakes</p>
                        <p class="brand-subtitle"><?= __("Library Management System") ?></p>
                    </div>
                </div>
                <div class="hero-version">v<?= htmlspecialchars($appVersion) ?></div>
            </div>

            <div class="installer-header">
                <div class="step-chip">
                    <?= __("Passo") ?> <?= str_pad((string)($currentStep + 1), 2, '0', STR_PAD_LEFT) ?> /
                    <?= str_pad((string)count($steps), 2, '0', STR_PAD_LEFT) ?>
                </div>
                <h1><?= htmlspecialchars($stepTitle) ?></h1>
                <p><?= __("Segui l'installazione guidata per completare la configurazione.") ?></p>
            </div>

            <div class="installer-progress" data-current="<?= $currentStep ?>">
                <?php foreach ($steps as $num => $label): ?>
                    <?php
                        $isActive = $num === $currentStep;
                        $isCompleted = in_array($num, $completedSteps, true);
                    ?>
                    <div class="progress-step <?= $isActive ? 'active' : '' ?> <?= $isCompleted ? 'completed' : '' ?>" data-step="<?= $num ?>">
                        <div class="step-node"><?= str_pad((string)($num + 1), 2, '0', STR_PAD_LEFT) ?></div>
                        <div class="step-label"><?= htmlspecialchars($label) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <script>
            (function(){
                var c = document.querySelector('.installer-progress');
                if (!c) return;
                var cur = parseInt(c.dataset.current, 10);
                var steps = c.querySelectorAll('.progress-step');
                steps.forEach(function(s) {
                    var n = parseInt(s.dataset.step, 10);
                    if (n === cur - 1 || n === cur + 1) s.classList.add('adjacent');
                });
            })();
            </script>

            <div class="installer-body">
    <?php
}

// Helper function to render footer
function renderFooter() {
    global $installerBasePath;
    ?>
            </div>
        </div>
        <script src="<?= $installerBasePath ?>/installer/assets/installer.js"></script>
    </body>
    </html>
    <?php
}

// Route to appropriate step
$stepFile = __DIR__ . "/steps/step{$step}.php";

if (file_exists($stepFile)) {
    require_once $stepFile;
} else {
    die("Step file not found for step: {$step}");
}
