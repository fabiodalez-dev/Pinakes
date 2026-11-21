<?php
/**
 * Book Club — personal multi-club dashboard (/my/book-clubs).
 *
 * @var list<array{club: array<string, mixed>, snapshot: array{current_books: list<array<string, mixed>>, next_meeting: array<string, mixed>|null, open_polls: list<array<string, mixed>>}}> $cards
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$corePartials = dirname(__DIR__, 5) . '/app/Views/frontend/partials';
$catalogPageStyles = true;
$heroTitle = __('I miei club di lettura');
$breadcrumbItems = [
    ['label' => __('Home'), 'href' => url('/')],
    ['label' => __('Club di lettura'), 'href' => url(\App\Support\RouteTranslator::route('book_club'))],
    ['label' => $heroTitle],
];
include $corePartials . '/catalog-hero.php';
?>
<?php $bookClubStyles = true; ?>
<style<?= csp_nonce_attr() ?>>
  /* Page-local helpers (my-clubs dashboard only). */
  .bc-club-accent{position:absolute;top:0;left:0;right:0;height:3px}
  .bc-link{color:var(--primary-text, var(--primary-color));font-weight:600;text-decoration:none}
  .bc-link:hover{text-decoration:underline}
  .bc-kicker{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.5rem}
  .bc-toolbar{display:flex;justify-content:flex-end;margin-bottom:1.5rem}
</style>
<div class="container py-4">
  <?php if (!empty($cards)): ?>
  <div class="bc-toolbar">
    <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club'))) ?>" class="bc-btn"><i class="fas fa-compass" aria-hidden="true"></i><?= $e(__('Esplora i club')) ?></a>
  </div>
  <?php endif; ?>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($cards)): ?>
    <?php
    $emptyIcon = 'fa-book-open';
    $emptyTitle = __('Non fai ancora parte di nessun club.');
    $emptyCtaHref = url(\App\Support\RouteTranslator::route('book_club'));
    $emptyCtaLabel = __('Esplora i club');
    include $corePartials . '/empty-state.php';
    ?>
  <?php endif; ?>

  <div>
    <?php foreach ($cards as $card): ?>
      <?php $club = $card['club']; $snap = $card['snapshot']; ?>
      <div class="bc-card relative overflow-hidden">
        <span class="bc-club-accent" style="background: <?= $e($club['color']) ?>"></span>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
          <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $club['slug'])) ?>" class="bc-link text-xl"><?= $e($club['name']) ?></a>
          <span class="bc-muted text-sm"><?= $e($club['role_name'] ?? '') ?><?= ($club['member_status'] ?? '') === 'pending' ? ' · ' . $e(__('adesione in attesa di approvazione')) : '' ?></span>
        </div>
        <div class="flex flex-wrap -mx-3 gap-y-4">
          <div class="w-full px-3 md:w-1/3">
            <div class="bc-kicker"><?= $e(__('Lettura corrente')) ?></div>
            <?php if (empty($snap['current_books'])): ?>
              <p class="bc-muted mb-0"><?= $e(__('Nessun libro in lettura.')) ?></p>
            <?php endif; ?>
            <?php foreach ($snap['current_books'] as $book): ?>
              <div class="mb-1">
                <span class="font-semibold"><?= $e($book['titolo']) ?></span>
                <?php if (!empty($book['reading_ends'])): ?>
                  <span class="bc-muted text-sm ml-1"><?= $e(__('fino al')) ?> <?= $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="w-full px-3 md:w-1/3">
            <div class="bc-kicker"><?= $e(__('Prossimo incontro')) ?></div>
            <?php if ($snap['next_meeting'] !== null): ?>
              <div class="font-semibold"><?= $e($snap['next_meeting']['title']) ?></div>
              <div class="bc-muted text-sm"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $snap['next_meeting']['starts_at']))) ?></div>
            <?php else: ?>
              <p class="bc-muted mb-0"><?= $e(__('Nessun incontro in programma.')) ?></p>
            <?php endif; ?>
          </div>
          <div class="w-full px-3 md:w-1/3">
            <div class="bc-kicker"><?= $e(__('Votazioni aperte')) ?></div>
            <?php if (empty($snap['open_polls'])): ?>
              <p class="bc-muted mb-0"><?= $e(__('Nessuna votazione aperta.')) ?></p>
            <?php endif; ?>
            <?php foreach ($snap['open_polls'] as $poll): ?>
              <div class="mb-1">
                <a class="bc-link" href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $club['slug'] . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
                <?php if (!empty($poll['closes_at'])): ?>
                  <span class="bc-muted text-sm ml-1"><?= $e(__('scade il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']))) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
