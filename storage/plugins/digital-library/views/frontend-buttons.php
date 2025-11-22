<?php
/**
 * Digital Library Plugin - Frontend Buttons
 *
 * Renders download buttons for eBooks and audiobooks in the book detail page.
 */

$hasEbook = !empty($book['file_url'] ?? '');
$hasAudiobook = !empty($book['audio_url'] ?? '');

if (!$hasEbook && !$hasAudiobook) {
    return;
}
?>

<?php if ($hasEbook): ?>
<a href="<?= htmlspecialchars($book['file_url'], ENT_QUOTES, 'UTF-8') ?>"
   download
   target="_blank"
   class="btn btn-outline-danger btn-lg"
   title="<?= __("Scarica l'eBook in formato digitale") ?>">
    <i class="fas fa-file-pdf me-2"></i>
    <?= __("Scarica eBook") ?>
</a>
<?php endif; ?>

<?php if ($hasAudiobook): ?>
<button type="button"
        id="btn-toggle-audiobook"
        class="btn btn-outline-success btn-lg"
        title="<?= __("Ascolta l'audiobook") ?>">
    <i class="fas fa-headphones me-2"></i>
    <?= __("Ascolta Audiobook") ?>
</button>
<?php endif; ?>

<?php if ($hasAudiobook): ?>
<style>
/* Additional button styles for Digital Library */
.action-buttons .btn-outline-danger {
    color: #dc2626;
    border-color: #dc2626;
    background: transparent;
}

.action-buttons .btn-outline-danger:hover {
    background: #dc2626;
    border-color: #dc2626;
    color: #ffffff;
}

.action-buttons .btn-outline-success {
    color: var(--success-color) !important;
    border-color: var(--success-color) !important;
    background: transparent;
}

.action-buttons .btn-outline-success:hover {
    background: var(--success-color) !important;
    border-color: var(--success-color) !important;
    color: #ffffff !important;
}
</style>

<script>
// Toggle audiobook player visibility and stop playback if needed
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('btn-toggle-audiobook');
    const playerContainer = document.getElementById('audiobook-player-container');

    if (!toggleBtn || !playerContainer) {
        return;
    }

    const audioEl = playerContainer.querySelector('audio');

    const openContent = '<i class="fas fa-headphones me-2"></i>' + '<?= __("Ascolta Audiobook") ?>';
    const closeContent = '<i class="fas fa-times me-2"></i>' + '<?= __("Chiudi Player") ?>';

    const setButtonState = (isOpen) => {
        toggleBtn.innerHTML = isOpen ? closeContent : openContent;
    };

    const pauseAudioIfPlaying = () => {
        if (audioEl && !audioEl.paused) {
            audioEl.pause();
        }
    };

    toggleBtn.addEventListener('click', function() {
        const isHidden = playerContainer.style.display === 'none' || playerContainer.style.display === '';

        if (isHidden) {
            playerContainer.style.display = 'block';
            setButtonState(true);
        } else {
            playerContainer.style.display = 'none';
            pauseAudioIfPlaying();
            setButtonState(false);
        }
    });

    // Ensure initial state reflects closed player
    playerContainer.style.display = 'none';
    setButtonState(false);
});
</script>
<?php endif; ?>
