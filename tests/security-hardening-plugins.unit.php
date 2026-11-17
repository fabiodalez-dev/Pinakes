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
 *      (#93, #94, #95, #96, #74, #68) — the real controllers, repos and
 *      modules against the plugin's own schema in a disposable database
 *      (tests/helpers/hardening-sandbox-db.php; SKIP without E2E_DB_* or .env).
 *   I. RDA Work/Expression gated on frbr-lrm being active (#54); LIMIT/OFFSET
 *      bound in the listed plugin queries (#104, token-stream scan: see the
 *      comment there for why it cannot be behavioural); SRU selects an explicit
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
// The guards run for real: the controllers / repos / modules of the plugin
// against the plugin's own schema (BookClubPlugin::ensureSchema(), modules
// included) in a disposable database rebuilt from schema.sql. Only the
// invitation flow uses the in-memory Repo of the existing book-club audit
// suites (tests/helpers/bookclub-controller-stubs.php): it needs no query.
echo "\nH. Book club membership/visibility guards (#93-#96, #74, #68)\n";
require_once $root . '/tests/helpers/bookclub-controller-stubs.php';
require_once $root . '/tests/helpers/hardening-sandbox-db.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\Plugins\\BookClub\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/storage/plugins/book-club/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// acceptInvite(): an ACTIVE member (here the owner) accepting a stray
// invitation is sent to the club; nothing is written, so the owner role is
// kept and the invitation stays unused.
$invRepo = bcFakeRepo();
$invRepo->clubs['hb-inv'] = bcClub(410, 'hb-inv', 'invite');
$invRepo->members['410:21'] = ['id' => 9, 'club_id' => 410, 'user_id' => 21, 'status' => 'active', 'role_slug' => 'owner'];
$invRepo->invitation = ['id' => 88, 'club_id' => 410, 'email' => 'reader@audit.invalid', 'role_id' => null, 'invited_by' => 1];
bcLogin(21);
$res = bcController(BcTestPublicController::class, $invRepo)->acceptInvite(bcRequest('POST'), bcResponse(), str_repeat('c', 64));
$check($invRepo->writes === [] && $invRepo->members['410:21']['role_slug'] === 'owner' && $invRepo->members['410:21']['status'] === 'active',
    'acceptInvite(): an active owner is not re-upserted (role kept) and the invitation is not consumed');
$check($res->getStatusCode() === 302 && str_ends_with($res->getHeaderLine('Location'), '/hb-inv'), 'acceptInvite(): the active member is sent to the club page');
$invRepo->members['410:21']['status'] = 'left';
bcController(BcTestPublicController::class, $invRepo)->acceptInvite(bcRequest('POST'), bcResponse(), str_repeat('c', 64));
$check(in_array(['markInvitationAccepted', 88], $invRepo->writes, true) && $invRepo->members['410:21']['status'] === 'active',
    'control: a former member accepting the invitation is (re)admitted and the invitation consumed');
$_SESSION = [];

try {
    $sandbox = hardeningSandboxDb('plug');
    $sandboxError = null;
} catch (\Throwable $e) {
    $sandbox = null;
    $sandboxError = $e->getMessage();
}
if ($sandboxError !== null) {
    $check(false, 'the disposable database could be built: ' . $sandboxError);
} elseif ($sandbox === null) {
    echo "SKIP: no database configured (E2E_DB_* or .env)\n";
} else {
    $db = $sandbox['db'];
    try {
        $schema = (new \App\Plugins\BookClub\BookClubPlugin($db, new \App\Support\HookManager($db)))->ensureSchema();
        if ($schema['failed'] !== []) {
            throw new RuntimeException('book-club ensureSchema failed for ' . implode(', ', $schema['failed']));
        }
        $modules = [];
        foreach (\App\Plugins\BookClub\Modules\Registry::all($db) as $m) {
            $modules[$m->slug()] = $m;
        }
        $repo = new \App\Plugins\BookClub\Repo($db);
        $q = static function (string $sql, string $types = '', array $params = []) use ($db): int {
            $stmt = $db->prepare($sql);
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        $scalar = static function (string $sql) use ($db): int {
            $row = $db->query($sql)->fetch_row();
            return (int) ($row[0] ?? 0);
        };
        $role = static fn (string $slug): int => $scalar("SELECT id FROM bookclub_roles WHERE club_id IS NULL AND slug = '{$slug}'");
        $users = [];
        foreach (['owner', 'member', 'guest', 'custom', 'pending', 'left', 'creator', 'admin'] as $who) {
            $users[$who] = hardeningSeedUser($db, "hb-bc-{$who}@example.org", 'x', $who === 'admin' ? 'admin' : 'standard');
        }
        $settings = (string) json_encode(['modules' => ['stats', 'quotes', 'ai', 'sprints', 'affinity']]);
        $clubId = $q("INSERT INTO bookclub_clubs (slug, name, privacy, settings, ics_token, is_active) VALUES ('hb-club', 'HB club', 'public', ?, REPEAT('a', 32), 1)", 's', [$settings]);
        $customRole = $q("INSERT INTO bookclub_roles (club_id, slug, name, permissions, is_system) VALUES (?, 'exporter', 'Exporter', '{\"exports.run\":true}', 0)", 'i', [$clubId]);
        $memberIds = [];
        foreach ([
            'owner' => [$role('owner'), 'active'], 'member' => [$role('member'), 'active'], 'guest' => [$role('guest'), 'active'],
            'custom' => [$customRole, 'active'], 'pending' => [$role('member'), 'pending'], 'left' => [$role('member'), 'left'],
            'creator' => [$role('member'), 'active'],
        ] as $who => [$roleId, $status]) {
            $memberIds[$who] = $q('INSERT INTO bookclub_members (club_id, user_id, role_id, status) VALUES (?, ?, ?, ?)', 'iiis', [$clubId, $users[$who], $roleId, $status]);
        }
        $club = $repo->clubBySlug('hb-club');
        $session = static function (string $who) use ($users): void {
            $_SESSION = ['user' => ['id' => $users[$who], 'tipo_utente' => $who === 'admin' ? 'admin' : 'standard', 'email' => "hb-bc-{$who}@example.org", 'nome' => $who]];
            \App\Plugins\BookClub\Permissions::flushCache();
        };

        // ── #94 affinity: leave / suspend / ban purge at once ────────────
        $pair = static fn (string $a, string $b) => $q('INSERT INTO bookclub_affinity (club_id, user_a, user_b, score) VALUES (?, ?, ?, 50)', 'iii', [$clubId, min($users[$a], $users[$b]), max($users[$a], $users[$b])]);
        $optIn = static fn (string $who) => $q('INSERT IGNORE INTO bookclub_affinity_optin (club_id, user_id) VALUES (?, ?)', 'ii', [$clubId, $users[$who]]);
        foreach (['owner', 'member', 'guest', 'custom'] as $who) {
            $optIn($who);
        }
        $pair('owner', 'member');
        $pair('owner', 'guest');
        $pair('owner', 'custom');
        $pair('member', 'custom');
        $affinityOf = static fn (string $who): int => $scalar("SELECT COUNT(*) FROM bookclub_affinity WHERE club_id = {$clubId} AND {$users[$who]} IN (user_a, user_b)")
            + $scalar("SELECT COUNT(*) FROM bookclub_affinity_optin WHERE club_id = {$clubId} AND user_id = {$users[$who]}");
        $check($affinityOf('member') === 3, 'setup: the member has two pairs and an opt-in');
        foreach (['member' => 'left', 'guest' => 'suspended', 'custom' => 'banned'] as $who => $status) {
            $check($repo->setMemberStatus($memberIds[$who], $status) === true, "setMemberStatus('{$status}') succeeds");
            $check($affinityOf($who) === 0, "{$status}: every affinity pair and the opt-in of the member are gone at once");
        }
        $check($affinityOf('owner') === 1, "the owner keeps only the opt-in (no pair involves a remaining member)");
        // Read time: a stored pair whose counterpart is not an active opted-in
        // member is never listed (#94, between status change and recompute).
        $db->query("DELETE FROM bookclub_affinity WHERE club_id = {$clubId}");
        $db->query("UPDATE bookclub_members SET status = 'active' WHERE id IN ({$memberIds['member']}, {$memberIds['guest']})");
        $optIn('member');
        $pair('owner', 'member');     // active + opted in: listed
        $pair('owner', 'guest');      // active, NOT opted in: hidden
        $pair('owner', 'pending');    // opted in but pending: hidden
        $optIn('pending');
        $aff = new \App\Plugins\BookClub\AffinityRepo($db);
        $names = array_column($aff->affinitiesFor($clubId, $users['owner']), 'name');
        $memberName = (string) $db->query("SELECT CONCAT(nome, ' ', cognome) FROM utenti WHERE id = {$users['member']}")->fetch_row()[0];
        $check($names === [$memberName], 'affinitiesFor(): only the active, opted-in counterpart is listed (' . implode(', ', $names) . ')');
        $db->query("UPDATE bookclub_members SET status = 'active' WHERE id = {$memberIds['custom']}");

        // ── #95 sprint cancel: the creator's right needs active membership ─
        $sprints = new \App\Plugins\BookClub\SprintController($db, $repo, $modules['sprints']);
        $future = date('Y-m-d H:i:s', time() + 86400);
        $sprintId = $q("INSERT INTO bookclub_sprints (club_id, title, starts_at, duration_min, created_by, status) VALUES (?, 'HB sprint', ?, 30, ?, 'scheduled')", 'isi', [$clubId, $future, $users['creator']]);
        $sprintStatus = static fn (): string => (string) $db->query("SELECT status FROM bookclub_sprints WHERE id = {$sprintId}")->fetch_row()[0];
        $db->query("UPDATE bookclub_members SET status = 'left' WHERE id = {$memberIds['creator']}");
        $session('creator');
        $res = $sprints->cancel(bcRequest(), bcResponse(), 'hb-club', $sprintId);
        $check($res->getStatusCode() === 404 && $sprintStatus() === 'scheduled', 'sprint cancel(): a creator who left cannot cancel (404, sprint untouched)');
        $db->query("UPDATE bookclub_members SET status = 'active' WHERE id = {$memberIds['creator']}");
        $session('creator');
        $sprints->cancel(bcRequest(), bcResponse(), 'hb-club', $sprintId);
        $check($sprintStatus() === 'cancelled', 'control: an active creator can cancel');

        // ── #96 stats panel follows stats.view ────────────────────────────
        $panel = static function (string $who) use ($modules, $club, $users): string {
            \App\Plugins\BookClub\Permissions::flushCache();
            return $modules['stats']->renderClubPanel(['club' => $club, 'isMember' => true, 'canManage' => false, 'userId' => $users[$who], 'states' => []]);
        };
        $check($panel('guest') === '', 'stats panel: an active guest (no stats.view) sees nothing');
        $check($panel('custom') === '', 'stats panel: a custom role without stats.view sees nothing');
        $check($panel('member') !== '', 'stats panel: a member (stats.view) sees it');

        // ── #93 export: pending proposals + non-active members for managers only
        $libro = $q("INSERT INTO libri (titolo) VALUES ('HB approved book')");
        $pendingLibro = $q("INSERT INTO libri (titolo) VALUES ('HB pending proposal')");
        $q("INSERT INTO bookclub_books (club_id, libro_id, state, proposed_by) VALUES (?, ?, 'approved', ?)", 'iii', [$clubId, $libro, $users['member']]);
        $q('INSERT INTO bookclub_books (club_id, libro_id, state, proposed_by) VALUES (?, ?, ?, ?)', 'iisi', [$clubId, $pendingLibro, \App\Plugins\BookClub\BookClubPlugin::STATE_PENDING, $users['member']]);
        $stats = new \App\Plugins\BookClub\StatsController($db, $repo, $modules['stats']);
        $export = static function (string $who) use ($stats, $session): array {
            $session($who);
            $res = $stats->exportJson(bcRequest('GET'), bcResponse(), 'hb-club');
            $data = json_decode((string) $res->getBody(), true);
            return [$res->getStatusCode(), is_array($data) ? $data : []];
        };
        [$status, $data] = $export('custom');
        $titles = array_column($data['books'] ?? [], 'titolo');
        $statuses = array_column($data['members'] ?? [], 'status');
        $check($status === 200 && in_array('HB approved book', $titles, true), 'export: a custom role with exports.run gets the export');
        $check(!in_array('HB pending proposal', $titles, true), 'export (non-manager): pending proposals are left out');
        $check($statuses !== [] && array_unique($statuses) === ['active'], 'export (non-manager): only active members are listed');
        [, $data] = $export('owner');
        $check(in_array('HB pending proposal', array_column($data['books'] ?? [], 'titolo'), true)
            && in_array('pending', array_column($data['members'] ?? [], 'status'), true), 'export (owner): pending proposals and members included');
        $_SESSION = [];

        // ── #74 public quotes: active, non-hidden clubs with quotes enabled ─
        $quoteLibro = $q("INSERT INTO libri (titolo) VALUES ('HB quoted book')");
        $quoteClub = static function (string $slug, string $privacy, int $active, array $modulesOn, bool $deleted = false) use ($q): int {
            return $q("INSERT INTO bookclub_clubs (slug, name, privacy, settings, ics_token, is_active, deleted_at) VALUES (?, ?, ?, ?, REPEAT('b', 32), ?, " . ($deleted ? 'NOW()' : 'NULL') . ')',
                'ssssi', [$slug, $slug, $privacy, (string) json_encode(['modules' => $modulesOn]), $active]);
        };
        $quotes = [
            'HBQ-visible' => $quoteClub('hb-q-ok', 'public', 1, ['quotes']),
            'HBQ-hidden-club' => $quoteClub('hb-q-hidden', 'hidden', 1, ['quotes']),
            'HBQ-inactive-club' => $quoteClub('hb-q-off', 'public', 0, ['quotes']),
            'HBQ-module-off' => $quoteClub('hb-q-nomod', 'public', 1, ['stats']),
            'HBQ-deleted-club' => $quoteClub('hb-q-del', 'public', 1, ['quotes'], true),
        ];
        foreach ($quotes as $text => $qClub) {
            $q("INSERT INTO bookclub_quotes (user_id, libro_id, club_id, quote, visibility) VALUES (?, ?, ?, ?, 'public')", 'iiis', [$users['member'], $quoteLibro, $qClub, $text]);
        }
        $html = $modules['quotes']->renderBookDetailQuotes($quoteLibro);
        $check(str_contains($html, 'HBQ-visible'), 'book page: a public quote of an active public club with the module on is shown');
        foreach (['HBQ-hidden-club' => 'a hidden club', 'HBQ-inactive-club' => 'an inactive club', 'HBQ-module-off' => 'a club with the quotes module off', 'HBQ-deleted-club' => 'a deleted club'] as $text => $why) {
            $check(!str_contains($html, $text), "book page: a quote of {$why} is not shown");
        }
        // Newer quotes of clubs with the module off (an explicit list without
        // it, or no list at all: the module is off by default) must not fill
        // the fetch window and starve the club that has it on.
        $offList = $quoteClub('hb-q-nomod2', 'public', 1, ['stats']);
        $offDefault = $q("INSERT INTO bookclub_clubs (slug, name, privacy, settings, ics_token, is_active) VALUES ('hb-q-default', 'hb-q-default', 'public', ?, REPEAT('c', 32), 1)", 's', ['{"color":"x"}']);
        $offBroken = $q("INSERT INTO bookclub_clubs (slug, name, privacy, settings, ics_token, is_active) VALUES ('hb-q-broken', 'hb-q-broken', 'public', ?, REPEAT('d', 32), 1)", 's', ['{not json']);
        for ($i = 0; $i < 40; $i++) {
            foreach ([$offList, $offDefault, $offBroken] as $offClub) {
                $q("INSERT INTO bookclub_quotes (user_id, libro_id, club_id, quote, visibility, created_at) VALUES (?, ?, ?, ?, 'public', NOW() + INTERVAL 1 HOUR)", 'iiis', [$users['member'], $quoteLibro, $offClub, "HBQ-flood-{$i}"]);
            }
        }
        $html = $modules['quotes']->renderBookDetailQuotes($quoteLibro);
        $check(str_contains($html, 'HBQ-visible') && !str_contains($html, 'HBQ-flood-'),
            'book page: 120 newer quotes of clubs with the module off do not hide the enabled club\'s quote');
        $onMany = $quoteClub('hb-q-many', 'public', 1, ['stats', 'quotes']);
        $q("INSERT INTO bookclub_quotes (user_id, libro_id, club_id, quote, visibility, created_at) VALUES (?, ?, ?, 'HBQ-second-enabled', 'public', NOW() + INTERVAL 2 HOUR)", 'iii', [$users['member'], $quoteLibro, $onMany]);
        $html = $modules['quotes']->renderBookDetailQuotes($quoteLibro);
        $check(str_contains($html, 'HBQ-second-enabled') && str_contains($html, 'HBQ-visible'), 'book page: a module list holding quotes among others counts as enabled');

        // ── #68 AI cap: the attempt is booked before the paid call ────────
        $ai = new \App\Plugins\BookClub\AiService($db);
        $cap = \App\Plugins\BookClub\AiService::MAX_OUTPUTS_PER_DAY;
        $aiClub = $clubId;
        for ($i = 0; $i < $cap - 1; $i++) {
            $q("INSERT INTO bookclub_ai_outputs (club_id, kind, entity_id, content, model) VALUES (?, 'questions', 1, 'done', 'm')", 'i', [$aiClub]);
        }
        $fake = new class ($db) extends \App\Plugins\BookClub\AiService {
            /** @var list<int> rows already booked for the club when generate() ran */
            public array $calls = [];
            public int $club = 0;
            public function isConfigured(): bool { return true; }
            // The controller checks the key before it books a slot.
            public function apiKey(): string { return 'test-key'; }
            public function generate(string $system, string $user): ?string
            {
                $this->calls[] = $this->countRecentOutputs($this->club, 24);
                return null; // the provider failed (billed or not)
            }
        };
        $fake->club = $aiClub;
        $aiCtrl = new \App\Plugins\BookClub\AiController($db, $repo, $modules['ai']);
        (new ReflectionProperty(\App\Plugins\BookClub\AiController::class, 'ai'))->setValue($aiCtrl, $fake);
        $clubBook = $scalar("SELECT id FROM bookclub_books WHERE club_id = {$clubId} AND libro_id = {$libro}");
        $session('admin');
        for ($i = 0; $i < 3; $i++) {
            $aiCtrl->generateQuestions(bcRequest('POST', ['club_book_id' => (string) $clubBook]), bcResponse(), 'hb-club');
        }
        $check($fake->calls === [$cap], "AI: with one slot left the provider is called once, with the attempt already booked ({$cap} rows)");
        $check($scalar("SELECT COUNT(*) FROM bookclub_ai_outputs WHERE club_id = {$aiClub}") === $cap, 'AI: the failed call still counts toward the daily cap');
        $check(count($ai->listOutputs($aiClub, 100)) === $cap - 1, 'AI: the history hides the reserved-but-failed attempt');
        $check($ai->reserveAttempt($aiClub, 'questions', 1, null) === null, 'AI: a full cap books nothing more');
        // Under a per-club lock: while another connection holds it, nothing is
        // booked (the check-then-insert cannot interleave).
        $db->query("DELETE FROM bookclub_ai_outputs WHERE club_id = {$aiClub}");
        $other = hardeningSandboxConnect($sandbox['name']);
        $other->query("SELECT GET_LOCK('pinakes_bookclub_ai_{$aiClub}', 0)");
        $check($ai->reserveAttempt($aiClub, 'questions', 1, null) === null
            && $scalar("SELECT COUNT(*) FROM bookclub_ai_outputs WHERE club_id = {$aiClub}") === 0, 'AI: no booking while another request holds the club lock');
        $other->query("SELECT RELEASE_LOCK('pinakes_bookclub_ai_{$aiClub}')");
        $other->close();
        $check(is_int($ai->reserveAttempt($aiClub, 'questions', 1, null)), 'AI: once the lock is free an attempt is booked');
        $_SESSION = [];
    } catch (\Throwable $e) {
        $check(false, 'book-club DB section raised ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        $_SESSION = [];
        ($sandbox['drop'])();
    }
}

// ── I. RDA gate, bound LIMIT/OFFSET, SRU columns, PDF check ────────────────
echo "\nI. RDA gate (#54), bound LIMIT/OFFSET (#104), SRU columns (#87), PDF (#83)\n";
$rda = $src('storage/plugins/bibframe-linked-data/RdaRegistryBuilder.php');
$check(str_contains($rda, "name = 'frbr-lrm'") && substr_count($rda, "frbrTableExists('") === 4 && !str_contains($rda, "->tableExists('opere')") && !str_contains($rda, "->tableExists('espressioni')"),
    'every Work/Expression lookup is gated on frbr-lrm active');
// #104 is checked on the token stream, not by running the queries: every
// spliced value was already an int cast in PHP, so a bound and a spliced
// LIMIT return the same rows for every input — no behavioural test can tell
// them apart, and one would keep passing if the binding were reverted. The
// scan therefore asks the structural question directly and independently of
// variable names or spacing: does any SQL string literal END in LIMIT/OFFSET
// (or "LIMIT ?,") and then get concatenated with '.', or interpolate a
// variable right after the keyword ("… OFFSET {$x}")? Against the pre-fix
// sources it reports every spliced site; on the fixed ones, none.
/** @return list<string> */
$pagingSplices = static function (string $code): array {
    $tokens = token_get_all($code);
    $count = count($tokens);
    $text = static fn ($t): string => is_array($t) ? $t[1] : $t;
    $kind = static fn ($t): int => is_array($t) ? $t[0] : 0;
    $endsWithKeyword = static fn (string $s): bool => preg_match('/\b(?:LIMIT|OFFSET)\s*$/i', $s) === 1
        || preg_match('/\bLIMIT\s+(?:\?|\d+)\s*,\s*$/i', $s) === 1;
    $hits = [];
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if ($kind($t) === T_CONSTANT_ENCAPSED_STRING && $endsWithKeyword(substr($text($t), 1, -1))) {
            $j = $i + 1;
            while ($j < $count && in_array($kind($tokens[$j]), [T_WHITESPACE, T_COMMENT], true)) {
                $j++;
            }
            if ($j < $count && $text($tokens[$j]) === '.') {
                $hits[] = 'line ' . $t[2];
            }
        } elseif ($kind($t) === T_ENCAPSED_AND_WHITESPACE && $endsWithKeyword($text($t))
            && in_array($kind($tokens[$i + 1] ?? ''), [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $hits[] = 'line ' . $t[2];
        }
    }
    return $hits;
};
foreach ([
    'archives SRU/OAI' => 'storage/plugins/archives/ArchivesPlugin.php',
    'emeroteca ContributionService' => 'storage/plugins/emeroteca/src/Services/ContributionService.php',
    'emeroteca MobileModule' => 'storage/plugins/emeroteca/src/Modules/MobileModule.php',
    'emeroteca public listing' => 'storage/plugins/emeroteca/src/Controllers/PublicController.php',
    'desiderata admin' => 'storage/plugins/desiderata/DesiderataPlugin.php',
    'book-club sprints' => 'storage/plugins/book-club/src/ExtensionsRepo.php',
] as $label => $file) {
    $hits = $pagingSplices($src($file));
    $check($hits === [], "{$label}: LIMIT/OFFSET bound, never spliced" . ($hits !== [] ? ' — ' . implode(', ', $hits) : ''));
}
$check($pagingSplices("<?php \$a = 'SELECT 1 LIMIT ' . (int) \$n; \$b = \"SELECT 1 LIMIT 5 OFFSET {\$o}\"; \$c = 'LIMIT ?, ' . \$x;") === ['line 1', 'line 1', 'line 1'],
    'the scan itself flags a concatenated, an interpolated and a half-bound LIMIT');
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
    echo "SKIP: no database configured (E2E_DB_NAME/E2E_DB_USER or .env)\n";
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
