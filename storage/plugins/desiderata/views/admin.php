<?php
/**
 * Rendered by DesiderataPlugin::admin(); these come from there.
 *
 * The page wears the same chrome as the rest of the back office — the
 * max-w-7xl container of /admin/cms/home, its header block and its white
 * rounded cards — because a plugin screen that looks like a different product
 * reads as broken. Every class is copied verbatim from app/Views/cms/edit-home.php
 * or app/Views/dashboard/index.php: public/assets/main.css is a purged Tailwind
 * build made from the core views, so a utility used nowhere in them has no rule.
 *
 * Proposals come first and requests second: a proposal is somebody waiting for
 * an answer, the wanted list is reference material.
 *
 * @var list<array<string,mixed>> $offers
 * @var list<array<string,mixed>> $books
 * @var array<int, list<array<string,mixed>>> $isbnMatches  offer id => records carrying its ISBN
 * @var int $offersPage
 * @var int $booksPage
 * @var bool $more
 * @var bool $moreBooks
 * @var string $error
 * @var int $errorOfferId
 */
$e = [DesiderataPlugin::class, 'e'];
$token = \App\Support\Csrf::ensureToken();
$labels = ['pending' => __('Da valutare'), 'accepted' => __('In attesa della consegna'), 'rejected' => __('Rifiutata'), 'received' => __('Ricevuta')];
$statusChip = [
    'pending' => 'bg-blue-100 text-blue-700',
    'accepted' => 'bg-purple-100 text-purple-700',
    'rejected' => 'bg-red-100 text-red-800',
    'received' => 'bg-green-100 text-green-800',
];
// Each list owns its cursor, and every link carries the sibling's value so that
// paging one list never moves the other. The whole href goes through $e(), so
// the ampersand http_build_query() emits is escaped in the attribute.
$pageUrl = static function (string $key, int $value) use ($offersPage, $booksPage): string {
    $params = ['offers_page' => $offersPage, 'books_page' => $booksPage];
    $params[$key] = $value;
    return url('/admin/desiderata') . DesiderataPlugin::pagingQuery($params['offers_page'], $params['books_page']);
};
// Actions post back to the page the operator is on, not to a bare URL.
$actionQuery = DesiderataPlugin::pagingQuery($offersPage, $booksPage);
// A failure about one proposal is shown inside that proposal's card, where the
// operator is looking, instead of on a banner up to thirty cards above it. The
// banner still carries anything not tied to a card — and anything tied to one
// that is not on this page, so a message can never be dropped in silence.
$errorInCard = $error !== '' && $errorOfferId > 0
    && in_array($errorOfferId, array_map(static fn(array $o): int => (int)$o['id'], $offers), true);
