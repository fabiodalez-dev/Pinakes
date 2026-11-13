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

// Set secure session cookie params
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    ),
]);

session_start();

// Only destroy session on explicit reset request
if (isset($_GET['reset'])) {
    session_destroy();
    session_start(); // Start a fresh session
}

// Normalize locale to canonical form (it_IT, en_US, de_DE, fr_FR, da_DK)
function normalizeInstallerLocale(string $locale): string {
    $locale = str_replace('-', '_', strtolower($locale));
    return match($locale) {
        'en', 'en_us' => 'en_US',
        'de', 'de_de' => 'de_DE',
        'fr', 'fr_fr' => 'fr_FR',
        'da', 'da_dk' => 'da_DK',
        default => 'it_IT',
    };
}

// Simple translation function for installer
function __(string $key, mixed ...$args): string {
    static $translationsByLocale = [];

    // Get locale from session (defaults to Italian)
    $locale = $_SESSION['app_locale'] ?? 'it';

    $message = $key;

    // Load translations when not Italian (Italian strings are the keys themselves)
    if ($locale !== 'it' && $locale !== 'it_IT') {
        $localeCode = normalizeInstallerLocale((string)$locale);
        if (!isset($translationsByLocale[$localeCode])) {
            $translationFile = dirname(__DIR__) . '/locale/' . $localeCode . '.json';
            if (file_exists($translationFile)) {
                $json = file_get_contents($translationFile);
                $translationsByLocale[$localeCode] = json_decode($json, true) ?? [];
            } else {
                $translationsByLocale[$localeCode] = [];
            }
        }
        $message = $translationsByLocale[$localeCode][$key] ?? $key;
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

// Ensure the root .htaccess exists BEFORE rendering any installer page. When the
// document root is the project root (a very common cPanel setup) the installer's
// own assets — /assets/vendor.css (Font Awesome icons) and /assets/brand/logo.png
// — live under public/assets and only resolve once the routing rewrite exists.
// cPanel's File Manager hides dotfiles, so the packaged root .htaccess is often
// lost during extraction; writing it here (PHP, on disk) makes the installer
// render correctly and self-heals routing for the installed app. Idempotent and
// best-effort: a non-writable root simply leaves things as they are.
try {
    $installer->createHtaccess();
} catch (\Throwable $e) {
    // Non-fatal: the install can still proceed; routing may need a manual .htaccess.
}

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

// Security: If force parameter is used, require admin authentication.
//
// From step 2 the installer rewrites .env, which repoints the live application,
// so this door must be held by a CURRENTLY active admin — checked against the
// database on EVERY request, with the same rules the main app applies (role
// admin, stato 'attivo', verified email; a bound user_sessions row that is
// deleted, revoked or expired ends the session). The installer does not load
// the app autoloader, so those checks are inlined (Installer::isActiveAdmin(),
// Installer::isBoundSessionRevoked()). Any failure fails closed.
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

    $openInstallerDb = static function (): ?mysqli {
        $dbSocket = trim((string) ($_ENV['DB_SOCKET'] ?? ''));
        $mysqli = new mysqli(
            $dbSocket !== '' ? 'localhost' : (string) ($_ENV['DB_HOST'] ?? '127.0.0.1'),
            (string) ($_ENV['DB_USER'] ?? ''),
            (string) ($_ENV['DB_PASS'] ?? ''),
            (string) ($_ENV['DB_NAME'] ?? ''),
            $dbSocket !== '' ? 0 : (int) ($_ENV['DB_PORT'] ?? 3306),
            $dbSocket !== '' ? $dbSocket : null
        );
        if ($mysqli->connect_error) {
            return null;
        }
        return $mysqli;
    };

    $forceAuthenticated = false;

    // The pre-fix grant was a sticky boolean that outlived demotion, suspension
    // and deletion of the account: never honour it again.
    unset($_SESSION['installer_admin_verified']);

    // The session that wrote .env for the reinstall in progress (it was already
    // authenticated here when it did) keeps going: the new .env may point at an
    // empty database where no admin exists yet.
    if ($installer->ownsInstallInProgress()) {
        $forceAuthenticated = true;
    } else {
        try {
            $db = $openInstallerDb();
            if ($db !== null) {
                try {
                    // (a) A main-app session: re-read the account and its bound sign-in row.
                    $sessionUserId = (int) ($_SESSION['user']['id'] ?? 0);
                    $boundRowId = (int) ($_SESSION['remember_session_id'] ?? 0);
                    if ($sessionUserId > 0
                        && Installer::isActiveAdmin($db, $sessionUserId)
                        && !Installer::isBoundSessionRevoked($db, $boundRowId)) {
                        $forceAuthenticated = true;
                    }
                    // (b) An earlier installer login: re-check the stored account every time.
                    $installerUserId = (int) ($_SESSION['installer_admin_user_id'] ?? 0);
                    if (!$forceAuthenticated && $installerUserId > 0) {
                        if (Installer::isActiveAdmin($db, $installerUserId)) {
                            $forceAuthenticated = true;
                        } else {
                            unset($_SESSION['installer_admin_user_id']);
                        }
                    }
                } finally {
                    $db->close();
                }
            }
        } catch (\Throwable $e) {
            $forceAuthenticated = false;
        }
    }

    // CSRF token for installer force-auth form
    if (!isset($_SESSION['installer_csrf_token'])) {
        $_SESSION['installer_csrf_token'] = bin2hex(random_bytes(32));
    }

    // Allow login via POST
    $csrfFailed = false;
    $rateLimited = false;
    if (!$forceAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_email'], $_POST['admin_password'])) {
        // Validate CSRF token
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        $sessionToken = (string) ($_SESSION['installer_csrf_token'] ?? '');
        if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            $csrfFailed = true;
        } elseif (!$installer->recordForceLoginAttempt((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            // Same budget as the main POST /login (15 attempts / 5 minutes per IP).
            // Also refused when the attempt cannot be recorded: fail closed.
            $rateLimited = true;
        } else {
            $email = trim((string) $_POST['admin_email']);
            $password = (string) $_POST['admin_password'];

            // Connect to database and verify admin credentials
            try {
                $mysqli = $openInstallerDb();
                if ($mysqli !== null) {
                    try {
                        // Same account-state rules as the main login: active and verified.
                        $stmt = $mysqli->prepare("SELECT id, password FROM utenti WHERE email = ? AND tipo_utente = 'admin' AND stato = 'attivo' AND email_verificata = 1 LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param('s', $email);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $row = $result ? $result->fetch_assoc() : null;
                            $stmt->close();
                            if (is_array($row) && password_verify($password, (string) $row['password'])) {
                                session_regenerate_id(true);
                                $forceAuthenticated = true;
                                // An account id, re-validated on every request above;
                                // never a standing yes.
                                $_SESSION['installer_admin_user_id'] = (int) $row['id'];
                            }
                        }
                    } finally {
                        $mysqli->close();
                    }
                }
            } catch (\Throwable $e) {
                // Fail closed - will show login form
                $forceAuthenticated = false;
            }
        }

        // Regenerate CSRF token after each POST attempt
        $_SESSION['installer_csrf_token'] = bin2hex(random_bytes(32));
    }

    // If not authenticated, show login form
    if (!$forceAuthenticated) {
        $errorHtml = '';
        if ($rateLimited) {
            http_response_code(429);
            $errorHtml = '<div class="alert alert-danger">' . htmlspecialchars(__('Troppi tentativi di accesso. Riprova tra qualche minuto.')) . '</div>';
        } elseif ($csrfFailed) {
            $errorHtml = '<div class="alert alert-danger">' . htmlspecialchars(__('Token CSRF non valido. Riprova.')) . '</div>';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $errorHtml = '<div class="alert alert-danger">' . htmlspecialchars(__('Credenziali non valide o utente non admin')) . '</div>';
        }
        $csrfToken = htmlspecialchars($_SESSION['installer_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
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
                        ' . $errorHtml . '
                        <form method="POST" action="' . $installerBasePath . '/installer/?force=1">
                            <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
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
    $installationStatus['lock_exists'] = $installer->hasLockFile();
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
                            <li>' . __("Per ripetere l'installazione usa «Reinstalla da Capo» (richiede le credenziali di un amministratore attivo) oppure, con accesso ai file del server, elimina sia .env sia .installed dalla root del progetto") . '</li>
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
                        <li>' . __("Accedere a /installer/?force=1 con le credenziali di un amministratore attivo") . '</li>
                        <li>' . __("Oppure, con accesso ai file del server, eliminare sia .env sia .installed dalla root del progetto e rieseguire l'installer") . '</li>
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
        'fr_FR' => 'fr',
        'da_DK' => 'da',
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
