<?php

declare(strict_types=1);

/**
 * Guard: no active curl_close() calls anywhere in the shipped code.
 *
 * curl_close() has been a no-op since PHP 8.0 (curl handles are objects freed
 * by the garbage collector) and emits a `Deprecated` notice on PHP 8.5+. When
 * display_errors is on, that notice is written to the response body BEFORE the
 * JSON payload, corrupting it — the browser's response.json() then throws and
 * the ISBN import surfaces "Risposta non valida dal servizio ISBN.", even
 * though the scrape succeeded. (The z39-server SBN/SRU clients sit in the ISBN
 * scrape path, which is how this first bit.)
 *
 * The fix is to drop the deprecated calls entirely. This guard keeps them out:
 * a real call passes a handle — `curl_close($ch)` — whereas the retained
 * marker comment is `curl_close(): no-op ...` with empty parens, so scanning
 * for `curl_close(` immediately followed by `$` catches every active call
 * without flagging the comments.
 */

$roots = [
    dirname(__DIR__) . '/app',
    dirname(__DIR__) . '/storage/plugins',
    dirname(__DIR__) . '/public',
];

$violations = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $n => $line) {
            // A real call passes a handle: curl_close($...). The retained
            // marker comment is `curl_close():` with empty parens, so it is
            // never matched here.
            if (preg_match('/curl_close\s*\(\s*\$/', $line)) {
                $rel = str_replace(dirname(__DIR__) . '/', '', $file->getPathname());
                $violations[] = $rel . ':' . ($n + 1) . '  ' . trim($line);
            }
        }
    }
}

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$check(
    $violations === [],
    'no active curl_close($handle) calls remain (deprecated no-op on PHP 8.5)'
);

if ($violations !== []) {
    echo PHP_EOL . 'Active curl_close() calls found:' . PHP_EOL;
    foreach ($violations as $v) {
        echo '  - ' . $v . PHP_EOL;
    }
    echo PHP_EOL . 'Replace each with the marker comment:' . PHP_EOL;
    echo '  /* curl_close(): no-op since PHP 8.0, deprecated 8.5 */' . PHP_EOL;
}

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
