<?php
/**
 * Step 7: Installation Complete
 */

// Finalize installation if not already done
if (!isset($_SESSION['installation_finalized'])) {
    try {
        // Load .env and connect to database
        $installer->loadEnvConfig();

        // Populate default settings
        $installer->populateDefaultSettings();

        // Install plugins from ZIP (open-library and z39-server)
        $installedPlugins = $installer->installPluginsFromZip();
        $_SESSION['installed_plugins'] = $installedPlugins;

        // Create .htaccess if missing
        $installer->createHtaccess();

        // Create lock file to prevent re-installation
        $installer->createLockFile();

        $_SESSION['installation_finalized'] = true;

    } catch (Exception $e) {
        $error = __("Errore durante la finalizzazione:") . " " . $e->getMessage();
    }
}

// Get admin info from session
$adminUser = $_SESSION['admin_user'] ?? null;
$appName = $_SESSION['app_settings']['name'] ?? 'Pinakes';

renderHeader(7, __('Installazione Completata'));
?>

<h2 class="step-title">🎉 <?= __("Installazione Completata!") ?></h2>
<p class="step-description">
    <?= __("Pinakes è stato installato con successo ed è pronto per essere utilizzato.") ?>
</p>

<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <strong><?= __("Complimenti!") ?></strong> <?= __("L'installazione è stata completata senza errori.") ?>
</div>

<?php
// Check if vendor/ directory exists (critical for app to run)
$vendorExists = file_exists($baseDir . '/vendor/autoload.php');
?>

<?php if (!$vendorExists): ?>
    <!-- CRITICAL: Composer dependencies missing -->
    <div class="alert alert-error" style="margin-top: 20px; border: 3px solid #dc2626;">
        <h4 style="margin-bottom: 15px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong><?= __("⚠️ AZIONE RICHIESTA: Installazione Dipendenze PHP") ?></strong>
        </h4>
        <p style="font-size: 16px; margin-bottom: 15px;">
            <strong><?= __("L'applicazione NON può funzionare senza questo passaggio!") ?></strong><br>
            <?= __("Il database è stato installato, ma mancano le librerie PHP necessarie per eseguire l'applicazione.") ?>
        </p>
        <p style="margin-bottom: 15px;">
            <strong><?= __("Cosa fare:") ?></strong> <?= __("Devi eseguire") ?> <code
                style="background: #2d3748; color: #fff; padding: 3px 8px; border-radius: 4px;">composer install</code>
            <?= __("sul server tramite SSH.") ?>
        </p>

        <details open style="margin-top: 20px; padding: 15px; background: #2d3748; border-radius: 8px;">
            <summary style="cursor: pointer; color: #fff; font-weight: 600; font-size: 15px; margin-bottom: 15px;">
                <?= __("📋 Istruzioni SSH (Click per espandere/chiudere)") ?>
            </summary>
            <div style="color: #fff;">
                <p style="margin-bottom: 10px; color: #fbbf24;">
                    <strong><?= __("1. Collegati al server via SSH:") ?></strong>
                </p>
                <pre style="background: #1f2937; padding: 15px; border-radius: 5px; overflow-x: auto; margin-bottom: 15px;">ssh tuoutente@biblioteca.fabiodalez.it
        # <?= __("Oppure usa il terminale SSH del tuo hosting (cPanel, Plesk, etc.)") ?></pre>

                <p style="margin-bottom: 10px; color: #fbbf24;">
                    <strong><?= __("2. Vai nella directory dell'applicazione:") ?></strong>
                </p>
                <pre
                    style="background: #1f2937; padding: 15px; border-radius: 5px; overflow-x: auto; margin-bottom: 15px;">cd <?= htmlspecialchars($baseDir) ?></pre>

                <p style="margin-bottom: 10px; color: #fbbf24;">
                    <strong><?= __("3. Installa le dipendenze con Composer:") ?></strong>
                </p>
                <pre style="background: #1f2937; padding: 15px; border-radius: 5px; overflow-x: auto; margin-bottom: 15px;">composer install --no-dev --optimize-autoloader

        # <?= __("Se composer non è installato globalmente:") ?>
        php composer.phar install --no-dev --optimize-autoloader</pre>

                <p style="margin-bottom: 10px; color: #fbbf24;">
                    <strong><?= __("4. Verifica che le dipendenze siano state installate:") ?></strong>
                </p>
                <pre style="background: #1f2937; padding: 15px; border-radius: 5px; overflow-x: auto; margin-bottom: 15px;">ls -la vendor/
        # <?= __("Output atteso: cartella vendor/ con sottocartelle (slim, monolog, etc.)") ?></pre>

                <p style="margin-top: 15px; color: #10b981;">
                    ✅ <strong><?= __("Fatto!") ?></strong>
                    <?= __("Ora puoi ricaricare questa pagina - il warning sparirà se tutto è OK.") ?>
                </p>
            </div>
        </details>

        <div
            style="margin-top: 15px; padding: 15px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;">
            <p style="margin: 0; color: #92400e;">
                <strong><?= __("💡 Non hai accesso SSH?") ?></strong><br>
                <?= __("Contatta il tuo provider di hosting e chiedi di eseguire") ?> <code>composer install --no-dev</code>
                <?= __("nella directory dell'applicazione.") ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <!-- Composer dependencies OK -->
    <div class="alert alert-success" style="margin-top: 20px; border-left: 4px solid #16a34a;">
        <i class="fas fa-check-circle"></i>
        <strong><?= __("✅ Dipendenze PHP installate correttamente") ?></strong><br>
        <small style="opacity: 0.8;"><?= __("La cartella vendor/ esiste e contiene le librerie necessarie.") ?></small>
    </div>
