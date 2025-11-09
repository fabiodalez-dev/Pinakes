<?php
/**
 * Test current locale and translations
 */

require __DIR__ . '/../vendor/autoload.php';

session_start();

echo "<!DOCTYPE html>";
echo "<html><head><meta charset='UTF-8'><title>Locale Test</title>";
echo "<style>body { font-family: system-ui; padding: 2rem; max-width: 800px; margin: 0 auto; }</style>";
echo "</head><body>";

echo "<h1>🌍 Locale Test</h1>";

echo "<h2>Session Status:</h2>";
echo "<ul>";
echo "<li><strong>Session Locale:</strong> " . ($_SESSION['locale'] ?? 'NOT SET') . "</li>";
echo "<li><strong>Default Locale:</strong> it_IT</li>";
echo "</ul>";

$selfRedirect = urlencode('/test-locale.php');
echo "<h2>Quick Actions:</h2>";
echo "<p><a href='/language/en_US?redirect={$selfRedirect}' style='display:inline-block; padding:10px 20px; background:#111827; color:white; text-decoration:none; border-radius:8px; margin-right:10px;'>🇬🇧 Switch to English</a>";
echo "<a href='/language/it_IT?redirect={$selfRedirect}' style='display:inline-block; padding:10px 20px; background:#059669; color:white; text-decoration:none; border-radius:8px;'>🇮🇹 Switch to Italian</a></p>";

echo "<h2>Translation Tests:</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Italian Key</th><th>Translated</th><th>Status</th></tr>";

$testStrings = [
    'Dashboard' => 'Dashboard',
    'Impostazioni Applicazione' => 'Application Settings',
    'Template Email' => 'Email Templates',
    'Seleziona Template' => 'Select Template',
    'Libri' => 'Books',
    'Editori' => 'Publishers',
    'Generi' => 'Genres',
    'Collocazione' => 'Location',
];

foreach ($testStrings as $italian => $expectedEnglish) {
    $translated = __($italian);
    $isCorrect = ($_SESSION['locale'] ?? 'it_IT') === 'en_US' ? ($translated === $expectedEnglish) : ($translated === $italian);
    $status = $isCorrect ? '✅' : '❌';
    echo "<tr>";
    echo "<td>" . htmlspecialchars($italian) . "</td>";
    echo "<td><strong>" . htmlspecialchars($translated) . "</strong></td>";
    echo "<td>" . $status . "</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h2>Current Translations Count:</h2>";
$locale = $_SESSION['locale'] ?? 'it_IT';
$translationFile = __DIR__ . "/../locale/{$locale}.json";
if (file_exists($translationFile)) {
    $translations = json_decode(file_get_contents($translationFile), true);
    echo "<p><strong>" . count($translations) . "</strong> translations loaded from <code>locale/{$locale}.json</code></p>";
} else {
    echo "<p style='color: red;'>⚠️ Translation file not found: locale/{$locale}.json</p>";
}

echo "<hr>";
echo "<p><a href='/admin/dashboard'>← Back to Dashboard</a> | <a href='/admin/settings'>Go to Settings</a></p>";

echo "</body></html>";
