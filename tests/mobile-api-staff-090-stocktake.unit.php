<?php
declare(strict_types=1);

/**
 * Mobile API 1.10.0 — stocktake vocabulary (StocktakeVocabulary): the English
 * codes of the API map one to one to the values StocktakeService stores, the
 * counters and filter keys translate without losing a key, the colours and
 * tones are the web scan page's, and datetimes come out as ISO 8601.
 *
 * Pure: no database. Run: php tests/mobile-api-staff-090-stocktake.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
if (!function_exists('__')) {
    function __(string $s): string
    {
        return $s;
    }
}
require_once $root . '/storage/plugins/mobile-api/src/Staff090/Support/StocktakeVocabulary.php';

use App\Plugins\MobileApi\Staff090\Support\StocktakeVocabulary as V;
use App\Services\StocktakeService;

$pass = 0;
$fail = 0;
function check(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "OK {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}

// Every value the service stores has a code, and the codes are distinct.
foreach (array_unique([...StocktakeService::OUTCOMES, ...StocktakeService::CLOSE_OUTCOMES]) as $value) {
    if ($value === 'persa') {
        continue; // frozen as part of "missing" (summary.lost), never a scan or report category
    }
    check(isset(V::OUTCOMES[$value]), "service outcome '{$value}' has an API code");
}
check(count(array_unique(V::OUTCOMES)) === count(V::OUTCOMES), 'outcome codes are distinct');
foreach (V::OUTCOMES as $value => $code) {
    check(V::outcomeValue($code) === $value, "outcome '{$code}' maps back to '{$value}'");
    check(isset(V::OUTCOME_COLORS[$value]), "outcome '{$value}' has a colour");
}
check(V::outcomeValue('bogus') === null, 'an unknown outcome code is refused');
check(V::outcome('trovata') === 'found' && V::outcome('fuori_posto') === 'misplaced' && V::outcome('in_prestito') === 'on_loan', 'outcome codes read in English');

foreach (V::STATUSES as $value => $code) {
    check(V::statusValue($code) === $value && V::status($value) === $code, "status '{$value}' <-> '{$code}'");
}
check(V::statusValue('all') === null, "'all' is not a status");
check(V::missingActionValue('none') === 'nessuna' && V::missingActionValue('lost') === 'perso', 'missing actions map to the web form values');
check(V::missingActionValue('burn') === null, 'an unknown missing action is refused');
check(V::missingAction(null) === null && V::missingAction('') === null && V::missingAction('perso') === 'lost', 'a session not closed has no missing action');

// Colours and tones: the web badges and beeps.
check(V::outcomeColor('trovata') === ['name' => 'green', 'foreground' => '#166534', 'background' => '#dcfce7'], 'found is green');
check(V::outcomeColor('fuori_posto')['name'] === 'amber' && V::outcomeColor('in_prestito')['name'] === 'blue', 'misplaced amber, on loan blue');
check(V::outcomeColor('fuori_ambito')['name'] === 'purple' && V::outcomeColor('duplicata')['name'] === 'gray', 'out of scope purple, duplicate gray');
check(V::outcomeColor('sconosciuta')['name'] === 'red' && V::outcomeColor('mancante')['name'] === 'red', 'unknown and missing red');
check(V::outcomeColor('whatever')['name'] === 'gray', 'an unknown value falls back to gray');
check(V::statusColor('aperta')['name'] === 'green' && V::statusColor('annullata')['name'] === 'red', 'status colours');
check(V::tone('trovata') === ['frequency_hz' => 880, 'duration_ms' => 120], 'found beeps 880 Hz for 120 ms');
check(V::tone('sconosciuta') === ['frequency_hz' => 220, 'duration_ms' => 450], 'unknown beeps 220 Hz for 450 ms');
check(V::tone('mancante') === null, 'missing is never a scan answer: no tone');
foreach (StocktakeService::OUTCOMES as $value) {
    check(V::tone($value) !== null, "scan outcome '{$value}' has a tone");
}

// Counters: every service key, nothing else, 0 when absent.
$summary = ['attese' => 5, 'trovate' => 2, 'fuori_posto' => 1, 'in_prestito' => 1, 'mancanti' => 1, 'perse' => 0, 'sconosciute' => 3, 'fuori_ambito' => 1, 'duplicate' => 2, 'scansioni' => 9, 'mie' => 4];
$mapped = V::summary($summary);
check($mapped === ['expected' => 5, 'found' => 2, 'misplaced' => 1, 'on_loan' => 1, 'missing' => 1, 'lost' => 0, 'unknown' => 3, 'out_of_scope' => 1, 'duplicates' => 2, 'scans' => 9, 'mine' => 4], 'summary keys in English, values kept');
check(V::summary([])['expected'] === 0 && count(V::summary([])) === 11, 'an empty summary has every counter at 0');

// Filters: API keys <-> the form keys StocktakeService::normalizeFilter reads.
$input = V::filterInput(['shelf_id' => 3, 'level_id' => '4', 'media_type' => 'dvd', 'collocation_to' => 'B', 'name' => 'x']);
check($input === ['scaffale_id' => 3, 'mensola_id' => '4', 'tipo_media' => 'dvd', 'collocazione_a' => 'B'], 'filter input keeps only filter keys, renamed');
check(StocktakeService::normalizeFilter(array_map(static fn ($v) => is_int($v) ? (string) $v : $v, $input)) === ['scaffale_id' => 3, 'mensola_id' => 4, 'tipo_media' => 'dvd', 'collocazione_a' => 'B'], 'the renamed input is what the service accepts');
$filter = V::filter(['scaffale_id' => 3, 'tipo_media' => 'libro']);
check($filter === ['shelf_id' => 3, 'level_id' => null, 'genre_id' => null, 'media_type' => 'libro', 'collocation_from' => null, 'collocation_to' => null], 'a stored filter reads back with every API key');
foreach (V::FILTER_KEYS as $api => $service) {
    check(array_key_exists($service, StocktakeService::normalizeFilter([$service => $api === 'media_type' ? 'libro' : '7'])), "filter key '{$api}' is one the service understands");
}

// Datetimes.
// Instants: epoch seconds computed by MySQL (UNIX_TIMESTAMP of the stored
// DATETIME), shown in the library zone with the offset of that date. PHP's
// default zone is set apart on purpose: it must play no part.
date_default_timezone_set('Asia/Tokyo');
$rome = new \DateTimeZone('Europe/Rome');
$winter = 1793521800; // 2026-11-01 08:30:00 UTC
$summer = 1782894600; // 2026-07-01 08:30:00 UTC
check(V::instant($winter, $rome) === '2026-11-01T09:30:00+01:00', 'a winter instant carries the library winter offset (+01:00)');
check(V::instant($summer, $rome) === '2026-07-01T10:30:00+02:00', 'a summer instant carries the summer offset (+02:00)');
check(V::instant((string) $summer, new \DateTimeZone('UTC')) === '2026-07-01T08:30:00+00:00', 'the epoch as MySQL returns it (a string) is read too, and the instant stays the same in any zone');
check(strtotime((string) V::instant($summer, new \DateTimeZone('America/New_York'))) === $summer && strtotime((string) V::instant($summer, $rome)) === $summer, 'whatever zone it is shown in, the string denotes the same instant');
$libraryZone = new \DateTimeZone((string) \App\Support\ConfigStore::get('app.timezone', 'Europe/Rome'));
check(V::instant($summer) === (new \DateTimeImmutable('@' . $summer))->setTimezone($libraryZone)->format('Y-m-d\TH:i:sP'), 'without a zone the configured app.timezone is used, not PHP\'s');
check(V::instant(null) === null && V::instant('') === null && V::instant(0) === null && V::instant('0') === null && V::instant('not a date') === null && V::instant(-5) === null, 'empty, zero (a zero DATETIME) or broken instants are null');

echo $fail === 0 ? "SUCCESS {$pass} stocktake vocabulary checks\n" : "FAILED {$fail} of " . ($pass + $fail) . " checks\n";
exit($fail === 0 ? 0 : 1);
