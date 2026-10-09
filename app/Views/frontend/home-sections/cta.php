<?php
/**
 * Call to Action Section Template
 * Final CTA section with registration button
 */
$ctaData = $section ?? [];
$registerRoute = $registerRoute ?? route_path('register');
// Prepend base path to CMS button link if it's a relative path
$ctaButtonLink = isset($ctaData['button_link']) && $ctaData['button_link'] !== ''
    ? url($ctaData['button_link'])
    : $registerRoute;
?>

<!-- Call to Action Section -->
<section class="cta-section pk-cta-wrap" data-section="cta">
    <div class="cta-content pk-cta">
        <div class="pk-cta__text">
            <?php $ctaTitle = \App\Support\HomeTexts::text($ctaData, 'cta', 'title'); $ctaSub = \App\Support\HomeTexts::text($ctaData, 'cta', 'subtitle'); ?>
            <?php if ($ctaTitle !== ''): ?><h2 class="cta-title"><?php echo htmlspecialchars($ctaTitle, ENT_QUOTES, 'UTF-8'); ?></h2><?php endif; ?>
            <?php if ($ctaSub !== ''): ?><p class="cta-subtitle"><?php echo htmlspecialchars($ctaSub, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        </div>
        <div class="pk-cta__actions">
            <a href="<?php echo htmlspecialchars($ctaButtonLink, ENT_QUOTES, 'UTF-8'); ?>" class="btn-cta pk-cta__btn pk-cta__btn--solid"><i class="fas fa-user-plus" aria-hidden="true"></i> <?php echo htmlspecialchars(\App\Support\HomeTexts::label($ctaData, 'cta', 'button_text'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a href="<?= htmlspecialchars(route_path('contact'), ENT_QUOTES, 'UTF-8') ?>" class="btn-cta pk-cta__btn pk-cta__btn--line"><i class="fas fa-envelope" aria-hidden="true"></i> <?= __("Contattaci") ?></a>
        </div>
    </div>
</section>
