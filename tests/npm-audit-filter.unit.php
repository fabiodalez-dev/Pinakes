<?php
declare(strict_types=1);

/**
 * scripts/npm-audit-filter.js: which npm advisories block the build.
 *
 * The filter lets a high/critical advisory through only when a waiver in
 * .github/npm-audit-waivers.json names it, the waiver is in date and has a
 * reason, and the advisory stays out of the production dependencies. Every
 * other case must still block, which is what most of these checks are about.
 */

$root = dirname(__DIR__);
$filter = $root . '/scripts/npm-audit-filter.js';
$tmp = sys_get_temp_dir() . '/pinakes-npm-audit-' . bin2hex(random_bytes(4));
mkdir($tmp);

$failures = 0;
$checks = 0;
/** Record one check and print its outcome. */
function check(bool $ok, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
}

/**
 * A report shaped like `npm audit --json`: the advisory sits on the package
 * it affects, and the packages above it only point at that package by name.
 *
 * @param list<array{0:string,1:string,2:string}> $advisories [package, GHSA id, severity]
 */
function report(array $advisories): array
{
    $vulnerabilities = [];
    foreach ($advisories as $i => [$package, $id, $severity]) {
        $vulnerabilities[$package] = [
            'name' => $package,
            'severity' => $severity,
            'isDirect' => false,
            'via' => [[
                'source' => 1240000 + $i,
                'name' => $package,
                'title' => "Advisory on {$package}",
                'url' => "https://github.com/advisories/{$id}",
                'severity' => $severity,
                'range' => '*',
            ]],
        ];
        $vulnerabilities["parent-of-{$package}"] = ['name' => "parent-of-{$package}", 'severity' => $severity, 'isDirect' => true, 'via' => [$package]];
    }

    return ['auditReportVersion' => 2, 'vulnerabilities' => $vulnerabilities];
}

/**
 * Run the filter on the given reports and waivers; returns its exit code.
 *
 * @param list<array<string, string>> $waivers
 */
function run(array $full, array $prod, array $waivers, string $today = '2026-10-03'): int
{
    global $filter, $tmp;
    $files = [];
    foreach (['full' => $full, 'prod' => $prod, 'waivers' => $waivers] as $name => $data) {
        $files[$name] = "{$tmp}/{$name}.json";
        file_put_contents($files[$name], json_encode($data));
    }
    $command = sprintf(
        'NPM_AUDIT_TODAY=%s node %s %s %s %s 2>&1',
        escapeshellarg($today),
        escapeshellarg($filter),
        escapeshellarg($files['full']),
        escapeshellarg($files['prod']),
        escapeshellarg($files['waivers'])
    );
    exec($command, $output, $code);

    return $code;
}

$braces = ['braces', 'GHSA-vfj7-8cjw-p6xm', 'high'];
$waiver = ['id' => 'GHSA-vfj7-8cjw-p6xm', 'package' => 'braces', 'expires' => '2026-11-03', 'reason' => 'build-time only'];
$clean = report([]);

try {
    check(run(report([$braces]), $clean, [$waiver]) === 0, 'a waived build-time advisory does not block');
    check(run(report([$braces]), $clean, []) === 1, 'the same advisory without a waiver blocks');
    check(run(report([$braces]), $clean, [$waiver], '2026-11-04') === 1, 'an expired waiver no longer covers it');
    check(run(report([$braces]), $clean, [$waiver], '2026-11-03') === 0, 'a waiver still holds on its expiry day');
    check(run(report([$braces]), report([$braces]), [$waiver]) === 1, 'a waiver never covers an advisory in the production dependencies');
    check(run($clean, report([['lodash', 'GHSA-aaaa-bbbb-cccc', 'critical']]), [$waiver]) === 1, 'an advisory only the production audit reports blocks');
    check(run(report([$braces, ['minimist', 'GHSA-dddd-eeee-ffff', 'high']]), $clean, [$waiver]) === 1, 'an unwaived high advisory next to a waived one blocks');
    check(run(report([['debug', 'GHSA-gggg-hhhh-iiii', 'moderate']]), $clean, []) === 0, 'a moderate advisory is below the bar');
    check(run(report([$braces]), $clean, [['reason' => ''] + $waiver]) === 1, 'a waiver without a reason counts as absent');
    check(run(report([$braces]), $clean, [['expires' => 'soon'] + $waiver]) === 1, 'a waiver without a real expiry date counts as absent');

    // The committed list: every entry well formed, and none set so far ahead
    // that the build would stop asking whether a fix has been published.
    $committed = json_decode((string) file_get_contents($root . '/.github/npm-audit-waivers.json'), true);
    check(is_array($committed), 'the committed waiver list is valid JSON');
    $today = new DateTimeImmutable('today');
    foreach (is_array($committed) ? $committed : [] as $entry) {
        $id = (string) ($entry['id'] ?? '');
        $expires = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($entry['expires'] ?? ''));
        check(preg_match('/^GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/i', $id) === 1 && trim((string) ($entry['reason'] ?? '')) !== '' && trim((string) ($entry['package'] ?? '')) !== '', "{$id}: id, package and reason are present");
        check($expires instanceof DateTimeImmutable && $expires <= $today->modify('+60 days'), "{$id}: expires within 60 days, so it is checked again");
    }
} finally {
    array_map('unlink', glob($tmp . '/*') ?: []);
    rmdir($tmp);
}

echo "\n" . ($failures === 0 ? "SUCCESS {$checks} checks" : "FAILURE {$failures} of {$checks} checks failed") . "\n";
exit($failures === 0 ? 0 : 1);