<?php endif; ?>

<?php
$triggerWarnings = $_SESSION['trigger_warnings'] ?? [];
if (!empty($triggerWarnings)):
    ?>
    <div class="alert alert-warning" style="margin-top: 20px;">
        <h4 style="margin-bottom: 10px;"><i class="fas fa-exclamation-triangle"></i>
            <?= __("Attenzione: Azione Manuale Richiesta") ?></h4>
        <p><?= __("L'utente del database non ha i permessi per creare i TRIGGER. L'installazione è stata completata, ma per garantire la piena integrità dei dati è necessario installarli manualmente.") ?></p>
        <p style="margin-top: 10px;"><strong><?= __("Azione richiesta:") ?></strong>
            <?= __("Chiedi al tuo amministratore di database di eseguire i comandi contenuti nel file") ?> <code>installer/database/triggers.sql</code>.</p>
    </div>
<?php endif; ?>
<h3 style="margin-top: 30px; margin-bottom: 15px; color: #2d3748;"><?= __("Riepilogo Installazione") ?></h3>
<ul class="summary-list">
    <li><i class="fas fa-check-circle"></i> <?= __("Database installato (40 tabelle)") ?></li>
    <li><i class="fas fa-check-circle"></i> <?= __("Trigger database configurati") ?></li>
    <li><i class="fas fa-check-circle"></i> <?= __("Dati essenziali caricati") ?></li>
    <?php if ($adminUser): ?>
        <li><i class="fas fa-check-circle"></i> <?= __("Utente admin creato:") ?>
            <strong><?= htmlspecialchars($adminUser['email']) ?></strong>
        </li>
    <?php endif; ?>
    <li><i class="fas fa-check-circle"></i> <?= __("Applicazione configurata:") ?> <strong><?= htmlspecialchars($appName) ?></strong>
    </li>
    <li><i class="fas fa-check-circle"></i> <?= __("Email configurata") ?></li>
    <?php
    $installedPlugins = $_SESSION['installed_plugins'] ?? [];
    if (!empty($installedPlugins)):
        $successfulPlugins = array_filter($installedPlugins, function ($p) {
            return $p['status'] === 'installed_and_activated';
        });
        ?>
        <li><i class="fas fa-check-circle"></i> <?= __("Plugin installati e attivati:") ?>
            <strong><?= count($successfulPlugins) ?></strong>
            <?php if (!empty($successfulPlugins)): ?>
                (<?= implode(', ', array_column($successfulPlugins, 'name')) ?>)
            <?php endif; ?>
        </li>
    <?php endif; ?>
    <li><i class="fas fa-check-circle"></i> <?= __("File .htaccess creato") ?></li>
    <li><i class="fas fa-check-circle"></i> <?= __("Lock file creato (installazione protetta)") ?></li>
</ul>

<?php if ($adminUser): ?>
    <div class="alert alert-info" style="margin-top: 30px;">
        <i class="fas fa-info-circle"></i>
        <strong><?= __("Credenziali Admin:") ?></strong><br>
        <?= __("Email:") ?> <strong><?= htmlspecialchars($adminUser['email']) ?></strong><br>
        <?= __("Codice Tessera:") ?> <strong><?= htmlspecialchars($adminUser['codice_tessera']) ?></strong><br>
        <small style="opacity: 0.8;"><?= __("Conserva queste informazioni in un luogo sicuro!") ?></small>
    </div>
