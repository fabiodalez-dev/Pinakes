<?php
/**
 * @var array<int,array<string,mixed>> $partners
 * @var string $csrfToken
 * @var string $error
 * @var string $success
 */
use App\Support\HtmlHelper;
?>
<div class="flex-1 overflow-x-hidden">

  <!-- Page Header -->
  <div class="bg-white/50 backdrop-blur-sm border-b border-gray-200/80 dark:bg-gray-900/50 dark:border-gray-800/80 sticky top-0 z-30">
    <div class="px-6 py-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-3">
          <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
            <i class="fas fa-exchange-alt text-white text-sm"></i>
          </div>
          <div>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= __("Gestione Partner NCIP") ?></h1>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Biblioteche partner per il prestito interbibliotecario NCIP 2.02") ?></p>
          </div>
        </div>
        <a href="<?= htmlspecialchars(url('/admin/plugins/ncip-server/transactions'), ENT_QUOTES, 'UTF-8') ?>"
           class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-700 text-white text-sm rounded-lg hover:bg-gray-700 dark:hover:bg-gray-600 transition-colors">
          <i class="fas fa-list mr-2"></i>
          <?= __("Log Transazioni") ?>
        </a>
      </div>
    </div>
  </div>

  <div class="p-6 space-y-6">

    <?php if ($error !== ''): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 flex items-center gap-3">
      <i class="fas fa-exclamation-circle text-red-500"></i>
      <p class="text-sm text-red-700 dark:text-red-300"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-4 flex items-center gap-3">
      <i class="fas fa-check-circle text-green-500"></i>
      <p class="text-sm text-green-700 dark:text-green-300"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <?php endif; ?>

    <!-- Add partner form -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
      <div class="p-5 border-b border-gray-200 dark:border-gray-700">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <i class="fas fa-plus-circle text-blue-500"></i>
          <?= __("Aggiungi Partner") ?>
        </h2>
      </div>
      <form method="post" action="<?= htmlspecialchars(url('/admin/plugins/ncip-server/partners'), ENT_QUOTES, 'UTF-8') ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= __("Nome Partner") ?> <span class="text-red-500">*</span>
            </label>
            <input type="text" name="name" required maxlength="255"
                   placeholder="<?= __("Es. Biblioteca Nazionale di Roma") ?>"
                   class="w-full px-3 py-2 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= __("URL Endpoint NCIP") ?> <span class="text-red-500">*</span>
            </label>
            <input type="url" name="endpoint_url" required maxlength="500"
                   placeholder="https://biblioteca.example.org/ncip"
                   class="w-full px-3 py-2 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= __("Codice ISIL") ?>
            </label>
            <input type="text" name="isil" maxlength="32"
                   placeholder="IT-XXXXX"
                   class="w-full px-3 py-2 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              <?= __("Note") ?>
            </label>
            <input type="text" name="notes" maxlength="500"
                   class="w-full px-3 py-2 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
        </div>
        <div class="mt-4">
          <button type="submit"
                  class="inline-flex items-center px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
            <i class="fas fa-plus mr-2"></i>
            <?= __("Aggiungi Partner") ?>
          </button>
        </div>
      </form>
    </div>

    <!-- Partners table -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
      <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <i class="fas fa-network-wired text-gray-500"></i>
          <?= __("Partner Configurati") ?>
          <span class="ml-1 px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs rounded-full">
            <?= count($partners) ?>
          </span>
        </h2>
      </div>

      <?php if (empty($partners)): ?>
      <div class="p-10 text-center text-gray-400 dark:text-gray-500">
        <i class="fas fa-network-wired text-4xl mb-3 block"></i>
        <p class="text-sm"><?= __("Nessun partner configurato.") ?></p>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Nome Partner") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Endpoint NCIP") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Codice ISIL") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Note") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Aggiunto il") ?></th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <?php foreach ($partners as $p): ?>
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
              <td class="px-5 py-4 text-sm font-medium text-gray-900 dark:text-white">
                <?= htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300 font-mono">
                <?= htmlspecialchars((string) ($p['endpoint_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300">
                <?= htmlspecialchars((string) ($p['isil'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate">
                <?= htmlspecialchars((string) ($p['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                <?= htmlspecialchars((string) ($p['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-4 text-right">
                <form id="ncip-partner-delete-form-<?= (int) $p['id'] ?>" method="post"
                      action="<?= htmlspecialchars(url('/admin/plugins/ncip-server/partners/' . (int) $p['id'] . '/delete'), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <button type="button"
                          data-partner-name="<?= htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-partner-isil="<?= htmlspecialchars((string) ($p['isil'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-partner-endpoint="<?= htmlspecialchars((string) ($p['endpoint_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-form-id="ncip-partner-delete-form-<?= (int) $p['id'] ?>"
                          onclick="ncipPartnerConfirmDelete(this)"
                          class="inline-flex items-center px-3 py-1.5 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 text-xs font-medium rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors">
                    <i class="fas fa-trash-alt mr-1.5"></i>
                    <?= __("Elimina") ?>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
(function () {
  var I18N = {
    title: <?= json_encode(__('Eliminare partner?'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    orphanNote: <?= json_encode(__('Le transazioni associate resteranno come orfane.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    confirmBtn: <?= json_encode(__('Elimina'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    cancelBtn: <?= json_encode(__('Annulla'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    isilLabel: <?= json_encode(__('Codice ISIL'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
  };

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  window.ncipPartnerConfirmDelete = function (btn) {
    var name = btn.dataset.partnerName || '';
    var isil = btn.dataset.partnerIsil || '';
    var endpoint = btn.dataset.partnerEndpoint || '';
    var formId = btn.dataset.formId;
    var form = formId ? document.getElementById(formId) : null;

    var html = '<strong>' + escapeHtml(name) + '</strong>';
    if (isil !== '') {
      html += ' (' + escapeHtml(I18N.isilLabel) + ' ' + escapeHtml(isil) + ')';
    }
    if (endpoint !== '') {
      html += '<br>' + escapeHtml(endpoint);
    }
    html += '<br><small>' + escapeHtml(I18N.orphanNote) + '</small>';

    if (typeof Swal === 'undefined' || !Swal.fire) {
      if (window.confirm(I18N.title + '\n' + name + (isil ? ' (' + I18N.isilLabel + ' ' + isil + ')' : '') + '\n' + I18N.orphanNote) && form) {
        form.submit();
      }
      return;
    }

    Swal.fire({
      title: I18N.title,
      html: html,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: I18N.confirmBtn,
      cancelButtonText: I18N.cancelBtn,
      confirmButtonColor: '#dc2626'
    }).then(function (result) {
      if (result && result.isConfirmed && form) {
        form.submit();
      }
    });
  };
})();
</script>
