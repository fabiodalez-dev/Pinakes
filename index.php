<?php
/**
 * Root Index - Redirect Handler with Installation Check
 * Note: This file should NOT be executed when accessing /installer/ directly
 * The .htaccess rule excludes /installer/ from rewriting
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';

// If requesting /installer/, this file should not be reached
// But as fallback, don't redirect installer requests
if (strpos($uri, '/installer') === 0) {
    // This shouldn't happen if .htaccess is working, but just in case
    // Don't redirect, let Apache serve the installer directory
    exit;
}

// Check if application is installed
$envFile = __DIR__ . '/.env';
$installerLockFile = __DIR__ . '/.installed';

// If .env doesn't exist AND installer lock doesn't exist, redirect to installer
if (!file_exists($envFile) && !file_exists($installerLockFile)) {
    header('Location: /installer/', true, 302);
    exit;
}

// Normal redirect to /public/
$target = '/public' . $uri;
if (strpos($uri, '/public/') === 0) {
    $target = $uri;
}
header('Location: ' . $target, true, 302);
exit;
