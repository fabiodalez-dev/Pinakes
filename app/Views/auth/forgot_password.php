<?php $forgotPasswordRoute = htmlspecialchars(route_path('forgot_password'), ENT_QUOTES, 'UTF-8'); ?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
        <i class="fas fa-key text-blue-600"></i>
        <?= __('Password dimenticata') ?>
      </h1>
      <p class="text-gray-600"><?= __('Inserisci la tua email per ricevere il link di reset.') ?></p>
    </div>

    <?php if (!empty($_GET['sent'])): ?>
      <div class="mb-4 p-3 bg-green-50 text-green-700 rounded border border-green-200" role="alert">Se l'email esiste, è stato inviato un link di reset.</div>
    <?php elseif (!empty($_GET['error'])): ?>
      <div class="mb-4 p-3 bg-red-50 text-red-700 rounded border border-red-200" role="alert">Richiesta non valida. Riprova.</div>
    <?php endif; ?>

    <form method="post" action="<?= $forgotPasswordRoute ?>" class="space-y-4 bg-white p-6 rounded-2xl border border-gray-200 shadow">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <div>
        <label class="form-label"><?= __("Email") ?></label>
        <input type="email" autocomplete="email" name="email" required aria-required="true" class="form-input" />
      </div>
      <div class="flex items-center justify-between">
        <a href="<?= htmlspecialchars(route_path('login'), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-blue-600 hover:underline"><?= __('Torna al login') ?></a>
        <button type="submit" class="btn-primary inline-flex items-center">
          <i class="fas fa-paper-plane mr-2"></i>
          <?= __('Invia link') ?>
        </button>
      </div>
    </form>
  </div>
</div>
