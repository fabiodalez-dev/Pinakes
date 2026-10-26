<?php
declare(strict_types=1);
/** Actual mobile backend/public collection reads, with connection-local fixtures. */
require __DIR__.'/helpers/exact-search-501.php';
require_once dirname(__DIR__).'/storage/plugins/mobile-api/MobileApiPlugin.php';
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
$db=Exact501::db();
$charset='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$db->query("CREATE TEMPORARY TABLE plugins(id INT,name VARCHAR(30),is_active INT) $charset");
$db->query("INSERT INTO plugins VALUES(1,'archives',1),(2,'desiderata',1)");
$db->query("CREATE TEMPORARY TABLE archival_units(id INT,parent_id INT,reference_code VARCHAR(50),level VARCHAR(20),constructed_title TEXT,formal_title TEXT,scope_content TEXT,date_start INT,date_end INT,extent TEXT,specific_material TEXT,cover_image_path TEXT,published INT,deleted_at DATETIME) $charset");
$db->query("CREATE TEMPORARY TABLE authority_records(id INT,type VARCHAR(20),authorised_form TEXT,parallel_forms TEXT,other_forms TEXT,dates_of_existence TEXT,deleted_at DATETIME) $charset");
$db->query("CREATE TEMPORARY TABLE archival_unit_authority(archival_unit_id INT,authority_id INT) $charset");
$db->query("CREATE TEMPORARY TABLE desiderata_offers(id INT,title TEXT,author TEXT,donor_name TEXT,donor_email TEXT,isbn VARCHAR(20),status VARCHAR(20),book_id INT,received_book_id INT,created_at DATETIME) $charset");
foreach(['Haffner','Häffner'] as $i=>$name){
    $id=$i+1;
    Exact501::exec($db,"INSERT INTO archival_units(id,reference_code,level,constructed_title,published) VALUES(?,?,'fonds',?,1)",[$id,"U$id",$name]);
    Exact501::exec($db,"INSERT INTO authority_records(id,type,authorised_form) VALUES(?,'person',?)",[$id,$name]);
    Exact501::exec($db,"INSERT INTO archival_unit_authority VALUES(?,?)",[$id,$id]);
    Exact501::exec($db,"INSERT INTO desiderata_offers(id,title,author,donor_name,donor_email,status,created_at) VALUES(?,?,?,?,?,'pending',NOW())",[$id,$name,$name,$name,$name]);
}
$hooks=new App\Support\HookManager($db);
$public=new App\Plugins\MobileApi\Controllers\CollectionsController($db,$hooks);
$staff=new App\Plugins\MobileApi\Controllers\StaffArchivesController($db,$hooks);
$offers=new App\Plugins\MobileApi\Controllers\StaffDesiderataController($db,$hooks);
foreach(['haffner'=>[1,2],'"haffner"'=>[1],'"HÄFFNER"'=>[2],'%'=>[]] as $q=>$expected){
    $request=(new ServerRequestFactory())->createServerRequest('GET','/')->withQueryParams(['q'=>$q]);
    foreach([
        'archive collection'=>fn()=> $public->handle('archives',$request,new Response(),'archives'),
        'staff authorities'=>fn()=> $staff->authorities($request,new Response()),
        'staff donation offers'=>fn()=> $offers->offers($request,new Response()),
    ] as $label=>$read){
        $response=$read();
        Exact501::same(200,$response->getStatusCode(),"$label: $q returns 200");
        $payload=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
        $found=array_map('intval',array_column($payload['data'],'id'));sort($found);
        Exact501::same($expected,$found,"$label: $q");
    }
}
// CodeRabbit (PR #502): a query of quotes only must not drop the root-only
// filter and return the whole archive; mixed words keep each word's own rule.
foreach(['"'=>[],'""'=>[],'u1 "haffner"'=>[1],'"haffner" u1'=>[1],'u2 "haffner"'=>[],'u2 "häffner"'=>[2]] as $q=>$expected){
    $request=(new ServerRequestFactory())->createServerRequest('GET','/')->withQueryParams(['q'=>$q]);
    $response=$public->handle('archives',$request,new Response(),'archives');
    Exact501::same(200,$response->getStatusCode(),"archive collection: $q returns 200");
    $payload=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
    $found=array_map('intval',array_column($payload['data'],'id'));sort($found);
    Exact501::same($expected,$found,"archive collection: $q");
}
Exact501::finish(36);
