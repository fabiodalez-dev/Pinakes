<?php $mode=$mode??'complete'; $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<?php if (($_SESSION['user']['tipo_utente']??'')==='admin'): ?>
<form method="post" action="<?= $e(url('/admin/periodicals/articles/mode')) ?>" class="flex flex-wrap items-end gap-3 mb-6">
<input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
<div><label for="emeroteca-mode" class="form-label"><?= __('Modalità Emeroteca') ?></label>
<select id="emeroteca-mode" name="mode" class="form-input"><option value="simple" <?= $mode==='simple'?'selected':'' ?>><?= __('Semplice') ?></option><option value="complete" <?= $mode==='complete'?'selected':'' ?>><?= __('Completa') ?></option></select></div>
<button class="btn-secondary" type="submit"><?= __('Salva modalità') ?></button>
<p class="text-sm text-gray-600"><?= __('Semplice per gli articoli; Completa anche per annate, fascicoli e abbonamenti. I dati restano disponibili.') ?></p>
</form>
<?php endif; ?>
