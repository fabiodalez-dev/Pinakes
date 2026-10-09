<?php
/**
 * Digital files of a book, one card each: the file type, its name and kind,
 * then what can be done with it. An audiobook plays in its card; a PDF opens
 * below its card ("Leggi PDF"); every file can be downloaded.
 *
 * @var list<array{url:string,label:string,kind:string}> $attachments
 */
$attachmentKinds = ['ebook' => __('Edizione digitale'), 'supplement' => __('Recensione o documento correlato'), 'audio' => __('Audiobook')];
?>
<section aria-label="<?= \App\Support\HtmlHelper::e(__('Contenuti Digitali')) ?>" class="digital-attachments">
<ul class="digital-attachments-list">
<?php foreach ($attachments as $attachment):
    $href = \App\Support\HtmlHelper::e(url($attachment['url']));
    $label = \App\Support\HtmlHelper::e($attachment['label']);
    $extension = strtolower(pathinfo((string) parse_url($attachment['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
    $isAudio = $attachment['kind'] === 'audio';
    // Unique per attachment, also when the list is rendered more than once on a page.
    $GLOBALS['digitalPdfViewers'] = (int) ($GLOBALS['digitalPdfViewers'] ?? 0) + 1;
    $viewerId = 'digital-pdf-viewer-' . $GLOBALS['digitalPdfViewers'];
?>
<li class="digital-attachment digital-attachment--<?= \App\Support\HtmlHelper::e($attachment['kind']) ?>">
    <div class="digital-attachment__head">
        <span class="digital-attachment__type<?= $isAudio ? ' digital-attachment__type--round' : '' ?>" aria-hidden="true"><?= \App\Support\HtmlHelper::e(strtoupper($extension !== '' ? $extension : ($isAudio ? 'audio' : 'file'))) ?></span>
        <p class="digital-attachment__text"><strong title="<?= $label ?>"><?= $label ?></strong> <span class="digital-attachment__sep" aria-hidden="true">·</span> <span class="digital-attachment__kind"><?= \App\Support\HtmlHelper::e($attachmentKinds[$attachment['kind']]) ?></span></p>
        <div class="digital-attachment__actions">
            <?php if (!$isAudio && $extension === 'pdf'): ?>
            <?php // A link to the PDF without JavaScript; the script below turns it into the toggle of the viewer under the card. ?>
            <a class="ui-button digital-attachment__read" href="<?= $href ?>" target="_blank" rel="noopener" aria-controls="<?= $viewerId ?>" data-digital-pdf-toggle><?= __('Leggi PDF') ?><span class="sr-only">: <?= $label ?></span></a>
            <?php endif; ?>
            <a class="ui-button btn-outline digital-attachment__download" href="<?= $href ?>" download target="_blank" rel="noopener noreferrer"><i class="fas fa-download" aria-hidden="true"></i><?= __('Scarica') ?><span class="sr-only">: <?= $label ?></span></a>
        </div>
    </div>
    <?php if ($isAudio): ?>
    <audio controls preload="none" aria-label="<?= $label ?>"><source src="<?= $href ?>"><?= __('Il tuo browser non supporta la riproduzione audio.') ?></audio>
    <?php elseif ($extension === 'pdf'): ?>
    <div class="digital-attachment__viewer" id="<?= $viewerId ?>" hidden><iframe data-src="<?= $href ?>" title="<?= $label ?>" loading="lazy"></iframe></div>
    <?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</section>

<script>
(function () {
    // Enhance the "Leggi PDF" links of this list (and of any rendered earlier) into toggles.
    document.querySelectorAll('[data-digital-pdf-toggle]:not([role])').forEach(function (toggle) {
        toggle.setAttribute('role', 'button');
        toggle.setAttribute('aria-expanded', 'false');
    });
    // The listeners are document-wide: bound once, however many times the list is rendered.
    if (window.digitalAttachmentsBound) return;
    window.digitalAttachmentsBound = true;
    document.addEventListener('play', function (event) {
        if (!event.target.matches('.digital-attachments audio')) return;
        document.querySelectorAll('.digital-attachments audio').forEach(function (audio) {
            if (audio !== event.target) audio.pause();
        });
    }, true);
    // "Leggi PDF" opens the file under its card; the frame loads on first open.
    function toggleViewer(toggle, event) {
        var viewer = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!viewer) return; // no viewer: the link opens the PDF in a new tab
        event.preventDefault();
        var frame = viewer.querySelector('iframe');
        if (frame && !frame.getAttribute('src')) frame.setAttribute('src', frame.getAttribute('data-src'));
        viewer.hidden = !viewer.hidden;
        toggle.setAttribute('aria-expanded', viewer.hidden ? 'false' : 'true');
    }
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest && event.target.closest('[data-digital-pdf-toggle]');
        if (toggle) toggleViewer(toggle, event);
    });
    // A link acting as a button also answers to Space.
    document.addEventListener('keydown', function (event) {
        if (event.key !== ' ' && event.key !== 'Spacebar') return;
        var toggle = event.target.closest && event.target.closest('[data-digital-pdf-toggle]');
        if (toggle) toggleViewer(toggle, event);
    });
})();
</script>
