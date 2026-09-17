<?php
/**
 * Rendered by DesiderataPlugin::bookField() inside the book form, immediately
 * above the copies field (core fires `book.form.before_copies` there).
 *
 * The two modes are genuinely different, and pretending otherwise is what made
 * this confusing:
 *
 * - CREATE: core honours the submitted `copie_totali`, so the checkbox can pin
 *   that field to zero and hand it back when it is cleared.
 * - EDIT: core deliberately IGNORES the submitted `copie_totali` and derives it
 *   from the `copie` table (LibriController::update, "Ignore any submitted
 *   copie_totali"), so the field is read-only by design. Unlocking it would
 *   produce a control that accepts a number, saves, and changes nothing.
 *   Clearing the checkbox there means "the book arrived": the plugin takes the
 *   count from its OWN field below and registers real copies on `book.save.after`.
 *
 * @var array<string,mixed>|null $book
 * @var int|null $id
 * @var bool $hasCopies
 */
$e = [DesiderataPlugin::class, 'e'];
$isEdit = $id !== null;
$isWanted = !empty($book['is_desiderata']) && !$hasCopies;
// The receipt field only makes sense for a record that IS an open request: a
// book with copies is already in the catalogue and has nothing to receive.
$canReceive = $isEdit && $isWanted;
$helpWanted = __('Il libro appare tra quelli richiesti dalla biblioteca. Le copie iniziali sono bloccate a zero. Alla registrazione della prima copia fisica diventerà automaticamente un libro del catalogo.');
// Three different situations, and one text for all three was the confusion:
// closing a request, editing a record whose copies live elsewhere, and filling
// in the initial copies of a book being created.
if ($canReceive) {
    $helpCleared = __('Togliendo la spunta stai registrando l’arrivo del libro: al salvataggio entrano in inventario le copie fisiche indicate qui sotto e il libro passa nel catalogo.');
} elseif ($isEdit) {
    $helpCleared = __('Il libro fa parte del catalogo: le copie fisiche si aggiungono dalla sezione Copie Fisiche della scheda libro.');
} else {
    $helpCleared = __('Il libro entra in catalogo con il numero di copie fisiche indicato qui sotto.');
}
?>
<div class="mb-4">
  <input type="hidden" name="desiderata_form" value="1">
  <label for="is_desiderata" class="flex items-center gap-2 font-medium">
    <input id="is_desiderata" name="is_desiderata" type="checkbox" value="1" <?= $isWanted ? 'checked' : '' ?> <?= $hasCopies ? 'disabled' : '' ?> aria-describedby="desiderata-help">
    <?= __('Desiderata: cerchiamo questo libro') ?>
  </label>
  <p id="desiderata-help" class="text-sm text-gray-600 mt-1"><?= $hasCopies ? __('Sono già presenti copie fisiche: questo libro fa parte del catalogo.') : ($isWanted ? $e($helpWanted) : $e($helpCleared)) ?></p>
  <?php if ($canReceive): ?>
  <?php // Hidden while the box is ticked: a request owns nothing, so there is
        // no number to show. `hidden` (not display:none in a class) because the
        // core layout already enforces [hidden] and nothing here is purgeable. ?>
  <?php // Always starts hidden: $canReceive implies the box is ticked right now. ?>
  <div id="desiderata-receive" class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-4" hidden>
    <label for="desiderata_copies" class="form-label"><?= __('Copie fisiche da registrare') ?></label>
    <input id="desiderata_copies" name="desiderata_copies" type="number" class="form-input" value="1" min="1" max="50" step="1">
    <p class="text-xs text-gray-600 mt-1">
      <i class="fas fa-info-circle text-blue-500 mr-1"></i>
      <?= __('Salvando, queste copie entrano davvero in inventario con un numero di inventario assegnato. La ricezione viene registrata anche fra le donazioni.') ?>
    </p>
  </div>
  <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const flag = document.getElementById('is_desiderata');
  if (!flag) return;
  const help = document.getElementById('desiderata-help');
  const receive = document.getElementById('desiderata-receive');
  const copies = document.getElementById('copie_totali');
  const texts = <?= json_encode(['wanted' => $helpWanted, 'cleared' => $helpCleared], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  // Only meaningful on the create form: there the core accepts the number. On
  // the edit form the field is readonly by design and is left alone — see the
  // note at the top of this file.
  const editable = copies && !copies.readOnly;
  const originalDisabled = editable ? copies.disabled : false;
  let previous = editable ? copies.value : '';
  const sync = () => {
    if (help) help.textContent = flag.checked ? texts.wanted : texts.cleared;
    if (receive) receive.hidden = flag.checked;
    if (!editable) return;
    if (flag.checked) {
      if (!copies.disabled) previous = copies.value;
      copies.value = '0'; copies.disabled = true;
    } else {
      copies.disabled = originalDisabled; copies.value = previous;
    }
  };
  flag.addEventListener('change', sync); sync();

  // The core save dialog only asks "update the book X?", which says nothing
  // about the one consequence here that cannot be undone from the same screen:
  // clearing this box turns the request into a holding and writes real copies
  // into the inventory, each with an allocated inventory number. The receipt
  // actions in the desiderata admin already warn before acting; this makes the
  // book form say the same thing at the same moment, through the extension
  // point the core form exposes rather than by teaching the core form what a
  // desiderata is.
  const notices = <?= json_encode([
      'received' => __('Togliendo la spunta Desiderata viene creata una copia fisica in inventario e la richiesta si chiude.'),
      'receivedMany' => __('Togliendo la spunta Desiderata vengono create %s copie fisiche in inventario e la richiesta si chiude.'),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  window.bookFormConfirmNotes = window.bookFormConfirmNotes || [];
  window.bookFormConfirmNotes.push(() => {
    // Only when the operator is actually closing a request: the box started
    // ticked and is now clear. A disabled box is a book that already has
    // copies, where nothing is created.
    if (!receive || flag.disabled || flag.checked) return '';
    const wanted = document.getElementById('desiderata_copies');
    const count = Math.max(1, parseInt(wanted && wanted.value, 10) || 1);
    return count === 1 ? notices.received : notices.receivedMany.replace('%s', String(count));
  });
});
</script>
