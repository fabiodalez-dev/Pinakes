/**
 * emeroteca-scan.js — Kardex barcode lookup + label batch selection.
 *
 * Two small behaviours of the "fascicoli" admin page, deliberately kept out
 * of the markup so the view stays declarative:
 *
 *  1. SCAN. The camera scanner is the CORE one (copy-scanner.bundle.js,
 *     self-hosted zxing-wasm from copy-tracking #238): this file adds no
 *     decoding library of its own, it only reacts to the value the core
 *     scanner writes into the target input — `[data-copy-scan]` buttons are
 *     wired by the bundle itself. Typing or pasting a code works exactly the
 *     same way, so the page is fully usable without a camera.
 *     The decoded code is sent to the read-only JSON lookup; when it
 *     resolves to an issue the Kardex can close, the EXISTING
 *     `receive_issue` form is revealed with its id filled in. No receiving
 *     logic lives here.
 *
 *  2. LABELS. A "select all" checkbox for the per-issue label selection.
 *
 * Configuration arrives as window.emerotecaScan (emitted by the view with
 * json_encode + HEX flags). All rendering uses textContent: nothing coming
 * back from the server is ever interpolated as HTML.
 */
(function () {
    'use strict';

    function config() {
        return (typeof window !== 'undefined' && window.emerotecaScan) || null;
    }

    function t(key, fallback) {
        var cfg = config();
        var dict = (cfg && cfg.i18n) || {};
        return dict[key] || fallback;
    }

    // ── Label selection ──────────────────────────────────────────────

    function initSelectAll() {
        var toggles = document.querySelectorAll('[data-emt-select-all]');
        Array.prototype.forEach.call(toggles, function (toggle) {
            if (toggle.dataset.emtBound === '1') {
                return;
            }
            toggle.dataset.emtBound = '1';
            var formId = toggle.getAttribute('data-emt-select-all');
            toggle.addEventListener('change', function () {
                var boxes = document.querySelectorAll(
                    'input[type="checkbox"][name="ids[]"][form="' + formId + '"]'
                );
                Array.prototype.forEach.call(boxes, function (box) {
                    box.checked = toggle.checked;
                });
            });
        });
    }

    // ── Scan lookup ──────────────────────────────────────────────────

    /**
     * Accept only a same-origin ABSOLUTE PATH as a link target.
     *
     * The server builds these with url(), which always returns a path
     * beginning with a single '/'. Assigning whatever JSON came back straight
     * to .href would honour 'javascript:', 'data:' and protocol-relative
     * '//evil.tld' URLs, so anything that is not "one slash then a path" is
     * dropped and the link simply stays hidden.
     * Returns '' when the value is not usable.
     */
    function safePath(value) {
        if (typeof value !== 'string') {
            return '';
        }
        var path = value.trim();
        if (path.charAt(0) !== '/') {
            return '';
        }
        // '//host' is protocol-relative, '/\host' is treated as such by browsers.
        if (path.charAt(1) === '/' || path.charAt(1) === '\\') {
            return '';
        }
        // A control character in a URL is never legitimate here.
        if (/[\u0000-\u001F\u007F]/.test(path)) {
            return '';
        }
        return path;
    }

    function showLink(openLink, rawUrl) {
        if (!openLink) {
            return;
        }
        var href = safePath(rawUrl);
        if (href === '') {
            return;
        }
        openLink.href = href;
        openLink.hidden = false;
    }

    function setStatus(box, message, tone) {
        box.textContent = message;
        box.classList.remove('is-error', 'is-ok');
        if (tone) {
            box.classList.add(tone === 'error' ? 'is-error' : 'is-ok');
        }
        box.hidden = message === '';
    }

    function initScan() {
        var cfg = config();
        var input = document.getElementById('emt-scan-code');
        var button = document.getElementById('emt-scan-lookup');
        var status = document.getElementById('emt-scan-result');
        var receiveForm = document.getElementById('emt-scan-receive');
        if (!cfg || !input || !button || !status || !receiveForm) {
            return;
        }
        if (button.dataset.emtBound === '1') {
            return;
        }
        button.dataset.emtBound = '1';

        var receiveId = receiveForm.querySelector('input[name="fascicolo_id"]');
        var openLink = document.getElementById('emt-scan-open');

        var requestSeq = 0;

        function reset() {
            receiveForm.hidden = true;
            if (receiveId) {
                receiveId.value = '';
            }
            if (openLink) {
                openLink.hidden = true;
                openLink.removeAttribute('href');
            }
        }

        function lookup() {
            var code = (input.value || '').trim();
            reset();
            var seq = ++requestSeq;
            if (code === '') {
                setStatus(status, t('empty', 'Inserisci o scansiona un codice a barre.'), 'error');
                return;
            }
            setStatus(status, t('searching', 'Ricerca in corso...'), null);

            var url = cfg.lookupUrl
                + (cfg.lookupUrl.indexOf('?') === -1 ? '?' : '&')
                + 'code=' + encodeURIComponent(code)
                + '&testata=' + encodeURIComponent(String(cfg.testataId || 0));

            fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (seq !== requestSeq) {
                        return;
                    }
                    if (!data || !data.found) {
                        setStatus(status, (data && data.message) || t('notFound', 'Nessuna corrispondenza.'), 'error');
                        return;
                    }
                    // A code matching several issues is NOT a result: the
                    // receive form stays closed and the operator is pointed at
                    // the title, so nothing is received on a guessed issue.
                    if (data.match === 'ambiguous') {
                        setStatus(status, data.message || t('ambiguous', 'Codice ambiguo: risolvi dalla testata.'), 'error');
                        if (data.title) {
                            showLink(openLink, data.title.url);
                        }
                        return;
                    }
                    setStatus(status, data.message || '', 'ok');
                    if (data.match === 'issue' && data.issue) {
                        showLink(openLink, data.issue.url);
                        if (data.action === 'receive' && receiveId) {
                            receiveId.value = String(data.issue.id);
                            receiveForm.hidden = false;
                        }
                    } else if (data.match === 'title' && data.title) {
                        showLink(openLink, data.title.url);
                    }
                })
                .catch(function () {
                    if (seq !== requestSeq) {
                        return;
                    }
                    setStatus(status, t('error', 'Errore durante la ricerca del codice.'), 'error');
                });
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();
            lookup();
        });
        // Enter inside the field looks up instead of submitting the page.
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lookup();
            }
        });
        // The core scanner fills the input and dispatches 'change': a
        // successful decode therefore searches without a second tap.
        input.addEventListener('change', lookup);
        // Editing the input invalidates a previous result even before lookup.
        input.addEventListener('input', function () {
            requestSeq++;
            reset();
            setStatus(status, '', null);
        });
        receiveForm.addEventListener('submit', function (event) {
            if (receiveForm.hidden || !receiveId || receiveId.value === '') {
                event.preventDefault();
            }
        });
    }

    function init() {
        initSelectAll();
        initScan();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
