<?php
/**
 * Digital Library Plugin - Badge Icons
 *
 * Renders small icons in status badges to indicate digital content availability.
 */

$book = $book ?? [];
$attachmentKinds = array_column(\App\Support\DigitalAttachments::fromBook($book), 'kind');
// A review or related article is not the digital edition: only an ebook earns the badge.
$hasEbook = in_array('ebook', $attachmentKinds, true);
$hasAudiobook = in_array('audio', $attachmentKinds, true);

if (!$hasEbook && !$hasAudiobook) {
    return;
}
?>

<?php if ($hasEbook): ?>
<i class="fas fa-file-pdf ml-1 digital-badge-icon ebook-icon"
   title="<?= htmlspecialchars(__("eBook disponibile"), ENT_QUOTES, 'UTF-8') ?>"
   style="font-size: 0.75em; opacity: 0.9; color: #dc2626;"></i>
<?php endif; ?>

<?php if ($hasAudiobook): ?>
<i class="fas fa-headphones ml-1 digital-badge-icon audio-icon"
   title="<?= htmlspecialchars(__("Audiobook disponibile"), ENT_QUOTES, 'UTF-8') ?>"
   style="font-size: 0.75em; opacity: 0.9; color: #16a34a;"></i>
<?php endif; ?>
