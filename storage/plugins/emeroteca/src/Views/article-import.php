<?php
/*
 * Article CSV import (#412). Chrome copied from the core CSV import
 * (app/Views/admin/csv_import.php): breadcrumb, header, the upload in a card
 * through Uppy, then the preview and the report as cards with core tables.
 */
$preview=$preview??null; $report=$report??null; $token=$token??''; $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
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
        <li><a href="<?= $e(url('/admin/periodicals/articles')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><?= __('Articoli') ?></a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium"><i class="fas fa-file-csv mr-1"></i><?= __('Import CSV') ?></li>
      </ol>
    </nav>

    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= __('Importa articoli') ?></h1>
      <p class="text-gray-600"><?= __('CSV UTF-8 separato da virgole, massimo 500 righe. La reference_key identifica gli aggiornamenti; le colonne assenti conservano i dati, le celle vuote li svuotano. Nessun fascicolo viene creato.') ?></p>
    </div>

    <div class="space-y-8">
    <?php if($report!==null): ?>
      <div class="card p-0 overflow-hidden">
        <div class="p-6 border-b border-gray-200"><h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-clipboard-check text-gray-900" aria-hidden="true"></i><?= __('Risultato importazione') ?></h2></div>
        <ul class="divide-y divide-gray-200">
          <?php foreach($report as $r): $ok=empty($r['error']); ?><li class="px-6 py-3 text-sm flex items-center gap-3"><span class="status-badge <?= $ok?'bg-green-100 text-green-800':'bg-red-100 text-red-800' ?>"><?= __('Riga') ?> <?= (int)$r['line'] ?></span><span class="text-gray-700"><?= $e($r['error']??__('Salvato')) ?></span></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if($preview!==null): ?>
      <div class="card p-0 overflow-hidden">
        <div class="p-6 border-b border-gray-200"><h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-eye text-gray-900" aria-hidden="true"></i><?= __('Anteprima: destinazione Emeroteca') ?></h2></div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr><th scope="col" class="<?= $th ?>"><?= __('Riga') ?></th><th scope="col" class="<?= $th ?>"><?= __('Titolo') ?></th><th scope="col" class="<?= $th ?>"><?= __('Operazione') ?></th><th scope="col" class="<?= $th ?>"><?= __('Verifica') ?></th></tr></thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach($preview as $r): ?><tr class="hover:bg-gray-50"><td class="px-6 py-4 text-sm text-gray-500"><?= (int)$r['line'] ?></td><td class="px-6 py-4 text-sm text-gray-900"><?= $e($r['data']['titolo']??'') ?></td><td class="px-6 py-4 text-sm text-gray-700"><?= $r['id']?__('Aggiorna'):__('Crea') ?></td><td class="px-6 py-4 text-sm"><?php $state=!empty($r['error'])?'error':(!empty($r['warning'])?'warning':'ok'); ?><span class="status-badge <?= $state==='error'?'bg-red-100 text-red-800':($state==='warning'?'bg-yellow-100 text-yellow-800':'bg-green-100 text-green-800') ?>"><?= $e($r['error']??$r['warning']??__('Pronto')) ?></span></td></tr><?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <form method="post" class="p-6 border-t border-gray-200 flex flex-col sm:flex-row gap-4 justify-end">
          <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>"><input type="hidden" name="token" value="<?= $e($token) ?>">
          <a class="btn-secondary order-2 sm:order-1" href="<?= $e(url('/admin/periodicals/articles/import')) ?>"><i class="fas fa-times mr-2"></i><?= __('Annulla') ?></a>
          <button class="btn-primary order-1 sm:order-2"><i class="fas fa-file-import mr-2"></i><?= __('Importa le righe valide') ?></button>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-header"><h2 class="form-section-title flex items-center gap-2"><i class="fas fa-upload text-gray-900" aria-hidden="true"></i><?= __('Carica File CSV') ?></h2></div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
            <div id="articles-csv-upload" data-emt-uppy="file" data-types=".csv,text/csv,application/csv" data-max-bytes="10485760" data-input="articles-csv" data-progress="articles-csv-progress" data-result="articles-csv-result" data-note="<?= $e(__('File CSV')) ?>" data-drop="<?= $e(__('Trascina qui il file o %{browse}')) ?>" data-browse="<?= $e(__('seleziona file')) ?>"></div>
            <div id="articles-csv-progress"></div>
            <p id="articles-csv-result" class="flex items-center gap-2 text-sm text-gray-600" hidden></p>
            <input id="articles-csv" name="csv" type="file" accept=".csv,text/csv" hidden>
            <div class="flex justify-end"><button class="btn-primary"><i class="fas fa-eye mr-2"></i><?= __('Mostra anteprima') ?></button></div>
          </form>
        </div>
      </div>
    <?php endif; ?>

      <div class="card">
        <div class="card-header"><h2 class="form-section-title flex items-center gap-2"><i class="fas fa-info-circle text-gray-900" aria-hidden="true"></i><?= __('Formato del file') ?></h2></div>
        <div class="card-body space-y-4 text-sm text-gray-700">
          <p><?= __('Le esportazioni grandi contengono più CSV in uno ZIP. Estrai e importa ogni CSV separatamente.') ?></p>
          <p class="text-gray-600"><?= __('Il CSV conserva i valori originali per il reimport. Nei fogli di calcolo importa le colonne come testo.') ?></p>
          <div>
            <p class="form-label"><?= __('Esempio') ?></p>
            <pre class="text-xs font-mono bg-gray-50 border border-gray-200 rounded-lg p-3 overflow-x-auto">record_type,titolo,autori,contenitore_titolo,anno_pubblicazione,volume,numero,pagine
journal_article,Intertextuality in Daniel Kehlmann&#039;s Novel Tyll,&quot;Schweissinger, Marc J.&quot;,International Journal of Language and Literature,2019,7,1,138-148</pre>
          </div>
          <div class="flex flex-wrap gap-2">
            <a class="btn-secondary" href="<?= $e(url('/admin/periodicals/articles/export?template=1')) ?>"><i class="fas fa-download mr-2"></i><?= __('Scarica il CSV come modello') ?></a>
            <a class="btn-secondary" href="<?= $e(url('/admin/periodicals/articles')) ?>"><i class="fas fa-arrow-left mr-2"></i><?= __('Articoli') ?></a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-upload.js?v=1.3.0')) ?>" defer></script>
