<?php
$readerScriptVersion = (string) filemtime(__DIR__ . '/../assets/js/pdf-reader.js');
$readerScriptUrl = url('/plugins/digital-library/assets/js/pdf-reader.js?v=' . $readerScriptVersion);
?>
<script<?= csp_nonce_attr() ?> src="<?= \App\Support\HtmlHelper::e($readerScriptUrl) ?>" defer></script>
