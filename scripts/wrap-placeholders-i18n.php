<?php
/**
 * Wrap untranslated placeholder attributes with __() function
 * Usage: php scripts/wrap-placeholders-i18n.php [--dry-run]
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

    $newContent = preg_replace_callback(
        '/placeholder="([^"]+)"/',
        function($match) use (&$count) {
            $fullMatch = $match[0];
            $text = $match[1];

            // Skip if already translated
            if (strpos($fullMatch, '__(') !== false) {
                return $fullMatch;
            }

            // Skip if contains PHP tags
            if (strpos($fullMatch, '<?') !== false) {
                return $fullMatch;
            }

            // Skip empty
            if (trim($text) === '') {
                return $fullMatch;
            }

            $count++;

            // Escape backslashes and single quotes
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $text);

            // Return new placeholder with translation
            $openTag = '<?=';
            $closeTag = '?>';
            $replacement = "placeholder=\"{$openTag} __('{$escaped}') {$closeTag}\"";

            return $replacement;
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
echo "  • Total files scanned: " . count($files) . "\n";
echo "  • Files with changes: " . ($isDryRun ? 'N/A (dry-run)' : $modifiedFiles) . "\n";
echo "  • Total replacements: {$totalReplacements}\n";

if ($isDryRun) {
    echo "\n💡 Run without --dry-run to apply changes:\n";
    echo "   php scripts/wrap-placeholders-i18n.php\n";
} else {
    echo "\n✅ Done!\n";
    echo "\n📝 Next: Generate English translations and test\n";
}
