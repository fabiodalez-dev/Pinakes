<?php
/**
 * Emeroteca — public 404 (testata or fascicolo not found), rendered
 * inside the frontend layout by PublicController::renderNotFound().
 */
declare(strict_types=1);
?>
<link rel="stylesheet" href="<?= htmlspecialchars(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.2.3'), ENT_QUOTES, 'UTF-8') ?>">
<main class="container emeroteca-public">
    <div class="emeroteca-notice" role="alert">
        <strong><?= __('Contenuto non trovato') ?></strong>
        — <?= __('La testata o il fascicolo richiesto non esiste o non è più disponibile.') ?>
        <a href="<?= htmlspecialchars(url('/emeroteca'), ENT_QUOTES, 'UTF-8') ?>" class="font-semibold underline underline-offset-2">
            <?= __("Torna all'emeroteca") ?>
        </a>
    </div>
</main>
