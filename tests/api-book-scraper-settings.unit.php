<?php
declare(strict_types=1);
/** Exercise scraper settings against a disposable MySQL table, including legacy HTTP URLs. */
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/storage/plugins/api-book-scraper/ApiBookScraperPlugin.php';
final class ScraperSettingsDb extends mysqli
{
    public string $prefix;
    public array $tables=['plugin_settings'];
    public function mapped(string $sql): string {
        foreach($this->tables as $name) { $sql=preg_replace('/\b'.preg_quote($name,'/').'\b/',$this->prefix.$name,$sql); }
        return $sql;
    }
    public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT): mysqli_result|bool { return parent::query($this->mapped($query),$result_mode); }
    public function prepare(string $query): mysqli_stmt|false { return parent::prepare($this->mapped($query)); }
}
$root=dirname(__DIR__); $env=Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new ScraperSettingsDb($env['DB_HOST']??'localhost',getenv('E2E_DB_USER')?:$env['DB_USER'],getenv('E2E_DB_PASS')?:($env['DB_PASS']??$env['DB_PASSWORD']),getenv('E2E_DB_NAME')?:$env['DB_NAME'],(int)($env['DB_PORT']??3306),getenv('E2E_DB_SOCKET')?:($env['DB_SOCKET']??null));
$db->prefix='zzscraper_'.bin2hex(random_bytes(3)).'_'; $db->set_charset('utf8mb4');

try {
    $db->query('CREATE TABLE plugin_settings (plugin_id INT, setting_key VARCHAR(100), setting_value TEXT, autoload TINYINT, UNIQUE KEY(plugin_id,setting_key)) ENGINE=InnoDB');
    $db->query("INSERT INTO plugin_settings VALUES (1,'api_endpoint','http://example.test/api',1),(1,'enabled','1',1)");
    $plugin=new ApiBookScraperPlugin();
    foreach(['db'=>$db,'pluginId'=>1,'apiEndpoint'=>'http://example.test/api','apiKey'=>'test-key','enabled'=>true] as $key=>$value) {
        (new ReflectionProperty($plugin,$key))->setValue($plugin,$value);
    }
    // Match both callers: modal posts '0', dedicated settings page posts false.
    foreach(['0',false] as $disabled) {
        if (!$plugin->saveSettings(['api_endpoint'=>'http://example.test/api','enabled'=>$disabled])) throw new RuntimeException('Disable failed');
        $stored=$db->query("SELECT setting_value FROM plugin_settings WHERE setting_key='enabled'")->fetch_row()[0];
        if ((bool)$stored) throw new RuntimeException('Disabled state was not persisted');
        echo "PASS disable legacy HTTP endpoint through form payload\n";
    }
    foreach([
        ['api_endpoint'=>'http://example.test/api','enabled'=>'1'],
        ['api_endpoint'=>'http://other.test/api','enabled'=>'0'],
        ['api_endpoint'=>'invalid','enabled'=>false],
    ] as $settings) {
        try { $plugin->saveSettings($settings); throw new RuntimeException('Invalid endpoint accepted'); }
        catch (InvalidArgumentException $e) { echo "PASS reject enabling HTTP or changing to an invalid endpoint\n"; }
    }
    if (!$plugin->saveSettings(['api_endpoint'=>'https://example.test/api','enabled'=>'0'])) throw new RuntimeException('HTTPS migration failed');
    $stored=$db->query("SELECT setting_value FROM plugin_settings WHERE setting_key='api_endpoint'")->fetch_row()[0];
    if ($stored!=='https://example.test/api') throw new RuntimeException('HTTPS endpoint was not persisted');
    echo "PASS replace legacy endpoint with HTTPS while disabled\n";
} finally {
    $db->query('DROP TABLE IF EXISTS plugin_settings');
    $db->close();
}
