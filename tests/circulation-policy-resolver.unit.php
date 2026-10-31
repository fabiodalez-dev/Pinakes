<?php
declare(strict_types=1);

/**
 * PolicyResolver: the pure five-layer precedence of the circulation rules
 * (rule type×material → rule type×* → rule *×material → patron type →
 * global settings), resolved per field, with NULL = inherit, sentinels,
 * fallbacks for malformed values, and the two quantity limits (total from the
 * patron, per material from the matrix).
 *
 * No database. Run: php tests/circulation-policy-resolver.unit.php
 */

use App\Services\Circulation\PolicyResolver;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
};

$globals = [
    'loanable' => true, 'duration_days' => 30, 'max_request_days' => 90, 'max_renewals' => 3,
    'renewal_days' => null, 'max_active_for_media' => null, 'max_active_loans' => 5,
    'reservations_enabled' => true, 'max_reservations' => 0, 'card_duration_days' => null,
    'debt_block_threshold' => null, 'daily_fine' => null, 'grace_days' => null, 'max_fine_per_loan' => null,
];
$noType = PolicyResolver::resolveUser(null, $globals);

echo "A. Globals only\n";
$p = PolicyResolver::resolve([], $globals, $noType, 'libro');
$check($p->durationDays === 30 && $p->maxRequestDays === 90 && $p->maxRenewals === 3, 'globals pass through');
$check($p->renewalDays === 30, 'renewal length defaults to the duration');
$check($p->loanable && $p->maxActiveForMedia === 0 && $p->maxActiveLoans === 5, 'loanable, total limit 5, no media limit');
$check($p->requestDurationDays() === 30, 'request default = min(duration, max request)');
$check($p->dailyFine === null && $p->graceDays === null && $p->maxFinePerLoan === null, 'fines unset');

echo "B. Precedence, one field at a time\n";
$type = ['duration_days' => 21, 'max_request_days' => 60, 'max_renewals' => 2, 'renewal_days' => null];
$ruleAllMedia = ['duration_days' => 7, 'max_active_for_media' => 2, 'loanable' => null];
$ruleTypeStar = ['duration_days' => 14, 'max_renewals' => null];
$ruleTypeMedia = ['duration_days' => 3, 'loanable' => null, 'max_active_for_media' => 1];
$layers = [$ruleTypeMedia, $ruleTypeStar, $ruleAllMedia, $type];
$p = PolicyResolver::resolve($layers, $globals, $noType, 'dvd');
$check($p->durationDays === 3, 'layer 1 (type × material) wins');
$p = PolicyResolver::resolve([null, $ruleTypeStar, $ruleAllMedia, $type], $globals, $noType, 'dvd');
$check($p->durationDays === 14, 'layer 2 (type × *) when layer 1 is absent');
$p = PolicyResolver::resolve([['duration_days' => null], null, $ruleAllMedia, $type], $globals, $noType, 'dvd');
$check($p->durationDays === 7, 'layer 3 (* × material) when 1 and 2 are NULL');
$p = PolicyResolver::resolve([null, null, null, $type], $globals, $noType, 'dvd');
$check($p->durationDays === 21 && $p->maxRequestDays === 60 && $p->maxRenewals === 2, 'layer 4 (patron type)');
$p = PolicyResolver::resolve([null, null, null, ['duration_days' => null]], $globals, $noType, 'dvd');
$check($p->durationDays === 30, 'layer 5 (globals) when every layer is NULL');
$p = PolicyResolver::resolve($layers, $globals, $noType, 'dvd');
$check($p->maxRenewals === 2, 'renewals: layers 1-3 NULL, so the type value applies');
$check($p->maxActiveForMedia === 1, 'per-material limit from the most specific rule');
$check($p->maxRequestDays === 60, 'request maximum only from the type');
$check($p->renewalDays === 3, 'renewal length follows the resolved duration');
$check($p->requestDurationDays() === 3, 'request default follows the resolved duration');

echo "C. Fields resolve independently\n";
$p = PolicyResolver::resolve([['max_renewals' => 0], ['duration_days' => 10], null, $type], $globals, $noType, 'libro');
$check($p->maxRenewals === 0 && $p->durationDays === 10, '0 renewals from layer 1, duration from layer 2');
$p = PolicyResolver::resolve([['renewal_days' => 5], null, null, $type], $globals, $noType, 'libro');
$check($p->renewalDays === 5 && $p->durationDays === 21, 'explicit renewal length');

