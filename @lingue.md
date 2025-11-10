# 🌍 Sistema di Gestione Lingue - Pinakes Biblioteca

## 📋 Panoramica del Sistema

Il sistema di gestione lingue in Pinakes è progettato per supportare multilingua completo con traduzioni di interfaccia, rotte localizzate e contenuti CMS. Il sistema è **bloccato all'installazione** - la lingua viene definita durante l'installazione e non può essere cambiata in seguito dagli utenti.

## 🎯 Principi Fondamentali

### 1. **Lingua Configurabile dall'Amministratore**
- **Gli amministratori possono cambiare la lingua dell'intera applicazione** tramite il pannello `/admin/languages`
- La lingua può essere cambiata in qualsiasi momento cliccando sull'icona ⭐ "Lingua App"
- **Gli utenti finali non possono cambiare lingua individualmente** - la lingua è globale per tutta l'applicazione
- La lingua predefinita può essere modificata solo dagli amministratori

### 2. **Fallback all'Italiano**
- **Lingua di fallback**: Italiano (`it_IT`)
- Se una traduzione manca, viene usato il testo originale italiano
- Tutto il nuovo codice deve essere scritto in italiano per garantire compatibilità
- Il cambio lingua avviene **a livello di sistema**, non a livello utente

### 3. **Struttura File di Traduzione**
- File JSON per le traduzioni: `locale/{codice_lingua}.json`
- File JSON per le rotte: `locale/routes_{codice_lingua}.json`
- Formato: coppie chiave-valore con testo italiano come chiave

## 🏗️ Architettura del Sistema

### Database Structure
```sql
CREATE TABLE languages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) UNIQUE NOT NULL,           -- es: 'it_IT', 'en_US'
    name VARCHAR(100) NOT NULL,                 -- Nome in inglese
    native_name VARCHAR(100) NOT NULL,          -- Nome nella lingua nativa
    flag_emoji VARCHAR(10) DEFAULT '🌐',        -- Emoji bandiera
    is_default BOOLEAN DEFAULT 0,               -- Lingua predefinita installazione
    is_active BOOLEAN DEFAULT 1,                -- Lingua attiva/disattiva
    translation_file VARCHAR(255),              -- Percorso file traduzioni
    total_keys INT DEFAULT 0,                   -- Totale chiavi di traduzione
    translated_keys INT DEFAULT 0,              -- Chiavi tradotte
    completion_percentage DECIMAL(5,2) DEFAULT 0.00,  -- Percentuale completamento
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Classi Principali

#### 1. `App\Support\I18n`
Classe principale per la gestione delle traduzioni:

```php
// Traduzione base
echo I18n::translate('Ciao Mondo');

// Traduzione con parametri
echo I18n::translate('Benvenuto %s', $nome);

// Plurale
echo I18n::translatePlural('%d libro', '%d libri', $count);

// Gestione locale
I18n::setLocale('en_US');
$currentLocale = I18n::getLocale();
```

#### 2. `App\Models\Language`
Modello per la gestione delle lingue nel database:

```php
$languageModel = new Language($db);
$languages = $languageModel->getAll();
$defaultLang = $languageModel->getDefault();
```

#### 3. `App\Controllers\LanguageController`
Controller per il cambio lingua (limitato all'installazione):

```php
// Switch lingua - solo durante installazione
$controller = new LanguageController();
$controller->switchLanguage($request, $response, $db, ['locale' => 'en_US']);
```

## 📁 Struttura File

```
locale/
├── it_IT.json              # Traduzioni italiano (fallback)
├── en_US.json              # Traduzioni inglese
├── routes_it_IT.json       # Rotte in italiano
├── routes_en_US.json       # Rotte in inglese
└── en_US/                  # Directory per file aggiuntivi
    └── LC_MESSAGES/        # Supporto gettext futuro
```

## 🔄 Flusso di Cambio Lingua

### 1. Cambio Lingua tramite Admin Panel
Gli amministratori possono cambiare la lingua dell'intera applicazione:
1. Vai su `/admin/languages`
2. Clicca sull'icona ⭐ "Lingua App" accanto alla lingua desiderata
3. La lingua cambia **globalmente per tutta l'applicazione**

### 2. Caricamento Traduzioni
```php
// Durante il bootstrap dell'applicazione
I18n::loadFromDatabase($db);     // Carica lingue dal database
I18n::loadTranslations();        // Carica file JSON traduzioni
```

### 2. Processo di Traduzione
```php
// Funzione helper globale __()
function __(string $message, ...$args): string {
    return App\Support\I18n::translate($message, ...$args);
}

// Uso nei template
echo __('Dashboard');                    // Traduzione semplice
echo __('Benvenuto %s', $username);      // Con parametri
echo __n('%d libro', '%d libri', $n);    // Plurale
```

### 3. Logica di Traduzione
1. Cerca la chiave italiana nel file JSON della lingua corrente
2. Se trovata, restituisce la traduzione
3. Se non trovata, restituisce la chiave originale (italiano)
4. Applica `sprintf()` se ci sono parametri aggiuntivi

## 🌐 Gestione Rotte Multilingua

### Sistema RouteTranslator
Le rotte vengono tradotte in base alla lingua di installazione:

```php
// File routes_it_IT.json
{
  "login": "/accedi",
  "logout": "/esci", 
  "register": "/registrati",
  "catalog": "/catalogo"
}

