<style>
  .profile-container {
    max-width: 1000px;
    margin: 5rem auto;
    padding: 2rem 1rem;
  }

  .profile-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
  }

  .profile-header-icon {
    width: 56px;
    height: 56px;
    background: #1f2937;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
  }

  .profile-header h1 {
    font-size: 1.875rem;
    font-weight: 700;
    color: #111827;
    margin: 0;
  }

  .alert {
    padding: 1rem 1.25rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .alert-success {
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #15803d;
  }

  .alert-error {
    background: #fee2e2;
    border: 1px solid #fecaca;
    color: #991b1b;
  }

  .card {
    background: white;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    padding: 2rem;
    margin-bottom: 1.5rem;
  }

  .card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #111827;
    margin: 0 0 1.5rem 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
  }

  .info-item dt {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6b7280;
    font-weight: 600;
    margin-bottom: 0.5rem;
  }

  .info-item dd {
    font-size: 1rem;
    color: #111827;
    font-weight: 500;
    margin: 0;
  }

  .info-item dd.empty {
    color: #9ca3af;
    font-style: italic;
  }

  .form-group {
    margin-bottom: 1.25rem;
  }

  .form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
  }

  .form-input, .form-select {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.875rem;
    transition: all 0.2s ease;
  }

  .form-input:focus, .form-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
  }

  .form-input:disabled {
    background: #f3f4f6;
    color: #6b7280;
    cursor: not-allowed;
  }

  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.25rem;
  }

  .form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1.5rem;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.5rem;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
  }

  .btn-primary {
    background: #1f2937;
    color: white;
  }

  .btn-primary:hover {
    background: #111827;
  }

  .badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
  }

  .badge-active {
    background: #dcfce7;
    color: #15803d;
  }

  .badge-suspended {
    background: #fee2e2;
    color: #991b1b;
  }

  .badge-expired {
    background: #fef3c7;
    color: #92400e;
  }

  /* Session management styles */
  .session-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .session-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
  }

  .session-item.current {
    background: #eff6ff;
    border-color: #3b82f6;
  }

  .session-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  .session-device {
    font-weight: 600;
    color: #111827;
  }

  .session-meta {
    font-size: 0.75rem;
    color: #6b7280;
  }

  .session-badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    background: #3b82f6;
    color: white;
    border-radius: 4px;
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 0.5rem;
  }

  .btn-danger {
    background: #dc2626;
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease;
  }

  .btn-danger:hover {
    background: #b91c1c;
  }

  .btn-danger:disabled {
    background: #9ca3af;
    cursor: not-allowed;
  }

  .btn-secondary {
    background: #6b7280;
    color: white;
    border: none;
  }

  .btn-secondary:hover {
    background: #4b5563;
  }

  .sessions-loading {
    text-align: center;
    padding: 2rem;
    color: #6b7280;
  }

  .sessions-empty {
    text-align: center;
    padding: 2rem;
    color: #6b7280;
    font-style: italic;
  }

  .sessions-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
  }
</style>

