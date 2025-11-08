# 🌍 Sistema Internazionalizzazione Pinakes

Guida completa al sistema di internazionalizzazione (i18n) implementato in Pinakes.

---

## 📋 Architettura del Sistema

Il sistema i18n è basato su **5 componenti principali**:

1. **File JSON di traduzione** (`locale/*.json`)
2. **Tabella database `languages`** (gestione lingue attive)
3. **Classe `I18n`** (`app/Support/I18n.php`) - motore di traduzione
4. **Funzione helper `__()`** - scorciatoia per tradurre stringhe
5. **Pannello Admin** - interfaccia per gestire lingue e upload traduzioni

---

## 🎯 Come Funziona: Passo per Passo

### 1. File di Traduzione (JSON)

Le traduzioni sono salvate in file JSON nella cartella `locale/`:

```
locale/
├── it_IT.json  (vuoto - italiano è lingua sorgente)
├── en_US.json  (1988 traduzioni italiano → inglese)
└── de_DE.json  (futuro: tedesco, francese, ecc.)
```

**Formato del file `en_US.json`:**
```json
{
  "Benvenuto": "Welcome",
  "Hai %d messaggi": "You have %d messages",
  "Libro non trovato": "Book not found",
  "Accedi": "Login"
}
```

**Chiave → Valore:**
- **Chiave**: Testo italiano originale
- **Valore**: Traduzione nella lingua target

---

### 2. Tabella Database `languages`

Gestisce le lingue disponibili nell'applicazione:

```sql
CREATE TABLE languages (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(10) NOT NULL UNIQUE,         -- es. 'it_IT', 'en_US'
  name VARCHAR(50) NOT NULL,                 -- es. 'Italian', 'English'
  native_name VARCHAR(50) NOT NULL,          -- es. 'Italiano', 'English'
  flag_emoji VARCHAR(10) DEFAULT '🌐',      -- es. '🇮🇹', '🇬🇧'
  is_default TINYINT(1) DEFAULT 0,          -- 1 = lingua predefinita
  is_active TINYINT(1) DEFAULT 1,           -- 1 = lingua attiva/visibile
  translation_file VARCHAR(255),             -- percorso file JSON
  total_keys INT DEFAULT 0,                  -- numero totale stringhe
  translated_keys INT DEFAULT 0,             -- numero stringhe tradotte
  completion_percentage DECIMAL(5,2),        -- % completamento (es. 98.50)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Esempio dati:**
| code | name | native_name | flag_emoji | is_default | is_active | completion_percentage |
|------|------|-------------|------------|------------|-----------|----------------------|
| it_IT | Italian | Italiano | 🇮🇹 | 1 | 1 | 100.00 |
| en_US | English | English | 🇬🇧 | 0 | 1 | 98.50 |

---

### 3. Classe `I18n` - Motore di Traduzione

**File:** `app/Support/I18n.php`

**Metodi principali:**

```php
// Carica lingue dal database
I18n::loadFromDatabase($db);

// Imposta lingua corrente
I18n::setLocale('en_US');

// Traduci stringa
I18n::translate('Benvenuto');  // → "Welcome"

// Traduci con parametri sprintf
I18n::translate('Hai %d messaggi', 5);  // → "You have 5 messages"

// Ottieni lingua corrente
I18n::getLocale();  // → "en_US"

// Ottieni lingue disponibili
I18n::getAvailableLocales();  // → ['it_IT' => 'Italiano', 'en_US' => 'English']

// Traduci forme plurali
I18n::translatePlural('%d libro', '%d libri', $count);
```

**Logica di traduzione:**
1. Carica file JSON per locale corrente (es. `locale/en_US.json`)
2. Cerca la chiave nel JSON
3. Se trovata → restituisce traduzione
4. Se NON trovata → restituisce stringa originale (fallback)

**Caratteristiche:**
- ✅ **Caching in memoria**: Translations loaded once per request
- ✅ **Fallback intelligente**: Se manca traduzione, mostra originale
- ✅ **Lazy loading**: File JSON caricati solo quando necessario
- ✅ **Supporto sprintf**: Parametri dinamici con `%s`, `%d`, ecc.
- ✅ **Database-driven**: Lingue caricate da DB, fallback a hardcoded

---

### 4. Funzione Helper `__()`

**File:** Definita globalmente in `public/index.php` o `bootstrap`

**Uso nei file PHP:**

```php
// Traduzione semplice
echo __("Benvenuto");
// Output IT: Benvenuto
// Output EN: Welcome

