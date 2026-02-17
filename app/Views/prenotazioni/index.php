<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700"><i class="fas fa-home mr-1"></i>Home</a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium"><?= __("Prenotazioni") ?></li>
      </ol>
    </nav>
    <div class="mb-6">
      <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3"><i class="fas fa-bookmark text-blue-600"></i><?= __("Gestione Prenotazioni") ?></h1>
      <?php if (!empty($_GET['updated'])): ?><div class="mt-3 p-3 bg-green-50 text-green-700 rounded border border-green-200" role="alert"><?= __("Prenotazione aggiornata.") ?></div><?php endif; ?>
    </div>
    <div class="bg-white border border-gray-200 rounded-2xl shadow p-4 mb-4">
      <form method="get" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
        <div class="relative">
          <label class="form-label"><?= __("Filtro Libro") ?></label>
          <input type="text" id="admin_filter_libro" name="q_libro" class="form-input" placeholder="<?= __('Titolo libro') ?>" value="<?php echo htmlspecialchars((string)($q_libro ?? ''), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
          <ul id="admin_filter_libro_suggest" class="autocomplete-suggestions"></ul>
          <input type="hidden" id="libro_id" name="libro_id" value="<?php echo (int)($libro_id ?? 0); ?>">
        </div>
        <div class="relative">
          <label class="form-label"><?= __("Filtro Utente") ?></label>
          <input type="text" id="admin_filter_utente" name="q_utente" class="form-input" placeholder="<?= __('Nome Cognome') ?>" value="<?php echo htmlspecialchars((string)($q_utente ?? ''), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
          <ul id="admin_filter_utente_suggest" class="autocomplete-suggestions"></ul>
          <input type="hidden" id="utente_id" name="utente_id" value="<?php echo (int)($utente_id ?? 0); ?>">
        </div>
        <div class="flex gap-2">
          <button class="btn-primary"><i class="fas fa-search mr-2"></i><?= __('Cerca') ?></button>
          <a href="<?= htmlspecialchars(url('/admin/prenotazioni'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary" id="btn-reset"><i class="fas fa-times mr-2"></i>Reset</a>
        </div>
      </form>
    </div>

    <div class="bg-white border border-gray-200 rounded-2xl shadow overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600"><?= __('ID') ?></th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600"><?= __('Libro') ?></th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600"><?= __('Utente') ?></th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600"><?= __('Data Prenotazione') ?></th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600"><?= __('Data Scadenza') ?></th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600"><?= __('Posizione Coda') ?></th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600"><?= __('Stato') ?></th>
            <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600"><?= __('Azioni') ?></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <?php foreach (($items ?? []) as $r): ?>
            <tr>
              <td class="px-4 py-2 text-sm text-gray-700"><?php echo (int)$r['id']; ?></td>
              <td class="px-4 py-2 text-sm text-gray-900"><?php echo App\Support\HtmlHelper::e($r['libro_titolo']); ?></td>
              <td class="px-4 py-2 text-sm text-gray-900"><?php echo App\Support\HtmlHelper::e($r['utente_nome']); ?></td>
              <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars(substr((string)$r['data_prenotazione'],0,10), ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-4 py-2 text-sm text-gray-700"><?php echo htmlspecialchars(substr((string)$r['data_scadenza_prenotazione'],0,10), ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="px-4 py-2 text-sm text-gray-700"><?php echo (int)($r['queue_position'] ?? 0); ?></td>
              <td class="px-4 py-2 text-sm">
                <?php
                $reservationStatoLabels = [
                  'attiva' => __('Attiva'),
                  'completata' => __('Completata'),
                  'annullata' => __('Annullata'),
                  'scaduta' => __('Scaduta'),
                ];
                $reservationStatoLabel = $reservationStatoLabels[$r['stato']] ?? ucfirst($r['stato']);
                ?>
                <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo ($r['stato']==='attiva'?'bg-green-100 text-green-800':($r['stato']==='completata'?'bg-blue-100 text-blue-800':'bg-gray-100 text-gray-800')); ?>"><?php echo App\Support\HtmlHelper::e($reservationStatoLabel); ?></span>
              </td>
              <td class="px-4 py-2 text-sm text-right">
                <a href="<?= htmlspecialchars(url('/admin/prenotazioni/modifica/' . (int)$r['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-blue-600 hover:text-blue-800"><i class="fas fa-edit mr-1"></i><?= __('Modifica') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>
.autocomplete-suggestions { position:absolute; z-index:40; background:#fff; border:1px solid #e5e7eb; border-radius:0.5rem; margin-top:0.25rem; width:100%; display:none; max-height:16rem; overflow-y:auto; box-shadow:0 5px 20px rgba(0,0,0,0.1); }
.autocomplete-suggestions.show { display:block; }
.autocomplete-suggestions li { padding:0.5rem 0.75rem; border-bottom:1px solid #f1f5f9; cursor:pointer; font-size:0.875rem; display:flex; align-items:center; gap:0.5rem; }
.autocomplete-suggestions li:last-child { border-bottom:none; }
.autocomplete-suggestions li:hover { background:#f8fafc; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
  async function fetchJSON(url){ const r = await fetch(url); return r.json(); }
  function setupAutocomplete(inputId, suggestId, fetchUrl, onPick){
    const input = document.getElementById(inputId);
    const ul = document.getElementById(suggestId);
    if (!input || !ul) return;
    let t;
    input.addEventListener('input', function(){
      clearTimeout(t);
      const q = this.value.trim();
      if (!q) { ul.classList.remove('show'); ul.innerHTML=''; return; }
      t = setTimeout(async ()=>{
        try {
          const data = await fetchJSON(fetchUrl + encodeURIComponent(q));
          ul.innerHTML = '';
          if (Array.isArray(data) && data.length){
            data.slice(0,8).forEach(it=>{
              const li = document.createElement('li');
              const icon = document.createElement('i');
              icon.className = `fas ${inputId.includes('utente')?'fa-user':'fa-book'} text-gray-400`;
              const span = document.createElement('span');
              span.textContent = it.label;
              li.appendChild(icon);
              li.appendChild(span);
              li.onclick = ()=>{ onPick(it); ul.classList.remove('show'); };
              ul.appendChild(li);
            });
            ul.classList.add('show');
          } else {
            ul.classList.remove('show');
          }
        } catch(e){ ul.classList.remove('show'); }
      }, 250);
    });
    input.addEventListener('blur', ()=> setTimeout(()=>{ ul.classList.remove('show'); }, 200));
  }

  setupAutocomplete('admin_filter_libro','admin_filter_libro_suggest', window.BASE_PATH + '/api/search/libri?q=', it=>{
    document.getElementById('libro_id').value = it.id;
    document.getElementById('admin_filter_libro').value = it.label;
  });
  setupAutocomplete('admin_filter_utente','admin_filter_utente_suggest', window.BASE_PATH + '/api/search/utenti?q=', it=>{
    document.getElementById('utente_id').value = it.id;
    document.getElementById('admin_filter_utente').value = it.label;
  });

  const reset = document.getElementById('btn-reset');
  if (reset) reset.addEventListener('click', function(){
    document.getElementById('libro_id').value='';
    document.getElementById('utente_id').value='';
  });
});
</script>