<div class="profile-container">
  <div class="profile-header">
    <div class="profile-header-icon">
      <i class="fas fa-user"></i>
    </div>
    <h1><?= __("Il mio profilo") ?></h1>
  </div>

  <?php if (!empty($_GET['success'])): ?>
    <?php if ($_GET['success'] === 'password'): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?= __("Password aggiornata con successo.") ?></span>
      </div>
    <?php else: ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?= __("Profilo aggiornato con successo.") ?></span>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span>
        <?php
          $errors = [
            'csrf' => __('Errore di sicurezza. Riprova.'),
            'required' => __('Nome e cognome sono obbligatori.'),
            'password_mismatch' => __('Le password non coincidono.'),
            'password_too_short' => __('La password deve essere lunga almeno 8 caratteri.'),
            'password_weak' => __('La password deve contenere maiuscole, minuscole e numeri.')
          ];
          echo $errors[$_GET['error']] ?? __('Si è verificato un errore.');
        ?>
      </span>
    </div>
  <?php endif; ?>

  <!-- Informazioni tessera -->
  <div class="card">
    <h2 class="card-title">
      <i class="fas fa-id-card"></i>
      <?= __("Informazioni tessera") ?>
    </h2>
    <div class="info-grid">
      <div class="info-item">
        <dt><?= __("Numero tessera") ?></dt>
        <dd><?php echo App\Support\HtmlHelper::e($user['codice_tessera'] ?? ''); ?></dd>
      </div>
      <div class="info-item">
        <dt><?= __("Email") ?></dt>
        <dd><?php echo App\Support\HtmlHelper::e($user['email'] ?? ''); ?></dd>
      </div>
      <div class="info-item">
        <dt><?= __("Stato") ?></dt>
        <dd>
          <?php
            $stato = $user['stato'] ?? 'attivo';
            $badgeClass = 'badge-active';
            if ($stato === 'sospeso') $badgeClass = 'badge-suspended';
            if ($stato === 'scaduto') $badgeClass = 'badge-expired';
          ?>
          <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($stato); ?></span>
        </dd>
      </div>
      <div class="info-item">
        <dt><?= __("Scadenza tessera") ?></dt>
        <dd class="<?php echo empty($user['data_scadenza_tessera']) ? 'empty' : ''; ?>">
          <?php echo !empty($user['data_scadenza_tessera']) ? format_date($user['data_scadenza_tessera'], false, '/') : __('Non specificata'); ?>
        </dd>
      </div>
    </div>
  </div>

  <!-- Dati personali -->
  <div class="card">
    <h2 class="card-title">
      <i class="fas fa-user-edit"></i>
      <?= __("Dati personali") ?>
    </h2>
    <form method="post" action="<?= htmlspecialchars(route_path('profile_update'), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="form-grid">
        <div class="form-group">
          <label for="nome" class="form-label"><?= __("Nome") ?> *</label>
          <input type="text" id="nome" name="nome" class="form-input" required aria-required="true" aria-describedby="nome-error"
                 value="<?php echo App\Support\HtmlHelper::e($user['nome'] ?? ''); ?>">
          <span id="nome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
        <div class="form-group">
          <label for="cognome" class="form-label"><?= __("Cognome") ?> *</label>
          <input type="text" id="cognome" name="cognome" class="form-input" required aria-required="true" aria-describedby="cognome-error"
                 value="<?php echo App\Support\HtmlHelper::e($user['cognome'] ?? ''); ?>">
          <span id="cognome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label for="telefono" class="form-label"><?= __("Telefono") ?></label>
          <input type="tel" id="telefono" name="telefono" class="form-input"
                 value="<?php echo App\Support\HtmlHelper::e($user['telefono'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="data_nascita" class="form-label"><?= __("Data di nascita") ?></label>
          <input type="date" id="data_nascita" name="data_nascita" class="form-input"
                 value="<?php echo htmlspecialchars(substr($user['data_nascita'] ?? '', 0, 10)); ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label for="cod_fiscale" class="form-label"><?= __("Codice fiscale") ?></label>
          <input type="text" id="cod_fiscale" name="cod_fiscale" class="form-input" maxlength="16"
                 value="<?php echo App\Support\HtmlHelper::e($user['cod_fiscale'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="sesso" class="form-label"><?= __("Sesso") ?></label>
          <select id="sesso" name="sesso" class="form-select">
            <option value=""><?= __("Non specificato") ?></option>
            <option value="M" <?php echo ($user['sesso'] ?? '') === 'M' ? 'selected' : ''; ?>><?= __("Maschio") ?></option>
            <option value="F" <?php echo ($user['sesso'] ?? '') === 'F' ? 'selected' : ''; ?>><?= __("Femmina") ?></option>
            <option value="Altro" <?php echo ($user['sesso'] ?? '') === 'Altro' ? 'selected' : ''; ?>><?= __("Altro") ?></option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label for="indirizzo" class="form-label"><?= __("Indirizzo") ?></label>
        <input type="text" id="indirizzo" name="indirizzo" class="form-input"
               value="<?php echo App\Support\HtmlHelper::e($user['indirizzo'] ?? ''); ?>">
      </div>


      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i>
          <?= __("Salva modifiche") ?>
        </button>
      </div>
    </form>
  </div>

  <!-- Cambio password -->
  <div class="card">
    <h2 class="card-title">
      <i class="fas fa-lock"></i>
      <?= __("Cambia password") ?>
    </h2>
    <form method="post" action="<?= htmlspecialchars(route_path('profile_password'), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="form-grid">
        <div class="form-group">
          <label for="password" class="form-label"><?= __("Nuova password") ?></label>
          <input type="password" id="password" name="password" class="form-input" autocomplete="new-password" required aria-required="true" aria-describedby="password-error"
                 minlength="8" placeholder="<?= __("Minimo 8 caratteri") ?>">
          <small><?= __("Deve contenere maiuscole, minuscole e numeri") ?></small>
          <span id="password-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
        <div class="form-group">
          <label for="password_confirm" class="form-label"><?= __("Conferma password") ?></label>
          <input type="password" id="password_confirm" name="password_confirm" class="form-input" autocomplete="new-password" required aria-required="true" aria-describedby="password_confirm-error"
                 minlength="8" placeholder="<?= __("Ripeti la password") ?>">
          <span id="password_confirm-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-key"></i>
          <?= __("Aggiorna password") ?>
        </button>
      </div>
    </form>
  </div>

  <!-- Sessioni attive (Remember Me) -->
  <div class="card">
    <h2 class="card-title">
      <i class="fas fa-desktop"></i>
      <?= __("Sessioni attive") ?>
    </h2>
    <p style="color: #6b7280; font-size: 0.875rem; margin-bottom: 1rem;">
      <?= __("Gestisci i dispositivi su cui hai effettuato l'accesso con 'Ricordami'. Puoi disconnetterti da singoli dispositivi o da tutti contemporaneamente.") ?>
    </p>

    <div id="sessions-container">
      <div class="sessions-loading">
        <i class="fas fa-spinner fa-spin"></i> <?= __("Caricamento sessioni...") ?>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const csrfToken = <?= json_encode(App\Support\Csrf::ensureToken(), JSON_HEX_TAG) ?>;
  const translations = {
    loading: <?= json_encode(__("Caricamento sessioni..."), JSON_HEX_TAG) ?>,
    noSessions: <?= json_encode(__("Nessuna sessione attiva. Le sessioni vengono create quando accedi con 'Ricordami' selezionato."), JSON_HEX_TAG) ?>,
    currentSession: <?= json_encode(__("Sessione corrente"), JSON_HEX_TAG) ?>,
    lastUsed: <?= json_encode(__("Ultimo utilizzo"), JSON_HEX_TAG) ?>,
    created: <?= json_encode(__("Creata"), JSON_HEX_TAG) ?>,
    expires: <?= json_encode(__("Scade"), JSON_HEX_TAG) ?>,
    revoke: <?= json_encode(__("Disconnetti"), JSON_HEX_TAG) ?>,
    revokeAll: <?= json_encode(__("Disconnetti tutti"), JSON_HEX_TAG) ?>,
    confirmRevoke: <?= json_encode(__("Vuoi disconnettere questo dispositivo?"), JSON_HEX_TAG) ?>,
    confirmRevokeAll: <?= json_encode(__("Vuoi disconnettere tutti i dispositivi? Dovrai effettuare nuovamente l'accesso su ogni dispositivo."), JSON_HEX_TAG) ?>,
    error: <?= json_encode(__("Si è verificato un errore. Riprova."), JSON_HEX_TAG) ?>,
    unknown: <?= json_encode(__("Dispositivo sconosciuto"), JSON_HEX_TAG) ?>,
    activeSessions: <?= json_encode(__("sessioni attive"), JSON_HEX_TAG) ?>,
    timeout: <?= json_encode(__("La richiesta ha impiegato troppo tempo. Riprova."), JSON_HEX_TAG) ?>
  };

  function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr + 'Z'); // Assume UTC
    return date.toLocaleString();
  }

  function loadSessions() {
    const container = document.getElementById('sessions-container');
    container.innerHTML = '<div class="sessions-loading"><i class="fas fa-spinner fa-spin"></i> ' + translations.loading + '</div>';

    // Add timeout support for network issues
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000);

    fetch((window.BASE_PATH || '') + '/api/profile/sessions', { credentials: 'same-origin', signal: controller.signal })
      .then(response => response.json())
      .then(data => {
        clearTimeout(timeoutId);
        if (!data.sessions || data.sessions.length === 0) {
          container.innerHTML = '<div class="sessions-empty">' + translations.noSessions + '</div>';
          return;
        }

        let html = '<div class="sessions-header">';
        html += '<span style="font-size: 0.875rem; color: #6b7280;">' + data.sessions.length + ' ' + translations.activeSessions + '</span>';
        if (data.sessions.length > 1) {
          html += '<button type="button" class="btn btn-danger btn-secondary" onclick="revokeAllSessions()">';
          html += '<i class="fas fa-sign-out-alt"></i> ' + translations.revokeAll + '</button>';
        }
        html += '</div>';

        html += '<div class="session-list">';
        data.sessions.forEach(function(session) {
          const isCurrent = session.is_current;
          // Escape HTML to prevent XSS from user-controlled data (User-Agent, IP via proxy)
          const escapeHtml = function(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
          };
          html += '<div class="session-item' + (isCurrent ? ' current' : '') + '">';
          html += '<div class="session-info">';
          html += '<span class="session-device">';
          // Check for mobile OS (Android/iOS) since parseDeviceInfo returns "Browser / OS" format
          const deviceInfo = session.device_info || '';
          const isMobile = deviceInfo.includes('Android') || deviceInfo.includes('iOS');
          html += '<i class="fas fa-' + (isMobile ? 'mobile-alt' : 'desktop') + '"></i> ';
          html += escapeHtml(session.device_info || translations.unknown);
          if (isCurrent) {
            html += '<span class="session-badge">' + translations.currentSession + '</span>';
          }
          html += '</span>';
          html += '<span class="session-meta">';
          html += '<i class="fas fa-map-marker-alt"></i> ' + escapeHtml(session.ip_address || '-');
          html += ' &bull; ' + translations.lastUsed + ': ' + formatDate(session.last_used_at || session.created_at);
          html += '</span>';
          html += '</div>';
          if (!isCurrent) {
            // Validate session.id is a positive integer before using in onclick
            const sessionId = parseInt(session.id, 10);
            if (!isNaN(sessionId) && sessionId > 0) {
              html += '<button type="button" class="btn-danger" onclick="revokeSession(' + sessionId + ')">';
              html += '<i class="fas fa-times"></i> ' + translations.revoke + '</button>';
            }
          }
          html += '</div>';
        });
        html += '</div>';

        container.innerHTML = html;
      })
      .catch(function(error) {
        clearTimeout(timeoutId);
        console.error('Error loading sessions:', error);
        // Check if it was a timeout (AbortError)
        const message = error.name === 'AbortError' ? translations.timeout : translations.error;
        container.innerHTML = '<div class="sessions-empty" style="color: #dc2626;">' +
          '<i class="fas fa-exclamation-triangle"></i> ' + message + '</div>';
      });
  }

  window.revokeSession = function(sessionId) {
    if (!confirm(translations.confirmRevoke)) return;

    fetch((window.BASE_PATH || '') + '/api/profile/sessions/revoke', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&session_id=' + sessionId
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        loadSessions();
      } else {
        alert(data.error || translations.error);
      }
    })
    .catch(function() {
      alert(translations.error);
    });
  };

  window.revokeAllSessions = function() {
    if (!confirm(translations.confirmRevokeAll)) return;

    fetch((window.BASE_PATH || '') + '/api/profile/sessions/revoke-all', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        loadSessions();
      } else {
        alert(data.error || translations.error);
      }
    })
    .catch(function() {
      alert(translations.error);
    });
  };

  // Load sessions on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadSessions);
  } else {
    loadSessions();
  }
})();
</script>
