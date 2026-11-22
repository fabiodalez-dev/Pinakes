<?php
declare(strict_types=1);

/**
 * Review of PR #536 (F053): three docblocks were left detached from what they
 * describe. In NotificationService::sendToAdmins() the extra $bodyFilter block
 * made PHP (and PHPStan) see only the LAST of three stacked blocks, so the
 * @param types of $variables and $dateVariables were lost; in
 * SearchIndexBuilder the searchTerms() description had become the docblock
 * of MAX_QUERY_CHARS; in Updater the copyDirectory() one sat on a constant.
 * Reflection returns the block a symbol really has.
 *
 * Run:  php tests/docblock-placement-536.unit.php   (exit 0 iff all pass)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$doc = (string) (new ReflectionMethod(App\Support\NotificationService::class, 'sendToAdmins'))->getDocComment();
$check(str_contains($doc, '@param array<string,mixed>') && str_contains($doc, '$dateVariables') && str_contains($doc, '$bodyFilter'),
    'sendToAdmins() carries all three @param types in its own docblock');

$terms = (string) (new ReflectionMethod(App\Support\SearchIndexBuilder::class, 'searchTerms'))->getDocComment();
$const = (string) (new ReflectionClassConstant(App\Support\SearchIndexBuilder::class, 'MAX_QUERY_CHARS'))->getDocComment();
$check(str_contains($terms, 'Split a raw user query') && str_contains($terms, '@return list<array{term: string, exact: bool}>'),
    'searchTerms() has its description and @return');
$check(!str_contains($const, '@return') && str_contains($const, 'Most characters of a query'), 'MAX_QUERY_CHARS has only its own docblock');

$copy = (string) (new ReflectionMethod(App\Support\Updater::class, 'copyDirectory'))->getDocComment();
$check(str_contains($copy, 'Copy directory contents'), 'copyDirectory() has its docblock back');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
