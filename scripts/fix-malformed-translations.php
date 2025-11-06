<?php
/**
 * Fix malformed __() calls in view files
 *
 * Problem: __("Text") without <?= ?> tags
 * Solution: Replace with <?= __("Text") ?>
 */

declare(strict_types=1);

$viewsDir = __DIR__ . '/../app/Views';
$fixedFiles = 0;
$totalReplacements = 0;

// Pattern to find malformed __() calls
// Looks for: >__(" or "__( without <?= before it
$patterns = [
    // Pattern 1: >__("text")
    '/(?<!<\?=\s)>(__\(["\'][^"\']+["\']\))/u' => '><?= $1 ?>',

    // Pattern 2: "__("text")
    '/(?<!<\?=\s)"(__\(["\'][^"\']+["\']\))/u' => '"<?= $1 ?>',
];

function fixFile(string $filePath, array $patterns): int
{
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $replacements = 0;

    foreach ($patterns as $pattern => $replacement) {
        $newContent = preg_replace($pattern, $replacement, $content, -1, $count);
        if ($newContent !== null && $count > 0) {
            $content = $newContent;
            $replacements += $count;
        }
    }

    // Only write if changes were made
    if ($replacements > 0) {
        file_put_contents($filePath, $content);
    }

    return $replacements;
}

function scanDirectory(string $dir, array $patterns, int &$fixedFiles, int &$totalReplacements): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $replacements = fixFile($file->getPathname(), $patterns);
            if ($replacements > 0) {
                $relativePath = str_replace(__DIR__ . '/../', '', $file->getPathname());
                echo "  ✓ {$relativePath}: {$replacements} replacement(s)\n";
                $fixedFiles++;
                $totalReplacements += $replacements;
            }
        }
    }
}

echo "🔧 Fixing malformed __() calls in view files...\n\n";

scanDirectory($viewsDir, $patterns, $fixedFiles, $totalReplacements);

echo "\n✅ Fix completed!\n";
echo "   Files fixed: {$fixedFiles}\n";
echo "   Total replacements: {$totalReplacements}\n";

if ($totalReplacements === 0) {
    echo "\n✨ No malformed __() calls found!\n";
}
