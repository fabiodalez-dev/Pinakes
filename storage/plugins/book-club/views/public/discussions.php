<?php
/**
 * Book Club — discussions: thread list (pinned first, then by activity)
 * plus the new-thread form for members.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $threads
 * @var list<array<string, mixed>> $books     club books (non-pending)
 * @var list<array<string, mixed>> $sections  reading sections ([] when the reading module is absent)
 * @var bool $isMember
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$kindLabels = [
    'general' => __('Generale'),
    'chapter' => __('Capitolo'),
    'character' => __('Personaggio'),
    'free' => __('Libera'),
    'announcement' => __('Annuncio'),
];
$kindBadges = [
    'general' => 'bg-gray-100 text-gray-600',
    'chapter' => 'bg-sky-100 text-sky-700',
    'character' => 'bg-purple-100 text-purple-700',
    'free' => 'bg-gray-100 text-gray-600',
    'announcement' => 'bg-amber-100 text-amber-800',
];
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e($club['name']) ?>
  </a>

  <div class="flex items-center justify-between mt-4 mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?= $e(__('Discussioni')) ?></h1>
    <span class="text-sm text-gray-400"><?= count($threads) ?> <?= $e(__n('discussione', 'discussioni', count($threads))) ?></span>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($isMember || $canManage): ?>
    <!-- New thread -->
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-3"><?= $e(__('Apri una nuova discussione')) ?></h2>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/new')) ?>" class="space-y-3 text-sm">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="text" name="title" required maxlength="190"
               placeholder="<?= $e(__('Titolo della discussione')) ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <select name="kind" class="border border-gray-300 rounded-lg px-2 py-1.5">
            <?php foreach ($kindLabels as $value => $label): ?>
              <?php if ($value === 'announcement' && !$canManage) { continue; } ?>
              <option value="<?= $e($value) ?>" <?= $value === 'free' ? 'selected' : '' ?>><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="club_book_id" class="border border-gray-300 rounded-lg px-2 py-1.5">
            <option value=""><?= $e(__('Nessun libro collegato')) ?></option>
            <?php foreach ($books as $book): ?>
              <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($sections !== []): ?>
            <select name="section_id" class="border border-gray-300 rounded-lg px-2 py-1.5">
              <option value=""><?= $e(__('Nessuna sezione collegata')) ?></option>
              <?php foreach ($sections as $section): ?>
                <option value="<?= (int) $section['id'] ?>"><?= $e($section['book_title'] . ' — ' . $section['title']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg"><?= $e(__('Apri discussione')) ?></button>
      </form>
    </section>
  <?php endif; ?>

  <!-- Thread list -->
  <section class="bg-white rounded-xl shadow p-6">
    <?php if ($threads === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Ancora nessuna discussione: apri la prima!')) ?></p>
    <?php endif; ?>
    <?php foreach ($threads as $thread): ?>
      <div class="flex items-start justify-between border-t first:border-t-0 py-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <?php if ((int) $thread['is_pinned'] === 1): ?>
              <i class="fas fa-thumbtack text-amber-500 text-xs" title="<?= $e(__('In evidenza')) ?>"></i>
            <?php endif; ?>
            <?php if ((int) $thread['is_locked'] === 1): ?>
              <i class="fas fa-lock text-gray-400 text-xs" title="<?= $e(__('Bloccata')) ?>"></i>
            <?php endif; ?>
            <a class="font-medium text-blue-600 hover:underline truncate"
               href="<?= $e(url('/book-club/' . $slug . '/discussions/' . (int) $thread['id'])) ?>"><?= $e($thread['title']) ?></a>
            <span class="px-2 py-0.5 text-xs rounded-full <?= $e($kindBadges[$thread['kind']] ?? 'bg-gray-100 text-gray-600') ?>">
              <?= $e($kindLabels[$thread['kind']] ?? $thread['kind']) ?>
            </span>
          </div>
          <div class="text-xs text-gray-400 mt-1">
            <?php if (!empty($thread['book_title'])): ?>
              <i class="fas fa-book mr-1"></i><?= $e($thread['book_title']) ?>
              <?php if (!empty($thread['section_title'])): ?> · <?= $e($thread['section_title']) ?><?php endif; ?>
              ·
            <?php endif; ?>
            <?php if (!empty($thread['creator_nome'])): ?>
              <?= $e(__('aperta da')) ?> <?= $e(trim($thread['creator_nome'] . ' ' . $thread['creator_cognome'])) ?> ·
            <?php endif; ?>
            <?= $e(__('ultima attività')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $thread['last_activity']))) ?>
          </div>
        </div>
        <div class="text-right text-sm text-gray-500 whitespace-nowrap ml-4">
          <?= (int) $thread['post_count'] ?> <?= $e(__n('messaggio', 'messaggi', (int) $thread['post_count'])) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
</div>