<?php endif; ?>

<h3 style="margin-top: 40px; margin-bottom: 15px; color: #2d3748;"><?= __("Prossimi Passi") ?></h3>
<ol style="list-style: decimal; margin-left: 20px; color: #4a5568;">
    <li style="margin-bottom: 10px;"><?= __("Accedi all'area admin con le credenziali sopra indicate") ?></li>
    <li style="margin-bottom: 10px;"><?= __("Configura le impostazioni rimanenti (privacy, contatti, etc.)") ?></li>
    <li style="margin-bottom: 10px;"><?= __("Aggiungi scaffali e mensole per la tua biblioteca") ?></li>
    <li style="margin-bottom: 10px;"><?= __("Inizia ad aggiungere libri al catalogo") ?></li>
    <li style="margin-bottom: 10px;"><?= __("Invita gli utenti a registrarsi") ?></li>
</ol>

<div style="margin-top: 40px; padding: 20px; background: #f7fafc; border-radius: 8px; border-left: 4px solid #16a34a;">
    <h4 style="margin-bottom: 10px; color: #2d3748;"><?= __("🔒 Sicurezza Importante") ?></h4>
    <p style="color: #4a5568; margin-bottom: 15px;">
        <?= __("Per motivi di sicurezza, è") ?> <strong><?= __("altamente consigliato") ?></strong> eliminare la
        cartella <code>installer/</code> <?= __("dopo aver completato l'installazione.") ?>
    </p>
    <?php if (!$vendorExists): ?>
        <div class="alert alert-warning" style="margin-bottom: 15px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong><?= __("Non puoi eliminare l'installer finché non completi l'installazione delle dipendenze PHP.") ?></strong><br>
            <small><?= __("Esegui") ?> <code>composer install</code> <?= __("prima di procedere.") ?></small>
        </div>
        <button type="button" class="btn btn-secondary" disabled style="opacity: 0.5; cursor: not-allowed;">
            <i class="fas fa-trash"></i> <?= __("Elimina Installer (Richiede Composer)") ?>
        </button>
    <?php else: ?>
        <form method="POST" action="/installer/?step=7&action=delete_installer&force"
            onsubmit="return confirmDeleteInstaller();" style="margin-top: 10px;">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-trash"></i> <?= __("Elimina Installer") ?>
            </button>
            <small style="display: block; margin-top: 8px; color: #718096;">
                <?= __("Questa azione rimuoverà completamente la cartella installer per sicurezza.") ?>
            </small>
        </form>
    <?php endif; ?>
</div>

<?php
// Handle installer deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_installer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if vendor/ exists before attempting deletion
    if (!$vendorExists) {
        echo '<div class="alert alert-error" style="margin-top: 20px;">
            <i class="fas fa-exclamation-triangle"></i>
            ' . __("Impossibile eliminare l'installer: devi prima installare le dipendenze PHP con") . ' <code>composer install</code>.
        </div>';
    } else {
        try {
            if ($installer->deleteInstaller()) {
                echo '<div class="alert alert-success" style="margin-top: 20px;">
                    <i class="fas fa-check-circle"></i> ' . __("Installer eliminato con successo!") . '
                </div>';
                echo '<script>setTimeout(() => { window.location.href = "/"; }, 2000);</script>';
            }
        } catch (Exception $e) {
            echo '<div class="alert alert-error" style="margin-top: 20px;">
                ' . __("Impossibile eliminare l'installer:") . ' ' . htmlspecialchars($e->getMessage()) . '
            </div>';
        }
    }
}
?>

<div style="margin-top: 40px; text-align: center;">
    <a href="/" class="btn btn-primary" style="min-width: 250px; font-size: 16px;">
        <i class="fas fa-sign-in-alt"></i> <?= __("Vai all'Applicazione") ?>
    </a>
</div>

<div style="margin-top: 30px; text-align: center; padding: 20px; background: #f7fafc; border-radius: 8px;">
    <p style="color: #718096; margin-bottom: 10px;">
        <?= __("Grazie per aver scelto Pinakes!") ?>
    </p>
    <p style="color: #a0aec0; font-size: 14px;">
        <?= __("Versione Installer:") ?> 1.0 | Data: <?= date('Y-m-d') ?>
    </p>
</div>

<script>
window.installerTranslations = {
    confirmDelete: <?= json_encode(__('Sei sicuro di voler eliminare la cartella installer? Questa azione non può essere annullata.')) ?>,
    passwordMismatch: <?= json_encode(__('Le password non corrispondono')) ?>,
};
</script>

<?php renderFooter(); ?>