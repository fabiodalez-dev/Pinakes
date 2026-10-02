<?php
// The catalogue header's placeholders (Settings → CMS): each language shows its
// own shipped wording, and the admin's language is restored afterwards.
// Run: php tests/catalog-header.unit.php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\CatalogHeader;
use App\Support\I18n;

$n = 0;
/** Count a passing check, or stop the run on the first failing one. */
function check(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $n++;
    echo "OK {$label}\n";
}

I18n::setLocale('de_DE');
$defaults = CatalogHeader::defaultsFor(['it_IT', 'en_US']);
check(array_keys($defaults) === ['it_IT', 'en_US'], 'one entry per language asked for, in order');
check($defaults['it_IT']['title'] === 'Catalogo', 'Italian placeholder in Italian');
check($defaults['en_US']['title'] === 'Catalog', 'English placeholder in English');
check($defaults['en_US']['subtitle'] !== $defaults['it_IT']['subtitle'], 'the subtitle is translated too');
check(I18n::getLocale() === 'de_DE', 'the admin language is restored afterwards');
check(__('Catalogo') === 'Katalog', 'and its translations are the ones served again');
check(CatalogHeader::defaultsFor([]) === [] && I18n::getLocale() === 'de_DE', 'no languages: nothing to build, nothing switched');

echo "SUCCESS {$n} checks\n";
