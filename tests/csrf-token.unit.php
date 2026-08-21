<?php
declare(strict_types=1);

use App\Support\Csrf;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$isGroupedToken = static fn(string $token): bool =>
    preg_match('/^(?:[a-f0-9]{8}-){7}[a-f0-9]{8}$/D', $token) === 1;

$_SESSION = [];
$issued = Csrf::ensureToken();
$check($isGroupedToken($issued), 'new tokens use eight hexadecimal groups');
$check(strlen(str_replace('-', '', $issued)) === 64, 'grouping preserves the full 256-bit payload');
$check(preg_match('/\d{13,}/', $issued) === 0, 'new tokens cannot contain a PII-like decimal run');
$check(Csrf::ensureToken() === $issued, 'ensureToken keeps the current non-expired token');
$check(Csrf::validate($issued), 'the issued token validates');
$check(Csrf::validateWithReason($issued) === ['valid' => true, 'reason' => 'valid'], 'detailed validation accepts the issued token');
$check(!Csrf::validate($issued . 'x'), 'a modified token is rejected');

$legacy = str_repeat('1', 16) . str_repeat('a', 48);
$_SESSION = [
    'csrf_token' => $legacy,
    'csrf_token_time' => time(),
];
$check(Csrf::ensureToken() === $legacy, 'a non-expired legacy hexadecimal token remains valid');
$check(Csrf::validate($legacy), 'legacy validation remains byte-for-byte compatible');

Csrf::regenerate();
$regenerated = Csrf::ensureToken();
$check($isGroupedToken($regenerated), 'explicit regeneration uses the grouped format');
$check($regenerated !== $legacy, 'explicit regeneration replaces the legacy token');
$check(preg_match('/\d{13,}/', $regenerated) === 0, 'regenerated tokens cannot contain a PII-like decimal run');

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
