<?php
declare(strict_types=1);

/**
 * SSRF regression guard for the z39-server plugin HTTP clients.
 *
 * Every client that performs outbound cURL requests with CURLOPT_FOLLOWLOCATION
 * MUST pin the allowed protocols to http/https on both the initial request
 * (CURLOPT_PROTOCOLS) and on redirects (CURLOPT_REDIR_PROTOCOLS), otherwise a
 * malicious/compromised upstream could redirect to file://, gopher://, dict://
 * etc. and turn the scraper into an SSRF/local-file-read primitive.
 *
 * This is a source-level guard (no network): it asserts every curl block in
 * each client sets both protocol options. Cheap, deterministic, regression-proof.
 *
 * Run:
 *   php tests/z39-ssrf-protocols.unit.php
 * Exits 0 on success, 1 on any failure.
 */

$classesDir = __DIR__ . '/../storage/plugins/z39-server/classes';

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
};

// Discover every client file that actually opens a cURL handle.
$files = glob($classesDir . '/*.php') ?: [];
$curlFiles = [];
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    if (strpos($src, 'curl_init') !== false || strpos($src, 'curl_setopt') !== false) {
        $curlFiles[basename($file)] = $src;
    }
}

$check($curlFiles !== [], 'at least one cURL-using client discovered');

foreach ($curlFiles as $name => $src) {
    // Count how many curl handles are created and how many pin both protocol options.
    $initCount   = preg_match_all('/curl_init\s*\(/', $src);
    $protoCount  = substr_count($src, 'CURLOPT_PROTOCOLS');
    $redirCount  = substr_count($src, 'CURLOPT_REDIR_PROTOCOLS');

    // Every opened handle must pin initial + redirect protocols.
    $check(
        $protoCount >= $initCount && $protoCount > 0,
        "$name: CURLOPT_PROTOCOLS set for every handle ($protoCount >= $initCount)"
    );
    $check(
        $redirCount >= $initCount && $redirCount > 0,
        "$name: CURLOPT_REDIR_PROTOCOLS set for every handle ($redirCount >= $initCount)"
    );

    // The pinned value must be exactly http|https, never a broader set.
    $check(
        preg_match('/CURLOPT_PROTOCOLS\s*=>\s*CURLPROTO_HTTP\s*\|\s*CURLPROTO_HTTPS/', $src) === 1
        || $protoCount === 0,
        "$name: protocols pinned to CURLPROTO_HTTP|CURLPROTO_HTTPS"
    );
    $check(
        strpos($src, 'CURLPROTO_ALL') === false,
        "$name: never widens to CURLPROTO_ALL"
    );
}

echo "\n  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
