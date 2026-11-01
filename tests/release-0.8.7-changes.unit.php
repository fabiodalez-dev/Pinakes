<?php
declare(strict_types=1);
/**
 * Pure checks (no database, no web server) on what 0.8.7 changes, covering the
 * edges the database-backed suites do not reach:
 *
 * - LoanWindow (#490, #493): unsorted and past conflict days, a month and leap-day
 *   boundary, a blocked start or next day, overdue renewals counted from today,
 *   redirect parameters that a reader can edit in the address bar.
 * - CopyRepository::inventoryBase() (#489, #494): the book's prefix or LIB-<id>.
 * - PhpSecurityAdvisory (#491): the table of fixed releases is consistent.
 * - The release itself: locales, plugin manifests, changelog, README and the two
 *   pages that show the PHP notice.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Models\CopyRepository;
use App\Support\I18n;
use App\Support\LoanWindow;
use App\Support\PhpSecurityAdvisory;

$n = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$n): void {
    if (!$ok) {
        throw new RuntimeException("FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : ''));
    }
    echo 'PASS ' . (++$n) . ' ' . $label . "\n";
};

$previousLocale = I18n::getLocale();
I18n::setLocale('it_IT');

// ── LoanWindow: loan requests (#490) ────────────────────────────────────────
$cap = LoanWindow::cap('2030-03-10', ['2030-03-01', '2030-03-09']);
$check($cap === null, '01 conflict days before the start never bound the window', json_encode($cap));

$cap = LoanWindow::cap('2030-03-10', ['2030-03-25', '2030-03-15', '2030-03-20']);
$check(($cap['first_conflict_date'] ?? '') === '2030-03-15' && ($cap['max_end_date'] ?? '') === '2030-03-14'
    && ($cap['max_duration_days'] ?? 0) === 4, '02 unsorted conflicts: the earliest future day bounds the window', json_encode($cap));

$cap = LoanWindow::cap('2028-02-27', ['2028-03-02']);
$check(($cap['max_end_date'] ?? '') === '2028-03-01' && ($cap['max_duration_days'] ?? 0) === 3,
    '03 a leap-year February is counted day by day (27 Feb to 1 Mar is 3 days)', json_encode($cap));

$check(LoanWindow::cap('2030-03-10', ['2030-03-10', '2030-03-20']) === null,
    '04 a loan that would start on a blocked day is not shortened');

$check(LoanWindow::cap('2030-03-10', ['2030-03-11']) === null,
    '05 a block on the very next day leaves no loan of one day to propose');

$params = LoanWindow::redirectParams('2030-03-10', ['2030-03-17']);
$check($params === ['max_loan_days' => 6, 'max_end_date' => '2030-03-16', 'blocked_from' => '2030-03-17']
    && LoanWindow::redirectParams('2030-03-10', []) === [],
    '06 redirect parameters carry exactly the three figures, and nothing when unbounded', json_encode($params));

$check(LoanWindow::explainFromParams($params) === LoanWindow::explain(6, '2030-03-16', '2030-03-17'),
    '07 the explanation rebuilt after the redirect is the one the refusal would give');

$tampered = [
    ['max_loan_days' => '12345', 'max_end_date' => '2030-03-16', 'blocked_from' => '2030-03-17'],
    ['max_loan_days' => '6', 'max_end_date' => '<script>alert(1)</script>', 'blocked_from' => '2030-03-17'],
    ['max_loan_days' => ['6'], 'max_end_date' => '2030-03-16', 'blocked_from' => '2030-03-17'],
    ['max_loan_days' => '-6', 'max_end_date' => '2030-03-16', 'blocked_from' => '2030-03-17'],
    ['max_loan_days' => '6', 'max_end_date' => '2030-3-16', 'blocked_from' => '2030-03-17'],
];
$rejected = array_map(static fn (array $p): ?string => LoanWindow::explainFromParams($p), $tampered);
$check($rejected === [null, null, null, null, null], '08 edited query parameters are ignored, never echoed', json_encode($rejected));

$one = LoanWindow::explain(1, '2030-03-11', '2030-03-12');
$check(str_contains($one, '1 giorno') && !str_contains($one, '1 giorni') && str_contains($one, '11-03-2030'),
    '09 one day uses the singular sentence', $one);

// ── LoanWindow: renewals (#493) ─────────────────────────────────────────────
$renew = LoanWindow::renewalCap('2030-04-01', ['2030-04-02']);
$check(($renew['max_extension_days'] ?? -1) === 0 && ($renew['max_due_date'] ?? '') === '2030-04-01',
    '10 a block on the day after the due date leaves 0 days and keeps the due date', json_encode($renew));

$renew = LoanWindow::renewalCap('2030-04-01', ['2030-04-05'], '2030-04-10');
$check(($renew['max_due_date'] ?? '') === '2030-04-10' && ($renew['max_extension_days'] ?? -1) === 0,
    '11 an overdue bulk extension counted from today never proposes a past due date', json_encode($renew));

$check(LoanWindow::renewalCap('2030-04-01', ['2030-03-20', '2030-04-01']) === null,
    '12 conflicts on or before the current due date do not bound the renewal');

$renew = LoanWindow::renewalCap('2030-04-01', ['2030-04-09', '2030-04-06']);
$check(($renew['max_due_date'] ?? '') === '2030-04-05' && ($renew['max_extension_days'] ?? 0) === 4
    && ($renew['first_conflict_date'] ?? '') === '2030-04-06', '13 the earliest later conflict bounds the renewal', json_encode($renew));

$none = LoanWindow::explainRenewal(0, '2030-04-01', '2030-04-02');
$check(str_contains($none, 'Rinnovo non possibile') && str_contains($none, '02-04-2030'),
    '14 zero days says that no renewal is possible and from when', $none);

$back = LoanWindow::explainRenewalFromParams(LoanWindow::renewalRedirectParams('2030-04-01', ['2030-04-06']));
$check($back === LoanWindow::explainRenewal(4, '2030-04-05', '2030-04-06')
    && LoanWindow::explainRenewalFromParams(['max_extension_days' => 'four', 'max_due_date' => '2030-04-05', 'blocked_from' => '2030-04-06']) === null,
    '15 renewal parameters survive the redirect and reject non-numeric days', (string) $back);

// ── CopyRepository::inventoryBase() (#489) ──────────────────────────────────
$check(CopyRepository::inventoryBase(42, 'BIB') === 'BIB', '16 the book prefix is the base when it is set');

$blank = [CopyRepository::inventoryBase(42, ''), CopyRepository::inventoryBase(42, '   '), CopyRepository::inventoryBase(42, null)];
$check($blank === ['LIB-42', 'LIB-42', 'LIB-42'], '17 an empty, blank or missing prefix falls back to LIB-<id>', json_encode($blank));

$check(CopyRepository::inventoryBase(7, ['BIB']) === 'LIB-7' && CopyRepository::inventoryBase(7, new stdClass()) === 'LIB-7',
    '18 a non-scalar value is not a prefix');

$check(CopyRepository::inventoryBase(7, '0') === '0' && CopyRepository::inventoryBase(7, 2026) === '2026',
    '19 a prefix of 0 and a numeric prefix are honoured');

$check(CopyRepository::inventoryBase(7, "  FONDO-A \t") === 'FONDO-A', '20 surrounding whitespace is never part of a code');

// ── PhpSecurityAdvisory table (#491) ───────────────────────────────────────
$branches = array_keys(PhpSecurityAdvisory::FIXED);
$sorted = $branches;
usort($sorted, 'version_compare');
$consistent = $branches === $sorted;
foreach (PhpSecurityAdvisory::FIXED as $branch => $fixed) {
    $consistent = $consistent && str_starts_with($fixed, $branch . '.') && preg_match('/^\d+\.\d+\.\d+$/', $fixed) === 1;
}
$check($consistent, '21 every fixed release belongs to its branch, branches in ascending order');

// ── The release itself ─────────────────────────────────────────────────────
$keys = [
    'PHP %s ha vulnerabilità critiche: aggiornalo a %s o successivo',
    'Avviso di sicurezza di PHP',
    'Amministratori, staff, utenti autorizzati e chi ha in corso il prestito di quel libro.',
];
$missing = [];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $table = json_decode((string) file_get_contents("{$root}/locale/{$locale}.json"), true);
    foreach ($keys as $key) {
        $value = $table[$key] ?? null;
        if (!is_string($value) || substr_count($value, '%s') !== substr_count($key, '%s')) {
            $missing[] = "{$locale}: {$key}";
        }
    }
}
$check($missing === [], '22 the new notice and description strings exist in all five locales with their placeholders', implode('; ', $missing));

$version = (string) json_decode((string) file_get_contents("{$root}/version.json"), true)['version'];
$manifests = [];
foreach (['desiderata', 'mobile-api'] as $plugin) {
    $manifest = json_decode((string) file_get_contents("{$root}/storage/plugins/{$plugin}/plugin.json"), true);
    $required = (string) ($manifest['requires_app'] ?? '');
    $manifests[$plugin] = $required;
}
$usesHelper = str_contains((string) file_get_contents("{$root}/storage/plugins/desiderata/DesiderataPlugin.php"), 'inventoryBase(')
    && str_contains((string) file_get_contents("{$root}/storage/plugins/mobile-api/src/Controllers/StaffBooksController.php"), 'inventoryBase(');
// mobile-api 1.9.0 moved to 0.9.0 (patron ledger): still at least 0.8.7, never above the app.
$check($usesHelper && $manifests['desiderata'] === '0.8.7'
    && version_compare($manifests['mobile-api'], '0.8.7', '>=') && version_compare($manifests['mobile-api'], $version, '<=')
    && version_compare('0.8.7', $version, '<='),
    '23 the two plugins that call inventoryBase() require the core that has it, and no later one', json_encode($manifests) . " app={$version}");

$changelog = (string) file_get_contents("{$root}/CHANGELOG.md");
$readme = (string) file_get_contents("{$root}/README.md");
$check(str_contains($changelog, "## [{$version}]") && str_contains($readme, "## What's New in v{$version}")
    && preg_match('/badge\/dynamic\/json[^)]*version\.json/', $readme) === 1
    && preg_match('/img\.shields\.io\/badge\/version-\d/', $readme) === 0,
    '24 changelog and README describe this version, and the version badge stays dynamic');

$pages = [];
foreach (['app/Views/dashboard/index.php', 'app/Views/admin/updates.php'] as $view) {
    $pages[$view] = substr_count((string) file_get_contents("{$root}/{$view}"), "partials/php-security-advisory.php");
}
$check($pages === ['app/Views/dashboard/index.php' => 1, 'app/Views/admin/updates.php' => 1],
    '25 the dashboard and the updates page each show the PHP notice once', json_encode($pages));

I18n::setLocale($previousLocale);
echo "\nALL {$n} PASS\n";