// File routes_en_US.json  
{
  "login": "/login",
  "logout": "/logout",
  "register": "/register", 
  "catalog": "/catalog"
}
```

### Utilizzo nelle View
```php
// Genera URL localizzato
$url = RouteTranslator::route('login'); // /accedi o /login
```

## 📊 Statistiche e Monitoraggio

### Statistiche Traduzione
- **Totale chiavi**: Numero totale di stringhe da tradurre
- **Chiavi tradotte**: Numero di stringhe effettivamente tradotte
- **Percentuale completamento**: (tradotte/totali) × 100

### Calcolo Automatico
```php
// Aggiornamento statistiche
$languageModel->updateStats('en_US', 2015, 1988); // 98.66% complete
```

## 🛠️ Best Practices per lo Sviluppo

### 1. **Scrivere sempre in Italiano**
```php
// ✅ CORRETTO
echo __('Dashboard');
echo __('Gestione Libri');
echo __('Aggiungi Nuovo Libro');

// ❌ ERRATO - Non in italiano
echo __('Dashboard Management');
```

### 2. **Usare Chiavi Descrittive**
```php
// ✅ CORRETTO
echo __('Errore durante il salvataggio');
echo __('Libro aggiunto con successo');

// ❌ ERRATO - Troppo generico
echo __('Error');
echo __('Success');
```

### 3. **Supporto Parametri**
```php
// ✅ CORRETTO - Usare %s, %d, %f
echo __('Libro "%s" salvato con successo', $titolo);
echo __('%d copie disponibili', $copie);
echo __('Prezzo: €%.2f', $prezzo);
```

### 4. **Gestione Plurale**
```php
// ✅ CORRETTO
echo __n('%d libro', '%d libri', $count);
echo __n('%d copia', '%d copie', $copie);
```

### 5. **Contesto Chiaro**
```php
// ✅ CORRETTO - Contesto specifico
echo __('Data di pubblicazione');
echo __('Data di scadenza prestito');

// ❌ ERRATO - Ambiguo
echo __('Data');
```

## 🔧 Debugging e Test

### Test Locale
File di test disponibile: `public/test-locale.php`

```php
// Verifica traduzioni attive
echo __("Dashboard");           // Should translate to English if en_US
echo __("Impostazioni");        // Should translate to "Settings"
```

### Debug Mode
```php
// In development mode
if (getenv('APP_ENV') === 'development') {
    // Log traduzioni mancanti
    error_log("Missing translation: $key for locale: " . I18n::getLocale());
}
```

## 🚨 Limitazioni e Restrizioni

### 1. **No Cambio Lingua Post-Installazione**
```php
// Questo NON funzionerà dopo l'installazione
I18n::setLocale('en_US'); // Ignorato in produzione
```

### 2. **Lingua di Installazione = Lingua Applicazione**
```php
// La lingua dell'installazione determina tutto
$installLocale = I18n::getInstallationLocale(); // Fixed forever
```

### 3. **Fallback Obbligatorio**
```php
// Se manca traduzione, usa italiano
echo __('Testo non tradotto'); // Restituisce la chiave stessa
```

## 📈 Performance e Ottimizzazioni

### 1. **Caching**
- Traduzioni caricate una sola volta per sessione
- Cache in memoria per evitare letture file ripetute

### 2. **Lazy Loading**
- File JSON caricati solo quando necessario
- Database query ottimizzate con prepared statements

### 3. **Minimal Overhead**
- Funzione `__()` è un semplice wrapper
- Nessuna query database per ogni traduzione

## 🔒 Sicurezza

### Validazione Input
```php
// Validazione codice lingua
if (!I18n::isValidLocaleCode($code)) {
    throw new \Exception("Invalid locale code");
}

// Sanitizzazione traduzioni
$sanitized = strip_tags($translation);
```

### Prevenzione XSS
```php
// Traduzioni sono sempre safe per HTML
echo htmlspecialchars(__('Testo sicuro'));
```

## 🚀 Estendibilità Futura

### 1. **Supporto Gettext**
```php
// Futuro: supporto .po/.mo files
I18n::loadGettextDomain('messages');
```

### 2. **Traduzioni Contestuali**
```php
// Futuro: contesti diversi
echo I18n::translateContext('formal', 'Salve');
echo I18n::translateContext('informal', 'Ciao');
```

### 3. **Traduzioni Automatiche**
```php
// Futuro: integrazione servizi traduzione
I18n::autoTranslateMissingKeys();
```

## 📚 Esempi Pratici

### Form di Login
```php
// Template login
<h1><?= __('Accedi al Sistema') ?></h1>
<label><?= __('Email') ?></label>
<input placeholder="<?= __('Inserisci la tua email') ?>">
<button><?= __('Accedi') ?></button>
```

### Messaggi di Successo
```php
// Controller
$_SESSION['success_message'] = __('Libro "%s" salvato con successo', $titolo);
```

### Validazioni
```php
// Validazione form
if (empty($email)) {
    $errors[] = __('Il campo email è obbligatorio');
}
if (strlen($password) < 8) {
    $errors[] = __('La password deve contenere almeno %d caratteri', 8);
}
```

---

## 📝 Note Finali

Il sistema di gestione lingue di Pinakes è progettato per essere:
- **Semplice**: Facile da usare e mantenere
- **Robusto**: Fallback sicuri e validazioni complete  
- **Performante**: Caching e ottimizzazioni integrate
- **Scalabile**: Architettura pronta per futuri miglioramenti

**Ricorda sempre**: Scrivere il codice in italiano, pensare alla traducibilità, testare con il file `test-locale.php`.