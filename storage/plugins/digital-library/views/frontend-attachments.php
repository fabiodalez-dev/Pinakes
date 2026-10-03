<?php
/** @var list<array{url:string,label:string,kind:string}> $attachments */
$attachmentKinds = ['ebook' => __('Edizione digitale'), 'supplement' => __('Recensione o documento correlato'), 'audio' => __('Audiobook')];
?>
<section aria-label="<?= \App\Support\HtmlHelper::e(__('Contenuti Digitali')) ?>" class="digital-attachments">
<ul class="digital-attachments-list">
<?php foreach ($attachments as $attachment):
    $href = \App\Support\HtmlHelper::e(url($attachment['url']));
    $label = \App\Support\HtmlHelper::e($attachment['label']);
    $extension = strtolower(pathinfo((string) parse_url($attachment['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
?>
<li>
    <p><strong><?= $label ?></strong> · <?= \App\Support\HtmlHelper::e($attachmentKinds[$attachment['kind']]) ?></p>
    <a class="ui-button btn-outline" href="<?= $href ?>" download target="_blank" rel="noopener noreferrer"><?= __('Scarica') ?>: <?= $label ?></a>
    <?php if ($attachment['kind'] === 'audio'): ?>
    <audio controls preload="none" aria-label="<?= $label ?>"><source src="<?= $href ?>"><?= __('Il tuo browser non supporta la riproduzione audio.') ?></audio>
    <?php elseif ($extension === 'pdf'): ?>
    <details><summary><?= __('Leggi PDF') ?>: <?= $label ?></summary><iframe src="<?= $href ?>" title="<?= $label ?>" loading="lazy" sandbox="allow-same-origin allow-downloads"></iframe></details>
    <?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</section>

<script>
document.addEventListener('play', function (event) {
    if (!event.target.matches('.digital-attachments audio')) return;
    document.querySelectorAll('.digital-attachments audio').forEach(function (audio) {
        if (audio !== event.target) audio.pause();
    });
}, true);
</script>