// Traduzione con parametri
echo __("Hai %d messaggi", 5);
// Output IT: Hai 5 messaggi
// Output EN: You have 5 messages

// Nei form HTML
<label><?php echo __("Email"); ?></label>
<input type="text" placeholder="<?php echo __("Inserisci email"); ?>">

// Nei controller
$_SESSION['flash_success'] = __("Operazione completata con successo");

// Con escape HTML
echo HtmlHelper::e(__("Nome utente"));
```

**Implementazione:**
```php
/**
 * Translate a string using I18n system
 * Shorthand for I18n::translate()
 */
function __($message, ...$args) {
    return \App\Support\I18n::translate($message, ...$args);
}
```

---

### 5. Pannello Admin - Gestione Lingue

**URL:** `/admin/languages`

**Controller:** `app/Controllers/Admin/LanguagesController.php`
**Model:** `app/Models/Language.php`
**Views:** `app/Views/admin/languages/`

**Funzionalità disponibili:**

✅ **Visualizza tutte le lingue** con statistiche completamento
✅ **Aggiungi nuova lingua** (codice, nome, emoji, file JSON)
✅ **Modifica lingua esistente**
✅ **Elimina lingua** (non permette eliminare lingua predefinita)
✅ **Upload file JSON** di traduzione
✅ **Imposta lingua predefinita**
✅ **Attiva/disattiva lingua**
✅ **Ricalcola statistiche** traduzioni automaticamente

**Esempio interfaccia:**
```
┌─────────────────────────────────────────────────────────┐
│ Gestione Lingue                         [+ Aggiungi]    │
├─────────────────────────────────────────────────────────┤
│ 🇮🇹 Italiano (it_IT)                                    │
│    Predefinita: ✓  Attiva: ✓  Completamento: 100%      │
│    [Modifica] [Elimina] [Imposta default]              │
├─────────────────────────────────────────────────────────┤
│ 🇬🇧 English (en_US)                                     │
│    Predefinita: ✗  Attiva: ✓  Completamento: 98.5%     │
│    [Modifica] [Elimina] [Imposta default]              │
│    1988 traduzioni / 2000 totali                        │
└─────────────────────────────────────────────────────────┘
```

**Route disponibili:**

| Metodo | URL | Azione |
|--------|-----|--------|
| GET | `/admin/languages` | Lista tutte le lingue |
| GET | `/admin/languages/create` | Form crea nuova lingua |
| POST | `/admin/languages` | Salva nuova lingua |
| GET | `/admin/languages/{code}/edit` | Form modifica lingua |
| POST | `/admin/languages/{code}` | Aggiorna lingua |
| POST | `/admin/languages/{code}/delete` | Elimina lingua |
| POST | `/admin/languages/{code}/toggle-active` | Attiva/disattiva lingua |
| POST | `/admin/languages/{code}/set-default` | Imposta lingua predefinita |
| POST | `/admin/languages/refresh-stats` | Ricalcola statistiche |

---

## 🔄 Workflow: Aggiungere Nuova Lingua

### Opzione A: Via Admin UI (Raccomandato)

1. **Vai su** `/admin/languages`
2. **Click** "Aggiungi Lingua"
3. **Compila form:**
   - Codice: `de_DE` (tedesco)
   - Nome inglese: `German`
   - Nome nativo: `Deutsch`
   - Emoji bandiera: `🇩🇪`
   - **Upload file JSON** con traduzioni (opzionale)
   - Spunta "Lingua attiva" per renderla visibile
4. **Salva** → Lingua disponibile immediatamente

**Validazioni automatiche:**
- Codice univoco (non può duplicare codici esistenti)
- File JSON valido (controllo sintassi JSON)
- Nome e nome nativo obbligatori
- Calcolo statistiche automatico da file JSON

### Opzione B: Manuale (File + Database)

#### Step 1: Crea file JSON

```bash
cd locale/
touch de_DE.json
```

**Contenuto `locale/de_DE.json`:**
```json
{
  "Benvenuto": "Willkommen",
  "Accedi": "Anmelden",
  "Registrati": "Registrieren",
  "Catalogo": "Katalog",
  "I miei prestiti": "Meine Ausleihen"
}
```

#### Step 2: Inserisci record in database

```sql
INSERT INTO languages (
  code,
  name,
  native_name,
  flag_emoji,
  is_active,
  translation_file,
  total_keys,
  translated_keys,
  completion_percentage
) VALUES (
  'de_DE',
  'German',
  'Deutsch',
  '🇩🇪',
  1,
  'locale/de_DE.json',
  1988,  -- numero totale stringhe nell'app
  5,     -- numero stringhe tradotte
  0.25   -- 5/1988 = 0.25%
);
```

#### Step 3: Ricalcola statistiche

Via admin: `/admin/languages` → Click "Aggiorna Statistiche"

Oppure manualmente:
```php
$languageModel = new \App\Models\Language($db);

