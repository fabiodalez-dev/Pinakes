<?php
$additional_css = "
<style>
    main {
        padding-top: 90px;
    }

    .contact-page {
        padding: 4rem 0;
        background: white;
    }

    .contact-header {
        text-align: center;
        margin-bottom: 3rem;
    }

    .contact-title {
        font-size: clamp(2rem, 4vw, 2.75rem);
        font-weight: 800;
        color: #111827;
        margin-bottom: 1rem;
        letter-spacing: -0.02em;
    }

    .contact-divider {
        width: 80px;
        height: 4px;
        background: #1f2937;
        margin: 0 auto 1.5rem;
        border-radius: 2px;
    }

    .contact-content {
        font-size: 1.0625rem;
        line-height: 1.8;
        color: #374151;
        text-align: center;
        max-width: 700px;
        margin: 0 auto;
    }

    .contact-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 3rem;
        max-width: 1200px;
        margin: 0 auto;
    }

    @media (max-width: 968px) {
        .contact-grid {
            grid-template-columns: 1fr;
            gap: 2rem;
        }
    }

    .contact-info-section {
        background: #f9fafb;
        border-radius: 16px;
        padding: 2.5rem;
        border: 1px solid #e5e7eb;
    }

    .contact-info-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 1.5rem;
    }

    .contact-info-item {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .contact-info-icon {
        width: 48px;
        height: 48px;
        background: #1f2937;
        color: white;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1.25rem;
    }

    .contact-info-content h4 {
        font-size: 0.875rem;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin: 0 0 0.5rem 0;
    }

    .contact-info-content p {
        font-size: 1.125rem;
        font-weight: 500;
        color: #111827;
        margin: 0;
    }

    .contact-info-content a {
        color: #111827;
        text-decoration: none;
        transition: color 0.2s;
    }

    .contact-info-content a:hover {
        color: #3b82f6;
    }

    .contact-map {
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid #e5e7eb;
        height: 400px;
        position: relative;
    }

    .contact-map iframe {
        width: 100%;
        height: 100%;
        border: 0;
    }

    .map-blocked-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        background: linear-gradient(135deg, #f9fafb 0%, #e5e7eb 100%);
        padding: 2rem;
        text-align: center;
    }

    .map-blocked-icon {
        width: 64px;
        height: 64px;
        background: #1f2937;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin-bottom: 1.5rem;
    }

    .map-blocked-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 0.75rem;
    }

    .map-blocked-description {
        font-size: 0.9375rem;
        color: #6b7280;
        margin-bottom: 1.5rem;
        max-width: 400px;
    }

    .map-blocked-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        background: #1f2937;
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 0.9375rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .map-blocked-button:hover {
        background: #111827;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }

    .external-content-wrapper[data-consent-required='analytics'] iframe {
        display: none;
    }

    .external-content-wrapper[data-consent-required='analytics'].consent-granted iframe {
        display: block;
    }

    .external-content-wrapper[data-consent-required='analytics'].consent-granted .map-blocked-placeholder {
        display: none;
    }

    .contact-form-section {
        background: white;
        border-radius: 16px;
        padding: 2.5rem;
        border: 1px solid #e5e7eb;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
        margin-bottom: 1.25rem;
    }

    @media (max-width: 640px) {
        .form-row {
            grid-template-columns: 1fr;
        }
    }

    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.5rem;
    }

    .form-label .required {
        color: #ef4444;
        margin-left: 0.25rem;
    }

    .form-input,
    .form-textarea {
        width: 100%;
        padding: 0.875rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        font-size: 1rem;
        color: #111827;
        transition: all 0.2s;
        font-family: inherit;
    }

    .form-input:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #1f2937;
        box-shadow: 0 0 0 3px rgba(31, 41, 55, 0.1);
    }

    .form-textarea {
        min-height: 150px;
        resize: vertical;
    }

    .checkbox-field {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        margin: 1.5rem 0;
    }

    .checkbox-field input[type=\"checkbox\"] {
        width: 20px;
        height: 20px;
        margin-top: 0.125rem;
        cursor: pointer;
        flex-shrink: 0;
    }

    .checkbox-field label {
        font-size: 0.9375rem;
        color: #374151;
        line-height: 1.6;
        cursor: pointer;
        flex: 1;
    }

    .btn-submit {
        width: 100%;
        padding: 1rem 2rem;
        background: #1f2937;
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 1.0625rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
    }

    .btn-submit:hover {
        background: #111827;
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }

    .btn-submit:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .alert {
        padding: 1rem 1.25rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .alert-success {
        background: #f0fdf4;
        border: 1px solid #86efac;
        color: #15803d;
    }

    .alert-error {
        background: #fef2f2;
        border: 1px solid #fca5a5;
        color: #991b1b;
    }

    .alert i {
        font-size: 1.25rem;
    }

    @media (max-width: 768px) {
        .contact-page {
            padding: 2rem 0;
        }

        .contact-form-section,
        .contact-info-section {
            padding: 1.5rem;
        }
    }
</style>
";

ob_start();
?>

<section class="contact-page">
    <div class="container">
        <div class="contact-header">
            <h1 class="contact-title"><?= htmlspecialchars($title) ?></h1>
            <div class="contact-divider"></div>
            <?php if (!empty($content)): ?>
                <div class="contact-content">
                    <?= $content ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span>Messaggio inviato con successo! Ti risponderemo al più presto.</span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <span>
                    <?php
                    $errorMsg = match($_GET['error']) {
                        'csrf' => 'Errore di sicurezza. Riprova.',
                        'recaptcha' => 'Verifica reCAPTCHA fallita. Riprova.',
                        'required' => 'Compila tutti i campi obbligatori.',
                        'email' => 'Inserisci un\'email valida.',
                        'privacy' => 'Devi accettare l\'informativa sulla privacy.',
                        'db' => 'Errore di salvataggio. Riprova più tardi.',
                        default => 'Si è verificato un errore. Riprova.'
                    };
                    echo htmlspecialchars($errorMsg);
                    ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="contact-grid">
            <!-- Informazioni e Mappa -->
            <div>
                <?php if (!empty($contactEmail) || !empty($contactPhone)): ?>
                <div class="contact-info-section">
                    <h2 class="contact-info-title">Informazioni di contatto</h2>

                    <?php if (!empty($contactEmail)): ?>
                    <div class="contact-info-item">
                        <div class="contact-info-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="contact-info-content">
                            <h3>__("Email")</h3>
                            <p><a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($contactPhone)): ?>
                    <div class="contact-info-item">
                        <div class="contact-info-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="contact-info-content">
                            <h3>__("Telefono")</h3>
                            <p><a href="tel:<?= htmlspecialchars($contactPhone) ?>"><?= htmlspecialchars($contactPhone) ?></a></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($googleMapsEmbed)): ?>
                <div class="contact-map external-content-wrapper" data-consent-required="analytics" style="margin-top: 2rem;">
                    <!-- Map Blocked Placeholder -->
                    <div class="map-blocked-placeholder">
                        <div class="map-blocked-icon">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <p class="map-blocked-title" role="status">Mappa non disponibile</p>
                        <p class="map-blocked-description">
                            Per visualizzare la mappa, accetta i cookie di Analytics nelle preferenze cookie.
                        </p>
                        <button type="button" class="map-blocked-button" onclick="if(window.CookieControl) window.CookieControl.open();">
                            <i class="fas fa-cookie-bite"></i>
                            Gestisci preferenze cookie
                        </button>
                    </div>

                    <!-- Map iframe (hidden by default if consent not granted) -->
                    <?php
                    // Print the Maps embed code without escaping
                    echo $googleMapsEmbed;
                    ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Form -->
            <div class="contact-form-section">
                <h2 class="contact-info-title">Inviaci un messaggio</h2>

                <form method="post" action="/contatti/invia" id="contact-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="recaptcha_token" id="recaptcha_token">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="nome" class="form-label">__("Nome")<span class="required">*</span></label>
                            <input type="text" id="nome" name="nome" class="form-input" required aria-required="true" aria-describedby="nome-error">
                            <span id="nome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
                        </div>
                        <div class="form-group">
                            <label for="cognome" class="form-label">Cognome<span class="required">*</span></label>
                            <input type="text" id="cognome" name="cognome" class="form-input" required aria-required="true" aria-describedby="cognome-error">
                            <span id="cognome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">__("Email")<span class="required">*</span></label>
                        <input type="email" id="email" name="email" class="form-input" required aria-required="true" aria-describedby="email-error">
                        <span id="email-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
                    </div>

                    <div class="form-group">
                        <label for="telefono" class="form-label">__("Telefono")</label>
                        <input type="tel" id="telefono" name="telefono" class="form-input">
                    </div>

                    <div class="form-group">
                        <label for="indirizzo" class="form-label">__("Indirizzo")</label>
                        <input type="text" id="indirizzo" name="indirizzo" class="form-input">
                    </div>

                    <div class="form-group">
                        <label for="messaggio" class="form-label">Messaggio<span class="required">*</span></label>
                        <textarea id="messaggio" name="messaggio" class="form-textarea" required aria-required="true" aria-describedby="messaggio-error"></textarea>
                        <span id="messaggio-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
                    </div>

                    <div class="checkbox-field">
                        <input type="checkbox" name="privacy" id="privacy" required aria-required="true" aria-describedby="privacy-error">
                        <label for="privacy"><?= htmlspecialchars($privacyText) ?><span class="required">*</span></label>
                        <span id="privacy-error" class="text-sm text-red-600 mt-1 hidden block" role="alert" aria-live="polite"></span>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i>
                        <span>Invia messaggio</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($recaptchaSiteKey)): ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($recaptchaSiteKey) ?>"></script>
