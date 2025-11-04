<?php
/**
 * Step 1: Welcome & System Requirements
 */

// Check system requirements
$requirements = [
    'PHP Version >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO Extension' => extension_loaded('pdo'),
    'PDO MySQL Extension' => extension_loaded('pdo_mysql'),
    'MySQLi Extension' => extension_loaded('mysqli'),
    'Mbstring Extension' => extension_loaded('mbstring'),
    'JSON Extension' => extension_loaded('json'),
    'GD Extension' => extension_loaded('gd'),
    'Fileinfo Extension' => extension_loaded('fileinfo'),
];

// Check directory permissions
$directories = [
    'Root Directory' => is_writable($baseDir),
    'Uploads Directory' => is_dir($baseDir . '/uploads') && is_writable($baseDir . '/uploads'),
    'Storage Directory' => is_dir($baseDir . '/storage') && is_writable($baseDir . '/storage'),
    'Backups Directory' => is_dir($baseDir . '/backups') && is_writable($baseDir . '/backups'),
];

$allRequirementsMet = !in_array(false, array_merge($requirements, $directories), true);

renderHeader(1, 'Benvenuto');
?>

<h2 class="step-title">👋 Benvenuto nell'Installer</h2>
<p class="step-description">
    Questo installer ti guiderà attraverso la configurazione del Sistema Biblioteca.
    Prima di iniziare, verifichiamo che il tuo server soddisfi tutti i requisiti necessari.
</p>

<?php if ($allRequirementsMet): ?>
    <div class="alert alert-success">
        ✓ Tutti i requisiti sono soddisfatti! Puoi procedere con l'installazione.
    </div>
<?php else: ?>
    <div class="alert alert-error">
        ✗ Alcuni requisiti non sono soddisfatti. Risolvi i problemi prima di continuare.
    </div>
<?php endif; ?>

<h3 style="margin-top: 30px; margin-bottom: 15px; color: #2d3748;">Requisiti di Sistema</h3>
<ul class="requirements-list">
    <?php foreach ($requirements as $req => $met): ?>
        <li class="<?= $met ? 'met' : 'not-met' ?>">
            <span><?= htmlspecialchars($req) ?></span>
            <span class="requirement-status"><?= $met ? '✓' : '✗' ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<h3 style="margin-top: 30px; margin-bottom: 15px; color: #2d3748;">Permessi Directory</h3>
<ul class="requirements-list">
    <?php foreach ($directories as $dir => $writable): ?>
        <li class="<?= $writable ? 'met' : 'not-met' ?>">
            <span><?= htmlspecialchars($dir) ?></span>
            <span class="requirement-status"><?= $writable ? '✓ Scrivibile' : '✗ Non scrivibile' ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<div style="margin-top: 40px; text-align: center;">
    <?php if ($allRequirementsMet): ?>
        <form method="GET" action="index.php">
            <input type="hidden" name="step" value="2">
            <button type="submit" class="btn btn-primary" style="min-width: 200px;">
                Inizia Installazione <i class="fas fa-arrow-right"></i>
            </button>
        </form>
        <?php
        // Mark step 1 as completed
        completeStep(1);
        ?>
    <?php else: ?>
        <p style="color: #e53e3e; margin-bottom: 15px;">
            Risolvi i problemi indicati sopra e ricarica la pagina.
        </p>
        <button class="btn btn-secondary" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> Ricarica Pagina
        </button>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>
