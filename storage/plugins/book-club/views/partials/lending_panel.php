<?php
/**
 * Book Club — club-page panel of the lending module (Prestito tra membri,
 * plan §7.17): count of open member offers, the viewer's active loans with
 * their due dates and the link to the full lending page. Rendered for
 * members/managers only.
 *
 * @var array<string, mixed> $club
 * @var int $openCount                          offers with status 'offered'
 * @var list<array<string, mixed>> $activeLoans my active loans (both sides)
 * @var int $userId
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-hand-holding-heart mr-2 text-gray-400"></i><?= $e(__('Prestito tra membri')) ?></h2>
    <a class="text-sm text-blue-600 hover:underline whitespace-nowrap" href="<?= $e(url('/book-club/' . $slug . '/lending')) ?>">
      <?= $e(__('Vai ai prestiti tra membri')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <p class="text-sm text-gray-600">
    <i class="fas fa-book-open mr-1 text-gray-400"></i>
    <?= $e(sprintf(__n('%d copia offerta dai membri', '%d copie offerte dai membri', $openCount), $openCount)) ?>
  </p>

  <?php if ($activeLoans !== []): ?>
    <div class="mt-3 text-sm text-gray-600">
      <div class="text-xs font-medium text-gray-500 mb-1"><?= $e(__('I miei prestiti attivi')) ?></div>
      <?php foreach ($activeLoans as $loan): ?>
        <?php
          $iAmLender = (int) $loan['lender_id'] === $userId;
          $otherName = $iAmLender
              ? trim((string) ($loan['borrower_nome'] ?? '') . ' ' . (string) ($loan['borrower_cognome'] ?? ''))
              : trim((string) $loan['lender_nome'] . ' ' . (string) $loan['lender_cognome']);
        ?>
        <div class="border-t first:border-t-0 py-1.5 flex flex-wrap items-center justify-between gap-2">
          <span class="min-w-0">
            <span class="font-medium text-gray-800"><?= $e($loan['titolo']) ?></span>
            <span class="text-xs text-gray-400">
              · <?= $e($iAmLender ? sprintf(__('Prestata a %s'), $otherName) : sprintf(__('Prestata da %s'), $otherName)) ?>
            </span>
          </span>
          <?php if (!empty($loan['due_on'])): ?>
            <span class="text-xs text-gray-400 whitespace-nowrap">
              <i class="far fa-calendar mr-1"></i><?= $e(sprintf(__('Da restituire entro il %s'), date('d/m/Y', (int) strtotime((string) $loan['due_on'])))) ?>
            </span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
