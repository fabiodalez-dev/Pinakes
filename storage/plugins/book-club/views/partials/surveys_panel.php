<?php
/**
 * Book Club — surveys module panel on the public club page: open surveys
 * with their answer count and a link to answer / see them. Rendered for
 * members/managers only (managers also get the "all surveys" shortcut even
 * when nothing is open).
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $open       open surveys with answer_count
 * @var list<int> $answeredIds                 survey ids the viewer answered
 * @var bool $isMember
 * @var bool $canManage
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$base = url('/book-club/' . $slug . '/surveys');
?>
<section class="bc-card">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-clipboard-list"></i>
      <h2><?= $e(__('Questionari')) ?></h2>
    </div>
    <a href="<?= $e($base) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
      <?= $e(__('Tutti i questionari')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <?php if ($open === []): ?>
    <p class="bc-muted mb-0"><?= $e(__('Nessun questionario aperto al momento.')) ?></p>
  <?php endif; ?>

  <?php foreach ($open as $survey): ?>
    <?php
      $answered = in_array((int) $survey['id'], $answeredIds, true);
      $scheduled = \App\Plugins\BookClub\SurveyRepo::notYetOpen($survey);
    ?>
    <div class="border rounded-3 px-3 py-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="fw-semibold"><?= $e($survey['title']) ?></a>
        <div class="bc-muted small mt-1 d-flex flex-wrap gap-3">
          <span><i class="fas fa-reply me-1"></i><?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?></span>
          <?php if ((int) $survey['anonymous'] === 1): ?>
            <span><i class="fas fa-user-secret me-1"></i><?= $e(__('Anonimo')) ?></span>
          <?php endif; ?>
          <?php if ($scheduled): ?>
            <span><i class="far fa-clock me-1"></i><?= $e(__('Apre il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['opens_at']))) ?></span>
          <?php endif; ?>
          <?php if (!empty($survey['closes_at'])): ?>
            <span><i class="far fa-clock me-1"></i><?= $e(__('Chiude il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['closes_at']))) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <?php if ($answered): ?>
          <span class="bc-badge bc-badge-open"><i class="fas fa-check"></i><?= $e(__('Hai già risposto')) ?></span>
        <?php elseif ($scheduled): ?>
          <span class="bc-badge bc-badge-closed"><i class="far fa-clock"></i><?= $e(__('Programmato')) ?></span>
        <?php elseif ($isMember): ?>
          <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="bc-btn bc-btn-sm"><?= $e(__('Rispondi al questionario')) ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
