<?php
/**
 * Public article search.
 *
 * Wears the catalogue surface (public/assets/catalog-pages.css, opted in via
 * $catalogPageStyles): coloured hero, facet sidebar, results header, cover grid
 * and the same pagination, so /emeroteca/articoli reads as a sibling of
 * /catalogo rather than as a bare form. Everything here is plain links and a
 * GET form — the page works without JavaScript; the only script collapses the
 * sidebar on small screens, as the catalogue does.
 *
 * Besides the free term the page answers three narrowing filters — author,
 * publication, keyword — which are where the links on an article page lead.
 * An active filter is shown as a removable chip: a visitor who arrived from
 * "everything by this author" must be able to see why the list is short and
 * get back to the whole corpus in one click.
 *
 * Every variable below is supplied by PublicController::articles(); the
 * fallbacks exist because a view file is a public surface of the plugin and
 * must not fatal when required with less than that.
 */
$term=$term??''; $testata=$testata??0; $rows=$rows??[]; $page=$page??1; $pages=$pages??1; $total=$total??count($rows); $filters=$filters??[];
$fascicolo=(int)($fascicolo??0); $fascicoloLabel=(string)($fascicoloLabel??'');
$facets=($facets??[])+['testate'=>[],'pubblicazioni'=>[]];
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$filterLabels=['autore'=>__('Autore'),'pubblicazione'=>__('Pubblicazione'),'keyword'=>__('Parola chiave')];
/**
 * One definition of "empty" for every URL this page builds: the same `!== ''`
 * PublicController::articleFilters() and ContributionService::search() use.
 * PHP's default falsiness would also swallow the string '0', silently dropping
 * a filter that is still active (?keyword=0) from a link the visitor clicks.
 * `testata` and `fascicolo` are normalised first: 0 is their "none" sentinel.
 */
$keep=static fn(mixed $value):bool=>(string)$value!=='';
$idParam=static fn(int $id):string=>$id>0?(string)$id:'';
$testataId=(int)$testata;
$articlesPath=url('/emeroteca/articoli');
/** The current narrowing (term, masthead, issue, filters) with $changes applied; a '' value removes a key. */
$state=['q'=>$term,'testata'=>$idParam($testataId),'fascicolo'=>$idParam($fascicolo)]+$filters;
$stateUrl=static function(array $changes) use ($state,$keep,$articlesPath):string {
    $query=array_filter($changes+$state,$keep);
    return $articlesPath.($query?'?'.http_build_query($query):'');
};
$narrowed=array_filter($state,$keep)!==[];
$testataNames=array_column($facets['testate'],'titolo','id');
$corePartials=dirname(__DIR__, 6).'/app/Views/frontend/partials';
// The catalogue surface: hero, sidebar, grid and pagination (read by the layout).
$catalogPageStyles=true;
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.10.0')) ?>">

<?php
$heroTitle=__('Articoli');
$heroSubtitle=__('Articoli e contributi dei periodici della biblioteca');
$breadcrumbItems=[['label'=>__('Home'),'href'=>url('/')],['label'=>__('Emeroteca'),'href'=>url('/emeroteca')],['label'=>__('Articoli')]];
include $corePartials.'/catalog-hero.php';
?>

<div id="emeroteca-articoli" class="container emeroteca-public">
    <div class="flex flex-wrap -mx-3">
        <?php
        $filterSearch=[
            'action'=>$articlesPath,
            'value'=>$term,
            'label'=>__('Cerca titolo, autore o pubblicazione'),
            'hidden'=>['testata'=>$idParam($testataId),'fascicolo'=>$idParam($fascicolo)]+$filters,
        ];
        $filterSections=[
            ['title'=>__('Testate'),'icon'=>'fa-newspaper','options'=>array_map(static fn(array $f):array=>[
                'label'=>$f['titolo'],'count'=>(int)$f['n'],'href'=>$stateUrl(['testata'=>$f['id']===$testataId?'':(string)$f['id'],'fascicolo'=>'']),'active'=>$f['id']===$testataId,
            ],$facets['testate'])],
            ['title'=>__('Pubblicazioni'),'icon'=>'fa-book-open','options'=>array_map(static fn(array $f):array=>[
                'label'=>$f['nome'],'count'=>(int)$f['n'],'href'=>$stateUrl(['pubblicazione'=>($filters['pubblicazione']??'')===$f['nome']?'':$f['nome']]),'active'=>($filters['pubblicazione']??'')===$f['nome'],
            ],$facets['pubblicazioni'])],
        ];
        $filterClearHref=$narrowed?$articlesPath:'';
        include $corePartials.'/filters-sidebar.php';
        ?>

        <div class="catalog-results-column w-full lg:w-2/3 px-3 xl:w-3/4">
            <?php
            $activeFilters=[];
            if($term!==''){ $activeFilters[]=['label'=>__('Ricerca'),'value'=>$term,'removeHref'=>$stateUrl(['q'=>''])]; }
            if($testataId>0){ $activeFilters[]=['label'=>__('Testata'),'value'=>$testataNames[$testataId]??('#'.$testataId),'removeHref'=>$stateUrl(['testata'=>''])]; }
            if($fascicolo>0){ $activeFilters[]=['label'=>__('Fascicolo'),'value'=>$fascicoloLabel,'removeHref'=>$stateUrl(['fascicolo'=>''])]; }
            foreach($filters as $key=>$value){ $activeFilters[]=['label'=>$filterLabels[$key]??$key,'value'=>$value,'removeHref'=>$stateUrl([$key=>''])]; }
            $resultsCount=(int)$total;
            $resultsLabel=__n('articolo trovato','articoli trovati',(int)$total);
            include $corePartials.'/results-header.php';
            ?>

            <?php
            $articleResults=['rows'=>$rows];
            $articleEmpty=['title'=>__('Nessun risultato trovato'),'text'=>__('Prova a modificare i filtri o la tua ricerca'),'ctaHref'=>$narrowed?$articlesPath:'','ctaLabel'=>__('Mostra tutti gli articoli')];
            require __DIR__.'/article-results.php';
            ?>

            <?php
            $paginationPage=(int)$page;
            $paginationPages=(int)$pages;
            $paginationUrl=static fn(int $p):string=>$stateUrl(['page'=>(string)$p]);
            include $corePartials.'/pagination.php';
            ?>
        </div>
    </div>
</div>
