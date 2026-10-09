<?php
declare(strict_types=1);

/**
 * Reusable invariant guard for the NCIP write-path error classification
 * (PR #311). Permanent rejections MUST map to terminal NCIP ProblemTypes so a
 * partner stops retrying; only a genuine DB error may stay retryable
 * (temporary-processing-failure). Complements ncip-requestitem-failure-reasons
 * (RequestItem) and review-eligibility-in-ritardo (eligibility).
 *
 * Run:  php tests/ncip-problemtype-classification.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/storage/plugins/ncip-server/NcipServerPlugin.php');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/** Extract the body of a `match ($failureReason) { ... }` that contains $needle. */
$matchArm = static function (string $src, string $needle): string {
    if (!preg_match_all('/match\s*\(\$failureReason\)\s*\{(.*?)\}/s', $src, $m)) {
        return '';
    }
    foreach ($m[1] as $body) {
        if (str_contains($body, $needle)) {
            return $body;
        }
    }
    return '';
};

// 1. RenewItem: the four permanent reasons map to terminal ProblemTypes, and the
//    default is the only retryable outcome.
$renew = $matchArm($src, 'maximum-renewals-exceeded');
$check(
    $renew !== ''
        && str_contains($renew, "'not_found'") && str_contains($renew, 'unknown-item')
        && str_contains($renew, 'item-not-renewable')
        && str_contains($renew, 'user-ineligible-to-renew')
        && str_contains($renew, 'maximum-renewals-exceeded')
        && (bool) preg_match("/default\\s*=>\\s*'temporary-processing-failure'/", $renew),
    "01 RenewItem maps permanent reasons to terminal ProblemTypes (retryable only by default)"
);

// 2. CheckOutItem: same shape for its reasons (a switch on $failureReason).
$checkoutStart = strpos($src, 'private function handleCheckOutItem(');
$checkoutEnd = strpos($src, 'private function handleCheckInItem(');
$checkout = ($checkoutStart !== false && $checkoutEnd !== false)
    ? substr($src, $checkoutStart, $checkoutEnd - $checkoutStart)
    : '';
$check(
    $checkout !== ''
        && str_contains($checkout, "'unknown-item'")
        && str_contains($checkout, "'duplicate-request'")
        && str_contains($checkout, "'user-ineligible-to-check-out'")
        && str_contains($checkout, "'user-loan-limit-reached'")
        && (bool) preg_match("/default:\s*return[^;]*'temporary-processing-failure'/s", $checkout),
    "02 CheckOutItem maps permanent reasons to terminal ProblemTypes (retryable only by default)"
);

// 3. CheckIn idempotency stays precise: a loan counts as returned only when
//    stato = 'restituito' — NOT merely inactive (attivo = 0), which also covers a
//    concurrently cancelled loan.
$check(
    (bool) preg_match("/function isLoanReturned/", $src)
        && (bool) preg_match("/return\\s+\\\$row\\['stato'\\]\\s*===\\s*'restituito'\\s*;/", $src)
        && !preg_match("/\\(int\\)\\s*\\\$row\\['attivo'\\]\\s*===\\s*0\\s*\\|\\|/", $src),
    "03 isLoanReturned requires stato='restituito', not attivo=0 alone"
);

// 4. A database fault while resolving CheckIn/Renew is retryable. It must not
//    collapse into the terminal item-not-checked-out branch used for a genuine
//    no-row result.
$checkIn = strstr($src, 'private function handleCheckInItem(', true);
$checkIn = $checkIn === false ? '' : substr($src, strlen($checkIn));
$renewPos = strpos($checkIn, 'private function handleRenewItem(');
$checkIn = $renewPos === false ? $checkIn : substr($checkIn, 0, $renewPos);
$renew = strstr($src, 'private function handleRenewItem(', true);
$renew = $renew === false ? '' : substr($src, strlen($renew));
$lookupPos = strpos($renew, 'private function findActiveLoan(');
$renew = $lookupPos === false ? $renew : substr($renew, 0, $lookupPos);
$check(
    str_contains($src, 'bool &$databaseError')
        && str_contains($src, '$databaseError = true;')
        && str_contains($src, "SecureLogger::error('[NcipServer] findActiveLoan failed: '")
        && str_contains($checkIn, 'if ($loanLookupFailed)')
        && str_contains($checkIn, "'temporary-processing-failure'")
        && str_contains($renew, 'if ($loanLookupFailed)')
        && str_contains($renew, "'temporary-processing-failure'"),
    '04 CheckIn/Renew classify active-loan lookup faults as retryable DB errors'
);

