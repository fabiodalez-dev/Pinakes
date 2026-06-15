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

// 5. Plain http:// to the API host must NOT receive the bearer (scheme is part
//    of the contract — a token over http leaks in transit).
$check($call('http://api.github.com/repos/x') === false,
    'http://api.github.com => false (scheme must be https)');

// ---------------------------------------------------------------------------
echo "isValidSha256() — digest hex guard\n";
// ---------------------------------------------------------------------------

$isValid = $ref->getMethod('isValidSha256');
$isValid->setAccessible(true);
$sha = static fn(string $h): bool => (bool) $isValid->invoke($updater, $h);

$check($sha(str_repeat('a', 64)) === true, '64 lowercase hex => true');
$check($sha(str_repeat('A', 64)) === false, 'uppercase hex => false (must be lowercase)');
$check($sha(str_repeat('a', 32)) === false, '32 chars (md5-like) => false');
$check($sha('sha256:' . str_repeat('a', 64)) === false, 'still-prefixed value => false');
$check($sha(str_repeat('a', 63) . 'g') === false, 'non-hex char => false');

// ---------------------------------------------------------------------------
echo "fetchVerifiedReleaseAsset() — behavioral, offline (injected canned data)\n";
// ---------------------------------------------------------------------------

// A test double overriding the two network seams (now protected) so the real
// verify logic runs against controlled bytes — no GitHub, no DB.
$makeUpdater = static function (?array $release, array $downloads): \App\Support\Updater {
    return new class($release, $downloads) extends \App\Support\Updater {
        private ?array $cannedRelease;
        /** @var array<string, ?string> */
        private array $cannedDownloads;
        public function __construct(?array $release, array $downloads)
        {
            $this->cannedRelease = $release;
            $this->cannedDownloads = $downloads;
        }
        protected function getReleaseByVersion(string $version): ?array
        {
            return $this->cannedRelease;
        }
        protected function downloadPatchFile(string $url): ?string
        {
            return $this->cannedDownloads[$url] ?? null;
        }
    };
};
$fvMethod = new ReflectionMethod(\App\Support\Updater::class, 'fetchVerifiedReleaseAsset');
$fvMethod->setAccessible(true);
$fetch = static function (\App\Support\Updater $u, string $asset) use ($fvMethod) {
    return $fvMethod->invoke($u, '1.0.0', $asset);
};

$BYTES = "<?php /* a verified patch */ return ['patches' => []];";
$HASH  = hash('sha256', $BYTES);
$URL   = 'https://github.com/o/r/releases/download/v1.0.0/pre-update-patch.php';

// 1. Matching API digest => verified, bytes returned.
$u1 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL, 'digest' => 'sha256:' . $HASH]]],
    [$URL => $BYTES]
);
$r1 = $fetch($u1, 'pre-update-patch.php');
$check($r1['status'] === 'verified' && $r1['content'] === $BYTES, 'matching digest => verified + content');

// 2. TAMPERED bytes (digest unchanged) => INVALID (present-but-corrupt → block). Core guarantee.
$u2 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL, 'digest' => 'sha256:' . $HASH]]],
    [$URL => $BYTES . ' /* tampered */']
);
$check($fetch($u2, 'pre-update-patch.php')['status'] === 'invalid', 'tampered bytes => invalid (block)');

// 3. Asset present but NO API digest => INVALID. The ".sha256" sidecar fallback was
//    removed (same-CDN forgery), so a present patch without a trusted digest blocks.
$u3 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL]]],
    [$URL => $BYTES]
);
$check($fetch($u3, 'pre-update-patch.php')['status'] === 'invalid', 'present asset, no digest => invalid (digest-only)');

// 4. Malformed digest => INVALID (no fall-through to bytes).
$u4 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL, 'digest' => 'sha256:NOTHEX']]],
    [$URL => $BYTES]
);
$check($fetch($u4, 'pre-update-patch.php')['status'] === 'invalid', 'malformed digest => invalid');

// 5. Asset absent on the release => ABSENT (normal "no patch", skip).
$u5 = $makeUpdater(['assets' => []], []);
$check($fetch($u5, 'pre-update-patch.php')['status'] === 'absent', 'asset absent => absent (no patch)');

// 6. Release lookup failed (API error) => ABSENT (skip, not block — transient API failure).
$u6 = $makeUpdater(null, []);
$check($fetch($u6, 'pre-update-patch.php')['status'] === 'absent', 'release lookup failed => absent');

