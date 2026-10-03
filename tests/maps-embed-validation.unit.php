<?php
declare(strict_types=1);

/**
 * The contacts map embed: what is accepted, what is refused, and what is
 * actually stored.
 *
 * Reported from a live library: a snippet copied from OpenStreetMap's own
 * Share panel was refused with "URL non valido". The site hands out
 * `/export/embed?bbox=...` and the validator only knew `/export/embed.html`,
 * a spelling OSM has moved away from — so the one place a person is told to
 * copy from produced the one thing the form would not take.
 *
 * None of these rules had a test. They live behind an authenticated POST,
 * which is exactly how a provider can change its URL and nothing notices.
 *
 *   php tests/maps-embed-validation.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\MapEmbed;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

// -------------------------------------------------------------------------
// A. Both OpenStreetMap spellings, because OSM serves both
// -------------------------------------------------------------------------
echo "\nA. OpenStreetMap\n";

$bbox = '11.85524046421051%2C45.407276655626355%2C11.857686638832092%2C45.40873413972526';

// The exact snippet OSM's Share panel produces today, entities and all.
$osmToday = '<iframe width="425" height="350" src="https://www.openstreetmap.org/export/embed?bbox='
    . $bbox . '&amp;layer=mapnik" style="border: 1px solid black"></iframe><br/>'
    . '<small><a href="https://www.openstreetmap.org/#map=19/45.408005/11.856464">Visualizza mappa ingrandita</a></small>';

$url = MapEmbed::extractUrl($osmToday);
$check(MapEmbed::provider($url) === MapEmbed::PROVIDER_OSM,
    'the snippet OpenStreetMap hands out today is accepted');

$osmLegacy = '<iframe src="https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&amp;layer=mapnik"></iframe>';
$check(MapEmbed::provider(MapEmbed::extractUrl($osmLegacy)) === MapEmbed::PROVIDER_OSM,
    'and so is the older .html spelling, which existing installs already store');

$check(MapEmbed::provider('https://www.openstreetmap.org/export/embed?bbox=' . $bbox) === MapEmbed::PROVIDER_OSM,
    'a bare URL works as well as a full snippet');

// The address bar is not an embed endpoint: OSM refuses to be framed there.
$check(MapEmbed::provider('https://www.openstreetmap.org/search?query=Piazza+Caduti#map=19/45.4/11.8') === MapEmbed::PROVIDER_NONE,
    'but a plain openstreetmap.org page is not an embed and stays refused');

// -------------------------------------------------------------------------
// B. The entities in a pasted snippet
// -------------------------------------------------------------------------
echo "\nB. Query string survives the round trip\n";

// A snippet's src is HTML, so its separators arrive encoded. Kept encoded and
// escaped again on the way out they become `&amp;amp;`, and the browser then
// asks for a parameter named `amp;layer` — the map loses every argument after
// the first, silently, and OSM falls back to defaults that may not match.
$stored = MapEmbed::buildIframe($url, MapEmbed::PROVIDER_OSM);
$srcInPage = '';
if (preg_match('/src="([^"]+)"/', $stored, $m) === 1) {
    $srcInPage = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
parse_str((string) parse_url($srcInPage, PHP_URL_QUERY), $params);

$check(array_key_exists('layer', $params),
    'the browser receives a parameter called layer');
$check(!array_key_exists('amp;layer', $params),
    'and not one called amp;layer (double-escaped separator)');
$check(($params['layer'] ?? '') === 'mapnik',
    'carrying the value that was pasted');
$check(($params['bbox'] ?? '') === '11.85524046421051,45.407276655626355,11.857686638832092,45.40873413972526',
    'and the bounding box intact');

// -------------------------------------------------------------------------
// C. Google Maps
// -------------------------------------------------------------------------
echo "\nC. Google Maps\n";

$check(MapEmbed::provider('https://www.google.com/maps/embed?pb=!1m18!1m12!1m3') === MapEmbed::PROVIDER_GOOGLE,
    'the standard Google embed URL is accepted');
$check(MapEmbed::provider('https://www.google.com/maps/embed/v1/place?q=Padova&key=x') === MapEmbed::PROVIDER_GOOGLE,
    'and so is the Embed API path');
$check(MapEmbed::provider('https://www.google.com/maps/place/Padova') === MapEmbed::PROVIDER_NONE,
    'a normal Google Maps page is not an embed');

// -------------------------------------------------------------------------
// D. What must not get through
// -------------------------------------------------------------------------
echo "\nD. Refusals\n";

$refusals = [
    'http://www.openstreetmap.org/export/embed?bbox=1' => 'plain HTTP',
    'https://evil.example/export/embed?bbox=1' => 'the right path on the wrong host',
    'https://www.openstreetmap.org/export/embed.html.evil?bbox=1' => 'a path that merely starts like the real one',
    'https://www.google.com/maps/embedsomething?pb=1' => 'a Google path that merely starts like the real one',
    'https://user:pass@www.google.com/maps/embed?pb=1' => 'embedded credentials',
    'javascript:alert(1)' => 'a javascript: URL',
    '' => 'an empty value',
];
foreach ($refusals as $candidate => $why) {
    $check(MapEmbed::provider((string) $candidate) === MapEmbed::PROVIDER_NONE, "refused: {$why}");
}

// Only the URL survives; the attributes are ours.
$hostile = '<iframe src="https://www.google.com/maps/embed?pb=1" onload="alert(1)" sandbox="allow-scripts"></iframe>';
$rebuilt = MapEmbed::buildIframe(MapEmbed::extractUrl($hostile), MapEmbed::PROVIDER_GOOGLE);
$check(strpos($rebuilt, 'onload') === false, 'an onload handler on the pasted iframe is dropped');
$check(strpos($rebuilt, 'sandbox') === false, 'and so is any other attribute that came with it');
$check(strpos($rebuilt, 'data-map-provider="google"') !== false, 'the rebuilt iframe records the provider');

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
