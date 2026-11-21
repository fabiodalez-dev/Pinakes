<?php
/**
 * Book Club — quotes module: club page with two tabs.
 *  - Citazioni: club-visible + public quotes of the club's books, add form,
 *    owner-only visibility change / delete.
 *  - Le mie annotazioni: the member's own per-book notes (add/edit/delete,
 *    private or club-shared) plus club-shared notes of other members.
 *
 * @var array<string, mixed> $club
 * @var string $tab                                'quotes' | 'notes'
 * @var int $userId
 * @var bool $canManage
 * @var list<array<string, mixed>> $books          club books (form selects)
 * @var list<array<string, mixed>> $quotes         quotes visible to the viewer
 * @var list<array<string, mixed>> $myNotes
 * @var list<array<string, mixed>> $clubNotes      club-shared notes of others
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug . '/quotes');
$quoteVisibilityLabels = [
    'private' => __('Privata'),
    'club' => __('Solo club'),
    'public' => __('Pubblica'),
];
$noteVisibilityLabels = [
    'private' => __('Privata'),
    'club' => __('Solo club'),
];
$visibilityBadge = static function (string $visibility) use ($e, $quoteVisibilityLabels): string {
    $classes = match ($visibility) {
        'private' => 'bc-badge bc-badge-closed',
        'public' => 'bc-badge bc-badge-open',
        default => 'bc-badge bc-badge-club',
    };
    $icon = match ($visibility) {
        'private' => 'fa-lock',
        'public' => 'fa-globe',
        default => 'fa-users',
    };
    return '<span class="' . $classes . '">'
        . '<i class="fas ' . $icon . '"></i>' . $e($quoteVisibilityLabels[$visibility] ?? $visibility) . '</span>';
};
?>
<?php $bookClubStyles = true; ?>
<style<?= csp_nonce_attr() ?>>
  .bc-quote{border-left:1px solid var(--primary-color);padding-left:1rem;font-style:italic;color:var(--text-color);margin:0}
  .bc-badge-club{background:var(--accent-color);color:var(--primary-text, var(--primary-color))}
</style>
<div class="container py-4">
  <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug)) ?>" class="bc-muted no-underline">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-3 mb-4">
    <div class="bc-section-header mb-0">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <h1><?= $e(__('Citazioni e annotazioni')) ?> — <?= $e($club['name']) ?></h1>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= $e($base . '/export.md') ?>" class="bc-btn bc-btn-outline bc-btn-sm"><i class="fab fa-markdown"></i><?= $e(__('Esporta i miei dati (Markdown)')) ?></a>
      <a href="<?= $e($base . '/export.csv') ?>" class="bc-btn bc-btn-outline bc-btn-sm"><i class="fas fa-file-csv"></i><?= $e(__('Esporta i miei dati (CSV)')) ?></a>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Tabs -->
  <ul class="flex flex-wrap nav-tabs mb-4">
    <li class="nav-item">
      <a href="<?= $e($base) ?>" class="nav-link <?= $tab === 'quotes' ? 'active' : '' ?>">
        <i class="fas fa-quote-left mr-1"></i><?= $e(__('Citazioni')) ?>
      </a>
    </li>
    <li class="nav-item">
      <a href="<?= $e($base . '?tab=notes') ?>" class="nav-link <?= $tab === 'notes' ? 'active' : '' ?>">
        <i class="fas fa-pen-fancy mr-1"></i><?= $e(__('Le mie annotazioni')) ?>
      </a>
    </li>
  </ul>

  <?php if ($tab === 'quotes'): ?>

    <!-- Add quote -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-quote-left"></i>
        <h2><?= $e(__('Aggiungi una citazione')) ?></h2>
      </div>
      <?php if ($books === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per citarlo.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e($base) ?>" class="flex flex-wrap -mx-3 gap-y-3">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <div class="w-full px-3 md:w-7/12">
            <label class="form-label text-sm"><?= $e(__('Libro')) ?></label>
            <select name="libro_id" required class="form-input">
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['libro_id'] ?>">
                  <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="w-1/2 px-3 md:w-1/6">
            <label class="form-label text-sm"><?= $e(__('Pagina')) ?></label>
            <input type="number" name="page" min="0" class="form-input">
          </div>
          <div class="w-1/2 px-3 md:w-1/4">
            <label class="form-label text-sm"><?= $e(__('Visibilità')) ?></label>
            <select name="visibility" class="form-input">
              <?php foreach ($quoteVisibilityLabels as $vKey => $vLabel): ?>
                <option value="<?= $e($vKey) ?>" <?= $vKey === 'club' ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="w-full px-3">
            <label class="form-label text-sm"><?= $e(__('Citazione')) ?></label>
            <textarea name="quote" rows="3" required maxlength="5000"
                      placeholder="<?= $e(__('Il testo della citazione…')) ?>" class="form-input"></textarea>
          </div>
          <div class="w-full px-3">
            <label class="form-label text-sm"><?= $e(__('Nota personale (facoltativa)')) ?></label>
            <textarea name="note" rows="2" maxlength="2000" class="form-input"></textarea>
          </div>
          <div class="w-full px-3">
            <button type="submit" class="bc-btn">
              <i class="fas fa-plus"></i><?= $e(__('Salva citazione')) ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <!-- Quotes list -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-book-open"></i>
        <h2><?= $e(__('Citazioni del club')) ?></h2>
      </div>
      <?php if ($quotes === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Ancora nessuna citazione: aggiungi la prima!')) ?></p>
      <?php endif; ?>
      <?php foreach ($quotes as $quote): ?>
        <?php
          $qid = (int) $quote['id'];
          $isOwner = (int) $quote['user_id'] === $userId;
          $memberName = trim((string) $quote['member_nome'] . ' ' . (string) $quote['member_cognome']);
        ?>
        <article class="bc-list-item block">
          <blockquote class="bc-quote" style="border-left-color: <?= $e($club['color']) ?>">
            “<?= nl2br($e($quote['quote'])) ?>”
          </blockquote>
          <div class="flex flex-wrap items-center gap-2 mt-2 bc-muted text-sm">
            <span class="font-medium"><i class="fas fa-book mr-1"></i><?= $e($quote['titolo']) ?></span>
            <?php if (!empty($quote['autori'])): ?>
              <span><?= $e($quote['autori']) ?></span>
            <?php endif; ?>
            <?php if ($quote['page'] !== null): ?>
              <span><?= $e(sprintf(__('pag. %d'), (int) $quote['page'])) ?></span>
            <?php endif; ?>
            <span><i class="far fa-user mr-1"></i><?= $e($memberName) ?></span>
            <span><?= $e(date('d/m/Y', (int) strtotime((string) $quote['created_at']))) ?></span>
            <?= $visibilityBadge((string) $quote['visibility']) ?>
          </div>
          <?php if ((string) ($quote['note'] ?? '') !== '' && ($isOwner || (string) $quote['visibility'] !== 'private')): ?>
            <p class="bc-muted mt-2 mb-0"><i class="far fa-sticky-note mr-1"></i><?= nl2br($e($quote['note'])) ?></p>
          <?php endif; ?>
          <?php if ($isOwner || $canManage): ?>
            <div class="flex flex-wrap items-center gap-2 mt-3">
              <?php if ($isOwner): ?>
                <form method="post" action="<?= $e($base . '/' . $qid . '/visibility') ?>" class="flex items-center gap-2">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <select name="visibility" class="form-input px-3 py-2 text-sm w-auto">
                    <?php foreach ($quoteVisibilityLabels as $vKey => $vLabel): ?>
                      <option value="<?= $e($vKey) ?>" <?= (string) $quote['visibility'] === $vKey ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Cambia visibilità')) ?></button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= $e($base . '/' . $qid . '/delete') ?>"
                    data-pk-confirm="<?= $e(__('Eliminare questa citazione?')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Elimina')) ?></button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

  <?php else: ?>

    <!-- Add note -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-pen-fancy"></i>
        <h2><?= $e(__('Nuova annotazione')) ?></h2>
      </div>
      <?php if ($books === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per annotarlo.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e($base . '/notes') ?>" class="flex flex-wrap -mx-3 gap-y-3">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <div class="w-full px-3 md:w-2/3">
            <label class="form-label text-sm"><?= $e(__('Libro')) ?></label>
            <select name="club_book_id" required class="form-input">
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['id'] ?>">
                  <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="w-full px-3 md:w-1/3">
            <label class="form-label text-sm"><?= $e(__('Visibilità')) ?></label>
            <select name="visibility" class="form-input">
              <?php foreach ($noteVisibilityLabels as $vKey => $vLabel): ?>
                <option value="<?= $e($vKey) ?>"><?= $e($vLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="w-full px-3">
            <label class="form-label text-sm"><?= $e(__('Annotazione')) ?></label>
            <textarea name="body" rows="4" required maxlength="20000"
                      placeholder="<?= $e(__('Le tue riflessioni su questo libro…')) ?>" class="form-input"></textarea>
          </div>
          <div class="w-full px-3">
            <button type="submit" class="bc-btn">
              <i class="fas fa-plus"></i><?= $e(__('Salva annotazione')) ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <!-- My notes -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-book-bookmark"></i>
        <h2><?= $e(__('Le mie annotazioni')) ?></h2>
      </div>
      <?php if ($myNotes === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Non hai ancora scritto annotazioni.')) ?></p>
      <?php endif; ?>
      <?php foreach ($myNotes as $note): ?>
        <?php $nid = (int) $note['id']; ?>
        <article class="border p-3 mb-3">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <span class="font-medium text-sm"><i class="fas fa-book mr-1" style="color:var(--text-muted)"></i><?= $e($note['titolo']) ?></span>
            <span class="bc-muted text-sm">
              <?= $e($noteVisibilityLabels[(string) $note['visibility']] ?? (string) $note['visibility']) ?>
              · <?= $e(date('d/m/Y', (int) strtotime((string) $note['created_at']))) ?>
            </span>
          </div>
          <form method="post" action="<?= $e($base . '/notes/' . $nid . '/update') ?>" class="flex flex-wrap -mx-3 gap-y-2">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <div class="w-full px-3">
              <textarea name="body" rows="3" required maxlength="20000" class="form-input"><?= $e($note['body']) ?></textarea>
            </div>
            <div class="w-full px-3 md:w-1/3">
              <select name="visibility" class="form-input px-3 py-2 text-sm">
                <?php foreach ($noteVisibilityLabels as $vKey => $vLabel): ?>
                  <option value="<?= $e($vKey) ?>" <?= (string) $note['visibility'] === $vKey ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="w-full px-3 md:w-2/3 flex items-center gap-2">
              <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Salva')) ?></button>
            </div>
          </form>
          <form method="post" action="<?= $e($base . '/notes/' . $nid . '/delete') ?>" class="mt-2"
                data-pk-confirm="<?= $e(__('Eliminare questa annotazione?')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Elimina')) ?></button>
          </form>
        </article>
      <?php endforeach; ?>
    </section>

    <!-- Club-shared notes of other members -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-users"></i>
        <h2><?= $e(__('Annotazioni condivise dal club')) ?></h2>
      </div>
      <?php if ($clubNotes === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun altro membro ha condiviso annotazioni.')) ?></p>
      <?php endif; ?>
      <?php foreach ($clubNotes as $note): ?>
        <?php $memberName = trim((string) $note['member_nome'] . ' ' . (string) $note['member_cognome']); ?>
        <article class="bc-list-item block">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
            <span class="font-medium text-sm"><i class="fas fa-book mr-1" style="color:var(--text-muted)"></i><?= $e($note['titolo']) ?></span>
            <span class="bc-muted text-sm">
              <i class="far fa-user mr-1"></i><?= $e($memberName) ?>
              · <?= $e(date('d/m/Y', (int) strtotime((string) $note['created_at']))) ?>
            </span>
          </div>
          <p class="text-sm mb-0" style="color:var(--text-light)"><?= nl2br($e($note['body'])) ?></p>
          <?php if ($canManage): ?>
            <form method="post" action="<?= $e($base . '/notes/' . (int) $note['id'] . '/delete') ?>" class="mt-2"
                  data-pk-confirm="<?= $e(__('Eliminare questa annotazione?')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Elimina')) ?></button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

  <?php endif; ?>
</div>
