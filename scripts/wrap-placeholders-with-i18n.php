<?php
/**
 * Wrap untranslated placeholder attributes with __() function
 *
 * Usage: php scripts/wrap-placeholders-with-i18n.php [--dry-run] [--file=path/to/file.php]
 */

declare(strict_types=1);

$isDryRun = in_array('--dry-run', $argv);
$specificFile = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $specificFile = substr($arg, 7);
    }
}

$viewsDir = __DIR__ . '/../app/Views';

function scanDirectory(string $dir): array {
    $files = [];
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $files = array_merge($files, scanDirectory($path));
        } elseif (str_ends_with($item, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

function processFile(string $file, bool $dryRun): array {
    $content = file_get_contents($file);
    $originalContent = $content;
    $replacements = 0;

    // Pattern: placeholder="text" where text doesn't contain PHP tags or __() function
    // We need to match and replace placeholder="..." with placeholder="<?= __('...') ?>"

    $pattern = '/placeholder="([^"]+)"/';

    $content = preg_replace_callback($pattern, function($matches) use (&$replacements) {
        $fullMatch = $matches[0]; // e.g., placeholder="Titolo libro"
        $placeholderText = $matches[1];

        // Skip if already uses __() or contains PHP tags
        if (strpos($fullMatch, '__(') !== false ||
            strpos($fullMatch, '<?') !== false) {
            return $fullMatch;
        }

        // Skip if placeholder is empty or just whitespace
        if (trim($placeholderText) === '') {
            return $fullMatch;
        }

        $replacements++;

        // Escape single quotes in the placeholder text
        $escapedText = str_replace("'", "\\'", $placeholderText);

        // Return wrapped version
        return "placeholder=\"<?= __('" . $escapedText . "') ?>\"";


    }, $content);

    $stats = [
        'file' => $file,
        'replacements' => $replacements,
        'modified' => $content !== $originalContent
    ];

    if (!$dryRun && $stats['modified']) {
        file_put_contents($file, $content);
    }

    return $stats;
}

echo "🔧 Wrapping placeholders with __() function\n";
echo str_repeat("=", 50) . "\n\n";

if ($isDryRun) {
    echo "🔍 DRY RUN MODE - No files will be modified\n\n";
}

$files = $specificFile ? [$viewsDir . '/' . $specificFile] : scanDirectory($viewsDir);

$totalReplacements = 0;
$modifiedFiles = 0;
$processedFiles = 0;

foreach ($files as $file) {
    if ($specificFile && !str_ends_with($file, $specificFile)) {
        continue;
    }

    $stats = processFile($file, $isDryRun);
    $processedFiles++;

    if ($stats['replacements'] > 0) {
        $relativePath = str_replace($viewsDir . '/', '', $stats['file']);
        echo sprintf(
            "  %s %s: %d replacement%s\n",
            $isDryRun ? '📋' : '✓',
            $relativePath,
            $stats['replacements'],
            $stats['replacements'] !== 1 ? 's' : ''
        );

        $totalReplacements += $stats['replacements'];

        if ($stats['modified']) {
            $modifiedFiles++;
        }
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 Summary:\n";
echo "  • Files processed: {$processedFiles}\n";
echo "  • Files modified: {$modifiedFiles}\n";
echo "  • Total replacements: {$totalReplacements}\n";

if ($isDryRun) {
    echo "\n💡 Run without --dry-run to apply changes\n";
    echo "💡 Example: php scripts/wrap-placeholders-with-i18n.php\n";
} else {
    echo "\n✅ Placeholders wrapped successfully!\n";
    echo "\n📝 Next steps:\n";
    echo "  1. Verify changes: git diff app/Views/\n";
    echo "  2. Test a few pages to ensure UI works\n";
    echo "  3. Generate English translations for locale/en_US.php\n";
    echo "  4. Commit changes: git add -A && git commit\n";
}
