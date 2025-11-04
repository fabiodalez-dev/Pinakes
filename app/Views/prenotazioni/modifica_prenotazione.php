<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li><a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700"><i class="fas fa-home mr-1"></i>Home</a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li><a href="/admin/prenotazioni" class="text-gray-500 hover:text-gray-700"><i class="fas fa-bookmark mr-1"></i>Prenotazioni</a></li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium">Modifica</li>
      </ol>
    </nav>
    <div class="bg-white border border-gray-200 rounded-2xl shadow p-6">
      <h1 class="text-2xl font-bold text-gray-900 mb-4">Modifica Prenotazione #<?php echo (int)$p['id']; ?></h1>
      <div class="text-sm text-gray-600 mb-4">
        <i class="fas fa-book mr-1"></i> Libro: <strong><?php echo App\Support\HtmlHelper::e($p['libro_titolo'] ?? ''); ?></strong><br>
        <i class="fas fa-user mr-1"></i> Utente: <strong><?php echo App\Support\HtmlHelper::e($p['utente_nome'] ?? ''); ?></strong>
      </div>
      <form method="post" action="/admin/prenotazioni/update/<?php echo (int)$p['id']; ?>" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="form-label">Data inizio</label>
            <input type="date" name="data_prenotazione" class="form-input" value="<?php echo htmlspecialchars(substr((string)$p['data_prenotazione'],0,10), ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div>
            <label class="form-label">Data fine</label>
            <input type="date" name="data_scadenza_prenotazione" class="form-input" value="<?php echo htmlspecialchars(substr((string)$p['data_scadenza_prenotazione'],0,10), ENT_QUOTES, 'UTF-8'); ?>">
            <p class="text-xs text-gray-500 mt-1">Default: un mese dopo la data inizio</p>
          </div>
        </div>
        <div>
          <label class="form-label">Stato</label>
          <select name="stato" class="form-input">
            <?php $st = (string)($p['stato'] ?? 'attiva'); ?>
            <option value="attiva" <?php echo $st==='attiva'?'selected':''; ?>>Attiva</option>
            <option value="completata" <?php echo $st==='completata'?'selected':''; ?>>Completata</option>
            <option value="annullata" <?php echo $st==='annullata'?'selected':''; ?>>Annullata</option>
          </select>
        </div>
        <div class="flex justify-end gap-2">
          <a href="/admin/prenotazioni" class="btn-secondary"><i class="fas fa-arrow-left mr-1"></i>Indietro</a>
          <button class="btn-primary"><i class="fas fa-save mr-2"></i>Salva</button>
        </div>
      </form>
    </div>
  </div>
</div>

