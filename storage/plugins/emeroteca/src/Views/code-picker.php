<?php
/**
 * One coded field (language, country, currency) as a searchable picker (#412).
 *
 * The <select data-code-picker> lists "Name (code)" in the operator's language
 * and posts the code; assets/js/emeroteca-code-picker.js turns it into the
 * Choices.js search box of the author picker. A stored code outside the list
 * (a two-letter "it" from before the pickers) stays selectable, so opening and
 * saving a record never changes it behind the operator's back. Without intl
 * there are no names to list, and the field falls back to a text box.
 *
 * Variables the including view sets:
 *   $pickerId, $pickerName, $pickerLabel  the field
 *   $pickerKind     'language' | 'country' | 'currency'
 *   $pickerCurrent  the stored code
 *   $pickerHint     optional hint under the picker
 *   $pickerCodeHint hint under the text-box fallback (the code format)
 *   $pickerMax      maxlength of the text-box fallback
 *   $pickerError    optional validation message
 *   $pickerEmpty    true to offer "Not specified" (default true)
 */
declare(strict_types=1);

// Views are rendered by several controllers; the picker loads its own list.
require_once dirname(__DIR__) . '/Support/CodeLists.php';

use App\Plugins\Emeroteca\Support\CodeLists;

$pickerEsc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pickerLocale = \App\Support\I18n::getLocale();
$pickerId = (string) ($pickerId ?? '');
$pickerName = (string) ($pickerName ?? '');
$pickerLabel = (string) ($pickerLabel ?? '');
$pickerKind = (string) ($pickerKind ?? 'language');
$pickerNames = match ($pickerKind) {
    'country' => CodeLists::countries($pickerLocale),
    'currency' => CodeLists::currencies($pickerLocale),
    default => CodeLists::languages($pickerLocale),
};
$pickerCurrent = trim((string) ($pickerCurrent ?? ''));
$pickerError = (string) ($pickerError ?? '');
$pickerHint = (string) ($pickerHint ?? '');
$pickerErrorClass = $pickerError !== '' ? ' border-red-500' : '';
?>
<div>
  <label for="<?= $pickerEsc($pickerId) ?>" class="form-label"><?= $pickerEsc($pickerLabel) ?></label>
  <?php if ($pickerNames === []): ?>
  <input class="form-input<?= $pickerErrorClass ?>" id="<?= $pickerEsc($pickerId) ?>" name="<?= $pickerEsc($pickerName) ?>" value="<?= $pickerEsc($pickerCurrent) ?>" maxlength="<?= (int) ($pickerMax ?? 10) ?>">
  <p class="text-xs text-gray-500 mt-1"><?= $pickerEsc($pickerCodeHint ?? '') ?></p>
  <?php else: ?>
  <select class="form-input<?= $pickerErrorClass ?>" id="<?= $pickerEsc($pickerId) ?>" name="<?= $pickerEsc($pickerName) ?>" data-code-picker data-search-placeholder="<?= $pickerEsc(__('Cerca per nome o codice')) ?>" data-no-results="<?= $pickerEsc(__('Nessun risultato')) ?>">
    <?php if ($pickerEmpty ?? true): ?><option value=""><?= __('Non specificato') ?></option><?php endif; ?>
    <?php if ($pickerCurrent !== '' && !isset($pickerNames[$pickerCurrent])): ?><option value="<?= $pickerEsc($pickerCurrent) ?>" selected><?= $pickerEsc($pickerKind === 'currency' ? $pickerCurrent : CodeLists::name($pickerCurrent, $pickerLocale, $pickerKind === 'country')) ?> (<?= $pickerEsc($pickerCurrent) ?>)</option><?php endif; ?>
    <?php foreach ($pickerNames as $pickerCode => $pickerLabelText): ?><option value="<?= $pickerEsc($pickerCode) ?>"<?= $pickerCurrent === (string) $pickerCode ? ' selected' : '' ?>><?= $pickerEsc($pickerLabelText) ?> (<?= $pickerEsc($pickerCode) ?>)</option><?php endforeach; ?>
  </select>
  <?php if ($pickerHint !== ''): ?><p class="text-xs text-gray-500 mt-1"><?= $pickerEsc($pickerHint) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php if ($pickerError !== ''): ?><p class="mt-1 text-xs text-red-600"><?= $pickerEsc($pickerError) ?></p><?php endif; ?>
</div>
