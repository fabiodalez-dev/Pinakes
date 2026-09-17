<?php
/**
 * Rendered by DesiderataPlugin::admin(); these come from there.
 *
 * @var list<array<string,mixed>> $offers
 * @var list<array<string,mixed>> $books
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
<div class="space-y-6">
  <div><h1 class="text-2xl font-bold"><?= __('Desiderata e donazioni') ?></h1><p class="text-gray-600 mt-2"><?= __('Valuta le proposte e registra la copia fisica solo dopo la consegna. Accettare una proposta non crea copie.') ?></p></div>
  <div class="flex flex-wrap gap-3"><a class="btn btn-primary" href="<?= $e(url('/admin/books/create')) ?>"><?= __('Aggiungi un desiderata') ?></a><a class="btn btn-secondary" href="<?= $e(url('/desiderata')) ?>"><?= __('Apri la pagina pubblica') ?></a></div>
  <?php if ($error && !$errorInCard): ?><div class="p-4 bg-red-50 text-red-800 rounded-lg" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <section id="requested-books"><h2 class="text-xl font-semibold mb-3"><?= __('Libri richiesti') ?></h2>
  <?php if (!$books): ?><p><?= $booksPage > 1 ? __('Nessuna altra richiesta oltre questa pagina.') : __('Non ci sono richieste aperte. Crea una scheda libro e seleziona Desiderata prima del numero di copie.') ?></p><?php endif; ?>
  <ul class="divide-y"><?php foreach ($books as $b): ?><li class="py-3 flex justify-between gap-3"><span><?= $e($b['titolo']) ?> <span class="text-gray-500"><?= $e($b['autore']) ?></span></span><a class="text-blue-700 underline" href="<?= $e(url('/admin/books/edit/' . (int)$b['id'])) ?>"><?= __('Modifica richiesta') ?></a></li><?php endforeach; ?></ul>
  <?php if (!empty($moreBooks) || $booksPage > 1): ?>
  <nav aria-label="<?= $e(__('Pagine richieste')) ?>" class="flex gap-4 mt-3"><?php if ($booksPage > 1): ?><a class="underline" href="<?= $e($pageUrl('books_page', $booksPage-1) . '#requested-books') ?>"><?= __('Precedente') ?></a><?php endif; ?><?php if (!empty($moreBooks)): ?><a class="underline" href="<?= $e($pageUrl('books_page', $booksPage+1) . '#requested-books') ?>"><?= __('Successiva') ?></a><?php endif; ?></nav>
  <?php endif; ?>
  </section>
  <section id="donation-offers"><h2 class="text-xl font-semibold mb-3"><?= __('Proposte di donazione') ?></h2>
  <?php if (!$offers): ?><p><?= $offersPage > 1 ? __('Nessuna altra proposta oltre questa pagina.') : __('Non sono ancora arrivate proposte.') ?></p><?php endif; ?>
  <?php foreach ($offers as $o): ?>
    <article class="py-5 border-b border-gray-200">
      <div class="flex flex-wrap justify-between gap-2"><h3 class="text-lg font-semibold"><?= $e($o['title']) ?></h3><span class="text-sm font-medium"><?= $e($labels[$o['status']] ?? $o['status']) ?></span></div>
      <p class="text-gray-600"><?= $e(implode(' · ', array_filter([$o['author'], $o['publisher'], $o['isbn']]))) ?></p>
      <p class="my-2"><?= $e($o['donor_name']) ?> · <a class="text-blue-700 underline" href="mailto:<?= $e(rawurlencode($o['donor_email'])) ?>"><?= $e($o['donor_email']) ?></a> · <?= $e($o['created_at']) ?></p>
      <?php if ($o['notes']): ?><p class="whitespace-pre-wrap mb-3"><?= $e($o['notes']) ?></p><?php endif; ?>
      <?php if ($errorInCard && (int)$o['id'] === $errorOfferId): ?><div class="p-4 bg-red-50 text-red-800 rounded-lg mb-3" role="alert"><?= $e($error) ?></div><?php endif; ?>
      <?php if (in_array($o['status'], ['pending','accepted'], true)): ?>
      <form method="post" action="<?= $e(url('/admin/desiderata/offers/' . (int)$o['id']) . $actionQuery) ?>" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
        <?php if (!$o['book_id']): ?>
        <div class="dw-receipt-search">
          <label for="receipt-query-<?= (int)$o['id'] ?>" class="block font-medium"><?= __('Collega la scheda del libro ricevuto') ?></label>
          <input class="form-input mt-1" id="receipt-query-<?= (int)$o['id'] ?>" type="search" maxlength="120" placeholder="<?= $e(__('Cerca titolo o ISBN, almeno 3 caratteri')) ?>" autocomplete="off">
          <label for="receipt-book-<?= (int)$o['id'] ?>" class="block mt-2"><?= __('Libro da acquisire') ?></label>
          <?php // required, so the browser stops a receipt with nothing picked
                // before the round trip that would throw away the typed search
                // and the selection. The other two buttons carry formnovalidate
                // because all three share this one form and neither of them
                // needs the field. manage() still validates server-side: this
                // only spares the operator a reload, it guards nothing. ?>
          <select class="form-input mt-1" id="receipt-book-<?= (int)$o['id'] ?>" name="received_book_id" required><option value=""><?= __('Cerca e seleziona una scheda') ?></option></select>
          <p role="status" class="text-sm text-gray-600 mt-1"></p>
          <p class="text-sm text-gray-600 mt-1"><?= __('Se il libro non esiste, crea prima la scheda completa con zero copie, poi torna qui per registrare la consegna.') ?> <a class="underline" href="<?= $e(url('/admin/books/create')) ?>"><?= __('Crea scheda libro') ?></a></p>
        </div>
        <?php else: ?><p><a class="underline text-blue-700" href="<?= $e(url('/admin/books/' . (int)$o['book_id'])) ?>"><?= __('Apri il libro richiesto') ?></a></p><?php endif; ?>
        <?php // The confirmation belongs on the buttons, not on the form: the
              // three actions share one <form>, so an onsubmit handler would
              // also gate "Accetta proposta", which is reversible and merely
              // tells the donor to bring the book. The other two are terminal —
              // one creates a real inventory copy, the other closes the
              // proposal for good — and manage() refuses any further action
              // once the status is 'received' or 'rejected'.
              //
              // json_encode with the HEX flags, never htmlspecialchars: the
              // attribute is HTML-decoded before the JS is parsed, so an
              // entity-escaped quote would break back out into the script. ?>
        <?php $ask = static fn(string $text): string => 'return confirm(' . json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ');'; ?>
        <div class="flex flex-wrap gap-2">
          <?php if ($o['status'] === 'pending'): ?><button name="action" value="accepted" class="btn btn-secondary" formnovalidate><?= __('Accetta proposta') ?></button><?php endif; ?>
          <button name="action" value="received" class="btn btn-primary" onclick="<?= $e($ask(__('Registrare la copia ricevuta? Viene creata una copia fisica in inventario e la proposta si chiude.'))) ?>"><?= __('Libro arrivato: registra una copia') ?></button>
          <button name="action" value="rejected" class="btn btn-secondary" formnovalidate onclick="<?= $e($ask(__('Rifiutare la proposta? La proposta si chiude e non potrà più essere accettata.'))) ?>"><?= __('Rifiuta proposta') ?></button>
        </div>
      </form>
      <?php elseif ($o['status'] === 'received'): ?><a class="underline text-blue-700" href="<?= $e(url('/admin/books/' . (int)$o['received_book_id'] . '#physical-copies')) ?>"><?= __('Visualizza la copia ricevuta') ?></a><?php endif; ?>
      <?php // The proposal holds the donor's name, e-mail and notes: once it is
            // settled, deleting it is how those data leave the archive. ?>
      <form method="post" action="<?= $e(url('/admin/desiderata/offers/' . (int)$o['id']) . $actionQuery) ?>" class="mt-3" onsubmit="return confirm(<?= $e(json_encode(__('Eliminare la proposta e i dati del donatore? L’operazione non è reversibile.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
        <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
        <button name="action" value="delete" class="text-sm text-red-700 underline"><?= __('Elimina proposta e dati del donatore') ?></button>
      </form>
    </article>
  <?php endforeach; ?>
  <?php if ($more || $offersPage > 1): ?>
  <nav aria-label="<?= $e(__('Pagine proposte')) ?>" class="flex gap-4 mt-4"><?php if ($offersPage > 1): ?><a class="underline" href="<?= $e($pageUrl('offers_page', $offersPage-1) . '#donation-offers') ?>"><?= __('Precedente') ?></a><?php endif; ?><?php if ($more): ?><a class="underline" href="<?= $e($pageUrl('offers_page', $offersPage+1) . '#donation-offers') ?>"><?= __('Successiva') ?></a><?php endif; ?></nav>
  <?php endif; ?>
  </section>
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
      $askJs = static fn(string $text): string => 'return confirm(' . json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ');'; ?>
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
  const sync=() => {
    const option=select.selectedOptions[0];
    const flagged=!!option && option.dataset.desiderata==='1';
    if(receive) receive.setAttribute('onclick', flagged?flaggedAsk:genericAsk);
    if(flagged) status.textContent=flaggedNote;
    else if(option && option.value) status.textContent=pickNote;
  };
  select.addEventListener('change', sync);
  input.addEventListener('input', () => {
    clearTimeout(timer); controller?.abort(); const current=++revision;
    select.replaceChildren(new Option(<?= json_encode(__('Cerca e seleziona una scheda'), JSON_HEX_TAG) ?>,'')); status.textContent=''; sync();
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
        status.textContent=books.length?pickNote:<?= json_encode(__('Nessuna scheda trovata. Crea prima il libro con zero copie.'), JSON_HEX_TAG) ?>;
        sync();
      } catch(error) { if(error.name!=='AbortError' && current===revision) status.textContent=<?= json_encode(__('Ricerca non riuscita. Riprova.'), JSON_HEX_TAG) ?>; }
    },250);
  });
});
</script>
