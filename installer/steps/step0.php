<?php
/**
 * Step 0: Language Selection
 * First step - choose installation language (will be default for entire app)
 */

// Handle language selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $selectedLanguage = $_POST['language'];

    // Validate language
    $allowedLanguages = ['it', 'en'];
    if (!in_array($selectedLanguage, $allowedLanguages)) {
        $selectedLanguage = 'it'; // Fallback to Italian
    }

    // Save language to session
    $_SESSION['installer_language'] = $selectedLanguage;
    $_SESSION['app_locale'] = $selectedLanguage;

    // Mark step 0 as completed
    completeStep(0);

    // Redirect to step 1
    header('Location: index.php?step=1');
    exit;
}

// Get current language (default to Italian if not set)
$currentLanguage = $_SESSION['installer_language'] ?? 'it';

renderHeader(0, 'Selezione Lingua');
?>

<div style="text-align: center; padding: 40px 20px;">
    <div style="font-size: 64px; margin-bottom: 30px;">🌍</div>

    <h2 class="step-title">Seleziona la Lingua / Select Language</h2>
    <p class="step-description" style="max-width: 600px; margin: 0 auto 40px;">
        Scegli la lingua per l'installazione e per l'applicazione.<br>
        Questa sarà la lingua predefinita per tutti gli utenti.<br><br>
        Choose the language for installation and application.<br>
        This will be the default language for all users.
    </p>

    <form method="POST" action="index.php?step=0" style="max-width: 500px; margin: 0 auto;">
        <div style="display: grid; gap: 20px; margin-bottom: 40px;">
            <!-- Italian Option -->
            <label class="language-option <?= $currentLanguage === 'it' ? 'selected' : '' ?>"
                   style="cursor: pointer; padding: 25px; border: 3px solid <?= $currentLanguage === 'it' ? '#2563eb' : '#e2e8f0' ?>; border-radius: 12px; background: <?= $currentLanguage === 'it' ? '#eff6ff' : '#ffffff' ?>; transition: all 0.2s;">
                <input type="radio" name="language" value="it" <?= $currentLanguage === 'it' ? 'checked' : '' ?>
                       style="display: none;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="font-size: 40px;">🇮🇹</div>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-size: 20px; font-weight: 600; color: #1e293b; margin-bottom: 5px;">
                            Italiano
                        </div>
                        <div style="font-size: 14px; color: #64748b;">
                            Lingua predefinita per l'intera applicazione
                        </div>
                    </div>
                    <div style="width: 24px; height: 24px; border: 2px solid <?= $currentLanguage === 'it' ? '#2563eb' : '#cbd5e1' ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: <?= $currentLanguage === 'it' ? '#2563eb' : 'white' ?>;">
                        <?php if ($currentLanguage === 'it'): ?>
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                                <path d="M11.6667 3.5L5.25 9.91667L2.33333 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                </div>
            </label>

            <!-- English Option -->
            <label class="language-option <?= $currentLanguage === 'en' ? 'selected' : '' ?>"
                   style="cursor: pointer; padding: 25px; border: 3px solid <?= $currentLanguage === 'en' ? '#2563eb' : '#e2e8f0' ?>; border-radius: 12px; background: <?= $currentLanguage === 'en' ? '#eff6ff' : '#ffffff' ?>; transition: all 0.2s;">
                <input type="radio" name="language" value="en" <?= $currentLanguage === 'en' ? 'checked' : '' ?>
                       style="display: none;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="font-size: 40px;">🇬🇧</div>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-size: 20px; font-weight: 600; color: #1e293b; margin-bottom: 5px;">
                            English
                        </div>
                        <div style="font-size: 14px; color: #64748b;">
                            Default language for the entire application
                        </div>
                    </div>
                    <div style="width: 24px; height: 24px; border: 2px solid <?= $currentLanguage === 'en' ? '#2563eb' : '#cbd5e1' ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: <?= $currentLanguage === 'en' ? '#2563eb' : 'white' ?>;">
                        <?php if ($currentLanguage === 'en'): ?>
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                                <path d="M11.6667 3.5L5.25 9.91667L2.33333 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                </div>
            </label>
        </div>

        <button type="submit" class="btn btn-primary" style="min-width: 250px; font-size: 16px; padding: 15px 30px;">
            <?= $currentLanguage === 'it' ? 'Continua' : 'Continue' ?> <i class="fas fa-arrow-right"></i>
        </button>
    </form>
</div>

<script>
// Make entire card clickable
document.querySelectorAll('.language-option').forEach(option => {
    option.addEventListener('click', function() {
        const radio = this.querySelector('input[type="radio"]');
        radio.checked = true;

        // Update visual selection
        document.querySelectorAll('.language-option').forEach(opt => {
            opt.style.border = '3px solid #e2e8f0';
            opt.style.background = '#ffffff';
            const checkbox = opt.querySelector('div:last-child');
            checkbox.style.borderColor = '#cbd5e1';
            checkbox.style.background = 'white';
            checkbox.innerHTML = '';
        });

        this.style.border = '3px solid #2563eb';
        this.style.background = '#eff6ff';
        const checkbox = this.querySelector('div:last-child');
        checkbox.style.borderColor = '#2563eb';
        checkbox.style.background = '#2563eb';
        checkbox.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M11.6667 3.5L5.25 9.91667L2.33333 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    });
});
</script>

<?php renderFooter(); ?>
