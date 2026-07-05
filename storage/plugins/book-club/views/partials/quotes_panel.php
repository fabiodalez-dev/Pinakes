<?php
/**
 * Book Club — club-page panel of the quotes module: the three most recent
 * club-visible quotes (italic, with book title + member name) and the link
 * to the full quotes & annotations page. Rendered for members/managers only.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $quotes
 * @var bool $isMember
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-quote-left mr-2 text-gray-400"></i><?= $e(__('Citazioni recenti')) ?></h2>
    <a class="text-sm text-blue-600 hover:underline whitespace-nowrap" href="<?= $e(url('/book-club/' . $slug . '/quotes')) ?>">
      <?= $e(__('Tutte le citazioni')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <?php if ($quotes === []): ?>
    <p class="text-sm text-gray-400"><?= $e(__('Ancora nessuna citazione: aggiungi la prima!')) ?></p>
  <?php endif; ?>

  <?php foreach ($quotes as $quote): ?>
    <?php $memberName = trim((string) $quote['member_nome'] . ' ' . (string) $quote['member_cognome']); ?>
    <div class="border-t first:border-t-0 py-3">
      <blockquote class="text-sm text-gray-700 italic border-l-2 pl-3" style="border-color: <?= $e($club['color']) ?>">
        “<?= $e(mb_strlen((string) $quote['quote']) > 220 ? mb_substr((string) $quote['quote'], 0, 220) . '…' : (string) $quote['quote']) ?>”
      </blockquote>
      <div class="text-xs text-gray-400 mt-1.5">
        <span class="font-medium text-gray-500"><?= $e($quote['titolo']) ?></span>
        <?php if ($quote['page'] !== null): ?>
          · <?= $e(sprintf(__('pag. %d'), (int) $quote['page'])) ?>
        <?php endif; ?>
        · <i class="far fa-user mr-0.5"></i><?= $e($memberName) ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
