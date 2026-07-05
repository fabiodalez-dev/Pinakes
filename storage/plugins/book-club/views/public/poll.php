<?php
/**
 * Book Club — poll page: ballot for members, live results, winner banner.
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed> $poll
 * @var list<array<string, mixed>> $options
 * @var array<int, list<string>> $voters   option_id → names (public polls only)
 * @var list<int> $myVotes                 option ids picked by the current user
 * @var bool $isMember
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$isOpen = $poll['status'] === 'open';
$maxVotes = $poll['mode'] === 'multi' ? (int) $poll['votes_per_member'] : 1;
$totalScore = 0.0;
foreach ($options as $option) {
    $totalScore += (float) $option['score'];
}
?>
<div class="max-w-3xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e($club['name']) ?>
  </a>

  <div class="bg-white rounded-xl shadow p-6 mt-4">
    <div class="flex items-start justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($poll['title']) ?></h1>
        <?php if (!empty($poll['description'])): ?>
          <p class="text-gray-500 mt-2 whitespace-pre-line"><?= $e($poll['description']) ?></p>
        <?php endif; ?>
        <div class="text-xs text-gray-400 mt-2">
          <?= $poll['mode'] === 'multi'
              ? $e(sprintf(__n('Preferenza multipla: %d voto a testa', 'Preferenza multipla: %d voti a testa', $maxVotes), $maxVotes))
              : $e(__('Voto singolo')) ?>
          · <?= $poll['anonymity'] === 'secret' ? $e(__('voto segreto')) : $e(__('voto pubblico')) ?>
          <?php if (!empty($poll['closes_at'])): ?>
            · <?= $isOpen ? $e(__('scade il')) : $e(__('scaduta il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']))) ?>
          <?php endif; ?>
        </div>
      </div>
      <span class="px-3 py-1 text-xs rounded-full <?= $isOpen ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500' ?>">
        <?= $isOpen ? $e(__('Aperta')) : $e(__('Chiusa')) ?>
      </span>
    </div>

    <?php if (!empty($flash)): ?>
      <div class="mt-4 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
        <?= $e($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!$isOpen && $poll['winner_club_book_id'] !== null): ?>
      <?php foreach ($options as $option): ?>
        <?php if ((int) $option['club_book_id'] === (int) $poll['winner_club_book_id']): ?>
          <div class="mt-4 px-4 py-3 rounded-lg bg-blue-50 text-blue-900 text-sm">
            <i class="fas fa-trophy mr-2"></i><?= $e(sprintf(__('Il club ha scelto: %s'), (string) $option['titolo'])) ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/vote')) ?>" class="mt-6 space-y-3">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <?php foreach ($options as $option): ?>
        <?php
          $pct = $totalScore > 0 ? (float) $option['score'] / $totalScore * 100 : 0;
          $isWinner = !$isOpen && $poll['winner_club_book_id'] !== null && (int) $option['club_book_id'] === (int) $poll['winner_club_book_id'];
        ?>
        <label class="block border rounded-lg p-3 <?= $isWinner ? 'border-blue-300 bg-blue-50/40' : 'border-gray-200' ?> <?= $isOpen && $isMember ? 'cursor-pointer hover:border-blue-300' : '' ?>">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <?php if ($isOpen && $isMember): ?>
                <input type="<?= $maxVotes > 1 ? 'checkbox' : 'radio' ?>" name="options[]" value="<?= (int) $option['id'] ?>"
                       <?= in_array((int) $option['id'], $myVotes, true) ? 'checked' : '' ?> class="rounded">
              <?php endif; ?>
              <?php if (!empty($option['copertina_url'])): ?>
                <img src="<?= $e($option['copertina_url']) ?>" alt="" class="w-8 h-12 object-cover rounded shadow-sm" loading="lazy">
              <?php endif; ?>
              <div>
                <div class="font-medium text-gray-900"><?= $e($option['titolo']) ?><?= $isWinner ? ' 🏆' : '' ?></div>
                <?php if (!empty($option['autori'])): ?><div class="text-sm text-gray-500"><?= $e($option['autori']) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="text-right text-sm text-gray-500 whitespace-nowrap">
              <?= (int) $option['vote_count'] ?> <?= $e(__n('voto', 'voti', (int) $option['vote_count'])) ?>
            </div>
          </div>
          <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width: <?= number_format($pct, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
          </div>
          <?php if (!empty($voters[(int) $option['id']])): ?>
            <div class="mt-1 text-xs text-gray-400"><?= $e(implode(', ', $voters[(int) $option['id']])) ?></div>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>

      <?php if ($isOpen && $isMember): ?>
        <div class="flex items-center justify-between pt-2">
          <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
            <?= $myVotes === [] ? $e(__('Vota')) : $e(__('Aggiorna il mio voto')) ?>
          </button>
          <?php if ($maxVotes > 1): ?>
            <span class="text-xs text-gray-400"><?= $e(sprintf(__('Puoi selezionare fino a %d libri.'), $maxVotes)) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($isOpen && !$isMember): ?>
        <p class="text-sm text-gray-400 pt-2"><?= $e(__('Solo i membri attivi del club possono votare.')) ?></p>
      <?php endif; ?>
    </form>

    <?php if ($isOpen && $canManage): ?>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/close')) ?>" class="mt-6 border-t pt-4"
            onsubmit="return confirm('<?= $e(__('Chiudere la votazione adesso? Il libro più votato avanzerà nel workflow.')) ?>');">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="text-sm text-red-600 hover:underline"><?= $e(__('Chiudi la votazione adesso')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>
