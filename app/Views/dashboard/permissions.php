<?php
$workspaceHeader = ['title' => __('Il tuo spazio di lavoro'), 'context' => htmlspecialchars((string) ($_SESSION['user']['role_name'] ?? ''), ENT_QUOTES, 'UTF-8')];
require __DIR__ . '/../partials/workspace-header.php';
?>
<div class="px-4 sm:px-6 lg:px-8 py-6">
  <p><?= __('Accedi alle sezioni abilitate per il tuo ruolo.') ?></p>
  <ul class="space-y-4 mt-6">
  <?php foreach (['catalog.manage' => ['/admin/books', __('Catalogazione')], 'loans.manage' => ['/admin/loans', __('Prestiti')], 'users.manage' => ['/admin/users', __('Utenti')], 'settings.manage' => ['/admin/settings', __('Impostazioni')], 'inventory.manage' => ['/admin/inventory', __('Revisione inventariale')]] as $permission => [$path, $label]): ?>
    <?php if (\App\Support\Permissions::can($permission) && ($permission !== 'inventory.manage' || \App\Support\Features::enabled('stocktake'))): ?><li><a class="ui-button btn-outline" href="<?= htmlspecialchars(url($path), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></a></li><?php endif; ?>
  <?php endforeach; ?>
  </ul>
  <p class="mt-6"><a href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Vai al catalogo pubblico') ?></a></p>
</div>
