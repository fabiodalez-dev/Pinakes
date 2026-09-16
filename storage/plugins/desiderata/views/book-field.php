<?php
/**
 * Rendered by DesiderataPlugin::bookField() inside the book form.
 *
 * @var array<string,mixed>|null $book
 * @var bool $hasCopies
 */
?>
<div class="mb-4">
  <input type="hidden" name="desiderata_form" value="1">
  <label for="is_desiderata" class="flex items-center gap-2 font-medium">
    <input id="is_desiderata" name="is_desiderata" type="checkbox" value="1" <?= !empty($book['is_desiderata']) && !$hasCopies ? 'checked' : '' ?> <?= $hasCopies ? 'disabled' : '' ?> aria-describedby="desiderata-help">
    <?= __('Desiderata: cerchiamo questo libro') ?>
  </label>
  <p id="desiderata-help" class="text-sm text-gray-600 mt-1"><?= $hasCopies ? __('Sono già presenti copie fisiche: questo libro fa parte del catalogo.') : __('Il libro appare tra quelli richiesti dalla biblioteca. Le copie iniziali sono bloccate a zero. Alla registrazione della prima copia fisica diventerà automaticamente un libro del catalogo.') ?></p>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const flag = document.getElementById('is_desiderata');
  const copies = document.getElementById('copie_totali');
  if (!flag || !copies) return;
  const originalDisabled = copies.disabled;
  let previous = copies.value;
  const sync = () => {
    if (flag.checked) {
      if (!copies.disabled) previous = copies.value;
      copies.value = '0'; copies.disabled = true;
    } else {
      copies.disabled = originalDisabled; copies.value = previous;
    }
  };
  flag.addEventListener('change', sync); sync();
});
</script>
