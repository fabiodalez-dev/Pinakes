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
    verifyIdentity($row['autori']==='Hans Uwe Petersen; Åse Sørensen','display comes from shared author registry');
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===1 && $catalog->countArticles(['autore_id'=>$homonym])===0,'ID filter distinguishes homonyms');
    $legacy=$service->save(['titolo'=>'Legacy article','autori'=>'Petersen, Hans Uwe','pubblico'=>1]);
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===1,'legacy text is never silently assigned an identity');
    verifyIdentity($catalog->countArticles(['autore'=>'Petersen, Hans Uwe'])===2,'legacy credits remain discoverable by name');
    $authors->update($person,['nome'=>'Hans Uwe Renamed','pseudonimo'=>'Archive pen name','gnd_id'=>'118559792']);
    $row=$service->get($id);
    verifyIdentity(str_contains($row['autori'],'Archive pen name (Hans Uwe Renamed)'),'rename and pseudonym update article without rewriting original credit');
    verifyIdentity($catalog->countArticles(['search'=>'Renamed'])===1,'general search finds current shared name');
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===1,'identity search survives a rename');
    verifyIdentity($service->search('Hans Uwe Renamed')['total']===1,'article search uses renamed shared author');
    verifyIdentity($service->search('',0,false,1,['autore'=>'Archive pen name'])['total']===1,'article author filter uses current pseudonym');
    $service->save(['titolo'=>'Sort comparison','autori'=>'Nolan','pubblico'=>1]);
    $sorted=$catalog->page('FROM libri l WHERE l.deleted_at IS NULL AND 1=0','CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore, CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore_principale_nome, CAST(NULL AS CHAR CHARACTER SET utf8mb4) autore_cognome','',[],['sort'=>'author_asc'],0,20,0);
    verifyIdentity((int)$sorted['rows'][0]['id']===$id,'author sort uses current pseudonym rather than stale article text');
    $suggestion=$plugin->suggestEmerotecaSearch([],'Renamed');
    verifyIdentity(count($suggestion)===1 && $suggestion[0]['total']===1 && str_contains($suggestion[0]['items'][0]['meta'],'Archive pen name'),'catalogue suggestions find and display the current shared author');
    $csv=(new \App\Plugins\Emeroteca\Services\ContributionCsv($service))->export();
    verifyIdentity(str_contains($csv,'Archive pen name (Hans Uwe Renamed)'),'CSV exports current author names');
    $csvService=new \App\Plugins\Emeroteca\Services\ContributionCsv($service);
    $report=$csvService->commit($csvService->preview($csv));
    verifyIdentity(count(array_filter($report,static fn($r)=>$r['error']!==null))===0 && array_column($service->get($id)['author_credits'],'autore_id')===[$person,$coauthor],'CSV round trip preserves explicitly linked identities after a rename');
    $xml=ArticleMarcXml::format($row);
    verifyIdentity(str_contains($xml,'<subfield code="0">https://d-nb.info/gnd/118559792</subfield>'),'MARC exports confirmed shared GND URI');
    $doc=new DOMDocument();$doc->loadXML($xml);$xp=new DOMXPath($doc);$xp->registerNamespace('m','http://www.loc.gov/MARC21/slim');
    verifyIdentity($xp->evaluate('string(//m:datafield[@tag="700"]/m:subfield[@code="a"])')==='Åse Sørensen','coauthor remains 700');
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
    $authors->update($newPerson,['nome'=>'Latest name']);
    $authors->delete($newPerson);
    $detached=$service->get($new);
    verifyIdentity($detached['autori']==='Latest name' && $detached['author_credits'][0]['autore_id']===null,'deleting identity preserves latest credit without dangling ID');
    $legacyRow=$service->get($legacy);
    $service->save($legacyRow+['credits_present'=>1,'credits'=>[$credit($person)]],$legacy,(int)$legacyRow['revision']);
    verifyIdentity($catalog->countArticles(['autore_id'=>$person])===3,'legacy record can be explicitly linked without recreating article');
    $replacement=$service->save(['titolo'=>'Import replacement','credits_present'=>1,'credits'=>[$credit($person)]]);
    $replacementRow=$service->get($replacement);
    $service->save(array_replace($replacementRow,['autori'=>'Different imported credit']),$replacement,(int)$replacementRow['revision']);
    verifyIdentity($service->get($replacement)['author_credits']===[] && $service->get($replacement)['autori']==='Different imported credit','text import replacing authors clears stale identities');
    $target=$authors->create(['nome'=>'Primary without authority']);
    $sourceAuthor=$authors->create(['nome'=>'Duplicate with authority','gnd_id'=>'119030238']);
    verifyIdentity($authors->mergeAuthors([$target,$sourceAuthor],$target)===$target && $authors->getById($target)['gnd_id']==='119030238','GND transfers from merged duplicate to retained identity');
    echo "SUCCESS $count identity checks\n";
} finally {
    foreach ($db->tables as $table) { $db->raw("DROP TABLE IF EXISTS `{$db->prefix}$table`"); }
    $db->close();
}
