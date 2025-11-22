<?php
/**
 * Call to Action Section Template
 * Final CTA section with registration button
 */
$ctaData = $section ?? [];
$registerRoute = $registerRoute ?? route_path('register');
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
                <a href="<?php echo htmlspecialchars($ctaData['button_link'] ?? $registerRoute, ENT_QUOTES, 'UTF-8'); ?>" class="btn-cta">
                    <i class="fas fa-user-plus"></i>
                    <?php echo htmlspecialchars($ctaData['button_text'] ?? __("Registrati Ora"), ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <a href="/contatti" class="btn-cta">
                    <i class="fas fa-envelope"></i>
                    <?= __("Contattaci") ?>
                </a>
            </div>
        </div>
    </div>
</section>
