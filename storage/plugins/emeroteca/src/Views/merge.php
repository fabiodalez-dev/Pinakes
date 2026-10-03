<?php
/**
 * Emeroteca — merge of two duplicate testate: preview + confirmation, and
 * the summary rendered right after the merge.
 *
 * Chrome copied verbatim from the core admin pages: page shell, breadcrumb and
 * header of app/Views/libri/crea_libro.php, sections as .card with an icon
 * title, action row of app/Views/libri/partials/book_form.php.
 *
 * Same defensive `?? ` defaults (and the matching nullable @var types) as the
 * sibling views of this plugin: a view must render something sane even when a
 * caller forgets a key.
 *
 * @var string|null $mode                       'confirm' or 'done'
 * @var array<int, array<string, mixed>>|null $testate
 * @var array<int, array{n_annate:int, n_fascicoli:int, n_abbonamenti:int}>|null $stats
 * @var int|null $suggested_target
 * @var array<string, mixed>|null $summary
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mode = ($mode ?? 'confirm') === 'done' ? 'done' : 'confirm';
$testate = $testate ?? [];
$stats = $stats ?? [];
$suggestedTarget = (int) ($suggested_target ?? 0);
$summary = is_array($summary ?? null) ? $summary : null;
$csrf = $e(\App\Support\Csrf::ensureToken());
?>
<div id="emeroteca-admin-merge" class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= $e(url('/admin/periodicals')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-newspaper mr-1"></i><?= __("Emeroteca") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <?= __('Unisci testate') ?>
        </li>
      </ol>
    </nav>

    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= __('Unisci testate') ?></h1>
      <p class="text-gray-600">
        <?= __('Le annate, i fascicoli, lo spoglio e gli abbonamenti della testata di origine passano alla testata di destinazione; la testata di origine viene eliminata.') ?>
      </p>
    </div>

<?php if ($mode === 'done' && $summary !== null): ?>
    <?php $rinumerati = is_array($summary['rinumerati'] ?? null) ? $summary['rinumerati'] : []; ?>
    <div class="space-y-8">
      <section class="card" aria-label="<?= $e(__('Riepilogo dell\'unione')) ?>">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-code-merge text-gray-900" aria-hidden="true"></i>
            <?= __('Riepilogo dell\'unione') ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <p class="text-sm text-gray-700">
            <?= $e(sprintf(
                __('«%1$s» è stata unita in «%2$s».'),
                (string) ($summary['source_titolo'] ?? ''),
                (string) ($summary['target_titolo'] ?? '')
            )) ?>
          </p>
          <ul class="text-sm text-gray-700 list-disc pl-5 space-y-1">
            <li><?= $e(sprintf(__('Annate spostate: %d'), (int) ($summary['annate_spostate'] ?? 0))) ?></li>
            <li><?= $e(sprintf(__('Annate fuse con una già esistente: %d'), (int) ($summary['annate_fuse'] ?? 0))) ?></li>
            <li><?= $e(sprintf(__('Fascicoli spostati: %d'), (int) ($summary['fascicoli_spostati'] ?? 0))) ?></li>
            <li><?= $e(sprintf(__('Abbonamenti spostati: %d'), (int) ($summary['abbonamenti_spostati'] ?? 0))) ?></li>
            <li><?= $e(sprintf(__('Testate ricollegate alla nuova testata precedente: %d'), (int) ($summary['testate_ricollegate'] ?? 0))) ?></li>
          </ul>

          <?php if ($rinumerati !== []): ?>
            <div class="alert alert-warning" role="status">
              <i class="fas fa-exclamation-triangle mr-2" aria-hidden="true"></i>
              <strong><?= $e(sprintf(__('Fascicoli rinumerati per conflitto: %d'), count($rinumerati))) ?></strong>
              <?= __('Nessun fascicolo è stato eliminato: i duplicati sono stati conservati con un numero alternativo, da verificare a mano.') ?>
            </div>
            <div class="overflow-x-auto">
              <table class="merge-table min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th><?= __('Anno') ?></th>
                    <th><?= __('Numero in conflitto') ?></th>
                    <th><?= __('Nuovo numero') ?></th>
                    <th><?= __('Copia rinumerata') ?></th>
                  </tr>
                </thead>
                <tbody class="bg-white">
                  <?php foreach ($rinumerati as $item): ?>
                    <tr>
                      <td class="text-gray-600"><?= (int) ($item['anno'] ?? 0) ?></td>
                      <td class="text-gray-900 font-medium"><?= $e($item['numero'] ?? '') ?></td>
                      <td class="text-gray-900 font-mono"><?= $e($item['nuovo'] ?? '') ?></td>
                      <td class="text-gray-600">
                        <?= (string) ($item['lato'] ?? '') === 'destinazione'
                            ? __('della testata di destinazione')
                            : __('della testata di origine') ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= $e(url('/admin/periodicals')) ?>" class="btn-secondary order-2 sm:order-1">
          <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i>
          <?= __('Torna all\'elenco') ?>
        </a>
        <a href="<?= $e(url('/admin/periodicals/' . (int) ($summary['target_id'] ?? 0) . '/issues')) ?>"
           class="btn-primary order-1 sm:order-2">
          <i class="fas fa-layer-group mr-2" aria-hidden="true"></i>
          <?= __('Apri i fascicoli della testata unita') ?>
        </a>
      </div>
    </div>
<?php else: ?>
    <div class="alert alert-warning mb-8" role="status">
      <i class="fas fa-exclamation-triangle mr-2" aria-hidden="true"></i>
      <strong><?= __('Regola di risoluzione dei conflitti') ?></strong>
      <?= __('Se le due testate hanno la stessa annata, i fascicoli confluiscono in quella di destinazione. Se hanno anche lo stesso numero, il numero resta al fascicolo posseduto (prima la destinazione, poi l\'origine); l\'altro non viene eliminato ma rinumerato con il suffisso «-dup» e ti viene elencato al termine.') ?>
      <?= __('Le annate con dati descrittivi discordanti restano separate con un volume distinto.') ?>
    </div>

    <form method="POST" action="<?= $e(url('/admin/periodicals/merge')) ?>" class="space-y-8"
          onsubmit="return confirm(<?= $e(json_encode(__('Unire le due testate? La testata di origine verrà eliminata.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-newspaper text-gray-900" aria-hidden="true"></i>
            <?= __('Testate') ?>
          </h2>
        </div>
        <div class="card-body">
          <div class="overflow-x-auto">
            <table class="merge-table min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th><?= __('Destinazione') ?></th>
                  <th><?= __('Testata') ?></th>
                  <th><?= __('ISSN') ?></th>
                  <th><?= __('Editore') ?></th>
                  <th><?= __('Annate') ?></th>
                  <th><?= __('Fascicoli') ?></th>
                  <th><?= __('Abbonamenti') ?></th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <?php foreach ($testate as $t): ?>
                  <?php
                  $tid = (int) ($t['id'] ?? 0);
                  $s = $stats[$tid] ?? ['n_annate' => 0, 'n_fascicoli' => 0, 'n_abbonamenti' => 0];
                  ?>
                  <tr>
                    <td class="whitespace-nowrap">
                      <input type="hidden" name="ids[]" value="<?= $tid ?>">
                      <label class="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                        <input type="radio" name="target_id" value="<?= $tid ?>"
                               class="w-4 h-4 border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer" required
                            <?= $tid === $suggestedTarget ? 'checked' : '' ?>>
                        <span><?= __('Mantieni questa') ?></span>
                      </label>
                    </td>
                    <td>
                      <a href="<?= $e(url('/admin/periodicals/' . $tid . '/issues')) ?>"
                         class="font-medium text-gray-900 hover:underline"><?= $e($t['titolo'] ?? '') ?></a>
                      <?php if (!empty($t['sottotitolo'])): ?>
                        <div class="text-xs text-gray-500 italic mt-0.5"><?= $e($t['sottotitolo']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap"><span class="text-xs font-mono text-gray-600"><?= $e($t['issn'] ?? '') ?></span></td>
                    <td class="text-gray-600"><?= $e($t['editore_nome'] ?? '') ?></td>
                    <td class="text-gray-600"><?= (int) $s['n_annate'] ?></td>
                    <td class="text-gray-600"><?= (int) $s['n_fascicoli'] ?></td>
                    <td class="text-gray-600"><?= (int) $s['n_abbonamenti'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= $e(url('/admin/periodicals')) ?>" class="btn-secondary order-2 sm:order-1">
          <i class="fas fa-times mr-2"></i>
          <?= __('Annulla') ?>
        </a>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-code-merge mr-2" aria-hidden="true"></i>
          <?= __('Unisci le testate') ?>
        </button>
      </div>
    </form>
<?php endif; ?>
  </div>
</div>

<style>
/* Table chrome of the core book list (app/Views/libri/index.php). */
.merge-table { border: 1px solid #e5e7eb; width: 100%; }
.merge-table thead th {
  border-bottom: 1px solid #e5e7eb;
  background: #f9fafb;
  color: #4b5563;
  font-size: .75rem;
  font-weight: 600;
  letter-spacing: .025em;
  padding: .75rem;
  text-align: left;
  text-transform: uppercase;
  white-space: nowrap;
}
.merge-table tbody td {
  border-bottom: 1px solid #f3f4f6;
  font-size: .875rem;
  padding: .75rem;
  vertical-align: middle;
}
.merge-table tbody tr:hover { background: #f9fafb; }
</style>
