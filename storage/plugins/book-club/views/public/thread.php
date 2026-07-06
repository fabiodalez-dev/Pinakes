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
        return '<p class="bc-muted fst-italic mb-0">' . $e(__('[messaggio rimosso]')) . '</p>';
    }
    $gated = !empty($hiddenPosts[(int) $post['id']]);
    if ($post['spoiler'] === 'none' || !$gated) {
        $badge = '';
        if ($post['spoiler'] !== 'none') {
            $badge = '<span class="bc-badge bc-badge-warn mb-1">'
                . $e(__('Spoiler')) . '</span> ';
        }
        return $badge . '<div class="small text-break">' . $bodyHtml($post) . '</div>';
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
        $out .= '<p class="small bc-muted text-break mb-1">' . $e($teaser) . (mb_strlen($plain) > 80 ? '…' : '') . '</p>';
    }
    $out .= '<details class="mt-1">'
        . '<summary class="bc-badge bc-badge-warn" style="cursor:pointer">'
        . '<i class="fas fa-eye-slash"></i>' . $e($label)
        . ' <span class="fw-normal">(' . $e(__('clicca per rivelare')) . ')</span></summary>'
        . '<div class="small text-break mt-2">' . $bodyHtml($post) . '</div>'
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
  <a href="<?= $e(url('/book-club/' . $slug . '/discussions')) ?>" class="bc-muted text-decoration-none">
    <i class="fas fa-arrow-left me-1"></i><?= $e(__('Discussioni')) ?> · <?= $e($club['name']) ?>
  </a>

  <!-- Thread header -->
  <div class="bc-card mt-3">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div>
        <h1 class="h3 fw-bold mb-0">
          <?php if ((int) $thread['is_pinned'] === 1): ?><i class="fas fa-thumbtack small me-2" style="color: var(--warning-color)" title="<?= $e(__('In evidenza')) ?>"></i><?php endif; ?>
          <?= $e($thread['title']) ?>
        </h1>
        <div class="bc-muted mt-2 d-flex align-items-center gap-2 flex-wrap">
          <span class="bc-badge bc-badge-closed"><?= $e($kindLabels[$thread['kind']] ?? $thread['kind']) ?></span>
          <?php if (!empty($thread['book_title'])): ?>
            <span>· <i class="fas fa-book me-1"></i><?= $e($thread['book_title']) ?></span>
          <?php endif; ?>
          <?php if (!empty($thread['section_title'])): ?>
            <span>· <?= $e($thread['section_title']) ?></span>
          <?php endif; ?>
          <?php if (!empty($thread['creator_nome'])): ?>
            <span>· <?= $e(__('aperta da')) ?> <?= $e(trim($thread['creator_nome'] . ' ' . $thread['creator_cognome'])) ?></span>
          <?php endif; ?>
          <span>· <?= $e(date('d/m/Y H:i', (int) strtotime((string) $thread['created_at']))) ?></span>
        </div>
        <?php if ($isLocked): ?>
          <div class="mt-3">
            <span class="bc-badge bc-badge-closed"><i class="fas fa-lock"></i><?= $e(__('Questa discussione è bloccata.')) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($canManage): ?>
        <div class="d-flex align-items-center gap-2 text-nowrap">
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/lock')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
              <i class="fas <?= $isLocked ? 'fa-lock-open' : 'fa-lock' ?>"></i><?= $isLocked ? $e(__('Sblocca')) : $e(__('Blocca')) ?>
            </button>
          </form>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/pin')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
              <i class="fas fa-thumbtack"></i><?= (int) $thread['is_pinned'] === 1 ? $e(__('Togli evidenza')) : $e(__('Fissa in alto')) ?>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Posts -->
  <div>
    <?php if ($topLevel === []): ?>
      <div class="bc-card">
        <p class="bc-muted mb-0"><?= $e(__('Nessun messaggio: scrivi il primo!')) ?></p>
      </div>
    <?php endif; ?>

    <?php foreach ($topLevel as $post): ?>
      <?php $postId = (int) $post['id']; ?>
      <div class="bc-card" id="post-<?= $postId ?>">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div>
            <span class="fw-semibold"><?= $post['deleted_at'] === null ? $e(trim((string) ($post['nome'] ?? '') . ' ' . (string) ($post['cognome'] ?? ''))) : $e(__('Utente')) ?></span>
            <span class="bc-muted ms-2"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $post['created_at']))) ?></span>
            <?php if ($post['edited_at'] !== null): ?><span class="bc-muted ms-1"><?= $e(__('(modificato)')) ?></span><?php endif; ?>
          </div>
          <?php if ($canManage && $post['deleted_at'] === null): ?>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $postId . '/delete')) ?>"
                  onsubmit="return confirm('<?= $e(__('Rimuovere questo messaggio?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm" title="<?= $e(__('Rimuovi messaggio')) ?>"><i class="fas fa-trash-alt"></i></button>
            </form>
          <?php endif; ?>
        </div>

        <?= $postBody($post) ?>

        <!-- Reactions -->
        <?php $postReactions = $reactions[$postId] ?? []; ?>
        <?php if ($post['deleted_at'] === null && ($isMember || $canManage)): ?>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $postId . '/react')) ?>" class="d-flex flex-wrap align-items-center gap-2 mt-3">
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
                      class="bc-btn bc-btn-sm <?= $mine ? '' : 'bc-btn-outline' ?>"
                      title="<?= $e(__('Reagisci')) ?>">
                <?= $e($emoji) ?><?= $count > 0 ? ' <span>' . $count . '</span>' : '' ?>
              </button>
            <?php endforeach; ?>
          </form>
        <?php elseif ($postReactions !== []): ?>
          <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            <?php foreach ($postReactions as $r): ?>
              <span class="bc-badge bc-badge-closed"><?= $e($r['emoji']) ?> <span><?= (int) $r['n'] ?></span></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Replies (one level) -->
        <?php foreach ($replies[$postId] ?? [] as $reply): ?>
          <?php $replyId = (int) $reply['id']; ?>
          <div class="mt-3 ms-4 ps-3 border-start" id="post-<?= $replyId ?>">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <div>
                <span class="fw-semibold"><?= $reply['deleted_at'] === null ? $e(trim((string) ($reply['nome'] ?? '') . ' ' . (string) ($reply['cognome'] ?? ''))) : $e(__('Utente')) ?></span>
                <span class="bc-muted ms-2"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $reply['created_at']))) ?></span>
              </div>
              <?php if ($canManage && $reply['deleted_at'] === null): ?>
                <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $replyId . '/delete')) ?>"
                      onsubmit="return confirm('<?= $e(__('Rimuovere questo messaggio?')) ?>');">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm" title="<?= $e(__('Rimuovi messaggio')) ?>"><i class="fas fa-trash-alt"></i></button>
                </form>
              <?php endif; ?>
            </div>

            <?= $postBody($reply) ?>

            <?php $replyReactions = $reactions[$replyId] ?? []; ?>
            <?php if ($reply['deleted_at'] === null && ($isMember || $canManage)): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/posts/' . $replyId . '/react')) ?>" class="d-flex flex-wrap align-items-center gap-2 mt-2">
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
                          class="bc-btn bc-btn-sm <?= $mine ? '' : 'bc-btn-outline' ?>"
                          title="<?= $e(__('Reagisci')) ?>">
                    <?= $e($emoji) ?><?= $count > 0 ? ' <span>' . $count . '</span>' : '' ?>
                  </button>
                <?php endforeach; ?>
              </form>
            <?php elseif ($replyReactions !== []): ?>
              <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <?php foreach ($replyReactions as $r): ?>
                  <span class="bc-badge bc-badge-closed"><?= $e($r['emoji']) ?> <span><?= (int) $r['n'] ?></span></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <!-- Reply form -->
        <?php if ($canPost): ?>
          <details class="mt-3">
            <summary class="small fw-semibold" style="cursor: pointer; color: var(--primary-color)"><?= $e(__('Rispondi')) ?></summary>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/posts')) ?>" class="mt-2">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="parent_id" value="<?= $postId ?>">
              <textarea name="body" rows="2" required maxlength="20000"
                        placeholder="<?= $e(__('Scrivi una risposta…')) ?>"
                        class="form-control mb-2"></textarea>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <select name="spoiler" class="form-select form-select-sm w-auto">
                  <option value="none"><?= $e(__('Nessuno spoiler')) ?></option>
                  <option value="mild"><?= $e(__('Spoiler leggero')) ?></option>
                  <option value="full"><?= $e(__('Spoiler completo')) ?></option>
                </select>
                <?php if ($sections !== []): ?>
                  <select name="spoiler_section_id" class="form-select form-select-sm w-auto">
                    <option value=""><?= $e(__('Spoiler fino a… (facoltativo)')) ?></option>
                    <?php foreach ($sections as $section): ?>
                      <option value="<?= (int) $section['id'] ?>"><?= $e($section['book_title'] . ' — ' . $section['title']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <button type="submit" class="bc-btn bc-btn-sm"><?= $e(__('Invia risposta')) ?></button>
              </div>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- New post -->
  <?php if ($canPost): ?>
    <div class="bc-card mt-4">
      <div class="bc-section-header">
        <i class="fas fa-pen"></i>
        <h2><?= $e(__('Scrivi un messaggio')) ?></h2>
      </div>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/' . $threadId . '/posts')) ?>">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <textarea name="body" rows="4" required maxlength="20000"
                  placeholder="<?= $e(__('Condividi le tue impressioni… usa @nome per menzionare un membro.')) ?>"
                  class="form-control mb-3"></textarea>
        <div class="d-flex flex-wrap align-items-center gap-3">
          <label class="form-label small mb-0"><?= $e(__('Livello spoiler')) ?></label>
          <select name="spoiler" class="form-select form-select-sm w-auto">
            <option value="none"><?= $e(__('Nessuno spoiler')) ?></option>
            <option value="mild"><?= $e(__('Spoiler leggero')) ?></option>
            <option value="full"><?= $e(__('Spoiler completo')) ?></option>
          </select>
          <?php if ($sections !== []): ?>
            <select name="spoiler_section_id" class="form-select form-select-sm w-auto">
              <option value=""><?= $e(__('Spoiler fino a… (facoltativo)')) ?></option>
              <?php foreach ($sections as $section): ?>
                <option value="<?= (int) $section['id'] ?>"><?= $e($section['book_title'] . ' — ' . $section['title']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button type="submit" class="bc-btn"><?= $e(__('Pubblica messaggio')) ?></button>
        </div>
      </form>
    </div>
  <?php elseif ($isMember && $isLocked): ?>
    <p class="bc-muted mt-4"><?= $e(__('La discussione è bloccata: non è possibile aggiungere messaggi.')) ?></p>
  <?php elseif (!$isMember): ?>
    <p class="bc-muted mt-4"><?= $e(__('Solo i membri attivi del club possono scrivere messaggi.')) ?></p>
  <?php endif; ?>
</div>
