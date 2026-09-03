<?php
/**
 * Emeroteca — public 404 (testata or fascicolo not found), rendered
 * inside the frontend layout by PublicController::renderNotFound().
 */
declare(strict_types=1);
?>
<main class="container py-4">
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
        <strong><?= __('Contenuto non trovato') ?></strong>
        — <?= __('La testata o il fascicolo richiesto non esiste o non è più disponibile.') ?>
        <a href="<?= htmlspecialchars(url('/emeroteca'), ENT_QUOTES, 'UTF-8') ?>" class="font-semibold underline underline-offset-2">
            <?= __("Torna all'emeroteca") ?>
        </a>
    </div>
</main>
