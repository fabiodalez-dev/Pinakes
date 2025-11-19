<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <span><i class="fas fa-tags mr-1"></i><?= __("Dewey") ?></span>
        </li>
      </ol>
    </nav>
    <div class="mb-6">
      <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
        <i class="fas fa-tags text-blue-600"></i>
        <?= __("Admin Dewey") ?>
      </h1>
      <p class="text-gray-600"><?= __("Gestione classificazione Dewey: seed e statistiche") ?></p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6" id="dewey-stats">
      <div class="card">
        <div class="card-body">
          <div class="text-sm text-gray-500"><?= __("Livello 1 (Classi)") ?></div>
          <div class="text-2xl font-bold" id="stat-l1">-</div>
        </div>
      </div>
      <div class="card">
        <div class="card-body">
          <div class="text-sm text-gray-500"><?= __("Livello 2 (Divisioni)") ?></div>
          <div class="text-2xl font-bold" id="stat-l2">-</div>
        </div>
      </div>
      <div class="card">
        <div class="card-body">
          <div class="text-sm text-gray-500"><?= __("Livello 3 (Specifiche)") ?></div>
          <div class="text-2xl font-bold" id="stat-l3">-</div>
        </div>
      </div>
    </div>

    <div class="card mb-6">
      <div class="card-header flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900"><?= __("Operazioni") ?></h2>
        <button id="btn-reseed" class="btn-primary">
          <i class="fas fa-sync mr-2"></i><?= __("Ricarica Dewey (seed)") ?>
        </button>
      </div>
      <div class="card-body">
        <div id="reseed-result" class="text-sm text-gray-600"><?= __("Premi il pulsante per ricaricare tutti i livelli (L1/L2/L3).") ?></div>
      </div>
    </div>

    <div class="text-xs text-gray-500">
      <?= __("Nota: in produzione limita questa funzione agli amministratori.") ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const l1 = document.getElementById('stat-l1');
  const l2 = document.getElementById('stat-l2');
  const l3 = document.getElementById('stat-l3');
  const out = document.getElementById('reseed-result');
  const btn = document.getElementById('btn-reseed');
  const csrfToken = '<?= \App\Support\Csrf::ensureToken() ?>';

  async function loadCounts(){
    try {
      const c = await fetch('/api/dewey/counts').then(r=>r.json());
      l1.textContent = (c.l1 ?? 0).toLocaleString('it-IT');
      l2.textContent = (c.l2 ?? 0).toLocaleString('it-IT');
      l3.textContent = (c.l3 ?? 0).toLocaleString('it-IT');
    } catch(e){ console.error('loadCounts', e); }
  }

  btn.addEventListener('click', async ()=>{
    btn.disabled = true; const prev = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?= addslashes(__("Working...")) ?>';
    try {
      const res = await fetch('/api/dewey/reseed', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        }
      }).then(r=>r.json());

      // Check for CSRF/session errors
      if (res.error || res.code) {
        out.textContent = res.error || '<?= addslashes(__("Errore di sicurezza")) ?>';
        if (res.code === 'SESSION_EXPIRED' || res.code === 'CSRF_INVALID') {
          setTimeout(() => window.location.reload(), 2000);
        }
        return;
      }

      if (res && res.ok) {
        out.textContent = `<?= addslashes(__("Seed completato.")) ?> L1=${res.counts.l1 || 0}, L2=${res.counts.l2 || 0}, L3=${res.counts.l3 || 0}`;
        await loadCounts();
      } else {
        out.textContent = '<?= addslashes(__("Errore durante il seed")) ?>';
      }
    } catch(e){ out.textContent = '<?= addslashes(__("Errore durante il seed")) ?>'; console.error('reseed', e); }
    finally { btn.disabled=false; btn.innerHTML = prev; }
  });

  loadCounts();
});
</script>
