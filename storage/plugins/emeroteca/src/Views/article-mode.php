<?php
/**
 * Emeroteca workflow chooser, the one place the initial workflow is decided.
 *
 * Nothing seeds a mode for a collection that does not exist yet: neither the
 * 0.7.84 migration nor ensureSchema() stamps one, precisely so the choice is
 * the operator's rather than a guess made behind their back. Until they pick,
 * mode() reads 'complete', so nothing is hidden meanwhile.
 *
 * Required by three views (the periodicals list, the articles list and the
 * plugin settings page) so the choice is reachable wherever the operator
 * already is. The markup is the core's: a .card with its header, and the
 * bordered radio rows of the settings page (label formats); main.css is a
 * purged Tailwind build, so only classes the core already uses appear here.
 */
$mode = $mode ?? 'complete';
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
    return;
}
$modeOptions = [
    'simple' => [__('Semplice'), __('Solo articoli: la scheda di un articolo, anche senza la rivista.')],
    'complete' => [__('Completa'), __('Anche annate, fascicoli e abbonamenti delle testate.')],
];
?>
<form method="post" action="<?= $e(url('/admin/periodicals/articles/mode')) ?>" class="card mb-6">
    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
    <input type="hidden" name="return_to" value="<?= $e($modeReturnTo ?? '/admin/periodicals/articles') ?>">
    <div class="card-header">
        <h2 class="form-section-title flex items-center gap-2"><i class="fas fa-sliders-h text-gray-900" aria-hidden="true"></i><?= __('Modalità Emeroteca') ?></h2>
        <p class="text-sm text-gray-600 mt-1"><?= __('Semplice per gli articoli; Completa anche per annate, fascicoli e abbonamenti. I dati restano disponibili.') ?></p>
    </div>
    <div class="card-body">
        <div class="form-grid-2">
            <?php foreach ($modeOptions as $value => [$label, $description]): $isSelected = $mode === $value; ?>
            <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all <?= $isSelected ? 'border-gray-900 bg-gray-100' : 'border-gray-200 hover:border-gray-300 bg-white' ?>">
                <input type="radio" name="mode" value="<?= $e($value) ?>" class="mt-1 w-4 h-4 aspect-square shrink-0 text-gray-900 focus:ring-gray-500" <?= $isSelected ? 'checked' : '' ?>>
                <div class="flex-1">
                    <span class="font-semibold text-gray-900"><?= $e($label) ?></span>
                    <p class="text-sm text-gray-600 mt-1"><?= $e($description) ?></p>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="flex justify-end mt-4">
            <button class="btn-secondary" type="submit"><i class="fas fa-save mr-2"></i><?= __('Salva modalità') ?></button>
        </div>
    </div>
</form>
