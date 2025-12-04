<?php
declare(strict_types=1);

// Check if application is installed BEFORE loading anything
$envFile = __DIR__ . '/../.env';
$installerLockFile = __DIR__ . '/../.installed';

// If .env doesn't exist AND installer hasn't been completed, redirect to installer
if (!file_exists($envFile) && !file_exists($installerLockFile)) {
    header('Location: /installer/', true, 302);
    exit;
}

// Check if composer dependencies are installed
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dipendenze PHP Mancanti</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f7fafc; margin: 0; padding: 20px; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .container { background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 600px; padding: 40px; }
        h1 { color: #dc2626; margin-top: 0; }
        pre { background: #1f2937; color: #fff; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .info { color: #4b5563; line-height: 1.6; }
        code { background: #e5e7eb; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚠️ Dipendenze PHP Mancanti</h1>
        <p class="info">
            L\'applicazione non può avviarsi perché mancano le librerie PHP necessarie.
        </p>

        <div class="warning">
            <strong>Azione richiesta:</strong> Devi installare le dipendenze con Composer.
        </div>

        <h2>Cosa fare:</h2>
        <ol class="info">
            <li>Collegati al server via SSH</li>
            <li>Vai nella directory dell\'applicazione:
                <pre>cd ' . htmlspecialchars(dirname(__DIR__), ENT_QUOTES, 'UTF-8') . '</pre>
            </li>
            <li>Installa le dipendenze:
                <pre>composer install --no-dev --optimize-autoloader</pre>
            </li>
            <li>Verifica che la cartella <code>vendor/</code> sia stata creata</li>
            <li>Ricarica questa pagina</li>
        </ol>

        <h2>Non hai Composer installato?</h2>
        <p class="info">
            Scarica Composer da <a href="https://getcomposer.org/" target="_blank">getcomposer.org</a>
            oppure usa:
        </p>
        <pre>curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev --optimize-autoloader</pre>

        <p class="info" style="margin-top: 30px; font-size: 14px; color: #6b7280;">
            Se hai bisogno di assistenza, contatta il tuo provider di hosting.
        </p>
    </div>
</body>
</html>';
    exit;
}

require $vendorAutoload;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
try {
    $dotenv->load();
} catch (Exception $e) {
    error_log("Error loading .env file: " . $e->getMessage());
    // If .env failed to load and installer exists, redirect there
    if (is_dir(__DIR__ . '/../installer') && !file_exists($installerLockFile)) {
        header('Location: /installer/', true, 302);
        exit;
    }
}

$httpsDetected = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

// Secure session configuration
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Configure secure session parameters
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $httpsDetected ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1'); // Previene session fixation
    ini_set('session.cookie_lifetime', '0'); // Session cookies only
    ini_set('session.gc_maxlifetime', '3600'); // Timeout sessione: 1 ora
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    session_start();

    // Regenera session ID periodicamente per prevenire session hijacking
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // Ogni 5 minuti
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Enforce HTTPS in production environments (only if server supports HTTPS)
$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
if (!$isCli && !$httpsDetected) {
    // Check settings from database (ConfigStore) or fallback to ENV
    $forceHttpsFromDb = \App\Support\ConfigStore::get('advanced.force_https', false);
    $forceHttpsFromEnv = getenv('FORCE_HTTPS') === 'true';
    $forceHttps = $forceHttpsFromDb || (getenv('APP_ENV') === 'production' && $forceHttpsFromEnv);

    if ($forceHttps) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $host . $requestUri, true, 301);
        exit;
    }
}

// Set HSTS header if enabled and using HTTPS
if (!$isCli && $httpsDetected) {
    $enableHstsFromDb = \App\Support\ConfigStore::get('advanced.enable_hsts', false);
    if ($enableHstsFromDb) {
        // HSTS: max-age=1 year (31536000 seconds), includeSubDomains
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains', false);
    }
}

