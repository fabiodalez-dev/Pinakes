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
// Mirrors Updater::runMigrations() for a file without DELIMITER: drop the "--"
// lines, then split on ';' outside single-quoted strings. Each statement goes
// through the sandbox's query(), which is what remaps table names — multi_query()
// would bypass it and write to the real tables.
function migrationStatements412(string $sql): array {
    $sql=implode("\n",array_filter(explode("\n",$sql),fn($l)=>!preg_match('/^\s*--/',$l)));
    $out=[]; $cur=''; $in=false; $len=strlen($sql);
    for($i=0;$i<$len;$i++){ $c=$sql[$i];
        if($c==="'"){ if($in&&$i+1<$len&&$sql[$i+1]==="'"){$cur.="''";$i++;continue;} $in=!$in; $cur.=$c; continue; }
        if($c===';'&&!$in){ if(trim($cur)!=='')$out[]=trim($cur); $cur=''; continue; }
        $cur.=$c; }
    if(trim($cur)!=='')$out[]=trim($cur);
    return $out;
}
function check412(bool $v,string $label): void { global $n; if(!$v)throw new RuntimeException($label); echo 'PASS '.(++$n).' '.$label."\n"; }
function rejects412(callable $fn,string $label): void { try{$fn();}catch(InvalidArgumentException $e){check412(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label); }
try {
    $db->query('CREATE TABLE plugins (id INT PRIMARY KEY, name VARCHAR(100) UNIQUE) ENGINE=InnoDB');
    $db->query('CREATE TABLE plugin_settings (plugin_id INT, setting_key VARCHAR(100), setting_value TEXT, UNIQUE KEY (plugin_id,setting_key)) ENGINE=InnoDB');
    $db->query("INSERT INTO plugins VALUES (1,'emeroteca')");
    foreach([EmerotecaPlugin::ddlTestate(),EmerotecaPlugin::ddlAnnate(),EmerotecaPlugin::ddlFascicoli(),EmerotecaPlugin::ddlArticoli(),EmerotecaPlugin::ddlAbbonamenti()] as $ddl){$db->query($ddl);}
    $migration=file_get_contents($root.'/installer/database/migrations/migrate_0.7.84.sql');
    $runMigration=static function() use($db,$migration): void { foreach(migrationStatements412($migration) as $st){ $db->query($st); } };
    // mode() falls back to 'complete' when no row exists, so it cannot tell a
    // stamped collection from an untouched one: assert on the row itself.
    $modeRow=static fn(): string => (string)($db->query("SELECT setting_value FROM plugin_settings WHERE plugin_id=1 AND setting_key='mode'")->fetch_row()[0] ?? '');
    $runMigration();
    check412($modeRow()==='','migration leaves an empty collection unstamped (tables alone are not a collection)');
    $db->query("INSERT INTO emeroteca_testate (titolo) VALUES ('Legacy')");
    $runMigration();
    $svc=new ContributionService($db);
    check412($modeRow()==='complete','real migration preserves legacy complete workflow');
    $svc->setMode('simple'); $runMigration();
    check412($svc->mode()==='simple','migration retry preserves explicit choice');
    $db->query('DELETE FROM emeroteca_testate');
    check412(\App\Support\Updater::shouldRunMigration('0.7.84','0.7.83',json_decode(file_get_contents($root.'/version.json'),true)['version']),'production migration-range gate includes this migration');
    $plugin=new EmerotecaPlugin($db,new \App\Support\HookManager($db));
    $schema=$plugin->ensureSchema();
    check412($schema['failed']===[], 'real plugin upgrade completes successfully');
    // Auto-registration runs onInstall() even for this optional, inactive
    // plugin, building every table empty; the operator's first activation later
    // runs ensureSchema() again in a NEW instance, with a fresh table cache that
    // now finds the tables. That sequence must still leave the choice unmade.
    $db->query("DELETE FROM plugin_settings WHERE plugin_id=1 AND setting_key='mode'");
    $plugin->ensureSchema();
    (new EmerotecaPlugin($db,new \App\Support\HookManager($db)))->ensureSchema();
    check412($modeRow()==='','install then first activation on an empty collection leaves the workflow for the administrator');
    $db->query("INSERT INTO emeroteca_testate (titolo) VALUES ('Held')");
    (new EmerotecaPlugin($db,new \App\Support\HookManager($db)))->ensureSchema();
    check412($modeRow()==='complete','a collection holding a masthead keeps Complete when the schema is repaired');
    $db->query("DELETE FROM emeroteca_testate WHERE titolo='Held'");
    $svc->setMode('simple');
    $db->query(ContributionService::ddl()); $db->query(ContributionService::ddl());
    check412(array_column($svc->rows('SHOW COLUMNS FROM emeroteca_contributi'), 'Field') === ['id','reference_key',...array_slice(array_keys(ContributionService::TEXT_FIELDS),0,7),'anno_pubblicazione',...array_slice(array_keys(ContributionService::TEXT_FIELDS),7),'testata_id','fascicolo_id','pubblico','pdf_path','pdf_nome_originale','pdf_dimensione','pdf_pubblico','copertina_url','revision','created_at','updated_at'], 'fresh and repeated schema DDL');
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
    $rev=(int)$svc->get($id)['revision'];
    $svc->associate([$id=>$rev],$title,$issue);
    check412((int)$svc->get($id)['revision']===$rev,'repeating a complete association is idempotent');
    rejects412(fn()=>$svc->associate([$id=>$rev],$title,0),'masthead only refuses to drop an existing issue link unconfirmed');
    check412((int)$svc->get($id)['fascicolo_id']===$issue,'the refused batch leaves the issue link untouched');
    $svc->associate([$id=>$rev],$title,0,'',false,true);
    check412($svc->get($id)['fascicolo_id']===null && (int)$svc->get($id)['testata_id']===$title,'masthead only removes the previous issue once confirmed');
    $svc->associate([$id=>(int)$svc->get($id)['revision']],$title,0);
    check412($svc->get($id)['fascicolo_id']===null,'an article with no issue link needs no confirmation');
    $svc->associate([$id=>(int)$svc->get($id)['revision']],$title,$issue);
    $svc->rows('DELETE FROM emeroteca_fascicoli WHERE id=?',[$issue]);
    check412($svc->get($id)['fascicolo_id']===null && (int)$svc->get($id)['testata_id']===$title,'issue deletion preserves article and masthead association');
    $svc->rows('DELETE FROM emeroteca_testate WHERE id=?',[$title]);
    check412($svc->get($id)['testata_id']===null && $svc->get($id)['pagine']==='138–148','masthead deletion preserves article citation');
    $private=$svc->save(['titolo'=>'Secret Article','note_private'=>'Secret notes','collocazione'=>'Secret shelf']);
    check412($svc->get($private,true)===null && $svc->search('Secret',0,true)['total']===0,'private article absent from public lookups/search');
    check412($svc->search('International',0,true)['total']===1,'search includes container title');
    check412($svc->search('%',0,true)['total']===0,'LIKE wildcard is escaped');

    // ── #412 (follow-up): l'articolo va trovato dove il lettore lo cerca ──
    // Author, publication and keyword are free text on a standalone article,
    // so "everything else by this author" can only be a filtered search.
    $second=$svc->save(['titolo'=>'Second Schweissinger piece','autori'=>'Marc J. Schweissinger','contenitore_titolo'=>'Another Journal','keywords'=>'Tyll, Kehlmann','pubblico'=>1]);
    $svc->save(['titolo'=>'Unpublished by the same author','autori'=>'Marc J. Schweissinger','keywords'=>'Tyll']);
    check412($svc->search('',0,true,1,['autore'=>'Schweissinger'])['total']===2,'author filter collects the articles by that author');
    check412($svc->search('',0,true,1,['pubblicazione'=>'Another Journal'])['total']===1,'publication filter narrows to one journal');
    check412($svc->search('',0,true,1,['keyword'=>'Kehlmann'])['total']===1,'keyword filter matches inside a comma-separated list');
    check412($svc->search('',0,true,1,['keyword'=>'Tyll'])['total']===2,'keyword filter matches every article carrying it');
    check412($svc->search('',0,true,1,['autore'=>'Schweissinger','pubblicazione'=>'Another Journal'])['total']===1,'filters combine');
    check412($svc->search('',0,true,1,['autore'=>'%'])['total']===0,'filter wildcards are escaped');
    check412($svc->search('',0,true,1,['sconosciuto'=>'x'])['total']===$svc->search('',0,true)['total'],'an unknown filter key is ignored, not applied');
    // The unpublished article by the same author is the point of this one: a
    // filter must never be a way around pubblico=0.
    check412($svc->search('Unpublished',0,true)['total']===0 && $svc->search('',0,true,1,['autore'=>'Schweissinger'])['total']===2,'a filtered search never surfaces an unpublished article');

    // The catalogue hint: it must carry the articles themselves, because the
    // visitor searched the catalogue for a title it cannot hold.
    $suggest=$plugin->suggestEmerotecaSearch([],'Intertextuality');
    check412(count($suggest)===1 && ($suggest[0]['total']??0)===1 && count($suggest[0]['items']??[])===1,'a catalogue search that matches an article yields one section with one item');
    check412(($suggest[0]['items'][0]['label']??'')===$base['titolo'],'the item carries the article title, not a generic label');
    check412(str_ends_with((string)($suggest[0]['items'][0]['url']??''),'/emeroteca/articolo/'.$id),'the item links to that article');
    check412(str_contains((string)($suggest[0]['items'][0]['meta']??''),'Schweissinger') && str_contains((string)($suggest[0]['items'][0]['meta']??''),'138–148'),'the item meta line carries authors and the page span');
    check412($plugin->suggestEmerotecaSearch([],'Secret Article')===[],'an unpublished article produces no suggestion at all');
    check412($plugin->suggestEmerotecaSearch([],'z')===[],'a one-character term is not worth a full scan');
    check412($plugin->suggestEmerotecaSearch([],'%%')===[],'wildcards in the term never match everything');
    check412($plugin->suggestEmerotecaSearch('not-an-array','Intertextuality')==='not-an-array','a non-array input is passed through untouched');
    check412(count($plugin->suggestEmerotecaSearch([['label'=>'zz existing','url'=>'/x'],],'Intertextuality'))===2,'the listener appends, it never replaces');
    $svc->rows("INSERT INTO emeroteca_testate (titolo,sottotitolo) VALUES ('Zeitschrift für Tests','Beilage')");
    $suggestTestata=$plugin->suggestEmerotecaSearch([],'Zeitschrift');
    check412(count($suggestTestata)===1 && ($suggestTestata[0]['items'][0]['meta']??'')==='Beilage','a masthead match yields its own section with the subtitle as meta');
    $svc->rows("DELETE FROM emeroteca_testate WHERE titolo='Zeitschrift für Tests'");

    // The article image, on an install that predates the column: the real
    // schema repair must add it, twice in a row, without touching the rows.
    $db->query('ALTER TABLE emeroteca_contributi DROP COLUMN copertina_url');
    $repair=(new EmerotecaPlugin($db,new \App\Support\HookManager($db)))->ensureSchema();
    (new EmerotecaPlugin($db,new \App\Support\HookManager($db)))->ensureSchema();
    check412($repair['failed']===[] && in_array('copertina_url',array_column($svc->rows('SHOW COLUMNS FROM emeroteca_contributi'),'Field'),true),'the real schema repair adds the article image column to an older install');
    check412($svc->get($second)['titolo']==='Second Schweissinger piece','repairing the schema leaves the catalogued articles alone');
    $rev=(int)$svc->get($id)['revision'];
    $svc->save(array_replace($svc->get($id),['copertina_url'=>'/uploads/emeroteca/forged.jpg']),$id,$rev);
    check412($svc->get($id)['copertina_url']===null,'the form body cannot point an article at a file of its own choosing');
    $rev=(int)$svc->get($id)['revision'];
    $svc->save($svc->get($id),$id,$rev,['copertina_url'=>'/uploads/emeroteca/real.jpg']);
    check412($svc->get($id)['copertina_url']==='/uploads/emeroteca/real.jpg','the controller sets the image through the validated files argument');
    $rev=(int)$svc->get($id)['revision'];
    $svc->save($svc->get($id),$id,$rev,['copertina_url'=>null]);
    check412($svc->get($id)['copertina_url']===null,'removing the image clears the column');
    $svc->rows('DELETE FROM emeroteca_contributi WHERE id IN (?,?)',[$second,(int)$svc->rows("SELECT id FROM emeroteca_contributi WHERE titolo='Unpublished by the same author'")[0]['id']]);
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
    check412(array_key_exists('pdf_url',$payload['data'][0]) && $payload['data'][0]['pdf_url']===null,'mobile private PDF has no public URL');
    $svc->rows("UPDATE emeroteca_contributi SET pdf_path=?,pdf_pubblico=1 WHERE id=?",[str_repeat('a',40).'.pdf',$id]);
    $pdfResponse=$mobile->articles($request,new \Slim\Psr7\Response(),$id);
    $pdfData=json_decode((string)$pdfResponse->getBody(),true)['data'];
    check412($pdfData['has_public_pdf']===true && $pdfData['pdf_url']===absoluteUrl('/emeroteca/articolo/'.$id.'/pdf'),'mobile public PDF uses the server-resolved route');
    check412(!str_contains((string)$pdfResponse->getBody(),str_repeat('a',40)),'mobile PDF never exposes the storage filename');
    $svc->rows('UPDATE emeroteca_contributi SET pdf_pubblico=0 WHERE id=?',[$id]);
    $withdrawn=$mobile->articles($request,new \Slim\Psr7\Response(),$id);
    check412(json_decode((string)$withdrawn->getBody(),true)['data']['pdf_url']===null && $withdrawn->getHeaderLine('ETag')!==$pdfResponse->getHeaderLine('ETag'),'withdrawing PDF clears its URL and invalidates the mobile ETag');

    $svc->rows('UPDATE emeroteca_contributi SET copertina_url=? WHERE id=?',['/uploads/emeroteca/articolo_test.jpg',$id]);
    $withCover=json_decode((string)$mobile->articles($request,new \Slim\Psr7\Response(),$id)->getBody(),true)['data'];
    check412($withCover['cover_url']===absoluteUrl('/uploads/emeroteca/articolo_test.jpg'),'mobile article resolves the image to an absolute URL');
    $svc->rows('UPDATE emeroteca_contributi SET copertina_url=NULL WHERE id=?',[$id]);
    check412(json_decode((string)$mobile->articles($request,new \Slim\Psr7\Response(),$id)->getBody(),true)['data']['cover_url']===null,'an article without an image reports no URL instead of an empty path');
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
        rejects412(fn()=>$parse->invoke(new \App\Controllers\CsvImportController(),['titolo'=>'Analytic','record_type'=>'','tipo_media'=>$type]),'an empty record_type cell still reads tipo_media: '.$type);
        rejects412(fn()=>$parse->invoke(new \App\Controllers\CsvImportController(),['titolo'=>'Analytic','record_type'=>'  '.strtoupper($type).' ']),'record_type is matched trimmed and case-insensitively: '.$type);
    }
    check412(is_array($parse->invoke(new \App\Controllers\CsvImportController(),['titolo'=>'A book','record_type'=>'','tipo_media'=>'libro'])),'a book row with an empty record_type is still imported');
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


    // Recurring columns are distinct publications when their chronology differs.
    $annualCsv="titolo,autori,contenitore_titolo,anno_pubblicazione,data_pubblicazione_testo,numero,pagine\nEditoriale annuale,Author,Journal,2024,,1,1\nEditoriale annuale,Author,Journal,2025,,1,1\n";
    $annual=$csv->preview($annualCsv);
    check412(!array_filter($annual,fn($r)=>$r['error']!==null),'different years are not duplicate citations in preview');
    check412(!array_filter($csv->commit($annual),fn($r)=>$r['error']!==null),'different years both survive commit-time duplicate checks');
    check412($csv->preview($annualCsv)[0]['error']!==null,'the same annual citation is still rejected');
    $dated=$csv->preview("titolo,autori,contenitore_titolo,data_pubblicazione_testo\nRubrica mensile,Author,Journal,June 2024\nRubrica mensile,Author,Journal,July 2024\n");
    check412(!array_filter($dated,fn($r)=>$r['error']!==null) && !array_filter($csv->commit($dated),fn($r)=>$r['error']!==null),'textual dates distinguish issues even without volume and number');
    $doiYears=$csv->preview("titolo,anno_pubblicazione,doi\nChronology DOI,2024,10.1234/annual\nChronology DOI,2025,10.1234/annual\n");
    check412($doiYears[1]['error']!==null,'identical DOI still identifies a duplicate across different dates');

    // Check actual exported cells, then reimport without losing literal prefixes.
    $formulaTitles=['=1+1','+1+1','-1+1','@SUM(1)',"'=1+1","''quoted","'ordinary"];
    $formulaIds=[];
    foreach($formulaTitles as $title) $formulaIds[]=$svc->save(['titolo'=>$title,'note_private'=>'=2+2']);
    $fp=fopen('php://temp','w+'); fwrite($fp,$csv->export()); rewind($fp);
    $header=fgetcsv($fp,0,',','"',''); $safeRows=[];
    while(($cells=fgetcsv($fp,0,',','"',''))!==false) $safeRows[]=array_combine($header,$cells);
    fclose($fp);
    foreach($formulaTitles as $title) {
        check412(count(array_filter($safeRows,fn($r)=>$r['titolo']==="'".$title && $r['note_private']==="'=2+2"))===1,'export escapes formula/apostrophe prefix: '.$title);
    }
    $roundTrip=$csv->preview($csv->export());
    check412(!array_filter($roundTrip,fn($r)=>$r['error']!==null) && !array_filter($csv->commit($roundTrip),fn($r)=>$r['error']!==null),'escaped export can be imported again');
    check412(array_map(fn($id)=>$svc->get($id)['titolo'],$formulaIds)===$formulaTitles,'reimport preserves formulas as text and literal apostrophes exactly');
    check412($csv->preview("titolo\n=3+3\n")[0]['data']['titolo']==='=3+3','ordinary external CSV formula text is not changed on import');
    $batch=$csv->preview("titolo,autori,contenitore_titolo\nBatch duplicate,Author,Journal\nBatch duplicate,Author,Journal\n");
    check412($batch[0]['error']===null && $batch[1]['error']!==null,'preview flags duplicate citations inside the uploaded batch');
    $committed=$csv->commit($batch);
    check412(count(array_filter($committed,fn($r)=>$r['error']===null))===1,'a duplicate batch creates only one article');
    $batch=$csv->preview("titolo,doi\nDOI first,10.1234/BATCH\nDOI second,https://doi.org/10.1234/batch\n");
    check412($batch[1]['error']!==null,'normalized DOI detects duplicates within the batch');
    // utf8mb4_unicode_ci ignores accents, so the batch check must too: otherwise
    // the preview passes a row the commit then refuses — right answer, wrong moment.
    $batch=$csv->preview("titolo,contenitore_titolo\nCitta e memoria,Journal\nCittà e memoria,Journal\n");
    check412($batch[0]['error']===null && $batch[1]['error']!==null,'accents fold in the batch check as they do in the database');
    check412(count(array_filter($csv->commit($batch),fn($r)=>$r['error']===null))===1,'only one of the two accent variants is stored');
    // An update can carry a known reference_key and ANOTHER article's identity:
    // that stores the same article twice, so it is refused like a new duplicate.
    $csv->commit($csv->preview("reference_key,titolo,doi\nupd-a,Update target A,10.5555/aaa\nupd-b,Update target B,10.5555/bbb\n"));
    $stolen=$csv->preview("reference_key,titolo,doi\nupd-b,Update target A,10.5555/aaa\n");
    check412($stolen[0]['error']!==null,'an update that takes another article\'s citation and DOI is refused');
    check412(count(array_filter($csv->commit($stolen),fn($r)=>$r['error']!==null))===1,'commit refuses it too, under the lock');
    check412((int)$svc->rows("SELECT COUNT(*) n FROM emeroteca_contributi WHERE reference_key='upd-b' AND titolo='Update target B'")[0]['n']===1,'the targeted row keeps its own citation');
    // But a row that keeps the citation it already has must still update: this
    // is every round trip of an export, and a collection may legitimately hold
    // two similar citations from before the check existed.
    $again=$csv->preview("reference_key,titolo,doi,keywords\nupd-a,Update target A,10.5555/aaa,revised\n");
    check412($again[0]['error']===null && $csv->commit($again)[0]['error']===null,'an update that keeps its own citation still goes through');
    check412($svc->rows("SELECT keywords FROM emeroteca_contributi WHERE reference_key='upd-a'")[0]['keywords']==='revised','and the update is actually applied');
    $first=$csv->preview("titolo\nConcurrent import citation\n");
    $second=$csv->preview("titolo\nConcurrent import citation\n");
    check412($csv->commit($first)[0]['error']===null && $csv->commit($second)[0]['error']!==null,'commit rechecks a citation inserted after its preview');
    // A second connection holding the import lock must cost ONE wait for the
    // whole batch. Taking the lock per row multiplied the 10 s timeout by the
    // row count: measured 50 s for five rows, so a 500-row import would sit for
    // over an hour and die on max_execution_time instead of saying it is busy.
    $blocker=new mysqli($env['DB_HOST']??'localhost',getenv('E2E_DB_USER')?:$env['DB_USER'],getenv('E2E_DB_PASS')?:($env['DB_PASS']??$env['DB_PASSWORD']),getenv('E2E_DB_NAME')?:$env['DB_NAME'],(int)($env['DB_PORT']??3306),getenv('E2E_DB_SOCKET')?:($env['DB_SOCKET']??null));
    $lockName='emeroteca_csv_'.substr(hash('sha256',(string)$db->query('SELECT DATABASE() n')->fetch_row()[0]),0,40);
    // Assert the precondition instead of assuming it: if the blocking connection
    // does not actually hold the lock, commit() legitimately succeeds and the
    // check below fails for a reason that has nothing to do with the batch.
    $escapedLock=$blocker->real_escape_string($lockName);
    $acquired=null;
    for($attempt=0;$attempt<3 && (string)$acquired!=='1';$attempt++) {
        $acquired=$blocker->query("SELECT GET_LOCK('$escapedLock', 5) a")->fetch_row()[0];
    }
    check412((string)$acquired==='1','the blocking connection holds the import lock (got '.var_export($acquired,true).')');
    $contended=$csv->preview("titolo\nBusy one\nBusy two\nBusy three\n");
    $start=microtime(true); $busy=null;
    try { $csv->commit($contended); } catch (InvalidArgumentException $e) { $busy=$e->getMessage(); }
    $waited=microtime(true)-$start;
    check412($busy!==null && $waited<20,sprintf('a contended import waits once for the batch, not once per row (esito=%s, attesa=%.1fs, lock=%s, held=%s)',var_export($busy,true),$waited,$lockName,var_export($blocker->query("SELECT IS_USED_LOCK('".$blocker->real_escape_string($lockName)."') u")->fetch_row()[0],true)));
    check412((int)$svc->rows("SELECT COUNT(*) n FROM emeroteca_contributi WHERE titolo LIKE 'Busy %'")[0]['n']===0,'a contended import writes nothing at all');
    $blocker->query("SELECT RELEASE_LOCK('$escapedLock')"); $blocker->close();
    check412(count(array_filter($csv->commit($contended),fn($r)=>$r['error']===null))===3,'the same batch imports once the lock is free');
    $parts=iterator_to_array($csv->exportParts());
    check412(count($parts)===1 && count($csv->preview($parts[0]))>0,'small exports remain a single reimportable CSV');
    for($i=0;$i<501;$i++) { $svc->save(['titolo'=>'Export batch '.$i,'abstract'=>str_repeat('a',10000),'note_private'=>str_repeat('n',10000)]); }
    $parts=iterator_to_array($csv->exportParts()); $exportCount=0;
    // Say what was actually produced: a bare "CSV vuoto" from preview() gives
    // the next reader nothing to go on.
    $shape=implode(', ',array_map(fn($i,$p)=>"#$i=".strlen($p).'B/'.substr_count($p,"\n").'righe',array_keys($parts),$parts));
    check412($parts!==[] && $parts[0]!=='',"exportParts produces non-empty parts (rows=".(int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_contributi')[0]['n'].", parts: $shape)");
    check412(count($parts)>1 && count($csv->preview($parts[0]))<500,'large exports split on byte size as well as row count');
    foreach($parts as $part) {
        $parsed=$csv->preview($part); $exportCount+=count($parsed);
        check412(strlen($part)<=ContributionCsv::MAX_BYTES && count($parsed)<=ContributionCsv::MAX_ROWS && !array_filter($parsed,fn($r)=>$r['error']!==null),'each exported part fits import limits and previews successfully');
    }
    check412($exportCount===(int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_contributi')[0]['n'],'split export contains every article exactly once');
    $_SESSION=['user'=>['tipo_utente'=>'admin']];
    $download=$controller->export($request,new \Slim\Psr7\Response());
    check412($download->getHeaderLine('Content-Type')==='application/zip','large export endpoint downloads a ZIP');
    $zipPath=tempnam(sys_get_temp_dir(),'test412_zip_');
    try {
        file_put_contents($zipPath,(string)$download->getBody()); $zip=new ZipArchive();
        check412($zip->open($zipPath)===true && $zip->numFiles===count($parts),'downloaded ZIP contains all reimportable parts');
        $zip->close();
    } finally { unlink($zipPath); }
    // The book form's Tipo Media hint (#412: that dropdown is where a
    // cataloguer with one article looks first). Three states, because a link to
    // a plugin that is not there is worse than no hint at all.
    $hint=\App\Support\PeriodicalArticlesHint::class;
    // The disposable plugins table is minimal; the hint reads is_active.
    $db->query('ALTER TABLE plugins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0');
    $db->query("UPDATE plugins SET is_active=1 WHERE name='emeroteca'");
    check412($hint::state($db)===$hint::ACTIVE,'active plugin: the book form points at the article form');
    $db->query("UPDATE plugins SET is_active=0 WHERE name='emeroteca'");
    check412($hint::state($db)===$hint::INACTIVE,'installed but off: the book form points at Plugins');
    $db->query("DELETE FROM plugins WHERE name='emeroteca'");
    check412($hint::state($db)===$hint::ABSENT,'uninstalled plugin: no hint, no dead link');
    $db->query("INSERT INTO plugins (id,name,is_active) VALUES (1,'emeroteca',1)");
    check412($hint::state($db,sys_get_temp_dir().'/pinakes-no-plugins-'.bin2hex(random_bytes(4)))===$hint::ABSENT,'a row without its plugin directory is treated as absent');
    check412($hint::state(null)===$hint::ABSENT,'no database connection: the book form still renders');
    // Uninstalling removes the plugin, never the catalogued articles.
    $before=(int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_contributi')[0]['n'];
    (new EmerotecaPlugin($db,new \App\Support\HookManager($db)))->onUninstall();
    check412($before>0 && (int)$svc->rows('SELECT COUNT(*) n FROM emeroteca_contributi')[0]['n']===$before,'uninstalling the plugin keeps the standalone articles');
    echo "SUCCESS $n behavioural checks\n";
} finally {
    foreach(['emeroteca_contributi','emeroteca_articoli','emeroteca_abbonamenti','emeroteca_fascicoli','emeroteca_annate','emeroteca_testate','plugin_hooks','plugin_settings','plugins'] as $t) { $db->query('DROP TABLE IF EXISTS '.$t); }
    $db->close();
}