// 7. Present asset + valid digest but the download fails => INVALID (a present patch
//    we cannot fetch must NOT be silently skipped).
$u7 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL, 'digest' => 'sha256:' . $HASH]]],
    [] // no canned download for $URL => downloadPatchFile() returns null
);
$check($fetch($u7, 'pre-update-patch.php')['status'] === 'invalid', 'present asset, download fails => invalid');

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
    'refuses update when no digest available');

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

// 12. The ".sha256" sidecar fallback helper was removed (same-CDN forgery).
$check(strpos($src, 'function fetchSidecarChecksum') === false,
    'fetchSidecarChecksum() removed (no same-CDN sidecar fallback)');

// 13. A present-but-unverifiable patch is reported as success=false by both patch
//     methods (the unverified code is never executed). The CALLER reacts
//     asymmetrically by lifecycle position:
//     - pre-update (nothing committed yet)  => THROW, abort the whole update.
//     - post-install (core update already committed) => NON-FATAL warning, keep
//       the update successful (else a correct upgrade is reported as failed).
$check(preg_match('/applyPreUpdatePatch.*?status.*?===\s*\x27invalid\x27.*?success.*?false/s', $src) === 1,
    'pre-update patch invalid => success=false (blocks update)');
$check(strpos($src, "throw new Exception(\$patchResult['error']") !== false,
    'update flow throws on a non-verifiable pre-update patch');
// post-install must NOT throw — it records a non-fatal warning instead.
$check(strpos($src, "throw new Exception(\$postPatchResult['error']") === false,
    'post-install patch failure does NOT throw (update already committed)');
$check(strpos($src, "'warning' => \$postPatchWarning") !== false,
    'post-install patch failure surfaces a non-fatal warning in the result');

// 14. The token-bearing GitHub request does NOT auto-follow redirects (no token leak).
$check(preg_match('/makeGitHubRequest.*?\x27follow_location\x27\s*=>\s*0/s', $src) === 1,
    'makeGitHubRequest() disables redirect auto-follow (token-scope)');
$check(preg_match('/statusCode\s*>=\s*300\s*&&\s*\$statusCode\s*<\s*400/s', $src) === 1,
    'makeGitHubRequest() fails closed on an unexpected redirect');
// Every authenticated request path disables redirect auto-follow, not just the
// cURL makeGitHubRequest() — the file_get_contents releases fallback (and its
// token-retry) must also pin follow_location => 0 so the bearer is never resent.
$check(substr_count($src, "'follow_location' => 0") >= 3,
    'authenticated file_get_contents fallback paths also disable redirect follow');
// 14b. fetchReleasesRaw's cURL branch (primary + token-retry) is fail-closed on
//      redirects too — FOLLOWLOCATION=false on both. (The asset-DOWNLOAD paths keep
//      FOLLOWLOCATION=true on purpose: they must follow github.com→CDN, and stay
//      token-safe via isApiUrl()/anonymous headers.)
$check(substr_count($src, 'CURLOPT_FOLLOWLOCATION => false') >= 2,
    'fetchReleasesRaw cURL branch disables redirect auto-follow (primary + retry)');

// 15. Patch error handling is fail-closed where nothing is committed yet:
//     a present, verified pre-update patch that errors mid-apply sets success=false
//     (the caller aborts) — the old "Still return success=true" fail-open is gone.
$check(strpos($src, 'Still return success=true to allow update to continue') === false,
    'pre-update patch no longer fails open on a mid-apply error');
$check(preg_match('/Errore durante pre-update-patch.*?\$result\[\x27success\x27\]\s*=\s*false/s', $src) === 1,
    'pre-update patch catch is fail-closed (success=false)');
// post-install: the core update is already committed, so a runtime error is a
// non-fatal warning — but it must set success=false so it is not hidden.
$check(preg_match('/Errore durante post-install-patch.*?\$result\[\x27success\x27\]\s*=\s*false/s', $src) === 1,
    'post-install patch catch surfaces errors (success=false), not hidden as "no patch"');

// ---------------------------------------------------------------------------
echo "\n" . ($failed === 0
    ? "[OK] updater-hardening unit: $passed passed\n"
    : "[FAIL] updater-hardening unit: $failed failed, $passed passed\n");
exit($failed === 0 ? 0 : 1);
