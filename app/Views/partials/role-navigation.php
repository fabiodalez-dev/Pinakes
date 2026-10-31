<?php
use App\Support\Permissions;
$links = [
    'backend.access' => ['/admin/dashboard', __('Il tuo spazio di lavoro')],
    'catalog.manage' => ['/admin/books', __('Catalogazione')],
    'loans.manage' => ['/admin/loans', __('Prestiti')],
    'users.manage' => ['/admin/users', __('Utenti')],
    'settings.manage' => ['/admin/settings', __('Impostazioni')],
    'inventory.manage' => ['/admin/inventory', __('Revisione inventariale')],
];
foreach ($links as $permission => [$path, $label]):
    if (!Permissions::can($permission)) { continue; }
?>
<a class="nav-link group flex items-center px-4 py-3 rounded-lg text-gray-700" href="<?= htmlspecialchars(url($path), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
