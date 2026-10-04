<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogoPath = Branding::fullLogo();
$appLogo = $appLogoPath !== '' ? url($appLogoPath) : '';
$registerRoute = route_path('register');
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,follow">
    <title><?= __('Registrazione') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <script>window.BASE_PATH = <?= json_encode(\App\Support\HtmlHelper::getBasePath(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(url('/favicon.ico'), ENT_QUOTES, 'UTF-8') ?>">
    
    <link href="<?= htmlspecialchars(assetUrl('vendor.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('main.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('fonts/fonts.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php require __DIR__ . '/partials/auth-theme.php'; ?>
    <?php require __DIR__ . '/partials/custom-css.php'; ?>
</head>
<body class="auth-body">

<div class="auth-page">
  <div class="auth-wrap auth-wrap--wide">
    <!-- Logo and Branding -->
    <header class="auth-brand">
      <?php if ($appLogo): ?>
        <img src="<?= htmlspecialchars($appLogo, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>"
             class="auth-brand-logo">
      <?php else: ?>
        <div class="auth-brand-tile" aria-hidden="true"><i class="fas fa-book-open"></i></div>
      <?php endif; ?>
      <p class="auth-brand-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></p>
    </header>

    <!-- Registration Form -->
    <main class="auth-card">
      <h1 class="auth-title"><?= __('Crea un account') ?></h1>
      <p class="auth-subtitle"><?= __('Compila i campi per registrarti') ?></p>
      <form method="post" action="<?= htmlspecialchars($registerRoute, ENT_QUOTES, 'UTF-8') ?>" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>" />
        
        <?php if (isset($_GET['error'])): ?>
          <div class="auth-alert auth-alert--error" role="alert">
            <div class="auth-alert-body">
              <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
              <div>
                <?php if ($_GET['error'] === 'session_expired'): ?>
                  <?= __('La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina e riprova') ?>
                <?php elseif ($_GET['error'] === '1'): ?>
                  <?= __('Errore durante la registrazione') ?>
                <?php elseif ($_GET['error'] === 'csrf'): ?>
                  <?= __('Errore di sicurezza, riprova') ?>
                <?php elseif ($_GET['error'] === 'already_registered'): ?>
                  <?= __('Questi dati risultano già registrati') ?>
                <?php elseif ($_GET['error'] === 'missing_fields'): ?>
                  <?= __('Compila tutti i campi richiesti') ?>
                <?php elseif ($_GET['error'] === 'custom_field_invalid'): ?>
                  <?= __('Controlla i campi personalizzati: un valore inserito non è valido.') ?>
                <?php elseif ($_GET['error'] === 'privacy_required'): ?>
                  <?= __('Devi accettare la Privacy Policy per procedere') ?>
                <?php elseif ($_GET['error'] === 'name_too_long'): ?>
                  <?= __('Nome o cognome troppo lungo (massimo 100 caratteri)') ?>
                <?php elseif ($_GET['error'] === 'email_too_long'): ?>
                  <?= __('Email troppo lunga (massimo 255 caratteri)') ?>
                <?php elseif ($_GET['error'] === 'password_too_long'): ?>
                  <?= __('Password troppo lunga (massimo 128 caratteri)') ?>
                <?php elseif ($_GET['error'] === 'password_too_short'): ?>
                  <?= __('La password deve essere lunga almeno 8 caratteri') ?>
                <?php elseif ($_GET['error'] === 'password_needs_upper_lower_number'): ?>
                  <?= __('La password deve contenere almeno una lettera maiuscola, una minuscola e un numero') ?>
                <?php elseif ($_GET['error'] === 'db'): ?>
                  <?= __('Errore del database durante la registrazione. Riprova più tardi') ?>
                <?php else: ?>
                  <?= __('Errore durante la registrazione') ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
          <div class="auth-alert auth-alert--success" role="alert">
            <div class="auth-alert-body">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <div>
                <?php if ($_GET['success'] === 'registered'): ?>
                  <?= __('Account creato con successo! Verifica la tua email.') ?>
                <?php elseif ($_GET['success'] === 'pending_approval'): ?>
                  <?= __('Account creato! In attesa di approvazione da parte dell\'amministratore.') ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="auth-grid">
          <div>
            <label for="nome" class="auth-label">
              <?= __('Nome') ?> *
            </label>
            <input
              type="text"
              id="nome"
              name="nome"
              required aria-required="true"
              aria-describedby="nome-error"
              class="auth-input"
              placeholder="<?= htmlspecialchars(__('Mario'), ENT_QUOTES, 'UTF-8') ?>"
              value="<?php echo htmlspecialchars($_GET['nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            />
            <span id="nome-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
          </div>

          <div>
            <label for="cognome" class="auth-label">
              <?= __('Cognome') ?><?= !empty($registrationRequired['cognome']) ? ' *' : '' ?>
            </label>
            <input
              type="text"
              id="cognome"
              name="cognome"
              <?= !empty($registrationRequired['cognome']) ? 'required aria-required="true"' : '' ?>
              aria-describedby="cognome-error"
              class="auth-input"
              placeholder="<?= htmlspecialchars(__('Rossi'), ENT_QUOTES, 'UTF-8') ?>"
              value="<?php echo htmlspecialchars($_GET['cognome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            />
            <span id="cognome-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
          </div>
        </div>

        <div>
          <label for="email" class="auth-label">
            <?= __('Email *') ?>
          </label>
          <input
            type="email" autocomplete="email"
            id="email"
            name="email"
            required aria-required="true"
            aria-describedby="email-error"
            class="auth-input"
            placeholder="<?= htmlspecialchars(__('mario.rossi@email.it'), ENT_QUOTES, 'UTF-8') ?>"
            value="<?php echo htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <span id="email-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <label for="telefono" class="auth-label">
            <?= __('Telefono') ?><?= !empty($registrationRequired['telefono']) ? ' *' : '' ?>
          </label>
          <input
            type="tel"
            id="telefono"
            name="telefono"
            <?= !empty($registrationRequired['telefono']) ? 'required aria-required="true"' : '' ?>
            aria-describedby="telefono-error"
            class="auth-input"
            placeholder="<?= htmlspecialchars(__('+39 123 456 7890'), ENT_QUOTES, 'UTF-8') ?>"
            value="<?php echo htmlspecialchars($_GET['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <span id="telefono-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <label for="indirizzo" class="auth-label">
            <?= __('Indirizzo completo') ?><?= !empty($registrationRequired['indirizzo']) ? ' *' : '' ?>
          </label>
          <textarea
            id="indirizzo"
            name="indirizzo"
            <?= !empty($registrationRequired['indirizzo']) ? 'required aria-required="true"' : '' ?>
            aria-describedby="indirizzo-error"
            rows="3"
            class="auth-input"
            placeholder="<?= htmlspecialchars(__('Via, numero civico, città, CAP'), ENT_QUOTES, 'UTF-8') ?>"
          ><?php echo htmlspecialchars($_GET['indirizzo'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
          <span id="indirizzo-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
        </div>

        <div class="auth-grid">
          <div>
            <label for="data_nascita" class="auth-label">
              <?= __('Data di nascita') ?>
            </label>
            <input
              type="date"
              id="data_nascita"
              name="data_nascita"
              class="auth-input"
              value="<?php echo htmlspecialchars($_GET['data_nascita'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            />
          </div>

          <div>
            <label for="sesso" class="auth-label">
              <?= __('Sesso') ?>
            </label>
            <select
              id="sesso"
              name="sesso"
              class="auth-input"
            >
              <option value=""><?= __("-- Seleziona --") ?></option>
              <option value="M"><?= __('Maschio') ?></option>
              <option value="F"><?= __('Femmina') ?></option>
              <option value="Altro"><?= __('Altro') ?></option>
            </select>
          </div>
        </div>

        <div>
          <label for="cod_fiscale" class="auth-label">
            <?= __('Codice Fiscale') ?>
          </label>
          <input
            type="text"
            id="cod_fiscale"
            name="cod_fiscale"
            maxlength="16"
            class="auth-input"
            placeholder="<?= htmlspecialchars(__('es. RSSMRA80A01H501U'), ENT_QUOTES, 'UTF-8') ?>"
            style="text-transform: uppercase;"
            value="<?php echo htmlspecialchars($_GET['cod_fiscale'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <p class="auth-help"><?= __("Opzionale") ?></p>
        </div>

        <?php foreach (($customFields ?? []) as $cf): ?>
          <?php $cfId = (int) $cf['id']; $cfName = 'custom_field[' . $cfId . ']'; ?>
          <div>
            <?php if ($cf['tipo'] === 'checkbox'): ?>
              <label class="auth-check">
                <input type="checkbox" name="<?= htmlspecialchars($cfName, ENT_QUOTES, 'UTF-8') ?>" value="1"
                  <?= $cf['obbligatorio'] ? 'required aria-required="true"' : '' ?>
                  >
                <span><?= htmlspecialchars($cf['etichetta'], ENT_QUOTES, 'UTF-8') ?><?= $cf['obbligatorio'] ? ' *' : '' ?></span>
              </label>
            <?php else: ?>
              <label for="custom_field_<?= $cfId ?>" class="auth-label">
                <?= htmlspecialchars($cf['etichetta'], ENT_QUOTES, 'UTF-8') ?><?= $cf['obbligatorio'] ? ' *' : '' ?>
              </label>
              <?php if ($cf['tipo'] === 'textarea'): ?>
                <textarea id="custom_field_<?= $cfId ?>" name="<?= htmlspecialchars($cfName, ENT_QUOTES, 'UTF-8') ?>" rows="3"
                  <?= $cf['obbligatorio'] ? 'required aria-required="true"' : '' ?>
                  class="auth-input"></textarea>
              <?php else: ?>
                <?php $cfType = in_array($cf['tipo'], ['email', 'url', 'number'], true) ? $cf['tipo'] : 'text'; ?>
                <input type="<?= $cfType ?>" id="custom_field_<?= $cfId ?>" name="<?= htmlspecialchars($cfName, ENT_QUOTES, 'UTF-8') ?>"
                  <?= $cf['obbligatorio'] ? 'required aria-required="true"' : '' ?>
                  class="auth-input" />
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php
          // #360: language preference — drives the account's UI language and
          // the language of every email the library sends. Rendered only on
          // multi-language installs (same rule as the profile page); defaults
          // to the language the visitor is browsing the form in.
          $availableLocales = \App\Support\I18n::getAvailableLocales();
          $currentLocale = \App\Support\I18n::getLocale();
          if (count($availableLocales) > 1):
        ?>
        <div>
          <label for="locale" class="auth-label">
            <?= __('Lingua preferita') ?>
          </label>
          <select
            id="locale"
            name="locale"
            class="auth-input"
          >
            <?php foreach ($availableLocales as $code => $name): ?>
              <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                <?= $code === $currentLocale ? 'selected' : '' ?>>
                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="auth-help"><?= __('Usata per l\'interfaccia e per le email che riceverai dalla biblioteca.') ?></p>
        </div>
        <?php endif; ?>

        <div class="auth-grid">
          <div>
            <label for="password" class="auth-label">
              Password
            </label>
            <input
              type="password"
              id="password"
              name="password"
              required aria-required="true"
              autocomplete="new-password"
              aria-describedby="password-error"
              class="auth-input"
              placeholder="<?= htmlspecialchars(__('••••••••'), ENT_QUOTES, 'UTF-8') ?>"
            />
            <span id="password-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
          </div>

          <div>
            <label for="password_confirm" class="auth-label">
              Conferma Password
            </label>
            <input
              type="password"
              id="password_confirm"
              name="password_confirm"
              required aria-required="true"
              autocomplete="new-password"
              aria-describedby="password_confirm-error"
              class="auth-input"
              placeholder="<?= htmlspecialchars(__('••••••••'), ENT_QUOTES, 'UTF-8') ?>"
            />
            <span id="password_confirm-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
          </div>
        </div>

        <div>
          <div class="auth-check">
            <input
              id="privacy_acceptance"
              name="privacy_acceptance"
              type="checkbox"
              required aria-required="true"
              aria-describedby="privacy_acceptance-error"
            />
            <label for="privacy_acceptance">
              <?= __('Accetto la') ?> <a href="<?= htmlspecialchars(route_path('privacy'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link"><?= __('Privacy Policy') ?></a>.
            </label>
          </div>
            <span id="privacy_acceptance-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <button
            type="submit"
            class="auth-btn"
          >
            <?= __('Registrati') ?>
          </button>
        </div>
      </form>

      <div>
        <p class="auth-switch">
          <?= __('Hai già un account?') ?> 
          <a href="<?= htmlspecialchars(route_path('login'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
            <?= __('Accedi') ?>
          </a>
        </p>
      </div>
    </main>

    <!-- Footer Links -->
    <div class="auth-footer">
      <div class="auth-footer-links">
        <a href="<?= htmlspecialchars(route_path('privacy'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
          <?= __('Privacy Policy') ?>
        </a>
        <a href="<?= htmlspecialchars(route_path('contact'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
          <?= __('Contatti') ?>
        </a>
      </div>
      <p class="auth-copy">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>. <?= __('Tutti i diritti riservati.') ?>
      </p>
    </div>
  </div>
</div>

<script>
// Password strength validation
document.addEventListener('DOMContentLoaded', function() {
  const password = document.getElementById('password');
  const confirmPassword = document.getElementById('password_confirm');
  const form = document.querySelector('form');

  if (password && confirmPassword && form) {
    form.addEventListener('submit', function(e) {
      if (password.value !== confirmPassword.value) {
        e.preventDefault();
        window.SwalApp.error(undefined, <?= json_encode(__("Le password non coincidono!"), JSON_HEX_TAG) ?>);
        confirmPassword.focus();
        return false;
      }

      if (password.value.length < 8) {
        e.preventDefault();
        window.SwalApp.error(undefined, <?= json_encode(__("La password deve essere lunga almeno 8 caratteri!"), JSON_HEX_TAG) ?>);
        password.focus();
        return false;
      }
    });
  }
});
</script>

<?php require __DIR__ . '/../partials/cookie-banner.php'; ?>

</body>
</html>