// 5. On the wire every internal code becomes an NCIP 2.02 scheme/value pair
//    (behavioural: the real mapping, through reflection).
require_once $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';
$problemType = new ReflectionMethod(\App\Plugins\NcipServer\NcipServerPlugin::class, 'problemType');
$pe = 'http://www.niso.org/ncip/v1_0/schemes/processingerrortype/';
$me = 'http://www.niso.org/ncip/v1_0/schemes/messagingerrortype/messagingerrortype.scm';
$expected = [
    'temporary-processing-failure' => [$pe . 'generalprocessingerror.scm', 'Temporary Processing Failure'],
    'invalid-data'                 => [$pe . 'generalprocessingerror.scm', 'Element Rule Violated'],
    'unknown-item'                 => [$pe . 'lookupitemprocessingerror.scm', 'Unknown Item'],
    'unknown-user'                 => [$pe . 'lookupuserprocessingerror.scm', 'Unknown User'],
    'item-not-checked-out'         => [$pe . 'checkinitemprocessingerror.scm', 'Item Not Checked Out'],
    'maximum-renewals-exceeded'    => [$pe . 'renewitemprocessingerror.scm', 'Maximum Renewals Exceeded'],
    'user-ineligible-to-renew'     => [$pe . 'renewitemprocessingerror.scm', 'User Ineligible To Renew This Item'],
    'user-ineligible-to-request'   => [$pe . 'requestitemprocessingerror.scm', 'User Ineligible To Request This Item'],
    'unsupported-request'          => [$me, 'Unsupported Service'],
    'invalid-xml'                  => [$me, 'Invalid Message Syntax Error'],
];
$allMapped = true;
foreach ($expected as $code => $pair) {
    if ($problemType->invoke(null, $code) !== $pair) {
        $allMapped = false;
        echo "       {$code} => " . json_encode($problemType->invoke(null, $code)) . "\n";
    }
}
$check($allMapped, '05 internal codes map to the NCIP 2.02 scheme URI and phrase');

// 6. No code the plugin emits falls through to the default: every literal
//    passed to buildProblem() or produced by a $failureReason mapping is a
//    key of PROBLEM_TYPES.
$table = (new ReflectionClassConstant(\App\Plugins\NcipServer\NcipServerPlugin::class, 'PROBLEM_TYPES'))->getValue();
preg_match_all("/buildProblem\([^;]*?,\s*'([a-z-]+)'\s*\)/s", $src, $m1);
preg_match_all("/=>\s*'([a-z]+(?:-[a-z]+)+)'/", (string) preg_replace('/private const PROBLEM_TYPES = \[.*?\];/s', '', $src), $m2);
$emitted = array_unique(array_merge($m1[1], $m2[1]));
$unmapped = array_values(array_diff($emitted, array_keys($table)));
$check(count($m1[1]) > 20 && $unmapped === [], '06 every emitted problem code is mapped (unmapped: ' . implode(', ', $unmapped) . ')');

// 7. ProblemType carries the phrase, never the internal code.
$build = new ReflectionMethod(\App\Plugins\NcipServer\NcipServerPlugin::class, 'buildProblem');
$plugin = (new ReflectionClass(\App\Plugins\NcipServer\NcipServerPlugin::class))->newInstanceWithoutConstructor();
$xml = (string) $build->invoke($plugin, 'x', 'maximum-renewals-exceeded');
$check(
    str_contains($xml, 'ncip:Scheme="' . $pe . 'renewitemprocessingerror.scm"')
        && str_contains($xml, '>Maximum Renewals Exceeded</ProblemType>')
        && !str_contains($xml, 'maximum-renewals-exceeded'),
    '07 Problem/ProblemType is the scheme value with its scheme URI'
);

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
