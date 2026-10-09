<?php
declare(strict_types=1);

/**
 * NCIP date and ResponseHeader shape (no DB needed).
 *
 *   - DateDue / DateAvailable are built from the stored local DATE without a
 *     timezone shift: under Europe/Rome, gmdate(strtotime('2026-10-20'))
 *     produced 2026-10-19T23:59:59Z, a day early for every partner.
 *   - The root carries the namespace-qualified ncip:version attribute.
 *   - ResponseHeader: FromAgencyId is this responder, ToAgencyId echoes the
 *     initiator's FromAgencyId (value and scheme); no header when the
 *     initiator did not identify itself (ToAgencyId is mandatory).
 *   - CheckInItemResponse carries no DateReturned (not in the XSD).
 *
 * Run: php tests/ncip-due-date-and-header.unit.php   (exit 0 iff all pass)
 */

use App\Plugins\NcipServer\NcipServerPlugin;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

date_default_timezone_set('Europe/Rome');

$endOfDay = new ReflectionMethod(NcipServerPlugin::class, 'endOfDayDateTime');
$check($endOfDay->invoke(null, '2026-10-20') === '2026-10-20T23:59:59Z', '01 2026-10-20 → 2026-10-20T23:59:59Z under Europe/Rome');
$check($endOfDay->invoke(null, '2026-03-29') === '2026-03-29T23:59:59Z', '02 DST change day keeps its calendar date');
$check($endOfDay->invoke(null, '2026-10-20 00:00:00') === '2026-10-20T23:59:59Z', '03 a DATETIME-shaped value keeps its date part');

$rc = new ReflectionClass(NcipServerPlugin::class);
/** @var NcipServerPlugin $plugin */
$plugin = $rc->newInstanceWithoutConstructor();
// Responder agency without touching the DB: pre-fill the per-request cache.
$rc->getProperty('responderAgencyCache')->setValue($plugin, 'PINAKES');

$checkout = new ReflectionMethod(NcipServerPlugin::class, 'buildCheckOutItemResponse');
$checkin  = new ReflectionMethod(NcipServerPlugin::class, 'buildCheckInItemResponse');
$request  = new ReflectionMethod(NcipServerPlugin::class, 'buildRequestItemResponse');

$xml = (string) $checkout->invoke($plugin, 7, 9, '2026-10-20');
$check(str_contains($xml, '<DateDue>2026-10-20T23:59:59Z</DateDue>'), '04 CheckOutItemResponse DateDue is the stored day');
$check(
    (bool) preg_match('/<NCIPMessage [^>]*ncip:version="http:\/\/www\.niso\.org\/schemas\/ncip\/v2_02\/ncip_v2_02\.xsd"/', $xml)
        && !preg_match('/<NCIPMessage [^>]*\sversion=/', $xml),
    '05 root uses ncip:version, not an unqualified version attribute'
);
$check(!str_contains($xml, 'ResponseHeader'), '06 no ResponseHeader when the initiator sent no FromAgencyId');

$xml = (string) $request->invoke($plugin, 7, 9, '2026-10-20');
$check(str_contains($xml, '<DateAvailable>2026-10-20T23:59:59Z</DateAvailable>'), '07 RequestItemResponse DateAvailable is the stored day');

// Initiator identified with a scheme: echoed back as ToAgencyId.
$initiator = new ReflectionMethod(NcipServerPlugin::class, 'initiatorAgency');
$scheme = 'http://example.org/schemes/agencyid.scm';
foreach ([
    'qualified'   => '<NCIPMessage xmlns="http://www.niso.org/2008/ncip" xmlns:ncip="http://www.niso.org/2008/ncip"><CheckInItem><InitiationHeader><FromAgencyId><AgencyId ncip:Scheme="' . $scheme . '">IT-RM0001</AgencyId></FromAgencyId><ToAgencyId><AgencyId>PINAKES</AgencyId></ToAgencyId></InitiationHeader><ItemId><ItemIdentifierValue>7</ItemIdentifierValue></ItemId></CheckInItem></NCIPMessage>',
    'no namespace' => '<NCIPMessage><CheckInItem><InitiationHeader><FromAgencyId><AgencyId Scheme="' . $scheme . '">IT-RM0001</AgencyId></FromAgencyId></InitiationHeader></CheckInItem></NCIPMessage>',
] as $label => $raw) {
    $agency = $initiator->invoke($plugin, new SimpleXMLElement($raw), 'CheckInItem');
    $check($agency === ['value' => 'IT-RM0001', 'scheme' => $scheme], "08 initiator FromAgencyId parsed ({$label})");
}
$noScheme = $initiator->invoke(
    $plugin,
    new SimpleXMLElement('<NCIPMessage><CheckInItem><InitiationHeader><FromAgencyId><AgencyId>LIB-X</AgencyId></FromAgencyId></InitiationHeader></CheckInItem></NCIPMessage>'),
    'CheckInItem'
);
$check($noScheme === ['value' => 'LIB-X', 'scheme' => null], '09 initiator without scheme → no scheme');

$rc->getProperty('initiatorAgency')->setValue($plugin, ['value' => 'IT-RM0001', 'scheme' => $scheme]);
$xml = (string) $checkin->invoke($plugin, 7);
$doc = new SimpleXMLElement($xml);
$doc->registerXPathNamespace('n', 'http://www.niso.org/2008/ncip');
$from = $doc->xpath('//n:ResponseHeader/n:FromAgencyId/n:AgencyId');
$to   = $doc->xpath('//n:ResponseHeader/n:ToAgencyId/n:AgencyId');
$check(
    is_array($from) && count($from) === 1 && (string) $from[0] === 'PINAKES'
        && (string) ($from[0]->attributes('http://www.niso.org/2008/ncip')['Scheme'] ?? '') === $scheme,
    '10 FromAgencyId is this responder (PINAKES), with the request scheme'
);
$check(
    is_array($to) && count($to) === 1 && (string) $to[0] === 'IT-RM0001'
        && (string) ($to[0]->attributes('http://www.niso.org/2008/ncip')['Scheme'] ?? '') === $scheme,
    '11 ToAgencyId echoes the initiator as received'
);
$check(!str_contains($xml, 'DateReturned'), '12 CheckInItemResponse has no DateReturned');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
