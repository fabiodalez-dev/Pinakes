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

        // Create .htaccess if missing
        $installer->createHtaccess();

        // Create lock file to prevent re-installation
        $installer->createLockFile();

        $_SESSION['installation_finalized'] = true;

    } catch (Exception $e) {
        $error = "Errore durante la finalizzazione: " . $e->getMessage();
    }
}

// Get admin info from session
$adminUser = $_SESSION['admin_user'] ?? null;
$appName = $_SESSION['app_settings']['name'] ?? 'Pinakes';

renderHeader(7, 'Installazione Completata');
?>

<h2 class="step-title">🎉 Installazione Completata!</h2>
<p class="step-description">
    Il Sistema Biblioteca è stato installato con successo e è pronto per essere utilizzato.
</p>

    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <strong>Complimenti!</strong> L'installazione è stata completata senza errori.
    </div>

    <?php
    $triggerWarnings = $_SESSION['trigger_warnings'] ?? [];
    if (!empty($triggerWarnings)):
    ?>
        <div class="alert alert-warning" style="margin-top: 20px;">
            <h4 style="margin-bottom: 10px;"><i class="fas fa-exclamation-triangle"></i> Attenzione: Azione Manuale Richiesta</h4>
            <p>L'utente del database non ha i permessi per creare i TRIGGER. L'installazione è stata completata, ma per garantire la piena integrità dei dati è necessario installarli manualmente.</p>
            <p style="margin-top: 10px;"><strong>Azione richiesta:</strong> Chiedi al tuo amministratore di database di eseguire i comandi contenuti nel file <code>installer/database/triggers.sql</code>.</p>
        </div>
    <?php endif; ?>
<h3 style="margin-top: 30px; margin-bottom: 15px; color: #2d3748;">Riepilogo Installazione</h3>
<ul class="summary-list">
    <li><i class="fas fa-check-circle"></i> Database installato (29 tabelle)</li>
    <li><i class="fas fa-check-circle"></i> Trigger database configurati</li>
    <li><i class="fas fa-check-circle"></i> Dati essenziali caricati</li>
    <?php if ($adminUser): ?>
    <li><i class="fas fa-check-circle"></i> Utente admin creato: <strong><?= htmlspecialchars($adminUser['email']) ?></strong></li>
    <?php endif; ?>
    <li><i class="fas fa-check-circle"></i> Applicazione configurata: <strong><?= htmlspecialchars($appName) ?></strong></li>
    <li><i class="fas fa-check-circle"></i> Email configurata</li>
    <li><i class="fas fa-check-circle"></i> File .htaccess creato</li>
    <li><i class="fas fa-check-circle"></i> Lock file creato (installazione protetta)</li>
</ul>

<?php if ($adminUser): ?>
<div class="alert alert-info" style="margin-top: 30px;">
    <i class="fas fa-info-circle"></i>
    <strong>Credenziali Admin:</strong><br>
    Email: <strong><?= htmlspecialchars($adminUser['email']) ?></strong><br>
    Codice Tessera: <strong><?= htmlspecialchars($adminUser['codice_tessera']) ?></strong><br>
    <small style="opacity: 0.8;">Conserva queste informazioni in un luogo sicuro!</small>
</div>
<?php endif; ?>

<h3 style="margin-top: 40px; margin-bottom: 15px; color: #2d3748;">Prossimi Passi</h3>
<ol style="list-style: decimal; margin-left: 20px; color: #4a5568;">
    <li style="margin-bottom: 10px;">Accedi all'area admin con le credenziali sopra indicate</li>
    <li style="margin-bottom: 10px;">Configura le impostazioni rimanenti (privacy, contatti, etc.)</li>
    <li style="margin-bottom: 10px;">Aggiungi scaffali e mensole per la tua biblioteca</li>
    <li style="margin-bottom: 10px;">Inizia ad aggiungere libri al catalogo</li>
    <li style="margin-bottom: 10px;">Invita gli utenti a registrarsi</li>
</ol>

<div style="margin-top: 40px; padding: 20px; background: #f7fafc; border-radius: 8px; border-left: 4px solid #16a34a;">
    <h4 style="margin-bottom: 10px; color: #2d3748;">🔒 Sicurezza Importante</h4>
    <p style="color: #4a5568; margin-bottom: 15px;">
        Per motivi di sicurezza, è <strong>altamente consigliato</strong> eliminare la cartella <code>installer/</code> dopo aver completato l'installazione.
    </p>
    <form method="POST" action="index.php?step=7&action=delete_installer" onsubmit="return confirmDeleteInstaller();" style="margin-top: 10px;">
        <button type="submit" class="btn btn-secondary">
            <i class="fas fa-trash"></i> Elimina Installer
        </button>
        <small style="display: block; margin-top: 8px; color: #718096;">
            Questa azione rimuoverà completamente la cartella installer per sicurezza.
        </small>
    </form>
</div>

<?php
// Handle installer deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_installer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($installer->deleteInstaller()) {
            echo '<div class="alert alert-success" style="margin-top: 20px;">
                <i class="fas fa-check-circle"></i> Installer eliminato con successo!
            </div>';
            echo '<script>setTimeout(() => { window.location.href = "/"; }, 2000);</script>';
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-error" style="margin-top: 20px;">
            Impossibile eliminare l\'installer: ' . htmlspecialchars($e->getMessage()) . '
        </div>';
    }
}
?>

<div style="margin-top: 40px; text-align: center;">
    <a href="/" class="btn btn-primary" style="min-width: 250px; font-size: 16px;">
        <i class="fas fa-sign-in-alt"></i> Vai all'Applicazione
    </a>
</div>

<div style="margin-top: 30px; text-align: center; padding: 20px; background: #f7fafc; border-radius: 8px;">
    <p style="color: #718096; margin-bottom: 10px;">
        Grazie per aver scelto il Sistema Biblioteca!
    </p>
    <p style="color: #a0aec0; font-size: 14px;">
        Versione Installer: 1.0 | Data: <?= date('Y-m-d') ?>
    </p>
</div>

<?php renderFooter(); ?>
