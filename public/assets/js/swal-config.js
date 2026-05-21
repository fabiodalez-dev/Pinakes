/**
 * Configurazione globale SweetAlert2
 * Schema colori: Nero, Bianco, Silver
 */

const __swal = (typeof window !== 'undefined' && typeof window.__ === 'function')
  ? (key, _fallback = key, ...args) => {
      // Usa sempre la chiave come stringa di lookup (__ è responsabile di scegliere la lingua corretta)
      const translated = window.__(key, ...args);
      // Se non trova traduzione, ripiega sulla chiave originale (fallback globale = IT)
      if (translated === undefined || translated === null || translated === key) {
        return key;
      }
      return translated;
    }
  : (key, _fallback = key) => key;

// Configurazione default per tutti gli alert
const SwalConfig = {
  // Colori tema app
  confirmButtonColor: '#111827',  // Nero/dark slate
  cancelButtonColor: '#9ca3af',   // Silver/grigio
  denyButtonColor: '#dc2626',     // Rosso per azioni distruttive

  // Stile popup
  customClass: {
    popup: 'swal-app-popup',
    title: 'swal-app-title',
    htmlContainer: 'swal-app-text',
    confirmButton: 'swal-app-confirm-button',
    cancelButton: 'swal-app-cancel-button',
    denyButton: 'swal-app-deny-button'
  },

  // Pulsanti (usano chiavi traducibili, default IT → EN tramite __swal)
  confirmButtonText: __swal('Conferma'),
  cancelButtonText: __swal('Annulla'),

  // Animazioni
  showClass: {
    popup: 'animate__animated animate__fadeIn animate__faster'
  },
  hideClass: {
    popup: 'animate__animated animate__fadeOut animate__faster'
  },

  // Comportamento
  allowEscapeKey: true,  // Permetti chiusura con ESC
  allowOutsideClick: true  // Permetti chiusura click esterno
};

// Applica configurazione di default
if (typeof Swal !== 'undefined') {
  Swal.mixin(SwalConfig);
}

// Check if SweetAlert2 is loaded — every helper below uses this for
// defensive fallback. If the JS bundle / CDN failed, or CSP blocked
// the script, we still want delete/save flows to work via the native
// browser dialogs.
const _hasSwal = function() {
  return typeof Swal !== 'undefined';
};

// Wrap a native confirm()/alert() in a Promise-like shape so callers
// using async/.then(r => r.isConfirmed) work both with and without Swal.
const _fakeResult = function(isConfirmed, value) {
  return Promise.resolve({ isConfirmed: !!isConfirmed, value: value });
};