// Enforce canonical host if configured
if (!$isCli) {
    $canonicalUrl = getenv('APP_CANONICAL_URL') ?: ($_ENV['APP_CANONICAL_URL'] ?? '');
    if ($canonicalUrl !== '' && isset($_SERVER['HTTP_HOST'])) {
        $canonicalParts = parse_url($canonicalUrl);
        if ($canonicalParts !== false && isset($canonicalParts['host'])) {
            $canonicalScheme = strtolower($canonicalParts['scheme'] ?? ($httpsDetected ? 'https' : 'http'));
            $canonicalHostOriginal = $canonicalParts['host'];
            $canonicalHost = strtolower($canonicalHostOriginal);
            $canonicalPort = isset($canonicalParts['port']) ? (int)$canonicalParts['port'] : null;

            $requestHostRaw = strtolower((string)$_SERVER['HTTP_HOST']);
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $requestScheme = $httpsDetected ? 'https' : (isset($_SERVER['REQUEST_SCHEME']) ? strtolower((string)$_SERVER['REQUEST_SCHEME']) : 'http');

            $requestHost = $requestHostRaw;
            $requestPort = null;
            if (str_contains($requestHostRaw, ':')) {
                [$requestHost, $portPart] = explode(':', $requestHostRaw, 2);
                $requestHost = strtolower($requestHost);
                $requestPort = is_numeric($portPart) ? (int)$portPart : null;
            } elseif (isset($_SERVER['SERVER_PORT']) && is_numeric((string)$_SERVER['SERVER_PORT'])) {
                $requestPort = (int)$_SERVER['SERVER_PORT'];
            }

            // Skip canonical redirect for API endpoints (needed for interoperability)
            $isApiRequest = strpos($requestUri, '/api/') === 0;

            $needsRedirect = false;
            if (!$isApiRequest && $requestHost !== $canonicalHost) {
                $needsRedirect = true;
            }

            if (!$isApiRequest && !$needsRedirect && $canonicalPort !== null && $requestPort !== $canonicalPort) {
                $needsRedirect = true;
            }

            // Only redirect for scheme mismatch if canonical is HTTPS and request is HTTP
            // Do NOT redirect if canonical is HTTP but request is HTTPS (server is forcing HTTPS)
            // This prevents redirect loops when server auto-upgrades to HTTPS
            if (
                !$isApiRequest
                && !$needsRedirect
                && $canonicalScheme !== ''
                && $requestScheme !== ''
                && $canonicalScheme !== $requestScheme
                && $canonicalScheme === 'https' // Only redirect TO https, never FROM https
            ) {
                $needsRedirect = true;
            }

            if ($needsRedirect) {
                $defaultPorts = ['http' => 80, 'https' => 443];
                $targetHost = $canonicalHostOriginal;

                // Use request scheme if server is forcing HTTPS (canonical is http but request is https)
                // This prevents redirect loops
                $targetScheme = ($canonicalScheme === 'http' && $requestScheme === 'https')
                    ? 'https'
                    : $canonicalScheme;

                if ($canonicalPort !== null) {
                    $defaultPort = $defaultPorts[$targetScheme] ?? null;
                    if ($defaultPort === null || $canonicalPort !== $defaultPort) {
                        $targetHost .= ':' . $canonicalPort;
                    }
                } elseif ($requestPort !== null) {
                    $defaultPort = $defaultPorts[$targetScheme] ?? null;
                    if ($defaultPort !== null && $requestPort !== $defaultPort) {
                        $targetHost .= ':' . $requestPort;
                    }
                }

                $targetUrl = $targetScheme . '://' . $targetHost . $requestUri;
                header('Location: ' . $targetUrl, true, 301);
                exit;
            }
        }
    }
}

// Container
$containerBuilder = new ContainerBuilder();
// Settings & services
require __DIR__ . '/../config/settings.php';
require __DIR__ . '/../config/container.php';
$containerBuilder->addDefinitions($containerDefinitions ?? []);
$container = $containerBuilder->build();
AppFactory::setContainer($container);

// Load available languages from database and restore session locale
if (!$isCli) {
    try {
        $db = $container->get('db');
        \App\Support\I18n::loadFromDatabase($db);
    } catch (Exception $e) {
        // Fallback to hardcoded locales if database query fails
        // This prevents errors during installation or if languages table doesn't exist yet
        error_log("Failed to load languages from database: " . $e->getMessage());
    }

    if (!empty($_SESSION['locale'])) {
        $sessionLocale = (string)$_SESSION['locale'];
        if (!\App\Support\I18n::setLocale($sessionLocale)) {
            unset($_SESSION['locale']);
        }
    }
}

