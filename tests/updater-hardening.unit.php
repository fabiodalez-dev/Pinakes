<?php
declare(strict_types=1);

/**
 * Unit tests for the Updater supply-chain hardening (security/updater-hardening).
 *
 * Covers the deterministic guarantees, without network I/O:
 *   - isApiUrl(): the bearer token may only go to api.github.com
 *   - source-level guards: mandatory sha256 verification (hash_equals, fail-closed),
 *     no unverifiable zipball fallback, token-scoped asset download, and patches
 *     routed through the verified-asset path (no same-CDN checksum compare).
 *
 * Run:
 *   php tests/updater-hardening.unit.php
 * Exits 0 on success, 1 on any failure.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/Support/Updater.php';

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

// ---------------------------------------------------------------------------
echo "isApiUrl() — bearer token host scoping\n";
// ---------------------------------------------------------------------------

$ref = new ReflectionClass(\App\Support\Updater::class);
$updater = $ref->newInstanceWithoutConstructor(); // no DB needed for a pure method
$isApiUrl = $ref->getMethod('isApiUrl');
$isApiUrl->setAccessible(true);
$call = static fn(string $url): bool => (bool) $isApiUrl->invoke($updater, $url);

// 1. The API host is the only one that may carry the token.
$check($call('https://api.github.com/repos/fabiodalez-dev/Pinakes/releases/latest') === true,
    'api.github.com => true');

// 2. The release "download" host (github.com) must stay anonymous.
$check($call('https://github.com/fabiodalez-dev/Pinakes/releases/download/v1/pinakes.zip') === false,
    'github.com (download) => false');

// 3. The CDN the download redirects to must stay anonymous.
$check($call('https://objects.githubusercontent.com/github-production-release-asset/x') === false,
    'objects.githubusercontent.com => false');

// 4. A lookalike host must not be treated as the API.
$check($call('https://api.github.com.evil.test/repos/x') === false,
    'api.github.com.evil.test => false (no suffix confusion)');

// ---------------------------------------------------------------------------
echo "Source guards — mandatory integrity + token scoping\n";
// ---------------------------------------------------------------------------

$src = (string) file_get_contents($ROOT . '/app/Support/Updater.php');

// 5. The downloaded ZIP is compared with a timing-safe hash_equals.
$check(strpos($src, "hash_equals(\$expectedHash, \$actualHash)") !== false,
    'ZIP verified with hash_equals($expectedHash, $actualHash)');

// 6. Missing integrity source => refuse (fail-closed, not silent install).
$check(strpos($src, 'Verifica di integrità impossibile') !== false
    && strpos($src, 'Installazione di un pacchetto non verificato rifiutata') !== false,
    'refuses update when no digest/sidecar available');

// 7. No silent zipball fallback as a download source any more.
$check(strpos($src, "\$downloadUrl = \$release['zipball_url']") === false,
    'no unverifiable zipball_url download fallback');

// 8. The package download is token-scoped via isApiUrl($downloadUrl).
$check(strpos($src, "getGitHubHeaders('application/octet-stream', \$this->isApiUrl(\$downloadUrl))") !== false,
    'package download header scoped by isApiUrl()');

// 9. fetchVerifiedReleaseAsset uses hash_equals (timing-safe).
$check(preg_match('/fetchVerifiedReleaseAsset.*?hash_equals\(\$expectedHash, hash\(/s', $src) === 1,
    'fetchVerifiedReleaseAsset() verifies with hash_equals');

// ---------------------------------------------------------------------------
echo "Source guards — hardened patches (ON, verified)\n";
// ---------------------------------------------------------------------------

// 10. Both patch entry points now route through the verified-asset path.
$check(strpos($src, "fetchVerifiedReleaseAsset(\$targetVersion, 'pre-update-patch.php')") !== false,
    'pre-update patch via fetchVerifiedReleaseAsset()');
$check(strpos($src, "fetchVerifiedReleaseAsset(\$targetVersion, 'post-install-patch.php')") !== false,
    'post-install patch via fetchVerifiedReleaseAsset()');

// 11. The weak same-CDN "!== $actualChecksum" patch compare is gone.
$check(strpos($src, '$expectedChecksum !== $actualChecksum') === false,
    'weak same-source checksum compare removed from patches');

// ---------------------------------------------------------------------------
echo "\n" . ($failed === 0
    ? "[OK] updater-hardening unit: $passed passed\n"
    : "[FAIL] updater-hardening unit: $failed failed, $passed passed\n");
exit($failed === 0 ? 0 : 1);
