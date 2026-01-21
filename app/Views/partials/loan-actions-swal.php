<?php
$loanActionTranslations = array_merge([
    'confirmApproveTitle' => __('Sei sicuro di voler approvare questo prestito?'),
    'confirmApproveText' => __('Questa azione assegnerà una copia disponibile.'),
    'approveButton' => __('Approva'),
    'rejectButton' => __('Rifiuta'),
    'cancelButton' => __('Annulla'),
    'successApproveTitle' => __('Prestito approvato!'),
    'successRejectTitle' => __('Prestito rifiutato!'),
    'errorTitle' => __('Errore'),
    'errorPrefix' => __('Errore:'),
    'errorFallback' => __('Si è verificato un errore durante l\'operazione. Riprova più tardi.'),
    'serverError' => __('Errore nella comunicazione con il server'),
    'rejectPromptTitle' => __('Rifiuta prestito'),
    'rejectPromptLabel' => __('Motivo del rifiuto (opzionale):'),
    'rejectPromptPlaceholder' => __('Aggiungi un motivo (opzionale)'),
    // Confirm pickup translations
    'confirmPickupTitle' => __('Confermare il ritiro?'),
    'confirmPickupText' => __('Questa azione conferma che l\'utente ha ritirato il libro.'),
    'pickupButton' => __('Conferma Ritiro'),
    'successPickupTitle' => __('Ritiro confermato!'),
    'cancelPickupTitle' => __('Annullare il prestito scaduto?'),
    'cancelPickupText' => __('Il termine per il ritiro è scaduto. Vuoi annullare questo prestito?'),
    'cancelPickupButton' => __('Annulla Prestito'),
    'cancelPickupReason' => __('Ritiro non effettuato entro il termine previsto'),
    'successCancelPickupTitle' => __('Prestito annullato!'),
    // Add missing translations for the Swal dialogs in prestiti/index.php
    'Approva Prestito?' => __('Approva Prestito?'),
    'Approverai questa richiesta di prestito?' => __('Approverai questa richiesta di prestito?'),
    'Rifiuta Prestito?' => __('Rifiuta Prestito?'),
    'Rifiuterai questa richiesta di prestito?' => __('Rifiuterai questa richiesta di prestito?'),
    'Successo' => __('Successo'),
    'Errore' => __('Errore'),
    'Errore nell\'approvazione' => __('Errore nell\'approvazione'),
    'Errore nel rifiuto' => __('Errore nel rifiuto'),
    'Errore di comunicazione con il server' => __('Errore di comunicazione con il server'),
    'Prestito approvato!' => __('Prestito approvato!'),
    'Prestito rifiutato!' => __('Prestito rifiutato!'),
], $loanActionTranslations ?? []);
?>
<script>
(function() {
  const t = <?= json_encode($loanActionTranslations, JSON_UNESCAPED_UNICODE); ?>;
  
  // Extend the existing global window.__ function to include loan action translations
  if (typeof window.__ === 'function') {
    // Store the original function
    const originalTranslate = window.__;
    
    // Create a new function that first checks loan action translations, then falls back to original
    window.__ = function(key, ...args) {
      // First check if it's a loan action translation
      if (t.hasOwnProperty(key)) {
        return t[key];
      }
      // Otherwise, use the original translation function
      return originalTranslate(key, ...args);
    };
  } else {
    // If no existing __ function, create one
    window.__ = function(key) {
      return t[key] || key;
    };
  }

  const hasSwal = () => typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function';

  const confirmApprove = async () => {
    if (hasSwal()) {
      return window.Swal.fire({
        icon: 'question',
        title: t.confirmApproveTitle,
        text: t.confirmApproveText,
        showCancelButton: true,
        confirmButtonText: t.approveButton,
        cancelButtonText: t.cancelButton,
        focusCancel: true
      });
    }
    const ok = window.confirm(t.confirmApproveTitle);
    return { isConfirmed: !!ok };
  };

  const promptRejectReason = async () => {
    if (hasSwal()) {
      return window.Swal.fire({
        icon: 'warning',
        title: t.rejectPromptTitle,
        input: 'textarea',
        inputLabel: t.rejectPromptLabel,
        inputPlaceholder: t.rejectPromptPlaceholder,
        showCancelButton: true,
        confirmButtonText: t.rejectButton,
        cancelButtonText: t.cancelButton,
        inputAttributes: { 'aria-label': t.rejectPromptLabel },
        customClass: {
          confirmButton: 'btn btn-danger',
          cancelButton: 'btn btn-outline-secondary'
        }
      });
    }
    const value = window.prompt(t.rejectPromptLabel);
    return { isConfirmed: value !== null, value };
  };

  const showSuccess = async (title, text) => {
    if (hasSwal()) {
      await window.Swal.fire({
        icon: 'success',
        title,
        text
      });
    } else {
      window.alert(`${title}${text ? '\n' + text : ''}`);
    }
  };

  const showError = async (text) => {
    const message = text || t.errorFallback;
    if (hasSwal()) {
      await window.Swal.fire({
        icon: 'error',
        title: t.errorTitle,
        text: message
      });
    } else {
      window.alert(`${t.errorPrefix} ${message}`);
    }
  };

  const sendRequest = async (url, payload, csrf) => {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(payload)
    });

    let data = {};
    try {
      data = await response.json();
    } catch (_) {
      data = {};
    }

    return { response, data };
  };

  const bindLoanActions = () => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    document.querySelectorAll('.approve-btn').forEach(btn => {
      if (btn.dataset.bound === '1') {
        return;
      }
      btn.dataset.bound = '1';
      btn.addEventListener('click', async function() {
        const loanId = parseInt(this.dataset.loanId || '0', 10);
        if (!loanId) return;

        const confirmed = await confirmApprove();
        if (!confirmed.isConfirmed) {
          return;
        }

        try {
          const { response, data } = await sendRequest('/admin/loans/approve', { loan_id: loanId }, csrf);
          if (response.ok && data.success) {
            const card = this.closest('[data-loan-card]');
            if (card) {
              card.remove();
            }
            await showSuccess(t.successApproveTitle, data.message || '');
            if (!document.querySelector('.approve-btn')) {
              window.location.reload();
            }
          } else {
            await showError(data.message);
          }
        } catch (_) {
          await showError(t.serverError);
        }
      });
    });

    document.querySelectorAll('.reject-btn').forEach(btn => {
      if (btn.dataset.bound === '1') {
        return;
      }
      btn.dataset.bound = '1';
      btn.addEventListener('click', async function() {
        const loanId = parseInt(this.dataset.loanId || '0', 10);
        if (!loanId) return;

        const promptResult = await promptRejectReason();
        if (!promptResult.isConfirmed) {
          return;
        }

        try {
          const { response, data } = await sendRequest('/admin/loans/reject', {
            loan_id: loanId,
            reason: promptResult.value || ''
          }, csrf);

          if (response.ok && data.success) {
            const card = this.closest('[data-loan-card]');
            if (card) {
              card.remove();
            }
            await showSuccess(t.successRejectTitle, data.message || '');
            if (!document.querySelector('.approve-btn')) {
              window.location.reload();
            }
          } else {
            await showError(data.message);
          }
        } catch (_) {
          await showError(t.serverError);
        }
      });
    });

    // Confirm pickup button handler
    document.querySelectorAll('.confirm-pickup-btn').forEach(btn => {
      if (btn.dataset.bound === '1') {
        return;
      }
      btn.dataset.bound = '1';
      btn.addEventListener('click', async function() {
        const loanId = parseInt(this.dataset.loanId || '0', 10);
        if (!loanId) return;

        if (hasSwal()) {
          const confirmed = await window.Swal.fire({
            icon: 'question',
            title: t.confirmPickupTitle,
            text: t.confirmPickupText,
            showCancelButton: true,
            confirmButtonText: t.pickupButton,
            cancelButtonText: t.cancelButton,
            confirmButtonColor: '#16a34a',
            focusCancel: true
          });
          if (!confirmed.isConfirmed) {
            return;
          }
        } else if (!window.confirm(t.confirmPickupTitle)) {
          return;
        }

        try {
          const { response, data } = await sendRequest('/admin/loans/confirm-pickup', { loan_id: loanId }, csrf);
          if (response.ok && data.success) {
            const card = this.closest('[data-pickup-card]');
            if (card) {
              card.remove();
            }
            await showSuccess(t.successPickupTitle, data.message || '');
            if (!document.querySelector('.confirm-pickup-btn') && !document.querySelector('.approve-btn')) {
              window.location.reload();
            }
          } else {
            await showError(data.message);
          }
        } catch (_) {
          await showError(t.serverError);
        }
      });
    });

    // Cancel expired pickup button handler
    document.querySelectorAll('.cancel-pickup-btn').forEach(btn => {
      if (btn.dataset.bound === '1') {
        return;
      }
      btn.dataset.bound = '1';
      btn.addEventListener('click', async function() {
        const loanId = parseInt(this.dataset.loanId || '0', 10);
        if (!loanId) return;

        if (hasSwal()) {
          const confirmed = await window.Swal.fire({
            icon: 'warning',
            title: t.cancelPickupTitle,
            text: t.cancelPickupText,
            showCancelButton: true,
            confirmButtonText: t.cancelPickupButton,
            cancelButtonText: t.cancelButton,
            confirmButtonColor: '#dc2626',
            focusCancel: true
          });
          if (!confirmed.isConfirmed) {
            return;
          }
        } else if (!window.confirm(t.cancelPickupTitle)) {
          return;
        }

        try {
          const { response, data } = await sendRequest('/admin/loans/cancel-pickup', {
            loan_id: loanId,
            reason: t.cancelPickupReason
          }, csrf);

          if (response.ok && data.success) {
            const card = this.closest('[data-pickup-card]');
            if (card) {
              card.remove();
            }
            await showSuccess(t.successCancelPickupTitle, data.message || '');
            if (!document.querySelector('.confirm-pickup-btn') && !document.querySelector('.approve-btn')) {
              window.location.reload();
            }
          } else {
            await showError(data.message);
          }
        } catch (_) {
          await showError(t.serverError);
        }
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindLoanActions, { once: true });
  } else {
    bindLoanActions();
  }
})();
</script>