// Leggi file JSON
$json = file_get_contents(__DIR__ . '/locale/de_DE.json');
$decoded = json_decode($json, true);

$totalKeys = count($decoded);
$translatedKeys = count(array_filter($decoded, fn($v) => !empty($v)));

// Aggiorna statistiche
$languageModel->updateStats('de_DE', $totalKeys, $translatedKeys);
```

---

## 🌟 Cambio Lingua dell'Applicazione

### ⚠️ Importante: Lingua Globale

Il sistema Pinakes usa **una singola lingua per tutta l'applicazione**, condivisa da tutti gli utenti. Non esiste uno switch lingua per singolo utente.

**Per cambiare la lingua dell'intera app:**
1. Vai su `/admin/languages`
2. Clicca sull'icona stella ⭐ della lingua desiderata
3. Conferma: "Questa diventerà la lingua dell'intera applicazione per tutti gli utenti"
4. L'app si ricarica nella nuova lingua immediatamente

### Come Funziona Tecnicamente

**1. Lingua predefinita dal database:**

La lingua con `is_default = 1` nella tabella `languages` viene usata per tutta l'app.

```sql
SELECT code FROM languages WHERE is_default = 1;
-- Risultato: it_IT (oppure en_US, de_DE, ecc.)
```

**2. Caricamento automatico all'avvio:**

```php
// File: public/index.php

// Load languages from database
\App\Support\I18n::loadFromDatabase($db);

// Imposta automaticamente la lingua con is_default=1
// Non serve altro codice - è automatico!
```

**3. Imposta nuova lingua predefinita (Admin):**

```php
// Controller: LanguagesController::setDefault()

public function setDefault(Request $request, Response $response, \mysqli $db, array $args): Response
{
    $code = $args['code'] ?? '';
    $languageModel = new Language($db);

    // Imposta questa lingua come predefinita
    // Automaticamente rimuove is_default dalle altre lingue
    $languageModel->setDefault($code);

    $_SESSION['flash_success'] = __("Lingua predefinita impostata con successo");
    return $response->withHeader('Location', '/admin/languages')->withStatus(302);
}
```

### Implementazione nel Bootstrap

**File: `public/index.php` (semplificato)**

```php
<?php
session_start();

// Database connection
$db = new mysqli(/* ... */);

// Load languages from database
// Questo metodo imposta automaticamente la lingua con is_default=1
\App\Support\I18n::loadFromDatabase($db);

// Fine! Non serve altro codice per gestire la lingua
// Tutte le chiamate a __() usano automaticamente la lingua predefinita

echo __("Benvenuto");  // → tradotto nella lingua predefinita dell'app
```

---

## 📊 Statistiche Traduzioni

Il sistema traccia automaticamente:

- **Total Keys**: Numero totale stringhe traducibili nell'app
- **Translated Keys**: Numero stringhe effettivamente tradotte
- **Completion %**: Percentuale completamento (98.5% = quasi completo)

### Calcolo Automatico

**Quando viene caricato un file JSON:**

```php
// Leggi file JSON
$jsonContent = file_get_contents($uploadedFile['tmp_name']);
$decoded = json_decode($jsonContent, true);

// Conta chiavi
$totalKeys = count($decoded);  // es. 2000

// Conta chiavi con traduzione (valore non vuoto)
$translatedKeys = count(array_filter($decoded, fn($v) => !empty($v)));  // es. 1970

// Calcola percentuale
$percentage = ($translatedKeys / $totalKeys) * 100;  // es. 98.50%

