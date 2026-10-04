<?php
declare(strict_types=1);
/** Real 0.7.89 migration: adds libri.luogo_pubblicazione to an old schema, keeps the data, is idempotent and a no-op on a fresh install. */
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env'));
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($env['DB_HOST']??'localhost',$env['DB_USER'],$env['DB_PASS']??$env['DB_PASSWORD']??'',$env['DB_NAME'],(int)($env['DB_PORT']??3306),getenv('E2E_DB_SOCKET')?:($env['DB_SOCKET']??null));
$db->set_charset('utf8mb4');
$table='zz_place0789_'.bin2hex(random_bytes(4));
$count=0;
function checkPlaceMigration(bool $ok,string $label):void {global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "OK $label\n";}
$source=file_get_contents(dirname(__DIR__).'/installer/database/migrations/migrate_0.7.89.sql');
$source=preg_replace('/^--.*$/m','',$source);
$source=preg_replace('/\blibri\b/',$table,$source);
$run=static function()use($db,$source):void {foreach(explode(';',$source) as $sql)if(trim($sql)!=='')$db->query($sql);};
try {
    // The 0.7.88 shape of the columns around the new one.
    $db->query("CREATE TABLE $table (id INT NOT NULL PRIMARY KEY, titolo VARCHAR(255) NOT NULL, edizione VARCHAR(100) NULL, data_pubblicazione VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO $table VALUES (1,'Reaching a state of hope','1st ed.','2013')");
    $run();
    $column=$db->query("SHOW FULL COLUMNS FROM $table LIKE 'luogo_pubblicazione'")->fetch_assoc();
    checkPlaceMigration(is_array($column) && $column['Type']==='varchar(255)' && $column['Null']==='YES','adds a nullable VARCHAR(255) place of publication');
    checkPlaceMigration(($column['Collation']??'')==='utf8mb4_unicode_ci','in the table collation, so "Malmö" and "København" compare like the other text columns');
    $order=array_column($db->query("SHOW COLUMNS FROM $table")->fetch_all(MYSQLI_ASSOC),'Field');
    checkPlaceMigration(array_search('luogo_pubblicazione',$order,true)===array_search('edizione',$order,true)+1,'placed after the edition, as in schema.sql');
    checkPlaceMigration($db->query("SELECT titolo,edizione,luogo_pubblicazione FROM $table WHERE id=1")->fetch_row()===['Reaching a state of hope','1st ed.',null],'existing books keep their data and get no invented place');
    $db->query("UPDATE $table SET luogo_pubblicazione='Lund' WHERE id=1");
    $run();
    checkPlaceMigration($db->query("SELECT luogo_pubblicazione FROM $table WHERE id=1")->fetch_row()[0]==='Lund','a second run is a no-op and keeps a recorded place');
    $db->query("DROP TABLE $table");

    // A fresh install already has the column: the migration must do nothing.
    $schema=file_get_contents(dirname(__DIR__).'/installer/database/schema.sql');
    // libri's own comments contain semicolons, so take the column line, not the statement.
    $libri=substr($schema,(int)strpos($schema,'CREATE TABLE `libri`'));
    preg_match('/^\s*(`luogo_pubblicazione` varchar\(255\)[^\n]*?),?\s*$/m',$libri,$match);
    checkPlaceMigration(isset($match[1]),'schema.sql declares the same column for new installs');
    $db->query("CREATE TABLE $table (id INT NOT NULL PRIMARY KEY, edizione VARCHAR(100) NULL, {$match[1]}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $run();$run();
    checkPlaceMigration($db->query("SHOW COLUMNS FROM $table LIKE 'luogo_pubblicazione'")->num_rows===1,'the migration is a clean no-op after a fresh installation');

    $release=json_decode(file_get_contents(dirname(__DIR__).'/version.json'),true)['version'];
    checkPlaceMigration(version_compare('0.7.89',$release,'<='),'the release version includes the migration');
    echo "SUCCESS $count migration checks\n";
} finally { $db->query("DROP TABLE IF EXISTS $table"); }
