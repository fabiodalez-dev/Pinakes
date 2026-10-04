<?php
declare(strict_types=1);
/**
 * $additional_css is printed inside the layout's own <style>. A view that wraps
 * it in another <style> turns that tag into the first selector, so the browser
 * drops the first rule, and its </style> closes the layout's block early. The
 * events pages lost their listing padding and the event cover's margins this way.
 */
$root = dirname(__DIR__);
$count = 0;
$views = array_merge(glob($root . '/app/Views/frontend/*.php') ?: [], glob($root . '/app/Views/frontend/partials/*.php') ?: []);
foreach ($views as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match_all('/\$additional_css\s*=\s*(.*?);\s*$/ms', $src, $m)) {
        continue;
    }
    foreach ($m[1] as $value) {
        $count++;
        if (stripos($value, '<style') !== false || stripos($value, '</style') !== false) {
            fwrite(STDERR, 'FAIL ' . basename($file) . ": \$additional_css carries its own <style> tag\n");
            exit(1);
        }
    }
}
if ($count < 5) {
    fwrite(STDERR, "FAIL only $count \$additional_css assignments found: the scan is not reading the views\n");
    exit(1);
}
$shared = require $root . '/app/Views/frontend/partials/static-page-css.php';
if (!is_string($shared) || !str_contains($shared, '.static-content') || stripos($shared, '<style') !== false) {
    fwrite(STDERR, "FAIL the shared text-page CSS partial must return bare CSS\n");
    exit(1);
}
echo "SUCCESS $count \$additional_css assignments carry bare CSS\n";
