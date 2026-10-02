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
$facets=($facets??[])+['testate'=>[],'pubblicazioni'=>[]];
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$filterLabels=['autore'=>__('Autore'),'pubblicazione'=>__('Pubblicazione'),'keyword'=>__('Parola chiave')];
/**
 * One definition of "empty" for every URL this page builds: the same `!== ''`
 * PublicController::articleFilters() and ContributionService::search() use.
 * PHP's default falsiness would also swallow the string '0', silently dropping
 * a filter that is still active (?keyword=0) from a link the visitor clicks.
 * `testata` is normalised first: 0 is its "no masthead" sentinel, not a value.
 */
$keep=static fn(mixed $value):bool=>(string)$value!=='';
$testataParam=static fn(int $id):string=>$id>0?(string)$id:'';
$testataId=(int)$testata;
$articlesPath=url('/emeroteca/articoli');
/** The current narrowing (term, masthead, filters) with $changes applied; a '' value removes a key. */
$state=['q'=>$term,'testata'=>$testataParam($testataId)]+$filters;
$stateUrl=static function(array $changes) use ($state,$keep,$articlesPath):string {
    $query=array_filter($changes+$state,$keep);
    return $articlesPath.($query?'?'.http_build_query($query):'');
};
$narrowed=array_filter($state,$keep)!==[];
$testataNames=array_column($facets['testate'],'titolo','id');
// The catalogue surface: hero, sidebar, grid and pagination (read by the layout).
$catalogPageStyles=true;
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.6.0')) ?>">

