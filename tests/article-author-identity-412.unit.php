<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/EmerotecaPlugin.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Support/ArticleMarcXml.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Services/ContributionCsv.php';
use App\Models\AuthorRepository;
use App\Plugins\Emeroteca\Services\ContributionService;
use App\Plugins\Emeroteca\Support\ArticleMarcXml;
use App\Services\UnifiedCatalogService;
use App\Services\ArticleAuthorService;
use App\Support\HookManager;
final class IdentitySandboxDb extends mysqli
{
    public string $prefix = "";
    public array $tables=['emeroteca_contributi_autori','emeroteca_contributi','emeroteca_abbonamenti','emeroteca_articoli','emeroteca_fascicoli','emeroteca_annate','emeroteca_testate','autori','libri','libri_autori','editori','generi','mensole','plugins','plugin_settings','plugin_hooks','author_authority_alternates','libri_autori_import_sources','autori_authority_link'];
    public function mapped(string $sql):string {
        foreach($this->tables as $table) {
            if ($table === 'autori') {
                // `autori` is also a credit column: only retarget table references.
                $sql=preg_replace('/\b(FROM|JOIN|INTO|UPDATE|TABLE(?: IF (?:NOT )?EXISTS)?|REFERENCES|ON)\s+(`?)autori\b/i','$1 $2'.$this->prefix.'autori',$sql);
                $sql=str_replace("'autori'", "'".$this->prefix."autori'", $sql);
            } else { $sql=preg_replace('/\b'.$table.'\b/',$this->prefix.$table,$sql); }
        }
        return preg_replace('/\\b(fk_emeroteca_\\w+|fk_contributo_\\w+)\\b/',$this->prefix.'$1',$sql);
    }
    public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT):mysqli_result|bool { return parent::query($this->mapped($query),$result_mode); }
    public function raw(string $query):mysqli_result|bool { return parent::query($query); }
    public function prepare(string $query):mysqli_stmt|false { return new IdentitySandboxStmt($this,$this->mapped($query)); }
}
final class IdentitySandboxStmt extends mysqli_stmt
{
    private array $values=[];
    public function __construct(private IdentitySandboxDb $sandbox,private string $query) {parent::__construct($sandbox,$query);}
    public function bind_param(string $types,mixed &...$vars):bool {
        $this->values=[];
        preg_match_all('/(?:REFERENCED_)?TABLE_NAME\s*=\s*\?/i',$this->query,$matches,PREG_OFFSET_CAPTURE);
        $tableParameters=[];
        foreach($matches[0] as [$match,$offset]) {$tableParameters[]=substr_count(substr($this->query,0,$offset),'?');}
        foreach($vars as $i=>$v) {$this->values[$i]=in_array($i,$tableParameters,true)&&is_string($v)&&in_array($v,$this->sandbox->tables,true)?$this->sandbox->prefix.$v:$v;}
        return parent::bind_param($types,...$this->values);
    }
}
$env=Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env'));
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new IdentitySandboxDb($env['DB_HOST']??'localhost',getenv('E2E_DB_USER')?:$env['DB_USER'],getenv('E2E_DB_PASS')?:($env['DB_PASS']??$env['DB_PASSWORD']??''),getenv('E2E_DB_NAME')?:$env['DB_NAME'],(int)($env['DB_PORT']??3306),getenv('E2E_DB_SOCKET')?:($env['DB_SOCKET']??null));
$db->set_charset('utf8mb4');
$source=$db->query('SELECT DATABASE()')->fetch_row()[0];
$db->prefix='zz_identity412_'.bin2hex(random_bytes(4)).'_';
$count=0;
function verifyIdentity(bool $ok,string $label):void { global $count; if(!$ok)throw new RuntimeException($label);$count++;echo "OK $label\n"; }
function rejectsIdentity(callable $call,string $label):void { try{$call();}catch(Throwable){verifyIdentity(true,$label);return;} throw new RuntimeException($label); }
try {
    foreach(['autori','libri','libri_autori','editori','generi','mensole','plugins','plugin_settings','plugin_hooks','author_authority_alternates','libri_autori_import_sources'] as $table) {
        $db->raw("CREATE TABLE `{$db->prefix}$table` LIKE `$table`");
    }
    // Exercise the real additive migration twice on a clone of the existing author schema.
    $sql=preg_replace('/^--.*$/m','',file_get_contents(dirname(__DIR__).'/installer/database/migrations/migrate_0.7.88.sql'));
    for($i=0;$i<2;$i++) {
        foreach(explode(';',$sql) as $statement){if(trim($statement)!=='')$db->query($statement);}
    }
    $plugin=new EmerotecaPlugin($db,new HookManager($db));
    verifyIdentity($plugin->ensureSchema()['failed']===[],'new author relation upgrades through real plugin lifecycle');
    verifyIdentity($plugin->ensureSchema()['failed']===[],'schema upgrade is idempotent');
    $db->query("INSERT INTO plugins(name,display_name,version,is_active,path,main_file) VALUES ('emeroteca','Emeroteca','1.8.0',1,'emeroteca','wrapper.php')");
    $authors=new AuthorRepository($db); $service=new ContributionService($db); $catalog=new UnifiedCatalogService($db);
    $person=$authors->create(['nome'=>'Hans Uwe Petersen','gnd_id'=>'https://d-nb.info/gnd/118559792']);
    $homonym=$authors->create(['nome'=>'Hans Uwe Petersen']);
    $coauthor=$authors->create(['nome'=>'Åse Sørensen']);
    $base=['titolo'=>'Probe article','contenitore_titolo'=>'Arbejderhistorie','pubblico'=>1];
    $credit=static fn(int $id,string $role='principale')=>['autore_id'=>(string)$id,'nome_credito'=>'ignored submitted spelling','ruolo'=>$role];
    $id=$service->save($base+['credits_present'=>1,'credits'=>[$credit($person),$credit($coauthor,'co-autore')]]);
    $db->query("INSERT INTO libri(titolo) VALUES ('Book sharing article author')");
    $bookId=(int)$db->insert_id;
    $db->query("INSERT INTO libri_autori(libro_id,autore_id,ruolo,ordine_credito) VALUES ($bookId,$person,'principale',0)");
    verifyIdentity(count($authors->getBooksByAuthorId($person))===1,'book and article refer to the same core person');
    $row=$service->get($id);
    verifyIdentity(array_column($row['author_credits'],'autore_id')===[$person,$coauthor],'same shared IDs and coauthor order persist');
    verifyIdentity($row['autori']==='Petersen, Hans Uwe; Sørensen, Åse','linked credits are cited in surname-first form from the shared registry');
verifyIdentity(array_column($row['author_credits'],'display_name')===['Hans Uwe Petersen','Åse Sørensen'],'display name travels separately for link text');
verifyIdentity(str_starts_with(\App\Plugins\Emeroteca\Support\CitationFormatter::apa($row),'Petersen, H. U.'),'linking an author keeps APA initials instead of a corporate-style name');
$linkedXml=new DOMDocument();$linkedXml->loadXML(ArticleMarcXml::format($row));$linkedPath=new DOMXPath($linkedXml);$linkedPath->registerNamespace('m','http://www.loc.gov/MARC21/slim');
verifyIdentity($linkedPath->evaluate('string(//m:datafield[@tag="100"]/@ind1)')==='1' && $linkedPath->evaluate('string(//m:datafield[@tag="100"]/m:subfield[@code="a"])')==='Petersen, Hans Uwe','linked author exports 100 ind1 1 in surname-first form');
$staleExport=(new \App\Plugins\Emeroteca\Services\ContributionCsv($service))->export();
$referenceKey=(string)$row['reference_key'];
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===1 && $catalog->countArticles(['autore_id'=>$homonym])===0,'ID filter distinguishes homonyms');
    $legacy=$service->save(['titolo'=>'Legacy article','autori'=>'Petersen, Hans Uwe','pubblico'=>1]);
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===1,'legacy text is never silently assigned an identity');
    verifyIdentity($catalog->countArticles(['autore'=>'Petersen, Hans Uwe'])===2,'legacy credits remain discoverable by name');
    $authors->update($person,['nome'=>'Hans Uwe Renamed','pseudonimo'=>'Uwe Archivist','gnd_id'=>'118559792']);
    $row=$service->get($id);
    verifyIdentity(str_starts_with($row['autori'],'Archivist, Uwe;') && !str_contains($row['autori'],'('),'rename and pseudonym update the cited credit without the real-name suffix');
    verifyIdentity($row['author_credits'][0]['display_name']==='Uwe Archivist (Hans Uwe Renamed)','display form keeps the real name for readers');
    $pseudoXml=ArticleMarcXml::format($row);
    verifyIdentity(str_contains($pseudoXml,'<subfield code="a">Archivist, Uwe</subfield>') && !str_contains($pseudoXml,'(Hans Uwe Renamed)'),'pseudonymous author exports the pseudonym heading without the parenthetical');
    // A partial import, or an export taken before the rename, carries a credit
    // that is either the stored text or the current one: identities survive.
    $staleCsv=new \App\Plugins\Emeroteca\Services\ContributionCsv($service);
    $staleReport=$staleCsv->commit($staleCsv->preview($staleExport));
    verifyIdentity(count(array_filter($staleReport,static fn($r)=>$r['error']!==null))===0 && array_column($service->get($id)['author_credits'],'autore_id')===[$person,$coauthor],'re-importing an export taken before a rename keeps linked identities');
    $partialCsv=new \App\Plugins\Emeroteca\Services\ContributionCsv($service);
    $partialReport=$partialCsv->commit($partialCsv->preview("record_type,reference_key,titolo,pubblico\narticle,$referenceKey,Probe article,1\n"));
    verifyIdentity(count(array_filter($partialReport,static fn($r)=>$r['error']!==null))===0 && array_column($service->get($id)['author_credits'],'autore_id')===[$person,$coauthor],'a partial CSV without an autori column keeps linked identities after a rename');
    $row=$service->get($id);
    verifyIdentity($catalog->countArticles(['search'=>'Renamed'])===1,'general search finds current shared name');
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===1,'identity search survives a rename');
    verifyIdentity($service->search('Hans Uwe Renamed')['total']===1,'article search uses renamed shared author');
    verifyIdentity($service->search('',0,false,1,['autore'=>'Uwe Archivist'])['total']===1,'article author filter uses current pseudonym');
    $service->save(['titolo'=>'Sort comparison','autori'=>'Nolan','pubblico'=>1]);
    $sorted=$catalog->page('FROM libri l WHERE l.deleted_at IS NULL AND 1=0','CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore, CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore_principale_nome, CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore_cognome','',[],['sort'=>'author_asc'],0,20,0);
    verifyIdentity((int)$sorted['rows'][0]['id']===$id,'author sort uses current pseudonym rather than stale article text');
    // F027: a linked article sorts like the author's books — principal author first, last word of the preferred name —
    // even when a co-author is credited before the principal author.
    $coFirst=$authors->create(['nome'=>'Anna Zulu']); $principal=$authors->create(['nome'=>'Bruno Aaberg']);
    $sortArticle=$service->save(['titolo'=>'Sort principal first','pubblico'=>1,'credits_present'=>1,'credits'=>[
        ['autore_id'=>(string)$coFirst,'nome_credito'=>'Zulu, Anna','ruolo'=>'co-autore'],
        ['autore_id'=>(string)$principal,'nome_credito'=>'Aaberg, Bruno','ruolo'=>'principale']]]);
    $sortedAll=$catalog->page('FROM libri l WHERE l.deleted_at IS NULL AND 1=0','CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore, CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore_principale_nome, CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore_cognome','',[],['sort'=>'author_asc'],0,50,0);
    verifyIdentity((int)$sortedAll['rows'][0]['id']===$sortArticle,'linked article sorts by its principal author like a book, not by the first-credited co-author');
    $suggestion=$plugin->suggestEmerotecaSearch([],'Renamed');
    verifyIdentity(count($suggestion)===1 && $suggestion[0]['total']===1 && str_contains($suggestion[0]['items'][0]['meta'],'Archivist, Uwe'),'catalogue suggestions find and display the current shared author');
    $csv=(new \App\Plugins\Emeroteca\Services\ContributionCsv($service))->export();
    verifyIdentity(str_contains($csv,'Archivist, Uwe') && !str_contains($csv,'(Hans Uwe Renamed)'),'CSV exports current author names in citation form');
    $csvService=new \App\Plugins\Emeroteca\Services\ContributionCsv($service);
    $report=$csvService->commit($csvService->preview($csv));
    verifyIdentity(count(array_filter($report,static fn($r)=>$r['error']!==null))===0 && array_column($service->get($id)['author_credits'],'autore_id')===[$person,$coauthor],'CSV round trip preserves explicitly linked identities after a rename');
    $xml=ArticleMarcXml::format($row);
    verifyIdentity(str_contains($xml,'<subfield code="0">https://d-nb.info/gnd/118559792</subfield>'),'MARC exports confirmed shared GND URI');
    $doc=new DOMDocument();$doc->loadXML($xml);$xp=new DOMXPath($doc);$xp->registerNamespace('m','http://www.loc.gov/MARC21/slim');
    verifyIdentity($xp->evaluate('string(//m:datafield[@tag="700"]/m:subfield[@code="a"])')==='Sørensen, Åse' && $xp->evaluate('string(//m:datafield[@tag="700"]/@ind1)')==='1','coauthor remains 700 in surname-first form');
    $db->query("UPDATE autori SET viaf_uri='https://viaf.org/viaf/123',authority_confidence='candidate' WHERE id=$coauthor");
    verifyIdentity(!str_contains(ArticleMarcXml::format($service->get($id)),'viaf.org'),'candidate authority is not exported as confirmed identity');
    $db->query("UPDATE autori SET authority_confidence='exact' WHERE id=$coauthor");
    verifyIdentity(str_contains(ArticleMarcXml::format($service->get($id)),'https://viaf.org/viaf/123'),'confirmed existing VIAF is shared with article');
    $db->query("UPDATE autori SET authority_source='manual',authority_confidence='rejected' WHERE id=$coauthor");
    verifyIdentity(!str_contains(ArticleMarcXml::format($service->get($id)),'viaf.org'),'rejected manual authority is never exported');
    $db->query("UPDATE autori SET authority_source='viaf',authority_confidence='exact',isni_uri='https://isni.org/isni/000000012146438X' WHERE id=$coauthor");
    $multi=new DOMDocument();$multi->loadXML(ArticleMarcXml::format($service->get($id)));$multiPath=new DOMXPath($multi);$multiPath->registerNamespace('m','http://www.loc.gov/MARC21/slim');
    verifyIdentity($multiPath->evaluate('count(//m:datafield[@tag="700"]/m:subfield[@code="0"])')===2.0,'MARC repeats authority subfield for shared VIAF and ISNI');
    $before=(int)$db->query('SELECT COUNT(*) n FROM autori')->fetch_assoc()['n'];
    $new=$service->save(['titolo'=>'New creator','credits_present'=>1,'credits'=>[['nome_credito'=>'Brand New Person','create'=>'1','ruolo'=>'principale']]]);
    $newPerson=$service->get($new)['author_credits'][0]['autore_id'];
    verifyIdentity($newPerson>0 && (int)$db->query('SELECT COUNT(*) n FROM autori')->fetch_assoc()['n']===$before+1,'explicit create makes one shared author');
    rejectsIdentity(fn()=>$service->save(['titolo'=>'Failure','credits_present'=>1,'credits'=>[['nome_credito'=>'Must Rollback','create'=>'1','ruolo'=>'principale'],$credit(999999)]]),'invalid selected ID rejects article');
    verifyIdentity((int)$db->query("SELECT COUNT(*) n FROM autori WHERE nome='Must Rollback'")->fetch_assoc()['n']===0,'failed article rolls back newly created author too');
    rejectsIdentity(fn()=>$service->save($base+['credits_present'=>1,'credits'=>[$credit($person),$credit($person,'co-autore')]]),'duplicate identity in article is rejected');
    $db->begin_transaction();
    $nested=$service->save(['titolo'=>'Caller rollback','credits_present'=>1,'credits'=>[$credit($person)]]);
    $db->rollback();
    verifyIdentity($service->get($nested)===null,'saving article does not commit caller transaction');
    $beforeRevision=(int)$service->get($id)['revision'];
    rejectsIdentity(fn()=>$service->save($base+['credits_present'=>1,'credits'=>[['nome_credito'=>'Stale orphan','create'=>'1','ruolo'=>'principale']]],$id,$beforeRevision-1),'stale edit is rejected');
    verifyIdentity((int)$db->query("SELECT COUNT(*) n FROM autori WHERE nome='Stale orphan'")->fetch_assoc()['n']===0,'stale save creates no orphan identity');
    $mergeArticle=$service->save(['titolo'=>'Merge both credits','pubblico'=>1,'credits_present'=>1,'credits'=>[$credit($person,'co-autore'),$credit($homonym)]]);
    verifyIdentity($authors->mergeAuthors([$person,$homonym],$person)===$person,'explicit core merge succeeds');
    verifyIdentity(count($service->get($mergeArticle)['author_credits'])===1,'merge collapses duplicate links on same article');
    verifyIdentity($service->get($mergeArticle)['author_credits'][0]['ruolo']==='principale','merge retains principal role from duplicate identity');
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===2,'merged identity keeps article discovery');
    verifyIdentity($authors->getById($person)['gnd_id']==='118559792','merge retains confirmed GND');
    $different=$authors->create(['nome'=>'Different Authority','gnd_id'=>'4079154-3']);
    verifyIdentity($authors->mergeAuthors([$person,$different],$person)===null,'different confirmed GNDs block accidental merge');
    verifyIdentity($authors->getById($different)!==null,'conflicting merge rolls back');
    $db->begin_transaction();
    $authors->delete($newPerson);
    $db->rollback();
    verifyIdentity($service->get($new)['author_credits'][0]['autore_id']===$newPerson && $authors->getById($newPerson)!==null,'author deletion preserves caller transaction and rolls back detached credits');
    $trigger=$db->prefix.'reject_delete';
    $db->query("CREATE TRIGGER $trigger BEFORE DELETE ON autori FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated delete failure'");
    try { rejectsIdentity(fn()=>$authors->delete($newPerson),'failed identity deletion is rejected'); }
    finally { $db->query("DROP TRIGGER $trigger"); }
    verifyIdentity($service->get($new)['author_credits'][0]['autore_id']===$newPerson,'failed deletion keeps article identity linked');
    $authors->update($newPerson,['nome'=>'Latest Name']);
    $authors->delete($newPerson);
    $detached=$service->get($new);
    verifyIdentity($detached['autori']==='Name, Latest' && $detached['author_credits'][0]['autore_id']===null,'deleting identity preserves latest credit without dangling ID');
    $legacyRow=$service->get($legacy);
    $service->save($legacyRow+['credits_present'=>1,'credits'=>[$credit($person)]],$legacy,(int)$legacyRow['revision']);
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===3,'legacy record can be explicitly linked without recreating article');
    $replacement=$service->save(['titolo'=>'Import replacement','credits_present'=>1,'credits'=>[$credit($person)]]);
    $replacementRow=$service->get($replacement);
    $service->save(array_replace($replacementRow,['autori'=>'Different imported credit']),$replacement,(int)$replacementRow['revision']);
    verifyIdentity($service->get($replacement)['author_credits']===[] && $service->get($replacement)['autori']==='Different imported credit','text import replacing authors clears stale identities');
    $typed=$service->save(['titolo'=>'Typed inverted','credits_present'=>1,'credits'=>[['nome_credito'=>'Kierkegaard, Søren','create'=>'1','ruolo'=>'principale']]]);
    $typedRow=$service->get($typed);
    verifyIdentity($typedRow['autori']==='Kierkegaard, Søren' && $db->query("SELECT autori FROM emeroteca_contributi WHERE id=$typed")->fetch_row()[0]==='Kierkegaard, Søren','created author keeps the typed inverted credit at rest');
    $single=$authors->create(['nome'=>'Plato']);
    $singleRow=$service->get($service->save(['titolo'=>'Single name','credits_present'=>1,'credits'=>[$credit($single)]]));
    $singleXml=new DOMDocument();$singleXml->loadXML(ArticleMarcXml::format($singleRow));$singlePath=new DOMXPath($singleXml);$singlePath->registerNamespace('m','http://www.loc.gov/MARC21/slim');
    verifyIdentity($singleRow['autori']==='Plato' && $singlePath->evaluate('string(//m:datafield[@tag="100"]/@ind1)')==='0','single-token name is never inverted and exports ind1 0');
    $target=$authors->create(['nome'=>'Primary without authority']);
    $sourceAuthor=$authors->create(['nome'=>'Duplicate with authority','gnd_id'=>'119030238']);
    verifyIdentity($authors->mergeAuthors([$target,$sourceAuthor],$target)===$target && $authors->getById($target)['gnd_id']==='119030238','GND transfers from merged duplicate to retained identity');
    // CodeRabbit #438: a lowercase DNB check digit is canonicalised, and a duplicate GND reaches the caller as MySQL 1062.
    $lowerGnd=$authors->create(['nome'=>'Lowercase authority','gnd_id'=>'118559792x']);
    verifyIdentity($authors->getById($lowerGnd)['gnd_id']==='118559792X','lowercase GND check digit is stored as X');
    try { $authors->create(['nome'=>'Second holder','gnd_id'=>'118559792X']); verifyIdentity(false,'duplicate GND is rejected'); }
    catch (mysqli_sql_exception $e) { verifyIdentity($e->getCode()===1062,'duplicate GND surfaces as MySQL 1062'); }
    // mergeAuthors joins an outer transaction instead of committing it implicitly.
    $outerA=$authors->create(['nome'=>'Outer merge keep']); $outerB=$authors->create(['nome'=>'Outer merge drop']);
    $db->begin_transaction();
    verifyIdentity($authors->mergeAuthors([$outerA,$outerB],$outerA)===$outerA,'merge succeeds inside a caller transaction');
    $db->rollback();
    verifyIdentity($authors->getById($outerB)!==null,'caller rollback undoes the merge (no implicit commit)');
    // Bulk delete detaches article credits exactly like the single delete.
    $bulkPerson=$authors->create(['nome'=>'Bulk Deleted']);
    $bulkArticle=$service->save(['titolo'=>'Bulk delete article','credits_present'=>1,'credits'=>[$credit($bulkPerson)]]);
    $authors->update($bulkPerson,['nome'=>'Bulk Renamed']);
    $bulkRequest=(new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/autori/bulk-delete')->withParsedBody(['ids'=>[$bulkPerson]]);
    $bulkResponse=(new App\Controllers\AutoriApiController())->bulkDelete($bulkRequest,new Slim\Psr7\Response(),$db);
    $bulkCredit=$service->get($bulkArticle)['author_credits'][0] ?? [];
    verifyIdentity($bulkResponse->getStatusCode()===200 && $authors->getById($bulkPerson)===null,'bulk delete removes the author');
    verifyIdentity(array_key_exists('autore_id',$bulkCredit) && $bulkCredit['autore_id']===null && ($bulkCredit['nome_credito'] ?? '')==='Renamed, Bulk','bulk delete freezes the latest credit name like the single delete');
    echo "SUCCESS $count identity checks\n";
} finally {
    foreach ($db->tables as $table) { $db->raw("DROP TABLE IF EXISTS `{$db->prefix}$table`"); }
    $db->close();
}
