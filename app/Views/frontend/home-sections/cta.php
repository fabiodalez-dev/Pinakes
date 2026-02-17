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
<section class="cta-section" data-section="cta">
    <div class="container">
        <div class="cta-content">
            <h2 class="cta-title"><?php echo htmlspecialchars($ctaData['title'] ?? __("Inizia la Tua Avventura Letteraria"), ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="cta-subtitle">
                <?php echo htmlspecialchars($ctaData['subtitle'] ?? __("Unisciti alla nostra community di lettori e scopri il piacere della lettura con la nostra piattaforma moderna."), ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="<?php echo htmlspecialchars($ctaButtonLink, ENT_QUOTES, 'UTF-8'); ?>" class="btn-cta">
                    <i class="fas fa-user-plus"></i>
                    <?php echo htmlspecialchars($ctaData['button_text'] ?? __("Registrati Ora"), ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <a href="<?= htmlspecialchars(route_path('contact'), ENT_QUOTES, 'UTF-8') ?>" class="btn-cta">
                    <i class="fas fa-envelope"></i>
                    <?= __("Contattaci") ?>
                </a>
            </div>
        </div>
    </div>
</section>