?>
<div class="max-w-7xl mx-auto py-6 px-4">
  <div class="mb-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
          <i class="fas fa-hand-holding-heart text-blue-600"></i>
          <?= __('Desiderata e donazioni') ?>
        </h1>
        <p class="mt-1 text-sm text-gray-600"><?= __('Valuta le proposte e registra la copia fisica solo dopo la consegna. Accettare una proposta non crea copie.') ?></p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <a class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-colors" href="<?= $e(url('/admin/books/create')) ?>"><i class="fas fa-plus"></i><?= __('Aggiungi un desiderata') ?></a>
        <a class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-colors" href="<?= $e(url('/desiderata')) ?>"><i class="fas fa-external-link-alt"></i><?= __('Apri la pagina pubblica') ?></a>
      </div>
    </div>
  </div>
  <?php if ($error && !$errorInCard): ?><div class="mb-6 p-4 bg-red-50 text-red-800 border border-red-200 rounded-xl" role="alert"><?= $e($error) ?></div><?php endif; ?>

  <div class="space-y-8">

    <!-- Proposals: the queue that needs an answer -->
    <section id="donation-offers" class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-gift text-blue-500"></i>
            <?= __('Proposte di donazione') ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __('Lettori che offrono un libro') ?></p>
        </div>
        <span class="bg-blue-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= count($offers) ?></span>
      </div>
      <div class="p-6 space-y-4">
        <?php if (!$offers): ?><p class="text-sm text-gray-600"><?= $offersPage > 1 ? __('Nessuna altra proposta oltre questa pagina.') : __('Non sono ancora arrivate proposte.') ?></p><?php endif; ?>
        <?php foreach ($offers as $o): $offerId = (int)$o['id']; $matches = $isbnMatches[$offerId] ?? []; ?>
        <article class="rounded-xl border border-gray-200 p-5">
          <div class="flex flex-wrap justify-between items-start gap-2">
            <h3 class="text-lg font-semibold text-gray-900"><?= $e($o['title']) ?></h3>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $e($statusChip[$o['status']] ?? 'bg-gray-100 text-gray-700') ?>"><?= $e($labels[$o['status']] ?? $o['status']) ?></span>
          </div>
          <p class="text-sm text-gray-600 mt-1"><?= $e(implode(' · ', array_filter([$o['author'], $o['publisher'], $o['isbn']]))) ?></p>
          <?php // A receipt registered at the desk has no donor: the empty
                // fields are the audit row's shape, not missing data, and the
                // label is written here rather than stored so it reads in the
                // operator's own language (decision J). ?>
          <p class="text-sm text-gray-600 my-2"><?php if (($o['donor_email'] ?? '') === ''): ?><?= __('Ricezione diretta registrata dallo staff') ?><?php else: ?><span class="font-medium text-gray-900"><?= $e($o['donor_name']) ?></span> · <a class="text-blue-700 underline" href="mailto:<?= $e(rawurlencode($o['donor_email'])) ?>"><?= $e($o['donor_email']) ?></a><?php endif; ?> · <?= $e($o['created_at']) ?></p>
          <?php if ($o['notes']): ?><p class="text-sm text-gray-700 whitespace-pre-wrap mb-3"><?= $e($o['notes']) ?></p><?php endif; ?>
          <?php if ($errorInCard && $offerId === $errorOfferId): ?><div class="p-4 bg-red-50 text-red-800 border border-red-200 rounded-xl mb-3" role="alert"><?= $e($error) ?></div><?php endif; ?>
          <?php if (in_array($o['status'], ['pending','accepted'], true)): ?>
          <form method="post" action="<?= $e(url('/admin/desiderata/offers/' . $offerId) . $actionQuery) ?>" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
            <?php if (!$o['book_id']): ?>
            <div class="dw-receipt-search rounded-xl border border-gray-200 bg-gray-50 p-4">
              <?php if ($matches !== []): ?>
              <?php // The donor already told us which book this is. Pre-filling
                    // the picker turns a search into a confirmation; it stays a
                    // suggestion, and the search below still overrides it. ?>
              <p class="text-sm text-gray-700 bg-blue-50 border border-blue-200 rounded-xl p-3 mb-3">
                <i class="fas fa-link mr-2 text-blue-500"></i>
                <?= count($matches) === 1
                    ? __('Scheda trovata dall’ISBN della proposta: controlla che sia quella giusta prima di registrare.')
                    : __('Più schede corrispondono all’ISBN della proposta: scegli quella giusta.') ?>
              </p>
              <?php endif; ?>
              <label for="receipt-query-<?= $offerId ?>" class="form-label"><?= __('Collega la scheda del libro ricevuto') ?></label>
              <input class="form-input" id="receipt-query-<?= $offerId ?>" type="search" maxlength="120" placeholder="<?= $e(__('Cerca titolo o ISBN, almeno 3 caratteri')) ?>" autocomplete="off">
              <label for="receipt-book-<?= $offerId ?>" class="form-label mt-3"><?= __('Libro da acquisire') ?></label>
              <?php // required, so the browser stops a receipt with nothing picked
                    // before the round trip that would throw away the typed search
                    // and the selection. The other two buttons carry formnovalidate
                    // because all three share this one form and neither of them
                    // needs the field. manage() still validates server-side: this
                    // only spares the operator a reload, it guards nothing. ?>
              <select class="form-input" id="receipt-book-<?= $offerId ?>" name="received_book_id" required>
                <option value=""><?= __('Cerca e seleziona una scheda') ?></option>
                <?php foreach ($matches as $m): ?>
                <option value="<?= (int)$m['id'] ?>"<?= count($matches) === 1 ? ' selected' : '' ?><?= (int)($m['is_desiderata'] ?? 0) === 1 ? ' data-desiderata="1"' : '' ?>><?= $e($m['titolo'] . ' · ' . (($m['isbn13'] ?? '') ?: (($m['isbn10'] ?? '') ?: '#' . (int)$m['id'])) . ((int)($m['is_desiderata'] ?? 0) === 1 ? ' — ' . __('richiesta aperta') : '')) ?></option>
                <?php endforeach; ?>
              </select>
              <p role="status" class="text-sm text-gray-600 mt-2"></p>
              <p class="text-sm text-gray-500 mt-2"><?= __('Se il libro non esiste, crea prima la scheda completa con zero copie, poi torna qui per registrare la consegna.') ?> <a class="text-blue-700 underline" href="<?= $e(url('/admin/books/create')) ?>"><?= __('Crea scheda libro') ?></a></p>
            </div>
            <?php else: ?><p class="text-sm"><a class="text-blue-700 underline" href="<?= $e(url('/admin/books/' . (int)$o['book_id'])) ?>"><?= __('Apri il libro richiesto') ?></a></p><?php endif; ?>
            <?php // The confirmation belongs on the buttons, not on the form: the
                  // three actions share one <form>, so an onsubmit handler would
                  // also gate "Accetta proposta", which is reversible and merely
                  // tells the donor to bring the book. The other two are terminal —
                  // one creates a real inventory copy, the other closes the
                  // proposal for good — and manage() refuses any further action
                  // once the status is 'received' or 'rejected'. ?>
            <?php $ask = [DesiderataPlugin::class, 'confirmJs']; ?>
            <div class="flex flex-wrap gap-2">
              <?php if ($o['status'] === 'pending'): ?><button name="action" value="accepted" class="btn btn-secondary" formnovalidate><?= __('Accetta proposta') ?></button><?php endif; ?>
              <button name="action" value="received" class="btn btn-primary" onclick="<?= $e($ask(__('Registrare la copia ricevuta? Viene creata una copia fisica in inventario e la proposta si chiude.'))) ?>"><?= __('Libro arrivato: registra una copia') ?></button>
              <button name="action" value="rejected" class="btn btn-secondary" formnovalidate onclick="<?= $e($ask(__('Rifiutare la proposta? La proposta si chiude e non potrà più essere accettata.'))) ?>"><?= __('Rifiuta proposta') ?></button>
            </div>
          </form>
          <?php elseif ($o['status'] === 'received'): ?><p class="text-sm"><a class="text-blue-700 underline" href="<?= $e(url('/admin/books/' . (int)$o['received_book_id'] . '#physical-copies')) ?>"><?= __('Visualizza la copia ricevuta') ?></a></p><?php endif; ?>
          <?php // The proposal holds the donor's name, e-mail and notes: once it is
                // settled, deleting it is how those data leave the archive. ?>
          <form method="post" action="<?= $e(url('/admin/desiderata/offers/' . $offerId) . $actionQuery) ?>" class="mt-3" onsubmit="return confirm(<?= $e(json_encode(__('Eliminare la proposta e i dati del donatore? L’operazione non è reversibile.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
            <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
            <button name="action" value="delete" class="text-sm text-red-700 underline"><?= __('Elimina proposta e dati del donatore') ?></button>
          </form>
        </article>
        <?php endforeach; ?>
        <?php if ($more || $offersPage > 1): ?>
        <nav aria-label="<?= $e(__('Pagine proposte')) ?>" class="flex gap-4 pt-2"><?php if ($offersPage > 1): ?><a class="text-blue-700 underline" href="<?= $e($pageUrl('offers_page', $offersPage-1) . '#donation-offers') ?>"><?= __('Precedente') ?></a><?php endif; ?><?php if ($more): ?><a class="text-blue-700 underline" href="<?= $e($pageUrl('offers_page', $offersPage+1) . '#donation-offers') ?>"><?= __('Successiva') ?></a><?php endif; ?></nav>
        <?php endif; ?>
      </div>
    </section>

    <!-- Requests: the books the library is still looking for -->
    <section id="requested-books" class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-search text-purple-500"></i>
            <?= __('Libri richiesti') ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __('Richieste aperte in attesa di una donazione') ?></p>
        </div>
        <span class="bg-purple-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= count($books) ?></span>
      </div>
      <div class="p-6">
        <?php if (!$books): ?><p class="text-sm text-gray-600"><?= $booksPage > 1 ? __('Nessuna altra richiesta oltre questa pagina.') : __('Non ci sono richieste aperte. Crea una scheda libro e seleziona Desiderata prima del numero di copie.') ?></p><?php endif; ?>
        <ul class="divide-y divide-gray-200"><?php foreach ($books as $b): $bookId = (int)$b['id']; ?>
          <li class="py-4 flex flex-wrap justify-between items-center gap-3">
            <div class="min-w-0">
              <p class="font-medium text-gray-900"><?= $e($b['titolo']) ?></p>
              <?php // Same meta line as the public list: author, publisher, ISBN. ?>
              <p class="text-sm text-gray-600"><?= $e(implode(' · ', array_filter([$b['autore'] ?? '', $b['editore'] ?? '', ($b['isbn13'] ?? '') ?: ($b['isbn10'] ?? '')]))) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <?php if ((int)($b['open_offers'] ?? 0) > 0): ?>
              <?php // Somebody has already offered this book, so the receipt belongs
                    // to that proposal: registering it here would leave the donor's
                    // proposal open for good with the book already in the catalogue,
                    // and receiveDirect() refuses it for exactly that reason. ?>
              <a class="text-blue-700 underline text-sm" href="<?= $e(url('/admin/desiderata') . $actionQuery . '#donation-offers') ?>"><?= $e(__n('%d proposta aperta', '%d proposte aperte', (int)$b['open_offers'])) ?></a>
              <?php else: ?>
              <form method="post" action="<?= $e(url('/admin/desiderata/books/' . $bookId . '/received')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
                <button class="btn btn-primary" onclick="<?= $e(DesiderataPlugin::confirmJs(__('Registrare il libro come donato? Viene creata una copia fisica in inventario e la richiesta si chiude.'))) ?>"><?= __('Libro donato: registra la copia') ?></button>
              </form>
              <?php endif; ?>
              <a class="text-blue-700 underline text-sm" href="<?= $e(url('/admin/books/edit/' . $bookId)) ?>"><?= __('Modifica richiesta') ?></a>
            </div>
          </li>
        <?php endforeach; ?></ul>
        <?php if (!empty($moreBooks) || $booksPage > 1): ?>
        <nav aria-label="<?= $e(__('Pagine richieste')) ?>" class="flex gap-4 mt-4"><?php if ($booksPage > 1): ?><a class="text-blue-700 underline" href="<?= $e($pageUrl('books_page', $booksPage-1) . '#requested-books') ?>"><?= __('Precedente') ?></a><?php endif; ?><?php if (!empty($moreBooks)): ?><a class="text-blue-700 underline" href="<?= $e($pageUrl('books_page', $booksPage+1) . '#requested-books') ?>"><?= __('Successiva') ?></a><?php endif; ?></nav>
        <?php endif; ?>
      </div>
    </section>

  </div>
