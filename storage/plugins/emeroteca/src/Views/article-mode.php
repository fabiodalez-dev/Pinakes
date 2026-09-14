<?php
/**
 * Emeroteca workflow chooser — the one place the initial workflow is decided.
 *
 * Nothing seeds a mode for a collection that does not exist yet: neither the
 * 0.7.84 migration nor ensureSchema() stamps one, precisely so the choice is
 * the operator's rather than a guess made behind their back. Until they pick,
 * mode() reads 'complete', so nothing is hidden meanwhile.
 *
 * Required by three views — the periodicals list, the articles list and the
 * plugin settings page — so the choice is reachable wherever the operator
 * already is. Every class here already exists in emeroteca.css or main.css
 * (emt-toolbar, emt-choice-list, emt-choice, form-radio, btn-secondary): the
 * stylesheet is a purged Tailwind build and an invented class would be inert.
 * The emt-* rules are scoped under .emeroteca-admin — a view that requires
 * this partial must provide that wrapper and link the plugin stylesheet.
 */
$mode = $mode ?? 'complete';
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
    return;
}
?>
<form method="post" action="<?= $e(url('/admin/periodicals/articles/mode')) ?>" class="emt-toolbar p-4 mb-6">
    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
    <input type="hidden" name="return_to" value="<?= $e($modeReturnTo ?? '/admin/periodicals/articles') ?>">
    <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3"><?= __('Modalità Emeroteca') ?></h2>
    <p class="text-sm text-gray-600"><?= __('Semplice per gli articoli; Completa anche per annate, fascicoli e abbonamenti. I dati restano disponibili.') ?></p>
    <div class="emt-choice-list mt-4">
        <label class="emt-choice">
            <input type="radio" name="mode" value="simple" class="form-radio" <?= $mode === 'simple' ? 'checked' : '' ?>>
            <span><strong><?= __('Semplice') ?></strong></span>
        </label>
        <label class="emt-choice">
            <input type="radio" name="mode" value="complete" class="form-radio" <?= $mode === 'complete' ? 'checked' : '' ?>>
            <span><strong><?= __('Completa') ?></strong></span>
        </label>
    </div>
    <button class="btn-secondary mt-4" type="submit"><?= __('Salva modalità') ?></button>
</form>
