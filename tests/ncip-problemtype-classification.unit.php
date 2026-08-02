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

// 2. CheckOutItem: same shape for its reasons.
$checkout = $matchArm($src, 'user-loan-limit-reached');
$check(
    $checkout !== ''
        && str_contains($checkout, 'unknown-item')
        && str_contains($checkout, 'duplicate-request')
        && str_contains($checkout, 'user-ineligible-to-check-out')
        && str_contains($checkout, 'user-loan-limit-reached')
        && (bool) preg_match("/default\\s*=>\\s*'temporary-processing-failure'/", $checkout),
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

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