// Aggiorna database
$languageModel->updateStats('en_US', $totalKeys, $translatedKeys);
```

### Ricalcolo Manuale

Via admin: `/admin/languages` → Click "Aggiorna Statistiche"

**Cosa fa:**
1. Legge tutti i file JSON di tutte le lingue
2. Conta chiavi totali e tradotte per ogni lingua
3. Aggiorna statistiche nel database
4. Mostra messaggi successo/errore

---

## 🚀 Stato Attuale Implementazione

### ✅ Completato

- **1988 traduzioni** italiano → inglese (99% completamento)
- **Installer completamente tradotto** (204 stringhe)
- **Tutte le pagine admin tradotte**
- **Tutte le stringhe hardcoded eliminate**
- **SEO metadata tradotto**
- **Template email supporto multilingua**
- **Pannello admin gestione lingue completo**
- **Database schema sincronizzato**

### 📁 File Principali Tradotti

**Installer (7 steps):**
- Step 1: Welcome + language selection
- Step 2: Requirements check
- Step 3: Database configuration
- Step 4: Site settings
- Step 5: Admin user creation
- Step 6: Email configuration
- Step 7: Completion

**Admin Dashboard:**
- Dashboard overview
- Gestione libri (CRUD)
- Gestione utenti (CRUD)
- Gestione prestiti
- Gestione categorie
- Gestione autori
- Gestione editori
- Gestione template email
- Gestione impostazioni
- Gestione lingue (nuovo!)

**Frontend:**
- Catalogo libri
- Dettaglio libro
- Registrazione utente
- Login/Logout
- Profilo utente
- I miei prestiti
- Wishlist
- Ricerca avanzata

**Email Templates:**
- Tutti gli 8 template email tradotti
- Supporto variabili dinamiche

**SEO & Metadata:**
- Meta title/description
- OG tags
- Breadcrumbs

---

## 🎓 Best Practices

### Per Developer

#### ✅ DO: Usa SEMPRE `__()` per stringhe visibili

```php
// ✅ GOOD
echo __("Libro non trovato");
$_SESSION['flash_error'] = __("Operazione fallita");
throw new Exception(__("Errore: parametro mancante"));

// ❌ BAD
echo "Libro non trovato";
$_SESSION['flash_error'] = "Operazione fallita";
throw new Exception("Errore: parametro mancante");
```

#### ✅ DO: Usa parametri sprintf per valori dinamici

```php
// ✅ GOOD
echo __("Trovati %d risultati", $count);
echo __("Benvenuto %s", $username);
echo __("Hai %d messaggi non letti", $unreadCount);

// ❌ BAD
echo __("Trovati") . " " . $count . " " . __("risultati");
echo __("Benvenuto") . " " . $username;
```

#### ✅ DO: Mantieni intere frasi come chiavi

```php
// ✅ GOOD - Frase completa
echo __("Il libro è stato aggiunto con successo");

// ❌ BAD - Frasi spezzate (impossibile tradurre correttamente)
echo __("Il libro") . " " . __("è stato") . " " . __("aggiunto");
```

#### ✅ DO: Fornisci contesto con commenti

```php
// ✅ GOOD - Commento spiega contesto
// Translators: This message appears when a user tries to borrow a book that's already checked out
echo __("Libro non disponibile per il prestito");

// Translators: Button text for submitting the login form
echo '<button>' . __("Accedi") . '</button>';
```

#### ❌ DON'T: NON tradurre questi elementi

```php
// ❌ NON tradurre nomi file/percorsi
$file = 'uploads/covers/book.jpg';  // NO __()

// ❌ NON tradurre chiavi di configurazione
$config['smtp_host'] = 'smtp.gmail.com';  // NO __()

// ❌ NON tradurre query SQL
$sql = "SELECT * FROM libri WHERE titolo = ?";  // NO __()

// ❌ NON tradurre log tecnici (OK per log utente)
error_log("Database connection failed");  // NO __() (log tecnico)
$_SESSION['flash_error'] = __("Errore di connessione");  // ✅ (messaggio utente)

// ❌ NON tradurre nomi variabili/costanti
define('MAX_FILE_SIZE', 5242880);  // NO __()
```

#### ✅ DO: Traduci questi elementi

```php
// ✅ Etichette form
echo '<label>' . __("Nome") . '</label>';

