<?php
/**
 * Rendered by DesiderataPlugin::renderHomeSection() (inside the ordered home
 * loop) and by ::page() (the standalone /desiderata route).
 *
 * @var list<array<string,mixed>> $books
 * @var array<string,string>|null $texts   Per-locale operator texts, see DesiderataPlugin::texts()
 * @var bool|null $standalone
 * @var array<string,mixed>|null $values
 * @var string|null $error
 * @var string|null $recaptchaSiteKey
 * @var string|null $returnTo
 * @var array{page:int,pages:int,total:int}|null $pager  set on /desiderata only
 * @var int|null $wantedTotal  open requests in total, to size the homepage link
 */
$e = [DesiderataPlugin::class, 'e'];
$headingTag = !empty($standalone) ? 'h1' : 'h2';
$texts = is_array($texts ?? null) ? $texts : [];
$values = $values ?? [];
$value = static fn(string $key): string => is_scalar($values[$key] ?? '') ? $e($values[$key] ?? '') : '';
$success = !empty($_SESSION['desiderata_success']); unset($_SESSION['desiderata_success']);
$pager = is_array($pager ?? null) ? $pager : null;
$wantedTotal = (int) ($wantedTotal ?? count($books));
// Page one has no ?page=: a link that says "1" and a link that says nothing
// would be two URLs for one list, which is one canonical URL too many.
$pageUrl = static fn(int $n): string => url('/desiderata') . ($n > 1 ? '?page=' . $n : '') . '#desiderata-results';
// The fallback the browser uses when a cover 404s, as a JS string literal: it
// sits inside an onerror= attribute, where htmlspecialchars() of a raw path
// would be HTML-decoded before the JS parser ever sees it.
$placeholderJson = json_encode(url(DesiderataPlugin::PLACEHOLDER_COVER), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section class="desiderata-section" aria-labelledby="desiderata-heading">
  <div class="container">
    <p class="dw-eyebrow"><?= $e($texts['eyebrow'] ?? __('Cresciamo insieme')) ?></p>
    <<?= $headingTag ?> id="desiderata-heading"><?= $e($texts['title'] ?? __('I libri che cerchiamo')) ?></<?= $headingTag ?>>
    <p><?= $e($texts['intro'] ?? __('Aiutaci ad arricchire la biblioteca. Puoi offrire un libro richiesto oppure proporre un altro titolo.')) ?></p>
    <div class="dw-search" data-desiderata-search>
      <label for="desiderata-search"><?= __('Cerca tra i desiderata') ?></label>
      <input id="desiderata-search" type="search" maxlength="120" class="form-input dw-search-input" placeholder="<?= $e(__('Titolo, autore, editore o ISBN, almeno 3 caratteri')) ?>" aria-describedby="desiderata-search-status" autocomplete="off">
      <p id="desiderata-search-status" class="dw-muted dw-status" role="status"><?= __('Ultimi libri richiesti. Scrivi almeno 3 caratteri per cercare.') ?></p>
      <ul id="desiderata-results" class="dw-results">
        <?php foreach ($books as $b): $href = is_string($b['url'] ?? null) ? $b['url'] : ''; ?>
        <?php // alt="" on purpose: the title sits right next to it, so a screen reader would read the same thing twice.
              // The cover link is hidden from assistive technology and skipped by the keyboard for the same reason:
              // it leads exactly where the title link beside it leads, and two identical stops is one stop too many. ?>
        <li><?php if ($href !== ''): ?><a class="dw-cover-link" href="<?= $e($href) ?>" tabindex="-1" aria-hidden="true"><?php endif; ?><img class="dw-cover" src="<?= $e($b['cover'] ?? url(DesiderataPlugin::PLACEHOLDER_COVER)) ?>" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=<?= $e($placeholderJson) ?>"><?php if ($href !== ''): ?></a><?php endif; ?>
        <div><strong><?php if ($href !== ''): ?><a class="dw-title-link" href="<?= $e($href) ?>"><?= $e($b['titolo']) ?></a><?php else: ?><?= $e($b['titolo']) ?><?php endif; ?></strong><p class="dw-muted"><?= $e(implode(' · ', array_filter([$b['autore'], $b['editore'], $b['isbn13'] ?: $b['isbn10']]))) ?></p></div>
        <button type="button" class="btn btn-outline-primary dw-select" data-book="<?= $e(json_encode($b, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"><?= __('Ce l’ho, posso donarlo') ?></button></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$books): ?><p id="desiderata-empty" class="dw-empty"><?= __('Non ci sono richieste aperte. Puoi comunque proporre un libro da donare.') ?></p><?php endif; ?>
      <?php // Ordinary links, no script involved. The search below hides this block
            // while it is filtering, because a pager over a filtered list would page
            // through the unfiltered one. ?>
      <?php if ($pager !== null && $pager['pages'] > 1): ?>
      <nav class="dw-pager" data-desiderata-pager aria-label="<?= $e(__('Pagine richieste')) ?>">
        <?php if ($pager['page'] > 1): ?><a href="<?= $e($pageUrl($pager['page'] - 1)) ?>" rel="prev"><?= __('Precedente') ?></a><?php endif; ?>
        <span class="dw-muted"><?= $e(__('Pagina %d di %d', $pager['page'], $pager['pages'])) ?></span>
        <?php if ($pager['page'] < $pager['pages']): ?><a href="<?= $e($pageUrl($pager['page'] + 1)) ?>" rel="next"><?= __('Successiva') ?></a><?php endif; ?>
      </nav>
      <?php elseif ($pager === null && $wantedTotal > count($books)): ?>
      <nav class="dw-pager" data-desiderata-pager>
        <a href="<?= $e(url('/desiderata')) ?>"><?= $e(__('Vedi tutti i libri che cerchiamo (%d)', $wantedTotal)) ?></a>
      </nav>
      <?php endif; ?>
    </div>
    <noscript><p><a href="<?= $e(url('/desiderata')) ?>"><?= __('Apri il modulo di donazione') ?></a></p></noscript>
    <?php require __DIR__ . '/partials/offer-form.php'; ?>
  </div>
</section>
<?php require __DIR__ . '/partials/offer-assets.php'; ?>
