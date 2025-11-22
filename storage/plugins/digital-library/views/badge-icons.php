<?php
/**
 * Digital Library Plugin - Badge Icons
 *
 * Renders small icons in status badges to indicate digital content availability.
 */

$hasEbook = !empty($book['file_url'] ?? '');
$hasAudiobook = !empty($book['audio_url'] ?? '');

if (!$hasEbook && !$hasAudiobook) {
    return;
}
?>

<?php if ($hasEbook): ?>
<i class="fas fa-file-pdf ms-1 digital-badge-icon ebook-icon"
   title="<?= __("eBook disponibile") ?>"
   style="font-size: 0.75em; opacity: 0.9; color: #dc2626;"></i>
<?php endif; ?>

<?php if ($hasAudiobook): ?>
<i class="fas fa-headphones ms-1 digital-badge-icon audio-icon"
   title="<?= __("Audiobook disponibile") ?>"
   style="font-size: 0.75em; opacity: 0.9; color: #16a34a;"></i>
<?php endif; ?>
