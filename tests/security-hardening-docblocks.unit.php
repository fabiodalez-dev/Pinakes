<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, hardening follow-up: helpers inserted by the PR must
 * not steal the docblock of the method they were inserted above. An orphaned
 * `@return never` makes PHPStan treat fail() as returning, and an orphaned
 * `@return array{...}` loses the documented shape.
 *
 * Run: php tests/security-hardening-docblocks.unit.php
 */

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';
require_once $ROOT . '/storage/plugins/desiderata/DesiderataPlugin.php';
require_once $ROOT . '/storage/plugins/discogs/DiscogsPlugin.php';
require_once $ROOT . '/installer/classes/Installer.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$doc = static fn (string $class, string $method): string => (string) (new ReflectionMethod($class, $method))->getDocComment();

$cases = [
    // [class, method, must contain, must not contain]
    [\App\Controllers\LoanApprovalController::class, 'approvalWindow', '@return array{string,string}|null', null],
    [\App\Controllers\LoanApprovalController::class, 'selfDealingResponse', 'JSON refusal', '@return array'],
    ['DesiderataPlugin', 'fail', '@return never', null],
    ['DesiderataPlugin', 'assertIdentifier', 'Schema DDL interpolates', '@return never'],
    [\App\Plugins\Discogs\DiscogsPlugin::class, 'throttleHost', 'from ANY PHP worker', 'Microseconds to wait'],
    [\App\Plugins\Discogs\DiscogsPlugin::class, 'throttleWaitMicros', 'Microseconds to wait', 'from ANY PHP worker'],
    [\App\Controllers\SearchController::class, 'isOperatorSession', 'CWE-613', 'custom-role cataloguers'],
    [\App\Controllers\SearchController::class, 'isCatalogueSession', 'custom-role cataloguers', 'CWE-613'],
    ['Installer', 'readEnvFile', '@return array<string, string>|null', 'arrived over HTTPS'],
    ['Installer', 'isInstallRequestHttps', 'arrived over HTTPS', '@return array<string, string>|null'],
];
foreach ($cases as [$class, $method, $has, $hasNot]) {
    if (!class_exists($class)) {
        $check(false, "{$class} is loadable");
        continue;
    }
    $d = $doc($class, $method);
    $check(str_contains($d, $has), "{$class}::{$method}() carries its own docblock ({$has})");
    if ($hasNot !== null) {
        $check(!str_contains($d, $hasNot), "{$class}::{$method}() does not carry a neighbour's docblock ({$hasNot})");
    }
}

echo $fail === 0 ? "ALL {$pass} PASS\n" : "{$fail} FAILED, {$pass} passed\n";
exit($fail === 0 ? 0 : 1);
