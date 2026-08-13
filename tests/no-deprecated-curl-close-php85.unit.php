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
 * The fix is to drop the deprecated calls entirely. This guard keeps them out.
 * Detection is token-based (token_get_all), not line-based, so a call split
 * across lines — curl_close(\n $ch \n) — is still caught. A call is flagged
 * only when it passes at least one argument, so the retained marker comment
 * `curl_close(): no-op ...` (a single T_COMMENT token) is never matched.
 */

$roots = [
    dirname(__DIR__) . '/app',
    dirname(__DIR__) . '/storage/plugins',
    dirname(__DIR__) . '/public',
];

/**
 * Return the index of the next semantically meaningful token at or after $from
 * (skipping whitespace and comments), or null if none remains.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 */
$nextMeaningful = static function (array $tokens, int $from): ?int {
    $count = count($tokens);
    for ($i = $from; $i < $count; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            continue;
        }
        return $i;
    }
    return null;
};

/**
 * Return the index of the previous meaningful token before $from, or null.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 */
$prevMeaningful = static function (array $tokens, int $from): ?int {
    for ($i = $from - 1; $i >= 0; $i--) {
        $t = $tokens[$i];
        if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            continue;
        }
        return $i;
    }
    return null;
};

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
        $src = file_get_contents($file->getPathname());
        if ($src === false) {
            continue;
        }
        $tokens = token_get_all($src);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || strcasecmp($t[1], 'curl_close') !== 0) {
                continue;
            }
            // Skip a method call ($obj->curl_close / Class::curl_close): the
            // deprecated global function is a bare call, never a member access.
            $prev = $prevMeaningful($tokens, $i);
            if ($prev !== null && is_array($tokens[$prev])
                && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            // Must be a call: the next meaningful token is '('.
            $open = $nextMeaningful($tokens, $i + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            // Flag only when at least one argument is passed — an empty
            // curl_close() would be a no-op we do not care about.
            $arg = $nextMeaningful($tokens, $open + 1);
            if ($arg === null || $tokens[$arg] === ')') {
                continue;
            }
            $rel = str_replace(dirname(__DIR__) . '/', '', $file->getPathname());
            $violations[] = $rel . ':' . $t[2];
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
