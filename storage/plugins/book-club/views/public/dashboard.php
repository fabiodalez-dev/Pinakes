<?php
/**
 * Book Club — personal multi-club dashboard (/my/book-clubs).
 *
 * @var list<array{club: array<string, mixed>, snapshot: array{current_books: list<array<string, mixed>>, next_meeting: array<string, mixed>|null, open_polls: list<array<string, mixed>>}}> $cards
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="max-w-6xl mx-auto px-4 py-10">
  <div class="flex items-center justify-between mb-8">
    <h1 class="text-3xl font-bold text-gray-900"><?= $e(__('I miei club di lettura')) ?></h1>
    <a href="<?= $e(url('/book-club')) ?>" class="text-sm text-blue-600 hover:underline"><?= $e(__('Esplora i club')) ?></a>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($cards)): ?>
    <div class="text-center py-16 text-gray-400">
      <i class="fas fa-book-open text-4xl mb-4"></i>
      <p><?= $e(__('Non fai ancora parte di nessun club.')) ?></p>
      <a href="<?= $e(url('/book-club')) ?>" class="inline-block mt-4 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg"><?= $e(__('Esplora i club')) ?></a>
    </div>
  <?php endif; ?>

  <div class="space-y-6">
    <?php foreach ($cards as $card): ?>
      <?php $club = $card['club']; $snap = $card['snapshot']; ?>
      <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="h-1.5" style="background: <?= $e($club['color']) ?>"></div>
        <div class="p-5">
          <div class="flex items-center justify-between mb-4">
            <a href="<?= $e(url('/book-club/' . $club['slug'])) ?>" class="text-xl font-semibold text-gray-900 hover:text-blue-600"><?= $e($club['name']) ?></a>
            <span class="text-xs text-gray-400"><?= $e($club['role_name'] ?? '') ?><?= ($club['member_status'] ?? '') === 'pending' ? ' · ' . $e(__('adesione in attesa di approvazione')) : '' ?></span>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-5 text-sm">
            <div>
              <div class="text-xs font-medium text-gray-400 uppercase mb-2"><?= $e(__('Lettura corrente')) ?></div>
              <?php if (empty($snap['current_books'])): ?>
                <p class="text-gray-400"><?= $e(__('Nessun libro in lettura.')) ?></p>
              <?php endif; ?>
              <?php foreach ($snap['current_books'] as $book): ?>
                <div class="mb-1">
                  <span class="font-medium text-gray-800"><?= $e($book['titolo']) ?></span>
                  <?php if (!empty($book['reading_ends'])): ?>
                    <span class="text-xs text-gray-400 ml-1"><?= $e(__('fino al')) ?> <?= $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) ?></span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div>
              <div class="text-xs font-medium text-gray-400 uppercase mb-2"><?= $e(__('Prossimo incontro')) ?></div>
              <?php if ($snap['next_meeting'] !== null): ?>
                <div class="font-medium text-gray-800"><?= $e($snap['next_meeting']['title']) ?></div>
                <div class="text-xs text-gray-500"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $snap['next_meeting']['starts_at']))) ?></div>
              <?php else: ?>
                <p class="text-gray-400"><?= $e(__('Nessun incontro in programma.')) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <div class="text-xs font-medium text-gray-400 uppercase mb-2"><?= $e(__('Votazioni aperte')) ?></div>
              <?php if (empty($snap['open_polls'])): ?>
                <p class="text-gray-400"><?= $e(__('Nessuna votazione aperta.')) ?></p>
              <?php endif; ?>
              <?php foreach ($snap['open_polls'] as $poll): ?>
                <div class="mb-1">
                  <a class="text-blue-600 hover:underline" href="<?= $e(url('/book-club/' . $club['slug'] . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
                  <?php if (!empty($poll['closes_at'])): ?>
                    <span class="text-xs text-gray-400 ml-1"><?= $e(__('scade il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']))) ?></span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
