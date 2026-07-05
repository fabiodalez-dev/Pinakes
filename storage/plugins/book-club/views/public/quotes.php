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
$base = url('/book-club/' . $slug . '/quotes');
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
        'private' => 'bg-gray-100 text-gray-600',
        'public' => 'bg-green-50 text-green-700',
        default => 'bg-blue-50 text-blue-700',
    };
    $icon = match ($visibility) {
        'private' => 'fa-lock',
        'public' => 'fa-globe',
        default => 'fa-users',
    };
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs ' . $classes . '">'
        . '<i class="fas ' . $icon . ' mr-1"></i>' . $e($quoteVisibilityLabels[$visibility] ?? $visibility) . '</span>';
};
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
      <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Citazioni e annotazioni')) ?> — <?= $e($club['name']) ?>
    </h1>
    <div class="flex items-center gap-2">
      <a href="<?= $e($base . '/export.md') ?>"
         class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg"><i class="fab fa-markdown mr-1"></i><?= $e(__('Esporta i miei dati (Markdown)')) ?></a>
      <a href="<?= $e($base . '/export.csv') ?>"
         class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg"><i class="fas fa-file-csv mr-1"></i><?= $e(__('Esporta i miei dati (CSV)')) ?></a>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="flex items-center gap-1 border-b mb-6">
    <a href="<?= $e($base) ?>"
       class="px-4 py-2 text-sm font-medium rounded-t-lg <?= $tab === 'quotes' ? 'bg-white border border-b-white text-gray-900 -mb-px' : 'text-gray-500 hover:text-gray-700' ?>">
      <i class="fas fa-quote-left mr-1"></i><?= $e(__('Citazioni')) ?>
    </a>
    <a href="<?= $e($base . '?tab=notes') ?>"
       class="px-4 py-2 text-sm font-medium rounded-t-lg <?= $tab === 'notes' ? 'bg-white border border-b-white text-gray-900 -mb-px' : 'text-gray-500 hover:text-gray-700' ?>">
      <i class="fas fa-pen-fancy mr-1"></i><?= $e(__('Le mie annotazioni')) ?>
    </a>
  </div>

  <?php if ($tab === 'quotes'): ?>

    <!-- Add quote -->
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Aggiungi una citazione')) ?></h2>
      <?php if ($books === []): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per citarlo.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e($base) ?>" class="grid grid-cols-1 sm:grid-cols-6 gap-3">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <div class="sm:col-span-4">
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Libro')) ?></label>
            <select name="libro_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['libro_id'] ?>">
                  <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Pagina')) ?></label>
            <input type="number" name="page" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Visibilità')) ?></label>
            <select name="visibility" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              <?php foreach ($quoteVisibilityLabels as $vKey => $vLabel): ?>
                <option value="<?= $e($vKey) ?>" <?= $vKey === 'club' ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm:col-span-6">
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Citazione')) ?></label>
            <textarea name="quote" rows="3" required maxlength="5000"
                      placeholder="<?= $e(__('Il testo della citazione…')) ?>"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>
          </div>
          <div class="sm:col-span-6">
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Nota personale (facoltativa)')) ?></label>
            <textarea name="note" rows="2" maxlength="2000"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>
          </div>
          <div class="sm:col-span-6">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
              <i class="fas fa-plus mr-1"></i><?= $e(__('Salva citazione')) ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <!-- Quotes list -->
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Citazioni del club')) ?></h2>
      <?php if ($quotes === []): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Ancora nessuna citazione: aggiungi la prima!')) ?></p>
      <?php endif; ?>
      <?php foreach ($quotes as $quote): ?>
        <?php
          $qid = (int) $quote['id'];
          $isOwner = (int) $quote['user_id'] === $userId;
          $memberName = trim((string) $quote['member_nome'] . ' ' . (string) $quote['member_cognome']);
        ?>
        <article class="border-t first:border-t-0 py-4">
          <blockquote class="text-gray-800 italic border-l-4 pl-4" style="border-color: <?= $e($club['color']) ?>">
            “<?= nl2br($e($quote['quote'])) ?>”
          </blockquote>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-xs text-gray-400">
            <span class="font-medium text-gray-600"><i class="fas fa-book mr-1"></i><?= $e($quote['titolo']) ?></span>
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
            <p class="text-sm text-gray-500 mt-2"><i class="far fa-sticky-note mr-1 text-gray-300"></i><?= nl2br($e($quote['note'])) ?></p>
          <?php endif; ?>
          <?php if ($isOwner || $canManage): ?>
            <div class="flex flex-wrap items-center gap-2 mt-3">
              <?php if ($isOwner): ?>
                <form method="post" action="<?= $e($base . '/' . $qid . '/visibility') ?>" class="flex items-center gap-2">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <select name="visibility" class="border border-gray-200 rounded px-2 py-1 text-xs">
                    <?php foreach ($quoteVisibilityLabels as $vKey => $vLabel): ?>
                      <option value="<?= $e($vKey) ?>" <?= (string) $quote['visibility'] === $vKey ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg"><?= $e(__('Cambia visibilità')) ?></button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= $e($base . '/' . $qid . '/delete') ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa citazione?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-lg"><?= $e(__('Elimina')) ?></button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

  <?php else: ?>

    <!-- Add note -->
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Nuova annotazione')) ?></h2>
      <?php if ($books === []): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per annotarlo.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e($base . '/notes') ?>" class="grid grid-cols-1 sm:grid-cols-6 gap-3">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <div class="sm:col-span-5">
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Libro')) ?></label>
            <select name="club_book_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['id'] ?>">
                  <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Visibilità')) ?></label>
            <select name="visibility" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              <?php foreach ($noteVisibilityLabels as $vKey => $vLabel): ?>
                <option value="<?= $e($vKey) ?>"><?= $e($vLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm:col-span-6">
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Annotazione')) ?></label>
            <textarea name="body" rows="4" required maxlength="20000"
                      placeholder="<?= $e(__('Le tue riflessioni su questo libro…')) ?>"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>
          </div>
          <div class="sm:col-span-6">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
              <i class="fas fa-plus mr-1"></i><?= $e(__('Salva annotazione')) ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <!-- My notes -->
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Le mie annotazioni')) ?></h2>
      <?php if ($myNotes === []): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Non hai ancora scritto annotazioni.')) ?></p>
      <?php endif; ?>
      <?php foreach ($myNotes as $note): ?>
        <?php $nid = (int) $note['id']; ?>
        <article class="border rounded-lg px-4 py-3 mb-3">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <span class="text-sm font-medium text-gray-700"><i class="fas fa-book mr-1 text-gray-300"></i><?= $e($note['titolo']) ?></span>
            <span class="text-xs text-gray-400">
              <?= $e($noteVisibilityLabels[(string) $note['visibility']] ?? (string) $note['visibility']) ?>
              · <?= $e(date('d/m/Y', (int) strtotime((string) $note['created_at']))) ?>
            </span>
          </div>
          <form method="post" action="<?= $e($base . '/notes/' . $nid . '/update') ?>" class="grid grid-cols-1 sm:grid-cols-6 gap-2">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <div class="sm:col-span-6">
              <textarea name="body" rows="3" required maxlength="20000"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><?= $e($note['body']) ?></textarea>
            </div>
            <div>
              <select name="visibility" class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                <?php foreach ($noteVisibilityLabels as $vKey => $vLabel): ?>
                  <option value="<?= $e($vKey) ?>" <?= (string) $note['visibility'] === $vKey ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sm:col-span-5 flex items-center gap-2">
              <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg"><?= $e(__('Salva')) ?></button>
            </div>
          </form>
          <form method="post" action="<?= $e($base . '/notes/' . $nid . '/delete') ?>" class="mt-2"
                onsubmit="return confirm('<?= $e(__('Eliminare questa annotazione?')) ?>');">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-lg"><?= $e(__('Elimina')) ?></button>
          </form>
        </article>
      <?php endforeach; ?>
    </section>

    <!-- Club-shared notes of other members -->
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Annotazioni condivise dal club')) ?></h2>
      <?php if ($clubNotes === []): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Nessun altro membro ha condiviso annotazioni.')) ?></p>
      <?php endif; ?>
      <?php foreach ($clubNotes as $note): ?>
        <?php $memberName = trim((string) $note['member_nome'] . ' ' . (string) $note['member_cognome']); ?>
        <article class="border-t first:border-t-0 py-3">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
            <span class="text-sm font-medium text-gray-700"><i class="fas fa-book mr-1 text-gray-300"></i><?= $e($note['titolo']) ?></span>
            <span class="text-xs text-gray-400">
              <i class="far fa-user mr-1"></i><?= $e($memberName) ?>
              · <?= $e(date('d/m/Y', (int) strtotime((string) $note['created_at']))) ?>
            </span>
          </div>
          <p class="text-sm text-gray-600"><?= nl2br($e($note['body'])) ?></p>
          <?php if ($canManage): ?>
            <form method="post" action="<?= $e($base . '/notes/' . (int) $note['id'] . '/delete') ?>" class="mt-2"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa annotazione?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-lg"><?= $e(__('Elimina')) ?></button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

  <?php endif; ?>
</div>
