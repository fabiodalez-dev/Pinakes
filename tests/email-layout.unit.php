<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Support\EmailLayout;
use App\Support\SettingsMailTemplates;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$fragment = '<h2>Prestito approvato</h2>'
    . '<div style="background-color:#ecfdf5;border-left:4px solid #10b981;padding:15px">Il libro è pronto.</div>'
    . '<p><a href="https://example.test/book/1" style="background-color:#3b82f6;color:white;padding:10px 20px">Apri</a></p>';
$html = EmailLayout::render($fragment, 'Aggiornamento prestito', 'it_IT');

$check(str_contains($html, '<!doctype html>') && str_contains($html, 'data-pinakes-email="1"'), 'renders a complete branded email document');
$check(str_contains($html, 'width="600"') && str_contains($html, 'role="presentation"'), 'uses a client-safe presentation table');
$check(str_contains($html, 'data-email-button="1"') && str_contains($html, 'background-color:#d70262'), 'normalizes legacy calls to action to one accent');
$check(!str_contains($html, '#3b82f6') && !str_contains($html, '#10b981') && !str_contains($html, '#ecfdf5'), 'removes the legacy random palette');
$check(!str_contains($html, 'linear-gradient') && !str_contains($html, 'cdn') && !str_contains($html, '@font-face'), 'does not introduce gradients, CDNs or remote fonts');
$check(str_contains($html, '@media screen and (max-width:640px)') && str_contains($html, 'display:block!important'), 'stacks action buttons on narrow screens');
$check(str_contains($html, 'href="https://example.test/book/1"') && str_contains($html, 'Il libro è pronto.'), 'preserves links and message content');
$check(EmailLayout::render($html, 'Ignored', 'it_IT') === $html, 'does not double-wrap an email');

$text = EmailLayout::plainText('<h2>Titolo</h2><p>Prima riga<br>Seconda riga</p>');
$check(str_contains($text, "Titolo\n") && str_contains($text, "Prima riga\nSeconda riga"), 'builds a readable plain-text fallback');

$legacyPalette = '/#(?:3b82f6|10b981|ef4444|f59e0b|1e40af|fef3c7|ecfdf5|fef2f2|fff7ed|f0f9ff)/i';
foreach (['it_IT', 'en_US', 'fr_FR', 'de_DE', 'da_DK'] as $locale) {
    $templates = SettingsMailTemplates::all($locale);
    $localeClean = $templates !== [];
    $tokensPreserved = true;
    foreach ($templates as $template) {
        $body = (string) $template['body'];
        $rendered = EmailLayout::render($body, (string) $template['subject'], $locale);
        $localeClean = $localeClean
            && preg_match($legacyPalette, $rendered) !== 1
            && !str_contains($rendered, 'linear-gradient')
            && !str_contains($rendered, '@font-face');

        preg_match_all('/\{\{[a-zA-Z0-9_]+\}\}/', $body, $matches);
        foreach (array_unique($matches[0]) as $token) {
            if (!str_contains($rendered, $token)) {
                $tokensPreserved = false;
                break 2;
            }
        }
    }
    $check($localeClean, "{$locale}: every seeded template uses the unified palette");
    $check($tokensPreserved, "{$locale}: every body placeholder is preserved");
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
