<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 (review of PR #536): formatDateLocale() returns String(date)
 * when the value is not a valid date, so its result is not safe HTML. Where a view
 * writes it into an innerHTML template (the loans table, the calendar pop-ups) it
 * goes through the page's HTML escaper first.
 *
 * Run:  php tests/security-audit-views-date-escape.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$views = [
    'app/Views/prestiti/index.php' => 'escHtml',
    'app/Views/dashboard/index.php' => 'escapeHtml',
    'app/Views/libri/scheda_libro.php' => 'escapeHtml',
];
foreach ($views as $file => $escaper) {
    $src = (string) file_get_contents($root . '/' . $file);
    // Every ${...formatDateLocale(...)} template hole must sit inside the escaper.
    preg_match_all('/\$\{([^}]*formatDateLocale\([^}]*)\}/', $src, $holes);
    $bad = array_filter($holes[1], static fn (string $h): bool => !str_starts_with(trim($h), $escaper . '('));
    $check($holes[1] !== [] && $bad === [], basename(dirname($file)) . '/' . basename($file) . ': every formatted date in a template goes through ' . $escaper . '()' . ($bad !== [] ? ' (unescaped: ' . implode(' | ', $bad) . ')' : ''));
}

echo PHP_EOL . "Passed: {$pass}   Failed: {$fail}" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
