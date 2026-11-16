<?php
/**
 * Security-audit hardening, bundled plugins + release pipeline (group D).
 *
 *   A. SRU scan: the LIKE prefix doubles the backslash FIRST (#51).
 *   B. Schema helpers refuse non-identifiers (#105): book-club, frbr-lrm,
 *      emeroteca, desiderata.
 *   C. Emeroteca: only a canonical '/uploads/emeroteca/<file>' image may be
 *      unlinked, and the reference probe also matches other spellings (#78).
 *   D. StaffCoverStore: the shared-reference check runs on the canonical
 *      path of the resolved file (#79).
 *   E. Every session key written by a core class the mobile API delegates to
 *      is isolated by Delegation::SESSION_KEYS, with the delegated classes
 *      DERIVED from the mobile-api sources, not hand-listed (#72).
 *   F. Release archive excludes sim-rating's nested vendor/ and PHPMailer's
 *      get_oauth_token.php in every builder + the archive audit (#62).
 *   G. Baseline attestation is pinned to release.yml and the tag (#102).
 *   H. Book club: invitation, affinity, sprint, stats, quotes, AI cap
 *      (#93, #94, #95, #96, #74, #68) — structural checks on the queries and
 *      guards, which need a full plugin DB to run end to end.
 *   I. RDA Work/Expression gated on frbr-lrm being active (#54); LIMIT/OFFSET
 *      bound in the listed plugin queries (#104); SRU selects an explicit
 *      public column list (#87); the contribution PDF is checked before the
 *      move (#83).
 *   J. (needs a DB, skipped otherwise) The SRU data query with the explicit
 *      column list runs and returns no private column.
 *
 * Run: php tests/security-hardening-plugins.unit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$src = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);
/** Call a (possibly private) static method; null when it does not exist. */
$callStatic = static function (string $class, string $method, array $args) {
    if (!method_exists($class, $method)) {
        return ['__missing__' => true];
    }
    $m = new ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
};
$throws = static function (callable $fn): bool {
    try { $fn(); } catch (\InvalidArgumentException $e) { return true; }
    return false;
};

// ── 0. Every touched plugin file parses ────────────────────────────────────
echo "0. Touched plugin files parse\n";
foreach ([
    'storage/plugins/book-club/src/AffinityRepo.php', 'storage/plugins/book-club/src/AiController.php',
    'storage/plugins/book-club/src/AiService.php', 'storage/plugins/book-club/src/ExtensionsRepo.php',
    'storage/plugins/book-club/src/PublicController.php', 'storage/plugins/book-club/src/QuoteRepo.php',
    'storage/plugins/book-club/src/SprintController.php', 'storage/plugins/book-club/src/StatsController.php',
    'storage/plugins/book-club/src/StatsRepo.php', 'storage/plugins/book-club/src/Modules/StatsModule.php',
    'storage/plugins/book-club/src/Modules/QuotesModule.php', 'storage/plugins/archives/ArchivesPlugin.php',
    'storage/plugins/emeroteca/src/Services/ContributionService.php', 'storage/plugins/emeroteca/src/Modules/MobileModule.php',
    'storage/plugins/emeroteca/src/Controllers/PublicController.php', 'storage/plugins/emeroteca/src/Controllers/ContributionController.php',
    'storage/plugins/bibframe-linked-data/RdaRegistryBuilder.php',
] as $rel) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $rc);
    $check($rc === 0, "php -l {$rel}");
}

// ── A. SRU scan LIKE escape ────────────────────────────────────────────────
echo "A. SRU scan LIKE prefix escape (#51)\n";
require_once $root . '/storage/plugins/z39-server/classes/SRUServer.php';
$esc = static fn (string $p) => $callStatic(\Z39Server\SRUServer::class, 'escapeLikePrefix', [$p]);
$check($esc('100%') === '100\\%', "'100%' -> '100\\%' (one escape, wildcard neutralised)");
$check($esc('a_b') === 'a\\_b', "'a_b' -> 'a\\_b'");
$check($esc('C:\\x') === 'C:\\\\x', 'a literal backslash is doubled');
$check($esc('\\%') === '\\\\\\%', "'\\%' -> '\\\\\\%' (backslash doubled, then % escaped)");

