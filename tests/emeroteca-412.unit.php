<?php
declare(strict_types=1);
/** Real MySQL, real services and migration. SQL identifiers alone are remapped
 * to disposable tables; no existing catalogue/schema is modified. */
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/EmerotecaPlugin.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Services/ContributionService.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Services/ContributionCsv.php';
use App\Plugins\Emeroteca\Services\ContributionService;
use App\Plugins\Emeroteca\Services\ContributionCsv;

final class Sandbox412Db extends mysqli
{
    public string $prefix;
    public array $tables=['emeroteca_testate','emeroteca_annate','emeroteca_fascicoli','emeroteca_articoli','emeroteca_abbonamenti','emeroteca_contributi','plugin_settings','plugins','plugin_hooks'];
    public function mapped(string $sql): string {
        foreach($this->tables as $name) { $sql=preg_replace('/\b'.preg_quote($name,'/').'\b/',$this->prefix.$name,$sql); }
        // Constraint names are database-global on MariaDB.
        $sql=preg_replace('/\b(fk_emeroteca_\w+|fk_contributo_\w+)\b/',$this->prefix.'$1',$sql);
        return $sql;
    }
    public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT): mysqli_result|bool { return parent::query($this->mapped($query),$result_mode); }
    public function prepare(string $query): mysqli_stmt|false { return new Sandbox412Stmt($this, $this->mapped($query)); }
}
final class Sandbox412Stmt extends mysqli_stmt
{
    public function __construct(private Sandbox412Db $sandbox, string $sql) { parent::__construct($sandbox,$sql); }
    public function bind_param(string $types, mixed &...$vars): bool {
        foreach($vars as &$v) { if(is_string($v) && in_array($v,$this->sandbox->tables,true)) $v=$this->sandbox->prefix.$v; }
        return parent::bind_param($types,...$vars);
    }
}
$root=dirname(__DIR__); $env=Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new Sandbox412Db($env['DB_HOST']??'localhost',getenv('E2E_DB_USER')?:$env['DB_USER'],getenv('E2E_DB_PASS')?:($env['DB_PASS']??$env['DB_PASSWORD']),getenv('E2E_DB_NAME')?:$env['DB_NAME'],(int)($env['DB_PORT']??3306),getenv('E2E_DB_SOCKET')?:($env['DB_SOCKET']??null));
$db->prefix='zz412_'.bin2hex(random_bytes(3)).'_'; $db->set_charset('utf8mb4');
$n=0;
function check412(bool $v,string $label): void { global $n; if(!$v)throw new RuntimeException($label); echo 'PASS '.(++$n).' '.$label."\n"; }
function rejects412(callable $fn,string $label): void { try{$fn();}catch(InvalidArgumentException $e){check412(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label); }
try {
    $db->query('CREATE TABLE plugins (id INT PRIMARY KEY, name VARCHAR(100) UNIQUE) ENGINE=InnoDB');
    $db->query('CREATE TABLE plugin_settings (plugin_id INT, setting_key VARCHAR(100), setting_value TEXT, UNIQUE KEY (plugin_id,setting_key)) ENGINE=InnoDB');
    $db->query("INSERT INTO plugins VALUES (1,'emeroteca')");
    foreach([EmerotecaPlugin::ddlTestate(),EmerotecaPlugin::ddlAnnate(),EmerotecaPlugin::ddlFascicoli(),EmerotecaPlugin::ddlArticoli(),EmerotecaPlugin::ddlAbbonamenti()] as $ddl){$db->query($ddl);}
    $migration=file_get_contents($root.'/installer/database/migrations/migrate_0.7.84.sql');
    $db->query($migration);
    $svc=new ContributionService($db);
    check412($svc->mode()==='complete','real migration preserves legacy complete workflow');
    $svc->setMode('simple'); $db->query($migration);
    check412($svc->mode()==='simple','migration retry preserves explicit choice');
    check412(\App\Support\Updater::shouldRunMigration('0.7.84','0.7.83',json_decode(file_get_contents($root.'/version.json'),true)['version']),'production migration-range gate includes this migration');
    $plugin=new EmerotecaPlugin($db,new \App\Support\HookManager($db));
    $schema=$plugin->ensureSchema();
    check412($schema['failed']===[], 'real plugin upgrade completes successfully');
    $db->query(ContributionService::ddl()); $db->query(ContributionService::ddl());
    check412(array_column($svc->rows('SHOW COLUMNS FROM emeroteca_contributi'), 'Field') === ['id','reference_key',...array_slice(array_keys(ContributionService::TEXT_FIELDS),0,7),'anno_pubblicazione',...array_slice(array_keys(ContributionService::TEXT_FIELDS),7),'testata_id','fascicolo_id','pubblico','pdf_path','pdf_nome_originale','pdf_dimensione','pdf_pubblico','revision','created_at','updated_at'], 'fresh and repeated schema DDL');
    $base=['titolo'=>"Intertextuality in Daniel Kehlmann's Novel Tyll",'autori'=>'Marc J. Schweissinger','contenitore_titolo'=>'International Journal of Language and Literature','data_pubblicazione_testo'=>'giugno 2019','anno_pubblicazione'=>'2019','volume'=>'7','numero'=>'1','pagine'=>'138–148','pubblico'=>1];
    $id=$svc->save($base);$row=$svc->get($id);
    check412($id>0 && $row['pagine']==='138–148' && $row['fascicolo_id']===null,'single article without any host');
    check412((int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_testate')[0]['n']===0,'no fictitious masthead');
    $svc->save($base+['keywords'=>'Tyll'],$id,(int)$row['revision']);
    check412((int)$svc->get($id)['revision']===2,'editing preserves id and increments revision');
    rejects412(fn()=>$svc->save($base,$id,1),'concurrent edit rejected');
    rejects412(fn()=>ContributionService::normalize(['titolo'=>'']),'empty title rejected');
    rejects412(fn()=>ContributionService::normalize(['titolo'=>['x']]),'array payload rejected');
    rejects412(fn()=>ContributionService::normalize($base+['issn'=>'1234-5678']),'bad ISSN rejected');
    rejects412(fn()=>ContributionService::normalize(array_replace($base,['anno_pubblicazione'=>'2019-06'])),'invalid numeric year rejected');
    rejects412(fn()=>ContributionService::normalize($base+['supporto'=>'book']),'unknown medium rejected');
    $rich=ContributionService::normalize($base+['issn'=>'0378-5955','doi'=>'https://doi.org/10.1234/TYLL']);
    check412($rich['doi']==='10.1234/tyll','DOI normalization');
    $title=$svc->associate([$id=>2],0,0,'Journal created later');
    check412($title>0 && (int)$svc->get($id)['testata_id']===$title,'create masthead and associate atomically');
    check412($svc->get($id)['contenitore_titolo']===$base['contenitore_titolo'],'citation survives association unchanged');
    $revision=(int)$svc->get($id)['revision']; $svc->associate([$id=>$revision],$title);
    check412((int)$svc->get($id)['revision']===$revision,'association retry is harmless');
    $before=(int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_testate')[0]['n'];
    rejects412(fn()=>$svc->associate([$id=>1],0,0,'Must rollback'),'stale bulk selection rejected');
    check412((int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_testate')[0]['n']===$before,'failed association rolls back new masthead');
    $svc->rows('INSERT INTO emeroteca_annate (testata_id,anno,volume) VALUES (?,2019,\'7\')',[$title]);$year=(int)$db->insert_id;
    $svc->rows('INSERT INTO emeroteca_fascicoli (annata_id,numero) VALUES (?,\'1\')',[$year]);$issue=(int)$db->insert_id;
    rejects412(fn()=>$svc->associate([$id=>$revision],0,$issue),'issue cannot belong to a different masthead');
    $holdings=EmerotecaPlugin::consistenzaTestata($db,$title);
    $svc->associate([$id=>$revision],$title,$issue);
    check412(EmerotecaPlugin::consistenzaTestata($db,$title)===$holdings,'article association never changes holdings');
    $svc->rows('DELETE FROM emeroteca_fascicoli WHERE id=?',[$issue]);
    check412($svc->get($id)['fascicolo_id']===null && (int)$svc->get($id)['testata_id']===$title,'issue deletion preserves article and masthead association');
    $svc->rows('DELETE FROM emeroteca_testate WHERE id=?',[$title]);
    check412($svc->get($id)['testata_id']===null && $svc->get($id)['pagine']==='138–148','masthead deletion preserves article citation');
    $private=$svc->save(['titolo'=>'Secret Article','note_private'=>'Secret notes','collocazione'=>'Secret shelf']);
    check412($svc->get($private,true)===null && $svc->search('Secret',0,true)['total']===0,'private article absent from public lookups/search');
    check412($svc->search('International',0,true)['total']===1,'search includes container title');
    check412($svc->search('%',0,true)['total']===0,'LIKE wildcard is escaped');
    $public=ContributionService::publicData($svc->get($private));
    check412(!isset($public['note_private'],$public['collocazione'],$public['pdf_path']),'mobile projection excludes private data');
    $csv=new ContributionCsv($svc); $export=$csv->export();$preview=$csv->preview($export);
    check412(count($preview)===2 && !$preview[0]['error'],'export can be previewed without loss');
    $report=$csv->commit($preview);
    check412(count(array_filter($report,fn($r)=>$r['error']))===0,'round-trip updates original records');
    check412((int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_contributi')[0]['n']===2,'round-trip creates no duplicates');
    check412($svc->get($id)['data_pubblicazione_testo']==='giugno 2019','partial publication date preserved');
    $key=$svc->get($id)['reference_key'];
    $preview=$csv->preview("reference_key,titolo,volume\n$key,Changed,\n");$csv->commit($preview);
    check412($svc->get($id)['volume']===null && $svc->get($id)['pagine']==='138–148','empty clears a value; missing column preserves it');
    rejects412(fn()=>$csv->preview("title,titolo\na,b\n"),'duplicate normalized headers rejected');
    rejects412(fn()=>$csv->preview("titolo,unknown\na,b\n"),'unknown columns rejected');
    $preview=$csv->preview("titolo,media_type,container_title,pages\nNew,journal_article,Journal,iv–x\nBad,book,X,1\n");
    check412($preview[0]['data']['contenitore_tipo']==='rivista' && $preview[1]['error']!==null,'typed routing and unsupported record rejection');
    check412($preview[0]['data']['pagine']==='iv–x','non-numeric page span preserved');
    $csv->commit($preview);
    $duplicates=$csv->preview("titolo,contenitore_titolo,pagine\nNew,Journal,iv–x\n");
    check412($duplicates[0]['error']!==null,'ambiguous duplicate requires explicit identity');
    // A second importer or editor cannot invalidate a preview silently.
    $pending=$csv->preview("reference_key,titolo\n$key,Pending\n");
    $current=$svc->get($id); $svc->save(array_replace($current,['titolo'=>'Concurrent winner']),$id,(int)$current['revision']);
    $report=$csv->commit($pending);
    check412($report[0]['error']!==null && $svc->get($id)['titolo']==='Concurrent winner','import commit rejects stale preview');
    rejects412(fn()=>$csv->preview("titolo,titolo\na,b\n"),'duplicate input headers rejected');
    rejects412(fn()=>$csv->preview("titolo\n".str_repeat("row\n",501)),'CSV batch size enforced');
    rejects412(fn()=>$csv->preview("titolo\n\xff\n"),'invalid UTF-8 rejected');
    rejects412(fn()=>ContributionService::normalize(['titolo'=>str_repeat('a',501)]),'title length enforced without truncation');
    rejects412(fn()=>ContributionService::normalize(['titolo'=>'x','pubblico'=>'yes']),'invalid visibility rejected');
    rejects412(fn()=>$svc->setMode('unknown'),'unknown mode rejected');
    rejects412(fn()=>$svc->associate([],0),'empty association rejected');
    rejects412(fn()=>$svc->associate([$id=>(int)$svc->get($id)['revision']],99999999),'missing masthead rejected');
    $rev=(int)$svc->get($id)['revision'];$host=$svc->associate([$id=>$rev],0,0,'Original host');
    $svc->rows("INSERT INTO emeroteca_testate (titolo) VALUES ('Other host')");$other=(int)$db->insert_id;
    $rev=(int)$svc->get($id)['revision'];
    rejects412(fn()=>$svc->associate([$id=>$rev],$other),'reassignment requires explicit selection');
    $svc->associate([$id=>$rev],$other,0,'',true);
    check412((int)$svc->get($id)['testata_id']===$other,'explicit reassignment succeeds');
    $svc->associate([$id=>(int)$svc->get($id)['revision']],0,0,'',true);
    check412($svc->get($id)['testata_id']===null,'detaching preserves the standalone record');
    require_once $root.'/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
    require_once $root.'/storage/plugins/emeroteca/src/Modules/MobileModule.php';
    $mobile=new \App\Plugins\Emeroteca\Modules\MobileModule($db);
    $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET','http://localhost/api/v1/periodicals/articles');
    $response=$mobile->articles($request,new \Slim\Psr7\Response());
    $payload=json_decode((string)$response->getBody(),true);
    check412($response->getStatusCode()===200 && count($payload['data'])===1,'mobile list includes only public standalone articles');
    check412(!str_contains((string)$response->getBody(),'Secret notes'),'mobile list excludes private notes');
    $cached=$mobile->articles($request->withHeader('If-None-Match',$response->getHeaderLine('ETag')),new \Slim\Psr7\Response());
    check412($cached->getStatusCode()===304,'mobile ETag supports conditional requests');
    check412($mobile->articles($request,new \Slim\Psr7\Response(),$private)->getStatusCode()===404,'mobile private detail returns 404');
    check412($mobile->articles($request->withQueryParams(['cursor'=>'bad']),new \Slim\Psr7\Response())->getStatusCode()===400,'malformed cursor rejected');
    for($i=0;$i<52;$i++) { $svc->save(['titolo'=>'Page article '.$i,'pubblico'=>1]); }
    $response=$mobile->articles($request->withQueryParams(['limit'=>50]),new \Slim\Psr7\Response());
    $first=json_decode((string)$response->getBody(),true);
    $response=$mobile->articles($request->withQueryParams(['limit'=>50,'cursor'=>$first['meta']['next_cursor']]),new \Slim\Psr7\Response());
    $second=json_decode((string)$response->getBody(),true);
    check412(count($first['data'])===50 && count($second['data'])===3,'mobile keyset pagination has no missing or repeated rows');
    check412($svc->search('',0,true,2)['page']===2 && count($svc->search('',0,true,2)['rows'])===3,'browser pagination counts public rows');
    require_once $root.'/storage/plugins/emeroteca/src/Controllers/ContributionController.php';
    $controller=new \App\Plugins\Emeroteca\Controllers\ContributionController($db,new \App\Support\HookManager($db));
    $_SESSION=['user'=>['tipo_utente'=>'staff']];
    check412($controller->mode($request->withParsedBody(['mode'=>'simple']),new \Slim\Psr7\Response())->getStatusCode()===403,'staff cannot change site-wide mode');
    check412($controller->delete($request,new \Slim\Psr7\Response(),['id'=>$id])->getStatusCode()===403,'staff cannot delete standalone records');
    $_SESSION=['user'=>['tipo_utente'=>'admin']];
    check412($controller->publicPdf($request,new \Slim\Psr7\Response(),['id'=>$private])->getStatusCode()===404,'private PDF does not disclose a path');
    $svc->setMode('complete');$svc->setMode('simple');
    check412($svc->get($id)!==null,'mode switch never moves or deletes articles');
    $parse=new ReflectionMethod(\App\Controllers\CsvImportController::class,'parseCsvRow');
    foreach (['article','journal_article','newspaper_article','articolo'] as $type) {
        rejects412(fn()=>$parse->invoke(new \App\Controllers\CsvImportController(),['titolo'=>'Analytic','tipo_media'=>$type]),'book importer rejects '.$type);
    }
    require_once $root.'/storage/plugins/emeroteca/src/Controllers/PeriodicalAdminController.php';
    $svc->rows("INSERT INTO emeroteca_testate (titolo) VALUES ('Merge source')");$source=(int)$db->insert_id;
    $svc->rows("INSERT INTO emeroteca_testate (titolo) VALUES ('Merge destination')");$target=(int)$db->insert_id;
    $svc->associate([$id=>(int)$svc->get($id)['revision']],$source);
    $periodicals=new \App\Plugins\Emeroteca\Controllers\PeriodicalAdminController($db,new \App\Support\HookManager($db));
    (new ReflectionMethod($periodicals,'performMerge'))->invoke($periodicals,$source,$target);
    check412((int)$svc->get($id)['testata_id']===$target,'real masthead merge preserves standalone associations');
    $db->query('ALTER TABLE emeroteca_contributi DROP COLUMN revision');
    $schema=$plugin->ensureSchema();
    check412($schema['failed']===[] && (int)$svc->get($id)['revision']===1,'interrupted schema upgrade repairs missing column');
    $db->query('ALTER TABLE emeroteca_contributi DROP FOREIGN KEY fk_contributo_testata');
    $schema=$plugin->ensureSchema();
    check412($schema['failed']===[],'interrupted schema upgrade repairs missing foreign key');
    check412($plugin->ensureSchema()['failed']===[],'repeated plugin upgrade is idempotent');
    echo "SUCCESS $n behavioural checks\n";
} finally {
    foreach(['emeroteca_contributi','emeroteca_articoli','emeroteca_abbonamenti','emeroteca_fascicoli','emeroteca_annate','emeroteca_testate','plugin_hooks','plugin_settings','plugins'] as $t) { $db->query('DROP TABLE IF EXISTS '.$t); }
    $db->close();
}
