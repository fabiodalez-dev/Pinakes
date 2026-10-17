<?php
/**
 * Digital files of a book, one card each: the file type, its name and kind,
 * then what can be done with it. An audiobook plays in its card; a PDF opens
 * in a fullscreen lightbox ("Leggi PDF"); every file can be downloaded.
 * A file the reader may not open yet keeps its card, without a link, and says
 * why: sign in, or borrow the book (DigitalAccess).
 *
 * @var list<array{url:string,label:string,kind:string,access:string}> $attachments
 */
$attachmentKinds = ['ebook' => __('Edizione digitale'), 'supplement' => __('Recensione o documento correlato'), 'audio' => __('Audiobook')];
?>
<section aria-label="<?= \App\Support\HtmlHelper::e(__('Contenuti Digitali')) ?>" class="digital-attachments">
<ul class="digital-attachments-list">
<?php foreach ($attachments as $attachment):
    $locked = $attachment['access'] !== \App\Support\DigitalAccess::GRANTED;
    $href = \App\Support\HtmlHelper::e(url($attachment['url']));
    $label = \App\Support\HtmlHelper::e($attachment['label']);
    $extension = strtolower(pathinfo((string) parse_url($attachment['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
    $isAudio = $attachment['kind'] === 'audio';
    // Unique per attachment, also when the list is rendered more than once on a page.
    $GLOBALS['digitalPdfViewers'] = (int) ($GLOBALS['digitalPdfViewers'] ?? 0) + 1;
    $viewerId = 'digital-pdf-viewer-' . $GLOBALS['digitalPdfViewers'];
?>
<li class="digital-attachment digital-attachment--<?= \App\Support\HtmlHelper::e($attachment['kind']) ?><?= $locked ? ' digital-attachment--locked' : '' ?>">
    <div class="digital-attachment__head">
        <span class="digital-attachment__type<?= $isAudio ? ' digital-attachment__type--round' : '' ?>" aria-hidden="true"><?= \App\Support\HtmlHelper::e(strtoupper($extension !== '' ? $extension : ($isAudio ? 'audio' : 'file'))) ?></span>
        <p class="digital-attachment__text"><strong title="<?= $label ?>"><?= $label ?></strong> <span class="digital-attachment__sep" aria-hidden="true">·</span> <span class="digital-attachment__kind"><?= \App\Support\HtmlHelper::e($attachmentKinds[$attachment['kind']]) ?></span></p>
        <div class="digital-attachment__actions">
            <?php if ($locked): ?>
                <?php if ($attachment['access'] === \App\Support\DigitalAccess::LOGIN_REQUIRED): ?>
                <?php $loginHref = \App\Support\HtmlHelper::e(url(\App\Support\RouteTranslator::route('login') . '?return_url=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/')))); ?>
                <a class="ui-button btn-outline digital-attachment__login" href="<?= $loginHref ?>"><i class="fas fa-lock" aria-hidden="true"></i><?= __('Accedi per aprirlo') ?><span class="sr-only">: <?= $label ?></span></a>
                <?php else: ?>
                <span class="digital-attachment__locked"><i class="fas fa-lock" aria-hidden="true"></i><?= __('Per chi ha il libro in prestito') ?></span>
                <?php endif; ?>
            <?php else: ?>
            <?php if (!$isAudio && $extension === 'pdf'): ?>
            <?php // An ordinary PDF link without JavaScript; enhanced into a fullscreen reader. ?>
            <a class="ui-button digital-attachment__read" href="<?= $href ?>" target="_blank" rel="noopener" aria-controls="<?= $viewerId ?>" data-digital-pdf-toggle><?= __('Leggi PDF') ?><span class="sr-only">: <?= $label ?></span></a>
            <?php endif; ?>
            <a class="ui-button btn-outline digital-attachment__download" href="<?= $href ?>" download target="_blank" rel="noopener noreferrer"><i class="fas fa-download" aria-hidden="true"></i><?= __('Scarica') ?><span class="sr-only">: <?= $label ?></span></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($locked): ?>
    <?php elseif ($isAudio): ?>
    <audio controls preload="none" aria-label="<?= $label ?>"><source src="<?= $href ?>"><?= __('Il tuo browser non supporta la riproduzione audio.') ?></audio>
    <?php elseif ($extension === 'pdf'): ?>
    <?php
    $pdfHref = url($attachment['url']);
    $pdfLabel = $attachment['label'];
    include __DIR__ . '/frontend-pdf-lightbox.php';
    ?>
    <?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</section>

<script>
(function () {
    // The listeners are document-wide: bound once, however many times the list is rendered.
    if (window.digitalAttachmentsBound) return;
    window.digitalAttachmentsBound = true;
    document.addEventListener('play', function (event) {
        if (!event.target.matches('.digital-attachments audio')) return;
        document.querySelectorAll('.digital-attachments audio').forEach(function (audio) {
            if (audio !== event.target) audio.pause();
        });
    }, true);
})();
</script>
<?php include __DIR__ . '/frontend-pdf-script.php'; ?>