// ── B. Identifier assertions in schema helpers ─────────────────────────────
echo "\nB. Schema helpers refuse non-identifiers (#105)\n";
require_once $root . '/storage/plugins/book-club/src/Modules/ModuleInterface.php';
require_once $root . '/storage/plugins/book-club/src/Modules/AbstractModule.php';
require_once $root . '/storage/plugins/frbr-lrm/FrbrLrmPlugin.php';
require_once $root . '/storage/plugins/emeroteca/EmerotecaPlugin.php';
require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';
$classes = [
    'book-club' => \App\Plugins\BookClub\Modules\AbstractModule::class,
    'frbr-lrm' => \App\Plugins\FrbrLrm\FrbrLrmPlugin::class,
    'emeroteca' => \EmerotecaPlugin::class,
    'desiderata' => \DesiderataPlugin::class,
];
foreach ($classes as $label => $class) {
    $has = method_exists($class, 'assertIdentifier');
    $check($has, "{$label}: assertIdentifier() exists");
    if (!$has) {
        continue;
    }
    $assert = static fn (string $n) => $callStatic($class, 'assertIdentifier', [$n]);
    $check(!$throws(static fn () => $assert('bookclub_polls')), "{$label}: 'bookclub_polls' accepted");
    $check(!$throws(static fn () => $assert('_x1')), "{$label}: '_x1' accepted");
    foreach (['libri; DROP TABLE x', 'a b', '1col', 'Tab', 'x`', ''] as $bad) {
        $check($throws(static fn () => $assert($bad)), "{$label}: " . var_export($bad, true) . ' refused');
    }
}
$check(preg_match('/function addColumnIfMissing[^{]*\{\s*self::assertIdentifier\(\$table\);\s*self::assertIdentifier\(\$column\);/', $src('storage/plugins/book-club/src/Modules/AbstractModule.php')) === 1,
    'book-club addColumnIfMissing() asserts table + column before the ALTER');
$check(preg_match('/function addUniqueIndexIfMissing[^{]*\{\s*self::assertIdentifier\(\$table\);\s*self::assertIdentifier\(\$index\);/', $src('storage/plugins/book-club/src/Modules/AbstractModule.php')) === 1,
    'book-club addUniqueIndexIfMissing() asserts table + index (+ each column)');
$check(preg_match('/function addLibriColumnIfMissing[^{]*\{\s*self::assertIdentifier\(\$column\);/', $src('storage/plugins/frbr-lrm/FrbrLrmPlugin.php')) === 1,
    'frbr-lrm addLibriColumnIfMissing() asserts its identifiers');
$eme = $src('storage/plugins/emeroteca/EmerotecaPlugin.php');
$check(str_contains($eme, "self::assertIdentifier(\$table);\n        foreach (\$definitions as \$column => \$ddl) {\n            self::assertIdentifier((string) \$column);"),
    'emeroteca ensureAdditiveColumns() asserts table + every column');
$check(str_contains($eme, "self::assertIdentifier(\$idx['table']);") && str_contains($eme, "self::assertIdentifier((string) \$fk[\$fkPart]);"),
    'emeroteca index + FK loops assert their identifiers');
$check(str_contains($src('storage/plugins/desiderata/DesiderataPlugin.php'), "self::assertIdentifier(\$column);\n            \$check = \$this->db->query(\"SHOW COLUMNS FROM desiderata_offers LIKE '\$column'\");"),
    'desiderata column loop asserts the column name');

// ── C. Emeroteca managed image canonical form ──────────────────────────────
echo "\nC. Emeroteca image deletion only for canonical managed URLs (#78)\n";
require_once $root . '/storage/plugins/emeroteca/src/Controllers/AbstractAdminController.php';
$name = static fn (string $u) => $callStatic(\App\Plugins\Emeroteca\Controllers\AbstractAdminController::class, 'managedImageFilename', [$u]);
$check($name('/uploads/emeroteca/testata_20260101_120000_0123456789abcdef.jpg') === 'testata_20260101_120000_0123456789abcdef.jpg', 'a stored upload is recognised');
$check($name('/uploads/emeroteca/x/testata_1.jpg') === null, 'a nested path naming another record\'s file is refused');
$check($name('/uploads/emeroteca/./testata_1.jpg') === null, "a './' spelling is refused");
$check($name('/uploads/emeroteca/.htaccess') === null, '.htaccess is never a managed image');
$check($name('/uploads/emeroteca/index.html') === null, 'index.html is never a managed image');
$check($name('https://example.org/uploads/emeroteca/a.jpg') === null, 'an external URL is refused');
$aac = $src('storage/plugins/emeroteca/src/Controllers/AbstractAdminController.php');
$check(str_contains($aac, 'WHERE $column = ? OR $column LIKE ?') && str_contains($aac, "realpath(\$baseDir . DIRECTORY_SEPARATOR . \$name)"),
    'reference probe also matches other spellings; unlink uses the same validated name');

