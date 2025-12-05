<?php
/**
 * Step 5: Application Settings (Name & Logo)
 */

$error = null;
$success = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appName = $validator->sanitize($_POST['app_name'] ?? '');
    $canonicalUrl = $validator->sanitize($_POST['canonical_url'] ?? '');

    // Validate app name
    if (!$validator->validateRequired($appName, 'Nome applicazione')) {
        $error = $validator->getFirstError();
    }
    // Validate canonical URL if provided
    elseif (!empty($canonicalUrl) && !filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
        $error = __("L'URL canonico non è valido. Deve iniziare con http:// o https://");
    }
    else {
        try {
            // Load .env and connect to database
            $installer->loadEnvConfig();

            // Save app name
            $installer->saveSetting('app', 'name', $appName);

            // Save app language (from session, set in step 0)
            $locale = $_SESSION['app_locale'] ?? 'it';
            $installer->saveSetting('app', 'locale', $locale);

            // Update APP_CANONICAL_URL in .env file
            if ($canonicalUrl !== '') {
                $installer->updateEnvVariable('APP_CANONICAL_URL', rtrim($canonicalUrl, '/'));
            }

            // Handle FORCE_HTTPS option
            $forceHttps = isset($_POST['force_https']) && $_POST['force_https'] === '1' ? 'true' : 'false';
            $installer->updateEnvVariable('FORCE_HTTPS', $forceHttps);

            // Handle logo upload (optional)
            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($validator->validateFileUpload($_FILES['logo_file'])) {
                    $logoPath = $installer->uploadLogo($_FILES['logo_file']);
                    // Save to database - ConfigStore reads 'logo_path' and maps to app.logo
                    $installer->saveSetting('app', 'logo_path', $logoPath);
                } else {
                    $error = $validator->getFirstError();
                }
            }

            // Handle catalogue mode option
            $catalogueMode = isset($_POST['catalogue_mode']) && $_POST['catalogue_mode'] === '1' ? '1' : '0';
            $installer->saveSetting('system', 'catalogue_mode', $catalogueMode);

            if (!$error) {
                $_SESSION['app_settings'] = ['name' => $appName];
                completeStep(5);
                header('Location: index.php?step=6');
                exit;
            }

        } catch (Exception $e) {
            $error = __("Errore durante il salvataggio delle impostazioni:") . " " . $e->getMessage();
        }
    }
}

// Default values
$appName = $_POST['app_name'] ?? 'Pinakes';

// Get auto-detected canonical URL from .env
$autoDetectedUrl = '';
if (file_exists($baseDir . '/.env')) {
    $envContent = file_get_contents($baseDir . '/.env');
    if (preg_match('/^APP_CANONICAL_URL=(.*)$/m', $envContent, $matches)) {
        $autoDetectedUrl = trim($matches[1]);
    }
}
$canonicalUrl = $_POST['canonical_url'] ?? $autoDetectedUrl;

renderHeader(5, __('Impostazioni Applicazione'));
?>

<h2 class="step-title">⚙️ <?= __("Impostazioni Applicazione") ?></h2>
<p class="step-description">
    <?= __("Personalizza il nome dell'applicazione e carica un logo opzionale.") ?>
</p>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="index.php?step=5" enctype="multipart/form-data">
    <div class="form-group">
        <label class="form-label"><?= __("Nome Applicazione") ?> *</label>
        <input type="text" name="app_name" class="form-input" value="<?= htmlspecialchars($appName) ?>" required>
        <small style="color: #718096;"><?= __("Sarà visualizzato nell'header e in tutto il sito") ?></small>
    </div>

    <div class="form-group" style="margin-top: 30px;">
        <label class="form-label"><?= __("URL Canonico (opzionale)") ?></label>
        <input type="url" name="canonical_url" class="form-input" value="<?= htmlspecialchars($canonicalUrl) ?>" placeholder="https://biblioteca.example.com">
        <small style="color: #718096;">
            <?= __("URL completo del sito (es: https://biblioteca.example.com). Usato per link nelle email (verifica account, reset password). Se lasciato vuoto, verrà auto-rilevato.") ?>
        </small>
    </div>

    <div class="form-group" style="margin-top: 20px;">
        <div style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border: 1px solid #0ea5e9; border-radius: 12px; padding: 20px;">
            <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                <input type="checkbox" name="force_https" value="1" style="width: 20px; height: 20px; margin-top: 2px; accent-color: #0369a1;">
                <div>
                    <span style="font-weight: 600; color: #0369a1; display: block;"><?= __("Forza HTTPS") ?></span>
                    <small style="color: #075985; display: block; margin-top: 4px;">
                        <?= __("Reindirizza automaticamente tutte le richieste HTTP a HTTPS") ?>
                    </small>
                </div>
            </label>
        </div>
        <div class="alert alert-warning" style="margin-top: 12px; padding: 12px; font-size: 13px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong><?= __("Attenzione:") ?></strong> <?= __("Attiva questa opzione solo se il tuo server supporta HTTPS. Se il tuo hosting forza già HTTPS automaticamente, non è necessario abilitarla (potrebbe causare redirect loop).") ?>
        </div>
    </div>

    <div class="form-group" style="margin-top: 30px;">
        <label class="form-label"><?= __("Logo Applicazione (opzionale)") ?></label>
        <div class="file-upload-wrapper">
            <input type="file" id="logo_file" name="logo_file" accept="image/jpeg,image/png,image/gif,image/webp">
            <label for="logo_file" class="file-upload-label">
                <i class="fas fa-cloud-upload-alt"></i>
                <div><?= __("Clicca o trascina per caricare un logo") ?></div>
                <small style="color: #a0aec0;"><?= __("JPG, PNG, GIF, WEBP - Max 5MB") ?></small>
            </label>
        </div>
        <div id="logo-preview" style="margin-top: 15px; text-align: center;"></div>
    </div>

    <div class="form-group" style="margin-top: 30px;">
        <label class="form-label"><?= __("Modalità Catalogo") ?></label>
        <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 1px solid #f59e0b; border-radius: 12px; padding: 20px;">
            <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                <input type="checkbox" name="catalogue_mode" value="1" style="width: 20px; height: 20px; margin-top: 2px; accent-color: #92400e;">
                <div>
                    <span style="font-weight: 600; color: #92400e; display: block;"><?= __("Abilita Modalità Solo Catalogo") ?></span>
                    <small style="color: #78350f; display: block; margin-top: 4px;">
                        <?= __("Disabilita prestiti, prenotazioni e wishlist. Gli utenti potranno solo consultare il catalogo.") ?>
                    </small>
                </div>
            </label>
        </div>
        <small style="color: #718096; margin-top: 8px; display: block;">
            <i class="fas fa-info-circle"></i> <?= __("Puoi cambiare questa impostazione in qualsiasi momento da Impostazioni → Avanzate") ?>
        </small>
    </div>

    <div class="alert alert-info" style="margin-top: 30px;">
        <i class="fas fa-info-circle"></i>
        <strong><?= __("Suggerimento:") ?></strong> <?= __("Puoi modificare queste impostazioni in qualsiasi momento dalla sezione Impostazioni dell'admin.") ?>
    </div>

    <div style="margin-top: 40px; display: flex; justify-content: space-between;">
        <a href="index.php?step=4" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> <?= __("Indietro") ?>
        </a>
        <button type="submit" class="btn btn-primary">
            <?= __("Continua") ?> <i class="fas fa-arrow-right"></i>
        </button>
    </div>
</form>

<?php renderFooter(); ?>
