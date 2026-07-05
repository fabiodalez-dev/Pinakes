<?php
/**
 * Book Club — AI module settings (Pinakes admin only): global on/off, API
 * key (stored AES-encrypted in plugin_settings via PluginManager, shown here
 * only as ****last4), model name and endpoint URL.
 *
 * @var bool $pluginFound
 * @var bool $enabled
 * @var string $maskedKey
 * @var string $model
 * @var string $endpoint
 * @var string $defaultModel
 * @var string $defaultEndpoint
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="p-6 max-w-3xl mx-auto">
  <div class="mb-6">
    <a href="<?= $e(url('/admin/book-club')) ?>" class="text-sm text-gray-500 hover:text-gray-700">
      <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Book Club')) ?>
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">
      <i class="fas fa-wand-magic-sparkles mr-2 text-gray-400"></i><?= $e(__('Impostazioni assistente IA')) ?>
    </h1>
    <p class="text-sm text-gray-500 mt-1">
      <?= $e(__('Il modulo IA è opt-in: va attivato per ogni club e funziona solo con una chiave API configurata qui. La chiave è cifrata nel database e non viene mai mostrata per intero.')) ?>
    </p>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$pluginFound): ?>
    <div class="px-4 py-3 rounded-lg text-sm bg-red-50 text-red-800">
      <?= $e(__('Plugin Book Club non trovato nel registro dei plugin.')) ?>
    </div>
  <?php else: ?>
    <form method="post" action="<?= $e(url('/admin/book-club/ai')) ?>" class="bg-white rounded-xl shadow p-6 space-y-5" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

      <label class="flex items-start gap-3">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> class="mt-1 rounded border-gray-300">
        <span>
          <span class="block text-sm font-medium text-gray-700"><?= $e(__('Abilita le funzioni IA')) ?></span>
          <span class="block text-xs text-gray-400"><?= $e(__('Interruttore globale: anche i club con il modulo attivo non potranno generare nulla finché è spento.')) ?></span>
        </span>
      </label>

      <div class="border-t pt-5">
        <label class="block text-sm font-medium text-gray-700 mb-1" for="ai-api-key"><?= $e(__('Chiave API')) ?></label>
        <?php if ($maskedKey !== ''): ?>
          <p class="text-xs text-gray-400 mb-2">
            <i class="fas fa-lock mr-1"></i><?= $e(sprintf(__('Chiave attualmente configurata: %s'), $maskedKey)) ?>
          </p>
        <?php endif; ?>
        <input type="password" id="ai-api-key" name="api_key" value="" maxlength="500"
               placeholder="<?= $maskedKey !== '' ? $e(__('Lascia vuoto per mantenere la chiave attuale')) : $e(__('Incolla qui la chiave API')) ?>"
               autocomplete="new-password"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
        <p class="text-xs text-gray-400 mt-1"><?= $e(__('Salvata cifrata (AES-256-GCM) in plugin_settings; usata solo lato server.')) ?></p>
        <?php if ($maskedKey !== ''): ?>
          <label class="flex items-center gap-2 mt-2 text-sm text-gray-600">
            <input type="checkbox" name="clear_key" value="1" class="rounded border-gray-300">
            <?= $e(__('Rimuovi la chiave salvata')) ?>
          </label>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="ai-model"><?= $e(__('Modello')) ?></label>
          <input type="text" id="ai-model" name="model" value="<?= $e($model) ?>" maxlength="100"
                 placeholder="<?= $e($defaultModel) ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="ai-endpoint"><?= $e(__('Endpoint URL')) ?></label>
          <input type="url" id="ai-endpoint" name="endpoint" value="<?= $e($endpoint) ?>" maxlength="500"
                 placeholder="<?= $e($defaultEndpoint) ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
          <p class="text-xs text-gray-400 mt-1"><?= $e(__('API compatibile con Anthropic Messages (header anthropic-version). Solo HTTPS.')) ?></p>
        </div>
      </div>

      <div class="border-t pt-5 flex items-center justify-between">
        <p class="text-xs text-gray-400">
          <i class="fas fa-hand mr-1"></i><?= $e(__('Limite di sicurezza fisso: 20 generazioni per club nelle 24 ore.')) ?>
        </p>
        <button type="submit" class="px-5 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
          <i class="fas fa-save mr-1"></i><?= $e(__('Salva impostazioni')) ?>
        </button>
      </div>
    </form>
  <?php endif; ?>
</div>
