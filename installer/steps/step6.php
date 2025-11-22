<?php
/**
 * Step 6: Email Configuration
 */

$error = null;
$success = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver = $validator->sanitize($_POST['email_driver'] ?? 'mail');
    $fromEmail = $validator->sanitize($_POST['from_email'] ?? '');
    $fromName = $validator->sanitize($_POST['from_name'] ?? '');
    $smtpHost = $validator->sanitize($_POST['smtp_host'] ?? '');
    $smtpPort = (int)($_POST['smtp_port'] ?? 587);
    $smtpUsername = $validator->sanitize($_POST['smtp_username'] ?? '');
    $smtpPassword = $_POST['smtp_password'] ?? '';
    $smtpEncryption = $validator->sanitize($_POST['smtp_encryption'] ?? 'tls');

    // Validate required fields
    $valid = true;
    if (!$validator->validateEmail($fromEmail, 'From Email')) $valid = false;
    if (!$validator->validateRequired($fromName, 'From Name')) $valid = false;

    // If SMTP selected, validate SMTP fields
    if ($driver === 'smtp') {
        if (!$validator->validateRequired($smtpHost, 'SMTP Host')) $valid = false;
    }

    if ($valid) {
        try {
            // Load .env and connect to database
            $installer->loadEnvConfig();

            // CRITICAL FIX: Use ConfigStore to save email settings
            // ConfigStore maps 'mail' category to 'email' in database automatically
            require_once __DIR__ . '/../../app/Support/ConfigStore.php';
            \App\Support\ConfigStore::set('mail.driver', $driver);
            \App\Support\ConfigStore::set('mail.from_email', $fromEmail);
            \App\Support\ConfigStore::set('mail.from_name', $fromName);

            // SMTP settings in nested structure
            \App\Support\ConfigStore::set('mail.smtp.host', $smtpHost);
            \App\Support\ConfigStore::set('mail.smtp.port', (int)$smtpPort);
            \App\Support\ConfigStore::set('mail.smtp.username', $smtpUsername);
            \App\Support\ConfigStore::set('mail.smtp.password', $smtpPassword);
            \App\Support\ConfigStore::set('mail.smtp.encryption', $smtpEncryption);

            completeStep(6);
            header('Location: index.php?step=7&force');
            exit;

        } catch (Exception $e) {
            $error = __("Errore durante il salvataggio:") . " " . $e->getMessage();
        }
    } else {
        $error = $validator->getFirstError();
    }
}

// Default values
$appName = $_SESSION['app_settings']['name'] ?? 'Pinakes';
$driver = $_POST['email_driver'] ?? 'mail';
// Extract domain without port for email generation
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$domain = preg_replace('/:\d+$/', '', $host); // Remove port if present
$fromEmail = $_POST['from_email'] ?? "no-reply@{$domain}";
$fromName = $_POST['from_name'] ?? $appName;
$smtpHost = $_POST['smtp_host'] ?? $domain;
$smtpPort = $_POST['smtp_port'] ?? 587;
$smtpUsername = $_POST['smtp_username'] ?? '';
$smtpPassword = $_POST['smtp_password'] ?? '';
$smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';

renderHeader(6, __('Configurazione Email'));
?>

<h2 class="step-title">✉️ <?= __("Configurazione Email") ?></h2>
<p class="step-description">
    <?= __("Configura le impostazioni email per l'invio di notifiche agli utenti.") ?>
</p>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="index.php?step=6">
    <div class="form-group">
        <label class="form-label"><?= __("Driver Email") ?> *</label>
        <select name="email_driver" id="email_driver" class="form-select" onchange="toggleSmtpFields()">
            <option value="mail" <?= $driver === 'mail' ? 'selected' : '' ?>><?= __("PHP mail() - Predefinito") ?></option>
            <option value="phpmailer" <?= $driver === 'phpmailer' ? 'selected' : '' ?>>PHPMailer</option>
            <option value="smtp" <?= $driver === 'smtp' ? 'selected' : '' ?>><?= __("SMTP Personalizzato") ?></option>
        </select>
        <small style="color: #718096;"><?= __("Consigliato: PHP mail() per semplicità, SMTP per maggiore controllo") ?></small>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label"><?= __("From Email") ?> *</label>
            <input type="email" name="from_email" class="form-input" value="<?= htmlspecialchars($fromEmail) ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label"><?= __("From Name") ?> *</label>
            <input type="text" name="from_name" class="form-input" value="<?= htmlspecialchars($fromName) ?>" required>
        </div>
    </div>

    <div id="smtp-fields" style="display: <?= $driver === 'smtp' ? 'block' : 'none' ?>;">
        <h3 style="margin-top: 30px; margin-bottom: 15px; color: #2d3748;"><?= __("Configurazione SMTP") ?></h3>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label"><?= __("SMTP Host") ?></label>
                <input type="text" name="smtp_host" class="form-input" value="<?= htmlspecialchars($smtpHost) ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><?= __("SMTP Port") ?></label>
                <input type="number" name="smtp_port" class="form-input" value="<?= htmlspecialchars((string)$smtpPort) ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label"><?= __("SMTP Username") ?></label>
                <input type="text" name="smtp_username" class="form-input" value="<?= htmlspecialchars($smtpUsername) ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><?= __("SMTP Password") ?></label>
                <input type="password" name="smtp_password" class="form-input" value="<?= htmlspecialchars($smtpPassword) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label"><?= __("Encryption") ?></label>
            <select name="smtp_encryption" class="form-select">
                <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>><?= __("Nessuna") ?></option>
            </select>
        </div>
    </div>

    <div class="alert alert-info" style="margin-top: 30px;">
        <i class="fas fa-info-circle"></i>
        <strong><?= __("Nota:") ?></strong> <?= __("Puoi configurare o modificare queste impostazioni in seguito dalla sezione Impostazioni Email dell'admin.") ?>
    </div>

    <div style="margin-top: 40px; display: flex; justify-content: space-between;">
        <a href="index.php?step=5" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> <?= __("Indietro") ?>
        </a>
        <button type="submit" class="btn btn-primary">
            <?= __("Continua") ?> <i class="fas fa-arrow-right"></i>
        </button>
    </div>
</form>

<script>
function toggleSmtpFields() {
    const driver = document.getElementById('email_driver').value;
    const smtpFields = document.getElementById('smtp-fields');

    if (driver === 'smtp') {
        smtpFields.style.display = 'block';
    } else {
        smtpFields.style.display = 'none';
    }
}
</script>

<?php renderFooter(); ?>
