<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
        <i class="fas fa-unlock-alt text-blue-600"></i>
        Reimposta password
      </h1>
      <p class="text-gray-600">Scegli una nuova password per il tuo account.</p>
    </div>

    <?php if (!empty($_GET['error'])): ?>
      <?php $err = $_GET['error']; ?>
      <div class="mb-4 p-3 bg-red-50 text-red-700 rounded border border-red-200" role="alert">
        <?php if ($err==='csrf'): ?>Token CSRF non valido.
        <?php elseif ($err==='expired'): ?>Link scaduto, richiedi un nuovo reset.
        <?php elseif ($err==='invalid_token'): ?>Token non valido.
        <?php else: ?>Dati non validi. Verifica e riprova.<?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/reset-password" class="space-y-4 bg-white p-6 rounded-2xl border border-gray-200 shadow">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)($token ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
      <div>
        <label class="form-label">Nuova password</label>
        <input type="password" name="password" autocomplete="new-password" required aria-required="true" class="form-input" />
      </div>
      <div>
        <label class="form-label">Conferma password</label>
        <input type="password" name="password_confirm" autocomplete="new-password" required aria-required="true" class="form-input" />
      </div>
      <div class="flex items-center justify-between">
        <a href="/login" class="text-sm text-blue-600 hover:underline">Torna al login</a>
        <button type="submit" class="btn-primary inline-flex items-center">
          <i class="fas fa-save mr-2"></i>
          Salva
        </button>
      </div>
    </form>
  </div>
</div>