<section class="catalog-header">
    <div class="container">
        <div class="catalog-header-content text-center">
            <h1 class="catalog-title"><?= __('Articoli') ?></h1>
            <p class="catalog-subtitle"><?= __('Articoli e contributi dei periodici della biblioteca') ?></p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb flex flex-wrap items-center gap-2 justify-center bg-transparent p-0 mb-0">
                    <li class="breadcrumb-item"><a href="<?= $e(url('/')) ?>" class="text-white opacity-75"><?= __('Home') ?></a></li>
                    <li class="breadcrumb-item"><a href="<?= $e(url('/emeroteca')) ?>" class="text-white opacity-75"><?= __('Emeroteca') ?></a></li>
                    <li class="breadcrumb-item text-white active" aria-current="page"><?= __('Articoli') ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<main id="emeroteca-articoli" class="container emeroteca-public">
    <div class="flex flex-wrap -mx-3">
        <div class="catalog-filters-column w-full lg:w-1/3 px-3 xl:w-1/4 mb-4">
            <div class="filters-panel">
                <div class="filters-header">
                    <h2 class="filters-title"><i class="fas fa-filter" aria-hidden="true"></i> <?= __('Filtri') ?></h2>
                    <button type="button" class="filters-mobile-toggle" id="catalog-filters-toggle" aria-controls="catalog-filters-content" aria-expanded="true" aria-label="<?= $e(__('Filtri')) ?>"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                </div>
                <div class="filters-content" id="catalog-filters-content">
                    <div class="filter-section">
                        <div class="filter-title"><i class="fas fa-search" aria-hidden="true"></i> <?= __('Ricerca') ?></div>
                        <form class="search-box" method="get" action="<?= $e($articlesPath) ?>" role="search">
                            <label for="article-q" class="sr-only"><?= __('Cerca titolo, autore o pubblicazione') ?></label>
                            <input id="article-q" type="search" name="q" maxlength="200" value="<?= $e($term) ?>" placeholder="<?= $e(__('Cerca titolo, autore o pubblicazione')) ?>">
                            <?php if($testataId>0): ?><input type="hidden" name="testata" value="<?= $testataId ?>"><?php endif; ?>
                            <?php foreach($filters as $key=>$value): ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach; ?>
                            <svg class="svg-inline--fa fa-magnifying-glass" role="img" viewBox="0 0 512 512" aria-hidden="true"><path fill="currentColor" d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376C296.3 401.1 253.9 416 208 416 93.1 416 0 322.9 0 208S93.1 0 208 0 416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"></path></svg>
                            <button type="submit" class="sr-only"><?= __('Cerca') ?></button>
                        </form>
                    </div>

                    <?php if($facets['testate']): ?>
                    <div class="filter-section">
                        <div class="filter-title"><i class="fas fa-newspaper" aria-hidden="true"></i> <?= __('Testate') ?></div>
                        <div class="filter-options">
                            <?php foreach($facets['testate'] as $facet): $active=$facet['id']===$testataId; ?>
                                <a class="filter-option count<?= $active?' active':'' ?>" href="<?= $e($stateUrl(['testata'=>$active?'':(string)$facet['id']])) ?>"<?= $active?' aria-current="true"':'' ?> title="<?= $e($facet['titolo']) ?>">
                                    <span><?= $e($facet['titolo']) ?></span>
                                    <span class="count-badge"><?= (int)$facet['n'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($facets['pubblicazioni']): ?>
                    <div class="filter-section">
                        <div class="filter-title"><i class="fas fa-book-open" aria-hidden="true"></i> <?= __('Pubblicazioni') ?></div>
                        <div class="filter-options">
                            <?php foreach($facets['pubblicazioni'] as $facet): $active=($filters['pubblicazione']??'')===$facet['nome']; ?>
                                <a class="filter-option count<?= $active?' active':'' ?>" href="<?= $e($stateUrl(['pubblicazione'=>$active?'':$facet['nome']])) ?>"<?= $active?' aria-current="true"':'' ?> title="<?= $e($facet['nome']) ?>">
                                    <span><?= $e($facet['nome']) ?></span>
                                    <span class="count-badge"><?= (int)$facet['n'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($narrowed): ?>
                    <div class="filter-section">
                        <a class="clear-all-btn" href="<?= $e($articlesPath) ?>"><i class="fas fa-times" aria-hidden="true"></i> <?= __('Pulisci tutti i filtri') ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="catalog-results-column w-full lg:w-2/3 px-3 xl:w-3/4">
            <?php if($narrowed): ?>
            <div class="active-filters">
                <div class="active-filters-title"><?= __('Filtri attivi:') ?></div>
                <div class="filter-tags">
                    <?php
                    $chips=[];
                    if($term!==''){ $chips[]=[__('Ricerca'),$term,'q']; }
                    if($testataId>0){ $chips[]=[__('Testata'),$testataNames[$testataId]??('#'.$testataId),'testata']; }
                    foreach($filters as $key=>$value){ $chips[]=[$filterLabels[$key]??$key,$value,$key]; }
                    foreach($chips as [$label,$value,$key]): ?>
                        <span class="filter-tag"><?= $e($label.': '.$value) ?> <a class="filter-tag-remove" href="<?= $e($stateUrl([$key=>''])) ?>" aria-label="<?= $e(__('Rimuovi filtro').' '.$label) ?>" title="<?= $e(__('Rimuovi filtro')) ?>">&times;</a></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="results-header">
                <div class="results-info"><strong><?= number_format((int)$total) ?></strong> <span><?= $e(__n('articolo trovato', 'articoli trovati', (int)$total)) ?></span></div>
                <?php if($narrowed): ?><a class="clear-filters-top-btn" href="<?= $e($articlesPath) ?>" title="<?= $e(__('Rimuovi tutti i filtri')) ?>"><i class="fas fa-filter-circle-xmark" aria-hidden="true"></i> <span class="clear-filters-text"><?= __('Pulisci filtri') ?></span></a><?php endif; ?>
            </div>

            <?php $articleResults=['rows'=>$rows]; $articleResultsVariant='grid'; $q=$term; $testata=['id'=>$testataId]; require __DIR__.'/article-results.php'; ?>

            <?php if($pages>1):
                $start=max(1,$page-2); $end=min($pages,$start+4); $start=max(1,$end-4); ?>
            <div class="mt-4">
                <nav aria-label="<?= $e(__('Navigazione pagine')) ?>"><ul class="pagination justify-center">
                    <?php if($page>1): ?><li class="page-item"><a class="page-link" href="<?= $e($stateUrl(['page'=>(string)($page-1)])) ?>" title="<?= $e(__('Pagina precedente')) ?>" aria-label="<?= $e(__('Pagina precedente')) ?>"><i class="fas fa-chevron-left" aria-hidden="true"></i></a></li><?php endif; ?>
                    <?php for($p=$start;$p<=$end;$p++): ?><li class="page-item<?= $p===$page?' active':'' ?>"><a class="page-link" href="<?= $e($stateUrl(['page'=>(string)$p])) ?>"<?= $p===$page?' aria-current="page"':'' ?>><?= $p ?></a></li><?php endfor; ?>
                    <?php if($page<$pages): ?><li class="page-item"><a class="page-link" href="<?= $e($stateUrl(['page'=>(string)($page+1)])) ?>" title="<?= $e(__('Pagina successiva')) ?>" aria-label="<?= $e(__('Pagina successiva')) ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></a></li><?php endif; ?>
                </ul></nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php
// Same small-screen behaviour as the catalogue sidebar: collapsed behind its
// toggle at <=768px, always open above. Without JS the panel simply stays open.
$additional_js=($additional_js??'').<<<'JS'
<script>
(() => {
    const toggle = document.getElementById('catalog-filters-toggle');
    const content = document.getElementById('catalog-filters-content');
    if (!toggle || !content) return;
    const mobile = window.matchMedia('(max-width: 768px)');
    const sync = () => {
        if (mobile.matches) {
            content.hidden = toggle.getAttribute('aria-expanded') !== 'true';
        } else {
            content.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }
    };
    if (mobile.matches) toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        content.hidden = expanded;
    });
    if (typeof mobile.addEventListener === 'function') mobile.addEventListener('change', sync); else mobile.addListener(sync);
    sync();
})();
</script>
JS;
