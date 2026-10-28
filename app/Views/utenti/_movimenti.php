<?php
/**
 * "Movimenti" section of the reader record (plan §7): balance, fines accruing
 * on open overdue loans, ledger rows, and the desk actions (payment, manual
 * charge, waiver). Included by dettagli_utente.php.
 *
 * @var int $id
 * @var int $ledgerBalanceCents
 * @var list<array<string, mixed>> $ledgerMovements
 * @var list<array{loan_id: int, titolo: string, data_scadenza: string, days: int, cents: int}> $ledgerAccrued
 * @var \App\Services\FineParameters $ledgerParams
 * @var bool $ledgerCanManage
 * @var int $ledgerPage
 * @var int $ledgerPages
 * @var int $ledgerPerPage
 * @var int $ledgerTotal
 */
use App\Services\FineParameters;
use App\Services\PatronLedger;

$ledgerE = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ledgerFmt = static fn (int $cents): string => FineParameters::format($cents);
$ledgerAccruedTotal = array_sum(array_map(static fn (array $a): int => $a['cents'], $ledgerAccrued));
$ledgerBlocked = $ledgerParams->debtBlockEnabled() && $ledgerBalanceCents > (int) $ledgerParams->debtBlockThresholdCents;
$ledgerFlash = is_scalar($_GET['ledger'] ?? null) ? (string) $_GET['ledger'] : '';
$ledgerError = is_scalar($_GET['ledger_error'] ?? null) ? (string) $_GET['ledger_error'] : '';
$ledgerMovementId = (int) (is_scalar($_GET['movement'] ?? null) ? $_GET['movement'] : 0);
$ledgerCsrf = App\Support\Csrf::ensureToken();
?>
  <!-- Movimenti (patron ledger) -->
  <div class="bg-white shadow-sm rounded-2xl border border-slate-200 mt-6" id="movimenti">
    <div class="px-6 py-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-gray-800"><?= __("Movimenti") ?></h2>
        <div class="text-right">
          <p class="text-sm text-gray-500"><?= __("Saldo") ?></p>
          <p class="text-2xl font-bold <?= $ledgerBalanceCents > 0 ? 'text-red-600' : 'text-green-600' ?>" data-ledger-balance="<?= (int) $ledgerBalanceCents ?>"><?= $ledgerE($ledgerFmt($ledgerBalanceCents)) ?></p>
        </div>
    </div>

    <div class="px-6 py-4 space-y-3">
      <?php if ($ledgerFlash !== ''): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 flex items-start gap-3" role="status" data-ledger-flash="<?= $ledgerE($ledgerFlash) ?>">
          <i class="fas fa-check-circle text-green-600 mt-0.5"></i>
          <div class="text-sm text-green-800">
            <?php if ($ledgerFlash === 'paid'): ?>
              <?= __("Pagamento registrato.") ?>
              <?php if ($ledgerMovementId > 0): ?>
                <a href="<?= htmlspecialchars(url('/admin/users/' . $id . '/ledger/receipt/' . $ledgerMovementId), ENT_QUOTES, 'UTF-8') ?>" class="font-medium underline" data-ledger-receipt><?= __("Scarica la ricevuta") ?></a>
              <?php endif; ?>
            <?php elseif ($ledgerFlash === 'charged'): ?>
              <?= __("Addebito registrato.") ?>
            <?php else: ?>
              <?= __("Condono registrato.") ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($ledgerError !== ''): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 flex items-start gap-3" role="alert" data-ledger-error="<?= $ledgerE($ledgerError) ?>">
          <i class="fas fa-exclamation-circle text-red-600 mt-0.5"></i>
          <p class="text-sm text-red-700"><?= $ledgerE(\App\Controllers\PatronLedgerController::errorMessage($ledgerError)) ?></p>
        </div>
      <?php endif; ?>
      <?php if ($ledgerBlocked): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-start gap-3" data-ledger-blocked>
          <i class="fas fa-ban text-amber-600 mt-0.5"></i>
          <p class="text-sm text-amber-700"><?= $ledgerE(sprintf(__('Prestiti bloccati: il saldo supera la soglia di %s.'), $ledgerFmt((int) $ledgerParams->debtBlockThresholdCents))) ?></p>
        </div>
      <?php endif; ?>
      <?php if ($ledgerAccrued !== []): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4" data-ledger-accrued="<?= (int) $ledgerAccruedTotal ?>">
          <p class="font-medium text-amber-900"><?= $ledgerE(sprintf(__('Multe in maturazione: %s'), $ledgerFmt($ledgerAccruedTotal))) ?></p>
          <p class="text-xs text-amber-700 mt-1"><?= __("Calcolate su oggi per i prestiti scaduti ancora aperti: vengono addebitate alla restituzione.") ?></p>
          <ul class="mt-2 text-sm text-amber-800 list-disc list-inside">
            <?php foreach ($ledgerAccrued as $acc): ?>
              <li><?= $ledgerE(sprintf(__('%1$s — %2$d giorni di ritardo: %3$s'), $acc['titolo'] !== '' ? $acc['titolo'] : __('Libro'), $acc['days'], $ledgerFmt($acc['cents']))) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm" id="ledger-movements">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left font-medium"><?= __("Data") ?></th>
                    <th scope="col" class="px-6 py-3 text-left font-medium"><?= __("Tipo") ?></th>
                    <th scope="col" class="px-6 py-3 text-left font-medium"><?= __("Descrizione") ?></th>
                    <th scope="col" class="px-6 py-3 text-right font-medium"><?= __("Importo") ?></th>
                    <th scope="col" class="px-6 py-3 text-right font-medium"><?= __("Azioni") ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php if ($ledgerMovements === []): ?>
                    <tr>
                        <td colspan="5" class="text-center py-10 text-gray-500">
                            <i class="fas fa-coins fa-2x mb-2"></i>
                            <p><?= __("Nessun movimento registrato.") ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ledgerMovements as $mov):
                        $movCents = FineParameters::decimalToCents($mov['importo']);
                        $movText = PatronLedger::describe($mov);
                    ?>
                        <tr class="hover:bg-slate-50 transition-colors" data-movement-type="<?= $ledgerE((string) $mov['tipo']) ?>">
                            <td class="px-6 py-4 whitespace-nowrap text-gray-700"><?= format_date((string) $mov['created_at'], true, '/') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900"><?= $ledgerE(PatronLedger::typeLabel((string) $mov['tipo'])) ?></td>
                            <td class="px-6 py-4 text-gray-700">
                                <?php if ($movText !== ''): ?><div><?= $ledgerE($movText) ?></div><?php endif; ?>
                                <?php if (!empty($mov['metodo_pagamento'])): ?><div class="text-gray-500"><?= $ledgerE(PatronLedger::methodLabel((string) $mov['metodo_pagamento'])) ?></div><?php endif; ?>
                                <?php if (!empty($mov['prestito_id'])): ?>
                                    <div class="text-gray-500">
                                        <a href="<?= htmlspecialchars(url('/admin/loans/details/' . (int) $mov['prestito_id']), ENT_QUOTES, 'UTF-8') ?>" class="hover:underline"><?= __("ID Prestito:") ?> <?= (int) $mov['prestito_id'] ?></a>
                                        <?php if (!empty($mov['libro_titolo'])): ?> — <?= $ledgerE((string) $mov['libro_titolo']) ?><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) ($mov['operatore'] ?? '')) !== ''): ?><div class="text-gray-500"><?= $ledgerE(sprintf(__('Registrato da %s'), (string) $mov['operatore'])) ?></div><?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-semibold <?= $movCents > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= $ledgerE($ledgerFmt($movCents)) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <?php if ((string) $mov['tipo'] === 'pagamento'): ?>
                                    <a href="<?= htmlspecialchars(url('/admin/users/' . $id . '/ledger/receipt/' . (int) $mov['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn-icon btn-secondary p-2 text-gray-500 hover:bg-gray-200 rounded-full transition-colors" title="<?= $ledgerE(__('Ricevuta PDF')) ?>" aria-label="<?= $ledgerE(__('Ricevuta PDF')) ?>">
                                        <i class="fas fa-file-pdf w-4 h-4"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($ledgerPages > 1):
        $ledgerFrom = ($ledgerPage - 1) * $ledgerPerPage + 1;
        $ledgerTo = min($ledgerTotal, $ledgerPage * $ledgerPerPage);
        $ledgerPageUrl = static fn (int $page): string => url('/admin/users/details/' . $id) . '?ledger_page=' . $page . '#movimenti';
    ?>
    <nav class="px-6 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-gray-600" data-ledger-pager aria-label="<?= $ledgerE(__('Movimenti')) ?>">
      <span><?= $ledgerE(sprintf(__('Movimenti %1$d–%2$d di %3$d'), $ledgerFrom, $ledgerTo, $ledgerTotal)) ?></span>
      <span class="flex gap-2">
        <?php if ($ledgerPage > 1): ?>
          <a href="<?= htmlspecialchars($ledgerPageUrl($ledgerPage - 1), ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-1 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg border border-gray-300" data-ledger-prev><?= __("Precedente") ?></a>
        <?php endif; ?>
        <?php if ($ledgerPage < $ledgerPages): ?>
          <a href="<?= htmlspecialchars($ledgerPageUrl($ledgerPage + 1), ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-1 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg border border-gray-300" data-ledger-next><?= __("Successiva") ?></a>
        <?php endif; ?>
      </span>
    </nav>
    <?php endif; ?>

    <?php if ($ledgerCanManage): ?>
    <div class="px-6 py-6 border-t border-slate-200 grid grid-cols-1 lg:grid-cols-3 gap-6">
      <form method="POST" action="<?= htmlspecialchars(url('/admin/users/' . $id . '/ledger/payment'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3" id="ledger-payment-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ledgerCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
        <h3 class="text-base font-medium text-gray-900"><?= __("Registra pagamento") ?></h3>
        <div>
          <label for="ledger_pay_amount" class="block text-sm font-medium text-gray-700"><?= __("Importo") ?> *</label>
          <input type="text" inputmode="decimal" id="ledger_pay_amount" name="importo" required value="<?= $ledgerBalanceCents > 0 ? $ledgerE(FineParameters::centsToDecimal($ledgerBalanceCents)) : '' ?>" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
        </div>
        <div>
          <label for="ledger_pay_method" class="block text-sm font-medium text-gray-700"><?= __("Metodo di pagamento") ?></label>
          <select id="ledger_pay_method" name="metodo_pagamento" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
            <?php foreach (PatronLedger::PAYMENT_METHODS as $method): ?>
              <option value="<?= $ledgerE($method) ?>"><?= $ledgerE(PatronLedger::methodLabel($method)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="ledger_pay_note" class="block text-sm font-medium text-gray-700"><?= __("Note") ?></label>
          <input type="text" id="ledger_pay_note" name="note" maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
          <i class="fas fa-cash-register mr-2"></i><?= __("Registra pagamento") ?>
        </button>
      </form>

      <form method="POST" action="<?= htmlspecialchars(url('/admin/users/' . $id . '/ledger/charge'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3" id="ledger-charge-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ledgerCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
        <h3 class="text-base font-medium text-gray-900"><?= __("Addebito manuale") ?></h3>
        <div>
          <label for="ledger_charge_amount" class="block text-sm font-medium text-gray-700"><?= __("Importo") ?> *</label>
          <input type="text" inputmode="decimal" id="ledger_charge_amount" name="importo" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
        </div>
        <div>
          <label for="ledger_charge_description" class="block text-sm font-medium text-gray-700"><?= __("Descrizione") ?> *</label>
          <input type="text" id="ledger_charge_description" name="descrizione" required maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
          <i class="fas fa-plus mr-2"></i><?= __("Registra addebito") ?>
        </button>
      </form>

      <form method="POST" action="<?= htmlspecialchars(url('/admin/users/' . $id . '/ledger/waive'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3" id="ledger-waive-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ledgerCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
        <h3 class="text-base font-medium text-gray-900"><?= __("Condona") ?></h3>
        <div>
          <label for="ledger_waive_amount" class="block text-sm font-medium text-gray-700"><?= __("Importo") ?> *</label>
          <input type="text" inputmode="decimal" id="ledger_waive_amount" name="importo" required class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
        </div>
        <div>
          <label for="ledger_waive_reason" class="block text-sm font-medium text-gray-700"><?= __("Motivazione") ?> *</label>
          <input type="text" id="ledger_waive_reason" name="motivazione" required maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
          <i class="fas fa-hand-holding-heart mr-2"></i><?= __("Condona") ?>
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>
