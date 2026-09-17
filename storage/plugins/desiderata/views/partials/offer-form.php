<?php
/**
 * The donation form, shared by the homepage section, the standalone
 * /desiderata page and (from WP5) the detail page of a wanted book. The markup
 * lives here once so the three placements cannot drift apart; the ids are the
 * ones tests/desiderata.spec.js drives, and only one instance is ever rendered
 * per page.
 *
 * Expected in scope (all optional, each with a working fallback):
 * @var array<string,string>|null $texts            operator texts, see DesiderataPlugin::texts()
 * @var array<string,mixed>|null  $values           what the visitor typed, re-rendered after a 422
 * @var string|null               $error            validation message to show above the fields
 * @var bool|null                 $success          show the "thank you" status
 * @var array<string,mixed>|null  $book             pre-bound wanted book (WP5, book detail page)
 * @var string|null               $returnTo         path to come back to after a successful send (WP5)
 * @var string|null               $recaptchaSiteKey empty = no reCAPTCHA on this installation (WP5)
 */
$e = [DesiderataPlugin::class, 'e'];
$texts = is_array($texts ?? null) ? $texts : [];
$values = is_array($values ?? null) ? $values : [];
$value = static fn(string $key): string => is_scalar($values[$key] ?? '') ? $e($values[$key] ?? '') : '';
// Read AND clear here rather than in the including view. The flag has to be
// consumed by whoever actually renders the form: when only the homepage view
// did it, a proposal sent from a book's page showed no confirmation at all and
// the "thank you" surfaced later, on the donor's next unrelated visit to
// /desiderata. Consuming it in the one file that can display it means a future
// third surface cannot reintroduce that.
$success = !empty($success) || !empty($_SESSION['desiderata_success']);
unset($_SESSION['desiderata_success']);
$returnTo = is_string($returnTo ?? null) ? $returnTo : '';
$recaptchaSiteKey = is_string($recaptchaSiteKey ?? null) ? $recaptchaSiteKey : '';
// A proposal aimed at a specific request names that book, not a title the
// visitor retypes: the field is locked here as well as in the script, so the
// binding survives with JavaScript switched off.
$lockTitle = ($values['book_id'] ?? '') !== '';
?>
<div class="dw-form" id="donation-form" data-desiderata-form>
  <h3><?= $e($texts['form_title'] ?? __('Proponi una donazione')) ?></h3>
  <p><?= $e($texts['form_intro'] ?? __('La biblioteca valuterà la proposta e ti contatterà per concordare la consegna.')) ?></p>
  <?php if ($success): ?><p role="status" class="alert alert-success"><?= __('Grazie! La proposta è stata inviata alla biblioteca.') ?></p><?php endif; ?>
  <?php if (!empty($error)): ?><p role="alert" class="alert alert-danger"><?= $e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $e(url('/desiderata/offers')) ?>" id="desiderata-offer">
    <input type="hidden" name="csrf_token" value="<?= $e(session_status() === PHP_SESSION_ACTIVE ? \App\Support\Csrf::ensureToken() : '') ?>">
    <input type="hidden" name="book_id" id="donation-book-id" value="<?= $value('book_id') ?>">
    <?php // Filled in by grecaptcha and by the caller respectively; inert while neither is configured. ?>
    <input type="hidden" name="recaptcha_token" value="">
    <input type="hidden" name="return_to" value="<?= $e($returnTo) ?>">
    <div hidden aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <p id="donation-match" class="dw-match" role="status"></p>
    <button type="button" id="donation-clear" class="btn btn-outline-primary dw-clear" hidden><?= __('Proponi un altro libro') ?></button>
    <div class="dw-fields">
      <?php foreach (['donor_name' => [__('Il tuo nome'), 150, 'text', true], 'donor_email' => [__('Email per essere contattato'), 254, 'email', true], 'title' => [__('Titolo del libro'), 255, 'text', true], 'author' => [__('Autore'), 255, 'text', false], 'publisher' => [__('Editore'), 255, 'text', false], 'isbn' => [__('ISBN (facoltativo)'), 20, 'text', false]] as $key => [$label, $max, $type, $required]): ?>
      <div><label for="donation-<?= $key ?>"><?= $e($label) ?><?= $required ? ' *' : '' ?></label><input class="form-input" id="donation-<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" maxlength="<?= $max ?>" value="<?= $value($key) ?>" <?= $required ? 'required' : '' ?><?= $key === 'title' && $lockTitle ? ' readonly' : '' ?>></div>
      <?php endforeach; ?>
    </div>
    <label for="donation-notes"><?= __('Condizioni del libro e note (facoltativo)') ?></label>
    <textarea class="form-input" id="donation-notes" name="notes" maxlength="2000" rows="3"><?= $value('notes') ?></textarea>
    <label class="dw-consent"><input type="checkbox" name="consent" value="1" required <?= ($values['consent'] ?? '') === '1' ? 'checked' : '' ?>> <span><?= __('Acconsento a essere contattato dalla biblioteca per questa proposta.') ?> <a href="<?= $e(route_path('privacy')) ?>"><?= __('Informativa privacy') ?></a></span></label>
    <button class="btn btn-primary" type="submit"><?= $e($texts['button'] ?? __('Invia la proposta')) ?></button>
  </form>
</div>
