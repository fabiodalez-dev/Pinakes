<?php
/**
 * Text Content Section Template
 * Displays custom text/HTML content
 */
$textData = $section ?? [];
?>

<!-- Text Content Section -->
<section class="section section-alt" data-section="text_content">
    <div class="container">
        <?php if (!empty($textData['title'])): ?>
        <h2 class="section-title"><?php echo htmlspecialchars($textData['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php endif; ?>
        <div class="text-content-body" style="margin: 0 auto; font-size: 1.1rem; line-height: 1.8;">
            <?php echo $textData['content'] ?? ''; ?>
        </div>
    </div>
</section>
