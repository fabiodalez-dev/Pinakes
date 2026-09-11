<?php
declare(strict_types=1);
$plugin=$GLOBALS['plugins']['emeroteca']??null;
if (!$plugin instanceof \EmerotecaPlugin) { return; }
$mode=$plugin->contributionService()->mode();
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<h1 class="text-3xl font-bold mb-6"><?= __('Emeroteca') ?></h1>
<?php require __DIR__.'/article-mode.php'; ?>
