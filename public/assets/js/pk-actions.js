/*
 * Delegated replacements for inline on* handlers.
 *
 * The Content-Security-Policy sends script-src-attr 'none', so markup must not
 * carry onclick/onsubmit/onerror/... attributes. Views describe the behaviour
 * with data-pk-* attributes instead and this file (loaded synchronously in the
 * <head> of every layout) wires it up through event delegation, so it also
 * covers markup inserted later with innerHTML.
 *
 *   data-pk-action="verb"      click (or change on <select>/<input>/<textarea>)
 *                              runs a built-in or registered verb: fn(el, event)
 *   data-pk-on="input"         run the verb on that event instead (input, change,
 *                              click, keyup, focusin, mouseover, mouseout)
 *   data-pk-confirm="text"     on a <form>: ask before submitting;
 *                              on a link/button: ask before following/clicking
 *   data-pk-fallback-src="url" on an <img>: swap to this source if it fails
 *   data-pk-fallback-class="c" on an <img>: add this class if it fails
 *   data-pk-fallback-hide      on an <img>: hide it if it fails
 *
 * Page scripts add their own verbs with PinakesActions.register('verb', fn)
 * instead of exposing globals called from attributes. Arguments travel in
 * data-* attributes, never as code.
 */
(function () {
    'use strict';
    if (window.PinakesActions) {
        return;
    }

    var verbs = Object.create(null);

    function target(el, selector) {
        if (!selector || selector === 'self') return el;
        if (selector === 'next') return el.nextElementSibling;
        if (selector === 'parent') return el.parentElement;
        if (selector.indexOf('closest:') === 0) return el.closest(selector.slice(8));
        return document.querySelector(selector);
    }

    function submitForm(form, submitter) {
        if (!form) return;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter || undefined);
        } else {
            form.submit();
        }
    }

    verbs.back = function () { history.back(); };
    verbs.reload = function () { location.reload(); };
    verbs.print = function () { window.print(); };
    // Remove the element named by data-pk-target (default: the closest
    // [data-pk-dismissible], else the parent element).
    verbs.dismiss = function (el) {
        var t = el.dataset.pkTarget
            ? target(el, el.dataset.pkTarget)
            : (el.closest('[data-pk-dismissible]') || el.parentElement);
        if (t) t.remove();
    };
    // Submit the enclosing form, or the one named by data-pk-target.
    // data-pk-require-valid: only when the control passes its own validation.
    verbs['submit-form'] = function (el) {
        if (el.dataset.pkRequireValid !== undefined && typeof el.checkValidity === 'function' && !el.checkValidity()) {
            return;
        }
        var form = el.dataset.pkTarget ? target(el, el.dataset.pkTarget) : (el.form || el.closest('form'));
        submitForm(form);
    };
    // Toggle a class (data-pk-class, default "hidden") on data-pk-target
    // (default: next sibling). Keeps aria-expanded in sync when present.
    verbs.toggle = function (el) {
        var t = target(el, el.dataset.pkTarget || 'next');
        if (!t) return;
        var open = !t.classList.toggle(el.dataset.pkClass || 'hidden');
        if (el.hasAttribute('aria-expanded')) el.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    verbs.show = function (el) {
        var t = target(el, el.dataset.pkTarget);
        if (t) t.classList.remove(el.dataset.pkClass || 'hidden');
    };
    verbs.hide = function (el) {
        var t = el.dataset.pkTarget ? target(el, el.dataset.pkTarget) : el.closest('[data-pk-modal]');
        if (t) t.classList.add(el.dataset.pkClass || 'hidden');
    };
    // Copy data-pk-text, or the value/text of data-pk-target.
    verbs.copy = function (el) {
        var text = el.dataset.pkText;
        if (text === undefined) {
            var t = target(el, el.dataset.pkTarget);
            text = t ? (t.value !== undefined ? t.value : t.textContent) : '';
        }
        var done = function () {
            el.dispatchEvent(new CustomEvent('pk:copied', { bubbles: true }));
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () {});
        }
    };
    // Navigate to data-pk-href (used where a non-link element navigates).
    verbs.go = function (el) {
        if (el.dataset.pkHref) window.location.href = el.dataset.pkHref;
    };

    function ask(message) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            // Same look and translated buttons as every other dialog of the
            // app: swal-config.js defines SwalConfig as a global binding.
            var base = (typeof SwalConfig !== 'undefined' && SwalConfig) ? SwalConfig : {};
            var t = function (s) {
                var r = typeof window.__ === 'function' ? window.__(s) : s;
                return (typeof r === 'string' && r !== '') ? r : s;
            };
            var options = {};
            for (var k in base) {
                if (Object.prototype.hasOwnProperty.call(base, k)) options[k] = base[k];
            }
            options.text = message;
            options.icon = 'warning';
            options.showCancelButton = true;
            options.confirmButtonText = t('Conferma');
            options.cancelButtonText = t('Annulla');
            return window.Swal.fire(options).then(function (r) { return !!r.isConfirmed; });
        }
        // No SweetAlert yet (vendor.bundle.js is deferred, so an early click
        // can beat it). Answer on a later task, never a microtask: a microtask
        // still runs inside the original click/submit dispatch, where the
        // browser ignores both requestSubmit() and a nested click().
        return new Promise(function (resolve) {
            setTimeout(function () { resolve(window.confirm(message)); }, 0);
        });
    }

    function run(el, event) {
        var fn = verbs[el.dataset.pkAction];
        if (!fn) return;
        fn(el, event);
    }

    function isFieldControl(el) {
        return el.matches('select, textarea, input:not([type=button]):not([type=submit]):not([type=reset]):not([type=image])');
    }

    // Clicks: confirmation first, then the verb.
    document.addEventListener('click', function (event) {
        var confirmEl = event.target.closest('a[data-pk-confirm], button[data-pk-confirm], input[data-pk-confirm]');
        if (confirmEl && confirmEl.dataset.pkConfirmed !== '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            ask(confirmEl.dataset.pkConfirm).then(function (ok) {
                if (!ok) return;
                confirmEl.dataset.pkConfirmed = '1';
                try {
                    confirmEl.click();
                } finally {
                    delete confirmEl.dataset.pkConfirmed;
                }
            });
            return;
        }

        var el = event.target.closest('[data-pk-action]');
        if (!el || (el.dataset.pkOn && el.dataset.pkOn !== 'click')) return;
        if (!el.dataset.pkOn && isFieldControl(el)) return; // fields act on change
        if (!verbs[el.dataset.pkAction]) return;
        if (el.matches('a[href], button[type=submit], button:not([type]), input[type=submit]') && el.dataset.pkAllowDefault === undefined) {
            event.preventDefault();
        }
        run(el, event);
    });

    // Events other than click: fields default to "change"; anything else
    // opts in with data-pk-on.
    ['change', 'input', 'keyup', 'focusin', 'mouseover', 'mouseout'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            if (!(event.target instanceof Element)) return;
            var el = event.target.closest('[data-pk-action]');
            if (!el) return;
            var on = el.dataset.pkOn || (isFieldControl(el) ? 'change' : 'click');
            if (on !== type) return;
            if ((type === 'mouseover' || type === 'mouseout') && event.relatedTarget && el.contains(event.relatedTarget)) return;
            run(el, event);
        });
    });

    // Forms: confirmation before submit.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        var message = form.dataset.pkConfirm;
        if (!message) return;
        if (form.dataset.pkConfirmed === '1') {
            delete form.dataset.pkConfirmed;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        var submitter = event.submitter || null;
        ask(message).then(function (ok) {
            if (!ok) return;
            // Leave the current dispatch before submitting again (see ask()).
            setTimeout(function () {
                form.dataset.pkConfirmed = '1';
                submitForm(form, submitter);
                // requestSubmit() fires 'submit' synchronously and the handler
                // above consumes the flag. When it fires nothing (constraint
                // validation failed) the flag must not survive, or the next
                // submit would skip the confirmation.
                setTimeout(function () { delete form.dataset.pkConfirmed; }, 0);
            }, 0);
        });
    }, true);

    // Images: 'error' does not bubble, so listen in the capture phase.
    function fallback(img) {
        if (img.dataset.pkFallbackDone === '1') return;
        img.dataset.pkFallbackDone = '1';
        if (img.dataset.pkFallbackClass) img.classList.add(img.dataset.pkFallbackClass);
        if (img.dataset.pkFallbackHide !== undefined) img.style.display = 'none';
        if (img.dataset.pkFallbackSrc) img.src = img.dataset.pkFallbackSrc;
    }
    function hasFallback(img) {
        return img.dataset.pkFallbackSrc !== undefined
            || img.dataset.pkFallbackClass !== undefined
            || img.dataset.pkFallbackHide !== undefined;
    }
    document.addEventListener('error', function (event) {
        var img = event.target;
        if (img instanceof HTMLImageElement && hasFallback(img)) fallback(img);
    }, true);
    // Images that failed before this listener saw them.
    function sweep() {
        document.querySelectorAll('img[data-pk-fallback-src], img[data-pk-fallback-class], img[data-pk-fallback-hide]').forEach(function (img) {
            if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) fallback(img);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sweep);
    } else {
        sweep();
    }
    window.addEventListener('load', sweep);

    window.PinakesActions = {
        register: function (name, fn) {
            if (typeof name === 'string' && typeof fn === 'function') verbs[name] = fn;
        },
        has: function (name) { return typeof verbs[name] === 'function'; },
        confirm: ask
    };
})();
