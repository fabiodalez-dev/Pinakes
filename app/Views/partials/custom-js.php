<?php

/**
 * Custom JavaScript from Settings > Advanced, granular by cookie category:
 * the essential script always runs; analytics and marketing are injected only
 * once the visitor has consented to that category (cookie banner). Shared by
 * the public layout and the reader's account pages.
 */

use App\Support\ConfigStore;
use App\Support\ContentSanitizer;

?>
    <?php
    // Load custom JavaScript from settings (granular by cookie category)
    $customJsEssential = ConfigStore::get('advanced.custom_js_essential', '');
    $customJsEssential = is_string($customJsEssential) ? ContentSanitizer::normalizeExternalAssets($customJsEssential) : $customJsEssential;

    $customJsAnalytics = ConfigStore::get('advanced.custom_js_analytics', '');
    $customJsAnalytics = is_string($customJsAnalytics) ? ContentSanitizer::normalizeExternalAssets($customJsAnalytics) : $customJsAnalytics;

    $customJsMarketing = ConfigStore::get('advanced.custom_js_marketing', '');
    $customJsMarketing = is_string($customJsMarketing) ? ContentSanitizer::normalizeExternalAssets($customJsMarketing) : $customJsMarketing;

    // JavaScript Essenziali: sempre caricati
    if (!empty($customJsEssential)):
        ?>
        <script id="custom-js-essential">
            <?= $customJsEssential ?>
        </script>
    <?php endif; ?>

    <?php
    // JavaScript Analitici e Marketing: caricati solo con consenso
    // Preparazione script per caricamento condizionato
    if (!empty($customJsAnalytics) || !empty($customJsMarketing)):
        ?>
        <script id="custom-js-loader">
                (function () {
                    'use strict';

                    // The CSP allows inline scripts by nonce only: an injected
                    // <script> carries this loader's nonce, or it never runs.
                    const cspNonce = (document.currentScript && document.currentScript.nonce) || '';

                    // Script analytics
                    const analyticsScript = <?= json_encode($customJsAnalytics, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

                    // Script marketing
                    const marketingScript = <?= json_encode($customJsMarketing, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

                    // Funzione per iniettare script
                    function injectScript(scriptContent, id) {
                        if (!scriptContent || document.getElementById(id)) {
                            return; // Skip se vuoto o già iniettato
                        }

                        // Verifica che il contenuto sia JavaScript valido (non HTML)
                        if (scriptContent.trim().startsWith('<') || scriptContent.includes('<iframe') || scriptContent.includes('<script')) {
                            console.warn('Custom script contains HTML tags and will be skipped. Use JavaScript code only.', id);
                            return;
                        }

                        try {
                            const script = document.createElement('script');
                            script.id = id;
                            if (cspNonce) {
                                script.nonce = cspNonce;
                            }
                            script.textContent = scriptContent;
                            document.head.appendChild(script);
                        } catch (error) {
                            console.error('Failed to inject custom script:', id, error);
                        }
                    }

                    // Funzione per controllare consenso e caricare script
                    function loadCustomScripts() {
                        if (!window.CookieControl || !window.CookieControl.getCategoryConsent) {
                            return; // Cookie Control non ancora pronto
                        }

                        // Carica analytics se consenso granted
                        if (analyticsScript && window.CookieControl.getCategoryConsent('analytics')) {
                            injectScript(analyticsScript, 'custom-js-analytics');
                        }

                        // Carica marketing se consenso granted
                        if (marketingScript && window.CookieControl.getCategoryConsent('marketing')) {
                            injectScript(marketingScript, 'custom-js-marketing');
                        }
                    }

                    // Prova a caricare al DOMContentLoaded
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function () {
                            setTimeout(loadCustomScripts, 200);
                        });
                    } else {
                        setTimeout(loadCustomScripts, 200);
                    }

                    // Ascolta cambiamenti consenso
                    window.addEventListener('silktideConsentChanged', function () {
                        setTimeout(loadCustomScripts, 100);
                    });

                    // Retry per i primi 3 secondi (in caso Cookie Control si carica lentamente)
                    let attempts = 0;
                    const retryInterval = setInterval(function () {
                        attempts++;
                        loadCustomScripts();

                        if (attempts >= 6 || (window.CookieControl && window.CookieControl.getCategoryConsent)) {
                            clearInterval(retryInterval);
                        }
                    }, 500);
                })();
        </script>
    <?php endif; ?>
