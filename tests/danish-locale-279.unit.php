<?php
declare(strict_types=1);

/**
 * Regression contract for #279: Danish must be complete at install time, not
 * only after switching locale on an already-configured application.
 *
 * Run: php tests/danish-locale-279.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$label}\n";
};

$readJson = static function (string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
};

echo "A. Locale catalogue and placeholders\n";
$italian = $readJson($root . '/locale/it_IT.json');
$danish = $readJson($root . '/locale/da_DK.json');
$itKeys = array_keys($italian);
$daKeys = array_keys($danish);
sort($itKeys);
sort($daKeys);
$check($daKeys === $itKeys, 'Danish and canonical Italian catalogues have identical keys');

$tinyMceKey = 'Personalizza il contenuto delle mail automatiche con l\'editor TinyMCE. Usa i segnaposto <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{variabile}}</code> per inserire dati dinamici.';
$check(str_contains((string) ($danish[$tinyMceKey] ?? ''), '{{variabile}}'), 'TinyMCE placeholder token is preserved verbatim');
$styleKey = "Il codice verrà inserito in un tag <style> nell'header. Non includere i tag <style></style>";
$styleValue = (string) ($danish[$styleKey] ?? '');
$check(str_contains($styleValue, '<style>') && str_contains($styleValue, '</style>'), 'literal style tags survive translation');
$check(
    !str_contains(json_encode($danish, JSON_UNESCAPED_UNICODE) ?: '', 'duleret')
    && !str_contains(json_encode($danish, JSON_UNESCAPED_UNICODE) ?: '', 'Verfügbar')
    && !str_contains(json_encode($danish, JSON_UNESCAPED_UNICODE) ?: '', 'analyseculies'),
    'known corrupt or cross-language translations are absent'
);

$analyticsPrefixKey = 'Per inserire il codice JavaScript Analytics (Google Analytics, Matomo, ecc.), vai su <a href=\\';
$mailPrefixKey = 'Personalizza il contenuto delle mail automatiche con l\'editor TinyMCE. Usa i segnaposto <code class=\\';
$smtpPrefixKey = 'Scegli come inviare le email dal sistema. Puoi usare la funzione PHP <code class=\\';
$check(str_contains((string) ($danish[$analyticsPrefixKey] ?? ''), '<a href='), 'legacy Analytics fragment keeps its markup prefix');
$check(str_contains((string) ($danish[$mailPrefixKey] ?? ''), '<code class='), 'legacy mail fragment keeps its markup prefix');
$check(str_contains((string) ($danish[$smtpPrefixKey] ?? ''), '<code class='), 'legacy SMTP fragment keeps its markup prefix');

echo "B. Fresh-install defaults and routes\n";
$defaults = require $root . '/config/default_texts.php';
$daDefaults = $defaults['da_DK'] ?? [];
$check($daDefaults !== [], 'Danish has explicit installer defaults');
$check(($daDefaults['privacy']['cookie_banner_language'] ?? null) === 'da', 'installer default uses Danish cookie language');
$check(($daDefaults['privacy']['cookie_banner_country'] ?? null) === 'DK', 'installer default uses Denmark as cookie country');
$check(trim((string) ($daDefaults['privacy']['cookie_policy_content'] ?? '')) !== '', 'installer default includes cookie-policy content');

$seed = (string) file_get_contents($root . '/installer/database/data_da_DK.sql');
$check(str_contains($seed, "'Udforsk kataloget', '/katalog'"), 'homepage catalogue CTA uses the Danish route');
$check(str_contains($seed, "'Registrer dig nu', '/registrer'"), 'homepage registration CTA uses the Danish route');
$check(str_contains($seed, "'cookie_banner_language', 'da'") && str_contains($seed, "'cookie_banner_country', 'DK'"), 'database seed uses Danish cookie locale');
$check(!str_contains($seed, 'Tema predefinito') && !str_contains($seed, 'Design minimale con nero'), 'theme descriptions are not left in Italian');

\App\Support\RouteTranslator::clearCache();
$check(\App\Support\RouteTranslator::getRouteForLocale('archives', 'da_DK') === '/arkiv', 'Danish archive route resolves to /arkiv');
$archivePlugin = (string) file_get_contents($root . '/storage/plugins/archives/ArchivesPlugin.php');
$check(
    preg_match('/foreach \(\[[^\]]*\'da_DK\'[^\]]*\] as \$locale\)/', $archivePlugin) === 1,
    'archive plugin registers the Danish localized route'
);

echo "C. Mail-template variable parity\n";
$englishMail = require $root . '/app/Support/mail_templates/en_US.php';
$danishMail = require $root . '/app/Support/mail_templates/da_DK.php';
$placeholderSet = static function (mixed $value): array {
    $found = [];
    $walk = static function (mixed $item) use (&$walk, &$found): void {
        if (is_array($item)) {
            foreach ($item as $child) {
                $walk($child);
            }
            return;
        }
        if (!is_string($item)) {
            return;
        }
        preg_match_all('/\{\{[a-zA-Z0-9_]+\}\}/', $item, $matches);
        foreach ($matches[0] as $placeholder) {
            $found[$placeholder] = true;
        }
    };
    $walk($value);
    $result = array_keys($found);
    sort($result);
    return $result;
};
$check($placeholderSet($danishMail) === $placeholderSet($englishMail), 'Danish mail templates preserve the canonical variable set');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
