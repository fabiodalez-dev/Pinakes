<?php
/**
 * Step 4: Create Admin User
 */

$error = null;
$success = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $validator->sanitize($_POST['nome'] ?? '');
    $cognome = $validator->sanitize($_POST['cognome'] ?? '');
    $email = $validator->sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validate all fields
    $valid = true;
    if (!$validator->validateRequired($nome, 'Nome')) $valid = false;
    if (!$validator->validateRequired($cognome, 'Cognome')) $valid = false;
    if (!$validator->validateEmail($email, 'Email')) $valid = false;
    if (!$validator->validatePassword($password, 'Password')) $valid = false;
    if (!$validator->validatePasswordConfirmation($password, $passwordConfirm)) $valid = false;

    if ($valid) {
        try {
            // Load .env and connect to database
            $installer->loadEnvConfig();

            // Create admin user
            $admin = $installer->createAdminUser($nome, $cognome, $email, $password);

            $_SESSION['admin_user'] = [
                'nome' => $nome,
                'cognome' => $cognome,
                'email' => $email,
                'codice_tessera' => $admin['codice_tessera']
            ];

            completeStep(4);
            header('Location: index.php?step=5');
            exit;

        } catch (Exception $e) {
            $error = "Errore durante la creazione dell'utente: " . $e->getMessage();
        }
    } else {
        $error = $validator->getFirstError();
    }
}

// Default values
$nome = $_POST['nome'] ?? '';
$cognome = $_POST['cognome'] ?? '';
$email = $_POST['email'] ?? '';

renderHeader(4, 'Crea Utente Admin');
?>

<h2 class="step-title">👤 Crea Utente Amministratore</h2>
<p class="step-description">
    Crea il primo utente amministratore. Questo account avrà accesso completo a tutte le funzionalità del sistema.
</p>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="index.php?step=4">
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Nome *</label>
            <input type="text" name="nome" class="form-input" value="<?= htmlspecialchars($nome) ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label">Cognome *</label>
            <input type="text" name="cognome" class="form-input" value="<?= htmlspecialchars($cognome) ?>" required>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Email *</label>
        <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($email) ?>" required>
        <small style="color: #718096;">Sarà utilizzata per accedere al sistema</small>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Password *</label>
            <input type="password" id="password" name="password" class="form-input" minlength="8" required>
            <small style="color: #718096;">Minimo 8 caratteri</small>
        </div>

        <div class="form-group">
            <label class="form-label">Conferma Password *</label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-input" minlength="8" required>
        </div>
    </div>

    <div class="alert alert-info" style="margin-top: 20px;">
        <i class="fas fa-info-circle"></i>
        <strong>Nota:</strong> Il codice tessera sarà generato automaticamente (formato: ADMIN-YYYYMMDD-XXX).
    </div>

    <div style="margin-top: 40px; display: flex; justify-content: space-between;">
        <a href="index.php?step=3" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Indietro
        </a>
        <button type="submit" class="btn btn-primary">
            Crea Admin <i class="fas fa-arrow-right"></i>
        </button>
    </div>
</form>

<?php renderFooter(); ?>