echo "D. Loanability\n";
$p = PolicyResolver::resolve([['loanable' => 0], null, ['loanable' => 1], null], $globals, $noType, 'dvd');
$check(!$p->loanable, 'a type × material "consultation only" beats the all-types rule');
$p = PolicyResolver::resolve([['loanable' => null, 'max_active_for_media' => 1], null, ['loanable' => 0], null], $globals, $noType, 'dvd');
$check(!$p->loanable && $p->maxActiveForMedia === 1, 'an inherit cell keeps the all-types "consultation only"');
$p = PolicyResolver::resolve([['loanable' => false], ['loanable' => 1]], $globals, $noType, 'libro');
$check(!$p->loanable, 'the copy layer (loanable false) on top wins');
$p = PolicyResolver::resolve([['loanable' => '1']], $globals, $noType, 'libro');
$check($p->loanable, 'string "1" from the database');

echo "E. Malformed values fall back\n";
$p = PolicyResolver::resolve([['duration_days' => 0, 'max_renewals' => -1, 'max_active_for_media' => 'x']], $globals, $noType, 'libro');
$check($p->durationDays === 30, 'non-positive duration → global');
$check($p->maxRenewals === 3, 'negative renewals → global');
$check($p->maxActiveForMedia === 0, 'non-numeric media limit → none');
$p = PolicyResolver::resolve([['duration_days' => '']], $globals, $noType, 'libro');
$check($p->durationDays === 30, 'empty string = inherit');
$bad = ['duration_days' => -4, 'max_request_days' => 'abc', 'max_renewals' => null] + $globals;
$p = PolicyResolver::resolve([], $bad, $noType, 'libro');
$check($p->durationDays === 30 && $p->maxRequestDays === 90 && $p->maxRenewals === 3, 'malformed globals → historical defaults');

echo "F. Patron-only limits\n";
$u = PolicyResolver::resolveUser(['id' => 4, 'code' => 'studente', 'name' => 'Studente', 'max_active_loans' => 2,
    'reservations_enabled' => '0', 'max_reservations' => 1, 'card_duration_days' => 365, 'debt_block_threshold' => '10.50'], $globals);
$check($u->patronTypeId === 4 && $u->patronTypeCode === 'studente', 'type identity');
$check($u->maxActiveLoans === 2, 'type total limit beats the global 5');
$check(!$u->reservationsEnabled && $u->maxReservations === 1, 'reservations disabled, limit 1');
$check($u->cardDurationDays === 365 && $u->debtBlockThreshold === 10.5, 'card duration and debt threshold');
$u = PolicyResolver::resolveUser(['id' => 1, 'max_active_loans' => null, 'reservations_enabled' => 1, 'card_duration_days' => 0], $globals);
$check($u->maxActiveLoans === 5, 'NULL type limit inherits the global');
$check($u->maxActiveLoans === 5 && $u->reservationsEnabled, 'reservations enabled');
$check($u->cardDurationDays === null, 'card duration 0 = not automatic');
$u = PolicyResolver::resolveUser(['id' => 1, 'max_active_loans' => 0], $globals);
$check($u->maxActiveLoans === 0, 'type limit 0 = unlimited even with a global limit');
$p = PolicyResolver::resolve([], $globals, $u, 'libro');
$check($p->maxActiveLoans === 0 && !$p->hasQuantityLimits(), 'the loan policy carries the patron total limit');
$p = PolicyResolver::resolve([['max_active_for_media' => 1]], $globals, $u, 'dvd');
$check($p->hasQuantityLimits(), 'a media limit alone is a quantity limit');

echo "G. Fines fields carried (not applied yet)\n";
$p = PolicyResolver::resolve([['daily_fine' => '0.50'], null, ['grace_days' => 2], ['max_fine_per_loan' => '5']], $globals, $u, 'dvd');
$check($p->dailyFine === 0.5 && $p->graceDays === 2 && $p->maxFinePerLoan === 5.0, 'fines resolve with the same precedence');

if ($failed > 0) {
    echo "\nFAILED: {$failed} of " . ($passed + $failed) . " checks\n";
    exit(1);
}
echo "\nALL {$passed} PASS\n";
