<?php
/**
 * The donation form on the public page of a book the library is looking for,
 * printed by DesiderataPlugin::bookDetail() through the core
 * `book.frontend.details` action.
 *
 * Deciding to donate happens while reading about the book, not on a separate
 * list, so this is the same form as the homepage — the very same partial, with
 * the request already selected and a return path back to this page.
 *
 * @var array<string,string> $texts
 * @var array<string,mixed>  $values           book_id and the read-only title
 * @var string               $returnTo         where a successful proposal returns
 * @var string               $recaptchaSiteKey empty = no reCAPTCHA here
 */
$e = [DesiderataPlugin::class, 'e'];
?>
<section class="desiderata-section dw-on-book" id="desiderata-book-offer" aria-labelledby="desiderata-book-heading">
  <div class="container">
    <h2 id="desiderata-book-heading"><?= $e($texts['form_title'] ?? __('Proponi una donazione')) ?></h2>
    <p><?= __('Questo libro è tra quelli che la biblioteca cerca. Se ce l’hai, puoi proporlo in donazione.') ?></p>
    <?php require __DIR__ . '/partials/offer-form.php'; ?>
  </div>
</section>
<?php require __DIR__ . '/partials/offer-assets.php'; ?>
