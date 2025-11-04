<?php
/**
 * Step 3: Database Installation
 */

$error = null;
$installing = false;
$debug = [];

// If already completed, redirect immediately ONLY if schema was actually imported
if (isset($_SESSION['installation_complete']) && isset($_SESSION['schema_imported']) && $_SESSION['schema_imported'] === true) {
    header('Location: index.php?step=4');
    exit;
}

// Reset the flag and start fresh installation
unset($_SESSION['installation_started']);
$installing = true;

// Start installation
if (true) {
    $_SESSION['installation_started'] = true;

    try {
        $debug[] = "Inizio installazione...";

        // Load .env configuration
        $debug[] = "Caricamento .env...";
        $installer->loadEnvConfig();
        $debug[] = ".env caricato OK";

        // Import database schema
        $debug[] = "Import schema in corso...";
        $installer->importSchema();
        $_SESSION['schema_imported'] = true;
        $debug[] = "Schema importato OK";

        // Import initial data (classificazione, generi, email_templates)
        $debug[] = "Import dati iniziali...";
        $installer->importData();
        $_SESSION['data_imported'] = true;
        $debug[] = "Dati iniziali importati OK";

        // Import triggers
        $debug[] = "Import trigger...";
        $installer->importTriggers();
        $_SESSION['trigger_warnings'] = $installer->getTriggerWarnings();
        $triggerWarnings = $_SESSION['trigger_warnings'];

        if (!empty($triggerWarnings)) {
            foreach ($triggerWarnings as $warning) {
                $debug[] = "AVVISO Trigger: " . $warning;
            }
        } else {
            $debug[] = "Trigger importati OK";
        }

        // Verify installation
        $debug[] = "Verifica installazione...";
        $installer->verifyInstallation();
        $debug[] = "Verifica completata OK";

        // Mark step as completed
        completeStep(3);

        $_SESSION['installation_complete'] = true;
        $_SESSION['debug_log'] = $debug;

        // Redirect to next step
        header('Location: index.php?step=4');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
        $debug[] = "ERRORE: " . $e->getMessage();
        $debug[] = "File: " . $e->getFile() . " linea " . $e->getLine();
        $_SESSION['debug_log'] = $debug;
        unset($_SESSION['installation_started']); // Allow retry
        $installing = false;
    } catch (Error $e) {
        $error = "Fatal Error: " . $e->getMessage();
        $debug[] = "FATAL ERROR: " . $e->getMessage();
        $debug[] = "File: " . $e->getFile() . " linea " . $e->getLine();
        $_SESSION['debug_log'] = $debug;
        unset($_SESSION['installation_started']); // Allow retry
        $installing = false;
    }
}

// Get debug log from session
if (isset($_SESSION['debug_log'])) {
    $debug = $_SESSION['debug_log'];
}

renderHeader(3, 'Installazione Database');
?>

<h2 class="step-title">⚙️ Installazione Database</h2>
<p class="step-description">
    Installazione delle tabelle del database e configurazione iniziale...
</p>

<?php if ($error): ?>
    <div class="alert alert-error">
        <strong>Errore durante l'installazione:</strong><br>
        <?= htmlspecialchars($error) ?>
    </div>

    <?php if (!empty($debug)): ?>
    <div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 6px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto;">
        <strong>🐛 Debug Log:</strong><br><br>
        <?php foreach ($debug as $line): ?>
            <?= htmlspecialchars($line) ?><br>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p style="margin-top: 20px;">
        <a href="index.php?step=2" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Torna alla Configurazione Database
        </a>
        <a href="index.php?step=3" class="btn btn-primary" style="margin-left: 10px;">
            <i class="fas fa-redo"></i> Riprova
        </a>
    </p>
<?php else: ?>
    <div class="progress-bar">
        <div class="progress-bar-fill" id="install-progress" style="width: 0%;"></div>
    </div>

    <p style="text-align: center; margin-top: 30px; color: #6b7280;">
        <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><br>
        <span style="margin-top: 10px; display: block;">Installazione in corso...</span>
    </p>

    <script>
    // Animate progress bar and redirect
    let progress = 0;
    const progressBar = document.getElementById('install-progress');

    const interval = setInterval(() => {
        progress += 10;
        if (progress <= 100) {
            progressBar.style.width = progress + '%';
        } else {
            clearInterval(interval);
        }
    }, 200);
    </script>
<?php endif; ?>

<?php renderFooter(); ?>
