<?php use App\Support\HtmlHelper; ?>
<section data-settings-panel="messages" class="settings-panel <?php echo $activeTab === 'messages' ? 'block' : 'hidden'; ?>">
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-inbox text-gray-500"></i>
          <?= __("Messaggi di Contatto") ?>
        </h2>
        <p class="text-sm text-gray-600 mt-1"><?= __("Tutti i messaggi ricevuti tramite il form contatti") ?></p>
      </div>
      <div class="flex gap-2">
        <button onclick="markAllAsRead()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors">
          <i class="fas fa-check-double"></i>
          <?= __("Segna tutti come letti") ?>
        </button>
      </div>
    </div>

    <!-- Messages Table -->
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <input type="checkbox" id="select-all-messages" class="rounded border-gray-300">
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Mittente") ?></th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Messaggio") ?></th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data") ?></th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200" id="messages-tbody">
            <?php if (empty($contactMessages)): ?>
            <tr>
              <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                <i class="fas fa-inbox text-4xl mb-3 text-gray-300"></i>
                <p><?= __("Nessun messaggio ricevuto") ?></p>
              </td>
            </tr>
            <?php else: ?>
              <?php foreach ($contactMessages as $message): ?>
              <tr class="<?php echo $message['is_read'] ? '' : 'bg-blue-50'; ?> hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                  <input type="checkbox" class="message-checkbox rounded border-gray-300" value="<?php echo $message['id']; ?>">
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm font-medium text-gray-900">
                    <?php echo HtmlHelper::e($message['nome'] . ' ' . $message['cognome']); ?>
                    <?php if (!$message['is_read']): ?>
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                      <?= __("Nuovo") ?>
                    </span>
                    <?php endif; ?>
                  </div>
                  <div class="text-sm text-gray-500"><?php echo HtmlHelper::e($message['email']); ?></div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-gray-900">
                    <?php
                    $excerpt = mb_substr($message['messaggio'], 0, 60);
                    echo HtmlHelper::e($excerpt) . (mb_strlen($message['messaggio']) > 60 ? '...' : '');
                    ?>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
  <?= format_date($message['created_at'], true, '/') ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <?php if ($message['is_archived']): ?>
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    <i class="fas fa-archive mr-1"></i> Archiviato
                  </span>
                  <?php elseif ($message['is_read']): ?>
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    <i class="fas fa-check mr-1"></i> Letto
                  </span>
                  <?php else: ?>
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                    <i class="fas fa-envelope mr-1"></i> Non letto
                  </span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <button onclick="viewMessage(<?php echo $message['id']; ?>)" class="text-blue-600 hover:text-blue-900 mr-3">
                    <i class="fas fa-eye"></i>
                  </button>
                  <button onclick="deleteMessage(<?php echo $message['id']; ?>)" class="text-red-600 hover:text-red-900">
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<!-- Message Detail Modal -->
<div id="message-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
    <div class="p-6 border-b border-gray-200 flex items-center justify-between">
      <h3 class="text-xl font-semibold text-gray-900"><?= __("Dettagli Messaggio") ?></h3>
      <button onclick="closeMessageModal()" class="text-gray-400 hover:text-gray-600">
        <i class="fas fa-times text-xl"></i>
      </button>
    </div>
    <div class="p-6" id="message-detail">
      <!-- Content loaded via JavaScript -->
    </div>
  </div>
</div>

<script>
function viewMessage(id) {
  fetch(`/admin/messages/${id}`)
    .then(response => response.json())
    .then(data => {
      const modal = document.getElementById('message-modal');
      const detail = document.getElementById('message-detail');

      detail.innerHTML = `
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="text-sm font-medium text-gray-500"><?= __("Da") ?></label>
              <p class="mt-1 text-sm text-gray-900">${escapeHtml(data.nome)} ${escapeHtml(data.cognome)}</p>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-500"><?= __("Email") ?></label>
              <p class="mt-1 text-sm text-gray-900">
                <a href="mailto:${escapeHtml(data.email)}" class="text-gray-900 hover:underline">${escapeHtml(data.email)}</a>
              </p>
            </div>
            ${data.telefono ? `
            <div>
              <label class="text-sm font-medium text-gray-500"><?= __("Telefono") ?></label>
              <p class="mt-1 text-sm text-gray-900">${escapeHtml(data.telefono)}</p>
            </div>
            ` : ''}
            ${data.indirizzo ? `
            <div>
              <label class="text-sm font-medium text-gray-500"><?= __("Indirizzo") ?></label>
              <p class="mt-1 text-sm text-gray-900">${escapeHtml(data.indirizzo)}</p>
            </div>
            ` : ''}
            <div>
              <label class="text-sm font-medium text-gray-500"><?= __("Data") ?></label>
              <p class="mt-1 text-sm text-gray-900">${new Date(data.created_at).toLocaleString('it-IT')}</p>
            </div>
          </div>
          <div>
            <label class="text-sm font-medium text-gray-500"><?= __("Messaggio") ?></label>
            <div class="mt-2 p-4 bg-gray-50 rounded-xl text-sm text-gray-900 whitespace-pre-wrap">${escapeHtml(data.messaggio)}</div>
          </div>
          <div class="flex gap-2 pt-4">
            <button onclick="replyToMessage('${escapeHtml(data.email)}')" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800 transition-colors">
              <i class="fas fa-reply"></i>
              <?= __("Rispondi") ?>
            </button>
            ${!data.is_archived ? `
            <button onclick="archiveMessage(${data.id})" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-600 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
              <i class="fas fa-archive"></i>
              <?= __("Archivia") ?>
            </button>
            ` : ''}
          </div>
        </div>
      `;

      modal.classList.remove('hidden');
      modal.classList.add('flex');
    });
}

function closeMessageModal() {
  const modal = document.getElementById('message-modal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

function deleteMessage(id) {
  if (confirm(__('Sei sicuro di voler eliminare questo messaggio?'))) {
    csrfFetch(`/admin/messages/${id}`, { method: 'DELETE' })
      .then(() => location.reload());
  }
}

function archiveMessage(id) {
  csrfFetch(`/admin/messages/${id}/archive`, { method: 'POST' })
    .then(() => {
      closeMessageModal();
      location.reload();
    });
}

function markAllAsRead() {
  csrfFetch('/admin/messages/mark-all-read', { method: 'POST' })
    .then(() => location.reload());
}

function replyToMessage(email) {
  window.location.href = `mailto:${email}`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}
</script>
