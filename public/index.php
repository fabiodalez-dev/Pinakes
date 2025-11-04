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

require __DIR__ . '/../vendor/autoload.php';

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
if (!$isCli && getenv('APP_ENV') === 'production' && !$httpsDetected) {
    // Only redirect to HTTPS if we can verify the server supports it
    // Check if HTTPS port is listening or if we have explicit HTTPS config
    $forceHttps = getenv('FORCE_HTTPS') === 'true';

    if ($forceHttps) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $host . $requestUri, true, 301);
        exit;
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

            $needsRedirect = false;
            if ($requestHost !== $canonicalHost) {
                $needsRedirect = true;
            }

            if (!$needsRedirect && $canonicalPort !== null && $requestPort !== $canonicalPort) {
                $needsRedirect = true;
            }

            if (
                !$needsRedirect
                && $canonicalScheme !== ''
                && $requestScheme !== ''
                && $canonicalScheme !== $requestScheme
            ) {
                $needsRedirect = true;
            }

            if ($needsRedirect) {
                $defaultPorts = ['http' => 80, 'https' => 443];
                $targetHost = $canonicalHostOriginal;

                if ($canonicalPort !== null) {
                    $defaultPort = $defaultPorts[$canonicalScheme] ?? null;
                    if ($defaultPort === null || $canonicalPort !== $defaultPort) {
                        $targetHost .= ':' . $canonicalPort;
                    }
                } elseif ($requestPort !== null) {
                    $defaultPort = $defaultPorts[$canonicalScheme] ?? null;
                    if ($defaultPort !== null && $requestPort !== $defaultPort) {
                        $targetHost .= ':' . $requestPort;
                    }
                }

                $targetUrl = $canonicalScheme . '://' . $targetHost . $requestUri;
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

// App
$app = AppFactory::create();
$app->addRoutingMiddleware();

// Global security headers
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);

    // Content Security Policy - restrictive but allows inline scripts/styles (required by app)
    // All assets (Uppy, Inter font, Pie-CSS, etc.) are self-hosted - no external CDN dependencies
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
           // Google Fonts stylesheet (temporary until we self-host the font)
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
           "img-src 'self' data: blob: http: https:; " .
           // Permit Google Fonts font files while keeping data URI support
           "font-src 'self' data: https://fonts.gstatic.com; " .
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

// Routes
(require __DIR__ . '/../app/Routes/web.php')($app);

$app->run();