</div>

<?php // The picker lists every non-deleted record, open requests included: an
      // operator who genuinely received a requested book has to be able to pick
      // it, and a free-form donation against an ordinary holding is a supported
      // workflow. What must not happen silently is the other case — closing
      // somebody else's open request with an unrelated donation — so a flagged
      // record is labelled in the list, called out in the status line, and
      // carries its own, more specific confirmation on the receipt button. The
      // generic one stays for every ordinary record.
      //
      // Same rule as the buttons above: json_encode with the HEX flags, never
      // htmlspecialchars, for anything that ends up inside an onclick — the
      // attribute is HTML-decoded before the JS parser sees it.
      $askJs = [DesiderataPlugin::class, 'confirmJs']; ?>
<script>
document.querySelectorAll('.dw-receipt-search').forEach(root => {
  const input=root.querySelector('input'), select=root.querySelector('select'), status=root.querySelector('[role="status"]');
  const receive=root.closest('form')?.querySelector('button[name="action"][value="received"]');
  const genericAsk=(receive && receive.getAttribute('onclick')) || '';
  const flaggedAsk=<?= json_encode($askJs(__('Questa scheda è una richiesta aperta della biblioteca: registrando la copia, quella richiesta risulterà soddisfatta da questa donazione. Procedere?')), JSON_HEX_TAG) ?>;
  const flaggedNote=<?= json_encode(__('Attenzione: la scheda selezionata è una richiesta aperta della biblioteca e verrà chiusa dalla registrazione.'), JSON_HEX_TAG) ?>;
  const pickNote=<?= json_encode(__('Seleziona il libro corrispondente prima di registrare la copia.'), JSON_HEX_TAG) ?>;
  const flaggedTag=<?= json_encode(__('richiesta aperta'), JSON_HEX_TAG) ?>;
  let timer, controller, revision=0;
  // EVERY path writes the status line. Leaving it untouched when nothing is
  // selected let the open-request warning outlive its selection: pick a
  // flagged record, then reselect the empty placeholder, and the page went on
  // announcing that a request would be closed by a form that had no record in
  // it at all. `fallback` is what "nothing selected" should read — the caller
  // that has just searched knows better than sync() does, because "no record
  // found" and "a list waiting to be chosen from" are not the same silence.
  const sync=(fallback) => {
    const option=select.selectedOptions[0];
    const flagged=!!option && option.dataset.desiderata==='1';
    if(receive) receive.setAttribute('onclick', flagged?flaggedAsk:genericAsk);
    status.textContent = flagged ? flaggedNote
      : (option && option.value) ? pickNote
      : (fallback ?? (select.options.length>1 ? pickNote : ''));
  };
  // Wrapped, not passed by reference: as a listener sync() would receive the
  // Event as its fallback and print "[object Event]" into the status line.
  select.addEventListener('change', () => sync());
  // Run once: a select pre-filled from the proposal's ISBN starts with a
  // selection nobody changed, and without this the flagged-record warning and
  // its stricter confirmation would only appear if the operator touched it.
  sync();
  input.addEventListener('input', () => {
    clearTimeout(timer); controller?.abort(); const current=++revision;
    select.replaceChildren(new Option(<?= json_encode(__('Cerca e seleziona una scheda'), JSON_HEX_TAG) ?>,'')); sync('');
    if([...input.value.trim()].length<3) return;
    timer=setTimeout(async () => {
      controller=new AbortController();
      try {
        const response=await fetch(<?= json_encode(url('/admin/desiderata/books'), JSON_HEX_TAG) ?>+'?q='+encodeURIComponent(input.value.trim()),{signal:controller.signal});
        if(!response.ok) throw new Error(); const books=await response.json(); if(current!==revision) return;
        books.forEach(b => {
          const flagged=Number(b.is_desiderata)===1;
          const option=new Option(b.titolo+' · '+(b.isbn13 || b.isbn10 || '#'+b.id)+(flagged?' — '+flaggedTag:''),b.id);
          if(flagged) option.dataset.desiderata='1';
          select.add(option);
        });
        sync(books.length?pickNote:<?= json_encode(__('Nessuna scheda trovata. Crea prima il libro con zero copie.'), JSON_HEX_TAG) ?>);
      } catch(error) { if(error.name!=='AbortError' && current===revision) status.textContent=<?= json_encode(__('Ricerca non riuscita. Riprova.'), JSON_HEX_TAG) ?>; }
    },250);
  });
});
</script>