// Helper functions per casi comuni
window.SwalApp = {
  /**
   * Conferma eliminazione
   */
  confirmDelete: function(options = {}) {
    const title = options.title || __swal('Sei sicuro?');
    const text  = options.text  || __swal('Questa azione non può essere annullata!');
    if (!_hasSwal()) {
      // Native fallback: combine title + text on two lines so the user
      // sees the same information as the SweetAlert dialog. Guard
      // against `text` being missing — title || default keeps `text`
      // potentially undefined when both options.text and the default
      // resolve to empty.
      const msg = text ? title + '\n\n' + text : title;
      return _fakeResult(window.confirm(msg));
    }
    return Swal.fire({
      title: title,
      text:  text,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626', // Rosso per delete
      confirmButtonText: options.confirmText || __swal('Elimina'),
      cancelButtonText: __swal('Annulla'),
      reverseButtons: true
    });
  },

  /**
   * Success message
   */
  success: function(title, text) {
    const t = title || __swal('Successo!');
    if (!_hasSwal()) {
      window.alert(text ? t + '\n\n' + text : t);
      return _fakeResult(true);
    }
    return Swal.fire({
      icon: 'success',
      title: t,
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Error message
   */
  error: function(title, text) {
    const t = title || __swal('Errore!');
    if (!_hasSwal()) {
      window.alert(text ? t + '\n\n' + text : t);
      return _fakeResult(true);
    }
    return Swal.fire({
      icon: 'error',
      title: t,
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Info message
   */
  info: function(title, text) {
    const t = title || __swal('Informazione');
    if (!_hasSwal()) {
      window.alert(text ? t + '\n\n' + text : t);
      return _fakeResult(true);
    }
    return Swal.fire({
      icon: 'info',
      title: t,
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Warning message
   */
  warning: function(title, text) {
    const t = title || __swal('Attenzione!');
    if (!_hasSwal()) {
      window.alert(text ? t + '\n\n' + text : t);
      return _fakeResult(true);
    }
    return Swal.fire({
      icon: 'warning',
      title: t,
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Conferma generica
   */
  confirm: function(options = {}) {
    const title = options.title || __swal('Confermi?');
    const text  = options.text;
    if (!_hasSwal()) {
      return _fakeResult(window.confirm(text ? title + '\n\n' + text : title));
    }
    return Swal.fire({
      title: title,
      text:  text,
      html:  options.html,
      icon:  options.icon || 'question',
      showCancelButton: true,
      confirmButtonColor: '#111827',
      cancelButtonColor: '#9ca3af',
      confirmButtonText: options.confirmText || __swal('Conferma'),
      cancelButtonText: __swal('Annulla'),
      reverseButtons: true
    });
  },

  /**
   * Prompt — single-line text input. Returns {isConfirmed, value}.
   * Falls back to window.prompt() when Swal is unavailable so the
   * user can still answer in degraded mode.
   */
  prompt: function(options = {}) {
    const title = options.title || __swal('Inserisci un valore');
    const text  = options.text;
    const def   = options.defaultValue || '';
    if (!_hasSwal()) {
      const v = window.prompt(text ? title + '\n\n' + text : title, def);
      // Only `null` is "cancel"; an empty string is a valid confirmed
      // value (matches Swal's semantics where the user can submit "").
      return _fakeResult(v !== null, v === null ? '' : v);
    }
    return Swal.fire({
      title: title,
      text:  text,
      input: options.input || 'text',
      inputValue: def,
      inputPlaceholder: options.placeholder || '',
      showCancelButton: true,
      confirmButtonColor: '#111827',
      confirmButtonText: options.confirmText || __swal('Conferma'),
      cancelButtonText:  __swal('Annulla'),
      inputValidator: options.inputValidator || null
    });
  },

  /**
   * Toast notification
   */
  toast: function(options = {}) {
    if (!_hasSwal()) {
      // Toasts are pure UX flair — silently no-op when Swal absent
      // rather than block flow with alert() for every transient notice.
      return _fakeResult(true);
    }
    const Toast = Swal.mixin({
      toast: true,
      position: options.position || 'top-end',
      showConfirmButton: false,
      timer: options.timer || 3000,
      timerProgressBar: true,
      didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer)
        toast.addEventListener('mouseleave', Swal.resumeTimer)
      }
    });

    return Toast.fire({
      icon: options.icon || 'success',
      title: options.title || __swal('Operazione completata')
    });
  },

  /**
   * Attach a SweetAlert confirm dialog to every form matching the
   * given selector (default: `form[data-swal-confirm]`). The form's
   * `data-swal-confirm` attribute is used as the dialog text; an
   * optional `data-swal-confirm-title` overrides the title.
   *
   * Pattern lets us migrate every legacy `<form onsubmit="return
   * confirm(...)">` to a uniform Swal flow without inlining a
   * `<script>` per form. Call once from a `DOMContentLoaded` handler.
   *
   * Falls back to `window.confirm()` when Swal isn't loaded — the
   * form still submits on user confirm.
   */
  attachSwalConfirm: function(selector) {
    const sel = selector || 'form[data-swal-confirm]';
    document.querySelectorAll(sel).forEach((form) => {
      if (form.dataset.swalAttached === '1') return;
      form.dataset.swalAttached = '1';
      form.addEventListener('submit', function(event) {
        // If this submit is the programmatic one we ourselves fire
        // after Swal confirm (see below), let it through. Using a
        // separate flag avoids the previous trick of CLEARING
        // data-swal-confirm — if form.submit() failed or got blocked
        // elsewhere, the cleared attribute permanently bypassed the
        // dialog on every later click.
        if (form.dataset.swalProceed === '1') {
          // Reset for any future submit so the dialog gates the next click.
          form.dataset.swalProceed = '';
          return;
        }
        event.preventDefault();
        const text  = form.dataset.swalConfirm;
        const title = form.dataset.swalConfirmTitle || __swal('Sei sicuro?');
        const confirmText = form.dataset.swalConfirmButton || __swal('Elimina');
        // Pick a kind-appropriate helper: forms with
        // data-swal-confirm-kind="action" use the neutral (gray)
        // confirm dialog; everything else defaults to confirmDelete
        // (red destructive button). Lets non-destructive flows
        // (activate user, set default language, reset colours, …)
        // opt out of the red-button look.
        const kind = form.dataset.swalConfirmKind === 'action'
          ? window.SwalApp.confirm
          : window.SwalApp.confirmDelete;
        kind.call(window.SwalApp, {
          title: title,
          text:  text,
          confirmText: confirmText
        }).then((r) => {
          if (r.isConfirmed) {
            // Mark the next submit as the proceed one and re-fire.
            form.dataset.swalProceed = '1';
            form.submit();
          }
        });
      });
    });
  }
};

// Auto-wire on DOMContentLoaded so views can simply mark their forms
// with `data-swal-confirm="..."` instead of including a per-page
// listener. Idempotent: re-attaching is a no-op via `data-swal-attached`.
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      window.SwalApp.attachSwalConfirm();
    });
  } else {
    window.SwalApp.attachSwalConfirm();
  }
}
