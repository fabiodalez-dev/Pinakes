<?php
/**
 * "Cite" button and dialog, shared by the book page and the Emeroteca article
 * page (#412).
 *
 * A reader who wants a reference asks for it, and then chooses a style: the
 * page shows one small button, and the dialog lists every style with a copy
 * button, filterable to one. This is the pattern of the Swedish union
 * catalogue (LIBRIS) that a librarian pointed to, and it keeps four long
 * citations out of a page that is about the record.
 *
 * Copying puts the citation on the clipboard twice: as HTML, so a word
 * processor keeps the titles in italics, and as plain text for everything
 * else.
 *
 * Variables the including view sets:
 *   $citeCitations  what App\Support\CitationStyles::all() returns: key,
 *                   label, text and html for each style (html already escaped)
 *   $citeTitle      the record's title, for the dialog heading
 *   $citeDownloads  optional list of ['label' => ..., 'url' => ...] file links
 * They are read defensively: a view that sets none of them renders nothing.
 */
declare(strict_types=1);

if (empty($citeCitations) || !is_array($citeCitations)) {
    return;
}
$citeTitle = trim((string) ($citeTitle ?? ''));
$citeDownloads = is_array($citeDownloads ?? null) ? $citeDownloads : [];
$citeEsc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="cite-block mt-3" id="cite-block">
  <button type="button" class="btn-secondary" id="cite-open" aria-haspopup="dialog" aria-controls="cite-dialog">
    <i class="fas fa-quote-left" aria-hidden="true"></i> <?= $citeEsc(__('Cita')) ?>
  </button>
  <?php if ($citeDownloads !== []): ?>
  <p class="text-sm text-gray-600 mt-3">
    <?php foreach ($citeDownloads as $i => $download): ?><?= $i > 0 ? ' · ' : '' ?><a class="underline" href="<?= $citeEsc((string) $download['url']) ?>"><?= $citeEsc((string) $download['label']) ?></a><?php endforeach; ?>
  </p>
  <?php endif; ?>
</div>

<dialog id="cite-dialog" aria-labelledby="cite-dialog-title">
  <div id="cite-dialog-head">
    <h2 id="cite-dialog-title"><?= $citeEsc($citeTitle !== '' ? __('Cita «%s»', $citeTitle) : __('Cita')) ?></h2>
    <button type="button" id="cite-close" aria-label="<?= $citeEsc(__('Chiudi')) ?>">&times;</button>
  </div>
  <label class="text-sm text-gray-600" for="cite-style"><?= $citeEsc(__('Formato')) ?></label>
  <select id="cite-style">
    <option value=""><?= $citeEsc(__('Tutti i formati')) ?></option>
    <?php foreach ($citeCitations as $citation): ?>
    <option value="<?= $citeEsc($citation['key']) ?>"><?= $citeEsc(__($citation['label'])) ?></option>
    <?php endforeach; ?>
  </select>
  <ul id="cite-list">
    <?php foreach ($citeCitations as $citation): ?>
    <li class="bg-gray-50 rounded p-3 mb-3" data-cite-style="<?= $citeEsc($citation['key']) ?>">
      <p class="font-semibold mb-1"><?= $citeEsc(__($citation['label'])) ?></p>
      <p data-cite-html><?= $citation['html'] ?></p>
      <button type="button" class="btn-secondary mt-3" data-cite-copy data-cite-text="<?= $citeEsc($citation['text']) ?>">
        <i class="far fa-copy" aria-hidden="true"></i> <span data-cite-label><?= $citeEsc(__('Copia negli appunti')) ?></span>
      </button>
    </li>
    <?php endforeach; ?>
  </ul>
  <p id="cite-status" role="status" aria-live="polite"></p>
</dialog>

