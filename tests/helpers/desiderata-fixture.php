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
    $like=$prefix.'%';
    $stmt=$db->prepare('SELECT id FROM libri WHERE titolo LIKE ? FOR UPDATE'); $stmt->bind_param('s',$like); $stmt->execute();
    foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $book) {
        $id=(int)$book['id']; $db->query("DELETE FROM copie WHERE libro_id=$id"); $db->query("DELETE FROM libri WHERE id=$id");
    }
    $db->commit(); App\Support\ContentCache::booksChanged();
    echo '{}';
} else { throw new InvalidArgumentException('Unknown operation'); }
