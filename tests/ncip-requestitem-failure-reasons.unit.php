<?php
declare(strict_types=1);

/**
 * Source regression guard for NCIP RequestItem failure classification.
 * Permanent rejections must not be exposed as retryable processing failures.
 */

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/storage/plugins/ncip-server/NcipServerPlugin.php');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else { $fail++; echo "  FAIL {$label}\n"; }
};

$check(
    str_contains($source, 'createRequestItemNcip(')
        && str_contains($source, '$failureReason'),
    '01 RequestItem receives a stable failure reason from createRequestItemNcip'
);

// RequestItem refusals use the request scheme (requestitemprocessingerror),
// not the check-out one: they are mapped to their own internal codes.
foreach ([
    "'duplicate'   => 'duplicate-request'",
    "'ineligible'  => 'user-ineligible-to-request'",
    "'max_loans'   => 'user-ineligible-to-request'",
] as $i => $mapping) {
    $check(
        str_contains($source, $mapping),
        sprintf('%02d permanent RequestItem rejection has a non-retryable mapping', $i + 2)
    );
}

$check(
    str_contains($source, "default       => 'temporary-processing-failure'")
        && str_contains($source, "\$failureReason = 'db_error';"),
    '05 only unclassified/database failures retain the retryable fallback'
);

foreach (['duplicate', 'ineligible', 'max_loans'] as $reason) {
    $check(
        str_contains($source, "\$failureReason = '{$reason}';"),
        "createRequestItemNcip reports {$reason}"
    );
}

require_once $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';
$problemType = new ReflectionMethod(\App\Plugins\NcipServer\NcipServerPlugin::class, 'problemType');
$rq = 'http://www.niso.org/ncip/v1_0/schemes/processingerrortype/requestitemprocessingerror.scm';
$check(
    $problemType->invoke(null, 'user-ineligible-to-request') === [$rq, 'User Ineligible To Request This Item']
        && $problemType->invoke(null, 'duplicate-request') === [$rq, 'Duplicate Request'],
    'RequestItem refusals go on the wire as requestitemprocessingerror scheme values'
);

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
