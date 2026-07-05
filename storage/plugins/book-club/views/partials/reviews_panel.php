<?php
/**
 * Book Club — review bridge panel (plan §7.9): approved core reviews of the
 * club's finished books written by active members (with spoiler badge and
 * strengths/weaknesses from bookclub_review_meta), plus the submission form
 * for finished books the member has not reviewed yet. New reviews land in
 * the core moderation queue (recensioni stato 'pendente').
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $reviews          from LibraryRepo::clubReviews
 * @var list<array<string, mixed>> $reviewableBooks  finished books without a review by the current user
 * @var bool $isMember
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-star mr-2 text-gray-400"></i><?= $e(__('Recensioni del club')) ?></h2>

  <?php if ($reviews === []): ?>
    <p class="text-sm text-gray-400"><?= $e(__('Nessuna recensione approvata per i libri conclusi dal club.')) ?></p>
  <?php endif; ?>

  <?php foreach ($reviews as $review): ?>
    <?php
      $stars = max(1, min(5, (int) $review['stelle']));
      $hasSpoiler = !empty($review['has_spoiler']);
      $reviewer = trim((string) $review['nome'] . ' ' . (string) $review['cognome']);
    ?>
    <div class="border-t py-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <span class="text-yellow-500 text-sm" aria-label="<?= $e(sprintf(__('%d stelle su 5'), $stars)) ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?><i class="<?= $i <= $stars ? 'fas' : 'far' ?> fa-star"></i><?php endfor; ?>
          </span>
          <?php if (!empty($review['titolo'])): ?>
            <span class="font-medium text-gray-900"><?= $e($review['titolo']) ?></span>
          <?php endif; ?>
          <?php if ($hasSpoiler): ?>
            <span class="px-2 py-0.5 text-xs rounded-full bg-red-50 text-red-700"><i class="fas fa-eye-slash mr-1"></i><?= $e(__('Spoiler')) ?></span>
          <?php endif; ?>
        </div>
        <span class="text-xs text-gray-400">
          <?= $e($reviewer) ?>
          · <?= $e($review['libro_titolo']) ?>
          <?php if (!empty($review['data_recensione'])): ?>
            · <?= $e(date('d/m/Y', (int) strtotime((string) $review['data_recensione']))) ?>
          <?php endif; ?>
        </span>
      </div>

      <?php if (!empty($review['descrizione'])): ?>
        <?php if ($hasSpoiler): ?>
          <details class="mt-1 text-sm text-gray-600">
            <summary class="cursor-pointer text-xs text-red-600"><?= $e(__('Mostra la recensione (contiene spoiler)')) ?></summary>
            <p class="whitespace-pre-line mt-1"><?= $e($review['descrizione']) ?></p>
          </details>
        <?php else: ?>
          <p class="text-sm text-gray-600 mt-1 whitespace-pre-line"><?= $e($review['descrizione']) ?></p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!empty($review['strengths']) || !empty($review['weaknesses'])): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2 text-xs">
          <?php if (!empty($review['strengths'])): ?>
            <div class="bg-green-50 rounded-lg px-3 py-2 text-green-800">
              <span class="font-semibold"><i class="fas fa-plus-circle mr-1"></i><?= $e(__('Punti di forza')) ?>:</span>
              <span class="whitespace-pre-line"><?= $e($review['strengths']) ?></span>
            </div>
          <?php endif; ?>
          <?php if (!empty($review['weaknesses'])): ?>
            <div class="bg-red-50 rounded-lg px-3 py-2 text-red-800">
              <span class="font-semibold"><i class="fas fa-minus-circle mr-1"></i><?= $e(__('Punti deboli')) ?>:</span>
              <span class="whitespace-pre-line"><?= $e($review['weaknesses']) ?></span>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($isMember && $reviewableBooks !== []): ?>
    <details class="mt-4 border-t pt-4">
      <summary class="text-sm font-medium text-blue-600 cursor-pointer"><?= $e(__('Scrivi una recensione')) ?></summary>
      <p class="text-xs text-gray-400 mt-2"><?= $e(__('La recensione sarà pubblicata dopo l\'approvazione di un amministratore della biblioteca.')) ?></p>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/reviews')) ?>" class="mt-3 space-y-3 text-sm">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <select name="libro_id" required class="border border-gray-300 rounded-lg px-2 py-1.5">
            <option value=""><?= $e(__('Scegli il libro concluso…')) ?></option>
            <?php foreach ($reviewableBooks as $book): ?>
              <option value="<?= (int) $book['libro_id'] ?>"><?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <select name="stelle" required class="border border-gray-300 rounded-lg px-2 py-1.5">
            <option value=""><?= $e(__('Valutazione…')) ?></option>
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <option value="<?= $i ?>"><?= $i ?> <?= $e(__n('stella', 'stelle', $i)) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <input type="text" name="titolo" maxlength="255" placeholder="<?= $e(__('Titolo della recensione (facoltativo)')) ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2">
        <textarea name="descrizione" rows="4" maxlength="2000" placeholder="<?= $e(__('La tua recensione…')) ?>"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <textarea name="strengths" rows="2" maxlength="2000" placeholder="<?= $e(__('Punti di forza (facoltativo)')) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
          <textarea name="weaknesses" rows="2" maxlength="2000" placeholder="<?= $e(__('Punti deboli (facoltativo)')) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <label class="flex items-center text-sm text-gray-700">
          <input type="checkbox" name="has_spoiler" value="1" class="mr-2 rounded">
          <?= $e(__('La recensione contiene spoiler')) ?>
        </label>
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg"><?= $e(__('Invia recensione')) ?></button>
      </form>
    </details>
  <?php elseif ($isMember): ?>
    <p class="text-xs text-gray-400 mt-4 border-t pt-4"><?= $e(__('Hai già recensito tutti i libri conclusi dal club.')) ?></p>
  <?php endif; ?>
</section>
