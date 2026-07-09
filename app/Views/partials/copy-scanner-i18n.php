<?php
/**
 * Shared i18n strings for the camera barcode scanner (copy-scanner.bundle.js).
 * Emitted before the bundle is loaded so window.copyScannerI18n is available.
 * Included by both prestiti/index.php and prestiti/crea_prestito.php.
 */
?>
<script>
    window.copyScannerI18n = {
      title: <?= json_encode(__("Scansiona codice a barre"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      instruction: <?= json_encode(__("Inquadra il codice a barre nella cornice"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      starting: <?= json_encode(__("Avvio della fotocamera in corso..."), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      cancel: <?= json_encode(__("Annulla"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      permissionDenied: <?= json_encode(__("Impossibile accedere alla fotocamera. Controlla i permessi del browser."), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      noCamera: <?= json_encode(__("Nessuna fotocamera trovata su questo dispositivo."), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      unsupported: <?= json_encode(__("Questo browser non supporta l'accesso alla fotocamera."), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
      genericError: <?= json_encode(__("Impossibile avviare lo scanner."), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
    };
</script>
