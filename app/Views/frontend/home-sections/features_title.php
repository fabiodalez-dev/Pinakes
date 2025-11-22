<?php
/**
 * Features Section Template
 * Displays features title and 4 feature cards
 */
$featuresData = $section ?? [];
?>

<!-- Features Section -->
<section class="section section-alt" data-section="features_title">
    <div class="container">
        <h2 class="section-title"><?php echo htmlspecialchars($featuresData['title'] ?? __("Perché Scegliere la Nostra Biblioteca"), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle">
            <?php echo htmlspecialchars($featuresData['subtitle'] ?? __("Un'esperienza di lettura moderna, intuitiva e sempre a portata di mano"), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div class="feature-grid">
            <?php for ($i = 1; $i <= 4; $i++):
                $feature = $homeContent["feature_{$i}"] ?? [];
                $icon = $feature['content'] ?? 'fas fa-star';
                $title = $feature['title'] ?? sprintf(__("Feature %d"), $i);
                $desc = $feature['subtitle'] ?? '';
            ?>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                </div>
                <h3 class="feature-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="feature-description">
                    <?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
