# Configurazione Google Books API

## ✅ Verifica Completata

Il sistema di integrazione con Google Books API è **completamente funzionante**:

- ✅ Backend salva/carica correttamente l'API key
- ✅ Frontend con modal e form funzionanti
- ✅ Plugin Open Library integra Google Books
- ✅ Logging completo per debug

## 🔑 Come Ottenere l'API Key di Google Books

### 1. Vai su Google Cloud Console

Apri: https://console.cloud.google.com/

### 2. Crea un Nuovo Progetto (se necessario)

1. Clicca su **"Select a project"** in alto
2. Clicca su **"New Project"**
3. Nome: `Biblioteca` (o quello che preferisci)
4. Clicca **"Create"**

### 3. Abilita Google Books API

1. Nel menu laterale, vai su **"APIs & Services" → "Library"**
2. Cerca **"Books API"**
3. Clicca su **"Books API"**
4. Clicca su **"Enable"**

### 4. Crea le Credenziali

1. Nel menu laterale, vai su **"APIs & Services" → "Credentials"**
2. Clicca su **"+ CREATE CREDENTIALS"**
3. Seleziona **"API key"**
4. Copia la chiave (inizia con `AIza...`)
5. *Opzionale*: Clicca su **"RESTRICT KEY"** per limitare l'uso solo a Books API

### 5. Configura la Chiave nel Sistema

1. Vai su: http://localhost:8000/admin/plugins
2. Trova il plugin **"Open Library Scraper"**
3. Clicca su **"Configura Google Books"**
4. Incolla la tua API key nel campo
5. Clicca su **"Salva API Key"**
6. Apri la **Console del Browser** (F12) per vedere i log di conferma

## 🎯 Come Funziona l'Integrazione

### Ordine di Priorità (dal più alto al più basso)

1. **Scraping Pro** (priority 2) - LibreriaUniversitaria + Feltrinelli
2. **Google Books API** (priority 4) - **SOLO se hai configurato l'API key**
3. **Open Library API** (priority 5) - Fallback gratuito

### Cosa Succede Quando Importi un ISBN

```
1. L'utente inserisce un ISBN nel form "Importa da ISBN"
2. Il sistema controlla quale plugin può gestire la richiesta
3. Prima prova: Scraping Pro (se attivo)
   - Se trova dati → restituisce
   - Se non trova → passa al successivo
4. Seconda prova: Google Books (se hai l'API key)
   - Se trova dati → restituisce
   - Se non trova → passa al successivo
5. Terza prova: Open Library
   - Se trova dati → restituisce
   - Se non trova → errore "ISBN non trovato"
```

## 🐛 Debug

### Log Frontend (Console Browser)

Quando salvi l'API key, dovresti vedere:

```
🔑 saveGoogleBooksKey() called
📋 Plugin ID: 15
🔐 API Key length: 39
📤 Sending request to: /admin/plugins/15/settings
📥 Response status: 200
📦 Response data: {success: true, message: "..."}
```

### Log Backend (storage/app.log o PHP error log)

Quando salvi l'API key:

```
[PluginController] updateSettings called
[PluginController] Plugin ID: 15
[PluginController] Plugin name: open-library
[PluginController] API key length: 39
[PluginController] Save result: true
[PluginController] Settings saved successfully
```

Quando importi un ISBN con Google Books attivo:

```
[OpenLibrary] Google Books API request for ISBN: 9788804671664
[OpenLibrary] Google Books returned 1 result(s)
```

### Verifica Manuale nel Database

```bash
php -r "
require 'vendor/autoload.php';
\$envFile = '.env';
if (file_exists(\$envFile)) {
    \$lines = file(\$envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (\$lines as \$line) {
        if (strpos(trim(\$line), '#') === 0 || strpos(\$line, '=') === false) continue;
        list(\$name, \$value) = explode('=', \$line, 2);
        \$_ENV[trim(\$name)] = trim(\$value);
        putenv(trim(\$name) . '=' . trim(\$value));
    }
}
\$containerDefinitions = require 'config/container.php';
\$container = new \DI\Container(\$containerDefinitions);
\$pluginManager = \$container->get('pluginManager');
\$settings = \$pluginManager->getSettings(15);
echo 'API Key: ' . (\$settings['google_books_api_key'] ?? 'NON CONFIGURATA') . \"\n\";
"
```

## 🎓 Test ISBN Suggeriti

### ISBN Italiani
- `9788804671664` - "La ragazza del treno" di Paula Hawkins
- `9788807032714` - "1984" di George Orwell
- `9788804726425` - "Il Codice Da Vinci" di Dan Brown

### ISBN Inglesi
- `9780451526538` - "1984" di George Orwell (edizione inglese)
- `9780143058144` - "La ragazza del treno" (edizione inglese)

### ISBN Inesistente (per testare gestione errori)
- `9791254620649` - Dovrebbe ritornare errore "ISBN non trovato"

## 📊 Limiti Google Books API

- **Quota giornaliera**: 1.000 richieste/giorno (gratis)
- **Quota per 100 secondi**: 100 richieste
- **Quota per utente per 100 secondi**: 10 richieste

Se superi i limiti, riceverai errori HTTP 429 (Too Many Requests).

## ⚡ Cosa Fare Se Non Trova Nulla

1. **Verifica che l'API key sia salvata**:
   - Vai su `/admin/plugins`
   - Controlla che il bottone "Configura Google Books" sia visibile

2. **Controlla i log della console**:
   - Apri DevTools (F12)
   - Vai sulla tab Console
   - Cerca messaggi con emoji (🔑, 📤, 📥, ecc.)

3. **Controlla i log del server**:
   - Guarda `storage/app.log`
   - Cerca `[PluginController]` o `[OpenLibrary]`

4. **Verifica che il plugin sia attivo**:
   - Il plugin "Open Library Scraper" deve essere ATTIVO
   - Il plugin "Scraping Pro" può essere attivo o meno

## 🔒 Sicurezza

- ✅ L'API key è salvata nel database (tabella `plugin_settings`)
- ✅ **MAI** committare l'API key su Git
- ✅ Solo admin possono configurare le API key
- ✅ CSRF protection attivo
- ✅ La chiave non viene esposta nei log pubblici

## 📚 Risorse Utili

- [Google Books API Documentation](https://developers.google.com/books/docs/v1/using)
- [Google Cloud Console](https://console.cloud.google.com/)
- [Open Library API](https://openlibrary.org/developers/api)
