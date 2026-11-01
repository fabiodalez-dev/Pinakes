<?php
/** @var string $featureKey */
/** @var bool $featureLocked */
$fieldId = 'feature_' . $featureKey;
?>
<div class="flex items-center justify-between gap-4 border-t border-gray-100 pt-4">
  <div>
    <label for="<?= $fieldId ?>" id="<?= $fieldId ?>_label" class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(\App\Support\Features::label($featureKey), ENT_QUOTES, 'UTF-8') ?></label>
    <p id="<?= $fieldId ?>_desc" class="text-xs text-gray-600 mt-1"><?= htmlspecialchars(\App\Support\Features::help($featureKey), ENT_QUOTES, 'UTF-8') ?></p>
  </div>
  <label class="relative inline-flex items-center cursor-pointer shrink-0">
    <?php if (!$featureLocked): ?><input type="hidden" name="<?= $fieldId ?>" value="0"><?php endif; ?>
    <input type="checkbox" id="<?= $fieldId ?>" name="<?= $fieldId ?>" value="1"
           <?= $featureLocked ? 'disabled' : '' ?>
           <?= \App\Support\Features::stored($featureKey) ? 'checked' : '' ?>
           aria-labelledby="<?= $fieldId ?>_label" aria-describedby="<?= $fieldId ?>_desc" class="toggle-checkbox sr-only">
    <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
    <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
  </label>
</div>
