<?php
/**
 * Book Club — governance module: custom club roles with the granular
 * permission matrix (create / edit / delete-when-unused).
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $roles      custom roles + member_count
 * @var list<string> $permKeys                 fixed permission matrix
 * @var array<string, string> $permLabels      perm key → translated label
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$clubId = (int) $club['id'];
$csrf = \App\Support\Csrf::ensureToken();
$formAction = url('/admin/book-club/' . $clubId . '/roles');

/** Decode a role's permissions JSON (map perm→bool or plain list) to a set. */
$activePerms = static function (mixed $json): array {
    $decoded = json_decode((string) ($json ?? ''), true);
    $set = [];
    if (is_array($decoded)) {
        foreach ($decoded as $k => $v) {
            if (is_int($k)) {
                $set[(string) $v] = true;
            } elseif (!empty($v)) {
                $set[(string) $k] = true;
            }
        }
    }
    return $set;
};
?>
<div class="p-6 max-w-5xl mx-auto space-y-8">
  <div>
    <a href="<?= $e(url('/admin/book-club/' . $clubId)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
      <i class="fas fa-arrow-left mr-1"></i><?= $e($club['name']) ?>
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">
      <i class="fas fa-user-shield mr-2 text-gray-400"></i><?= $e(__('Ruoli personalizzati')) ?>
    </h1>
    <p class="text-sm text-gray-500 mt-1">
      <?= $e(__('Oltre ai ruoli di sistema (Fondatore, Moderatore, Membro, Ospite) puoi definire ruoli su misura spuntando i permessi dalla matrice.')) ?>
    </p>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Existing custom roles -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Ruoli del club')) ?> (<?= count($roles) ?>)</h2>
    <?php if ($roles === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun ruolo personalizzato: creane uno qui sotto.')) ?></p>
    <?php endif; ?>
    <div class="space-y-4">
      <?php foreach ($roles as $role): ?>
        <?php $set = $activePerms($role['permissions'] ?? null); $inUse = (int) ($role['member_count'] ?? 0) > 0; ?>
        <details class="border rounded-lg">
          <summary class="flex items-center justify-between px-4 py-3 cursor-pointer">
            <span class="font-medium text-gray-900">
              <?= $e($role['name']) ?>
              <span class="text-xs text-gray-400 font-normal ml-2"><?= $e($role['slug']) ?></span>
            </span>
            <span class="text-xs text-gray-500">
              <?= count($set) ?> <?= $e(__('permessi')) ?> ·
              <?= (int) ($role['member_count'] ?? 0) ?> <?= $e(__('membri')) ?>
            </span>
          </summary>
          <div class="px-4 pb-4 border-t pt-4">
            <form method="post" action="<?= $e($formAction) ?>" class="space-y-3">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
              <div>
                <label class="block text-xs text-gray-500 mb-1"><?= $e(__('Nome del ruolo')) ?></label>
                <input type="text" name="name" required maxlength="190" value="<?= $e($role['name']) ?>"
                       class="border border-gray-300 rounded-lg px-3 py-1.5 w-72">
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <?php foreach ($permKeys as $key): ?>
                  <label class="flex items-center text-sm text-gray-700">
                    <input type="checkbox" name="perms[]" value="<?= $e($key) ?>" class="mr-2 rounded" <?= isset($set[$key]) ? 'checked' : '' ?>>
                    <?= $e($permLabels[$key] ?? $key) ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <button type="submit" class="px-4 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><?= $e(__('Salva ruolo')) ?></button>
            </form>
            <?php if ($inUse): ?>
              <p class="text-xs text-gray-400 mt-3">
                <i class="fas fa-lock mr-1"></i><?= $e(__('Il ruolo è assegnato ad almeno un membro e non può essere eliminato.')) ?>
              </p>
            <?php else: ?>
              <form method="post" action="<?= $e($formAction) ?>" class="mt-3"
                    onsubmit="return confirm('<?= $e(__('Eliminare definitivamente questo ruolo?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
                <button type="submit" class="text-xs text-red-600 hover:underline"><i class="fas fa-trash mr-1"></i><?= $e(__('Elimina ruolo')) ?></button>
              </form>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Create a new role -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-1"><?= $e(__('Nuovo ruolo')) ?></h2>
    <p class="text-sm text-gray-500 mb-4"><?= $e(__('Esempi: Bibliotecario, Organizzatore eventi, Curatore Fantasy, Tesoriere.')) ?></p>
    <form method="post" action="<?= $e($formAction) ?>" class="space-y-3">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <div>
        <label class="block text-xs text-gray-500 mb-1"><?= $e(__('Nome del ruolo')) ?></label>
        <input type="text" name="name" required maxlength="190" class="border border-gray-300 rounded-lg px-3 py-1.5 w-72">
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
        <?php foreach ($permKeys as $key): ?>
          <label class="flex items-center text-sm text-gray-700">
            <input type="checkbox" name="perms[]" value="<?= $e($key) ?>" class="mr-2 rounded">
            <?= $e($permLabels[$key] ?? $key) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
        <i class="fas fa-plus mr-1"></i><?= $e(__('Crea ruolo')) ?>
      </button>
    </form>
  </section>
</div>
