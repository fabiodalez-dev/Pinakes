<?php
/**
 * Book Club — poll page: ballot for members (per-mode UI: simple/multi
 * radio-or-checkbox, stars 1–5 selects, ranking position selects,
 * elimination round-scoped radio, weighted with the poll's own documented
 * weights), live results, winner banner, quorum banner and admin tie
 * resolution.
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed> $poll
 * @var list<array<string, mixed>> $options
 * @var array<int, list<string>> $voters   option_id → names (public polls only)
 * @var list<int> $myVotes                 option ids picked by the current user
 * @var array<int, float> $myVoteValues    option_id → my vote value (stars/ranking)
 * @var array<int, int> $eliminated        option_id → eliminated_in_round (elimination)
 * @var bool $quorumFailed                 closed without winner because of the quorum
 * @var list<int> $adminTiedIds            tied option ids awaiting a manager's pick
 * @var bool $isMember
 * @var bool $canManage                    club managers (kept for non-close UI)
 * @var bool $canClose                     granular polls.close permission → close/pick-winner UI
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$isOpen = $poll['status'] === 'open';
$mode = (string) ($poll['mode'] ?? 'simple');
$round = max(1, (int) ($poll['round'] ?? 1));
$myVoteValues = $myVoteValues ?? [];
$eliminated = $eliminated ?? [];
$quorumFailed = $quorumFailed ?? false;
$adminTiedIds = $adminTiedIds ?? [];
$canClose = $canClose ?? $canManage;
$maxVotes = in_array($mode, ['multi', 'weighted'], true) ? (int) $poll['votes_per_member'] : 1;
$nOptions = count($options);
$activeCount = 0;
foreach ($options as $option) {
    if (!isset($eliminated[(int) $option['id']])) {
        $activeCount++;
    }
}
$totalScore = 0.0;
foreach ($options as $option) {
    $totalScore += (float) $option['score'];
}
$fmtScore = static function (float $s): string {
    $out = rtrim(rtrim(number_format($s, 2, ',', ''), '0'), ',');
    return $out === '' ? '0' : $out;
};
switch ($mode) {
    case 'multi':
        $modeLine = sprintf(__n('Preferenza multipla: %d voto a testa', 'Preferenza multipla: %d voti a testa', $maxVotes), $maxVotes);
        break;
    case 'stars':
        $modeLine = __('Stelle: valuta i libri da 1 a 5');
        break;
    case 'ranking':
        $modeLine = __('Classifica completa (conteggio Borda)');
        break;
    case 'elimination':
        $modeLine = sprintf(__('Eliminazione progressiva — turno %d'), $round);
        break;
    case 'weighted':
        $modeLine = __('Voto ponderato');
        break;
    default:
        $modeLine = __('Voto singolo');
}
$showScores = in_array($mode, ['stars', 'ranking', 'weighted'], true);
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
          <?= $e($modeLine) ?>
          · <?= $poll['anonymity'] === 'secret' ? $e(__('voto segreto')) : $e(__('voto pubblico')) ?>
          <?php if (!empty($poll['quorum_pct'])): ?>
            · <?= $e(sprintf(__('quorum %d%% dei membri attivi'), (int) $poll['quorum_pct'])) ?>
          <?php endif; ?>
          <?php if (!empty($poll['closes_at'])): ?>
            · <?= $isOpen ? $e(__('scade il')) : $e(__('scaduta il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']))) ?>
          <?php endif; ?>
        </div>
        <?php if ($mode === 'weighted'): ?>
          <?php
            // Per-poll weights (voting2); NULL on legacy polls → the old fixed defaults.
            $fmtWeight = static function (float $w): string {
                $s = rtrim(rtrim(number_format($w, 2, ',', ''), '0'), ',');
                return str_contains($s, ',') ? $s : $s . ',0'; // «2,0» / «1,5» / «2,25»
            };
            $weightOwner = isset($poll['weight_owner']) && is_numeric($poll['weight_owner']) ? (float) $poll['weight_owner'] : 2.0;
            $weightModerator = isset($poll['weight_moderator']) && is_numeric($poll['weight_moderator']) ? (float) $poll['weight_moderator'] : 1.5;
          ?>
          <div class="text-xs text-gray-400 mt-1">
            <i class="fas fa-balance-scale mr-1"></i><?= $e(sprintf(__('Pesi: fondatore ×%s · moderatore ×%s · membro ×1,0.'), $fmtWeight($weightOwner), $fmtWeight($weightModerator))) ?>
          </div>
        <?php endif; ?>
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

    <?php if (!$isOpen && $quorumFailed): ?>
      <div class="mt-4 px-4 py-3 rounded-lg bg-yellow-50 text-yellow-900 text-sm">
        <i class="fas fa-exclamation-triangle mr-2"></i><strong><?= $e(__('Quorum non raggiunto')) ?></strong>
        — <?= $e(__('la votazione si è chiusa senza vincitore e le proposte tornano tra i libri proposti.')) ?>
      </div>
    <?php endif; ?>

    <?php if (!$isOpen && $adminTiedIds !== []): ?>
      <div class="mt-4 px-4 py-3 rounded-lg bg-purple-50 text-purple-900 text-sm">
        <i class="fas fa-gavel mr-2"></i><?= $e(__('Parità in testa: un moderatore deve proclamare il vincitore.')) ?>
        <?php if ($canClose): ?>
          <div class="mt-3 space-y-2">
            <?php foreach ($options as $option): ?>
              <?php if (!in_array((int) $option['id'], $adminTiedIds, true)) { continue; } ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/pick-winner/' . (int) $option['id'])) ?>"
                    class="flex items-center justify-between gap-3"
                    onsubmit="return confirm('<?= $e(__('Proclamare questo libro vincitore? Avanzerà nel workflow.')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <span class="font-medium"><?= $e($option['titolo']) ?></span>
                <button type="submit" class="px-3 py-1 text-xs bg-purple-600 hover:bg-purple-700 text-white rounded-lg">
                  <?= $e(__('Proclama vincitore')) ?>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/vote')) ?>" class="mt-6 space-y-3">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <?php foreach ($options as $option): ?>
        <?php
          $optId = (int) $option['id'];
          $isEliminated = isset($eliminated[$optId]);
          $pct = $totalScore > 0 ? (float) $option['score'] / $totalScore * 100 : 0;
          $isWinner = !$isOpen && $poll['winner_club_book_id'] !== null && (int) $option['club_book_id'] === (int) $poll['winner_club_book_id'];
          $canBallot = $isOpen && $isMember && !$isEliminated;
        ?>
        <label class="block border rounded-lg p-3 <?= $isWinner ? 'border-blue-300 bg-blue-50/40' : 'border-gray-200' ?> <?= $isEliminated ? 'opacity-50' : '' ?> <?= $canBallot && !in_array($mode, ['stars', 'ranking'], true) ? 'cursor-pointer hover:border-blue-300' : '' ?>">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <?php if ($canBallot): ?>
                <?php if ($mode === 'stars'): ?>
                  <select name="stars[<?= $optId ?>]" class="border border-gray-300 rounded-lg px-1.5 py-1 text-sm"
                          title="<?= $e(__('Stelle (0 = nessun voto)')) ?>">
                    <?php $mine = isset($myVoteValues[$optId]) ? (int) $myVoteValues[$optId] : 0; ?>
                    <option value="0">–</option>
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                      <option value="<?= $s ?>" <?= $mine === $s ? 'selected' : '' ?>><?= $s ?> ★</option>
                    <?php endfor; ?>
                  </select>
                <?php elseif ($mode === 'ranking'): ?>
                  <select name="ranks[<?= $optId ?>]" required class="border border-gray-300 rounded-lg px-1.5 py-1 text-sm"
                          title="<?= $e(__('Posizione in classifica (1 = preferito)')) ?>">
                    <?php $myRank = isset($myVoteValues[$optId]) ? $nOptions - (int) $myVoteValues[$optId] + 1 : 0; ?>
                    <option value=""><?= $e(__('Posizione')) ?></option>
                    <?php for ($r = 1; $r <= $nOptions; $r++): ?>
                      <option value="<?= $r ?>" <?= $myRank === $r ? 'selected' : '' ?>><?= $r ?>°</option>
                    <?php endfor; ?>
                  </select>
                <?php elseif ($mode === 'elimination'): ?>
                  <input type="radio" name="options[]" value="<?= $optId ?>"
                         <?= in_array($optId, $myVotes, true) ? 'checked' : '' ?> class="rounded">
                <?php else: ?>
                  <input type="<?= $maxVotes > 1 ? 'checkbox' : 'radio' ?>" name="options[]" value="<?= $optId ?>"
                         <?= in_array($optId, $myVotes, true) ? 'checked' : '' ?> class="rounded">
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($option['copertina_url'])): ?>
                <img src="<?= $e($option['copertina_url']) ?>" alt="" class="w-8 h-12 object-cover rounded shadow-sm" loading="lazy">
              <?php endif; ?>
              <div>
                <div class="font-medium text-gray-900">
                  <?= $e($option['titolo']) ?><?= $isWinner ? ' 🏆' : '' ?>
                  <?php if ($isEliminated): ?>
                    <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-500">
                      <?= $e(sprintf(__('Eliminato al turno %d'), (int) $eliminated[$optId])) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($option['autori'])): ?><div class="text-sm text-gray-500"><?= $e($option['autori']) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="text-right text-sm text-gray-500 whitespace-nowrap">
              <?php if ($showScores): ?>
                <div class="font-medium text-gray-700"><?= $e($fmtScore((float) $option['score'])) ?> <?= $e(__('punti')) ?></div>
              <?php endif; ?>
              <?= (int) $option['vote_count'] ?> <?= $e(__n('voto', 'voti', (int) $option['vote_count'])) ?>
            </div>
          </div>
          <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width: <?= number_format($pct, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
          </div>
          <?php if (!empty($voters[$optId])): ?>
            <div class="mt-1 text-xs text-gray-400"><?= $e(implode(', ', $voters[$optId])) ?></div>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>

      <?php if ($isOpen && $isMember): ?>
        <div class="flex items-center justify-between pt-2">
          <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
            <?= $myVotes === [] ? $e(__('Vota')) : $e(__('Aggiorna il mio voto')) ?>
          </button>
          <?php if ($mode === 'stars'): ?>
            <span class="text-xs text-gray-400"><?= $e(__('Valuta da 1 a 5 stelle solo i libri che ti interessano.')) ?></span>
          <?php elseif ($mode === 'ranking'): ?>
            <span class="text-xs text-gray-400"><?= $e(__('Assegna una posizione a ogni libro: 1 = preferito.')) ?></span>
          <?php elseif ($mode === 'elimination'): ?>
            <span class="text-xs text-gray-400"><?= $e(sprintf(__('Un voto per turno: siamo al turno %d.'), $round)) ?></span>
          <?php elseif ($maxVotes > 1): ?>
            <span class="text-xs text-gray-400"><?= $e(sprintf(__('Puoi selezionare fino a %d libri.'), $maxVotes)) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($isOpen && !$isMember): ?>
        <p class="text-sm text-gray-400 pt-2"><?= $e(__('Solo i membri attivi del club possono votare.')) ?></p>
      <?php endif; ?>
    </form>

    <?php if ($isOpen && $canClose): ?>
      <?php
        $isRoundClose = $mode === 'elimination' && $activeCount > 2;
        $confirmMsg = $isRoundClose
            ? __('Concludere il turno corrente? Il libro ultimo classificato sarà eliminato.')
            : __('Chiudere la votazione adesso? Il libro più votato avanzerà nel workflow.');
      ?>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/close')) ?>" class="mt-6 border-t pt-4"
            onsubmit="return confirm('<?= $e($confirmMsg) ?>');">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="text-sm text-red-600 hover:underline">
          <?= $isRoundClose ? $e(__('Concludi il turno')) : $e(__('Chiudi la votazione adesso')) ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
