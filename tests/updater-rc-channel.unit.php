<?php
declare(strict_types=1);

/**
 * Unit tests for the Updater release-candidate / prerelease channel
 * (feat/rc-channel). Exercises the three pure decision methods that gate RC
 * visibility, via reflection so no DB connection or GitHub network call is
 * needed:
 *
 *   - prereleaseChannelEnabled() — the env opt-in gate
 *   - filterReleasesByChannel()  — stable channel hides prereleases/drafts
 *   - selectNewestRelease()      — RC channel picks newest non-draft release
 *
 * Run:
 *   php tests/updater-rc-channel.unit.php
 * Exits 0 on success, 1 on any failure.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\Updater;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
};

// Build an Updater WITHOUT running its constructor (which needs a mysqli handle
// and writable storage dirs) — we only want to reach the pure private methods.
$ref = new ReflectionClass(Updater::class);
$updater = $ref->newInstanceWithoutConstructor();

$invoke = static function (string $method, array $args) use ($ref, $updater) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($updater, $args);
};

// Set/clear an env var on BOTH layers the method reads ($_ENV first, then getenv).
$setEnv = static function (string $name, ?string $value): void {
    if ($value === null) {
        unset($_ENV[$name]);
        putenv($name);
        return;
    }
    $_ENV[$name] = $value;
    putenv("$name=$value");
};

$clearChannelEnv = static function () use ($setEnv): void {
    $setEnv('UPDATER_ALLOW_PRERELEASE', null);
    $setEnv('UPDATER_CHANNEL', null);
};

$channelEnabled = static fn(): bool => (bool) $invoke('prereleaseChannelEnabled', []);

// ── prereleaseChannelEnabled() ────────────────────────────────────────────

// 1. Both unset → disabled (the safe default; RC hidden)
$clearChannelEnv();
$check($channelEnabled() === false, '1. both env unset -> channel disabled (default)');

// 2. UPDATER_ALLOW_PRERELEASE truthy variants → enabled
foreach (['1', 'true', 'TRUE', 'yes', 'on', '  on  '] as $truthy) {
    $clearChannelEnv();
    $setEnv('UPDATER_ALLOW_PRERELEASE', $truthy);
    $check($channelEnabled() === true, "2. UPDATER_ALLOW_PRERELEASE='$truthy' -> enabled");
}

// 3. UPDATER_ALLOW_PRERELEASE falsy variants → disabled
foreach (['0', 'false', 'no', 'off', ''] as $falsy) {
    $clearChannelEnv();
    $setEnv('UPDATER_ALLOW_PRERELEASE', $falsy);
    $check($channelEnabled() === false, "3. UPDATER_ALLOW_PRERELEASE='$falsy' -> disabled");
}

// 4. UPDATER_CHANNEL=rc (or any non-stable) → enabled
foreach (['rc', 'beta', 'RC', 'dev'] as $chan) {
    $clearChannelEnv();
    $setEnv('UPDATER_CHANNEL', $chan);
    $check($channelEnabled() === true, "4. UPDATER_CHANNEL='$chan' -> enabled");
}

// 5. UPDATER_CHANNEL=stable (or empty) → disabled
foreach (['stable', 'STABLE', ''] as $chan) {
    $clearChannelEnv();
    $setEnv('UPDATER_CHANNEL', $chan);
    $check($channelEnabled() === false, "5. UPDATER_CHANNEL='$chan' -> disabled");
}

// ── filterReleasesByChannel() ─────────────────────────────────────────────

$fixture = [
    ['tag_name' => 'v0.7.16-rc.1', 'prerelease' => true,  'draft' => false],
    ['tag_name' => 'v0.7.15',      'prerelease' => false, 'draft' => false],
    ['tag_name' => 'v0.7.15-draft','prerelease' => false, 'draft' => true],
    ['tag_name' => 'v0.7.14',      'prerelease' => false, 'draft' => false],
];

// 6. Stable channel: prereleases AND drafts are dropped
$clearChannelEnv();
$filteredStable = $invoke('filterReleasesByChannel', [$fixture]);
$stableTags = array_map(static fn($r) => $r['tag_name'], $filteredStable);
$check(
    $stableTags === ['v0.7.15', 'v0.7.14'],
    '6. stable channel drops prerelease + draft (kept: ' . implode(',', $stableTags) . ')'
);

// 7. RC channel: nothing is filtered out (all four kept, re-indexed)
$clearChannelEnv();
$setEnv('UPDATER_ALLOW_PRERELEASE', '1');
$filteredRc = $invoke('filterReleasesByChannel', [$fixture]);
$check(count($filteredRc) === 4, '7. RC channel keeps all releases (count=' . count($filteredRc) . ')');

// ── selectNewestRelease() ─────────────────────────────────────────────────

// 8. Picks the first non-draft (newest-first list), prerelease allowed
$pick = $invoke('selectNewestRelease', [$fixture]);
$check(is_array($pick) && ($pick['tag_name'] ?? '') === 'v0.7.16-rc.1', '8. selects newest non-draft (the RC)');

// 9. Skips leading drafts
$withLeadingDraft = array_merge(
    [['tag_name' => 'v0.7.17-wip', 'prerelease' => true, 'draft' => true]],
    $fixture
);
$pick2 = $invoke('selectNewestRelease', [$withLeadingDraft]);
$check(is_array($pick2) && ($pick2['tag_name'] ?? '') === 'v0.7.16-rc.1', '9. skips leading draft entry');

// 10. Empty list → null
$check($invoke('selectNewestRelease', [[]]) === null, '10. empty list -> null');

// ── version_compare sanity for RC tags (the migration/update gate relies on it)
$check(version_compare('0.7.16-rc.1', '0.7.15', '>'), '11. version_compare: 0.7.16-rc.1 > 0.7.15');
$check(version_compare('0.7.16-rc.1', '0.7.16', '<'), '12. version_compare: 0.7.16-rc.1 < 0.7.16 (final)');
$check(version_compare('0.7.16-rc.2', '0.7.16-rc.1', '>'), '13. version_compare: rc.2 > rc.1');

$clearChannelEnv();
echo "\n  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
