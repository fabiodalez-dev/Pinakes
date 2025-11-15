<?php
/**
 * Step 2: Database Configuration
 */

$error = null;
$success = null;

// Handle AJAX test connection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_connection') {
    header('Content-Type: application/json');

    try {
        $host = $_POST['host'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $database = $_POST['database'] ?? '';
        $port = (int)($_POST['port'] ?? 3306);
        $socket = $_POST['socket'] ?? '';

        if ($validator->validateDatabaseConnection($host, $username, $password, $database, $port, $socket)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $validator->getFirstError()]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => __('Errore:') . ' ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['success' => false, 'error' => __('Fatal Error:') . ' ' . $e->getMessage()]);
    }
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $host = $validator->sanitize($_POST['db_host'] ?? '');
    $username = $validator->sanitize($_POST['db_username'] ?? '');
    $password = $_POST['db_password'] ?? ''; // Don't sanitize password
    $database = $validator->sanitize($_POST['db_database'] ?? '');
    $port = (int)($_POST['db_port'] ?? 3306);
    $socket = $validator->sanitize($_POST['db_socket'] ?? '');

    // Validate all fields
    $valid = true;
    if (!$validator->validateRequired($host, 'Host')) $valid = false;
    if (!$validator->validateRequired($username, 'Username')) $valid = false;
    if (!$validator->validateRequired($database, 'Database')) $valid = false;

    if ($valid && $validator->validateDatabaseConnection($host, $username, $password, $database, $port, $socket)) {
        // Auto-detect if we should use TCP instead of socket
        // This is needed on macOS Sequoia and other systems with socket restrictions
        $finalHost = $host;
        if ($host === 'localhost' && empty($socket)) {
            // Test if socket connection works with mysqli
            try {
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $testConn = @new mysqli('localhost', $username, $password, $database, $port);
                if ($testConn->connect_errno) {
                    // Socket failed, use TCP
                    $finalHost = '127.0.0.1';
                }
                if ($testConn instanceof mysqli) {
                    $testConn->close();
                }
            } catch (Exception $e) {
                // Socket failed, use TCP
                $finalHost = '127.0.0.1';
            }
        }

        // Get selected language from session (default to Italian if not set)
        $locale = $_SESSION['app_locale'] ?? 'it';

        // Create .env file with language setting
        if ($installer->createEnvFile($finalHost, $username, $password, $database, $port, $socket, $locale)) {
            $_SESSION['db_config'] = compact('finalHost', 'username', 'database', 'port', 'socket');
            completeStep(2);
            header('Location: index.php?step=3');
            exit;
        } else {
            $error = __("Impossibile creare il file .env. Verifica i permessi.");
        }
    } else {
        $error = $validator->getFirstError();
    }
}

// Default values
$dbHost = $_POST['db_host'] ?? 'localhost';
$dbUsername = $_POST['db_username'] ?? '';
$dbPassword = $_POST['db_password'] ?? '';
$dbDatabase = $_POST['db_database'] ?? '';
$dbPort = $_POST['db_port'] ?? 3306;
$dbSocket = $_POST['db_socket'] ?? '';

renderHeader(2, __('Configurazione Database'));
?>

<h2 class="step-title">🗄️ <?= __("Configurazione Database") ?></h2>
<p class="step-description">
    <?= __("Inserisci le credenziali del tuo database MySQL. Assicurati che il database sia già stato creato e sia vuoto.") ?>
</p>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST" action="index.php?step=2">
    <div class="form-group">
        <label class="form-label"><?= __("Host Database") ?> *</label>
        <input type="text" id="db_host" name="db_host" class="form-input" value="<?= htmlspecialchars($dbHost) ?>" required>
        <small style="color: #718096;"><?= __("Usa 'localhost' (raccomandato, rileva automaticamente TCP/socket). Puoi forzare '127.0.0.1' per TCP.") ?></small>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label"><?= __("Username") ?> *</label>
            <input type="text" id="db_username" name="db_username" class="form-input" value="<?= htmlspecialchars($dbUsername) ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label"><?= __("Password") ?></label>
            <input type="password" id="db_password" name="db_password" class="form-input" value="<?= htmlspecialchars($dbPassword) ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label"><?= __("Nome Database") ?> *</label>
            <input type="text" id="db_database" name="db_database" class="form-input" value="<?= htmlspecialchars($dbDatabase) ?>" required>
            <small style="color: #718096;"><?= __("Il database deve essere vuoto.") ?></small>
        </div>

        <div class="form-group">
            <label class="form-label"><?= __("Porta") ?></label>
            <input type="number" id="db_port" name="db_port" class="form-input" value="<?= htmlspecialchars((string)$dbPort) ?>" required>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label"><?= __("Socket MySQL (opzionale)") ?></label>
        <input type="text" id="db_socket" name="db_socket" class="form-input" value="<?= htmlspecialchars($dbSocket) ?>" placeholder="<?= __("Auto-detect se vuoto") ?>">
        <small style="color: #718096;"><?= __("Lascia vuoto per auto-rilevamento. Necessario solo su macOS/Linux con socket personalizzati.") ?></small>
    </div>

    <div style="margin-top: 30px;">
        <button type="button" id="test-connection-btn" class="btn btn-secondary" onclick="testDatabaseConnection()">
            <i class="fas fa-plug"></i> <?= __("Test Connessione") ?>
        </button>

        <div id="connection-result" style="display: none; margin-top: 15px;"></div>
    </div>

    <div style="margin-top: 30px; text-align: right;">
        <button type="submit" id="continue-btn" class="btn btn-primary" disabled>
            <?= __("Continua") ?> <i class="fas fa-arrow-right"></i>
        </button>
    </div>
</form>

<script>
window.installerTranslations = {
    testing: <?= json_encode(__('Verifica in corso...')) ?>,
    testSuccess: <?= json_encode(__('✓ Connessione riuscita! Database è vuoto e pronto per l\'installazione.')) ?>,
    testFailure: <?= json_encode(__('Connessione fallita')) ?>,
    errorPrefix: <?= json_encode(__('Errore di connessione:')) ?>,
    testButton: <?= json_encode(__('Test Connessione')) ?>,
};
</script>
<script>
// Enable continue button after successful connection test
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const continueBtn = document.getElementById('continue-btn');

    // If all fields are filled, enable test button
    const inputs = form.querySelectorAll('input[required]');
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            let allFilled = true;
            inputs.forEach(i => {
                if (!i.value) allFilled = false;
            });
        });
    });
});
</script>

<?php renderFooter(); ?>