// Initialize Hook System
\App\Support\Hooks::init($container->get('hookManager'));

// Make HookManager globally accessible for helper functions (do_action, apply_filters)
$GLOBALS['hookManager'] = $container->get('hookManager');

// Load active plugins
$container->get('pluginManager')->loadActivePlugins();

// Register maintenance hook for admin login (fallback for cron)
\App\Support\Hooks::add('login.success', [\App\Support\MaintenanceService::class, 'onAdminLogin'], 100);

// App
$app = AppFactory::create();
$app->addRoutingMiddleware();

// Global security headers
$app->add(function ($request, $handler) use ($httpsDetected) {
    $response = $handler->handle($request);

    // Content Security Policy - restrictive but allows inline scripts/styles (required by app)
    // Tutti gli asset (font inclusi) sono self-hosted: nessuna dipendenza esterna
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com http://fonts.googleapis.com; " .
           "img-src 'self' data: blob: http: https:; " .
           "font-src 'self' data: https://fonts.gstatic.com http://fonts.gstatic.com; " .
           "connect-src 'self' data: blob:; " .
           "object-src 'none'; " .
           "base-uri 'self'; " .
           "form-action 'self'; " .
           "frame-src 'self' https://www.google.com https://www.google.it https://maps.google.com https://www.openstreetmap.org; " .
           "frame-ancestors 'none'";

    // Add upgrade-insecure-requests only in production with HTTPS
    if (getenv('APP_ENV') === 'production' && $httpsDetected) {
        $csp .= "; upgrade-insecure-requests";
    }

    $response = $response->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Content-Security-Policy', $csp)
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-XSS-Protection', '1; mode=block')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

    if (!$response->hasHeader('Strict-Transport-Security') && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        $response = $response->withHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
    }

    return $response;
});

// Error middleware (dev-friendly by default; tune in settings)
$displayErrorDetails = $container->get('settings')['displayErrorDetails'] ?? true;
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);

// Custom error handler for production mode only (handles both 404 and 500 errors)
// In development mode (displayErrorDetails=true), use Slim's default detailed error pages
if (!$displayErrorDetails) {
    $errorHandler = $errorMiddleware->getDefaultErrorHandler();
    $errorHandler->forceContentType('text/html');

    $customErrorHandler = function (
        \Psr\Http\Message\ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app): \Psr\Http\Message\ResponseInterface {
        // Log error for debugging
        if ($logErrors) {
            error_log(sprintf(
                "[ERROR] %s in %s:%d\nStack trace:\n%s",
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            ));
        }

        // Check if it's a 404 error
        $is404 = $exception instanceof \Slim\Exception\HttpNotFoundException
            || $exception instanceof \Slim\Exception\HttpMethodNotAllowedException
            || $exception->getCode() === 404;

        // Create response
        $response = $app->getResponseFactory()->createResponse();

        try {
            if ($is404) {
                // Render custom 404 page
                ob_start();
                $requestedPath = $request->getUri()->getPath();
                require __DIR__ . '/../app/Views/errors/404.php';
                $html = ob_get_clean();
                $response->getBody()->write($html);
                return $response->withStatus(404);
            } else {
                // Render custom 500 page for all other errors
                ob_start();
                require __DIR__ . '/../app/Views/errors/500.php';
                $html = ob_get_clean();
                $response->getBody()->write($html);
                return $response->withStatus(500);
            }
        } catch (\Throwable $e) {
            // Fallback to simple error page if rendering fails
            error_log('[CustomErrorHandler] Error rendering custom error page: ' . $e->getMessage());
            $statusCode = $is404 ? 404 : 500;
            $message = $is404
                ? '<h1>404 Not Found</h1><p>The requested page could not be found.</p>'
                : '<h1>500 Internal Server Error</h1><p>An unexpected error occurred.</p>';
            $response->getBody()->write($message);
            return $response->withStatus($statusCode);
        }
    };

    $errorMiddleware->setDefaultErrorHandler($customErrorHandler);
}

// CSRF: for now use simple session token (see App\Support\Csrf)

// Load plugins
// Note: Plugins can be loaded via PluginManager (database) or directly
// Plugins installed via PluginManager are loaded automatically from database

// Routes
(require __DIR__ . '/../app/Routes/web.php')($app);

$app->run();
