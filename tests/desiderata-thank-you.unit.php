<?php
declare(strict_types=1);

/**
 * The donor must see the thank-you banner even when the session flash does not
 * survive the redirect.
 *
 * This is not a hypothetical. SessionPolicy lists '/' among the SESSIONLESS
 * paths, so an anonymous visitor reading the homepage is served with no session
 * and no cookie — deliberately. The donation form is on that page, so a proposal
 * sent from the homepage mints its session midway through the submission and
 * then asks a flash to survive a redirect onto a DIFFERENT page, /desiderata.
 * Nothing guarantees that continuity; the policy declines to. The twin flow,
 * submitted from /desiderata, never showed the problem because that page is
 * sessionful AND is where the donor lands, so the flash never crosses anything.
 *
 * The checks below therefore exercise the case that breaks: render the form with
 * $_SESSION deliberately EMPTY, as it is when the flash was lost, and require
 * the banner to appear from the redirect marker alone. A test that only rendered
 * with the flash present would pass against the broken code.
 *
 * No database and no HTTP: the unit is the redirect URL and the view's decision.
 *
 * Run:  php tests/desiderata-thank-you.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';

use App\Support\SessionPolicy;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

echo "A. The premise: the page the form lives on has no session\n";

// If this ever stops being true the marker is redundant, and whoever changes it
// should be told by a failing check rather than discover it years later.
$check(SessionPolicy::requiresSession('GET', [], '/', '') === false,
    "the homepage is sessionless for a visitor with no cookie — which is why a flash minted there cannot be relied on");
$check(SessionPolicy::requiresSession('GET', [], '/desiderata', '') === true,
    'while /desiderata is sessionful, which is why the twin flow never showed the problem');

echo "\nB. The redirect carries the marker, whatever the donor came from\n";

$thankYou = new ReflectionMethod(DesiderataPlugin::class, 'thankYouUrl');
$thankYou->setAccessible(true);
$marker = DesiderataPlugin::THANK_YOU_MARKER;

$cases = [
    ''                     => '/desiderata?' . $marker . '=1#donation-form',
    '/desiderata'          => '/desiderata?' . $marker . '=1#donation-form',
    '/libro/42'            => '/libro/42?' . $marker . '=1#donation-form',
    // A return path that already carries a query: a second '?' would fold the
    // marker into the previous parameter's value and do nothing at all.
    '/libro/42?da=home'    => '/libro/42?da=home&' . $marker . '=1#donation-form',
];
foreach ($cases as $returnTo => $expected) {
    $got = $thankYou->invoke(null, $returnTo);
    $check($got === $expected, "return_to=" . var_export($returnTo, true) . " -> {$got}");
}

echo "\nC. The view shows the banner from the marker, with NO session flash\n";

/** Render the real partial with a given $_GET and $_SESSION. */
$render = static function (array $get, array $session) use ($root): string {
    $_GET = $get;
    $_SESSION = $session;
    $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $value = static fn(string $k): string => '';
    $texts = [];
    $values = [];
    $error = '';
    $returnTo = '';
    $recaptchaSiteKey = '';
    ob_start();
    require $root . '/storage/plugins/desiderata/views/partials/offer-form.php';

    return (string) ob_get_clean();
};

$banner = 'Grazie!';

$withMarkerNoFlash = $render([DesiderataPlugin::THANK_YOU_MARKER => '1'], []);
$check(str_contains($withMarkerNoFlash, $banner),
    'the marker alone raises the banner — this is the case that was failing');

$withFlashNoMarker = $render([], ['desiderata_success' => true]);
$check(str_contains($withFlashNoMarker, $banner),
    'the session flash still works on its own, so same-session flows are unchanged');

$withNeither = $render([], []);
$check(!str_contains($withNeither, $banner),
    'and an ordinary visit shows no banner at all');

$wrongValue = $render([DesiderataPlugin::THANK_YOU_MARKER => 'yes'], []);
$check(!str_contains($wrongValue, $banner),
    'a marker with any other value is ignored, so the check is an equality and not a presence test');

echo "\nD. The flash is still consumed, so it cannot resurface later\n";

$_GET = [];
$_SESSION = ['desiderata_success' => true];
$render([], ['desiderata_success' => true]);
// The render above replaced $_SESSION wholesale; assert on the partial's own
// effect by rendering against a session array we can inspect afterwards.
$_SESSION = ['desiderata_success' => true, 'unrelated' => 'kept'];
$_GET = [];
(static function () use ($root): void {
    $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $value = static fn(string $k): string => '';
    $texts = [];
    $values = [];
    $error = '';
    $returnTo = '';
    $recaptchaSiteKey = '';
    ob_start();
    require $root . '/storage/plugins/desiderata/views/partials/offer-form.php';
    ob_end_clean();
})();
$check(!isset($_SESSION['desiderata_success']), 'the flash is unset once shown');
$check(($_SESSION['unrelated'] ?? null) === 'kept', 'and nothing else in the session is touched');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
