<?php
// Every translated string echoed into an HTML attribute is escaped (project
// rule "View escaping"). A translation can contain a quote — "l'utente", a
// French apostrophe — and a raw one would end the attribute early.
// Run: php tests/view-attribute-escaping.unit.php
declare(strict_types=1);

$root = dirname(__DIR__);
// Calls whose result is safe in an attribute: the whole argument is escaped.
$escapers = '(?:htmlspecialchars|htmlentities|json_encode|(?:\\\\?App\\\\Support\\\\)?HtmlHelper::e|\$[A-Za-z]*[Ee]sc[A-Za-z]*|\$e|\$h|\(int\))';

/** Blank out the argument list of every escaping call, keeping the rest. */
function stripEscaped(string $expr, string $escapers): string
{
    while (preg_match('/' . $escapers . '\s*\(/', $expr, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1];
        $open = $start + strlen($m[0][0]) - 1;
        $depth = 0;
        $quote = null;
        for ($i = $open, $len = strlen($expr); $i < $len; $i++) {
            $c = $expr[$i];
            if ($quote !== null) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $quote) { $quote = null; }
                continue;
            }
            if ($c === '"' || $c === "'") { $quote = $c; continue; }
            if ($c === '(') { $depth++; }
            if ($c === ')' && --$depth === 0) { break; }
        }
        $expr = substr($expr, 0, $start) . 'SAFE' . substr($expr, $i + 1);
    }
    return $expr;
}

$violations = [];
$scanned = 0;
foreach (['app/Views', 'storage/plugins'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || str_contains($path, '/vendor/')) {
            continue;
        }
        $src = (string)file_get_contents($path);
        $scanned++;
        // An attribute whose value is a short echo or an echo statement.
        if (!preg_match_all('/\b([A-Za-z][\w:-]*)="<\?(?:=|php\s+echo)\s*((?:(?!\?>).)*?)\s*;?\s*\?>/s', $src, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[2] as $i => [$expr, $offset]) {
            // Event handlers hold JavaScript: htmlspecialchars is the wrong tool
            // there, and they are checked separately.
            if (stripos($m[1][$i][0], 'on') === 0 || !str_contains($expr, '__(')) {
                continue;
            }
            if (str_contains(stripEscaped($expr, $escapers), '__(')) {
                $violations[] = substr($path, strlen($root) + 1) . ':' . (substr_count(substr($src, 0, $offset), "\n") + 1)
                    . ' ' . $m[1][$i][0] . '="' . preg_replace('/\s+/', ' ', $expr);
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "FAIL: translated text echoed raw into an attribute:\n  " . implode("\n  ", $violations) . "\n");
    exit(1);
}
echo "SUCCESS {$scanned} view files, no raw translation in an attribute\n";