// ── D. StaffCoverStore canonical reference check ───────────────────────────
echo "\nD. StaffCoverStore checks references on the canonical path (#79)\n";
require_once $root . '/storage/plugins/mobile-api/src/Support/StaffCoverStore.php';
$canon = static fn (string $f, string $b) => $callStatic(\App\Plugins\MobileApi\Support\StaffCoverStore::class, 'canonicalCoverPath', [$f, $b]);
$base = DIRECTORY_SEPARATOR . 'srv' . DIRECTORY_SEPARATOR . 'copertine';
$check($canon($base . DIRECTORY_SEPARATOR . 'libro_5_x.jpg', $base) === '/uploads/copertine/libro_5_x.jpg', "a file under the base maps to '/uploads/copertine/<file>'");
$check($canon('/etc/passwd', $base) === null, 'a file outside the base has no canonical path');
$check($canon($base . 'evil' . DIRECTORY_SEPARATOR . 'a.jpg', $base) === null, 'a sibling directory sharing the prefix is outside the base');
$scs = $src('storage/plugins/mobile-api/src/Support/StaffCoverStore.php');
$check(str_contains($scs, "(copertina_url = ? OR copertina_url LIKE ?) AND id <> ?") && str_contains($scs, "bind_param('ssi', \$canonical, \$suffix, \$bookId)"),
    'retire() binds the canonical path, not the stored spelling');

