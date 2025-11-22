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

// Auto-fix permissions if requested
$fixAttempted = false;
$fixResults = [];

if (isset($_POST['fix_permissions'])) {
    $fixAttempted = true;
    $dirsToFix = [
        'storage' => $baseDir . '/storage',
        'storage/logs' => $baseDir . '/storage/logs',
        'storage/tmp' => $baseDir . '/storage/tmp',
        'storage/uploads' => $baseDir . '/storage/uploads',
        'storage/backups' => $baseDir . '/storage/backups',
        'public/uploads' => $baseDir . '/public/uploads',
    ];

    foreach ($dirsToFix as $name => $path) {
        if (!is_dir($path)) {
            // Try to create directory
            if (@mkdir($path, 0777, true)) {
                $fixResults[$name] = 'created';
            } else {
                $fixResults[$name] = 'failed_create';
            }
        } elseif (!is_writable($path)) {
            // Try to fix permissions
            if (@chmod($path, 0777)) {
                $fixResults[$name] = 'fixed';
            } else {
                $fixResults[$name] = 'failed_chmod';
            }
        } else {
            $fixResults[$name] = 'already_ok';
        }
    }
}

// Check directory permissions
// Ensure public uploads directory exists before checking permissions
$publicUploadsPath = $baseDir . '/public/uploads';
if (!is_dir($publicUploadsPath)) {
    @mkdir($publicUploadsPath, 0777, true);
    // Create .htaccess to disable script execution
    @file_put_contents($publicUploadsPath . '/.htaccess', "php_flag engine off\n");
}

$directories = [
    'Root Directory' => is_writable($baseDir),
    'Storage Directory' => is_dir($baseDir . '/storage') && is_writable($baseDir . '/storage'),
    'Storage/Uploads Directory' => is_dir($baseDir . '/storage/uploads') && is_writable($baseDir . '/storage/uploads'),
    'Storage/Backups Directory' => is_dir($baseDir . '/storage/backups') && is_writable($baseDir . '/storage/backups'),
    __('Directory Upload Pubblici') => is_dir($baseDir . '/public/uploads') && is_writable($baseDir . '/public/uploads'),
];

$allRequirementsMet = !in_array(false, array_merge($requirements, $directories), true);

renderHeader(1, __('Benvenuto'));
?>

<h2 class="step-title">👋 <?= __("Benvenuto nell'Installer") ?></h2>
<p class="step-description">
    <?= __("Questo installer ti guiderà attraverso la configurazione di Pinakes.") ?>
    <?= __("Prima di iniziare, verifichiamo che il tuo server soddisfi tutti i requisiti necessari.") ?>
</p>

<?php if ($fixAttempted): ?>
    <?php
    $successCount = count(array_filter($fixResults, fn($r) => in_array($r, ['fixed', 'created', 'already_ok'])));
    $failCount = count(array_filter($fixResults, fn($r) => in_array($r, ['failed_chmod', 'failed_create'])));
    ?>
    <?php if ($failCount === 0): ?>
        <div class="alert alert-success">
            ✓ <?= __("Permessi corretti con successo!") ?> (<?= $successCount ?>/<?= count($fixResults) ?>
            <?= __("directory") ?>)
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            ⚠ <?= __("Correzione parziale:") ?>         <?= $successCount ?>         <?= __("OK") ?>, <?= $failCount ?>         <?= __("fallite.") ?>
            <br><small><?= __("Vedi istruzioni sotto per correggere manualmente.") ?></small>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($allRequirementsMet): ?>
    <div class="alert alert-success">
        ✓ <?= __("Tutti i requisiti sono soddisfatti! Puoi procedere con l'installazione.") ?>
    </div>
<?php else: ?>
    <div class="alert alert-error">
        ✗ <?= __("Alcuni requisiti non sono soddisfatti. Risolvi i problemi prima di continuare.") ?>
    </div>
<?php endif; ?>

<h3 style="margin-top: 30px; margin-bottom: 15px; color: #2d3748;"><?= __("Requisiti di Sistema") ?></h3>
<ul class="requirements-list">
    <?php foreach ($requirements as $req => $met): ?>
        <li class="<?= $met ? 'met' : 'not-met' ?>">
            <span><?= htmlspecialchars($req) ?></span>
            <span class="requirement-status"><?= $met ? '✓' : '✗' ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<h3 style="margin-top: 30px; margin-bottom: 15px; color: #2d3748;"><?= __("Permessi Directory") ?></h3>
<ul class="requirements-list">
    <?php foreach ($directories as $dir => $writable): ?>
        <li class="<?= $writable ? 'met' : 'not-met' ?>">
            <span><?= htmlspecialchars($dir) ?></span>
            <span class="requirement-status"><?= $writable ? '✓ ' . __("Scrivibile") : '✗ ' . __("Non scrivibile") ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<!-- Auto-fix permissions button -->
<?php if (!$allRequirementsMet && !empty(array_filter($directories, fn($w) => !$w))): ?>
    <div style="margin-top: 30px; padding: 20px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 8px;">
        <h4 style="margin-top: 0; color: #856404;">🔧 <?= __("Correzione Automatica Permessi") ?></h4>
        <p style="color: #856404; margin-bottom: 15px;">
            <?= __("Puoi tentare di correggere automaticamente i permessi delle directory cliccando il pulsante sotto.") ?>
            <?= __("Questo funziona se PHP ha i privilegi sufficienti sul server.") ?>
        </p>
        <form method="POST" action="index.php?step=1" style="margin-bottom: 15px;">
            <button type="submit" name="fix_permissions" class="btn btn-warning" style="min-width: 250px;">
                <i class="fas fa-tools"></i> <?= __("Correggi Permessi Automaticamente") ?>
            </button>
        </form>

        <details style="margin-top: 15px;">
            <summary style="cursor: pointer; color: #856404; font-weight: 600;">
                📋 <?= __("Correzione Manuale via SSH (se automatica fallisce)") ?>
            </summary>
            <div
                style="margin-top: 15px; padding: 15px; background-color: #2d3748; color: #fff; border-radius: 5px; font-family: 'Courier New', monospace; font-size: 13px; overflow-x: auto;">
                <pre style="margin: 0; color: #fff;"># Collegati al server via SSH, poi esegui:
            cd <?= htmlspecialchars($baseDir) ?>

            # Metodo 1: Script automatico (raccomandato)
            ./bin/setup-permissions.sh

            # Metodo 2: Comandi manuali
            chmod 777 uploads backups storage
            chmod 777 storage/logs storage/tmp storage/uploads storage/backups
            chmod 777 public/uploads

            # Verifica permessi
            ls -la storage storage/uploads storage/backups public/uploads
            # Output atteso: drwxrwxrwx (777)</pre>
            </div>
        </details>
    </div>
<?php endif; ?>

<div style="margin-top: 40px; text-align: center;">
    <?php if ($allRequirementsMet): ?>
        <form method="GET" action="index.php">
            <input type="hidden" name="step" value="2">
            <button type="submit" class="btn btn-primary" style="min-width: 200px;">
                <?= __("Inizia Installazione") ?> <i class="fas fa-arrow-right"></i>
            </button>
        </form>
        <?php
        // Mark step 1 as completed
        completeStep(1);
        ?>
    <?php else: ?>
        <p style="color: #e53e3e; margin-bottom: 15px;">
            <?= __("Risolvi i problemi indicati sopra e ricarica la pagina.") ?>
        </p>
        <button class="btn btn-secondary" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> <?= __("Ricarica Pagina") ?>
        </button>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>