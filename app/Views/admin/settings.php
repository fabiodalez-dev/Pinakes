<?php use App\Support\ConfigStore; $cfg = ConfigStore::all(); ?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
      <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
        <i class="fas fa-cogs text-blue-600"></i>
        Impostazioni Applicazione
      </h1>
      <?php if(isset($_GET['saved'])): ?>
        <div class="mt-3 p-3 bg-green-50 text-green-800 border border-green-200 rounded" role="alert">Impostazioni salvate.</div>
      <?php endif; ?>
      <?php if(isset($_GET['error']) && $_GET['error']==='csrf'): ?>
        <div class="mt-3 p-3 bg-red-50 text-red-800 border border-red-200 rounded" role="alert">CSRF non valido.</div>
      <?php endif; ?>
    </div>

    <form method="post" action="/settings" class="space-y-8">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-envelope text-blue-600"></i>
            Email
          </h2>
        </div>
        <div class="card-body space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="form-label">Driver</label>
              <select name="mail_driver" class="form-input">
                <?php $drv = (string)($cfg['mail']['driver'] ?? 'mail'); ?>
                <option value="mail" <?php echo $drv==='mail'?'selected':''; ?>>PHP mail()</option>
                <option value="smtp" <?php echo $drv==='smtp'?'selected':''; ?>>SMTP (custom)</option>
                <option value="phpmailer" <?php echo $drv==='phpmailer'?'selected':''; ?>>PHPMailer</option>
              </select>
            </div>
            <div>
              <label class="form-label">From Email</label>
              <input name="from_email" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['mail']['from_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">From Name</label>
              <input name="from_name" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['mail']['from_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="form-label">SMTP Host</label>
              <input name="smtp_host" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['mail']['smtp']['host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">SMTP Port</label>
              <input name="smtp_port" type="number" class="form-input" value="<?php echo (int)($cfg['mail']['smtp']['port'] ?? 587); ?>" />
            </div>
            <div>
              <label class="form-label">SMTP Username</label>
              <input name="smtp_username" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['mail']['smtp']['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">SMTP Password</label>
              <input name="smtp_password" type="password" autocomplete="off" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['mail']['smtp']['password'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">Encryption</label>
              <select name="smtp_encryption" class="form-input">
                <?php $enc = (string)($cfg['mail']['smtp']['encryption'] ?? 'tls'); ?>
                <option value="tls" <?php echo $enc==='tls'?'selected':''; ?>>TLS</option>
                <option value="ssl" <?php echo $enc==='ssl'?'selected':''; ?>>SSL</option>
                <option value="none" <?php echo $enc==='none'?'selected':''; ?>>Nessuna</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-user-check text-blue-600"></i>
            Registrazione
          </h2>
        </div>
        <div class="card-body space-y-4">
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="require_admin_approval" value="1" <?php echo (($cfg['registration']['require_admin_approval'] ?? true) ? 'checked' : ''); ?> />
            <span>Richiedi approvazione admin dopo la conferma email</span>
          </label>
        </div>
      </div>

      <!-- CMS Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-file-alt text-blue-600"></i>
            Gestione Contenuti (CMS)
          </h2>
        </div>
        <div class="card-body">
          <div class="flex items-center justify-between p-4 bg-blue-50 border border-blue-200 rounded">
            <div>
              <h3 class="font-semibold text-blue-900"><i class="fas fa-info-circle mr-2"></i>Pagina "Chi Siamo"</h3>
              <p class="text-sm text-blue-700 mt-1">Gestisci il contenuto della pagina Chi Siamo con testo e immagine</p>
            </div>
            <a href="/admin/cms/chi-siamo" class="btn-primary whitespace-nowrap">
              <i class="fas fa-edit mr-2"></i>Modifica
            </a>
          </div>
        </div>
      </div>

      <!-- Cron Job Configuration -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-clock text-blue-600"></i>
            Configurazione Cron Job
          </h2>
        </div>
        <div class="card-body space-y-4">
          <div class="p-4 bg-blue-50 border border-blue-200 rounded">
            <h3 class="font-semibold text-blue-900 mb-2"><i class="fas fa-info-circle mr-2"></i>Notifiche Automatiche</h3>
            <p class="text-sm text-blue-700 mb-3">Il sistema include un cron job che gestisce automaticamente:</p>
            <ul class="text-sm text-blue-700 space-y-1 mb-3">
              <li>• Avvisi scadenza prestiti (configurabile in Impostazioni → Avanzate, default 3 giorni prima)</li>
              <li>• Notifiche prestiti scaduti</li>
              <li>• Notifiche disponibilità libri in wishlist</li>
              <li>• Manutenzione giornaliera del database</li>
            </ul>
          </div>

          <div class="space-y-4">
            <h3 class="font-semibold text-gray-900">Installazione Cron Job</h3>

            <div class="bg-gray-100 p-4 rounded border">
              <h4 class="font-medium text-gray-800 mb-2">1. Accesso al server</h4>
              <p class="text-sm text-gray-600 mb-2">Accedi al server tramite SSH e modifica il crontab:</p>
              <code class="block bg-gray-800 text-green-400 p-2 rounded text-sm">crontab -e</code>
            </div>

            <div class="bg-gray-100 p-4 rounded border">
              <h4 class="font-medium text-gray-800 mb-2">2. Aggiungi una delle configurazioni seguenti:</h4>

              <div class="space-y-3">
                <div>
                  <p class="text-sm text-gray-600 mb-1"><strong>Opzione A:</strong> Esecuzione ogni ora (8:00-20:00)</p>
                  <code class="block bg-gray-800 text-green-400 p-2 rounded text-sm break-all">
0 8-20 * * * /usr/bin/php <?php echo __DIR__; ?>/../../../cron/automatic-notifications.php >> <?php echo __DIR__; ?>/../../../logs/cron.log 2>&1
                  </code>
                </div>

                <div>
                  <p class="text-sm text-gray-600 mb-1"><strong>Opzione B:</strong> Ogni 15 minuti nei giorni lavorativi (8:00-18:00)</p>
                  <code class="block bg-gray-800 text-green-400 p-2 rounded text-sm break-all">
*/15 8-18 * * 1-5 /usr/bin/php <?php echo __DIR__; ?>/../../../cron/automatic-notifications.php >> <?php echo __DIR__; ?>/../../../logs/cron.log 2>&1
                  </code>
                </div>

                <div>
                  <p class="text-sm text-gray-600 mb-1"><strong>Opzione C:</strong> Esecuzione ogni 30 minuti (consigliato)</p>
                  <code class="block bg-gray-800 text-green-400 p-2 rounded text-sm break-all">
*/30 * * * * /usr/bin/php <?php echo __DIR__; ?>/../../../cron/automatic-notifications.php >> <?php echo __DIR__; ?>/../../../logs/cron.log 2>&1
                  </code>
                </div>
              </div>
            </div>

            <div class="bg-yellow-50 p-4 rounded border border-yellow-200">
              <h4 class="font-medium text-yellow-800 mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>Note importanti:</h4>
              <ul class="text-sm text-yellow-700 space-y-1">
                <li>• Sostituisci <code>/usr/bin/php</code> con il percorso corretto di PHP sul tuo server</li>
                <li>• Assicurati che il path assoluto dello script sia corretto</li>
                <li>• Crea la cartella logs se non esiste: <code>mkdir -p logs</code></li>
                <li>• Verifica i permessi di esecuzione: <code>chmod +x cron/automatic-notifications.php</code></li>
              </ul>
            </div>

            <div class="bg-green-50 p-4 rounded border border-green-200">
              <h4 class="font-medium text-green-800 mb-2"><i class="fas fa-check-circle mr-2"></i>Test del cron job:</h4>
              <p class="text-sm text-green-700 mb-2">Per testare lo script manualmente:</p>
              <code class="block bg-gray-800 text-green-400 p-2 rounded text-sm">
cd <?php echo dirname(__DIR__, 3); ?><br>
php cron/automatic-notifications.php
              </code>
            </div>
          </div>
        </div>
      </div>

      <!-- Cookie Banner Configuration -->
      <form method="post" action="/admin/settings/cookie-banner">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-cookie-bite text-blue-600"></i>
            Testi Cookie Banner
          </h2>
        </div>
        <div class="card-body space-y-6">
          <!-- Banner Section -->
          <div class="space-y-4">
            <h3 class="font-semibold text-gray-800 border-b pb-2">Testi Banner</h3>
            <div>
              <label class="form-label">Descrizione Banner</label>
              <textarea name="cookie_banner_description" class="form-input" rows="3"><?php echo htmlspecialchars((string)($cfg['cookie_banner']['banner_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
              <p class="text-xs text-gray-500 mt-1">Testo principale mostrato nel banner. Puoi usare HTML.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="form-label">Pulsante "Accetta Tutti"</label>
                <input name="cookie_accept_all_text" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['cookie_banner']['accept_all_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
              </div>
              <div>
                <label class="form-label">Pulsante "Rifiuta"</label>
                <input name="cookie_reject_non_essential_text" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['cookie_banner']['reject_non_essential_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
              </div>
              <div>
                <label class="form-label">Pulsante "Preferenze"</label>
                <input name="cookie_preferences_button_text" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['cookie_banner']['preferences_button_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
              </div>
            </div>
          </div>

          <!-- Preferences Modal Section -->
          <div class="space-y-4">
            <h3 class="font-semibold text-gray-800 border-b pb-2">Testi Modale Preferenze</h3>
            <div>
              <label class="form-label">Titolo Modale</label>
              <input name="cookie_preferences_title" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['cookie_banner']['preferences_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">Descrizione Modale</label>
              <textarea name="cookie_preferences_description" class="form-input" rows="3"><?php echo htmlspecialchars((string)($cfg['cookie_banner']['preferences_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
              <p class="text-xs text-gray-500 mt-1">Descrizione nella modale preferenze. Puoi usare HTML.</p>
            </div>
          </div>

          <!-- Essential Cookies Section -->
          <div class="space-y-4">
            <h3 class="font-semibold text-gray-800 border-b pb-2">Cookie Essenziali</h3>
            <div>
              <label class="form-label">Nome Categoria</label>
              <input name="cookie_essential_name" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['cookie_banner']['cookie_essential_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">__("Descrizione")</label>
              <textarea name="cookie_essential_description" class="form-input" rows="2"><?php echo htmlspecialchars((string)($cfg['cookie_banner']['cookie_essential_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
          </div>

          <!-- Analytics Cookies Section -->
          <div class="space-y-4">
            <h3 class="font-semibold text-gray-800 border-b pb-2">Cookie Analitici</h3>
            <div class="p-3 bg-blue-50 border border-blue-200 rounded">
              <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="show_analytics" value="1" <?php echo (($cfg['cookie_banner']['show_analytics'] ?? true) ? 'checked' : ''); ?> />
                <span class="font-medium text-blue-900">Mostra categoria "Cookie Analitici"</span>
              </label>
              <p class="text-xs text-blue-700 mt-1">Disabilita se il tuo sito non usa cookie analitici (es. Google Analytics)</p>
            </div>
            <div>
              <label class="form-label">Nome Categoria</label>
              <input name="cookie_analytics_name" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['cookie_banner']['cookie_analytics_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">__("Descrizione")</label>
              <textarea name="cookie_analytics_description" class="form-input" rows="2"><?php echo htmlspecialchars((string)($cfg['cookie_banner']['cookie_analytics_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="bg-blue-50 p-4 rounded border border-blue-200">
              <p class="text-sm text-blue-900 font-medium mb-2">
                <i class="fas fa-info-circle mr-2"></i>Codice JavaScript Analytics
              </p>
              <p class="text-xs text-blue-800">
                Per inserire il codice JavaScript Analytics (Google Analytics, Matomo, ecc.),
                vai su <a href="/admin/settings?tab=advanced#advanced" class="underline font-semibold hover:text-blue-900">Impostazioni → Avanzate</a>
                nella sezione "JavaScript Analitici".
              </p>
            </div>
          </div>

          <!-- Marketing Cookies Section -->
          <div class="space-y-4">
            <h3 class="font-semibold text-gray-800 border-b pb-2">Cookie di Marketing</h3>
            <div class="p-3 bg-blue-50 border border-blue-200 rounded">
              <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="show_marketing" value="1" <?php echo (($cfg['cookie_banner']['show_marketing'] ?? true) ? 'checked' : ''); ?> />
                <span class="font-medium text-blue-900">Mostra categoria "Cookie di Marketing"</span>
              </label>
              <p class="text-xs text-blue-700 mt-1">Disabilita se il tuo sito non usa cookie di marketing/advertising</p>
            </div>
            <div>
              <label class="form-label">Nome Categoria</label>
              <input name="cookie_marketing_name" class="form-input" value="<?php echo htmlspecialchars((string)($cfg['cookie_banner']['cookie_marketing_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
            </div>
            <div>
              <label class="form-label">__("Descrizione")</label>
              <textarea name="cookie_marketing_description" class="form-input" rows="2"><?php echo htmlspecialchars((string)($cfg['cookie_banner']['cookie_marketing_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
          </div>

          <!-- Save Button -->
          <div class="flex justify-end pt-4 border-t">
            <button type="submit" class="btn-primary">
              <i class="fas fa-save mr-2"></i>Salva Testi Cookie Banner
            </button>
          </div>
        </div>
      </div>
      </form>

      <!-- Email Templates -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-envelope-open text-blue-600"></i>
            Template Email
          </h2>
        </div>
        <div class="card-body">
          <div class="mb-4">
            <label class="form-label">Seleziona Template</label>
            <select id="template-selector" class="form-input">
              <option value="">-- Seleziona un template --</option>
              <?php
              use App\Support\SettingsMailTemplates;
              foreach (SettingsMailTemplates::keys() as $key):
                $def = SettingsMailTemplates::get($key);
              ?>
              <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($def['label'] ?? $key); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="template-editor" style="display: none;">
            <form method="post" id="template-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" id="template-name" name="template-name" value="">

              <div class="mb-4">
                <label class="form-label">Soggetto Email</label>
                <input type="text" id="template-subject" name="subject" class="form-input" placeholder="Oggetto dell'email">
              </div>

              <div class="mb-4">
                <label class="form-label">Corpo Email</label>
                <textarea id="template-body" name="body" class="form-input" style="min-height: 400px; display: none;"></textarea>
              </div>

              <div class="bg-blue-50 p-4 rounded border border-blue-200 mb-4">
                <h4 class="font-medium text-blue-900 mb-2"><i class="fas fa-info-circle mr-2"></i>Variabili disponibili:</h4>
                <div id="template-placeholders" class="text-sm text-blue-700"></div>
              </div>

              <div class="flex gap-2">
                <button type="submit" class="btn-primary"><i class="fas fa-save mr-2"></i>Salva Template</button>
                <button type="button" onclick="closeTemplateEditor()" class="btn-secondary"><i class="fas fa-times mr-2"></i>__("Annulla")</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="flex justify-end">
        <button class="btn-primary"><i class="fas fa-save mr-2"></i>Salva Impostazioni</button>
      </div>
    </form>
  </div>
</div>

<script src="/assets/tinymce/tinymce.min.js"></script>
<script>
// Wait for TinyMCE to be available
var tinymceRetries = 0;
var tinymceCheckInterval = setInterval(function() {
  tinymceRetries++;
  if (typeof tinymce !== 'undefined') {
    clearInterval(tinymceCheckInterval);
    initTinyMCE();
  } else if (tinymceRetries > 50) {
    clearInterval(tinymceCheckInterval);
    console.error('TinyMCE failed to load after 50 attempts (5 seconds)');
  }
}, 100);

function initTinyMCE() {
// TinyMCE Configuration
tinymce.init({
  selector: '#template-body',
  plugins: 'lists link image table code help',
  toolbar: 'undo redo | styles | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist | link image | code | help',
  menubar: 'file edit view insert format tools help',
  height: 400,
  content_css: 'default',
  branding: false,
  relative_urls: false,
  style_formats: [
    { title: 'Paragraph', format: 'p' },
    { title: 'Heading 1', format: 'h1' },
    { title: 'Heading 2', format: 'h2' },
    { title: 'Heading 3', format: 'h3' },
    { title: 'Heading 4', format: 'h4' },
    { title: 'Heading 5', format: 'h5' },
    { title: 'Heading 6', format: 'h6' }
  ],
  setup: function(editor) {
    editor.on('init', function() {
      // TinyMCE initialized successfully
    });
  },
  init_instance_callback: function(editor) {
    // Editor initialized
  },
  oninit: function() {
    // TinyMCE init callback
  }
});
}

const templates = {
  <?php
  $keys = SettingsMailTemplates::keys();
  foreach ($keys as $index => $key):
    $def = SettingsMailTemplates::get($key);
    echo '"' . $key . '": ' . json_encode($def);
    if ($index < count($keys) - 1) echo ',';
    echo "\n  ";
  endforeach;
  ?>
};

document.getElementById('template-selector').addEventListener('change', function() {
  const templateKey = this.value;
  if (templateKey && templates[templateKey]) {
    const template = templates[templateKey];
    document.getElementById('template-name').value = templateKey;
    document.getElementById('template-subject').value = template.subject || '';

    // Set TinyMCE content
    setTimeout(function() {
      const editor = tinymce.get('template-body');
      if (editor) {
        editor.setContent(template.body || '');
      } else {
        console.warn('TinyMCE editor not found, using textarea fallback');
        document.getElementById('template-body').value = template.body || '';
      }
    }, 100);

    // Show placeholders
    let placeholderHTML = '<ul class="list-disc pl-5">';
    if (template.placeholders && template.placeholders.length > 0) {
      template.placeholders.forEach(ph => {
        placeholderHTML += '<li><code>{{' + ph + '}}</code></li>';
      });
    }
    placeholderHTML += '</ul>';
    document.getElementById('template-placeholders').innerHTML = placeholderHTML;

    document.getElementById('template-editor').style.display = 'block';
  }
});

document.getElementById('template-form').addEventListener('submit', async function(e) {
  e.preventDefault();

  const templateKey = document.getElementById('template-name').value;
  const subject = document.getElementById('template-subject').value;
  const editor = tinymce.get('template-body');
  const body = editor ? editor.getContent() : document.getElementById('template-body').value;

  try {
    const response = await fetch('/admin/settings/templates/' + encodeURIComponent(templateKey), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: new URLSearchParams({
        csrf_token: document.querySelector('input[name="csrf_token"]').value,
        subject: subject,
        body: body
      })
    });

    if (response.ok) {
      alert('Template aggiornato con successo!');
      closeTemplateEditor();
    } else {
      alert('Errore nell\'aggiornamento del template');
    }
  } catch (error) {
    console.error('Error:', error);
    alert('Errore: ' + error.message);
  }
});

function closeTemplateEditor() {
  document.getElementById('template-selector').value = '';
  document.getElementById('template-editor').style.display = 'none';
  const editor = tinymce.get('template-body');
  if (editor) {
    editor.setContent('');
  }
  document.getElementById('template-subject').value = '';
}
</script>
