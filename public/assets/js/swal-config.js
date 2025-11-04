/**
 * Configurazione globale SweetAlert2
 * Schema colori: Nero, Bianco, Silver
 */

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
  confirmButtonText: 'Conferma',
  cancelButtonText: 'Annulla',

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
      title: options.title || 'Sei sicuro?',
      text: options.text || 'Questa azione non può essere annullata!',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626', // Rosso per delete
      confirmButtonText: options.confirmText || 'Elimina',
      cancelButtonText: 'Annulla',
      reverseButtons: true
    });
  },

  /**
   * Success message
   */
  success: function(title, text) {
    return Swal.fire({
      icon: 'success',
      title: title || 'Successo!',
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: 'OK'
    });
  },

  /**
   * Error message
   */
  error: function(title, text) {
    return Swal.fire({
      icon: 'error',
      title: title || 'Errore!',
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: 'OK'
    });
  },

  /**
   * Info message
   */
  info: function(title, text) {
    return Swal.fire({
      icon: 'info',
      title: title || 'Informazione',
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: 'OK'
    });
  },

  /**
   * Warning message
   */
  warning: function(title, text) {
    return Swal.fire({
      icon: 'warning',
      title: title || 'Attenzione!',
      text: text,
      confirmButtonColor: '#111827',
      confirmButtonText: 'OK'
    });
  },

  /**
   * Conferma generica
   */
  confirm: function(options = {}) {
    return Swal.fire({
      title: options.title || 'Confermi?',
      text: options.text,
      html: options.html,
      icon: options.icon || 'question',
      showCancelButton: true,
      confirmButtonColor: '#111827',
      cancelButtonColor: '#9ca3af',
      confirmButtonText: options.confirmText || 'Conferma',
      cancelButtonText: 'Annulla',
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
      title: options.title || 'Operazione completata'
    });
  }
};
