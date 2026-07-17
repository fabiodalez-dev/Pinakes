<?php

use App\Support\ConfigStore;
use App\Support\ContentSanitizer;

$customCss = ConfigStore::get('advanced.custom_header_css', '');
$customCss = is_string($customCss) ? ContentSanitizer::normalizeExternalAssets($customCss) : $customCss;
if (!empty($customCss)):
    ?>
    <style>
        <?= $customCss ?>
    </style>
<?php endif; ?>