<script>
document.getElementById('contact-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const submitBtn = form.querySelector('.btn-submit');

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Invio in corso...</span>';

    grecaptcha.ready(function() {
        grecaptcha.execute('<?= htmlspecialchars($recaptchaSiteKey) ?>', {action: 'contact_form'}).then(function(token) {
            document.getElementById('recaptcha_token').value = token;
            form.submit();
        });
    });
});
</script>
<?php endif; ?>

<!-- External Content Consent Manager -->
<script>
(function() {
    'use strict';

    function checkAndUpdateExternalContent() {
        // Check if Silktide Cookie Control is loaded
        if (!window.CookieControl || !window.CookieControl.getCategoryConsent) {
            // Cookie Control not ready yet, will retry
            return;
        }

        // Get all external content wrappers
        const externalWrappers = document.querySelectorAll('.external-content-wrapper[data-consent-required]');

        externalWrappers.forEach(function(wrapper) {
            const requiredCategory = wrapper.getAttribute('data-consent-required');
            const wasGranted = wrapper.classList.contains('consent-granted');

            // Check if user has consented to the required category
            const hasConsent = window.CookieControl.getCategoryConsent(requiredCategory);

            if (hasConsent) {
                // User has consented - show the content
                wrapper.classList.add('consent-granted');

                // If consent was just granted (not previously granted), reload iframes
                if (!wasGranted) {
                    const iframes = wrapper.querySelectorAll('iframe');
                    iframes.forEach(function(iframe) {
                        // Force reload by setting src to itself
                        const src = iframe.src;
                        if (src) {
                            iframe.src = src;
                        }
                    });
                }
            } else {
                // User has not consented - hide the content
                wrapper.classList.remove('consent-granted');
            }
        });
    }

    // Check consent on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(checkAndUpdateExternalContent, 100);
        });
    } else {
        setTimeout(checkAndUpdateExternalContent, 100);
    }

    // Listen for consent changes from Silktide
    window.addEventListener('silktideConsentChanged', function(event) {
        // User has changed their consent preferences
        setTimeout(checkAndUpdateExternalContent, 100);
    });

    // Also check every second for the first 5 seconds (in case Cookie Control loads slowly)
    let attempts = 0;
    const checkInterval = setInterval(function() {
        attempts++;
        checkAndUpdateExternalContent();

        if (attempts >= 5 || (window.CookieControl && window.CookieControl.getCategoryConsent)) {
            clearInterval(checkInterval);
        }
    }, 1000);
})();
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
?>
