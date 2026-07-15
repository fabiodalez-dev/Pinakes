<?php
declare(strict_types=1);

/**
 * Cross-plugin contract guards for issue #237.
 *
 * Protocol exports keep canonical names and preserve contributor roles; user
 * interfaces may search/display pseudonyms. No database or active plugin state
 * is required, so this runs on an empty CI installation too.
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        echo "  OK  {$label}\n";
    } else {
        ++$failed;
        echo "  FAIL {$label}\n";
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

echo "A. Bundled plugin surface\n";
$manifests = glob($root . '/storage/plugins/*/plugin.json') ?: [];
$check(count($manifests) === 20, 'all 20 bundled plugin manifests are covered by the compatibility audit');
$check(count(array_filter($manifests, static fn (string $file): bool => is_array(json_decode((string) file_get_contents($file), true)))) === count($manifests), 'all bundled plugin manifests remain valid JSON');

echo "B. Identity boundary\n";
$authors = $read('app/Models/AuthorRepository.php');
$check(str_contains($authors, 'findByCanonicalName') && str_contains($authors, 'intentionally never searches `pseudonimo`'), 'import lookup has an explicit canonical-name-only API');
foreach ([
    'app/Controllers/LibriController.php',
    'app/Controllers/CsvImportController.php',
    'app/Controllers/LibraryThingImportController.php',
    'app/Support/ContributorSync.php',
    'storage/plugins/book-club/src/Repo.php',
] as $path) {
    $check(str_contains($read($path), 'findByCanonicalName'), basename($path) . ' resolves imported names canonically');
}
$sbnAuthority = $read('storage/plugins/z39-server/classes/SbnAuthorityClient.php');
$check(!str_contains($sbnAuthority, 'pseudonimo'), 'SBN authority records are never written into the pseudonym field');

echo "C. Role-aware interoperability\n";
$openUrl = $read('storage/plugins/openurl-resolver/OpenUrlResolverPlugin.php');
$check(substr_count($openUrl, "ruolo IN (\\'principale\\', \\'co-autore\\')") >= 1 && substr_count($openUrl, "ruolo IN ('principale', 'co-autore')") >= 1, 'OpenURL exposes canonical creators only');
$bibframe = $read('storage/plugins/bibframe-linked-data/BibframeLinkedDataPlugin.php');
$check(str_contains($bibframe, "relators/clr") && str_contains($bibframe, "la2.ruolo IN"), 'BIBFRAME keeps creator selection separate and maps colorist');
$ncip = $read('storage/plugins/ncip-server/NcipServerPlugin.php');
$check(str_contains($ncip, 'la2.ruolo IN'), 'NCIP primary author cannot fall through to a contributor');

require_once $root . '/storage/plugins/z39-server/classes/UnimarcLibriParser.php';
$check(\Z39Server\UnimarcLibriParser::relatorForRole('traduttore') === '730', 'UNIMARC translator relator is 730');
$check(\Z39Server\UnimarcLibriParser::relatorForRole('illustratore') === '440', 'UNIMARC illustrator relator is 440');
$check(\Z39Server\UnimarcLibriParser::relatorForRole('colorista') === '410', 'UNIMARC colorist relator is 410');

$oai = $read('storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php');
$check(str_contains($oai, "? 'creator' : 'contributor'") && str_contains($oai, "? (\$mainCreatorWritten ? '701' : '700') : '702'"), 'OAI Dublin Core and UNIMARC preserve creator/contributor semantics');
$check(str_contains($oai, "'traduttore'   => '730'") && str_contains($oai, "'illustratore' => '440'") && str_contains($oai, "'colorista'    => '410'"), 'OAI UNIMARC mappings use the standard relator codes');

echo "D. Plugin and API presentation\n";
foreach ([
    'LibraryRepo.php', 'Repo.php', 'StatsRepo.php', 'LendingRepo.php',
    'QuoteRepo.php', 'AffinityRepo.php', 'ReadingRepo.php',
] as $file) {
    $src = $read('storage/plugins/book-club/src/' . $file);
    $check((str_contains($src, 'a.pseudonimo') || str_contains($src, 'AuthorName::displaySql')) && str_contains($src, "la.ruolo IN ('principale', 'co-autore')"), 'Book Club ' . $file . ' displays preferred creator names only');
}
$challenge = $read('storage/plugins/book-club/src/ChallengeRepo.php');
$check(str_contains($challenge, "la.ruolo IN (\\'principale\\', \\'co-autore\\')"), 'Book Club author challenges count creators only');

$mobile = $read('storage/plugins/mobile-api/src/Controllers/CatalogController.php');
$check(str_contains($mobile, 'canonical_name') && str_contains($mobile, "'pseudonym'") && str_contains($mobile, 'a_q.pseudonimo LIKE'), 'Mobile API returns both identities and searches pseudonyms');
$check(str_contains($mobile, '$creatorAuthors') && str_contains($mobile, "la.ruolo IN ('principale', 'co-autore')"), 'Mobile related-title logic uses creators only');
$openApi = $read('storage/plugins/mobile-api/src/Controllers/OpenApiController.php');
$check(str_contains($openApi, "'canonical_name'") && str_contains($openApi, "'colorista'"), 'Mobile OpenAPI documents identity fields and all roles');

$frbr = $read('storage/plugins/frbr-lrm/OpereRepository.php') . $read('storage/plugins/frbr-lrm/EspressioniRepository.php');
$check(str_contains($frbr, 'AuthorName::displaySql'), 'FRBR/LRM views use preferred display names');
$archives = $read('storage/plugins/archives/ArchivesPlugin.php');
$check(str_contains($archives, 'a.pseudonimo LIKE ?') && str_contains($archives, 'MATCH(a.nome)'), 'Archives UI searches pseudonyms while authority reconciliation remains canonical');

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} passed, {$failed} FAILED\n");
exit($failed === 0 ? 0 : 1);