// ✅ Messaggi errore/successo
$_SESSION['flash_success'] = __("Libro aggiunto con successo");

// ✅ Testi pulsanti
echo '<button>' . __("Salva") . '</button>';

// ✅ Titoli/heading
echo '<h1>' . __("Catalogo Libri") . '</h1>';

// ✅ Placeholder
echo '<input placeholder="' . __("Cerca libro...") . '">';

// ✅ Testi email
$subject = __("Conferma registrazione");

// ✅ SEO metadata
$pageTitle = __("Catalogo Libri") . " - " . $siteName;
```

---

### Per Traduttori

#### ✅ DO: Mantieni placeholder sprintf identici

```json
{
  "Hai %d messaggi non letti": "You have %d unread messages",
  "Benvenuto %s": "Welcome %s",
  "Libro da %s a %s": "Book from %s to %s"
}
```

**IMPORTANTE:** `%d` = numero, `%s` = stringa - mantieni sempre l'ordine!

#### ✅ DO: Rispetta formattazione HTML

```json
{
  "<b>Attenzione:</b> Operazione irreversibile": "<b>Warning:</b> Irreversible operation",
  "Clicca <a href=\"%s\">qui</a> per continuare": "Click <a href=\"%s\">here</a> to continue"
}
```

#### ✅ DO: Testa traduzioni con valori variabili

Verifica che le traduzioni funzionino con:
- 0 elementi: "You have 0 messages"
- 1 elemento: "You have 1 message" (singolare!)
- Molti: "You have 42 messages"

#### ✅ DO: Mantieni consistenza terminologica

Crea un glossario:
- "Libro" → "Book" (non "Volume", "Title")
- "Prestito" → "Loan" (non "Borrow", "Checkout")
- "Utente" → "User" (non "Member", "Account")

#### ❌ DON'T: Non alterare variabili/placeholder

```json
// ❌ BAD - Rimozione placeholder
{
  "Hai %d messaggi": "You have messages"  // MANCA %d!
}

// ✅ GOOD
{
  "Hai %d messaggi": "You have %d messages"
}
```

#### ❌ DON'T: Non invertire ordine placeholder senza sprintf posizionale

```json
// ❌ BAD - Inverte %s senza indicare posizione
{
  "Prestito di %s dal %s al %s": "Loan from %s to %s of %s"
}

// ✅ GOOD - Usa sprintf posizionale se necessario
{
  "Prestito di %s dal %s al %s": "Loan from %2$s to %3$s of %1$s"
}
```

---

## 🔧 Troubleshooting

### Problema: Traduzione non appare (ancora italiano)

**Sintomi:**
```php
echo __("Benvenuto");
// Output: Benvenuto (invece di Welcome)
```

**Possibili cause e soluzioni:**

#### 1. Locale non impostato correttamente

```php
// Debug: Verifica locale corrente
echo "Locale attuale: " . I18n::getLocale();
// Deve essere 'en_US', non 'it_IT'

// Soluzione: Imposta locale
I18n::setLocale('en_US');
```

#### 2. Chiave non presente in file JSON

```bash
# Verifica che chiave esista
cd locale/
grep -i "Benvenuto" en_US.json
# Se vuoto → chiave mancante
```

**Soluzione:** Aggiungi traduzione
```json
{
  "Benvenuto": "Welcome"
}
```

#### 3. File JSON non caricato

```php
// Debug: Verifica file esiste
$file = __DIR__ . '/locale/en_US.json';
if (!file_exists($file)) {
    echo "FILE NON TROVATO!";
} else {
    echo "File esiste: " . filesize($file) . " bytes";
}

// Debug: Verifica JSON valido
$json = file_get_contents($file);
$decoded = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON INVALIDO: " . json_last_error_msg();
}
```

#### 4. Typo nella chiave

```php
// ❌ BAD - Typo
echo __("Benevenuto");  // 'Benevenuto' non esiste!

// ✅ GOOD
echo __("Benvenuto");
```

#### 5. Cache non aggiornato

Se hai modificato il file JSON ma non vedi le modifiche:

```php
// Forza ricaricamento resettando cache
I18n::setLocale(I18n::getLocale());  // Reset cache per locale corrente
```

O riavvia il server PHP:
```bash
# Se usi built-in server
pkill -f "php -S"
php -S localhost:8000 router.php
```

---

### Problema: Statistiche traduzioni errate

**Sintomi:**
- Completion percentage non aggiornato
- Total keys/Translated keys sbagliati

**Soluzione:**

Via admin: `/admin/languages` → "Aggiorna Statistiche"

Oppure manualmente:
```php
$languageModel = new \App\Models\Language($db);

