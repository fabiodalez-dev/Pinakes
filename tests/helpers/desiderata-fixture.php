<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
require $root.'/storage/plugins/desiderata/wrapper.php';
$tag=$argv[2]??'';
if(!preg_match('/^[a-f0-9]{12}$/D',$tag)) throw new InvalidArgumentException('Invalid test tag');
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$definitions=require $root.'/config/container.php'; $db=$definitions['db']();
$prefix='DWBrowser-'.$tag;
$email='dw-'.$tag.'@example.invalid';
/**
 * Where seed-extended parks what it is about to overwrite. Outside the repo on
 * purpose, and outside the argument list: the home_content snapshot carries
 * whole CMS pages, which have no business travelling through argv.
 */
function snapshot_path(string $tag): string { return sys_get_temp_dir().'/desiderata-browser-fixture-'.$tag.'.json'; }
$plugin=new DesiderataPlugin($db,new App\Support\HookManager($db));
if($argv[1]==='carousel') {
    // Render the production template, independent of the library's selected genres.
    $section=[];
    $genres_with_books=[];
    foreach(['Prosa','Poesia'] as $index=>$name) {
        $genres_with_books[]=['genre'=>['id'=>$index+1,'nome'=>$name], 'books'=>[
            ['id'=>1,'titolo'=>'Libro primo','autore'=>'Autore test'],
            ['id'=>2,'titolo'=>'Libro secondo','autore'=>'Autore test'],
            ['id'=>3,'titolo'=>'Libro terzo','autore'=>'Autore test'],
        ]];
    }
    ob_start(); require $root.'/app/Views/frontend/home-sections/genre_carousel.php'; $html=ob_get_clean();
    echo json_encode(['html'=>$html]);
} elseif($argv[1]==='seed') {
    $plugin->ensureSchema();
    $repo=new App\Models\BookRepository($db);
    $wanted=$repo->createBasic(['titolo'=>$prefix.' desiderata','copie_totali'=>0,'is_desiderata'=>1]);
    $normal=$repo->createBasic(['titolo'=>$prefix.' ordinario','copie_totali'=>0]);
    App\Support\ContentCache::booksChanged();
    echo json_encode(compact('wanted','normal','prefix','email'));
} elseif($argv[1]==='state') {
    $like=$prefix.'%';
    $stmt=$db->prepare('SELECT id, titolo, is_desiderata, copie_totali, copie_disponibili, (SELECT COUNT(*) FROM copie c WHERE c.libro_id=l.id) AS physical FROM libri l WHERE titolo LIKE ?');
    $stmt->bind_param('s',$like); $stmt->execute(); $books=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt=$db->prepare('SELECT id, status, book_id, received_book_id, copy_id, title FROM desiderata_offers WHERE donor_email=?');
    $stmt->bind_param('s',$email); $stmt->execute(); $offers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(compact('books','offers'));
} elseif($argv[1]==='cleanup') {
    $db->begin_transaction();
    $stmt=$db->prepare('DELETE FROM desiderata_offers WHERE donor_email=?'); $stmt->bind_param('s',$email); $stmt->execute();
    // The bell rows go too. Since the notifications were wired, every offer and
    // every receipt in a spec run wrote one, and nothing removed them — they
    // accumulated in the operator's real notification list run after run.
    // Matched on the run tag rather than on $prefix: the free-form offer this
    // spec sends is titled "Offerta libera <tag>" and carries no DWBrowser-
    // prefix, so a prefix sweep left its notification behind. The donor's email
    // is no use either — it never appears in a notification message.
    // The tag is validated as 12 hex chars at the top, so it holds no LIKE
    // wildcard, but ESCAPE stays: this statement deletes rows.
    $anywhere='%'.$tag.'%';
    $stmt=$db->prepare("DELETE FROM admin_notifications WHERE title LIKE ? ESCAPE '!' OR message LIKE ? ESCAPE '!'");
    $stmt->bind_param('ss',$anywhere,$anywhere); $stmt->execute();
    $like=$prefix.'%';
    $stmt=$db->prepare('SELECT id FROM libri WHERE titolo LIKE ? FOR UPDATE'); $stmt->bind_param('s',$like); $stmt->execute();
    foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $book) {
        $id=(int)$book['id']; $db->query("DELETE FROM copie WHERE libro_id=$id"); $db->query("DELETE FROM libri WHERE id=$id");
    }
    $db->commit(); App\Support\ContentCache::booksChanged();
    echo '{}';
}
// ---------------------------------------------------------------------------
// Actions below serve tests/desiderata-extended.spec.js. They are additive on
// purpose: the four above are driven by tests/desiderata.spec.js and must keep
// behaving exactly as they did.
// ---------------------------------------------------------------------------
elseif($argv[1]==='seed-extended') {
    $plugin->ensureSchema();
    $repo=new App\Models\BookRepository($db);
    // A cover that really exists on disk. A made-up path would 404 in the
    // browser, the view's onerror would rewrite src to the placeholder, and the
    // assertion on the seeded URL would fail for a reason that has nothing to
    // do with the code under test.
    //
    // WRITTEN, not hunted for. Borrowing whatever jpg the machine happened to
    // have in public/uploads/copertine passes on a developer's installation,
    // where demo covers are lying around, and fails on a clean runner, where the
    // directory holds nothing but the placeholder — reported as eight
    // "suspicious skips" and a missing-fixture error that says nothing about the
    // code. A tiny real JPEG, embedded rather than generated, so the fixture
    // does not depend on the GD extension either. cleanup-extended removes it.
    $coverDir=$root.'/public/uploads/copertine';
    if(!is_dir($coverDir) && !mkdir($coverDir,0775,true) && !is_dir($coverDir)) {
        throw new RuntimeException('cannot create '.$coverDir.' to seed a cover into');
    }
    $coverName='zz-desiderata-fixture-'.$tag.'.jpg';
    $coverBytes=base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFs'
        . 'aXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElD'
        . 'PEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7'
        . 'Ozs7/8AAEQgAPAAoAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQE'
        . 'AAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RV'
        . 'VldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY'
        . '2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQE'
        . 'AAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNU'
        . 'VVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW'
        . '19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A9AooorYyCiiigAooooAKKKKACiiigAooooAKKKKACiiigAoo'
        . 'ooAKKKKACiiigAooooA//9k=',
        true
    );
    if($coverBytes===false || file_put_contents($coverDir.'/'.$coverName,$coverBytes)===false) {
        throw new RuntimeException('cannot write the fixture cover to '.$coverDir);
    }
    $coverPath='/uploads/copertine/'.$coverName;
    $make=static function(string $suffix, bool $wanted, string $cover='') use ($repo,$db,$prefix): array {
        $data=['titolo'=>$prefix.' '.$suffix, 'copie_totali'=>$wanted?0:1];
        if($wanted) $data['is_desiderata']=1;
        if($cover!=='') $data['copertina_url']=$cover;
        $id=$repo->createBasic($data);
        // A raw INSERT leaves libri.search_index empty and the book invisible to
        // catalogue search and to the header preview, both of which match on the
        // FULLTEXT column — which looks exactly like a broken visibility predicate.
        App\Support\SearchIndexBuilder::rebuild($db,$id);
        return ['id'=>$id, 'titolo'=>$data['titolo'], 'path'=>book_path(['id'=>$id,'titolo'=>$data['titolo']]), 'cover'=>$cover];
    };
    $books=[
        'main'=>$make('principale', true),
        'donate'=>$make('donato', true),
        'cover'=>$make('con copertina', true, $coverPath),
        'nocover'=>$make('senza copertina', true),
        'xss'=>$make('<b>grassetto</b> iniezione', true),
        'held'=>$make('posseduto', false),
    ];
    // One proposal nobody has judged yet, so the sidebar pill and the "open
    // proposals" branch of the dashboard card have something real to show.
    $stmt=$db->prepare("INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, author, publisher, isbn, notes) VALUES (?, 'Donatore fixture', ?, ?, '', '', '', '')");
    $stmt->bind_param('iss',$books['main']['id'],$email,$books['main']['titolo']); $stmt->execute();
    $pendingOfferId=(int)$db->insert_id; $stmt->close();
    App\Support\ContentCache::booksChanged();
    // Everything this run will overwrite, so cleanup can put it back byte for byte.
    $settings=new App\Models\SettingsRepository($db);
    $snapshot=[
        'home_texts'=>(static function() use ($db) {
            $stmt=$db->prepare("SELECT setting_value FROM plugin_settings ps JOIN plugins p ON p.id=ps.plugin_id WHERE p.name='desiderata' AND ps.setting_key='home_texts' LIMIT 1");
            $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
            return is_array($row) ? (string)$row['setting_value'] : null;
        })(),
        'recaptcha_site_key'=>(string)$settings->get('contacts','recaptcha_site_key',''),
        'recaptcha_secret_key'=>(string)$settings->get('contacts','recaptcha_secret_key',''),
        // Submitting the CMS home form re-saves all eight core sections. Their
        // editorial columns are snapshotted here so cleanup can put back
        // anything that round trip failed to preserve — and say that it had to.
        'home_content'=>$db->query('SELECT * FROM home_content')->fetch_all(MYSQLI_ASSOC),
    ];
    file_put_contents(snapshot_path($tag), (string)json_encode($snapshot));
    echo json_encode([
        'prefix'=>$prefix, 'email'=>$email, 'books'=>$books, 'pendingOfferId'=>$pendingOfferId,
        'coverPath'=>$coverPath, 'placeholder'=>DesiderataPlugin::PLACEHOLDER_COVER,
        'installLocale'=>App\Support\I18n::getInstallationLocale(),
        'snapshot'=>snapshot_path($tag),
    ]);
} elseif($argv[1]==='state-extended') {
    $like=$prefix.'%';
    $stmt=$db->prepare('SELECT id, titolo, is_desiderata, copie_totali, copie_disponibili, (SELECT COUNT(*) FROM copie c WHERE c.libro_id=l.id) AS physical FROM libri l WHERE titolo LIKE ?');
    $stmt->bind_param('s',$like); $stmt->execute(); $books=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $ids=array_map(static fn(array $b): int => (int)$b['id'], $books);
    $in=$ids===[] ? '0' : implode(',',$ids);
    // Offers of this run reach the table two ways: the donor form (tagged
    // e-mail) and the direct receipt, whose audit row has NO donor at all.
    $stmt=$db->prepare("SELECT id, status, book_id, received_book_id, copy_id, title, donor_name, donor_email FROM desiderata_offers WHERE donor_email=? OR book_id IN ($in)");
    $stmt->bind_param('s',$email); $stmt->execute(); $offers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $offerIds=array_map(static fn(array $o): int => (int)$o['id'], $offers);
    $notifications=[];
    foreach($ids as $id) {
        $stmt=$db->prepare('SELECT id, type, title, link, related_id FROM admin_notifications WHERE link LIKE ?');
        $needle='%/admin/books/'.$id.'#%'; $stmt->bind_param('s',$needle); $stmt->execute();
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) { $notifications[]=$row; }
        $stmt->close();
    }
    foreach($offerIds as $offerId) {
        $stmt=$db->prepare("SELECT id, type, title, link, related_id FROM admin_notifications WHERE related_id=? AND link LIKE '%/admin/desiderata%'");
        $stmt->bind_param('i',$offerId); $stmt->execute();
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) { $notifications[]=$row; }
        $stmt->close();
    }
    $home=$db->query("SELECT id, section_key, title, is_active, display_order FROM home_content WHERE section_key='desiderata'")->fetch_assoc();
    $plugin_row=$db->query("SELECT id, version, is_active FROM plugins WHERE name='desiderata'")->fetch_assoc();
    $hooks=(int)($db->query("SELECT COUNT(*) n FROM plugin_hooks ph JOIN plugins p ON p.id=ph.plugin_id WHERE p.name='desiderata'")->fetch_assoc()['n'] ?? 0);
    $pendingTotal=(int)($db->query("SELECT COUNT(*) n FROM desiderata_offers WHERE status='pending'")->fetch_assoc()['n'] ?? 0);
    $texts=$db->query("SELECT ps.setting_value v FROM plugin_settings ps JOIN plugins p ON p.id=ps.plugin_id WHERE p.name='desiderata' AND ps.setting_key='home_texts'")->fetch_assoc();
    echo json_encode([
        'books'=>$books, 'offers'=>$offers, 'notifications'=>$notifications, 'pendingTotal'=>$pendingTotal,
        'home'=>$home ?: null, 'plugin'=>$plugin_row ?: null, 'hooks'=>$hooks,
        'homeTexts'=>is_array($texts) ? $texts['v'] : null,
        // `digest` folds in every operator-visible column: submitting the whole
        // CMS form (T5) re-saves all eight core sections, and a field the form
        // fails to round-trip would quietly blank a section nobody looked at.
        // Hashed here rather than in SQL — MySQL 9 no longer ships MD5().
        'homeContent'=>array_map(static function(array $row): array {
            $keep=['section_key'=>$row['section_key'], 'is_active'=>$row['is_active'], 'display_order'=>$row['display_order']];
            unset($row['id'], $row['section_key'], $row['is_active'], $row['display_order'], $row['created_at'], $row['updated_at']);
            $keep['digest']=md5((string)json_encode($row));
            return $keep;
        }, $db->query('SELECT * FROM home_content ORDER BY section_key')->fetch_all(MYSQLI_ASSOC)),
    ]);
} elseif($argv[1]==='recaptcha') {
    // The write path SettingsController::updateContactSettings() uses. Raw SQL
    // on system_settings would leave ConfigStore serving its 60-second cache,
    // so the plugin would keep reading the old key and the test would prove
    // nothing; SettingsRepository::set() + ConfigStore::set() drop that cache.
    $settings=new App\Models\SettingsRepository($db); $settings->ensureTables();
    $site=(string)($argv[3] ?? ''); $secret=(string)($argv[4] ?? '');
    foreach(['recaptcha_site_key'=>$site, 'recaptcha_secret_key'=>$secret] as $key=>$value) {
        $settings->set('contacts',$key,$value);
        App\Support\ConfigStore::set("contacts.$key",$value);
    }
    App\Support\ConfigStore::clearCache();
    echo json_encode(['recaptcha_site_key'=>$settings->get('contacts','recaptcha_site_key',''), 'recaptcha_secret_key'=>$settings->get('contacts','recaptcha_secret_key','')]);
} elseif($argv[1]==='cleanup-extended') {
    // The cover this run wrote, and only that one: named after the run tag so a
    // parallel shard's file is never touched.
    $ownCover=$root.'/public/uploads/copertine/zz-desiderata-fixture-'.$tag.'.jpg';
    if(is_file($ownCover)) { @unlink($ownCover); }
    $file=snapshot_path($tag);
    $snapshot=is_file($file) ? json_decode((string)file_get_contents($file),true) : null;
    if(!is_array($snapshot)) throw new InvalidArgumentException('cleanup-extended cannot find the snapshot seed-extended wrote at '.$file);
    $like=$prefix.'%';
    $stmt=$db->prepare('SELECT id FROM libri WHERE titolo LIKE ?'); $stmt->bind_param('s',$like); $stmt->execute();
    $ids=array_map(static fn(array $b): int => (int)$b['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC)); $stmt->close();
    $in=$ids===[] ? '0' : implode(',',$ids);
    $stmt=$db->prepare("SELECT id FROM desiderata_offers WHERE donor_email=? OR book_id IN ($in)");
    $stmt->bind_param('s',$email); $stmt->execute();
    $offerIds=array_map(static fn(array $o): int => (int)$o['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC)); $stmt->close();
    // The bell rows this run produced, and only those: a receipt is addressed by
    // the book it names, a proposal by the offer id it carries.
    foreach($ids as $id) {
        $stmt=$db->prepare('DELETE FROM admin_notifications WHERE link LIKE ?');
        $needle='%/admin/books/'.$id.'#%'; $stmt->bind_param('s',$needle); $stmt->execute(); $stmt->close();
    }
    foreach($offerIds as $offerId) {
        $stmt=$db->prepare("DELETE FROM admin_notifications WHERE related_id=? AND link LIKE '%/admin/desiderata%'");
        $stmt->bind_param('i',$offerId); $stmt->execute(); $stmt->close();
    }
    $db->begin_transaction();
    $stmt=$db->prepare("DELETE FROM desiderata_offers WHERE donor_email=? OR book_id IN ($in)");
    $stmt->bind_param('s',$email); $stmt->execute(); $stmt->close();
    foreach($ids as $id) { $db->query("DELETE FROM copie WHERE libro_id=$id"); $db->query("DELETE FROM libri WHERE id=$id"); }
    $db->commit();
    App\Support\ContentCache::booksChanged();
    // Put the installation back exactly as it was found.
    $pluginId=(int)($db->query("SELECT id FROM plugins WHERE name='desiderata'")->fetch_assoc()['id'] ?? 0);
    if($pluginId>0) {
        if($snapshot['home_texts']===null) {
            $stmt=$db->prepare("DELETE FROM plugin_settings WHERE plugin_id=? AND setting_key='home_texts'");
            $stmt->bind_param('i',$pluginId); $stmt->execute(); $stmt->close();
        } else {
            $value=(string)$snapshot['home_texts'];
            $stmt=$db->prepare('INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, autoload, created_at) VALUES (?, ?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()');
            $key='home_texts'; $stmt->bind_param('iss',$pluginId,$key,$value); $stmt->execute(); $stmt->close();
        }
    }
    $settings=new App\Models\SettingsRepository($db);
    foreach(['recaptcha_site_key','recaptcha_secret_key'] as $key) {
        $value=(string)($snapshot[$key] ?? '');
        $settings->set('contacts',$key,$value);
        App\Support\ConfigStore::set("contacts.$key",$value);
    }
    App\Support\ConfigStore::clearCache();
    // The editorial columns of the core sections, exactly as they were before
    // the CMS form was submitted. `restored` should come back empty: a
    // non-empty list means saving the page changed a section nobody edited.
    $restored=[];
    $editorial=['title','subtitle','content','button_text','button_link','background_image','seo_title','seo_description','seo_keywords','og_image','og_title','og_description','og_type','og_url','twitter_card','twitter_title','twitter_description','twitter_image'];
    foreach(is_array($snapshot['home_content'] ?? null) ? $snapshot['home_content'] : [] as $row) {
        $key=(string)$row['section_key'];
        $stmt=$db->prepare('SELECT * FROM home_content WHERE section_key=? LIMIT 1');
        $stmt->bind_param('s',$key); $stmt->execute(); $now=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!is_array($now)) { continue; }
        $drift=array_values(array_filter($editorial, static fn(string $c): bool => ($now[$c] ?? null) !== ($row[$c] ?? null)));
        if($drift===[]) { continue; }
        $restored[]=['section_key'=>$key, 'columns'=>$drift];
        $set=implode(', ', array_map(static fn(string $c): string => "$c = ?", $drift));
        $stmt=$db->prepare("UPDATE home_content SET $set WHERE section_key = ?");
        $values=array_map(static fn(string $c) => $row[$c], $drift);
        $values[]=$key;
        $stmt->bind_param(str_repeat('s',count($values)), ...$values);
        $stmt->execute(); $stmt->close();
    }
    App\Support\ContentCache::homeContentChanged();
    @unlink($file);
    echo json_encode(['restored'=>$restored]);
} else { throw new InvalidArgumentException('Unknown operation'); }
