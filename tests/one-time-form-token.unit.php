<?php
declare(strict_types=1);

use App\Support\OneTimeFormToken;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$_SESSION = [];
$first = OneTimeFormToken::issue('loan.create');
$second = OneTimeFormToken::issue('loan.create');
$other = OneTimeFormToken::issue('user.update');

$check(strlen($first) === 64 && ctype_xdigit($first), 'issued token is a 256-bit hexadecimal value');
$check($first !== $second, 'multiple tabs receive independent tokens');
$check(OneTimeFormToken::consume('loan.create', $first), 'first submission consumes its token');
$check(!OneTimeFormToken::consume('loan.create', $first), 'replayed submission is rejected');
$check(OneTimeFormToken::consume('loan.create', $second), 'consuming one tab does not invalidate another');
$check(!OneTimeFormToken::consume('loan.create', $other), 'tokens are scoped to one form');
$check(OneTimeFormToken::consume('user.update', $other), 'token remains valid in its own scope');
$check(!OneTimeFormToken::consume('loan.create', ['crafted']), 'non-scalar crafted values fail closed');
$check(!OneTimeFormToken::consume('loan.create', str_repeat('a', 63)), 'malformed token length fails closed');

$scopeError = false;
try {
    OneTimeFormToken::issue('../invalid');
} catch (InvalidArgumentException) {
    $scopeError = true;
}
$check($scopeError, 'invalid scopes are rejected');

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
