<?php
/**
 * "Cite this book" inline (2026 design): one tab per citation style, the
 * selected citation in a box, then Copy and Download RIS. Same input as the
 * Cite dialog (partials/cite-dialog.php): $citeCitations and $citeDownloads.
 *
 * @var list<array{key:string,label:string,text:string,html:string}> $citeCitations
 * @var list<array{label:string,url:string}> $citeDownloads
 */
declare(strict_types=1);

if (empty($citeCitations)) {
    return;
}
$pkCiteEsc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$pkCiteRis = '';
foreach ($citeDownloads as $pkDownload) {
    if (str_contains($pkDownload['url'], '.ris')) {
        $pkCiteRis = $pkDownload['url'];
        break;
    }
}
?>
<div class="pk-citebox" data-pk-cite>
    <div class="pk-citebox__tabs" role="tablist" aria-label="<?= $pkCiteEsc(__('Formato')) ?>">
        <?php foreach ($citeCitations as $pkI => $pkCitation): ?>
        <button type="button" role="tab" class="pk-citebox__tab<?= $pkI === 0 ? ' is-active' : '' ?>" aria-selected="<?= $pkI === 0 ? 'true' : 'false' ?>" data-pk-cite-tab="<?= $pkCiteEsc((string) $pkCitation['key']) ?>"><?= $pkCiteEsc(__((string) $pkCitation['label'])) ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($citeCitations as $pkI => $pkCitation): ?>
    <div class="pk-citebox__text" role="tabpanel" data-pk-cite-panel="<?= $pkCiteEsc((string) $pkCitation['key']) ?>" data-pk-cite-text="<?= $pkCiteEsc((string) $pkCitation['text']) ?>"<?= $pkI === 0 ? '' : ' hidden' ?>><?= $pkCitation['html'] ?></div>
    <?php endforeach; ?>
    <div class="pk-citebox__actions">
        <button type="button" class="pk-btn pk-btn--dark pk-btn--sm" data-pk-cite-copy data-pk-cite-done="<?= $pkCiteEsc(__('Copiato')) ?>"><i class="far fa-copy" aria-hidden="true"></i> <span><?= $pkCiteEsc(__('Copia negli appunti')) ?></span></button>
        <?php if ($pkCiteRis !== ''): ?>
        <a class="pk-btn pk-btn--ghost pk-btn--sm" href="<?= $pkCiteEsc($pkCiteRis) ?>"><i class="fas fa-download" aria-hidden="true"></i> <?= $pkCiteEsc(__('Scarica RIS')) ?></a>
        <?php endif; ?>
        <?php // The caller's "Cite" dialog (all styles, partials/cite-dialog.php), when given. ?>
        <?= $pkCiteDialog ?? '' ?>
    </div>
</div>
