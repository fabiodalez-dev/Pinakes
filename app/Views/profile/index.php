<style>
  .profile-container {
    max-width: 1000px;
    margin: 0 auto;
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
    border: none;
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
</style>

<div class="profile-container">
  <div class="profile-header">
    <div class="profile-header-icon">
      <i class="fas fa-user"></i>
    </div>
    <h$1><?= __("$2") ?></h$1>
  </div>

  <?php if (!empty($_GET['success'])): ?>
    <?php if ($_GET['success'] === 'password'): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span>Password aggiornata con successo.</span>
      </div>
    <?php else: ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span>Profilo aggiornato con successo.</span>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span>
        <?php
          $errors = [
            'csrf' => 'Errore di sicurezza. Riprova.',
            'required' => 'Nome e cognome sono obbligatori.',
            'password_mismatch' => 'Le password non coincidono.',
            'password_too_short' => 'La password deve essere lunga almeno 8 caratteri.',
            'password_weak' => 'La password deve contenere maiuscole, minuscole e numeri.'
          ];
          echo $errors[$_GET['error']] ?? 'Si è verificato un errore.';
        ?>
      </span>
    </div>
  <?php endif; ?>

  <!-- Informazioni tessera -->
  <div class="card">
    <h2 class="card-title">
      <i class="fas fa-id-card"></i>
      Informazioni tessera
    </h2>
    <div class="info-grid">
      <div class="info-item">
        <dt>Numero tessera</dt>
        <dd><?php echo App\Support\HtmlHelper::e($user['codice_tessera'] ?? ''); ?></dd>
      </div>
      <div class="info-item">
        <dt>__("Email")</dt>
        <dd><?php echo App\Support\HtmlHelper::e($user['email'] ?? ''); ?></dd>
      </div>
      <div class="info-item">
        <dt>__("Stato")</dt>
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
        <dt>Scadenza tessera</dt>
        <dd class="<?php echo empty($user['data_scadenza_tessera']) ? 'empty' : ''; ?>">
          <?php echo !empty($user['data_scadenza_tessera']) ? date('d/m/Y', strtotime($user['data_scadenza_tessera'])) : 'Non specificata'; ?>
        </dd>
      </div>
    </div>
  </div>

  <!-- Dati personali -->
  <div class="card">
    <h2 class="card-title">
      <i class="fas fa-user-edit"></i>
      Dati personali
    </h2>
    <form method="post" action="/profilo/update">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="form-grid">
        <div class="form-group">
          <label for="nome" class="form-label">Nome *</label>
          <input type="text" id="nome" name="nome" class="form-input" required aria-required="true" aria-describedby="nome-error"
                 value="<?php echo App\Support\HtmlHelper::e($user['nome'] ?? ''); ?>">
          <span id="nome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
        <div class="form-group">
          <label for="cognome" class="form-label">Cognome *</label>
          <input type="text" id="cognome" name="cognome" class="form-input" required aria-required="true" aria-describedby="cognome-error"
                 value="<?php echo App\Support\HtmlHelper::e($user['cognome'] ?? ''); ?>">
          <span id="cognome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label for="telefono" class="form-label">__("Telefono")</label>
          <input type="tel" id="telefono" name="telefono" class="form-input"
                 value="<?php echo App\Support\HtmlHelper::e($user['telefono'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="data_nascita" class="form-label">Data di nascita</label>
          <input type="date" id="data_nascita" name="data_nascita" class="form-input"
                 value="<?php echo htmlspecialchars(substr($user['data_nascita'] ?? '', 0, 10)); ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label for="cod_fiscale" class="form-label">Codice fiscale</label>
          <input type="text" id="cod_fiscale" name="cod_fiscale" class="form-input" maxlength="16"
                 value="<?php echo App\Support\HtmlHelper::e($user['cod_fiscale'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="sesso" class="form-label">Sesso</label>
          <select id="sesso" name="sesso" class="form-select">
            <option value="">Non specificato</option>
            <option value="M" <?php echo ($user['sesso'] ?? '') === 'M' ? 'selected' : ''; ?>>Maschio</option>
            <option value="F" <?php echo ($user['sesso'] ?? '') === 'F' ? 'selected' : ''; ?>>Femmina</option>
            <option value="Altro" <?php echo ($user['sesso'] ?? '') === 'Altro' ? 'selected' : ''; ?>>Altro</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label for="indirizzo" class="form-label">__("Indirizzo")</label>
        <input type="text" id="indirizzo" name="indirizzo" class="form-input"
               value="<?php echo App\Support\HtmlHelper::e($user['indirizzo'] ?? ''); ?>">
      </div>


      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i>
          Salva modifiche
        </button>
      </div>
    </form>
  </div>

  <!-- Cambio password -->
  <div class="card">
    <h2 class="card-title">
      <i class="fas fa-lock"></i>
      Cambia password
    </h2>
    <form method="post" action="/profilo/password">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="form-grid">
        <div class="form-group">
          <label for="password" class="form-label">Nuova password</label>
          <input type="password" id="password" name="password" class="form-input" autocomplete="new-password" required aria-required="true" aria-describedby="password-error"
                 minlength="8" placeholder="Minimo 8 caratteri">
          <small><?= __("$1") ?></small>
          <span id="password-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
        <div class="form-group">
          <label for="password_confirm" class="form-label">Conferma password</label>
          <input type="password" id="password_confirm" name="password_confirm" class="form-input" autocomplete="new-password" required aria-required="true" aria-describedby="password_confirm-error"
                 minlength="8" placeholder="Ripeti la password">
          <span id="password_confirm-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-key"></i>
          Aggiorna password
        </button>
      </div>
    </form>
  </div>
</div>
