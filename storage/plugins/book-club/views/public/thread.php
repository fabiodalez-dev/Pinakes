<?php
/**
 * Book Club — single discussion thread: chronological posts with one reply
 * level, SpoilerGate rendering, emoji reactions, @mentions in bold and
 * manager moderation (soft delete, lock, pin).
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed> $thread
 * @var list<array<string, mixed>> $posts
 * @var array<int, list<array{emoji: string, n: int, mine: bool}>> $reactions post_id → reactions
 * @var array<int, list<string>> $mentionNames                     post_id → mentioned first/last names
 * @var array<int, string> $sectionTitles                          section_id → title
 * @var array<int, bool> $hiddenPosts                              post_id → spoiler-gated for this viewer
 * @var list<array<string, mixed>> $sections                       reading sections ([] without the reading module)
 * @var list<string> $emojis                                       reaction whitelist
 * @var bool $isMember
 * @var bool $canManage
 * @var int|null $userId
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$threadId = (int) $thread['id'];
$isLocked = (int) $thread['is_locked'] === 1;
$canPost = ($isMember && !$isLocked) || $canManage;
$kindLabels = [
    'general' => __('Generale'),
    'chapter' => __('Capitolo'),
    'character' => __('Personaggio'),
    'free' => __('Libera'),
    'announcement' => __('Annuncio'),
];

/** Escaped body → nl2br + bold @mentions. */
$bodyHtml = static function (array $post) use ($e, $mentionNames): string {
    $html = nl2br($e($post['body']));
    foreach ($mentionNames[(int) $post['id']] ?? [] as $name) {
        $pattern = '/(@' . preg_quote($e($name), '/') . ')(?![\p{L}\p{N}_])/iu';
        $replaced = preg_replace($pattern, '<strong>$1</strong>', $html);
        if (is_string($replaced)) {
            $html = $replaced;
        }
    }
    return $html;
};

/** Full post-body block: removal placeholder, spoiler gate or plain body. */
$postBody = static function (array $post) use ($e, $bodyHtml, $hiddenPosts, $sectionTitles): string {
    if ($post['deleted_at'] !== null) {
        return '<p class="text-sm italic text-gray-400">' . $e(__('[messaggio rimosso]')) . '</p>';
    }
    $gated = !empty($hiddenPosts[(int) $post['id']]);
    if ($post['spoiler'] === 'none' || !$gated) {
        $badge = '';
        if ($post['spoiler'] !== 'none') {
            $badge = '<span class="inline-block px-2 py-0.5 mb-1 text-xs rounded-full bg-amber-100 text-amber-800">'
                . $e(__('Spoiler')) . '</span> ';
        }
        return $badge . '<div class="text-sm text-gray-700 leading-relaxed break-words">' . $bodyHtml($post) . '</div>';
    }
    $sectionId = !empty($post['spoiler_section_id']) ? (int) $post['spoiler_section_id'] : null;
    $sectionTitle = $sectionId !== null ? ($sectionTitles[$sectionId] ?? null) : null;
    $label = $sectionTitle !== null && $sectionTitle !== ''
        ? sprintf(__('Spoiler — fino a: %s'), $sectionTitle)
        : __('Spoiler');
    $out = '';
    if ($post['spoiler'] === 'mild') {
        $plain = (string) $post['body'];
        $teaser = mb_substr($plain, 0, 80);
        $out .= '<p class="text-sm text-gray-500 break-words">' . $e($teaser) . (mb_strlen($plain) > 80 ? '…' : '') . '</p>';
    }
    $out .= '<details class="mt-1 rounded-lg border border-amber-200 bg-amber-50">'
        . '<summary class="cursor-pointer px-3 py-2 text-sm font-medium text-amber-800">'
        . '<i class="fas fa-eye-slash mr-2"></i>' . $e($label)
        . ' <span class="font-normal text-amber-600">(' . $e(__('clicca per rivelare')) . ')</span></summary>'
        . '<div class="px-3 pb-3 text-sm text-gray-700 leading-relaxed break-words">' . $bodyHtml($post) . '</div>'
        . '</details>';
    return $out;
};

