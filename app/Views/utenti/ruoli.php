<?php
use App\Support\Csrf;
$roles = $roles ?? [];
$labels = $labels ?? \App\Support\Permissions::labels();
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$workspaceHeader = ['title' => __('Ruoli e permessi'), 'context' => __('Scegli quali sezioni può usare ogni ruolo. Assegna il ruolo dalla scheda utente.')];
require __DIR__ . '/../partials/workspace-header.php';
?>
<div class="roles-page">
  <p><?= __('Gli amministratori possono fare tutto. Standard e Premium non accedono al backend. Il permesso di aprire allegati riservati funziona anche senza accesso al backend e non pubblica i file.') ?></p>
  <p><?= __('Solo un amministratore può modificare i ruoli o assegnare privilegi. Lo Staff mantiene i permessi esistenti; usa un ruolo personalizzato per limitarli.') ?></p>
  <p><?= __('I ruoli preimpostati sono modificabili: la matrice indica sempre i permessi effettivi.') ?></p>
  <form method="post" action="<?= $e(url('/admin/roles')) ?>">
    <input type="hidden" name="csrf_token" value="<?= $e(Csrf::ensureToken()) ?>">
    <div class="roles-table-wrap" tabindex="0" role="region" aria-label="<?= $e(__('Matrice dei permessi')) ?>">
      <table class="roles-matrix">
        <caption><?= __('Matrice dei permessi') ?></caption>
        <thead><tr><th scope="col"><?= __('Ruolo') ?></th>
          <?php foreach ($labels as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach; ?>
          <th scope="col"><?= __('Utenti') ?></th><th scope="col"><?= __('Elimina') ?></th>
        </tr></thead>
        <tbody>
          <?php foreach (['admin' => __('Amministratore'), 'staff' => __('Staff'), 'reader' => __('Standard / Premium')] as $system => $name): ?>
          <tr><th scope="row"><?= $e($name) ?><small><?= __('Ruolo di sistema') ?></small></th>
            <?php foreach ($labels as $permission => $label): ?><td><?php
              // Staff keeps its historical rights: users it may not delete or promote, and
              // the settings without the administrator-only tabs.
              if ($system === 'staff' && in_array($permission, ['users.manage', 'settings.manage'], true)): ?><span><?= __('Diritti esistenti') ?></span><?php
              else: $enabled = $system !== 'reader'; ?><span aria-label="<?= $e($enabled ? __('Consentito') : __('Non consentito')) ?>"><?= $enabled ? '✓' : '—' ?></span><?php endif; ?></td><?php endforeach; ?>
            <td>—</td><td>—</td>
          </tr>
          <?php endforeach; ?>
          <?php foreach ($roles as $role): $id = (int) $role['id']; ?>
          <tr>
            <th scope="row"><label class="sr-only" for="role-name-<?= $id ?>"><?= __('Nome del ruolo') ?></label><input id="role-name-<?= $id ?>" class="form-input" name="roles[<?= $id ?>][nome]" maxlength="100" required value="<?= $e($role['display_name']) ?>" <?= $role['description'] !== '' ? 'aria-describedby="role-description-' . $id . '"' : '' ?>>
              <?php if ($role['description'] !== ''): ?><small id="role-description-<?= $id ?>" class="roles-description"><?= $e($role['description']) ?></small><?php endif; ?>
            </th>
            <?php foreach ($labels as $permission => $label): ?>
              <td><label class="roles-check"><input type="checkbox" name="roles[<?= $id ?>][permissions][]" value="<?= $e($permission) ?>" <?= in_array($permission, $role['permissions'], true) ? 'checked' : '' ?>><span class="sr-only"><?= $e($role['display_name'] . ': ' . $label) ?></span></label></td>
            <?php endforeach; ?>
            <td><?= (int) $role['utenti'] ?></td>
            <td><?php if ((int) $role['utenti'] === 0): ?><label class="roles-check"><input type="checkbox" name="roles[<?= $id ?>][delete]" value="1"><span class="sr-only"><?= $e(sprintf(__('Elimina %s'), $role['display_name'])) ?></span></label><?php else: ?><span title="<?= $e(__('Assegna prima un altro ruolo agli utenti.')) ?>">—</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          <tr>
            <th scope="row"><label for="new-role-name"><?= __('Nuovo ruolo') ?></label><input id="new-role-name" class="form-input" name="new_role[nome]" maxlength="100" placeholder="<?= $e(__('Nome del ruolo')) ?>"></th>
            <?php foreach ($labels as $permission => $label): ?><td><label class="roles-check"><input type="checkbox" name="new_role[permissions][]" value="<?= $e($permission) ?>"><span class="sr-only"><?= $e(__('Nuovo ruolo') . ': ' . $label) ?></span></label></td><?php endforeach; ?>
            <td>—</td><td>—</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p><?= __('Gestire gli allegati richiede anche Catalogazione per modificare i file nella scheda libro. Gestione utenti permette di modificare i lettori, ma non di concedere privilegi o cambiare gli account degli operatori.') ?></p>
    <p><?= __('Gestione allegati digitali comprende anche l’apertura degli allegati riservati: chi può cambiare la visibilità di un file può comunque aprirlo, quindi i due permessi vengono salvati insieme.') ?></p>
    <div class="pk-form-actions"><button type="submit" class="ui-button btn-primary"><?= __('Salva ruoli e permessi') ?></button></div>
  </form>
</div>
<style<?= csp_nonce_attr() ?>>
.roles-page { padding: 0 0 2.25rem; color: var(--text-primary, #1b1720); }
.roles-page > p, .roles-page form > p { max-width: 75ch; margin: 1rem 0; }
.roles-table-wrap { max-width: 100%; overflow-x: auto; margin: 2rem 0; }
.roles-matrix { width: 100%; border-collapse: collapse; font-size: .875rem; }
.roles-matrix caption { text-align: left; font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; }
.roles-matrix th, .roles-matrix td { padding: .75rem; border-bottom: 1px solid var(--border-color, #e2dce0); text-align: center; }
.roles-matrix thead { background: var(--bg-secondary, #faf8f9); }
.roles-matrix th:first-child { min-width: 12rem; text-align: left; }
.roles-matrix th small { display: block; font-weight: 400; }
.roles-description { max-width: 24ch; margin-top: .5rem; font-size: .8125rem; line-height: 1.5; }
.roles-matrix th:not(:first-child) { min-width: 7rem; }
.roles-check { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; cursor: pointer; }
.roles-check input { width: 18px; height: 18px; accent-color: var(--color-primary, #d70161); }
.roles-table-wrap:focus-visible, .roles-check input:focus-visible { outline: 2px solid var(--color-primary, #d70161); outline-offset: 3px; }
@media (max-width: 767px) { .roles-page { padding: 0 0 1rem; } }
</style>