// ── E. Delegated session keys (derived) ────────────────────────────────────
echo "\nE. Session keys written by every class the mobile API delegates to (#72)\n";
require_once $root . '/storage/plugins/mobile-api/src/Support/Delegation.php';
$apiFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/storage/plugins/mobile-api/src', FilesystemIterator::SKIP_DOTS));
$delegatedClasses = [];
foreach ($apiFiles as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $code = (string) file_get_contents($f->getPathname());
    if (!str_contains($code, 'Delegation::run(')) {
        continue;
    }
    $uses = [];
    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?;/m', $code, $um, PREG_SET_ORDER);
    foreach ($um as $u) {
        $parts = explode('\\', $u[1]);
        $uses[($u[2] ?? '') !== '' ? $u[2] : end($parts)] = $u[1];
    }
    // Superset on purpose: every class the delegating file instantiates.
    preg_match_all('/new\s+(\\\\?[A-Z][A-Za-z0-9_\\\\]*)\s*\(/', $code, $nm);
    foreach ($nm[1] as $c) {
        $c = ltrim($c, '\\');
        $delegatedClasses[str_contains($c, '\\') ? $c : ($uses[$c] ?? $c)] = true;
    }
}
$sources = [];
foreach (array_keys($delegatedClasses) as $class) {
    if (str_starts_with($class, 'App\\') && !str_starts_with($class, 'App\\Plugins\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) { $sources[$class] = $path; }
        continue;
    }
    $short = basename(str_replace('\\', '/', $class));
    foreach (glob($root . '/storage/plugins/*/{,src/,src/*/,classes/}' . $short . '.php', GLOB_BRACE) ?: [] as $path) {
        $sources[$class] = $path;
    }
}
$check(count($sources) >= 20, 'delegated classes resolved from the mobile-api sources (' . count($sources) . ')');
$sources[] = $root . '/app/Support/OneTimeFormToken.php';
$written = [];
foreach ($sources as $path) {
    $code = (string) file_get_contents($path);
    preg_match_all("/\\\$_SESSION\\[['\"]([A-Za-z0-9_]+)['\"]\\]\\s*(?:\\[[^\\]]*\\]\\s*)*=(?!=)/", $code, $m);
    foreach ($m[1] as $key) {
        $written[$key][basename($path)] = true;
    }
    if (str_contains($code, "SESSION_KEY = '_one_time_form_tokens'")) {
        $written['_one_time_form_tokens'][basename($path)] = true;
    }
}
// Keys that are session STATE owned elsewhere, not flash left for a page:
$exempt = [
    'user' => 'AppAuthMiddleware per-request mirror (UsersController self-edit)',
    'locale' => "the signed-in account's own language, set by UsersController::update self-edit (never delegated)",
    'last_regeneration' => 'session keep-alive timestamp owned by the session layer',
    'new_api_key' => 'SettingsController API-key creation, never delegated',
    'desiderata_last_offer' => 'public donation form throttle (DesiderataPlugin::offer), never delegated',
    'desiderata_success' => 'public donation form thank-you flag (DesiderataPlugin::offer), never delegated',
];
$missing = [];
foreach ($written as $key => $files) {
    if (!in_array($key, \App\Plugins\MobileApi\Support\Delegation::SESSION_KEYS, true) && !isset($exempt[$key])) {
        $missing[] = $key . ' (' . implode(', ', array_keys($files)) . ')';
    }
}
$check($missing === [], 'written keys ⊆ SESSION_KEYS ∪ documented exemptions' . ($missing !== [] ? ' — missing: ' . implode('; ', $missing) : ''));
// The derivation must actually see the 0.9.0 staff areas, or it proves nothing.
foreach (['custom_holiday_draft', 'attendance_sede_id', 'error_message'] as $key) {
    $check(isset($written[$key]), "derived scan sees '{$key}'");
}

// ── F. Release archive exclusions ──────────────────────────────────────────
echo "\nF. Release archive excludes non-runtime vendor material (#62)\n";
$check(str_contains($src('.rsync-filter'), "- /vendor/**/vendor/\n"), 'rsync filter drops nested vendor/ trees (sim-rating)');
$check(str_contains($src('.rsync-filter'), "- /vendor/phpmailer/phpmailer/get_oauth_token.php\n"), 'rsync filter drops get_oauth_token.php');
$ga = $src('.gitattributes');
$check(str_contains($ga, "vendor/emleons/sim-rating/vendor/ export-ignore") && str_contains($ga, "vendor/phpmailer/phpmailer/get_oauth_token.php export-ignore"),
    '.gitattributes mirrors both exclusions for git archive');
$verify = $src('scripts/ci-verify-release.sh');
$check(str_contains($verify, 'vendor/emleons/sim-rating/vendor vendor/phpmailer/phpmailer/get_oauth_token.php'), 'the archive audit fails a ZIP that ships either');
$simAutoload = json_decode($src('vendor/emleons/sim-rating/composer.json'), true);
$check(($simAutoload['autoload']['psr-4'] ?? null) === ['Emleons\\SimRating\\' => 'src/'] && !str_contains($src('vendor/composer/autoload_static.php'), 'sim-rating/vendor'),
    'sim-rating autoloads only its src/ (nested vendor is not runtime)');
if (trim((string) shell_exec('command -v rsync')) !== '') {
    $listing = (string) shell_exec('cd ' . escapeshellarg($root) . ' && rsync -an --filter="merge .rsync-filter" --out-format="%n" . ' . escapeshellarg(sys_get_temp_dir() . '/pinakes-rsync-dry-' . getmypid()) . '/ 2>/dev/null | grep -E "^vendor/(emleons|phpmailer)/"');
    $check($listing !== '' && !str_contains($listing, 'get_oauth_token.php') && !str_contains($listing, 'sim-rating/vendor/') && str_contains($listing, 'sim-rating/src/'),
        'rsync dry-run with the real filter keeps sim-rating/src and drops both');
}

// ── G. Attestation pinned to release.yml ───────────────────────────────────
echo "\nG. Baseline provenance must come from release.yml for the tag (#102)\n";
$cru = $src('.github/workflows/ci-real-upgrade.yml');
$check(str_contains($cru, '--signer-workflow "${GH_REPO}/.github/workflows/release.yml"') && str_contains($cru, '--source-ref "refs/tags/${tag}"'),
    'gh attestation verify uses --signer-workflow and --source-ref');

// ── H. Book club ───────────────────────────────────────────────────────────
echo "\nH. Book club membership/visibility guards (#93-#96, #74, #68)\n";
$pc = $src('storage/plugins/book-club/src/PublicController.php');
$accept = substr($pc, (int) strpos($pc, 'function acceptInvite'), 4000);
$check(str_contains($accept, "\$existing['status'] === 'active'") && strpos($accept, "\$existing['status'] === 'active'") < strpos($accept, 'upsertMember('),
    'acceptInvite(): an active member is sent to the club before any upsert (role kept, invitation unused)');
$check(str_contains($accept, "in_array(\$existing['status'], ['banned', 'suspended'], true)"), 'acceptInvite(): suspended/banned still refused (#75)');
$mob = $src('storage/plugins/book-club/src/MobileApiController.php');
$check(str_contains(substr($mob, (int) strpos($mob, 'function join'), 2000), "['banned', 'suspended']"), 'mobile join(): suspended refused (#92)');
$aff = $src('storage/plugins/book-club/src/AffinityRepo.php');
$affFor = substr($aff, (int) strpos($aff, 'function affinitiesFor'), 1200);
$check(str_contains($affFor, "m.user_id = u.id AND m.status = 'active'") && str_contains($affFor, 'JOIN bookclub_affinity_optin o'),
    'affinitiesFor(): counterpart re-checked as active + opted in at read time');
$sp = $src('storage/plugins/book-club/src/SprintController.php');
$cancel = substr($sp, (int) strpos($sp, 'function cancel'), 900);
$check(str_contains($cancel, '=== $userId') && str_contains($cancel, '&& $this->isActiveMember($club)'), 'sprint cancel(): creator right requires active membership');
$sm = $src('storage/plugins/book-club/src/Modules/StatsModule.php');
$check(str_contains($sm, "Permissions::can(\$this->db, \$club, \$userId, 'stats.view')"), 'stats panel requires stats.view like the stats page');
$sc = $src('storage/plugins/book-club/src/StatsController.php');
$check(str_contains($sc, "!\$canManage && \$book['state'] === BookClubPlugin::STATE_PENDING")
    && str_contains($sc, "!\$canManage && (\$entry['current_state'] ?? null) === BookClubPlugin::STATE_PENDING")
    && str_contains($sc, "!\$canManage && \$member['status'] !== 'active'"),
    'export: pending proposals + non-active members only for managers');
$qr = $src('storage/plugins/book-club/src/QuoteRepo.php');
$check(str_contains($qr, "AND c.is_active = 1 AND c.privacy <> 'hidden'") && str_contains($qr, 'Modules\\Registry::clubEnabled('),
    'public quotes: active, non-hidden clubs with the quotes module enabled');
$check(str_contains($src('storage/plugins/book-club/src/Modules/QuotesModule.php'), 'publicQuotesForBook($libroId, 5, $this)'), 'book page passes the quotes module for the enablement check');
$ai = $src('storage/plugins/book-club/src/AiService.php');
$aic = $src('storage/plugins/book-club/src/AiController.php');
$check(str_contains($ai, "SELECT GET_LOCK(?, 5)") && str_contains($ai, "saveOutput(\$clubId, \$kind, \$entityId, '', "), 'AI cap: attempt booked under a per-club lock before the call');
$check(substr_count($aic, 'reserveAttempt(') === 2 && strpos($aic, 'reserveAttempt(') < strpos($aic, '$this->ai->generate('), 'both generators reserve before generate()');
$check(str_contains($ai, "'max_bytes'       => 1048576"), 'AI response body capped (max_bytes)');
$check(str_contains($ai, "WHERE o.club_id = ? AND o.content <> ''"), 'history hides reserved-but-failed attempts (they still count)');

// ── I. RDA gate, bound LIMIT/OFFSET, SRU columns, PDF check ────────────────
echo "\nI. RDA gate (#54), bound LIMIT/OFFSET (#104), SRU columns (#87), PDF (#83)\n";
$rda = $src('storage/plugins/bibframe-linked-data/RdaRegistryBuilder.php');
$check(str_contains($rda, "name = 'frbr-lrm'") && substr_count($rda, "frbrTableExists('") === 4 && !str_contains($rda, "->tableExists('opere')") && !str_contains($rda, "->tableExists('espressioni')"),
    'every Work/Expression lookup is gated on frbr-lrm active');
$arch = $src('storage/plugins/archives/ArchivesPlugin.php');
$check(!str_contains($arch, "' LIMIT ' . \$maximumRecords") && !str_contains($arch, "'LIMIT ' . \$pageSize"), 'archives SRU/OAI: LIMIT/OFFSET bound');
$cs = $src('storage/plugins/emeroteca/src/Services/ContributionService.php');
$check(!preg_match('/LIMIT \$\w+|OFFSET \$\w+/', $cs), 'emeroteca ContributionService: no interpolated LIMIT/OFFSET');
$check(!str_contains($src('storage/plugins/emeroteca/src/Modules/MobileModule.php'), "LIMIT '.(\$limit+1)"), 'emeroteca MobileModule: LIMIT bound');
$check(!str_contains($src('storage/plugins/emeroteca/src/Controllers/PublicController.php'), 'OFFSET {$offset}'), 'emeroteca public listing: OFFSET bound');
$check(!str_contains($src('storage/plugins/desiderata/DesiderataPlugin.php'), "OFFSET ' . \$offersOffset"), 'desiderata admin: OFFSET bound');
$check(!str_contains($src('storage/plugins/book-club/src/ExtensionsRepo.php'), "LIMIT ' . \$limit"), 'book-club sprints: LIMIT bound');
$sru = $src('storage/plugins/z39-server/classes/SRUServer.php');
$check(!preg_match('/SELECT\s+l\.\*,/', $sru) && str_contains($sru, '$this->publicBookColumns()'), 'SRU data query no longer selects l.*');
$ref = new ReflectionClassConstant(\Z39Server\SRUServer::class, 'PUBLIC_BOOK_COLUMNS');
$cols = (array) $ref->getValue();
foreach (['private_comment', 'note_varie', 'comment', 'lending_patron', 'prezzo', 'value', 'review', 'fornitore_id'] as $private) {
    $check(!in_array($private, $cols, true), "SRU column list excludes {$private}");
}
// Every libri key the formatters read must be in the list.
$formatterKeys = [];
foreach (['RecordFormatter', 'DublinCoreFormatter', 'MARCXMLFormatter', 'MODSFormatter', 'UNIMARCXMLFormatter'] as $fmt) {
    preg_match_all("/\\\$record\\[['\"]([a-z_0-9]+)['\"]\\]/", $src('storage/plugins/z39-server/classes/' . $fmt . '.php'), $m);
    foreach ($m[1] as $k) { $formatterKeys[$k] = true; }
}
$computed = ['autori', 'contributors', 'publishers', 'copies', 'editore', 'genere', 'scaffale', 'mensola',
    // serial (Emeroteca masthead) records are built separately:
    '_record_type', 'public_url', 'e_issn', 'issn_l', 'periodicita', 'tipo_periodico', 'anno_fine', 'numerazione'];
$notSelected = array_values(array_diff(array_keys($formatterKeys), $cols, $computed));
$check($notSelected === [], 'every formatter field is selected' . ($notSelected !== [] ? ' — missing: ' . implode(', ', $notSelected) : ''));
foreach (['traduttore', 'illustratore', 'curatore', 'colorista', 'isbn10', 'isbn13'] as $dyn) {
    $check(in_array($dyn, $cols, true), "dynamically-read field {$dyn} selected");
}
$cc = $src('storage/plugins/emeroteca/src/Controllers/ContributionController.php');
$check(strpos($cc, "->buffer(\$head) !== 'application/pdf'") !== false && strpos($cc, "->buffer(\$head) !== 'application/pdf'") < strpos($cc, '$file->moveTo($newPath);'),
    'contribution PDF content checked before moveTo');

// ── J. Real DB: SRU data query ─────────────────────────────────────────────
echo "\nJ. SRU data query on a real database (#87)\n";
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    $env[$k] = trim($v, "\"'");
}
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
if ($dbName === '' || $dbUser === '') {
    echo "  SKIP no database configured (E2E_DB_NAME/E2E_DB_USER or .env)\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $dbUser, $dbPass, $dbName, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    foreach (['CQLException', 'InvalidCQLSyntaxException', 'UnsupportedRelationException', 'UnsupportedIndexException', 'DatabaseException', 'SRUQueryException'] as $ex) {
        require_once $root . '/storage/plugins/z39-server/classes/Exceptions/' . $ex . '.php';
    }
    require_once $root . '/storage/plugins/z39-server/classes/CQLParser.php';
    $server = new \Z39Server\SRUServer($db, []);
    $build = new ReflectionMethod($server, 'buildSearchQuery');
    $build->setAccessible(true);
    $q = $build->invoke($server, (new \Z39Server\CQLParser())->parse('dc.title = "a"'), 1, 5, '');
    $ok = true;
    $keys = [];
    try {
        $res = $db->query($q['data']);
        $fields = $res instanceof mysqli_result ? $res->fetch_fields() : [];
        foreach ($fields as $fd) { $keys[] = $fd->name; }
        if ($res instanceof mysqli_result) { $res->free(); }
    } catch (\Throwable $e) {
        $ok = false;
        echo '    ' . $e->getMessage() . "\n";
    }
    $check($ok, 'data query runs');
    $check(in_array('titolo', $keys, true) && in_array('contributors_encoded', $keys, true), 'public fields present');
    $check(!in_array('private_comment', $keys, true) && !in_array('note_varie', $keys, true), 'private columns absent from the result');
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
