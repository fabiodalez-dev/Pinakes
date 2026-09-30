<?php
declare(strict_types=1);
/*
 * Articles list (#412). Chrome copied from the book list
 * (app/Views/libri/index.php): breadcrumb, header with the actions on the
 * right and the count badge, one card holding the tabs, the filters and the
 * table; the table rows follow the core plain-table pattern
 * (app/Views/admin/imports_history.php).
 */
$source=$source??'autonomo'; $destination=$destination??0; $term=$term??''; $testata=$testata??0; $testate=$testate??[]; $total=$total??0; $rows=$rows??[]; $page=$page??1; $pages=$pages??1;
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$th='px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider';
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li><a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-home mr-1"></i><?= __('Home') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li><a href="<?= $e(url('/admin/periodicals')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-newspaper mr-1"></i><?= __('Emeroteca') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium" aria-current="page"><?= __('Articoli') ?></li>
      </ol>
    </nav>

    <div class="mb-6">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-gray-900"><?= __('Articoli') ?></h1>
          <p class="text-sm text-gray-600 mt-1"><?= __('Registra un articolo, anche senza possedere la rivista completa.') ?></p>
          <span class="status-badge bg-gray-100 text-gray-700 mt-3"><?= $e(sprintf(__('%d articoli'),$total)) ?></span>
        </div>
        <div class="flex flex-wrap items-center gap-2" id="articles-page-actions">
          <a class="btn-secondary" href="<?= $e(url('/admin/periodicals?view=titles')) ?>"><i class="fas fa-newspaper mr-2"></i><?= __('Testate') ?></a>
          <a class="btn-secondary" href="<?= $e(url('/admin/periodicals/articles/import')) ?>"><i class="fas fa-upload mr-2"></i><?= __('Importa CSV') ?></a>
          <a class="btn-secondary" href="<?= $e(url('/admin/periodicals/articles/export')) ?>" title="<?= $e(__('Le esportazioni grandi contengono più CSV in uno ZIP. Estrai e importa ogni CSV separatamente.')) ?>"><i class="fas fa-download mr-2"></i><?= __('Esporta articoli') ?></a>
          <a class="btn-primary" href="<?= $e(url('/admin/periodicals/articles/create')) ?>"><i class="fas fa-plus mr-2"></i><?= __('Aggiungi articolo') ?></a>
        </div>
      </div>
    </div>

    <?php $modeReturnTo='/admin/periodicals/articles'; require __DIR__.'/article-mode.php'; ?>

    <div class="card p-0 overflow-hidden">
      <nav class="settings-tabs" aria-label="<?= $e(__('Tipo di articolo')) ?>">
        <a class="settings-tab<?= $source==='autonomo'?' settings-tab-active':'' ?>" <?= $source==='autonomo'?'aria-current="page"':'' ?> href="<?= $e(url('/admin/periodicals/articles').'?'.http_build_query(['testata'=>$testata])) ?>"><i class="fas fa-file-alt mr-2"></i><?= __('Articoli autonomi') ?></a>
        <a class="settings-tab<?= $source==='spoglio'?' settings-tab-active':'' ?>" <?= $source==='spoglio'?'aria-current="page"':'' ?> href="<?= $e(url('/admin/periodicals/articles').'?'.http_build_query(['source'=>'spoglio','testata'=>$testata])) ?>"><i class="fas fa-layer-group mr-2"></i><?= __('Spoglio dei fascicoli') ?></a>
      </nav>

      <form method="get" class="p-4 border-b border-gray-100">
        <input type="hidden" name="source" value="<?= $e($source) ?>"><input type="hidden" name="destination" value="<?= $destination ?>">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
          <div><label for="article-search" class="form-label"><i class="fas fa-search mr-1"></i><?= __('Cerca titolo, autore o pubblicazione') ?></label><input id="article-search" name="q" value="<?= $e($term) ?>" class="form-input" maxlength="200"></div>
          <div><label for="article-host" class="form-label"><i class="fas fa-newspaper mr-1"></i><?= __('Testata') ?></label><select id="article-host" name="testata" class="form-input"><option value="0"><?= __('Tutte') ?></option><?php foreach($testate as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $testata===(int)$t['id']?'selected':'' ?>><?= $e($t['titolo']) ?></option><?php endforeach; ?></select></div>
          <div><button class="btn-secondary w-full md:w-auto"><i class="fas fa-search mr-2"></i><?= __('Cerca') ?></button></div>
        </div>
      </form>

      <form method="post" action="<?= $e(url('/admin/periodicals/articles/associate')) ?>">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
        <p class="px-6 pt-4 text-xs text-gray-500"><?= __('«Pubblicazione» è il titolo della rivista o del giornale scritto nella scheda dell’articolo; «Testata associata» è la scheda della rivista nell’Emeroteca, quando l’articolo vi è collegato.') ?></p>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr>
              <th scope="col" class="<?= $th ?>"><?= __('Seleziona') ?></th>
              <th scope="col" class="<?= $th ?>"><?= __('Articolo') ?></th>
              <th scope="col" class="<?= $th ?>"><?= __('Pubblicazione') ?></th>
              <th scope="col" class="<?= $th ?>"><?= __('Testata associata') ?></th>
              <th scope="col" class="<?= $th ?>"><?= __('Visibilità') ?></th>
            </tr></thead>
            <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach($rows as $r): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm"><?php if($source==='autonomo'): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500" aria-label="<?= $e(__('Seleziona').' '.$r['titolo']) ?>"><?php else: ?><span class="text-gray-500"><?= __('Spoglio') ?></span><?php endif; ?></td>
                <td class="px-6 py-4 text-sm"><a class="font-medium text-gray-900 hover:underline" href="<?= $e(url($source==='autonomo'?'/admin/periodicals/articles/'.(int)$r['id']:'/admin/periodicals/issue/'.(int)$r['fascicolo_id'])) ?>"><?= $e($r['titolo']) ?></a><?php if(trim((string)$r['autori'])!==''): ?><p class="text-xs text-gray-500 mt-1"><i class="fas fa-user mr-1"></i><?= $e($r['autori']) ?></p><?php endif; ?></td>
                <td class="px-6 py-4 text-sm text-gray-900"><?= $e($r['contenitore_titolo']) ?><p class="text-xs text-gray-500 mt-1"><?= $e(implode(' · ',array_filter([$r['data_pubblicazione_testo'],$r['volume'],$r['numero'],$r['pagine']]))) ?></p></td>
                <td class="px-6 py-4 text-sm text-gray-700"><?= $e($r['testata_titolo']??__('Non associato')) ?></td>
                <td class="px-6 py-4 text-sm"><span class="status-badge <?= $r['pubblico']?'bg-green-100 text-green-800':'bg-gray-100 text-gray-700' ?>"><?= $r['pubblico']?__('Pubblico'):__('Privato') ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$rows): ?>
              <tr><td colspan="5" class="px-6 py-12 text-center"><div class="flex flex-col items-center"><i class="fas fa-inbox text-gray-300 text-5xl mb-3"></i><p class="text-gray-500 text-sm"><?= __('Nessun articolo. Aggiungi il primo o importa un CSV.') ?></p></div></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if($source==='autonomo'): ?>
        <details class="border-t border-gray-200">
          <summary class="px-6 py-4 cursor-pointer"><span class="text-lg font-semibold text-gray-900"><i class="fas fa-link mr-2 text-gray-900" aria-hidden="true"></i><?= __('Associa gli articoli selezionati a una testata') ?></span></summary>
          <div class="px-6 pb-4 space-y-4">
            <p class="text-sm text-gray-600"><?= __('Scegli una testata o creala adesso. Citazioni, identificativi e allegati saranno conservati.') ?></p>
            <div class="form-grid-3">
              <div><label for="target-host" class="form-label"><?= __('Testata esistente') ?></label><select id="target-host" name="testata_id" class="form-input"><option value="0"><?= __('Nessuna testata') ?></option><?php foreach($testate as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $destination===(int)$t['id']?'selected':'' ?>><?= $e($t['titolo']) ?></option><?php endforeach; ?></select></div>
              <div><label for="new-host" class="form-label"><?= __('Oppure crea una testata') ?></label><input id="new-host" name="new_title" maxlength="255" class="form-input"></div>
              <div><label for="target-issue" class="form-label"><?= __('Fascicolo esistente (facoltativo)') ?></label><select id="target-issue" name="fascicolo_id" class="form-input" data-issues-url="<?= $e(url('/admin/periodicals/articles/issues')) ?>"><option value="0"><?= __('Solo testata') ?></option></select><p class="text-xs text-gray-500 mt-1"><?= __('Deve appartenere alla testata scelta. Nessun fascicolo viene creato automaticamente.') ?></p></div>
            </div>
            <div class="flex justify-end"><button class="btn-primary" type="submit"><i class="fas fa-eye mr-2"></i><?= __('Anteprima associazione') ?></button></div>
          </div>
        </details>
        <?php endif; ?>
      </form>
    </div>

    <?php if($pages>1): ?>
    <nav class="flex flex-wrap gap-2 mt-6 justify-center" aria-label="<?= $e(__('Paginazione')) ?>"><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?><a class="<?= $p===$page?'btn-primary':'btn-secondary' ?>" <?= $p===$page?'aria-current="page"':'' ?> href="<?= $e(url('/admin/periodicals/articles').'?'.http_build_query(['page'=>$p,'q'=>$term,'testata'=>$testata,'source'=>$source,'destination'=>$destination])) ?>"><?= $p ?></a><?php endfor; ?></nav>
    <?php endif; ?>
  </div>
</div>
<style>
  /* Same compact header buttons as the book list (.books-page-actions in
     app/Views/libri/index.php), so four actions fit on one row. */
  #articles-page-actions .btn-primary,
  #articles-page-actions .btn-secondary { min-height: 44px; padding: .65rem 1rem; white-space: nowrap; }
</style>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-articles.js?v=1.5.0')) ?>" defer></script>
