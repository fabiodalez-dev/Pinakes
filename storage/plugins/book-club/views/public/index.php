<?php
/**
 * Book Club — public directory of clubs.
 *
 * @var list<array<string, mixed>> $clubs
 * @var array<int, string> $mine  club_id → member_status for the logged-in user
 * @var bool $loggedIn
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$privacyLabels = [
    'public' => __('Pubblico'),
    'private' => __('Privato'),
    'invite' => __('Su invito'),
];
?>
<div class="max-w-6xl mx-auto px-4 py-10">
  <div class="flex items-center justify-between mb-8">
    <div>
      <h1 class="text-3xl font-bold text-gray-900"><?= $e(__('Club di lettura')) ?></h1>
      <p class="text-gray-500 mt-1"><?= $e(__('Leggi insieme: proposte, votazioni e incontri.')) ?></p>
    </div>
    <?php if ($loggedIn): ?>
      <a href="<?= $e(url('/my/book-clubs')) ?>" class="inline-flex items-center px-4 py-2 bg-gray-900 hover:bg-gray-700 text-white text-sm font-medium rounded-lg">
        <i class="fas fa-book-reader mr-2"></i><?= $e(__('I miei club')) ?>
      </a>
    <?php endif; ?>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($clubs)): ?>
    <div class="text-center py-16 text-gray-400">
      <i class="fas fa-book-open text-4xl mb-4"></i>
      <p><?= $e(__('Nessun club di lettura attivo al momento.')) ?></p>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($clubs as $club): ?>
      <a href="<?= $e(url('/book-club/' . $club['slug'])) ?>"
         class="block bg-white rounded-xl shadow hover:shadow-md transition-shadow overflow-hidden">
        <div class="h-2" style="background: <?= $e($club['color']) ?>"></div>
        <div class="p-5">
          <div class="flex items-center justify-between mb-2">
            <h2 class="text-lg font-semibold text-gray-900"><?= $e($club['name']) ?></h2>
            <?php if (isset($mine[(int) $club['id']])): ?>
              <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800">
                <?= $mine[(int) $club['id']] === 'active' ? $e(__('Membro')) : $e(__('In attesa')) ?>
              </span>
            <?php endif; ?>
          </div>
          <p class="text-sm text-gray-500 line-clamp-3"><?= $e(mb_substr((string) ($club['description'] ?? ''), 0, 180)) ?></p>
          <div class="flex items-center gap-4 mt-4 text-xs text-gray-400">
            <span><i class="fas fa-users mr-1"></i><?= (int) $club['member_count'] ?><?= $club['max_members'] !== null ? '/' . (int) $club['max_members'] : '' ?></span>
            <span><i class="fas fa-lock mr-1"></i><?= $e($privacyLabels[$club['privacy']] ?? $club['privacy']) ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
