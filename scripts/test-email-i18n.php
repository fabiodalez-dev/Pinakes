<?php
/**
 * Test email templates i18n functionality
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$db = new mysqli(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_USER'] ?? 'root',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? 'biblioteca'
);

if ($db->connect_error) {
    die("❌ Connection failed: " . $db->connect_error . "\n");
}

$db->set_charset('utf8mb4');

echo "🧪 Testing Email Templates i18n System\n";
echo "======================================\n\n";

// Test 1: Verify templates exist for both locales
echo "📋 Test 1: Check template counts per locale\n";
$result = $db->query("SELECT locale, COUNT(*) as count FROM email_templates GROUP BY locale ORDER BY locale");
$totalTemplates = 0;
while ($row = $result->fetch_assoc()) {
    echo "  • {$row['locale']}: {$row['count']} templates\n";
    $totalTemplates += (int)$row['count'];
}
echo "  ✓ Total: {$totalTemplates} templates\n\n";

// Test 2: Verify specific template exists in both languages
echo "📋 Test 2: Check 'loan_approved' template in both languages\n";
$testTemplate = 'loan_approved';
foreach (['it_IT', 'en_US'] as $locale) {
    $stmt = $db->prepare("SELECT subject FROM email_templates WHERE name = ? AND locale = ?");
    $stmt->bind_param('ss', $testTemplate, $locale);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo "  • {$locale}: {$row['subject']}\n";
    } else {
        echo "  ❌ {$locale}: Template NOT FOUND\n";
    }
    $stmt->close();
}
echo "\n";

// Test 3: Verify English translations are actually in English
echo "📋 Test 3: Verify English translations contain English words\n";
$stmt = $db->prepare("SELECT subject, body FROM email_templates WHERE name = 'user_account_approved' AND locale = 'en_US'");
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $englishWords = ['approved', 'access', 'details', 'Welcome'];
    $found = 0;
    $body = $row['body'];

    foreach ($englishWords as $word) {
        if (stripos($body, $word) !== false) {
            $found++;
        }
    }

    echo "  • Found {$found}/{count($englishWords)} English keywords in body\n";
    echo "  • Subject: {$row['subject']}\n";

    if ($found >= 3) {
        echo "  ✓ English translation verified\n";
    } else {
        echo "  ⚠ Warning: English translation may not be complete\n";
    }
} else {
    echo "  ❌ English template not found\n";
}
$stmt->close();
echo "\n";

// Test 4: Verify Italian translations contain Italian words
echo "📋 Test 4: Verify Italian translations contain Italian words\n";
$stmt = $db->prepare("SELECT subject, body FROM email_templates WHERE name = 'user_account_approved' AND locale = 'it_IT'");
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $italianWords = ['approvato', 'accedere', 'dettagli', 'Benvenuto'];
    $found = 0;
    $body = $row['body'];

    foreach ($italianWords as $word) {
        if (stripos($body, $word) !== false) {
            $found++;
        }
    }

    echo "  • Found {$found}/" . count($italianWords) . " Italian keywords in body\n";
    echo "  • Subject: {$row['subject']}\n";

    if ($found >= 3) {
        echo "  ✓ Italian translation verified\n";
    } else {
        echo "  ⚠ Warning: Italian translation may not be complete\n";
    }
} else {
    echo "  ❌ Italian template not found\n";
}
$stmt->close();
echo "\n";

// Test 5: List all templates to verify variety
echo "📋 Test 5: List all template names\n";
$result = $db->query("SELECT DISTINCT name FROM email_templates ORDER BY name");
$templates = [];
while ($row = $result->fetch_assoc()) {
    $templates[] = $row['name'];
}
echo "  Templates available (" . count($templates) . " unique):\n";
foreach ($templates as $name) {
    echo "    - {$name}\n";
}
echo "\n";

echo "✅ All tests completed!\n\n";

echo "📊 Summary:\n";
echo "  • Total templates: {$totalTemplates}\n";
echo "  • Locales supported: 2 (it_IT, en_US)\n";
echo "  • Unique template names: " . count($templates) . "\n";
echo "  • Expected: " . (count($templates) * 2) . " total templates\n";

if ($totalTemplates === count($templates) * 2) {
    echo "  ✓ Template count matches expectation!\n";
} else {
    echo "  ⚠ Warning: Template count mismatch\n";
}

$db->close();
