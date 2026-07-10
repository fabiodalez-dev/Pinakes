<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$layout = file_get_contents($root . '/app/Views/frontend/layout.php');
$home = file_get_contents($root . '/app/Views/frontend/home.php');
$detail = file_get_contents($root . '/app/Views/frontend/book-detail.php');
$scroll = file_get_contents($root . '/app/Views/partials/scroll-to-top.php');

$checks = [
    'related-book badge has a solid background fallback' => str_contains($detail, "background: var(--success-color); /* fallback for browsers without color-mix() */\n        background: color-mix(in srgb, var(--success-color) 95%, transparent);"),
    'global muted text token meets AA on white' => str_contains($layout, '--text-muted: #64748b;'),
    'mobile search control has a 44px target' => preg_match('/\.mobile-search-toggle\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/s', $layout) === 1,
    'mobile menu control has a 44px target' => preg_match('/\.mobile-menu-toggle\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/s', $layout) === 1,
    'hero search button has a 44px target' => preg_match('/\.hero-search-button\s*\{[^}]*min-height:\s*44px;/s', $home) === 1,
    'scroll-to-top control has a 44px target' => str_contains($scroll, 'width:44px;height:44px'),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
