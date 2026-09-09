<?php
/**
 * Emeroteca — merge of two duplicate testate: preview + confirmation, and
 * the summary rendered right after the merge.
 *
 * Chrome copied verbatim from the sibling admin views of this plugin
 * (index.php for the header/table, issues.php for the notice/card blocks).
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
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<div id="emeroteca-admin-merge" class="emeroteca-admin">
    <header class="emt-page-header">
        <nav aria-label="breadcrumb" class="text-sm text-gray-500 mb-4">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp; <?= __('Unisci testate') ?>
        </nav>
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?= __('Unisci testate') ?></h1>
            <p class="mt-1 text-sm text-gray-600">
                <?= __('Le annate, i fascicoli, lo spoglio e gli abbonamenti della testata di origine passano alla testata di destinazione; la testata di origine viene eliminata.') ?>
            </p>
        </div>
    </header>

<?php if ($mode === 'done' && $summary !== null): ?>
    <?php $rinumerati = is_array($summary['rinumerati'] ?? null) ? $summary['rinumerati'] : []; ?>
    <section class="card emt-actions-panel mb-6" aria-label="<?= $e(__('Riepilogo dell\'unione')) ?>">
        <div class="card-header">
            <h2 class="form-section-title flex items-center gap-2">
                <i class="fas fa-code-merge text-gray-600" aria-hidden="true"></i>
                <?= __('Riepilogo dell\'unione') ?>
            </h2>
        </div>
        <div class="emt-year__body">
            <p class="text-sm text-gray-700 mb-3">
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
                <div class="emt-notice bg-yellow-50 text-yellow-900 mt-4" role="status">
                    <p class="text-sm text-yellow-800">
                        <strong><?= $e(sprintf(__('Fascicoli rinumerati per conflitto: %d'), count($rinumerati))) ?></strong>
                        <?= __('Nessun fascicolo è stato eliminato: i duplicati sono stati conservati con un numero alternativo, da verificare a mano.') ?>
                    </p>
                </div>
                <div class="emt-surface overflow-hidden mt-3">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Anno') ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Numero in conflitto') ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Nuovo numero') ?></th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Copia rinumerata') ?></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($rinumerati as $item): ?>
                                    <tr class="hover:bg-gray-50 border-b">
                                        <td class="px-4 py-2 text-sm text-gray-600"><?= (int) ($item['anno'] ?? 0) ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-900 font-medium"><?= $e($item['numero'] ?? '') ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-900 font-mono"><?= $e($item['nuovo'] ?? '') ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-600">
                                            <?= (string) ($item['lato'] ?? '') === 'destinazione'
                                                ? __('della testata di destinazione')
                                                : __('della testata di origine') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mt-4 flex items-center gap-2">
                <a href="<?= $e(url('/admin/periodicals/' . (int) ($summary['target_id'] ?? 0) . '/issues')) ?>"
                   class="btn-primary inline-flex items-center text-sm">
                    <?= __('Apri i fascicoli della testata unita') ?>
                </a>
                <a href="<?= $e(url('/admin/periodicals')) ?>" class="btn-secondary text-sm">
                    <?= __('Torna all\'elenco') ?>
                </a>
            </div>
        </div>
    </section>
<?php else: ?>
    <div class="emt-notice bg-yellow-50 text-yellow-900 mb-6" role="status">
        <p class="text-sm text-yellow-800">
            <strong><?= __('Regola di risoluzione dei conflitti') ?></strong>
            <?= __('Se le due testate hanno la stessa annata, i fascicoli confluiscono in quella di destinazione. Se hanno anche lo stesso numero, il numero resta al fascicolo posseduto (prima la destinazione, poi l\'origine); l\'altro non viene eliminato ma rinumerato con il suffisso «-dup» e ti viene elencato al termine.') ?>
            <?= __('Le annate con dati descrittivi discordanti restano separate con un volume distinto.') ?>
        </p>
    </div>

    <form method="POST" action="<?= $e(url('/admin/periodicals/merge')) ?>"
          onsubmit="return confirm(<?= $e(json_encode(__('Unire le due testate? La testata di origine verrà eliminata.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="emt-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Destinazione') ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Testata') ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('ISSN') ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Editore') ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Annate') ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Fascicoli') ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Abbonamenti') ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($testate as $t): ?>
                            <?php
                            $tid = (int) ($t['id'] ?? 0);
                            $s = $stats[$tid] ?? ['n_annate' => 0, 'n_fascicoli' => 0, 'n_abbonamenti' => 0];
                            ?>
                            <tr class="hover:bg-gray-50 border-b">
                                <td class="px-4 py-2">
                                    <input type="hidden" name="ids[]" value="<?= $tid ?>">
                                    <label class="emt-checkbox-label">
                                        <input type="radio" name="target_id" value="<?= $tid ?>"
                                               class="form-radio" required
                                            <?= $tid === $suggestedTarget ? 'checked' : '' ?>>
                                        <span><?= __('Mantieni questa') ?></span>
                                    </label>
                                </td>
                                <td class="px-4 py-2">
                                    <a href="<?= $e(url('/admin/periodicals/' . $tid . '/issues')) ?>"
                                       class="text-gray-900 font-medium hover:underline"><?= $e($t['titolo'] ?? '') ?></a>
                                    <?php if (!empty($t['sottotitolo'])): ?>
                                        <div class="text-xs text-gray-500"><?= $e($t['sottotitolo']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs text-gray-500"><?= $e($t['issn'] ?? '') ?></td>
                                <td class="px-4 py-2 text-sm text-gray-600"><?= $e($t['editore_nome'] ?? '') ?></td>
                                <td class="px-4 py-2 text-sm text-gray-600"><?= (int) $s['n_annate'] ?></td>
                                <td class="px-4 py-2 text-sm text-gray-600"><?= (int) $s['n_fascicoli'] ?></td>
                                <td class="px-4 py-2 text-sm text-gray-600"><?= (int) $s['n_abbonamenti'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 flex items-center gap-2">
            <button type="submit" class="btn-primary inline-flex items-center text-sm">
                <i class="fas fa-code-merge mr-2" aria-hidden="true"></i>
                <?= __('Unisci le testate') ?>
            </button>
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="btn-secondary text-sm">
                <?= __('Annulla') ?>
            </a>
        </div>
    </form>
<?php endif; ?>
</div>
