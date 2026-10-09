<?php
/**
 * Features Section Template
 * Displays features title and 4 feature cards
 */
$featuresData = $section ?? [];
?>

<!-- Features Section -->
<section class="section section-alt pk-section pk-section--features" data-section="features_title">
    <div class="pk-features__head">
        <h2 class="section-title pk-h2"><?php echo htmlspecialchars($featuresData['title'] ?? __("Perché Scegliere la Nostra Biblioteca"), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle pk-lead"><?php echo htmlspecialchars($featuresData['subtitle'] ?? __("Un'esperienza di lettura moderna, intuitiva e sempre a portata di mano"), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php
    // $homeContent holds only the sections that are switched on, so a card
    // the administrator turned off simply is not here. It used to be drawn
    // anyway, from defaults: a real library ended up publishing four
    // placeholders reading "Feature 1" to "Feature 4", because switching the
    // cards off is exactly what someone does when they do not want them.
    $visibleFeatures = [];
    for ($i = 1; $i <= 4; $i++) {
        if (isset($homeContent["feature_{$i}"])) {
            $visibleFeatures[] = $homeContent["feature_{$i}"];
        }
    }
    ?>
    <?php if ($visibleFeatures !== []): ?>
    <div class="feature-grid pk-features">
        <?php foreach ($visibleFeatures as $n => $feature):
            $icon = $feature['content'] ?? 'fas fa-star';
            $title = $feature['title'] ?? '';
            $desc = $feature['subtitle'] ?? '';
        ?>
        <div class="feature-card pk-feature">
            <div class="feature-heading pk-feature__top">
                <span class="pk-feature__n" aria-hidden="true"><?= sprintf('%02d', $n + 1) ?></span>
                <span class="feature-icon pk-feature__icon"><i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
            </div>
            <h3 class="feature-title pk-feature__title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
            <p class="feature-description pk-feature__text"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
