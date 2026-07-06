<?php
/**
 * Book Club — library module panel (plan §7.10): copy availability, active
 * reservation queue (whole library + the club members' share) and
 * club-member loans for every book being read.
 * Loan holder names are manager-only; regular members see only a count.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $books  rows from LibraryRepo::booksInStates
 *      enriched with member_loans, member_loan_count, max_yes_rsvps
 * @var bool $isMember
 * @var bool $canManage
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-landmark mr-2 text-gray-400"></i><?= $e(__('Disponibilità in biblioteca')) ?></h2>
  <?php foreach ($books as $book): ?>
    <?php
      $available = max(0, (int) $book['copie_disponibili']);
      $total = max(0, (int) $book['copie_totali']);
      $waitlist = max(0, (int) $book['waitlist']);
      $clubWaitlist = max(0, (int) ($book['club_waitlist'] ?? 0));
      $loanCount = (int) ($book['member_loan_count'] ?? 0);
      $loans = is_array($book['member_loans'] ?? null) ? $book['member_loans'] : [];
      $yesRsvps = (int) ($book['max_yes_rsvps'] ?? 0);
      $bookUrl = book_url(['id' => (int) $book['libro_id'], 'titolo' => (string) $book['titolo'], 'autori' => (string) ($book['autori'] ?? '')]);
    ?>
    <div class="border rounded-lg px-4 py-3 mb-3">
      <div class="flex items-start gap-3">
        <?php if (!empty($book['copertina_url'])): ?>
          <img src="<?= $e($book['copertina_url']) ?>" alt="" class="w-10 h-14 object-cover rounded shadow-sm" loading="lazy">
        <?php endif; ?>
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <div class="font-medium text-gray-900"><?= $e($book['titolo']) ?></div>
              <?php if (!empty($book['autori'])): ?><div class="text-sm text-gray-500"><?= $e($book['autori']) ?></div><?php endif; ?>
            </div>
            <span class="px-2 py-1 text-xs rounded-full whitespace-nowrap <?= $available > 0 ? 'bg-green-100 text-green-800' : 'bg-red-50 text-red-700' ?>">
              <?= $e(sprintf(__('%1$d copie disponibili su %2$d'), $available, $total)) ?>
            </span>
          </div>

          <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 mt-2">
            <span><i class="fas fa-hourglass-half mr-1 text-gray-400"></i><?= $e(sprintf(__n('%d prenotazione in lista d\'attesa', '%d prenotazioni in lista d\'attesa', $waitlist), $waitlist)) ?></span>
            <span><i class="fas fa-users mr-1 text-gray-400"></i><?= $e(sprintf(__('in coda dal club: %d'), $clubWaitlist)) ?></span>
            <span><i class="fas fa-book-reader mr-1 text-gray-400"></i><?= $e(sprintf(__n('%d membro del club lo ha in prestito', '%d membri del club lo hanno in prestito', $loanCount), $loanCount)) ?></span>
          </div>

          <?php if ($canManage && $loans !== []): ?>
            <div class="text-xs text-gray-400 mt-1">
              <i class="fas fa-user-lock mr-1"></i><?= $e(__('In prestito a:')) ?>
              <?php
                $names = [];
                foreach ($loans as $loan) {
                    $names[] = trim((string) $loan['nome'] . ' ' . (string) $loan['cognome']);
                }
              ?>
              <?= $e(implode(', ', $names)) ?>
            </div>
          <?php endif; ?>

          <?php if ($canManage && $total > 0 && $yesRsvps > $total): ?>
            <div class="mt-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              <?= $e(sprintf(__('%1$d partecipanti, %2$d copie: valuta l\'acquisto di altre copie o l\'allungamento dei tempi di lettura.'), $yesRsvps, $total)) ?>
            </div>
          <?php endif; ?>

          <div class="mt-2">
            <a href="<?= $e($bookUrl) ?>" class="text-xs text-blue-600 hover:underline">
              <?= $e(__('Vai alla scheda del libro per prenotare la tua copia')) ?> <i class="fas fa-arrow-right ml-1"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</section>
