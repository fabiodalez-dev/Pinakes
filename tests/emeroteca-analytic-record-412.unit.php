<?php
declare(strict_types=1);
/**
 * Standalone articles as ANALYTIC (component-part) records — issue #412.
 *
 * Hans Uwe Petersen catalogues single articles out of journals he does not own
 * the run of. What he asked for, in danMARC2 terms, is that such a record stop
 * being nine free-text boxes and become the thing library science already has
 * a name for: a record that describes a piece OF something, with the host
 * publication, the classification, the language and country of publication,
 * the note saying the library holds a copy and not the run, and the electronic
 * location with its link text and access conditions.
 *
 * Almost none of that is Danish. danMARC2 is, field 004 is, and DK5 is — but
 * 245 $a/$b, 100/700, 008, 773, 653 and 856 $u/$y/$z are MARC 21 down to the
 * subfield letters, and Pinakes already emits MARC 21 through its OAI-PMH and
 * SRU plugins. So the classification is stored as a SCHEME plus a VALUE (084
 * $2 and $a) and DK5 is one scheme beside DDC, UDC, LCC and RVK rather than
 * the model every other country has to work around. Several checks below exist
 * only to hold that line.
 *
 * Real MySQL, real services, real migration. SQL identifiers alone are
 * remapped to disposable tables; no existing catalogue is modified.
 *
 *   php tests/emeroteca-analytic-record-412.unit.php
 */
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/EmerotecaPlugin.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Services/ContributionService.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Services/ContributionCsv.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Support/CitationFormatter.php';

use App\Plugins\Emeroteca\Services\ContributionService;
use App\Plugins\Emeroteca\Services\ContributionCsv;
use App\Plugins\Emeroteca\Support\CitationFormatter;

final class SandboxAnalyticDb extends mysqli
{
    public string $prefix = '';
    /** @var list<string> */
    public array $tables = ['emeroteca_testate','emeroteca_annate','emeroteca_fascicoli','emeroteca_articoli','emeroteca_abbonamenti','emeroteca_contributi','emeroteca_contributi_autori','plugin_settings','plugins','plugin_hooks'];

    public function mapped(string $sql): string
    {
        foreach ($this->tables as $name) {
            $sql = preg_replace('/\b'.preg_quote($name, '/').'\b/', $this->prefix.$name, $sql) ?? $sql;
        }
        // Constraint names are database-global on MariaDB.
        return preg_replace('/\b(fk_emeroteca_\w+|fk_contributo_\w+)\b/', $this->prefix.'$1', $sql) ?? $sql;
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        return parent::query($this->mapped($query), $result_mode);
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        return new SandboxAnalyticStmt($this, $this->mapped($query));
    }
}

final class SandboxAnalyticStmt extends mysqli_stmt
{
    /** @var array<int,mixed> kept alive: bind_param() binds by reference */
    private array $boundValues = [];

    public function __construct(private SandboxAnalyticDb $sandbox, string $sql)
    {
        parent::__construct($sandbox, $sql);
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->boundValues = [];
        foreach ($vars as $i => $v) {
            $this->boundValues[$i] = (is_string($v) && in_array($v, $this->sandbox->tables, true))
                ? $this->sandbox->prefix.$v
                : $v;
        }
        $refs = [];
        foreach ($this->boundValues as $i => &$value) {
            $refs[$i] = &$value;
        }
        unset($value);

        return parent::bind_param($types, ...$refs);
    }
}

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root.'/.env'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new SandboxAnalyticDb(
    $env['DB_HOST'] ?? 'localhost',
    getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? $env['DB_PASSWORD']),
    getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) ($env['DB_PORT'] ?? 3306),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null)
);
$db->prefix = 'zzana_'.bin2hex(random_bytes(3)).'_';
$db->set_charset('utf8mb4');

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
$rejects = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        $check(true, $label);

        return;
    }
    $check(false, $label.' (accepted when it should have been refused)');
};

/** The ten columns the analytic record adds, in the order they must appear. */
const ANALYTIC_COLUMNS = ['sottotitolo','lingua','paese','classificazione_schema','classificazione','nota_possesso','risorsa_url','risorsa_testo','risorsa_accesso','risorsa_pubblica'];

/** Uwe's own article, as the Royal Danish Library records it. */
const UWE = [
    'titolo' => 'På sporet af et internationalt samarbejde blandt kedel- og maskinpassere i den anti-fascistiske kamp',
    'sottotitolo' => 'faglig solidaritet med Hitler-Fascismens ofre',
    'autori' => 'Petersen, Hans Uwe',
    'anno_pubblicazione' => 1988,
    'contenitore_titolo' => 'Arbejderhistorie',
    'contenitore_tipo' => 'rivista',
    'numero' => '31',
    'pagine' => '18-38',
    'lingua' => 'dan',
    'paese' => 'DK',
    'classificazione_schema' => 'DK5',
    'classificazione' => '33.129',
    'keywords' => 'Danmark, fagbevægelsen, nazisme',
];

