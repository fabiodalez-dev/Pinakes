<?php
/**
 * Wrap untranslated placeholder attributes with __() function
 * Usage: php scripts/wrap-placeholders-with-i18n-v2.php [--dry-run]
 */

declare(strict_types=1);

$isDryRun = in_array('--dry-run', $argv);
$viewsDir = __DIR__ . '/../app/Views';

function scanDirectory(string $dir): array {
    $files = [];
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    ) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

function wrapPlaceholders(string $content): array {
    $count = 0;

    // Replace placeholder="text" with placeholder="<?= __('text') ?>"
    // But skip if it already contains PHP tags or __()
    $newContent = preg_replace_callback(
        '/placeholder="([^"]+)"/',
        function($match) use (&$count) {
            $fullMatch = $match[0];
            $text = $match[1];

            // Skip if already translated or contains PHP
            if (strpos($fullMatch, '__(') !== false ||
                strpos($fullMatch, '<' . '?') !== false) {
                return $fullMatch;
            }

            // Skip empty placeholders
            if (trim($text) === '') {
                return $fullMatch;
            }

            $count++;
            // Escape single quotes for PHP string
            $escaped = addslashes($text);
            // Build the replacement using concatenation to avoid escape issues
            return 'placeholder="<?= __(' . "'" . $escaped . "'" . ') ?>"';
        },
        $content
    );

    return ['content' => $newContent, 'count' => $count];
}

echo "🔧 Wrapping placeholders with __() function\n";
echo str_repeat("=", 50) . "\n\n";

if ($isDryRun) {
    echo "🔍 DRY RUN MODE - No files will be modified\n\n";
}

$files = scanDirectory($viewsDir);
$totalReplacements = 0;
$modifiedFiles = 0;

foreach ($files as $file) {
    $originalContent = file_get_contents($file);
    $result = wrapPlaceholders($originalContent);

    if ($result['count'] > 0) {
        $relativePath = str_replace($viewsDir . '/', '', $file);
        echo sprintf(
            "  %s %s: %d replacement%s\n",
            $isDryRun ? '📋' : '✓',
            $relativePath,
            $result['count'],
            $result['count'] !== 1 ? 's' : ''
        );

        $totalReplacements += $result['count'];

        if (!$isDryRun) {
            file_put_contents($file, $result['content']);
            $modifiedFiles++;
        }
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 Summary:\n";
echo "  • Files processed: " . count($files) . "\n";
echo "  • Files with changes: {$modifiedFiles}\n";
echo "  • Total replacements: {$totalReplacements}\n";

if ($isDryRun) {
    echo "\n💡 Run without --dry-run to apply changes\n";
} else {
    echo "\n✅ Placeholders wrapped successfully!\n";
    echo "\n📝 Next steps:\n";
    echo "  1. Verify: git diff app/Views/\n";
    echo "  2. Test UI in browser\n";
    echo "  3. Generate English translations\n";
    echo "  4. Commit: git add -A && git commit\n";
}