$topLevel = [];
$replies = [];
foreach ($posts as $post) {
    if (!empty($post['parent_id'])) {
        $replies[(int) $post['parent_id']][] = $post;
    } else {
        $topLevel[] = $post;
    }
}
?>
<div class="max-w-3xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug . '/discussions')) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Discussioni')) ?> · <?= $e($club['name']) ?>
  </a>

  <!-- Thread header -->
  <div class="bg-white rounded-xl shadow p-6 mt-4 mb-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          <?php if ((int) $thread['is_pinned'] === 1): ?><i class="fas fa-thumbtack text-amber-500 text-sm mr-2" title="<?= $e(__('In evidenza')) ?>"></i><?php endif; ?>
          <?= $e($thread['title']) ?>
        </h1>
        <div class="text-xs text-gray-400 mt-2">
          <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= $e($kindLabels[$thread['kind']] ?? $thread['kind']) ?></span>
          <?php if (!empty($thread['book_title'])): ?>
            · <i class="fas fa-book mr-1"></i><?= $e($thread['book_title']) ?>
          <?php endif; ?>
          <?php if (!empty($thread['section_title'])): ?>
            · <?= $e($thread['section_title']) ?>
          <?php endif; ?>
          <?php if (!empty($thread['creator_nome'])): ?>
            · <?= $e(__('aperta da')) ?> <?= $e(trim($thread['creator_nome'] . ' ' . $thread['creator_cognome'])) ?>
          <?php endif; ?>
          · <?= $e(date('d/m/Y H:i', (int) strtotime((string) $thread['created_at']))) ?>
        </div>
        <?php if ($isLocked): ?>
          <div class="mt-3 inline-flex items-center px-3 py-1.5 text-sm rounded-lg bg-gray-100 text-gray-600">
            <i class="fas fa-lock mr-2"></i><?= $e(__('Questa discussione è bloccata.')) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($canManage): ?>
        <div class="flex items-center gap-2 whitespace-nowrap">
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/lock')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="px-3 py-1.5 text-xs border border-gray-200 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-50">
              <i class="fas <?= $isLocked ? 'fa-lock-open' : 'fa-lock' ?> mr-1"></i><?= $isLocked ? $e(__('Sblocca')) : $e(__('Blocca')) ?>
            </button>
          </form>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/pin')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="px-3 py-1.5 text-xs border border-gray-200 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-50">
              <i class="fas fa-thumbtack mr-1"></i><?= (int) $thread['is_pinned'] === 1 ? $e(__('Togli evidenza')) : $e(__('Fissa in alto')) ?>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Posts -->
  <div class="space-y-4">
    <?php if ($topLevel === []): ?>
      <div class="bg-white rounded-xl shadow p-6">
        <p class="text-sm text-gray-400"><?= $e(__('Nessun messaggio: scrivi il primo!')) ?></p>
      </div>
    <?php endif; ?>

    <?php foreach ($topLevel as $post): ?>
      <?php $postId = (int) $post['id']; ?>
      <div class="bg-white rounded-xl shadow p-5" id="post-<?= $postId ?>">
        <div class="flex items-center justify-between mb-2">
          <div class="text-sm">
            <span class="font-medium text-gray-900"><?= $post['deleted_at'] === null ? $e(trim((string) ($post['nome'] ?? '') . ' ' . (string) ($post['cognome'] ?? ''))) : $e(__('Utente')) ?></span>
            <span class="text-xs text-gray-400 ml-2"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $post['created_at']))) ?></span>
            <?php if ($post['edited_at'] !== null): ?><span class="text-xs text-gray-300 ml-1"><?= $e(__('(modificato)')) ?></span><?php endif; ?>
          </div>
          <?php if ($canManage && $post['deleted_at'] === null): ?>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $postId . '/delete')) ?>"
                  onsubmit="return confirm('<?= $e(__('Rimuovere questo messaggio?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="text-xs text-gray-300 hover:text-red-600" title="<?= $e(__('Rimuovi messaggio')) ?>"><i class="fas fa-trash-alt"></i></button>
            </form>
          <?php endif; ?>
        </div>

        <?= $postBody($post) ?>

        <!-- Reactions -->
        <?php $postReactions = $reactions[$postId] ?? []; ?>
        <?php if ($post['deleted_at'] === null && ($isMember || $canManage)): ?>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $postId . '/react')) ?>" class="flex flex-wrap items-center gap-1.5 mt-3">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <?php foreach ($emojis as $emoji): ?>
              <?php
                $count = 0;
                $mine = false;
                foreach ($postReactions as $r) {
                    if ($r['emoji'] === $emoji) {
                        $count = $r['n'];
                        $mine = $r['mine'];
                        break;
                    }
                }
              ?>
              <button type="submit" name="emoji" value="<?= $e($emoji) ?>"
                      class="px-2 py-0.5 text-xs rounded-full border <?= $mine ? 'border-blue-300 bg-blue-50' : 'border-gray-200 hover:bg-gray-50' ?>"
                      title="<?= $e(__('Reagisci')) ?>">
                <?= $e($emoji) ?><?= $count > 0 ? ' <span class="text-gray-500">' . $count . '</span>' : '' ?>
              </button>
            <?php endforeach; ?>
          </form>
        <?php elseif ($postReactions !== []): ?>
          <div class="flex flex-wrap items-center gap-1.5 mt-3">
            <?php foreach ($postReactions as $r): ?>
              <span class="px-2 py-0.5 text-xs rounded-full border border-gray-200"><?= $e($r['emoji']) ?> <span class="text-gray-500"><?= (int) $r['n'] ?></span></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Replies (one level) -->
        <?php foreach ($replies[$postId] ?? [] as $reply): ?>
          <?php $replyId = (int) $reply['id']; ?>
          <div class="mt-3 ml-6 border-l-2 border-gray-100 pl-4" id="post-<?= $replyId ?>">
            <div class="flex items-center justify-between mb-1">
              <div class="text-sm">
                <span class="font-medium text-gray-900"><?= $reply['deleted_at'] === null ? $e(trim((string) ($reply['nome'] ?? '') . ' ' . (string) ($reply['cognome'] ?? ''))) : $e(__('Utente')) ?></span>
                <span class="text-xs text-gray-400 ml-2"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $reply['created_at']))) ?></span>
              </div>
              <?php if ($canManage && $reply['deleted_at'] === null): ?>
                <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $replyId . '/delete')) ?>"
                      onsubmit="return confirm('<?= $e(__('Rimuovere questo messaggio?')) ?>');">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="text-xs text-gray-300 hover:text-red-600" title="<?= $e(__('Rimuovi messaggio')) ?>"><i class="fas fa-trash-alt"></i></button>
                </form>
              <?php endif; ?>
            </div>

            <?= $postBody($reply) ?>

            <?php $replyReactions = $reactions[$replyId] ?? []; ?>
            <?php if ($reply['deleted_at'] === null && ($isMember || $canManage)): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $replyId . '/react')) ?>" class="flex flex-wrap items-center gap-1.5 mt-2">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <?php foreach ($emojis as $emoji): ?>
                  <?php
                    $count = 0;
                    $mine = false;
                    foreach ($replyReactions as $r) {
                        if ($r['emoji'] === $emoji) {
                            $count = $r['n'];
                            $mine = $r['mine'];
                            break;
                        }
                    }
                  ?>
                  <button type="submit" name="emoji" value="<?= $e($emoji) ?>"
                          class="px-2 py-0.5 text-xs rounded-full border <?= $mine ? 'border-blue-300 bg-blue-50' : 'border-gray-200 hover:bg-gray-50' ?>"
                          title="<?= $e(__('Reagisci')) ?>">
                    <?= $e($emoji) ?><?= $count > 0 ? ' <span class="text-gray-500">' . $count . '</span>' : '' ?>
                  </button>
                <?php endforeach; ?>
              </form>
            <?php elseif ($replyReactions !== []): ?>
              <div class="flex flex-wrap items-center gap-1.5 mt-2">
                <?php foreach ($replyReactions as $r): ?>
                  <span class="px-2 py-0.5 text-xs rounded-full border border-gray-200"><?= $e($r['emoji']) ?> <span class="text-gray-500"><?= (int) $r['n'] ?></span></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <!-- Reply form -->
        <?php if ($canPost): ?>
          <details class="mt-3">
            <summary class="text-xs font-medium text-blue-600 cursor-pointer"><?= $e(__('Rispondi')) ?></summary>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/posts')) ?>" class="mt-2 space-y-2 text-sm">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="parent_id" value="<?= $postId ?>">
              <textarea name="body" rows="2" required maxlength="20000"
                        placeholder="<?= $e(__('Scrivi una risposta…')) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
              <div class="flex flex-wrap items-center gap-2">
                <select name="spoiler" class="border border-gray-300 rounded-lg px-2 py-1 text-xs">
                  <option value="none"><?= $e(__('Nessuno spoiler')) ?></option>
                  <option value="mild"><?= $e(__('Spoiler leggero')) ?></option>
                  <option value="full"><?= $e(__('Spoiler completo')) ?></option>
                </select>
                <?php if ($sections !== []): ?>
                  <select name="spoiler_section_id" class="border border-gray-300 rounded-lg px-2 py-1 text-xs">
                    <option value=""><?= $e(__('Spoiler fino a… (facoltativo)')) ?></option>
                    <?php foreach ($sections as $section): ?>
                      <option value="<?= (int) $section['id'] ?>"><?= $e($section['book_title'] . ' — ' . $section['title']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg"><?= $e(__('Invia risposta')) ?></button>
              </div>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- New post -->
  <?php if ($canPost): ?>
    <div class="bg-white rounded-xl shadow p-6 mt-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-3"><?= $e(__('Scrivi un messaggio')) ?></h2>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/posts')) ?>" class="space-y-3 text-sm">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <textarea name="body" rows="4" required maxlength="20000"
                  placeholder="<?= $e(__('Condividi le tue impressioni… usa @nome per menzionare un membro.')) ?>"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
        <div class="flex flex-wrap items-center gap-3">
          <label class="text-xs text-gray-400"><?= $e(__('Livello spoiler')) ?></label>
          <select name="spoiler" class="border border-gray-300 rounded-lg px-2 py-1.5">
            <option value="none"><?= $e(__('Nessuno spoiler')) ?></option>
            <option value="mild"><?= $e(__('Spoiler leggero')) ?></option>
            <option value="full"><?= $e(__('Spoiler completo')) ?></option>
          </select>
          <?php if ($sections !== []): ?>
            <select name="spoiler_section_id" class="border border-gray-300 rounded-lg px-2 py-1.5">
              <option value=""><?= $e(__('Spoiler fino a… (facoltativo)')) ?></option>
              <?php foreach ($sections as $section): ?>
                <option value="<?= (int) $section['id'] ?>"><?= $e($section['book_title'] . ' — ' . $section['title']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg"><?= $e(__('Pubblica messaggio')) ?></button>
        </div>
      </form>
    </div>
  <?php elseif ($isMember && $isLocked): ?>
    <p class="text-sm text-gray-400 mt-6"><?= $e(__('La discussione è bloccata: non è possibile aggiungere messaggi.')) ?></p>
  <?php elseif (!$isMember): ?>
    <p class="text-sm text-gray-400 mt-6"><?= $e(__('Solo i membri attivi del club possono scrivere messaggi.')) ?></p>
  <?php endif; ?>
</div>
