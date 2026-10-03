<?php
/**
 * The plugin's card inside /admin/cms/home, printed through the
 * `cms.home.section.fields` action from DesiderataPlugin::cmsFields().
 *
 * The chrome is copied verbatim from the CTA card and the SEO accordion of
 * app/Views/cms/edit-home.php: main.css is a purged Tailwind build made from
 * the core views only, so a class this file invents would simply not exist.
 * The "Visibile" checkbox must keep the name `desiderata[is_active]` — that is
 * how the page's toggle-sync script pairs it with the row in the sortable list.
 *
 * @var array<string,mixed>         $section   the home_content row of this plugin
 * @var array<string,string>        $locales   locale code => language name, current first
 * @var array<string,mixed>         $overrides raw per-locale texts saved by the operator
 * @var array<string,string>        $labels    field key => field label
 * @var array<string,array<string,string>> $defaults locale => shipped texts in that language
 */
$e = [DesiderataPlugin::class, 'e'];
$fieldMax = DesiderataPlugin::TEXT_FIELDS;
$multiline = ['intro' => true, 'form_intro' => true];
$first = true;
?>

<!-- Desiderata Section (plugin) -->
<div class="bg-white rounded-3xl shadow-xl border border-gray-200">
  <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
    <div>
      <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
        <i class="fas fa-hand-holding-heart text-purple-600"></i>
        <?= $e(__('Sezione Desiderata in homepage')) ?>
      </h2>
      <p class="text-sm text-gray-600 mt-1"><?= $e(__('Testi della sezione, una scheda per lingua. Vuoto = testo standard tradotto.')) ?></p>
    </div>
    <div class="flex items-center gap-2">
      <label for="desiderata_visible" class="text-sm font-medium text-gray-700"><?= $e(__('Visibile')) ?></label>
      <input type="checkbox" id="desiderata_visible" name="desiderata[is_active]" value="1"
             <?= !empty($section['is_active']) ? 'checked' : '' ?>
             class="h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-gray-500">
    </div>
  </div>
  <div class="p-6">
    <div class="space-y-3">
      <?php foreach ($locales as $locale => $language): ?>
        <?php
        $locale = (string) $locale;
        $localeOverrides = is_array($overrides[$locale] ?? null) ? $overrides[$locale] : [];
        $localeDefaults = $defaults[$locale] ?? [];
        $open = $first;
        $first = false;
        ?>
        <div class="border border-gray-200 rounded-lg overflow-hidden">
          <button type="button" class="w-full px-4 py-3 flex items-center justify-between bg-gray-50 hover:bg-gray-100 transition-colors"
                  onclick="toggleAccordion('desiderata-<?= $e($locale) ?>')">
            <span class="font-medium text-gray-900 flex items-center gap-2">
              <i class="fas fa-language text-purple-600"></i>
              <?= $e($language) ?>
            </span>
            <i class="fas fa-chevron-down transition-transform<?= $open ? ' rotate-180' : '' ?>" id="desiderata-<?= $e($locale) ?>-icon"></i>
          </button>
          <div id="desiderata-<?= $e($locale) ?>-content" class="<?= $open ? '' : 'hidden ' ?>p-4 space-y-4 bg-white">
            <?php foreach ($labels as $field => $label): ?>
              <div>
                <label for="desiderata_<?= $e($locale) ?>_<?= $e($field) ?>" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= $e($label) ?>
                  <span class="text-xs text-gray-500 font-normal"><?= $e(__('(max %d caratteri)', $fieldMax[$field])) ?></span>
                </label>
                <?php if (isset($multiline[$field])): ?>
                  <textarea id="desiderata_<?= $e($locale) ?>_<?= $e($field) ?>" name="desiderata[texts][<?= $e($locale) ?>][<?= $e($field) ?>]" rows="3" maxlength="<?= (int) $fieldMax[$field] ?>"
                            class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                            placeholder="<?= $e($localeDefaults[$field] ?? '') ?>"><?= $e($localeOverrides[$field] ?? '') ?></textarea>
                <?php else: ?>
                  <input type="text" id="desiderata_<?= $e($locale) ?>_<?= $e($field) ?>" name="desiderata[texts][<?= $e($locale) ?>][<?= $e($field) ?>]" maxlength="<?= (int) $fieldMax[$field] ?>"
                         value="<?= $e($localeOverrides[$field] ?? '') ?>"
                         class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                         placeholder="<?= $e($localeDefaults[$field] ?? '') ?>">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
