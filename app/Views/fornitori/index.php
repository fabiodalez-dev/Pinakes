<?php
/**
 * @var array $data { fornitori: list<array<string,mixed>> }
 */
use App\Support\Csrf;

$fornitori = $data['fornitori'];
$csrf = Csrf::ensureToken();
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Header -->
    <?php
// Shared page anatomy, same as the publishers list.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= __("Fornitori") ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
          <p class="mt-1 text-sm text-gray-600"><?= __("Gestisci i fornitori da cui la biblioteca acquista i libri") ?></p>
          <span id="total-badge" class="status-badge bg-gray-100 text-gray-700 mt-3"><?= (int) count($fornitori) ?> <?= __("fornitori") ?></span>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?>
        <div class="flex items-center gap-2">
          <a href="<?= htmlspecialchars(url('/admin/suppliers/create'), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">
            <i class="fas fa-plus mr-2"></i><?= __("Nuovo Fornitore") ?>
          </a>
        </div>
      <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
    <ol>
        <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a></li>
        <li aria-current="page"><span><?= __("Fornitori") ?></span></li>
    </ol>
</nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <!-- Main Card with Integrated Filters -->
    <div class="card entity-list-card">
      <div class="p-4 border-b border-gray-100">
        <div class="entity-filter-grid publisher-filter-grid">
          <div>
            <label for="search_fornitore" class="form-label">
              <i class="fas fa-search mr-1"></i><?= __("Cerca") ?>
            </label>
            <input id="search_fornitore" type="text" aria-describedby="search_fornitore_hint" placeholder="<?= htmlspecialchars(__('Nome fornitore...'), ENT_QUOTES, 'UTF-8') ?>"
                   class="form-input" />
            <p id="search_fornitore_hint" class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(__('Usa le virgolette per la grafia esatta: "Häffner" trova solo Häffner, non Haffner.'), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
      </div>

      <div class="p-4">
        <div class="entity-table-wrap overflow-x-auto">
          <table id="fornitori-table" class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Nome") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Referente") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Contatti") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("N. Libri") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php if ($fornitori === []): ?>
              <tr>
                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                  <i class="fas fa-truck text-4xl text-gray-300 mb-3"></i>
                  <p><?= __("Nessun fornitore registrato") ?></p>
                </td>
              </tr>
              <?php else: ?>
                <?php foreach ($fornitori as $f): $fid = (int) $f['id']; ?>
                <tr class="hover:bg-gray-50 transition-colors" data-supplier-row data-name="<?= htmlspecialchars(mb_strtolower((string) $f['nome']), ENT_QUOTES, 'UTF-8') ?>">
                  <td class="px-6 py-4">
                    <a href="<?= htmlspecialchars(url('/admin/suppliers/edit/' . $fid), ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-medium text-gray-900 hover:text-blue-600 transition-colors">
                      <?= htmlspecialchars((string) ((string) $f['nome']), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php if (!empty($f['partita_iva'])): ?>
                      <div class="text-xs text-gray-500"><?= __("P. IVA") ?> <?= htmlspecialchars((string) ((string) $f['partita_iva']), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-700"><?= htmlspecialchars((string) ((string) ($f['referente'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 text-sm text-gray-700">
                    <?php if (!empty($f['email'])): ?><div><?= htmlspecialchars((string) ((string) $f['email']), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <?php if (!empty($f['telefono'])): ?><div class="text-xs text-gray-500"><?= htmlspecialchars((string) ((string) $f['telefono']), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-center">
                    <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-gray-100 text-gray-800 text-xs font-medium" data-supplier-count><?= (int) $f['libri_count'] ?></span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                      <a href="<?= htmlspecialchars(url('/admin/books?fornitore=' . $fid), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary" title="<?= htmlspecialchars(__('Libri di questo fornitore'), ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fas fa-book"></i><span class="sr-only"><?= __('Libri di questo fornitore') ?></span>
                      </a>
                      <a href="<?= htmlspecialchars(url('/admin/suppliers/edit/' . $fid), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary" title="<?= htmlspecialchars(__('Modifica'), ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fas fa-edit"></i><span class="sr-only"><?= __('Modifica') ?></span>
                      </a>
                      <form method="post" action="<?= htmlspecialchars(url('/admin/suppliers/delete/' . $fid), ENT_QUOTES, 'UTF-8') ?>" class="supplier-delete-form" data-name="<?= htmlspecialchars((string) $f['nome'], ENT_QUOTES, 'UTF-8') ?>" data-count="<?= (int) $f['libri_count'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn-danger" title="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>">
                          <i class="fas fa-trash"></i><span class="sr-only"><?= __('Elimina') ?></span>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const search = document.getElementById('search_fornitore');
  if (search) {
    // Same rules as App\Support\ExactSearch (#501): a word matches whatever
    // its accents ("muller" finds Müller), a quoted group matches the exact
    // spelling ("Muller" does not find Müller), case is ignored, and every
    // term must match. An unclosed quote runs to the end, and a straight or
    // closing double quote right after a digit outside a group is a literal
    // inch mark (12" stays 12"). Lower-casing is locale-free (toLowerCase),
    // so a Turkish browser does not turn "IKEA" into "ıkea".
    const QUOTES = '"“”„«»';
    const INCH = '\uE000';
    const fold = function (s) { return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); };
    const exact = function (s) { return s.normalize('NFC').replace(/\s+/g, ' ').toLowerCase(); };
    const protectInchMarks = function (query) {
      let open = false;
      let prev = '';
      let out = '';
      for (const ch of query) {
        let c = ch;
        if (QUOTES.includes(ch)) {
          if (!open && (ch === '"' || ch === '”') && /^[0-9]$/.test(prev)) {
            c = INCH;
          } else {
            open = !open;
          }
        }
        out += c;
        prev = c;
      }
      return out;
    };
    const terms = function (query) {
      const out = [];
      const re = /["“”„«»]([^"“”„«»]*)(?:["“”„«»]|$)|([^\s"“”„«»]+)/g;
      const q = protectInchMarks(query);
      let m;
      while ((m = re.exec(q)) !== null) {
        if (m[1] !== undefined) {
          const phrase = m[1].trim().replace(/\s+/g, ' ');
          if (phrase !== '') { out.push({ term: exact(phrase), exact: true }); }
        } else {
          out.push({ term: fold(m[2].split(INCH).join('"')), exact: false });
        }
      }
      return out;
    };
    search.addEventListener('input', function () {
      const wanted = terms(search.value);
      document.querySelectorAll('[data-supplier-row]').forEach(function (row) {
        const name = row.dataset.name || '';
        const exactName = exact(name);
        const foldedName = fold(name);
        const match = wanted.every(function (t) { return (t.exact ? exactName : foldedName).includes(t.term); });
        row.classList.toggle('hidden', !match);
      });
    });
  }

  document.querySelectorAll('.supplier-delete-form').forEach(function (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      const count = parseInt(form.dataset.count || '0', 10);
      const text = count > 0
        ? <?= json_encode(__("I %d libri collegati resteranno nel catalogo senza fornitore."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('%d', String(count))
        : <?= json_encode(__("Il fornitore non ha libri collegati."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      // The supplier name is user data: it only ever goes into `text`,
      // which SweetAlert2 renders as plain text (its `title` is HTML).
      const question = <?= json_encode(__("Eliminare il fornitore \"%s\"?"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('%s', form.dataset.name || '');
      const result = await window.SwalApp.confirm({
        title: <?= json_encode(__("Elimina"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        text: question + ' ' + text,
        confirmText: <?= json_encode(__("Sì, elimina"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
      });
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
});
</script>
