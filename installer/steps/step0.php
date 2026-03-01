<?php
/**
 * Step 0: Language Selection
 * First step - choose installation language (will be default for entire app)
 */

// Handle language selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $selectedLanguage = $_POST['language'];

    // Validate language — use canonical locale codes (it_IT, en_US, de_DE)
    $allowedLanguages = ['it_IT', 'en_US', 'de_DE'];
    if (!in_array($selectedLanguage, $allowedLanguages, true)) {
        $selectedLanguage = 'it_IT'; // Fallback to Italian
    }

    // Save language to session
    // installer_language is a simple flag used in the UI
    $_SESSION['installer_language'] = match($selectedLanguage) {
        'en_US' => 'en',
        'de_DE' => 'de',
        default => 'it',
    };
    // app_locale is used by the installer __() helper and must match I18n locale codes
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

    <h2 class="step-title">Seleziona la Lingua / Select Language / Sprache wählen</h2>
    <p class="step-description" style="max-width: 600px; margin: 0 auto 40px;">
        Scegli la lingua per l'installazione e per l'applicazione.<br>
        Choose the language for installation and application.<br>
        Wählen Sie die Sprache für die Installation und die Anwendung.
    </p>

    <form method="POST" action="index.php?step=0" style="max-width: 500px; margin: 0 auto;">
        <div style="display: grid; gap: 20px; margin-bottom: 40px;">
            <!-- Italian Option -->
            <label class="language-option <?= $currentLanguage === 'it' ? 'selected' : '' ?>">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <input
                        type="radio"
                        name="language"
                        value="it_IT"
                        <?= ($currentLanguage === 'it' || $currentLanguage === 'it_IT') ? 'checked' : '' ?>
                        class="language-radio"
                    >
                    <svg width="48" height="24" viewBox="0 0 60 30" style="border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.15);" aria-hidden="true" focusable="false">
                        <rect width="20" height="30" fill="#009246"/>
                        <rect x="20" width="20" height="30" fill="#fff"/>
                        <rect x="40" width="20" height="30" fill="#ce2b37"/>
                    </svg>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-size: 18px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">
                            Italiano
                        </div>
                        <div style="font-size: 13px; color: #64748b;">
                            Lingua predefinita per l'intera applicazione
                        </div>
                    </div>
                </div>
            </label>
 
            <!-- English Option -->
            <label class="language-option <?= $currentLanguage === 'en' ? 'selected' : '' ?>">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <input
                        type="radio"
                        name="language"
                        value="en_US"
                        <?= $currentLanguage === 'en' ? 'checked' : '' ?>
                        class="language-radio"
                    >
                    <svg width="48" height="24" viewBox="0 0 60 30" style="border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.15);" aria-hidden="true" focusable="false">
                        <clipPath id="clip-flag-en"><rect width="60" height="30"/></clipPath>
                        <g clip-path="url(#clip-flag-en)">
                            <path d="M0,0 v30 h60 v-30 z" fill="#00247d"/>
                            <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                            <path d="M0,0 L60,30 M60,0 L0,30" stroke="#cf142b" stroke-width="4"/>
                            <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                            <path d="M30,0 v30 M0,15 h60" stroke="#cf142b" stroke-width="6"/>
                        </g>
                    </svg>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-size: 18px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">
                            English
                        </div>
                        <div style="font-size: 13px; color: #64748b;">
                            Default language for the entire application
                        </div>
                    </div>
                </div>
            </label>

            <!-- German Option -->
            <label class="language-option <?= $currentLanguage === 'de' ? 'selected' : '' ?>">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <input
                        type="radio"
                        name="language"
                        value="de_DE"
                        <?= $currentLanguage === 'de' ? 'checked' : '' ?>
                        class="language-radio"
                    >
                    <svg width="48" height="24" viewBox="0 0 60 30" style="border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.15);" aria-hidden="true" focusable="false">
                        <rect width="60" height="10" fill="#000"/>
                        <rect y="10" width="60" height="10" fill="#dd0000"/>
                        <rect y="20" width="60" height="10" fill="#ffcc00"/>
                    </svg>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-size: 18px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">
                            Deutsch
                        </div>
                        <div style="font-size: 13px; color: #64748b;">
                            Standardsprache für die gesamte Anwendung
                        </div>
                    </div>
                </div>
            </label>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">
            <?= match($currentLanguage) { 'en' => 'Continue', 'de' => 'Weiter', default => 'Continua' } ?> <i class="fas fa-arrow-right"></i>
        </button>
    </form>
</div>

<script>
 // Make entire card clickable and keep styles consistent via CSS classes
 document.querySelectorAll('.language-option').forEach(option => {
     option.addEventListener('click', function (event) {
         const radio = this.querySelector('.language-radio');
         if (!radio) return;

         // Evita doppio toggle se clicchi direttamente sul radio
         if (event.target !== radio) {
             radio.checked = true;
         }

         document.querySelectorAll('.language-option').forEach(opt => {
             opt.classList.remove('selected');
         });

         this.classList.add('selected');
     });
 });
</script>

<?php renderFooter(); ?>