// Per una lingua specifica
$code = 'en_US';
$file = __DIR__ . '/locale/' . $code . '.json';
$json = json_decode(file_get_contents($file), true);

$total = count($json);
$translated = count(array_filter($json, fn($v) => !empty($v)));

$languageModel->updateStats($code, $total, $translated);

// Per tutte le lingue
$languages = $languageModel->getAll();
foreach ($languages as $lang) {
    $file = __DIR__ . '/' . $lang['translation_file'];
    if (file_exists($file)) {
        $json = json_decode(file_get_contents($file), true);
        $total = count($json);
        $translated = count(array_filter($json, fn($v) => !empty($v)));
        $languageModel->updateStats($lang['code'], $total, $translated);
    }
}
```

---

### Problema: Upload JSON fallisce

**Sintomi:**
- Errore "Il file deve essere un JSON valido"
- File non salvato in `locale/`

**Possibili cause:**

#### 1. File non è JSON

```bash
# Verifica tipo file
file uploads/translation.json
# Output corretto: JSON data

# Se output è diverso → non è JSON
```

#### 2. JSON sintatticamente invalido

```bash
# Valida JSON con jq (se installato)
jq empty < translation.json
# Se errore → JSON invalido

# Oppure usa validator online:
# https://jsonlint.com/
```

**Errori comuni JSON:**
```json
// ❌ BAD - Virgola finale
{
  "Benvenuto": "Welcome",
}

// ❌ BAD - Chiavi non quotate
{
  Benvenuto: "Welcome"
}

// ❌ BAD - Apici singoli
{
  'Benvenuto': 'Welcome'
}

// ✅ GOOD
{
  "Benvenuto": "Welcome"
}
```

#### 3. Permessi cartella `locale/`

```bash
# Verifica permessi
ls -la locale/
# Deve essere scrivibile da web server

