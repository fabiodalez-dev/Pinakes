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
<section class="bc-card">
  <div class="bc-section-header">
    <i class="fas fa-landmark"></i>
    <h2><?= $e(__('Disponibilità in biblioteca')) ?></h2>
  </div>
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
    <div class="border rounded-3 px-3 py-3 mb-3">
      <div class="d-flex align-items-start gap-3">
        <?php if (!empty($book['copertina_url'])): ?>
          <img src="<?= $e($book['copertina_url']) ?>" alt="" class="bc-cover flex-shrink-0" loading="lazy">
        <?php endif; ?>
        <div class="flex-grow-1 overflow-hidden">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
              <div class="fw-semibold"><?= $e($book['titolo']) ?></div>
              <?php if (!empty($book['autori'])): ?><div class="bc-muted"><?= $e($book['autori']) ?></div><?php endif; ?>
            </div>
            <span class="bc-badge <?= $available > 0 ? 'bc-badge-open' : 'bc-badge-warn' ?> text-nowrap">
              <?= $e(sprintf(__('%1$d copie disponibili su %2$d'), $available, $total)) ?>
            </span>
          </div>

          <div class="d-flex flex-wrap gap-3 bc-muted small mt-2">
            <span><i class="fas fa-hourglass-half me-1"></i><?= $e(sprintf(__n('%d prenotazione in lista d\'attesa', '%d prenotazioni in lista d\'attesa', $waitlist), $waitlist)) ?></span>
            <span><i class="fas fa-users me-1"></i><?= $e(sprintf(__('in coda dal club: %d'), $clubWaitlist)) ?></span>
            <span><i class="fas fa-book-reader me-1"></i><?= $e(sprintf(__n('%d membro del club lo ha in prestito', '%d membri del club lo hanno in prestito', $loanCount), $loanCount)) ?></span>
          </div>

          <?php if ($canManage && $loans !== []): ?>
            <div class="bc-muted small mt-1">
              <i class="fas fa-user-lock me-1"></i><?= $e(__('In prestito a:')) ?>
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
            <div class="alert alert-warning small py-2 px-3 mt-2 mb-0">
              <i class="fas fa-exclamation-triangle me-1"></i>
              <?= $e(sprintf(__('%1$d partecipanti, %2$d copie: valuta l\'acquisto di altre copie o l\'allungamento dei tempi di lettura.'), $yesRsvps, $total)) ?>
            </div>
          <?php endif; ?>

          <div class="mt-2">
            <a href="<?= $e($bookUrl) ?>" class="small">
              <?= $e(__('Vai alla scheda del libro per prenotare la tua copia')) ?> <i class="fas fa-arrow-right ms-1"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</section>
