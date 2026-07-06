<?php
/**
 * Book Club — public leaderboard page (members/managers): full XP ranking
 * with level and badges per member, the viewer's own position, the XP
 * formula legend and the badge catalogue.
 *
 * Data comes from bookclub_xp_snapshot, recomputed from the club's activity
 * tables at most once per hour (lazily on view + maintenance tick).
 *
 * @var array<string, mixed> $club
 * @var list<array{rank: int, user_id: int, name: string, xp: int, level: int, badges: list<array<string, mixed>>, is_me: bool}> $ranking
 * @var array{rank: int, name: string, xp: int, level: int, badges: list<array<string, mixed>>}|null $me
 * @var list<array<string, mixed>> $allBadges
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$xpRules = [
    [__('Libro concluso'), \App\Plugins\BookClub\GamificationRepo::XP_FINISHED_BOOK, 'fa-flag-checkered'],
    [__('Proposta di lettura accettata'), \App\Plugins\BookClub\GamificationRepo::XP_PROPOSAL, 'fa-lightbulb'],
    [__('Presenza confermata a un incontro'), \App\Plugins\BookClub\GamificationRepo::XP_RSVP_YES, 'fa-calendar-check'],
    [__('Voto espresso in una votazione'), \App\Plugins\BookClub\GamificationRepo::XP_VOTE, 'fa-vote-yea'],
    [__('Post scritto nelle discussioni'), \App\Plugins\BookClub\GamificationRepo::XP_POST, 'fa-comments'],
];
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
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="bc-muted text-decoration-none">
    <i class="fas fa-arrow-left me-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-3 mb-4">
    <div class="bc-section-header mb-0">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <h1><?= $e(__('Classifica')) ?> — <?= $e($club['name']) ?></h1>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($me !== null): ?>
    <!-- My position -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-user"></i>
        <h2><?= $e(__('La tua posizione')) ?></h2>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0 text-white fw-bold"
             style="width:56px;height:56px;border-radius:50%;font-size:1.25rem;background: <?= $e($club['color']) ?>">
          <?= (int) $me['level'] ?>
        </div>
        <div>
          <div class="fw-bold" style="font-size:1.1rem">#<?= (int) $me['rank'] ?> — <?= $e($me['name']) ?></div>
          <div class="bc-muted"><?= $e(sprintf(__('Livello %d'), (int) $me['level'])) ?> · <?= (int) $me['xp'] ?> XP</div>
        </div>
        <?php if ($me['badges'] !== []): ?>
          <div class="d-flex flex-wrap gap-2 ms-auto">
            <?php foreach ($me['badges'] as $badge): ?>
              <span class="bc-badge bc-badge-closed" title="<?= $e($badge['description']) ?>">
                <i class="fas <?= $e($badge['icon']) ?>" style="color:var(--warning-color)"></i><?= $e($badge['name']) ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- Ranking -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-trophy"></i>
      <h2><?= $e(__('Classifica del club')) ?></h2>
    </div>
    <?php if ($ranking === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('La classifica è ancora vuota: i punti vengono calcolati dalle attività del club.')) ?></p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <tbody>
            <?php foreach ($ranking as $row): ?>
              <tr class="<?= $row['is_me'] ? 'table-active' : '' ?>">
                <td class="text-center" style="width:3rem">
                  <?php if ($row['rank'] <= 3): ?>
                    <i class="fas fa-medal" style="color:var(--warning-color)"></i>
                  <?php else: ?>
                    <span class="bc-muted fw-semibold"><?= (int) $row['rank'] ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="fw-medium">
                    <?= $e($row['name']) ?>
                    <?php if ($row['is_me']): ?>
                      <span class="bc-badge bc-badge-open ms-1"><?= $e(__('Tu')) ?></span>
                    <?php endif; ?>
                  </span>
                  <?php if ($row['badges'] !== []): ?>
                    <span class="ms-2 text-nowrap">
                      <?php foreach ($row['badges'] as $badge): ?>
                        <i class="fas <?= $e($badge['icon']) ?> me-1 small" style="color:var(--warning-color)" title="<?= $e($badge['name']) ?> — <?= $e($badge['description']) ?>"></i>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-center text-nowrap bc-muted" style="width:6rem"><?= $e(sprintf(__('Livello %d'), (int) $row['level'])) ?></td>
                <td class="text-end text-nowrap fw-semibold" style="width:6rem"><?= (int) $row['xp'] ?> XP</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- XP formula -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-calculator"></i>
      <h2><?= $e(__('Come si guadagnano i punti')) ?></h2>
    </div>
    <div class="row g-2">
      <?php foreach ($xpRules as [$label, $xp, $icon]): ?>
        <div class="col-12 col-md-6">
          <div class="d-flex align-items-center gap-3 small">
            <i class="fas <?= $e($icon) ?> text-center" style="width:1.25rem;color:var(--text-muted)"></i>
            <span class="flex-grow-1"><?= $e($label) ?></span>
            <span class="fw-semibold text-nowrap">+<?= (int) $xp ?> XP</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="bc-muted mt-3 mb-0 small">
      <?= $e(__('Il livello si calcola come 1 + parte intera della radice quadrata di XP/100: livello 2 a 100 XP, livello 3 a 400 XP, livello 4 a 900 XP.')) ?>
      <?= $e(__('I punti vengono ricalcolati automaticamente al massimo una volta all\'ora.')) ?>
    </p>
  </section>

  <!-- Badge catalogue -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-award"></i>
      <h2><?= $e(__('Badge disponibili')) ?></h2>
    </div>
    <?php if ($allBadges === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun badge configurato.')) ?></p>
    <?php endif; ?>
    <div class="row g-3">
      <?php foreach ($allBadges as $badge): ?>
        <div class="col-12 col-md-6">
          <div class="d-flex align-items-start gap-3 border rounded-3 p-3 h-100">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:2.25rem;height:2.25rem;border-radius:50%;background:var(--accent-color)">
              <i class="fas <?= $e($badge['icon']) ?>" style="color:var(--warning-color)"></i>
            </div>
            <div>
              <div class="fw-semibold small"><?= $e($badge['name']) ?></div>
              <div class="bc-muted small"><?= $e($badge['description']) ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
