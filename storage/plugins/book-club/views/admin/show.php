<?php
/**
 * Book Club — admin club detail: workflow editor, members, books, polls, meetings.
 *
 * @var array<string, mixed> $club
 * @var list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
 * @var list<array<string, mixed>> $members
 * @var list<array<string, mixed>> $roles
 * @var list<array<string, mixed>> $customRoles   governance module: custom club roles (id, slug, name)
 * @var bool $governanceEnabled                    governance module enabled for this club
 * @var list<array<string, mixed>> $books
 * @var list<array<string, mixed>> $polls
 * @var list<array<string, mixed>> $meetings
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$clubId = (int) $club['id'];
$csrf = \App\Support\Csrf::ensureToken();
$stateLabels = [];
foreach ($states as $s) {
    $stateLabels[$s['key']] = $s;
}
$customRoles = isset($customRoles) && is_array($customRoles) ? $customRoles : [];
$governanceEnabled = !empty($governanceEnabled);
$statusLabels = [
    'pending' => __('In attesa'),
    'active' => __('Attivo'),
    'suspended' => __('Sospeso'),
    'left' => __('Uscito'),
    'banned' => __('Bandito'),
];
?>
<div class="p-6 max-w-7xl mx-auto space-y-8">
  <div class="flex items-center justify-between">
    <div>
      <a href="<?= $e(url('/admin/book-club')) ?>" class="text-sm text-gray-500 hover:text-gray-700">
        <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Book Club')) ?>
      </a>
      <h1 class="text-2xl font-bold text-gray-900 mt-2 flex items-center">
        <span class="inline-block w-4 h-4 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
        <?= $e($club['name']) ?>
      </h1>
    </div>
    <div class="flex items-center gap-3">
      <a href="<?= $e(url('/book-club/' . $club['slug'])) ?>" target="_blank" class="text-sm text-gray-600 hover:underline">
        <i class="fas fa-external-link-alt mr-1"></i><?= $e(__('Pagina pubblica')) ?>
      </a>
      <a href="<?= $e(url('/admin/book-club/' . $clubId . '/edit')) ?>"
         class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-medium rounded-lg">
        <i class="fas fa-cog mr-2"></i><?= $e(__('Impostazioni')) ?>
      </a>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Workflow editor -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-1"><?= $e(__('Workflow del libro')) ?></h2>
    <p class="text-sm text-gray-500 mb-4"><?= $e(__('Stati ordinati che ogni libro attraversa. Il primo stato accoglie le proposte; il vincitore di una votazione avanza allo stato successivo.')) ?></p>
    <form method="post" action="<?= $e(url('/admin/book-club/' . $clubId . '/workflow')) ?>" id="bc-workflow-form">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <table class="min-w-full text-sm" id="bc-workflow-table">
        <thead>
          <tr class="text-left text-xs text-gray-500 uppercase">
            <th class="py-2 pr-3"><?= $e(__('Etichetta')) ?></th>
            <th class="py-2 pr-3"><?= $e(__('Chiave')) ?></th>
            <th class="py-2 pr-3"><?= $e(__('Colore')) ?></th>
            <th class="py-2 pr-3" title="<?= $e(__('I libri in votazione sostano qui')) ?>"><?= $e(__('Votazione')) ?></th>
            <th class="py-2 pr-3" title="<?= $e(__('Mostrato come "lettura corrente" nella dashboard')) ?>"><?= $e(__('Corrente')) ?></th>
            <th class="py-2 pr-3" title="<?= $e(__('Sezione archivio')) ?>"><?= $e(__('Archivio')) ?></th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($states as $i => $state): ?>
            <tr class="border-t">
              <td class="py-2 pr-3"><input type="text" name="state_label[]" required value="<?= $e($state['label']) ?>" class="border border-gray-300 rounded px-2 py-1 w-full"></td>
              <td class="py-2 pr-3"><input type="text" name="state_key[]" value="<?= $e($state['key']) ?>" pattern="[a-z0-9_\-]*" class="border border-gray-200 bg-gray-50 rounded px-2 py-1 w-32 text-gray-500"></td>
              <td class="py-2 pr-3"><input type="color" name="state_color[]" value="<?= $e($state['color']) ?>" class="w-10 h-8 border border-gray-300 rounded"></td>
              <td class="py-2 pr-3 text-center"><input type="checkbox" name="flag_voting[<?= $i ?>]" value="1" <?= !empty($state['flags']['voting']) ? 'checked' : '' ?>></td>
              <td class="py-2 pr-3 text-center"><input type="checkbox" name="flag_current[<?= $i ?>]" value="1" <?= !empty($state['flags']['current']) ? 'checked' : '' ?>></td>
              <td class="py-2 pr-3 text-center"><input type="checkbox" name="flag_archived[<?= $i ?>]" value="1" <?= !empty($state['flags']['archived']) ? 'checked' : '' ?>></td>
              <td class="py-2 text-right"><button type="button" class="text-red-500 hover:text-red-700 bc-remove-state" title="<?= $e(__('Rimuovi stato')) ?>"><i class="fas fa-times"></i></button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="flex items-center gap-3 mt-4">
        <button type="button" id="bc-add-state" class="px-3 py-1.5 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg"><i class="fas fa-plus mr-1"></i><?= $e(__('Aggiungi stato')) ?></button>
        <button type="submit" class="px-4 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><?= $e(__('Salva workflow')) ?></button>
        <span class="text-xs text-gray-400"><?= $e(__('Attenzione: rinominare una chiave non migra i libri già in quello stato.')) ?></span>
      </div>
    </form>
    <script>
      (function () {
        var table = document.getElementById('bc-workflow-table').querySelector('tbody');
        document.getElementById('bc-add-state').addEventListener('click', function () {
          var idx = table.querySelectorAll('tr').length;
          var tr = document.createElement('tr');
          tr.className = 'border-t';
          tr.innerHTML = '<td class="py-2 pr-3"><input type="text" name="state_label[]" required class="border border-gray-300 rounded px-2 py-1 w-full"></td>'
            + '<td class="py-2 pr-3"><input type="text" name="state_key[]" pattern="[a-z0-9_\\-]*" class="border border-gray-200 bg-gray-50 rounded px-2 py-1 w-32 text-gray-500" placeholder="auto"></td>'
            + '<td class="py-2 pr-3"><input type="color" name="state_color[]" value="#6b7280" class="w-10 h-8 border border-gray-300 rounded"></td>'
            + '<td class="py-2 pr-3 text-center"><input type="checkbox" name="flag_voting[' + idx + ']" value="1"></td>'
            + '<td class="py-2 pr-3 text-center"><input type="checkbox" name="flag_current[' + idx + ']" value="1"></td>'
            + '<td class="py-2 pr-3 text-center"><input type="checkbox" name="flag_archived[' + idx + ']" value="1"></td>'
            + '<td class="py-2 text-right"><button type="button" class="text-red-500 hover:text-red-700 bc-remove-state"><i class="fas fa-times"></i></button></td>';
          table.appendChild(tr);
        });
        table.addEventListener('click', function (ev) {
          var btn = ev.target.closest('.bc-remove-state');
          if (btn) { btn.closest('tr').remove(); }
        });
      })();
    </script>
  </section>

  <!-- Members -->
  <section class="bg-white rounded-xl shadow p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-900"><?= $e(__('Membri')) ?> (<?= count($members) ?>)</h2>
      <?php if ($governanceEnabled): ?>
        <a href="<?= $e(url('/admin/book-club/' . $clubId . '/roles')) ?>" class="text-sm text-blue-600 hover:underline">
          <i class="fas fa-user-shield mr-1"></i><?= $e(__('Ruoli personalizzati')) ?>
        </a>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= $e(url('/admin/book-club/' . $clubId . '/members/add')) ?>" class="flex flex-wrap items-end gap-3 mb-5">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <div>
        <label class="block text-xs text-gray-500 mb-1"><?= $e(__('Email utente registrato')) ?></label>
        <input type="email" name="email" required class="border border-gray-300 rounded-lg px-3 py-1.5 w-64">
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1"><?= $e(__('Ruolo')) ?></label>
        <select name="role" class="border border-gray-300 rounded-lg px-3 py-1.5">
          <?php foreach ($roles as $role): ?>
            <option value="<?= $e($role['slug']) ?>" <?= $role['slug'] === 'member' ? 'selected' : '' ?>><?= $e($role['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="px-4 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><?= $e(__('Aggiungi membro')) ?></button>
    </form>
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left text-xs text-gray-500 uppercase">
          <th class="py-2 pr-3"><?= $e(__('Nome')) ?></th>
          <th class="py-2 pr-3"><?= $e(__('Email')) ?></th>
          <th class="py-2 pr-3"><?= $e(__('Ruolo')) ?></th>
          <th class="py-2 pr-3"><?= $e(__('Stato')) ?></th>
          <th class="py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($members)): ?>
          <tr><td colspan="5" class="py-6 text-center text-gray-400"><?= $e(__('Nessun membro.')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($members as $member): ?>
          <tr class="border-t">
            <td class="py-2 pr-3 font-medium text-gray-900"><?= $e($member['nome'] . ' ' . $member['cognome']) ?></td>
            <td class="py-2 pr-3 text-gray-600"><?= $e($member['email']) ?></td>
            <td class="py-2 pr-3">
              <form method="post" action="<?= $e(url('/admin/book-club/' . $clubId . '/members/' . (int) $member['id'] . '/update')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <select name="role" class="border border-gray-200 rounded px-2 py-1 text-xs" onchange="this.form.submit()">
                  <?php foreach ($roles as $role): ?>
                    <option value="<?= $e($role['slug']) ?>" <?= $role['slug'] === $member['role_slug'] ? 'selected' : '' ?>><?= $e($role['name']) ?></option>
                  <?php endforeach; ?>
                  <?php if ($customRoles !== []): ?>
                    <optgroup label="<?= $e(__('Ruoli personalizzati')) ?>">
                      <?php foreach ($customRoles as $customRole): ?>
                        <option value="<?= (int) $customRole['id'] ?>" <?= (int) $customRole['id'] === (int) $member['role_id'] ? 'selected' : '' ?>><?= $e($customRole['name']) ?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                </select>
              </form>
            </td>
            <td class="py-2 pr-3">
              <form method="post" action="<?= $e(url('/admin/book-club/' . $clubId . '/members/' . (int) $member['id'] . '/update')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <select name="status" class="border border-gray-200 rounded px-2 py-1 text-xs" onchange="this.form.submit()">
                  <?php foreach ($statusLabels as $value => $label): ?>
                    <option value="<?= $e($value) ?>" <?= $value === $member['status'] ? 'selected' : '' ?>><?= $e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="py-2 text-xs text-gray-400"><?= $e(__('dal')) ?> <?= $e(date('d/m/Y', (int) strtotime((string) $member['joined_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <!-- Books by state -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Libri del club')) ?> (<?= count($books) ?>)</h2>
    <?php if (empty($books)): ?>
      <p class="text-gray-400 text-sm"><?= $e(__('Nessun libro: le proposte dei membri appariranno qui.')) ?></p>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($books as $book): ?>
          <?php $st = $stateLabels[$book['state']] ?? null; ?>
          <div class="flex items-center justify-between border rounded-lg px-3 py-2">
            <div>
              <span class="font-medium text-gray-900"><?= $e($book['titolo']) ?></span>
              <?php if (!empty($book['autori'])): ?><span class="text-gray-500 text-sm"> — <?= $e($book['autori']) ?></span><?php endif; ?>
              <?php if (!empty($book['proposer_nome'])): ?>
                <span class="text-xs text-gray-400 ml-2"><?= $e(__('proposto da')) ?> <?= $e($book['proposer_nome'] . ' ' . $book['proposer_cognome']) ?></span>
              <?php endif; ?>
            </div>
            <span class="px-2 py-1 text-xs rounded-full text-white" style="background: <?= $e($st['color'] ?? '#6b7280') ?>">
              <?= $e($st['label'] ?? ($book['state'] === 'pending' ? __('In moderazione') : $book['state'])) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="text-xs text-gray-400 mt-3"><?= $e(__('Le transizioni di stato si gestiscono dalla pagina pubblica del club (come moderatore).')) ?></p>
  </section>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
    <!-- Polls -->
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Votazioni')) ?></h2>
      <?php if (empty($polls)): ?>
        <p class="text-gray-400 text-sm"><?= $e(__('Nessuna votazione.')) ?></p>
      <?php endif; ?>
      <?php foreach ($polls as $poll): ?>
        <div class="flex items-center justify-between border-t py-2 text-sm">
          <a class="text-blue-600 hover:underline" href="<?= $e(url('/book-club/' . $club['slug'] . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
          <span class="text-xs <?= $poll['status'] === 'open' ? 'text-green-600' : 'text-gray-400' ?>">
            <?= $poll['status'] === 'open' ? $e(__('Aperta')) : $e(__('Chiusa')) ?>
            · <?= (int) $poll['voter_count'] ?> <?= $e(__('votanti')) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </section>

    <!-- Meetings -->
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Incontri')) ?></h2>
      <?php if (empty($meetings)): ?>
        <p class="text-gray-400 text-sm"><?= $e(__('Nessun incontro.')) ?></p>
      <?php endif; ?>
      <?php foreach ($meetings as $meeting): ?>
        <div class="flex items-center justify-between border-t py-2 text-sm">
          <div>
            <span class="font-medium text-gray-900"><?= $e($meeting['title']) ?></span>
            <span class="text-xs text-gray-400 ml-2"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $meeting['starts_at']))) ?></span>
          </div>
          <span class="text-xs text-gray-500">
            <?= (int) $meeting['yes_count'] ?> <?= $e(__('sì')) ?><?= $meeting['seats'] !== null ? ' / ' . (int) $meeting['seats'] : '' ?>
            · <?= $e(['scheduled' => __('In programma'), 'done' => __('Svolto'), 'cancelled' => __('Annullato')][$meeting['status']] ?? $meeting['status']) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </section>
  </div>
</div>