<style>
  /* The site's reset zeroes every margin, and margin:auto is what centres a
     modal dialog: restore it, and scroll inside rather than off screen. */
  #cite-dialog { margin: auto; overflow: auto; width: min(40rem, calc(100vw - 2rem)); max-height: calc(100vh - 4rem); padding: 1.25rem; border: 0; border-radius: .75rem; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
  #cite-dialog::backdrop { background: rgba(0,0,0,.45); }
  #cite-dialog-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
  #cite-dialog-title { font-size: 1.125rem; font-weight: 700; line-height: 1.4; margin: 0; overflow-wrap: anywhere; }
  #cite-close { font-size: 1.75rem; line-height: 1; background: none; border: 0; cursor: pointer; padding: 0 .25rem; }
  #cite-style { display: block; margin: .25rem 0 1rem; padding: .4rem .6rem; border: 1px solid #d1d5db; border-radius: .5rem; background: #fff; }
  #cite-list { list-style: none; margin: 0; padding: 0; }
  #cite-list [data-cite-html] { overflow-wrap: anywhere; }
  #cite-status { margin: 0; font-size: .875rem; }
  #cite-status:empty { display: none; }
  #cite-status.is-error { color: #b91c1c; }
</style>
<script>
(function () {
  var dialog = document.getElementById('cite-dialog');
  var opener = document.getElementById('cite-open');
  if (!dialog || !opener) { return; }
  var messages = <?= json_encode([
      'copied' => __('Copiato'),
      'failed' => __('Copia non riuscita: seleziona il testo e copialo a mano.'),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  opener.addEventListener('click', function () {
    if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
  });
  document.getElementById('cite-close').addEventListener('click', function () { dialog.close ? dialog.close() : dialog.removeAttribute('open'); });
  // A click on the backdrop lands on the dialog itself, outside its content.
  dialog.addEventListener('click', function (event) {
    if (event.target !== dialog) { return; }
    var box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) { dialog.close(); }
  });
  dialog.addEventListener('close', function () { opener.focus(); if (status) { status.textContent = ''; } });

  document.getElementById('cite-style').addEventListener('change', function () {
    var chosen = this.value;
    dialog.querySelectorAll('[data-cite-style]').forEach(function (item) {
      item.hidden = chosen !== '' && item.getAttribute('data-cite-style') !== chosen;
    });
  });

  // The result is announced in a live region. A success also shows on the
  // button for two seconds; a failure asks the reader to copy by hand, so it
  // stays until the next attempt instead of vanishing before it is read.
  var status = document.getElementById('cite-status');
  var resetTimer = null;
  dialog.querySelectorAll('[data-cite-label]').forEach(function (label) {
    label.setAttribute('data-cite-original', label.textContent);
  });
  function restoreLabels() {
    dialog.querySelectorAll('[data-cite-label]').forEach(function (label) {
      label.textContent = label.getAttribute('data-cite-original');
    });
  }
  function feedback(button, ok) {
    clearTimeout(resetTimer);
    restoreLabels();
    status.textContent = ok ? messages.copied : messages.failed;
    status.classList.toggle('is-error', !ok);
    if (!ok) { return; }
    button.querySelector('[data-cite-label]').textContent = messages.copied;
    resetTimer = setTimeout(function () { restoreLabels(); status.textContent = ''; }, 2000);
  }
  function fallback(text, button) {
    var area = document.createElement('textarea');
    area.value = text; area.style.position = 'fixed'; area.style.opacity = '0';
    dialog.appendChild(area); area.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    dialog.removeChild(area);
    feedback(button, ok);
  }
  dialog.querySelectorAll('[data-cite-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var text = button.getAttribute('data-cite-text') || '';
      var html = button.parentNode.querySelector('[data-cite-html]').innerHTML;
      var clipboard = navigator.clipboard;
      if (clipboard && typeof ClipboardItem !== 'undefined' && clipboard.write) {
        // HTML keeps the italics in a word processor; plain text for the rest.
        clipboard.write([new ClipboardItem({
          'text/html': new Blob([html], { type: 'text/html' }),
          'text/plain': new Blob([text], { type: 'text/plain' })
        })]).then(function () { feedback(button, true); })
          .catch(function () {
            clipboard.writeText(text).then(function () { feedback(button, true); })
              .catch(function () { fallback(text, button); });
          });
      } else if (clipboard && clipboard.writeText) {
        clipboard.writeText(text).then(function () { feedback(button, true); })
          .catch(function () { fallback(text, button); });
      } else {
        fallback(text, button);
      }
    });
  });
})();
</script>
