<?php
/**
 * Router for PHP built-in server
 * Usage: php -S localhost:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve uploads from either project root or public directory
if (strpos($uri, '/uploads/') === 0) {
    $candidatePaths = [
        __DIR__ . $uri,
        __DIR__ . '/public' . $uri,
    ];

    foreach ($candidatePaths as $candidatePath) {
        if (!is_file($candidatePath)) {
            continue;
        }

        $mimeType = mime_content_type($candidatePath);
        if (is_string($mimeType) && $mimeType !== '') {
            header('Content-Type: ' . $mimeType);
        }

        readfile($candidatePath);
        return true;
    }
}

// Serve static files from public/ directory
if (preg_match('/\.(?:png|jpg|jpeg|gif|ico|css|js|svg|woff|woff2|ttf|eot)$/', $uri)) {
    $publicFile = __DIR__ . '/public' . $uri;
    if (is_file($publicFile)) {
        // Serve the file with correct MIME type
        $extension = pathinfo($publicFile, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
        ];
        if (isset($mimeTypes[$extension])) {
            header('Content-Type: ' . $mimeTypes[$extension]);
        }
        readfile($publicFile);
        return true;
    }
    // If not in public/, try serving from root (for installer assets)
    return false;
}

// Handle /installer/ requests
if (strpos($uri, '/installer') === 0) {
    if ($uri === '/installer' || $uri === '/installer/') {
        $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/installer/index.php';
        include __DIR__ . '/installer/index.php';
        return true;
    }
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        return false;
    }
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/installer/index.php';
    include __DIR__ . '/installer/index.php';
    return true;
}

// Handle /public/ requests
if (strpos($uri, '/public/') === 0) {
    $publicFile = __DIR__ . $uri;
    if (is_file($publicFile)) {
        return false;
    }
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/public/index.php';
    chdir(__DIR__ . '/public');
    include __DIR__ . '/public/index.php';
    return true;
}

// Check if installed
$envFile = __DIR__ . '/.env';
$installerLock = __DIR__ . '/.installed';

if (!file_exists($envFile) && !file_exists($installerLock)) {
    header('Location: /installer/', true, 302);
    exit;
}

// Route to /public/index.php
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir(__DIR__ . '/public');
include __DIR__ . '/public/index.php';
return true;
