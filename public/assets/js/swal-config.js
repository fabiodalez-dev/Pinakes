/**
 * Configurazione globale SweetAlert2
 * Schema colori: Nero, Bianco, Silver
 */

const __swal = (typeof window !== 'undefined' && typeof window.__ === 'function')
  ? (key, fallback = key, ...args) => {
      const translated = window.__(key, ...args);
      return translated === undefined || translated === null || translated === key
        ? fallback
        : translated;
    }
  : (key, fallback = key) => fallback;

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

  // Pulsanti
  confirmButtonText: __swal('Conferma', 'Confirm'),
  cancelButtonText: __swal('Annulla', 'Cancel'),

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

// Helper functions per casi comuni
window.SwalApp = {
  /**
   * Conferma eliminazione
   */
  confirmDelete: function(options = {}) {
    return Swal.fire({
      title: options.title || __swal('Sei sicuro?', 'Are you sure?'),
      text: options.text || __swal('Questa azione non può essere annullata!', 'This action cannot be undone!'),
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626', // Rosso per delete
      confirmButtonText: options.confirmText || __swal('Elimina', 'Delete'),
      cancelButtonText: __swal('Annulla', 'Cancel'),
      reverseButtons: true
    });
  },

  /**
   * Success message
   */
  success: function(title, text) {
    return Swal.fire({
      icon: 'success',
      title: title || __swal('Successo!', 'Success!'),
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Error message
   */
  error: function(title, text) {
    return Swal.fire({
      icon: 'error',
      title: title || __swal('Errore!', 'Error!'),
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Info message
   */
  info: function(title, text) {
    return Swal.fire({
      icon: 'info',
      title: title || __swal('Informazione', 'Information'),
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Warning message
   */
  warning: function(title, text) {
    return Swal.fire({
      icon: 'warning',
      title: title || __swal('Attenzione!', 'Warning!'),
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: __swal('OK', 'OK')
    });
  },

  /**
   * Conferma generica
   */
  confirm: function(options = {}) {
    return Swal.fire({
      title: options.title || __swal('Confermi?', 'Confirm?'),
      text: options.text,
      html: options.html,
      icon: options.icon || 'question',
      showCancelButton: true,
      confirmButtonColor: '#111827',
      cancelButtonColor: '#9ca3af',
      confirmButtonText: options.confirmText || __swal('Conferma', 'Confirm'),
      cancelButtonText: __swal('Annulla', 'Cancel'),
      reverseButtons: true
    });
  },

  /**
   * Toast notification
   */
  toast: function(options = {}) {
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
      title: options.title || __swal('Operazione completata', 'Operation completed')
    });
  }
};
