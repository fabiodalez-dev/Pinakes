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
    str_contains($source, 'createLoanNcip(')
        && str_contains($source, '$failureReason'),
    '01 RequestItem receives a stable failure reason from createLoanNcip'
);

foreach ([
    "'duplicate'   => 'duplicate-request'",
    "'ineligible'  => 'user-ineligible-to-check-out'",
    "'max_loans'   => 'user-loan-limit-reached'",
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
        "createLoanNcip reports {$reason}"
    );
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
