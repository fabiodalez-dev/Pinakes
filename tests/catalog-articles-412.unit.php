<?php
declare(strict_types=1);
/** Mixed catalogue regression tests, isolated tables; no catalogue records changed. */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/ContributionService.php';
require dirname(__DIR__) . '/storage/plugins/openurl-resolver/OpenUrlResolverPlugin.php';

use App\Services\UnifiedCatalogService;
use App\Plugins\OpenUrlResolver\OpenUrlResolverPlugin;
use App\Plugins\Emeroteca\Services\ContributionService;

final class MixedCatalogDb extends mysqli
{
    public string $prefix;
    public array $tables = ['plugins', 'libri', 'autori', 'editori', 'generi', 'emeroteca_testate', 'emeroteca_contributi', 'emeroteca_contributi_autori'];
    private function mapped(string $sql): string
    {
        foreach ($this->tables as $table) {
            if ($table === 'autori') {
                $sql = preg_replace('/\b(FROM|JOIN|INTO|UPDATE|TABLE(?: IF (?:NOT )?EXISTS)?|REFERENCES|ON)\s+(`?)autori\b/i', '$1 $2'.$this->prefix.'autori', $sql);
                $sql = str_replace("'autori'", "'".$this->prefix."autori'", $sql);
            } else { $sql = preg_replace('/\b' . $table . '\b/', $this->prefix . $table, $sql); }
        }
        return str_replace('fk_contributo_autori_record', $this->prefix.'fk_contributo_autori_record', $sql);
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    { return parent::query($this->mapped($query), $result_mode); }
    public function prepare(string $query): mysqli_stmt|false
    { return parent::prepare($this->mapped($query)); }
}
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new MixedCatalogDb($env['DB_HOST'] ?? 'localhost', getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? $env['DB_PASSWORD'] ?? ''), getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int)($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->prefix = 'zzmixed_' . bin2hex(random_bytes(4)) . '_';
$db->set_charset('utf8mb4');
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++; echo "OK $label\n";
}
try {
    $schemas = [
        'plugins' => 'name VARCHAR(100), is_active INT',
        'libri' => 'id INT PRIMARY KEY, titolo VARCHAR(500), created_at DATETIME, anno_pubblicazione INT NULL, test_author VARCHAR(255), editore_id INT NULL, genere_id INT NULL, deleted_at DATETIME NULL',
        'autori' => 'id INT PRIMARY KEY, nome VARCHAR(255), pseudonimo VARCHAR(255)',
        'editori' => 'id INT PRIMARY KEY, nome VARCHAR(255)',
        'generi' => 'id INT PRIMARY KEY, nome VARCHAR(255)',
        'emeroteca_testate' => 'id INT PRIMARY KEY, logo_url VARCHAR(500)',
        'emeroteca_contributi' => implode(', ', array_map(static fn($key, $definition) => "$key $definition", array_keys(ContributionService::COLUMN_DEFINITIONS), ContributionService::COLUMN_DEFINITIONS)),
    ];
    foreach ($schemas as $table => $columns) {
        $db->query("CREATE TABLE $table ($columns) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $db->query(ContributionService::authorsDdl());
    $db->query("INSERT INTO plugins VALUES ('emeroteca',1)");
    $db->query("INSERT INTO autori VALUES (1,'Hans Uwe Petersen',NULL),(2,'',NULL)");
    $db->query("INSERT INTO libri(id,titolo,created_at,test_author,editore_id,genere_id) VALUES (1,'Probe 00 Book','2020-01-01','Petersen',NULL,NULL), (2,'Probe 99 Book','2020-01-02',NULL,NULL,NULL)");
    for ($i = 1; $i <= 15; $i++) {
        $title = sprintf('Probe %02d Article', $i);
        $db->query("INSERT INTO emeroteca_contributi (reference_key,id,titolo,autori,sottotitolo,contenitore_titolo,anno_pubblicazione,pubblico,created_at) VALUES ('probe-$i',$i,'$title','Petersen, Hans Uwe; Sørensen, Åse Bjørk','subtitle','Arbejderhistorie',1988,1,'2021-01-01')");
    }
    $db->query("INSERT INTO emeroteca_contributi (reference_key,titolo,autori,pubblico) VALUES ('hidden','Probe Hidden','Petersen, Hans Uwe',0),('unrelated','Unrelated','Petersen, Hans Uwe Junior',1)");
    $db->query("INSERT INTO emeroteca_contributi_autori(contributo_id,ordine_credito,autore_id,nome_credito,ruolo) SELECT id,0,1,'Hans Uwe Petersen','principale' FROM emeroteca_contributi WHERE id BETWEEN 1 AND 15");
    $service = new UnifiedCatalogService($db);
    $from = 'FROM libri l WHERE l.titolo LIKE ?';
    $authors = 'l.test_author autore, l.test_author autore_principale_nome, l.test_author autore_cognome';
    $page = static fn(array $filters, int $offset=0) => $service->page($from, $authors, 's', ['Probe%'], $filters, 2, 12, $offset);
    $first = $page(['search'=>'Probe', 'sort'=>'title_asc']);
    $second = $page(['search'=>'Probe', 'sort'=>'title_asc'],12);
    check($first['total']===17 && $first['articles']===15, 'combined total excludes private article');
    check(count($first['rows'])===12 && count($second['rows'])===5, 'one global page boundary');
    $all = array_merge($first['rows'],$second['rows']);
    check(count(array_unique(array_map(fn($r)=>$r['_record_kind'].':'.$r['id'],$all)))===17, 'no collisions, duplicates or omissions across pages');
    check($all[0]['titolo']==='Probe 00 Book' && $all[16]['titolo']==='Probe 99 Book', 'books and articles share title ordering');
    check($page(['search'=>'Probe','sort'=>'title_desc'])['rows'][0]['titolo']==='Probe 99 Book', 'descending title order');
    check($page(['search'=>'Probe','sort'=>'oldest'])['rows'][0]['_record_kind']==='book', 'chronological order across corpora');
    check($page(['search'=>'Probe','sort'=>'newest'])['rows'][0]['_record_kind']==='article', 'newest order across corpora');
    check($page(['search'=>'Probe','sort'=>'author_desc'],12)['rows'][4]['id']===2, 'missing author sorts last');
    check($page(['search'=>'Hans Uwe Petersen'])['articles']===16, 'natural-order general author query');
    check($page(['autore'=>'Petersen, Hans Uwe'])['articles']===15, 'author filter matches whole semicolon-delimited credit, not Junior');
    check($page(['autore_id'=>1])['articles']===15, 'shared author identity finds confirmed article credits');
    check($page(['autore_id'=>987654])===null, 'nonexistent authority finds no articles');
    check($page(['autore_id'=>2])===null, 'empty authority name cannot match all articles');
    check($page(['search'=>'Probe 01 Article : subtitle'])['articles']===1, 'complete title and subtitle query');
    check($page(['search'=>'%'])===null, 'literal percent is not a wildcard');
    check($page(['search'=>'Probe','anno_min'=>2000])===null, 'year filter applies to articles');
    foreach (['genere_id'=>1,'editore'=>'Publisher','disponibilita'=>'disponibile','tipo_media'=>'libro','_books_only'=>true] as $key=>$value) {
        check($page([$key=>$value])===null, "$key does not leak unfiltered articles");
    }
    $db->query("UPDATE libri SET anno_pubblicazione=2020 WHERE id=2");
    $chronology = array_merge($page(['search'=>'Probe','sort'=>'publication_desc'])['rows'], $page(['search'=>'Probe','sort'=>'publication_desc'],12)['rows']);
    check($chronology[0]['titolo']==='Probe 99 Book' && $chronology[16]['titolo']==='Probe 00 Book', 'publication order spans pages with undated records last');
    check($chronology[1]['titolo']==='Probe 01 Article' && $chronology[15]['titolo']==='Probe 15 Article', 'same-year records use title ordering');
    check($page(['search'=>str_repeat('Probe ',20).'unmatched'])['articles']===15, 'long search bounds article predicates to twenty words');
    $resolver = (new ReflectionClass(OpenUrlResolverPlugin::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($resolver,'db'))->setValue($resolver,$db);
    $find = new ReflectionMethod($resolver,'findArticle');
    check((int)$find->invoke($resolver,['rft.atitle'=>'Probe 01 Article : subtitle'])['id']===1, 'OpenURL round trip includes subtitle');
    check((int)$find->invoke($resolver,['rft_atitle'=>'Probe 01 Article'])['id']===1, 'OpenURL bare title remains supported');
    check($find->invoke($resolver,['rft.atitle'=>'Probe Hidden'])===null, 'OpenURL refuses private article');
    check($find->invoke($resolver,['rft.atitle'=>'Probe 01 Article','rft.jtitle'=>'Different journal'])===null, 'OpenURL does not resolve wrong host');
    $db->query("INSERT INTO emeroteca_contributi(reference_key,titolo,sottotitolo,contenitore_titolo,pubblico) VALUES ('duplicate','Probe 01 Article','subtitle','Other host',1)");
    check($find->invoke($resolver,['rft.atitle'=>'Probe 01 Article : subtitle'])===null, 'ambiguous title cannot resolve arbitrary record');
    check((int)$find->invoke($resolver,['rft.atitle'=>'Probe 01 Article : subtitle','rft.jtitle'=>'Arbejderhistorie'])['id']===1, 'host disambiguates common title');
    $db->query("UPDATE emeroteca_contributi SET doi='10.1000/probe.2' WHERE id=2");
    check((int)$find->invoke($resolver,['rft_id'=>'info:doi/10.1000/PROBE.2'])['id']===2, 'unique DOI resolves its article');
    $db->query("UPDATE emeroteca_contributi SET doi='10.1000/probe.2' WHERE id=3");
    check($find->invoke($resolver,['rft_id'=>'info:doi/10.1000/probe.2'])===null, 'duplicate DOI is ambiguous, not an arbitrary record');
    // A chapter in an anthology (#412) is a book item: its COinS lets Zotero
    // import a book section, and an incoming book-item request finds it.
    $db->query("UPDATE emeroteca_contributi SET contenitore_tipo='antologia', contenitore_titolo='Exil in Dänemark', isbn='9780306406157', contenitore_editore='Museum Tusculanum', contenitore_luogo='København' WHERE id=4");
    (new ReflectionMethod($resolver,'loadCitationFormatter'))->invoke($resolver);
    $kev = (new ReflectionMethod($resolver,'buildArticleKev'))->invoke($resolver, $db->query('SELECT * FROM emeroteca_contributi WHERE id=4')->fetch_assoc(),
        (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET','https://biblioteca.example/emeroteca/articolo/4/coins'));
    // parse_str() would turn every "rft.x" key into "rft_x": split by hand.
    $kevParams = [];
    foreach (explode('&', $kev) as $pair) { [$k, $v] = array_pad(explode('=', $pair, 2), 2, ''); $kevParams[rawurldecode($k)] = rawurldecode($v); }
    check(($kevParams['rft_val_fmt'] ?? '')==='info:ofi/fmt:kev:mtx:book' && ($kevParams['rft.genre'] ?? '')==='bookitem', 'a chapter COinS is a book item');
    check(($kevParams['rft.btitle'] ?? '')==='Exil in Dänemark' && ($kevParams['rft.isbn'] ?? '')==='9780306406157' && !isset($kevParams['rft.jtitle']), 'with the volume as btitle and its ISBN, not a journal title');
    check((int)$find->invoke($resolver,['rft.atitle'=>'Probe 04 Article : subtitle','rft.btitle'=>'Exil in Dänemark'])['id']===4, 'a book-item request with the volume title finds the chapter');
    check($find->invoke($resolver,['rft.atitle'=>'Probe 04 Article : subtitle','rft.btitle'=>'Another volume'])===null, 'and a different volume does not');
    $db->query("UPDATE plugins SET is_active=0");
    check($page(['search'=>'Probe'])===null, 'disabled plugin is absent from catalogue');
    check($find->invoke($resolver,['rft.atitle'=>'Probe 01 Article'])===null, 'disabled plugin is absent from resolver');
    echo "SUCCESS $checks behavioural checks\n";
} finally {
    foreach (array_reverse($db->tables) as $table) { $db->query("DROP TABLE IF EXISTS $table"); }
    $db->close();
}
