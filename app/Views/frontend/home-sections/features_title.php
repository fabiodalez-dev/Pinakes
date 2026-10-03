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
        <?php
        // $homeContent holds only the sections that are switched on, so a card
        // the administrator turned off simply is not here. It used to be drawn
        // anyway, from the defaults on the next lines: a real library ended up
        // publishing four placeholders reading "Feature 1" to "Feature 4", each
        // a star icon with no text, because switching the cards off is exactly
        // what someone does when they do not want them.
        $visibleFeatures = [];
        for ($i = 1; $i <= 4; $i++) {
            if (isset($homeContent["feature_{$i}"])) {
                $visibleFeatures[] = $homeContent["feature_{$i}"];
            }
        }
        ?>
        <?php if ($visibleFeatures !== []): ?>
        <div class="feature-grid">
            <?php foreach ($visibleFeatures as $feature):
                $icon = $feature['content'] ?? 'fas fa-star';
                $title = $feature['title'] ?? '';
                $desc = $feature['subtitle'] ?? '';
            ?>
            <div class="feature-card">
                <div class="feature-heading">
                    <div class="feature-icon">
                        <i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                    </div>
                    <h3 class="feature-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <p class="feature-description">
                    <?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