# Fix permessi
chmod 755 locale/
chmod 644 locale/*.json
```

---

### Problema: Lingua non appare nel dropdown

**Sintomi:**
- Lingua aggiunta ma non visibile in language selector

**Possibili cause:**

#### 1. Lingua non attiva

```sql
-- Verifica stato lingua
SELECT code, is_active FROM languages WHERE code = 'de_DE';

-- Se is_active = 0, attivala
UPDATE languages SET is_active = 1 WHERE code = 'de_DE';
```

#### 2. Cache lingue non aggiornato

```php
// Forza ricaricamento lingue dal database
I18n::loadFromDatabase($db);

// Verifica lingue disponibili
var_dump(I18n::getAvailableLocales());
```

#### 3. Frontend non ricarica lingue

Se il menu lingue è hardcoded, deve chiamare:
```php
$languageModel = new \App\Models\Language($db);
$activeLanguages = $languageModel->getActive();

foreach ($activeLanguages as $lang) {
    echo '<a href="?lang=' . $lang['code'] . '">';
    echo $lang['flag_emoji'] . ' ' . $lang['native_name'];
    echo '</a>';
}
```

---

## 📦 File e Cartelle Principali

```
biblioteca/
├── locale/                              (traduzioni JSON)
│   ├── it_IT.json                       (vuoto - italiano è sorgente)
│   └── en_US.json                       (1988 traduzioni)
│
├── app/
│   ├── Support/
│   │   └── I18n.php                     (motore traduzione)
│   │
│   ├── Models/
│   │   └── Language.php                 (CRUD lingue database)
│   │
│   ├── Controllers/Admin/
│   │   └── LanguagesController.php      (gestione admin)
│   │
│   └── Views/admin/languages/
│       ├── index.php                    (lista lingue)
│       ├── create.php                   (form crea lingua)
│       └── edit.php                     (form modifica lingua)
│
├── installer/
│   └── steps/                           (installer tradotto)
│       ├── step1.php                    (welcome + language selection)
│       ├── step2.php                    (requirements)
│       ├── step3.php                    (database)
│       ├── step4.php                    (site settings)
│       ├── step5.php                    (admin user)
│       ├── step6.php                    (email)
│       └── step7.php                    (completion)
│
└── database/
    └── schema.sql                       (tabella languages)
```

---

## 🌐 Codici Lingua Standard (ISO 639-1 + ISO 3166-1)

**Formato:** `{lingua}_{PAESE}`

| Codice | Nome Inglese | Nome Nativo | Emoji |
|--------|-------------|-------------|-------|
| `it_IT` | Italian | Italiano | 🇮🇹 |
| `en_US` | English (US) | English | 🇺🇸 |
| `en_GB` | English (UK) | English | 🇬🇧 |
| `de_DE` | German | Deutsch | 🇩🇪 |
| `fr_FR` | French | Français | 🇫🇷 |
| `es_ES` | Spanish | Español | 🇪🇸 |
| `pt_BR` | Portuguese (BR) | Português | 🇧🇷 |
| `pt_PT` | Portuguese (PT) | Português | 🇵🇹 |
| `ru_RU` | Russian | Русский | 🇷🇺 |
| `zh_CN` | Chinese (Simplified) | 简体中文 | 🇨🇳 |
| `zh_TW` | Chinese (Traditional) | 繁體中文 | 🇹🇼 |
| `ja_JP` | Japanese | 日本語 | 🇯🇵 |
| `ko_KR` | Korean | 한국어 | 🇰🇷 |
| `ar_SA` | Arabic | العربية | 🇸🇦 |

**Nota:** Usa sempre underscore (`_`), non trattino (`-`)

---

## 📝 Checklist: Aggiungere Nuova Lingua

Quando aggiungi una nuova lingua, segui questa checklist:

### Pre-requisiti
- [ ] Determina codice ISO (es. `de_DE`)
- [ ] Trova emoji bandiera (es. 🇩🇪)
- [ ] Prepara file JSON di traduzione

### Via Admin UI
- [ ] Vai su `/admin/languages`
- [ ] Click "Aggiungi Lingua"
- [ ] Compila form (codice, nome, emoji)
- [ ] Upload file JSON (se pronto)
- [ ] Spunta "Lingua attiva"
- [ ] Salva

### Testing
- [ ] Verifica lingua appare in `/admin/languages`
- [ ] Verifica statistiche (total keys, completion %)
- [ ] **Imposta come predefinita** (clicca stella ⭐)
- [ ] Ricarica pagina - l'intera app deve essere nella nuova lingua
- [ ] Verifica almeno 3 pagine tradotte correttamente:
  - [ ] Homepage/Catalogo
  - [ ] Admin dashboard
  - [ ] Form registrazione
- [ ] Verifica messaggi flash tradotti
- [ ] Verifica email templates (se applicabile)
- [ ] Verifica installer (se nuova installazione)
- [ ] **Ripristina lingua originale** (imposta nuovamente it_IT come predefinita)

### Completamento
- [ ] Documenta lingua in README
- [ ] Commit file JSON: `git add locale/de_DE.json`
- [ ] Commit con messaggio: `feat(i18n): add German (de_DE) translation`

---

## 📈 Roadmap Future

### v1.1 (Prossima Release)
- [ ] Esportazione CSV per traduttori (key, it_IT, en_US, de_DE, ...)
- [ ] Editor traduzioni in-app (modifica JSON via interfaccia web)
- [ ] Backup automatico file traduzione prima di modifiche

### v1.2
- [ ] Traduzione dinamica contenuti utente (titoli libri, descrizioni)
- [ ] Integrazione Google Translate API per suggerimenti automatici
- [ ] Interfaccia per traduttori collaborativi
- [ ] Version control traduzioni (git-style diff)
- [ ] Importazione massiva traduzioni da file Excel/CSV

### v2.0
- [ ] Support per RTL (Right-to-Left) lingue arabe/ebraiche
- [ ] Plurali complessi (ngettext integration)
- [ ] Contesto traduzioni (msgctxt)
- [ ] Fallback chain (es. `pt_BR` → `pt_PT` → `en_US`)
- [ ] Multi-tenancy: lingua diversa per ogni tenant/organizzazione

---

## 📚 Risorse Utili

### Documentazione
- [PHP Internationalization](https://www.php.net/manual/en/book.intl.php)
- [ISO 639-1 Language Codes](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes)
- [ISO 3166-1 Country Codes](https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2)
- [Unicode CLDR](http://cldr.unicode.org/) - Date/number formatting per locale

### Tools
- [JSONLint](https://jsonlint.com/) - Valida sintassi JSON
- [DeepL](https://www.deepl.com/) - Traduttore AI (migliore di Google Translate)
- [Poedit](https://poedit.net/) - Editor gettext (future integrazione)
- [i18n Ally](https://marketplace.visualstudio.com/items?itemName=Lokalise.i18n-ally) - VS Code extension

### Emoji Flags
- [Emojipedia - Flags](https://emojipedia.org/flags/)
- [Unicode Flag Emoji](https://unicode.org/emoji/charts/emoji-list.html#1f1e6)

---

## ❓ FAQ

### Q: Posso cambiare codice lingua dopo creazione?

**A:** No, il codice è la chiave primaria. Soluzione:
1. Crea nuova lingua con codice corretto
2. Upload stesso file JSON
3. Elimina lingua vecchia

### Q: Come gestisco varianti regionali? (es. `pt_BR` vs `pt_PT`)

**A:** Crea lingue separate:
- `pt_BR` (Português - Brasil) 🇧🇷
- `pt_PT` (Português - Portugal) 🇵🇹

Condividi base translation JSON e sovrascrive differenze:
```json
// pt_BR.json
{
  "Autobus": "Ônibus",
  "Cellulare": "Celular"
}

// pt_PT.json
{
  "Autobus": "Autocarro",
  "Cellulare": "Telemóvel"
}
```

### Q: Cosa succede se elimino file JSON ma non record DB?

**A:** La lingua rimarrà in lista ma:
- `completion_percentage` = 0%
- Tutte le traduzioni mostreranno testo originale italiano
- Non errori (fallback graceful)

**Soluzione:** Elimina anche record DB o ri-upload file JSON

### Q: Posso tradurre contenuti dinamici (es. titoli libri inseriti da utenti)?

**A:** Attualmente no, solo stringhe statiche dell'interfaccia.

Per contenuti dinamici (v2.0):
- Opzione 1: Tabelle separate per lingua (es. `libri_translations`)
- Opzione 2: Colonne multiple (es. `titolo_it`, `titolo_en`)
- Opzione 3: JSON field (es. `titolo: {"it": "...", "en": "..."}`)

### Q: Come gestisco traduzioni con genere (es. "Benvenuto" vs "Benvenuta")?

**A:** Usa forma neutra o includi variabili:

```php
// Opzione 1: Forma neutra
echo __("Ciao!");  // No genere

// Opzione 2: Include nome
echo __("Benvenuto/a, %s", $username);

// Opzione 3: Chiavi separate (se necessario)
$greeting = $user['sesso'] === 'F'
    ? __("Benvenuta")
    : __("Benvenuto");
```

### Q: Devo tradurre i log tecnici (error_log)?

**A:** No. Log tecnici devono essere in inglese per:
- Facilità debug
- Ricerca errori online
- Consistenza con log di terze parti (Apache, MySQL, ecc.)

Traduci solo messaggi visibili all'utente (flash messages, UI errors).

---

## 📞 Supporto

Per domande o problemi con il sistema i18n:

1. **Controlla troubleshooting** in questa guida
2. **Verifica console browser** per errori JS
3. **Controlla log PHP** (`storage/logs/error.log`)
4. **Contatta:** [Il tuo contatto/email]

---

## 📝 Summary

Il sistema i18n di Pinakes è:

- ✅ **Database-driven**: Lingue gestite via admin, non hardcoded
- ✅ **File-based translations**: JSON files (facili da modificare)
- ✅ **Statistiche automatiche**: Traccia completamento traduzioni
- ✅ **Fallback intelligente**: Se traduzione manca, mostra testo originale
- ✅ **Performance**: Caricamento lazy + caching in memoria
- ✅ **Sicurezza**: Upload JSON validato + CSRF protection
- ✅ **Developer-friendly**: Funzione `__()` semplice e intuitiva
- ✅ **Translation-friendly**: Admin UI per gestione completa

**Stato attuale:** 1988 stringhe tradotte italiano → inglese, con copertura **99% dell'applicazione** (frontend, admin, installer, email).

---

**Ultima revisione:** 2025-11-07
**Versione App:** 0.1.1
**Autori:** Claude AI + Fabio
