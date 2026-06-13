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
echo "isValidSha256() — digest/sidecar hex guard (shared by both paths)\n";
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
$SIDE  = 'https://github.com/o/r/releases/download/v1.0.0/pre-update-patch.php.sha256';

// 1. Matching API digest => verified bytes returned.
$u1 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL, 'digest' => 'sha256:' . $HASH]]],
    [$URL => $BYTES]
);
$check($fetch($u1, 'pre-update-patch.php') === $BYTES, 'matching digest => returns verified bytes');

// 2. TAMPERED bytes (digest unchanged) => rejected (null). The core guarantee.
$u2 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL, 'digest' => 'sha256:' . $HASH]]],
    [$URL => $BYTES . ' /* tampered */']
);
$check($fetch($u2, 'pre-update-patch.php') === null, 'tampered bytes => rejected (null)');

// 3. No digest, but a valid matching .sha256 sidecar => verified bytes returned.
$u3 = $makeUpdater(
    ['assets' => [
        ['name' => 'pre-update-patch.php', 'browser_download_url' => $URL],
        ['name' => 'pre-update-patch.php.sha256', 'browser_download_url' => $SIDE],
    ]],
    [$URL => $BYTES, $SIDE => $HASH . '  pre-update-patch.php']
);
$check($fetch($u3, 'pre-update-patch.php') === $BYTES, 'no digest + valid sidecar => returns bytes');

// 4. Malformed digest AND no sidecar => refused (null), no fall-through to bytes.
$u4 = $makeUpdater(
    ['assets' => [['name' => 'pre-update-patch.php', 'browser_download_url' => $URL, 'digest' => 'sha256:NOTHEX']]],
    [$URL => $BYTES]
);
$check($fetch($u4, 'pre-update-patch.php') === null, 'malformed digest + no sidecar => null');

// 5. Asset absent on the release => null (normal "no patch").
$u5 = $makeUpdater(['assets' => []], []);
$check($fetch($u5, 'pre-update-patch.php') === null, 'asset absent => null (no patch)');

// 6. Release lookup failed (API error) => null (fail-closed).
$u6 = $makeUpdater(null, []);
$check($fetch($u6, 'pre-update-patch.php') === null, 'release lookup failed => null');

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
