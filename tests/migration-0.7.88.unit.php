<?php
declare(strict_types=1);
/** Real GND migration: preserves old people, permits unknown identities, prevents duplicate GNDs and is idempotent. */
require dirname(__DIR__).'/vendor/autoload.php';
$env=Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env'));
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($env['DB_HOST']??'localhost',$env['DB_USER'],$env['DB_PASS']??$env['DB_PASSWORD']??'',$env['DB_NAME'],(int)($env['DB_PORT']??3306),getenv('E2E_DB_SOCKET')?:($env['DB_SOCKET']??null));
$db->set_charset('utf8mb4');
$table='zz_gnd0788_'.bin2hex(random_bytes(4));
$count=0;
function checkGndMigration(bool $ok,string $label):void {global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "OK $label\n";}
$source=file_get_contents(dirname(__DIR__).'/installer/database/migrations/migrate_0.7.88.sql');
$source=preg_replace('/^--.*$/m','',$source);
$source=preg_replace('/\bautori\b/',$table,$source);
$run=static function()use($db,$source):void {foreach(explode(';',$source) as $sql)if(trim($sql)!=='')$db->query($sql);};
try {
    $db->query("CREATE TABLE $table (id INT NOT NULL PRIMARY KEY, nome VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO $table VALUES (1,'First homonym'),(2,'Second homonym')");
    $run();
    $column=$db->query("SHOW COLUMNS FROM $table LIKE 'gnd_id'")->fetch_assoc();
    checkGndMigration($column['Type']==='varchar(32)' && $column['Null']==='YES','adds nullable GND with fresh-schema type');
    checkGndMigration((int)$db->query("SELECT COUNT(*) FROM $table WHERE gnd_id IS NULL")->fetch_row()[0]===2,'legacy people remain distinct without invented authority IDs');
    $index=$db->query("SHOW INDEX FROM $table WHERE Key_name='uq_autori_gnd'")->fetch_all(MYSQLI_ASSOC);
    checkGndMigration(count($index)===1 && (int)$index[0]['Non_unique']===0 && $index[0]['Column_name']==='gnd_id','GND identity uniqueness is enforced');
    $db->query("UPDATE $table SET gnd_id='118559792' WHERE id=1");
    $duplicateRejected=false;
    try {$db->query("UPDATE $table SET gnd_id='118559792' WHERE id=2");}catch(mysqli_sql_exception $e){$duplicateRejected=$e->getCode()===1062;}
    checkGndMigration($duplicateRejected,'two core people cannot claim the same confirmed GND');
    $run();
    checkGndMigration($db->query("SELECT nome,gnd_id FROM $table WHERE id=1")->fetch_row()===['First homonym','118559792'],'repeated migration preserves names and confirmed authority');
    $db->query("DROP TABLE $table");
    $schema=file_get_contents(dirname(__DIR__).'/installer/database/schema.sql');
    preg_match('/CREATE TABLE `autori` \(.*?;\s*/s',$schema,$match);
    $db->query(str_replace('`autori`',"`$table`",$match[0]));
    $run();$run();
    checkGndMigration($db->query("SHOW COLUMNS FROM $table LIKE 'gnd_id'")->num_rows===1,'migration is a clean no-op after fresh installation');
    checkGndMigration(App\Support\GndIdentifier::normalize('https://d-nb.info/gnd/118559792')==='118559792','normalizes an authority URL to a canonical ID');
    checkGndMigration(App\Support\GndIdentifier::normalize('4079154-3')==='4079154-3','accepts historical hyphenated GNDs');
    checkGndMigration(App\Support\GndIdentifier::normalize('')===null,'empty manual input clears the optional authority');
    $invalid=false;try{App\Support\GndIdentifier::normalize('https://example.org/118559792');}catch(InvalidArgumentException){$invalid=true;}
    checkGndMigration($invalid,'rejects unrelated URLs as GND identities');
    $release=json_decode(file_get_contents(dirname(__DIR__).'/version.json'),true)['version'];
    checkGndMigration(version_compare('0.7.88',$release,'<='),'release version includes the migration');
    echo "SUCCESS $count migration checks\n";
} finally { $db->query("DROP TABLE IF EXISTS $table"); }