try {
    $db->query('CREATE TABLE plugins (id INT PRIMARY KEY, name VARCHAR(100) UNIQUE) ENGINE=InnoDB');
    $db->query('CREATE TABLE plugin_settings (plugin_id INT, setting_key VARCHAR(100), setting_value TEXT, UNIQUE KEY (plugin_id,setting_key)) ENGINE=InnoDB');
    $db->query("INSERT INTO plugins VALUES (1,'emeroteca')");
    foreach ([EmerotecaPlugin::ddlTestate(), EmerotecaPlugin::ddlAnnate(), EmerotecaPlugin::ddlFascicoli(), EmerotecaPlugin::ddlArticoli(), EmerotecaPlugin::ddlAbbonamenti()] as $ddl) {
        $db->query($ddl);
    }
    $plugin = new EmerotecaPlugin($db, new \App\Support\HookManager($db));
    $schema = $plugin->ensureSchema();
    if ($schema['failed'] !== []) {
        throw new RuntimeException('sandbox schema build failed: '.implode(',', $schema['failed']));
    }
    $svc = new ContributionService($db);
    $columns = static fn (): array => array_column($svc->rows('SHOW COLUMNS FROM emeroteca_contributi'), 'Field');
    $inventory = static fn (): array => $svc->rows(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$db->prefix}emeroteca_contributi' ORDER BY ORDINAL_POSITION"
    );

    // -----------------------------------------------------------------------
    echo "\nA. The upgrade, run for real against the schema 1.6.0 shipped\n";

    // A fragment copied from the sibling tables in additiveColumnDefs() would
    // carry an AFTER clause. That is legal in ALTER and a SYNTAX ERROR in
    // CREATE TABLE — and ddl() interpolates these same fragments into CREATE
    // TABLE. So the mistake breaks every FRESH install while every upgrade
    // stays green, which is the hardest direction to notice.
    $check(!str_contains(ContributionService::ddl(), ' AFTER '),
        'no AFTER clause can reach CREATE TABLE');
    $check($db->query(ContributionService::ddl()) !== false,
        'and the DDL is still executable a second time');

    foreach (ANALYTIC_COLUMNS as $column) {
        $db->query("ALTER TABLE emeroteca_contributi DROP COLUMN {$column}");
    }
    $legacy = $columns();
    $check($legacy === array_values(array_diff($legacy, ANALYTIC_COLUMNS)) && end($legacy) === 'updated_at',
        'the table is back to the 1.6.0 shape, ending at updated_at');
    $db->query("INSERT INTO emeroteca_contributi (reference_key, titolo, autori) VALUES ('legacy-1', 'Un articolo del 1.6', 'Rossi, Mario')");

    // A NEW instance: an upgrading installation has no warm table cache.
    $upgrade = (new EmerotecaPlugin($db, new \App\Support\HookManager($db)))->ensureSchema();
    $check($upgrade['failed'] === [], 'the real upgrade reports no failed table');

    $after = $columns();
    $check(array_slice($after, -count(ANALYTIC_COLUMNS)) === ANALYTIC_COLUMNS,
        'the ten analytic columns are appended, in order, after updated_at');

    $types = [];
    foreach ($inventory() as $row) {
        $types[$row['COLUMN_NAME']] = $row;
    }
    $expected = [
        'sottotitolo' => ['varchar(500)','YES',null],
        'lingua' => ['varchar(10)','YES',null],
        'paese' => ['varchar(2)','YES',null],
        'classificazione_schema' => ['varchar(20)','YES',null],
        'classificazione' => ['varchar(100)','YES',null],
        'nota_possesso' => ['varchar(255)','YES',null],
        'risorsa_url' => ['varchar(500)','YES',null],
        'risorsa_testo' => ['varchar(255)','YES',null],
        'risorsa_accesso' => ['varchar(255)','YES',null],
        'risorsa_pubblica' => ['tinyint(1)','NO','0'],
    ];
    // "No default" has two spellings. MySQL reports COLUMN_DEFAULT as SQL NULL
    // for a nullable column that declares no default; MariaDB reports the
    // four-character STRING 'NULL' (verified: MySQL 9.6 vs MariaDB 12.3, and
    // the CI matrix agrees). Reading it as SQL NULL alone passes on MySQL and
    // fails on every MariaDB, which is a difference in information_schema, not
    // in the schema the plugin built.
    $noDefault = static fn ($raw): bool => $raw === null || strtoupper((string) $raw) === 'NULL';

    foreach ($expected as $column => [$type, $nullable, $default]) {
        $got = $types[$column] ?? null;
        // One assertion per property: a single compound check reports only
        // "the column is wrong" and leaves the reader to guess which of three
        // facts broke.
        $check($got !== null, "  {$column} exists");
        if ($got === null) {
            continue;
        }
        $check(strtolower((string) $got['COLUMN_TYPE']) === $type,
            "  {$column} is {$type} (got " . strtolower((string) $got['COLUMN_TYPE']) . ')');
        $check($got['IS_NULLABLE'] === $nullable,
            "  {$column} nullable={$nullable} (got {$got['IS_NULLABLE']})");
        $check($default === null
                ? $noDefault($got['COLUMN_DEFAULT'])
                : (string) $got['COLUMN_DEFAULT'] === (string) $default,
            "  {$column} default=" . ($default ?? 'none')
                . ' (got ' . ($got['COLUMN_DEFAULT'] === null ? 'SQL NULL' : (string) $got['COLUMN_DEFAULT']) . ')');
    }

    // Every new field is OPTIONAL. A library that catalogues nothing but books
    // must not be forced to say what language an article it never entered is
    // in — and the row that predates the upgrade must survive it untouched.
    $legacyRow = $svc->rows("SELECT * FROM emeroteca_contributi WHERE reference_key='legacy-1'")[0];
    $check($legacyRow['titolo'] === 'Un articolo del 1.6', 'the pre-upgrade row is still there');
    $check(array_reduce(array_slice(ANALYTIC_COLUMNS, 0, 9), static fn ($c, $k) => $c && $legacyRow[$k] === null, true),
        'and every new descriptive field on it is NULL, not an empty string');
    $check((int) $legacyRow['risorsa_pubblica'] === 0, 'the new visibility flag defaults to hidden');

    $snapshot = $inventory();
    (new EmerotecaPlugin($db, new \App\Support\HookManager($db)))->ensureSchema();
    $check($inventory() === $snapshot, 'running the upgrade again changes nothing (idempotent)');

    // The boot-time self-heal re-runs onActivate() when a column is missing.
    // Without the sentinel a half-applied upgrade stays half-applied for ever.
    $expectedColumns = (new ReflectionMethod(EmerotecaPlugin::class, 'expectedColumns'))->invoke($plugin);
    $sentinels = array_column(array_filter($expectedColumns, static fn ($e) => $e['table'] === 'emeroteca_contributi'), 'column');
    $check(array_diff(ANALYTIC_COLUMNS, $sentinels) === [],
        'each new column is a boot-time self-heal sentinel');

    // -----------------------------------------------------------------------
    echo "\nB. What the record refuses to store\n";

    $check(ContributionService::normalize(['titolo' => 'x','lingua' => 'DAN'])['lingua'] === 'dan',
        'a language code is stored lower case, however it is typed');
    $check(ContributionService::normalize(['titolo' => 'x','paese' => 'dk'])['paese'] === 'DK',
        'a country code is stored upper case');
    $rejects(static fn () => ContributionService::normalize(['titolo' => 'x','lingua' => 'Danish']),
        'a language NAME is refused: the column holds a code so it can be read back in the reader’s own language');
    $rejects(static fn () => ContributionService::normalize(['titolo' => 'x','paese' => 'DNK']),
        'a three-letter country is refused: ISO 3166 alpha-2 or nothing');
    $rejects(static fn () => ContributionService::normalize(['titolo' => 'x','nota_possesso' => "solo copia\nestratto"]),
        'a line break in the holdings note is refused at the door');
    $rejects(static fn () => ContributionService::normalize(['titolo' => 'x','risorsa_accesso' => "ad uso interno\r\nsoltanto"]),
        'and in the access conditions, because a break would split a RIS record in two');
    $rejects(static fn () => ContributionService::normalize(['titolo' => 'x','risorsa_url' => 'http://not a url']),
        'a malformed http address is refused');
    $rejects(static fn () => ContributionService::normalize(['titolo' => 'x','risorsa_pubblica' => '2']),
        'the resource visibility flag takes 0 or 1 and nothing else');
    $check(ContributionService::normalize(['titolo' => 'x','classificazione_schema' => 'DK5','classificazione' => '33.129'])['classificazione'] === '33.129',
        'a DK5 notation is stored as typed — no scheme is privileged');
    $check(ContributionService::normalize(['titolo' => 'x','classificazione_schema' => 'DDC','classificazione' => '853.92'])['classificazione_schema'] === 'DDC',
        'and so is a Dewey one: the scheme is data, not a branch in the code');

    // -----------------------------------------------------------------------
    echo "\nC. The 856 triple decides once, for every reader\n";

    $offline = ['risorsa_url' => '\\\\archivio\\scansioni\\1988-31.pdf','risorsa_pubblica' => 1];
    $online = ['risorsa_url' => 'https://arkiv.example/1988-31.pdf','risorsa_testo' => 'Download the article as a PDF','risorsa_accesso' => 'For internal use only','risorsa_pubblica' => 1];

    $check(ContributionService::resource([], true) === null, 'no address, nothing to show');
    $check(ContributionService::resource(['risorsa_url' => 'https://x/y'], true) === null,
        'an unpublished resource is invisible to the public, address and all');
    $check(ContributionService::resource(['risorsa_url' => 'https://x/y'], false)!== null,
        'while the cataloguer still sees it');
    $res = ContributionService::resource($online, true);
    $check($res !== null && $res['linkable'] === true && $res['text'] === 'Download the article as a PDF' && $res['access'] === 'For internal use only',
        'an https address is linkable and carries its link text and access conditions');
    $res = ContributionService::resource($offline, true);
    $check($res !== null && $res['linkable'] === false,
        'a UNC share is kept, and is NOT linkable');
    foreach (['file:///srv/scans/a.pdf', 'javascript:alert(1)', 'DMS:2026/117', 'ftp://x/y'] as $opaque) {
        $res = ContributionService::resource(['risorsa_url' => $opaque,'risorsa_pubblica' => 1], true);
        $check($res !== null && $res['linkable'] === false, "  never linkable: {$opaque}");
    }

    // -----------------------------------------------------------------------
    echo "\nD. What this record IS, said in the reader's language\n";

    $check(ContributionService::materialType(['contenitore_tipo' => 'giornale']) !== ContributionService::materialType(['contenitore_tipo' => 'rivista']),
        'a newspaper article and a journal article do not read the same');
    $check(ContributionService::materialType([]) !== '', 'and one with no host still says what it is');

    // -----------------------------------------------------------------------
    echo "\nE. A round trip through the database, and what reaches the public\n";

    $db->begin_transaction();
    $id = $svc->save(UWE + $online + ['pubblico' => 1]);
    $row = $svc->get($id);
    $check($row['sottotitolo'] === UWE['sottotitolo'], 'the subtitle survives the round trip');
    $check($row['lingua'] === 'dan' && $row['paese'] === 'DK', 'so do the ISO codes');
    $check($row['classificazione_schema'] === 'DK5' && $row['classificazione'] === '33.129', 'and the classification with its scheme');

    $public = ContributionService::publicData($row);
    $check(($public['sottotitolo'] ?? null) === UWE['sottotitolo'], 'the subtitle reaches the mobile payload');
    $check(($public['classificazione'] ?? null) === '33.129', 'and the classification');
    $check(($public['has_public_resource'] ?? null) === true, 'a published resource is announced');
    $check(($public['risorsa_url'] ?? null) === $online['risorsa_url'], 'and carried');
    $check(!array_key_exists('collocazione', $public) && !array_key_exists('note_private', $public),
        'while the shelf mark and the private notes stay out, as they always have');

    $svc->save(UWE + ['risorsa_url' => $online['risorsa_url'],'risorsa_pubblica' => 0,'pubblico' => 1], $id, (int) $row['revision']);
    $hidden = ContributionService::publicData($svc->get($id));
    $check(($hidden['has_public_resource'] ?? null) === false, 'an unpublished resource is announced as absent');
    $check(!array_key_exists('risorsa_url', $hidden),
        'and its key is ABSENT, not null — a null address still says one exists');
    $db->rollback();

    // -----------------------------------------------------------------------
    echo "\nF. The citation the catalogue already knew\n";

    $apa = CitationFormatter::apa(UWE);
    $harvard = CitationFormatter::harvard(UWE);
    $check(str_starts_with($apa, 'Petersen, H. U. (1988).'), 'APA: inverted name, initials, year');
    $check(str_contains($apa, 'Arbejderhistorie, 31, 18–38.'), 'APA: container, issue and page span with an en dash');
    $check(str_contains($apa, UWE['titolo'].' : '.UWE['sottotitolo']),
        'APA: title and subtitle joined the way ISBD joins them');
    $check(str_starts_with($harvard, "Petersen, H.U. (1988) '"), 'Harvard: no space between initials');
    $check(str_contains($harvard, ', (31), pp. 18–38.'), 'Harvard: issue in brackets, pp. for a span');

    $check(CitationFormatter::apa(['titolo' => 'Senza autore','anno_pubblicazione' => 2020]) === 'Senza autore. (2020).',
        'with no author the title takes the author slot, as APA prescribes');
    $check(str_contains(CitationFormatter::apa(['titolo' => 'x']), '(n.d.)'),
        'with no year anywhere the citation says so rather than inventing one');
    $check(str_contains(CitationFormatter::apa(['titolo' => 'x','data_pubblicazione_testo' => 'June 2019']), '(2019)'),
        'a year written only in the free-text date is still found');
    $check(str_contains(CitationFormatter::apa(['titolo' => 'x','data_pubblicazione_testo' => 'pp. 138-148']), '(n.d.)'),
        'and a page number in that field is not mistaken for one');
    $check(str_starts_with(CitationFormatter::apa(['titolo' => 'x','autori' => 'Institute of Science and Technology','anno_pubblicazione' => 2001]), 'Institute of Science and Technology '),
        'a corporate author is never initialised: guessing its surname would be wrong');
    $check(str_contains(CitationFormatter::apa(['titolo' => 'x','autori' => 'Rossi, Mario; Bianchi, Anna','anno_pubblicazione' => 2001]), 'Rossi, M. & Bianchi, A.'),
        'two authors are joined, and the comma inside a name is not a separator');

    $parts = CitationFormatter::parts(['pagine' => '138–148']);
    $check($parts['pageStart'] === '138' && $parts['pageEnd'] === '148', 'an en-dashed span splits');
    $parts = CitationFormatter::parts(['pagine' => 'S. 18-38']);
    $check($parts['pageStart'] === 'S. 18' && $parts['pageEnd'] === '38', 'a prefixed span splits at the hyphen');
    $parts = CitationFormatter::parts(['pagine' => 'iv']);
    $check($parts['pageStart'] === 'iv' && $parts['pageEnd'] === '', 'a single page is a start with no end');

    // -----------------------------------------------------------------------
    echo "\nG. RIS, as EndNote actually reads it\n";

    $ris = CitationFormatter::ris(UWE, 'https://biblioteca.example/emeroteca/articolo/7', 'https://biblioteca.example/emeroteca/articolo/7/pdf');
    $check(str_starts_with($ris, "TY  - JOUR\r\n"), 'the first line is the type, with two spaces before the hyphen');
    $check(str_ends_with($ris, "ER  - \r\n"), 'and the last is the end-of-record marker');
    $check(substr_count($ris, "\n") === substr_count($ris, "\r\n"),
        'every line ends CR LF — the specification says so and EndNote on Windows enforces it');
    $check(str_contains($ris, "AU  - Petersen, Hans Uwe\r\n"), 'the author is written as catalogued');
    $check(str_contains($ris, "SP  - 18\r\n") && str_contains($ris, "EP  - 38\r\n"), 'the page span is split into its ends');
    $check(str_contains($ris, "LA  - dan\r\n"), 'the language travels as its code');
    $check(substr_count($ris, "KW  - ") === 3, 'each keyword is its own line');
    $check(str_contains($ris, "UR  - https://biblioteca.example/emeroteca/articolo/7\r\n"), 'the record URL is included when the caller has one');
    $check(str_contains($ris, "L1  - https://biblioteca.example/emeroteca/articolo/7/pdf\r\n"), 'and the PDF as a file link');
    $check(!str_contains(CitationFormatter::ris(UWE), 'UR  - '), 'with no URL passed, no empty UR line is emitted');

    $check(str_starts_with(CitationFormatter::ris(['titolo' => 'x','contenitore_tipo' => 'giornale','contenitore_titolo' => 'Politiken']), "TY  - NEWS\r\n"),
        'a newspaper article is NEWS, which is what a reference manager expects');
    $check(str_starts_with(CitationFormatter::ris(['titolo' => 'x']), "TY  - GEN\r\n"),
        'and a record that never said what it came out of does not claim to be a journal article');

    $multiline = CitationFormatter::ris(['titolo' => 'x','abstract' => "Prima riga.\r\n\r\nSeconda riga."]);
    $check(substr_count($multiline, 'AB  - ') === 1 && str_contains($multiline, 'AB  - Prima riga. Seconda riga.'),
        'an abstract pasted out of a PDF stays ONE line: a break would start a new tag and truncate the record');
    $check(CitationFormatter::fileName(7) === 'articolo-7.ris', 'the download has a name a human can read');

    // -----------------------------------------------------------------------
    echo "\nH. Nothing here is Denmark-only\n";

    // The same record, catalogued by an Italian, a German and an Anglo-American
    // library. If any of these needed different code, the feature would have
    // been built around one country's rules.
    $international = [
        'italiana' => ['lingua' => 'ita','paese' => 'IT','classificazione_schema' => 'DDC','classificazione' => '853.92'],
        'tedesca' => ['lingua' => 'ger','paese' => 'DE','classificazione_schema' => 'RVK','classificazione' => 'GM 1234'],
        'inglese' => ['lingua' => 'eng','paese' => 'GB','classificazione_schema' => 'LCC','classificazione' => 'PT2621'],
        'francese' => ['lingua' => 'fre','paese' => 'FR','classificazione_schema' => 'UDC','classificazione' => '821.133.1'],
    ];
    $db->begin_transaction();
    foreach ($international as $country => $fields) {
        $normalized = ContributionService::normalize(['titolo' => 'Un articolo '.$country] + $fields);
        $check($normalized['classificazione_schema'] === $fields['classificazione_schema']
            && $normalized['lingua'] === $fields['lingua']
            && $normalized['paese'] === $fields['paese'],
            "  a {$country} record stores its own scheme and codes unchanged");
        $newId = $svc->save(['titolo' => 'Un articolo '.$country] + $fields);
        $check($svc->get($newId)['classificazione'] === $fields['classificazione'], "  and reads back intact");
    }
    // Two-letter ISO 639-1 is accepted alongside three-letter 639-2, because a
    // cataloguer should not have to know which list the field wanted.
    foreach (['da','it','en','de','fr'] as $short) {
        $check(ContributionService::normalize(['titolo' => 'x','lingua' => $short])['lingua'] === $short,
            "  ISO 639-1 '{$short}' is accepted too");
    }
    $db->rollback();

    // -----------------------------------------------------------------------
    echo "\nI. The CSV keeps carrying everything\n";

    $db->begin_transaction();
    $svc->save(UWE + $online + ['reference_key' => 'analytic-csv-1','pubblico' => 1]);
    $csv = new ContributionCsv($svc);
    $exported = $csv->export();
    $header = str_getcsv(explode("\n", $exported)[0]);
    foreach (ANALYTIC_COLUMNS as $column) {
        $check(in_array($column, $header, true), "  the export header carries {$column}");
    }
    $roundTrip = $csv->preview($exported);
    $check(array_filter(array_column($roundTrip, 'error')) === [],
        'the catalogue can re-import its own export without an error');
    $reimported = null;
    foreach ($roundTrip as $line) {
        if (($line['data']['reference_key'] ?? '') === 'analytic-csv-1') {
            $reimported = $line['data'];
        }
    }
    $check($reimported !== null
        && $reimported['classificazione'] === '33.129'
        && $reimported['lingua'] === 'dan'
        && $reimported['risorsa_testo'] === $online['risorsa_testo'],
        'and every analytic field survives the round trip');

    // A 1.6-era file has none of these columns. Importing it onto an existing
    // key must UPDATE the record, not blank the analytic fields it never knew
    // about — otherwise one legacy CSV silently erases a cataloguer's work.
    $legacyCsv = "record_type,reference_key,titolo\njournal_article,analytic-csv-1,Titolo aggiornato\n";
    $legacyPreview = $csv->preview($legacyCsv);
    $check(array_filter(array_column($legacyPreview, 'error')) === [], 'a 1.6-era CSV still imports');
    $merged = $legacyPreview[0]['data'] ?? [];
    $check(($merged['classificazione'] ?? null) === '33.129' && ($merged['lingua'] ?? null) === 'dan',
        'and it preserves the analytic fields it does not carry');

    // The English header names a non-Italian cataloguer would reach for.
    $aliasCsv = "record_type,reference_key,title,subtitle,language,country,classification_scheme,classification,holdings_note\n"
        . "journal_article,analytic-alias-1,Alias test,Un sottotitolo,eng,GB,LCC,PT2621,Copy only\n";
    $aliasPreview = $csv->preview($aliasCsv);
    $check(array_filter(array_column($aliasPreview, 'error')) === [], 'the English column names are accepted');
    $aliased = $aliasPreview[0]['data'] ?? [];
    $check(($aliased['sottotitolo'] ?? null) === 'Un sottotitolo'
        && ($aliased['lingua'] ?? null) === 'eng'
        && ($aliased['paese'] ?? null) === 'GB'
        && ($aliased['classificazione_schema'] ?? null) === 'LCC'
        && ($aliased['nota_possesso'] ?? null) === 'Copy only',
        'and map onto the Italian column names');
    $db->rollback();
    // -----------------------------------------------------------------------
    echo "\nJ. What the reader actually sees\n";

    // Render the REAL view. The 856 rule lives in the markup, so reading the
    // resolver's return value would prove nothing about the page.
    $root = dirname(__DIR__);
    $render = static function (array $row) use ($root): string {
        $article = $row;
        ob_start();
        require $root . '/storage/plugins/emeroteca/src/Views/public/article.php';

        return (string) ob_get_clean();
    };
    $base = UWE + ['id' => 4242,'pubblico' => 1,'abstract' => 'Un riassunto.'];

    $html = $render($base);
    $check(str_contains($html, htmlspecialchars(UWE['sottotitolo'], ENT_QUOTES, 'UTF-8')),
        'the subtitle is on the page');
    $check(str_contains($html, '33.129') && str_contains($html, 'DK5'),
        'the classification is shown WITH its scheme, so the notation means something');
    $check(str_contains($html, 'Cita questo articolo') || str_contains($html, 'Cite this article'),
        'the citation section is there');
    $check(str_contains($html, 'Petersen, H. U. (1988).'), 'with the APA citation ready to copy');
    $check(str_contains($html, '/citazione.ris'), 'and a link to the RIS download');
    $check(!str_contains($html, 'onclick='),
        'the copy button carries no inline handler: the text travels in the DOM, not in an attribute');

    // The language is rendered in the READER's language, never as the stored
    // code — that is the whole reason the column holds `dan` and not `Dansk`.
    if (class_exists(\Locale::class)) {
        $expectedLanguage = \Locale::getDisplayLanguage('dan', \App\Support\I18n::getLocale());
        $check($expectedLanguage !== '' && str_contains($html, htmlspecialchars($expectedLanguage, ENT_QUOTES, 'UTF-8')),
            "the ISO code is rendered as a name the reader understands ({$expectedLanguage})");
    }

    $ld = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m) === 1
        ? json_decode($m[1], true)
        : null;
    $check(is_array($ld) && ($ld['alternativeHeadline'] ?? null) === UWE['sottotitolo'],
        'structured data declares the subtitle');
    $check(is_array($ld) && ($ld['inLanguage'] ?? null) === 'dan', 'and the language code');
    $check(is_array($ld) && ($ld['datePublished'] ?? null) === '1988', 'and a bare year, which is valid ISO 8601');
    $check(is_array($ld) && ($ld['pageStart'] ?? null) === '18' && ($ld['pageEnd'] ?? null) === '38',
        'and the page ends, taken from the same decomposition the citation uses');
    $check(is_array($ld) && ($ld['isPartOf']['@type'] ?? null) === 'PublicationIssue',
        'and the article is part of an ISSUE, not loosely of the periodical');
    $check(is_array($ld) && !array_key_exists('countryOfOrigin', $ld),
        'and it does NOT claim a country of origin: 008 records the HOST publication’s country, not the work’s');

    // array_replace, not +: the union operator keeps the LEFT operand's key,
    // so `$base + [...]` would silently render Uwe's single author again.
    $twoAuthors = $render(array_replace($base, ['autori' => 'Rossi, Mario; Bianchi, Anna']));
    $ld2 = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $twoAuthors, $m) === 1
        ? json_decode($m[1], true)
        : null;
    $check(is_array($ld2) && is_array($ld2['author'] ?? null) && count($ld2['author']) === 2,
        'two credited names are two Persons, not one string that matches nobody');

    // ---- the 856 rule, which is the part that can go wrong silently --------
    $pdf = ['pdf_path' => str_repeat('a', 40) . '.pdf','pdf_pubblico' => 1];
    $link = ['risorsa_url' => 'https://arkiv.example/1988-31.pdf','risorsa_testo' => 'Download the article as a PDF','risorsa_accesso' => 'For internal use only','risorsa_pubblica' => 1];

    $both = $render($base + $pdf + $link);
    $check(substr_count($both, 'class="btn-primary') === 1,
        'with a PDF and an external link there is exactly ONE primary action');
    $check(preg_match('#class="btn-primary[^"]*" href="[^"]*/pdf"#', $both) === 1,
        'and it is the library’s own copy, the only thing guaranteed to resolve');
    $check(str_contains($both, 'arkiv.example'), 'the external link is still offered, as a secondary one');
    $check(str_contains($both, 'For internal use only'), 'with its access conditions beside it');

    $linkOnly = $render($base + $link);
    $check(substr_count($linkOnly, 'class="btn-primary') === 1
        && preg_match('#class="btn-primary[^"]*" href="https://arkiv\.example[^"]*"#', $linkOnly) === 1,
        'with no PDF the external link becomes the primary action');
    $check(str_contains($linkOnly, 'rel="noopener nofollow"'),
        'and leaves the referrer and the ranking behind');

    $hidden = $render($base + ['risorsa_url' => 'https://segreto.example/x.pdf','risorsa_pubblica' => 0]);
    $check(!str_contains($hidden, 'segreto.example'),
        'an unpublished resource does not appear in the HTML at all, not even hidden');

    $opaque = $render($base + ['risorsa_url' => '\\\\archivio\\scansioni\\1988-31.pdf','risorsa_pubblica' => 1]);
    $check(str_contains($opaque, '<code'), 'a local path is shown as what it is');
    $check(preg_match('#href="[^"]*archivio[^"]*"#', $opaque) !== 1,
        'and is NEVER wrapped in an anchor a browser cannot follow');
    $check(substr_count($opaque, 'class="btn-primary') === 0,
        'nor promoted to the primary action');

    $bare = $render(['id' => 1,'titolo' => 'Solo un titolo','pubblico' => 1]);
    $check(str_contains($bare, 'Solo un titolo'),
        'a record with nothing but a title still renders — every analytic field is optional');
    $check(!str_contains($bare, 'DK5') && substr_count($bare, 'class="btn-primary') === 0,
        'and shows none of the analytic apparatus it does not have');

    // ── K. The number beside a link is the number of results that link opens ──
    //
    // The catalogue search offers "Articoli nell'emeroteca (N)" pointing at
    // /emeroteca/articoli?q=. Two different queries produce N and that page, so
    // making one of them search a new column and not the other is how a counter
    // starts lying. Here the term exists ONLY in a subtitle: before the counter
    // learned about sottotitolo there was no suggestion at all while the linked
    // page listed the article.
    echo "\nK. The suggestion counter agrees with the page it links to\n";

    $db->query("DELETE FROM emeroteca_contributi");
    $onlyInSubtitle = 'Zwischenkriegszeit';
    $svc->save([
        'titolo' => 'A title that does not contain the term',
        'sottotitolo' => 'eine Studie zur ' . $onlyInSubtitle,
        'autori' => 'Petersen, Hans Uwe',
        'contenitore_titolo' => 'Arbejderhistorie',
        'pubblico' => 1,
    ]);
    $svc->save([
        'titolo' => 'Another record entirely',
        'autori' => 'Rossi, Mario',
        'pubblico' => 1,
    ]);

    // $public = true: the counter only ever counts published articles, so the
    // page has to be asked the same way or the two disagree for a second reason.
    $pageTotal = (int) $svc->search($onlyInSubtitle, 0, true)['total'];
    $check($pageTotal === 1, "the linked page finds the article by its subtitle alone (total {$pageTotal})");

    $suggested = $plugin->suggestEmerotecaSearch([], $onlyInSubtitle);
    $articleSuggestion = null;
    foreach ((array) $suggested as $entry) {
        if (is_array($entry) && isset($entry['total'], $entry['url'])
            && str_contains((string) $entry['url'], '/emeroteca/articoli')) {
            $articleSuggestion = $entry;
        }
    }
    $check($articleSuggestion !== null, 'and the catalogue offers a suggestion for it at all');
    $check($articleSuggestion !== null && (int) $articleSuggestion['total'] === $pageTotal,
        'with the same total the page returns (counter '
        . ($articleSuggestion === null ? 'absent' : (string) $articleSuggestion['total'])
        . " vs page {$pageTotal})");

    // ── L. The URLs a reference manager follows unattended ────────────────────
    //
    // serveRis() writes UR and L1 into the downloaded file through absoluteUrl()
    // rather than from $request->getUri()->getAuthority(). A reference manager
    // fetches those without asking, so a poisoned Host header would send it
    // somewhere the library never published. These checks pin the property that
    // decision rests on, in the three configurations an installation can be in —
    // otherwise "absoluteUrl() is safer" is an assumption, not a guarantee.
    echo "\nL. absoluteUrl() under a poisoned Host header\n";

    require_once dirname(__DIR__).'/app/helpers.php';
    $envKeys = ['APP_CANONICAL_URL', 'APP_TRUSTED_HOSTS'];
    $savedEnv = [];
    foreach ($envKeys as $k) {
        $savedEnv[$k] = $_ENV[$k] ?? null;
    }
    $savedHost = $_SERVER['HTTP_HOST'] ?? null;
    $savedPort = $_SERVER['SERVER_PORT'] ?? null;

    /** @param array<string,string> $env */
    $origin = static function (array $env, string $host) use ($envKeys): string {
        foreach ($envKeys as $k) {
            unset($_ENV[$k]);
            putenv($k);
        }
        foreach ($env as $k => $v) {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_PORT'] = '80';

        return \App\Support\HtmlHelper::absoluteUrl('/emeroteca/articolo/7');
    };

    $twoHosts = ['APP_TRUSTED_HOSTS' => 'biblio.example,catalogo.example'];

    // The property that makes this safer than reading the Host directly.
    $check($origin($twoHosts, 'evil.example') === 'http://biblio.example/emeroteca/articolo/7',
        'a Host outside the whitelist is clamped to the first entry, not echoed');

    // And the property the previous comment was afraid of losing: an install
    // legitimately reached on a second hostname still exports that hostname.
    $check($origin($twoHosts, 'catalogo.example') === 'http://catalogo.example/emeroteca/articolo/7',
        'while a Host that IS whitelisted is honoured — multi-hostname survives');

    $check($origin(['APP_CANONICAL_URL' => 'https://biblio.example'], 'evil.example')
            === 'https://biblio.example/emeroteca/articolo/7',
        'APP_CANONICAL_URL wins outright, whatever the Host says');

    // Stated rather than asserted as a virtue: with NEITHER variable set — the
    // default small-library install — the helper echoes the request Host, so
    // this change is behaviour-preserving there, not protective. The guarantee
    // arrives with APP_TRUSTED_HOSTS.
    $check($origin([], 'evil.example') === 'http://evil.example/emeroteca/articolo/7',
        'with no host configuration at all the helper still echoes the request Host');

    foreach ($envKeys as $k) {
        unset($_ENV[$k]);
        putenv($k);
        if ($savedEnv[$k] !== null) {
            $_ENV[$k] = $savedEnv[$k];
            putenv("{$k}={$savedEnv[$k]}");
        }
    }
    if ($savedHost !== null) {
        $_SERVER['HTTP_HOST'] = $savedHost;
    }
    if ($savedPort !== null) {
        $_SERVER['SERVER_PORT'] = $savedPort;
    }

} finally {
    foreach (array_reverse($db->tables) as $table) {
        @$db->real_query("DROP TABLE IF EXISTS {$db->prefix}{$table}");
    }
}

echo "\n".($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of ".($pass + $fail)." checks failed\n");

exit($fail === 0 ? 0 : 1);
