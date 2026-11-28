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
 * @var array<string,mixed>  $values           book_id, the read-only title, and the
 *                                             author/publisher/ISBN the catalogue
 *                                             already knows (all three editable)
 * @var string               $returnTo         where a successful proposal returns
 * @var string               $recaptchaSiteKey empty = no reCAPTCHA here
 */
$e = [DesiderataPlugin::class, 'e'];
?>
<?php // No .container: the section already sits in the book page's text column, and the
      // theme's container padding would push it off the description above it. ?>
<section class="desiderata-section dw-on-book" id="desiderata-book-offer" aria-labelledby="desiderata-book-heading">
  <h2 id="desiderata-book-heading"><?= $e($texts['form_title'] ?? __('Proponi una donazione')) ?></h2>
  <p><?= __('Questo libro è tra quelli che la biblioteca cerca. Se ce l’hai, puoi proporlo in donazione.') ?></p>
  <?php $omitFormTitle = true; // the section heading above already says it
  require __DIR__ . '/partials/offer-form.php'; ?>
</section>
<?php require __DIR__ . '/partials/offer-assets.php'; ?>
