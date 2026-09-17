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
 */
$e = [DesiderataPlugin::class, 'e'];
$headingTag = !empty($standalone) ? 'h1' : 'h2';
$texts = is_array($texts ?? null) ? $texts : [];
$values = $values ?? [];
$value = static fn(string $key): string => is_scalar($values[$key] ?? '') ? $e($values[$key] ?? '') : '';
$success = !empty($_SESSION['desiderata_success']); unset($_SESSION['desiderata_success']);
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
        <?php foreach ($books as $b): ?>
        <?php // alt="" on purpose: the title sits right next to it, so a screen reader would read the same thing twice. ?>
        <li><img class="dw-cover" src="<?= $e($b['cover'] ?? url(DesiderataPlugin::PLACEHOLDER_COVER)) ?>" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=<?= $e($placeholderJson) ?>">
        <div><strong><?= $e($b['titolo']) ?></strong><p class="dw-muted"><?= $e(implode(' · ', array_filter([$b['autore'], $b['editore'], $b['isbn13'] ?: $b['isbn10']]))) ?></p></div>
        <button type="button" class="btn btn-outline-primary dw-select" data-book="<?= $e(json_encode($b, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"><?= __('Ce l’ho, posso donarlo') ?></button></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$books): ?><p id="desiderata-empty" class="dw-empty"><?= __('Non ci sono richieste aperte. Puoi comunque proporre un libro da donare.') ?></p><?php endif; ?>
    </div>
    <noscript><p><a href="<?= $e(url('/desiderata')) ?>"><?= __('Apri il modulo di donazione') ?></a></p></noscript>
    <?php require __DIR__ . '/partials/offer-form.php'; ?>
  </div>
</section>
<?php require __DIR__ . '/partials/offer-assets.php'; ?>
