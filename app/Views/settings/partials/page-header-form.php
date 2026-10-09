<?php
/**
 * Title and subtitle of a public page header, one pair per language
 * (Settings → CMS). Each field holds exactly the text the page shows: the
 * saved text, or the shipped wording until the field is first saved. An
 * emptied field shows nothing on the page.
 *
 * @var string $phPage        catalog | events
 * @var string $phAction      form action (already url()-ed)
 * @var string $phButton      submit label
 * @var array<string, string> $phLocales  locale => language name
 * @var array<string, array{title: ?string, subtitle: ?string}> $phTexts
 * @var array<string, array{title: string, subtitle: string}> $phDefaults
 * @var string $csrfToken
 */
$phEsc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<form action="<?= $phEsc($phAction) ?>" method="post" class="space-y-5" id="<?= $phEsc($phPage) ?>-header-form">
  <input type="hidden" name="csrf_token" value="<?= $phEsc($csrfToken) ?>">
  <?php foreach ($phLocales as $phLocale => $phLanguage): ?>
    <?php
    $phLocale = (string) $phLocale;
    $phFieldId = $phPage . '_' . preg_replace('/[^A-Za-z0-9_]/', '', $phLocale);
    $phStored = $phTexts[$phLocale] ?? ['title' => null, 'subtitle' => null];
    $phDefault = $phDefaults[$phLocale] ?? ['title' => '', 'subtitle' => ''];
    ?>
    <fieldset class="space-y-2">
      <legend class="block text-sm font-semibold text-gray-900 mb-1"><?= $phEsc($phLanguage) ?></legend>
      <?php foreach (['title' => [__('Titolo'), \App\Support\CatalogHeader::TITLE_MAX], 'subtitle' => [__('Sottotitolo'), \App\Support\CatalogHeader::SUBTITLE_MAX]] as $phField => [$phLabel, $phMax]): ?>
        <div>
          <label for="<?= $phEsc($phFieldId . '_' . $phField) ?>" class="block text-xs font-medium text-gray-600 mb-1"><?= $phEsc($phLabel) ?></label>
          <input
            type="text"
            id="<?= $phEsc($phFieldId . '_' . $phField) ?>"
            name="<?= $phEsc($phPage . '_' . $phField) ?>[<?= $phEsc($phLocale) ?>]"
            value="<?= $phEsc($phStored[$phField] ?? $phDefault[$phField]) ?>"
            maxlength="<?= (int) $phMax ?>"
            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900"
          >
        </div>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
  <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors w-full justify-center">
    <i class="fas fa-save" aria-hidden="true"></i>
    <?= $phEsc($phButton) ?>
  </button>
</form>
