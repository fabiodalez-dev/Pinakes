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
<style>
  .bc-card{background:var(--white);border-radius:20px;box-shadow:var(--card-shadow);padding:clamp(1.5rem,3vw,2rem);margin-bottom:1.5rem}
  .bc-section-header{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem}
  .bc-section-header i{color:var(--primary-color);font-size:1.15rem}
  .bc-section-header h2,.bc-section-header h1{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  .bc-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.55rem 1.4rem;border-radius:999px;border:1.5px solid var(--button-color);background:var(--button-color);color:var(--button-text-color);font-weight:600;font-size:.9rem;cursor:pointer;text-decoration:none;transition:all .2s ease;white-space:nowrap}
  .bc-btn:hover{background:var(--button-hover);border-color:var(--button-hover);color:var(--button-text-color);transform:translateY(-1px)}
  .bc-btn-outline{background:transparent;color:var(--text-color);border:1px solid var(--border-color)}
  .bc-btn-outline:hover{border-color:var(--primary-color);color:var(--primary-color);background:transparent;transform:translateY(-1px)}
  .bc-btn-danger{background:transparent;border:1px solid var(--danger-color);color:var(--danger-color)}
  .bc-btn-danger:hover{background:var(--danger-color);border-color:var(--danger-color);color:#fff}
  .bc-btn-sm{padding:.3rem .9rem;font-size:.8rem}
  .bc-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600}
  .bc-badge-open{background:rgba(16,185,129,.12);color:var(--success-color)}
  .bc-badge-closed{background:var(--accent-color);color:var(--text-light)}
  .bc-badge-warn{background:rgba(245,158,11,.14);color:#92400e}
  .bc-muted{color:var(--text-light);font-size:.85rem}
  .bc-hero{background:var(--primary-color);color:#fff;border-radius:22px;padding:clamp(1.75rem,4vw,2.5rem);margin-bottom:2rem}
  .bc-hero h1{font-size:clamp(1.8rem,4vw,2.5rem);font-weight:800;letter-spacing:-.03em;margin:0 0 .5rem;color:#fff}
  .bc-hero p{opacity:.9;margin:0}
  .bc-progress{height:8px;background:var(--accent-color);border-radius:999px;overflow:hidden}
  .bc-progress>span{display:block;height:100%;border-radius:999px;background:var(--primary-color)}
  .bc-list-item{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;padding:.9rem 0;border-top:1px solid var(--border-color)}
  .bc-list-item:first-child{border-top:none}
  .bc-cover{width:44px;height:64px;object-fit:cover;border-radius:8px;box-shadow:var(--card-shadow)}
  .bc-chip{display:inline-block;width:.8rem;height:.8rem;border-radius:50%;flex:none}
</style>
<div class="container py-4">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="bc-muted text-decoration-none d-inline-flex align-items-center gap-2 mb-3">
    <i class="fas fa-arrow-left"></i><?= $e($club['name']) ?>
  </a>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="bc-card">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div>
        <h1 class="h3 fw-bold mb-1"><?= $e($poll['title']) ?></h1>
        <?php if (!empty($poll['description'])): ?>
          <p class="bc-muted mb-2" style="white-space: pre-line"><?= $e($poll['description']) ?></p>
        <?php endif; ?>
        <div class="bc-muted small">
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
          <div class="bc-muted small mt-1">
            <i class="fas fa-balance-scale me-1"></i><?= $e(sprintf(__('Pesi: fondatore ×%s · moderatore ×%s · membro ×1,0.'), $fmtWeight($weightOwner), $fmtWeight($weightModerator))) ?>
          </div>
        <?php endif; ?>
      </div>
      <span class="bc-badge <?= $isOpen ? 'bc-badge-open' : 'bc-badge-closed' ?>">
        <?= $isOpen ? $e(__('Aperta')) : $e(__('Chiusa')) ?>
      </span>
    </div>

    <?php if (!$isOpen && $poll['winner_club_book_id'] !== null): ?>
      <?php foreach ($options as $option): ?>
        <?php if ((int) $option['club_book_id'] === (int) $poll['winner_club_book_id']): ?>
          <div class="bc-card mt-4 mb-0" style="border-left: 4px solid var(--success-color)">
            <i class="fas fa-trophy me-2" style="color: var(--success-color)"></i><?= $e(sprintf(__('Il club ha scelto: %s'), (string) $option['titolo'])) ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$isOpen && $quorumFailed): ?>
      <div class="alert alert-warning mt-4 mb-0">
        <i class="fas fa-exclamation-triangle me-2"></i><strong><?= $e(__('Quorum non raggiunto')) ?></strong>
        — <?= $e(__('la votazione si è chiusa senza vincitore e le proposte tornano tra i libri proposti.')) ?>
      </div>
    <?php endif; ?>

    <?php if (!$isOpen && $adminTiedIds !== []): ?>
      <div class="bc-card mt-4 mb-0" style="border-left: 4px solid var(--warning-color)">
        <i class="fas fa-gavel me-2" style="color: var(--warning-color)"></i><?= $e(__('Parità in testa: un moderatore deve proclamare il vincitore.')) ?>
        <?php if ($canClose): ?>
          <div class="mt-3 d-flex flex-column gap-2">
            <?php foreach ($options as $option): ?>
              <?php if (!in_array((int) $option['id'], $adminTiedIds, true)) { continue; } ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/pick-winner/' . (int) $option['id'])) ?>"
                    class="d-flex align-items-center justify-content-between gap-3"
                    onsubmit="return confirm('<?= $e(__('Proclamare questo libro vincitore? Avanzerà nel workflow.')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <span class="fw-semibold"><?= $e($option['titolo']) ?></span>
                <button type="submit" class="bc-btn bc-btn-sm">
                  <?= $e(__('Proclama vincitore')) ?>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/vote')) ?>" class="mt-4">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <?php foreach ($options as $option): ?>
        <?php
          $optId = (int) $option['id'];
          $isEliminated = isset($eliminated[$optId]);
          $pct = $totalScore > 0 ? (float) $option['score'] / $totalScore * 100 : 0;
          $isWinner = !$isOpen && $poll['winner_club_book_id'] !== null && (int) $option['club_book_id'] === (int) $poll['winner_club_book_id'];
          $canBallot = $isOpen && $isMember && !$isEliminated;
        ?>
        <label class="d-block border rounded-3 p-3 mb-2 <?= $isEliminated ? 'opacity-50' : '' ?>"
               <?= $isWinner ? 'style="border-color: var(--success-color)"' : '' ?>>
          <div class="d-flex align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
              <?php if ($canBallot): ?>
                <?php if ($mode === 'stars'): ?>
                  <select name="stars[<?= $optId ?>]" class="form-select form-select-sm w-auto"
                          title="<?= $e(__('Stelle (0 = nessun voto)')) ?>">
                    <?php $mine = isset($myVoteValues[$optId]) ? (int) $myVoteValues[$optId] : 0; ?>
                    <option value="0">–</option>
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                      <option value="<?= $s ?>" <?= $mine === $s ? 'selected' : '' ?>><?= $s ?> ★</option>
                    <?php endfor; ?>
                  </select>
                <?php elseif ($mode === 'ranking'): ?>
                  <select name="ranks[<?= $optId ?>]" required class="form-select form-select-sm w-auto"
                          title="<?= $e(__('Posizione in classifica (1 = preferito)')) ?>">
                    <?php $myRank = isset($myVoteValues[$optId]) ? $nOptions - (int) $myVoteValues[$optId] + 1 : 0; ?>
                    <option value=""><?= $e(__('Posizione')) ?></option>
                    <?php for ($r = 1; $r <= $nOptions; $r++): ?>
                      <option value="<?= $r ?>" <?= $myRank === $r ? 'selected' : '' ?>><?= $r ?>°</option>
                    <?php endfor; ?>
                  </select>
                <?php elseif ($mode === 'elimination'): ?>
                  <input type="radio" name="options[]" value="<?= $optId ?>"
                         <?= in_array($optId, $myVotes, true) ? 'checked' : '' ?> class="form-check-input mt-0 flex-shrink-0">
                <?php else: ?>
                  <input type="<?= $maxVotes > 1 ? 'checkbox' : 'radio' ?>" name="options[]" value="<?= $optId ?>"
                         <?= in_array($optId, $myVotes, true) ? 'checked' : '' ?> class="form-check-input mt-0 flex-shrink-0">
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($option['copertina_url'])): ?>
                <img src="<?= $e($option['copertina_url']) ?>" alt="" class="bc-cover flex-shrink-0" loading="lazy">
              <?php endif; ?>
              <div>
                <div class="fw-semibold">
                  <?= $e($option['titolo']) ?><?= $isWinner ? ' 🏆' : '' ?>
                  <?php if ($isEliminated): ?>
                    <span class="bc-badge bc-badge-closed ms-2">
                      <?= $e(sprintf(__('Eliminato al turno %d'), (int) $eliminated[$optId])) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($option['autori'])): ?><div class="bc-muted"><?= $e($option['autori']) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="text-end bc-muted text-nowrap">
              <?php if ($showScores): ?>
                <div class="fw-semibold" style="color: var(--text-color)"><?= $e($fmtScore((float) $option['score'])) ?> <?= $e(__('punti')) ?></div>
              <?php endif; ?>
              <?= (int) $option['vote_count'] ?> <?= $e(__n('voto', 'voti', (int) $option['vote_count'])) ?>
            </div>
          </div>
          <div class="bc-progress mt-2">
            <span style="width: <?= number_format($pct, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
          </div>
          <?php if (!empty($voters[$optId])): ?>
            <div class="bc-muted small mt-1"><?= $e(implode(', ', $voters[$optId])) ?></div>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>

      <?php if ($isOpen && $isMember): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2">
          <button type="submit" class="bc-btn">
            <?= $myVotes === [] ? $e(__('Vota')) : $e(__('Aggiorna il mio voto')) ?>
          </button>
          <?php if ($mode === 'stars'): ?>
            <span class="bc-muted small"><?= $e(__('Valuta da 1 a 5 stelle solo i libri che ti interessano.')) ?></span>
          <?php elseif ($mode === 'ranking'): ?>
            <span class="bc-muted small"><?= $e(__('Assegna una posizione a ogni libro: 1 = preferito.')) ?></span>
          <?php elseif ($mode === 'elimination'): ?>
            <span class="bc-muted small"><?= $e(sprintf(__('Un voto per turno: siamo al turno %d.'), $round)) ?></span>
          <?php elseif ($maxVotes > 1): ?>
            <span class="bc-muted small"><?= $e(sprintf(__('Puoi selezionare fino a %d libri.'), $maxVotes)) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($isOpen && !$isMember): ?>
        <p class="bc-muted pt-2 mb-0"><?= $e(__('Solo i membri attivi del club possono votare.')) ?></p>
      <?php endif; ?>
    </form>

    <?php if ($isOpen && $canClose): ?>
      <?php
        $isRoundClose = $mode === 'elimination' && $activeCount > 2;
        $confirmMsg = $isRoundClose
            ? __('Concludere il turno corrente? Il libro ultimo classificato sarà eliminato.')
            : __('Chiudere la votazione adesso? Il libro più votato avanzerà nel workflow.');
      ?>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'] . '/close')) ?>" class="mt-4 pt-3 border-top"
            onsubmit="return confirm('<?= $e($confirmMsg) ?>');">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
          <?= $isRoundClose ? $e(__('Concludi il turno')) : $e(__('Chiudi la votazione adesso')) ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
