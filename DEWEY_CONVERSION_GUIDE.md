# Guida alla Conversione Dewey da Italiano a Inglese

## Problema

Se durante l'installazione hai scelto **Inglese** ma nel database sono stati caricati i dati della classificazione Dewey in **Italiano**, puoi usare questo script per convertirli.

## Sintomi

Nel form di creazione libro, i dropdown della classificazione Dewey mostrano:
- ❌ "000 - Informatica, informazione e opere generali" (italiano)
- ✅ Invece di: "000 - Computer science, information & general works" (inglese)

## Soluzione

### Opzione 1: Reinstallazione (consigliata per nuove installazioni)

Se non hai ancora inserito libri:
1. Elimina il file `.installed` dalla root del progetto
2. Visita `/installer/`
3. Scegli **English** nello step 0
4. Completa l'installazione

### Opzione 2: Script di Conversione (per database esistenti)

Se hai già inserito libri e non vuoi reinstallare:

#### Locale (development)

```bash
cd /Users/fabio/Documents/GitHub/biblioteca
php convert_dewey_to_english.php
```

#### Produzione (https://bibliodoc.fabiodalez.it)

**Via SSH:**
```bash
# 1. Carica lo script
scp convert_dewey_to_english.php user@server:/path/to/biblioteca/

# 2. Esegui
ssh user@server
cd /path/to/biblioteca
php convert_dewey_to_english.php

# 3. Cancella lo script
rm convert_dewey_to_english.php
```

**Via FTP/Web:**
1. Carica `convert_dewey_to_english.php` nella root del progetto
2. Esegui visitando: `https://bibliodoc.fabiodalez.it/convert_dewey_to_english.php`
3. Cancella il file dopo l'esecuzione

## Output Atteso

### Se i dati sono in italiano:
```
🔌 Connessione al database...
✅ Connesso al database: fabiodal_biblioteca

📋 Current Dewey root entry:
   Code: 000
   Name: 000 - Informatica, informazione e opere generali

🇮🇹 Database contains Italian Dewey data
🔄 Converting to English...

📂 Reading English data file...
📊 Found 1001 classificazione entries
🗑️  Clearing Italian data...
✍️  Inserting English data...

✅ Conversion completed!
📊 Total entries: 1001
📋 Sample entry (ID=1):
   Code: 000
   Name: 000 - Computer science, information & general works
   Level: 1

✅ Script completed successfully!
```

### Se i dati sono già in inglese:
```
🇬🇧 Database already contains English Dewey data
✅ No conversion needed!
```

## Sicurezza

- ✅ Lo script è escluso da git (pattern `convert-*.php`)
- ✅ Usa `TRUNCATE` quindi è molto veloce (< 1 secondo)
- ✅ Non modifica altri dati (solo tabella `classificazione`)
- ⚠️ **Backup consigliato** prima dell'esecuzione su produzione

### Backup prima della conversione

```bash
# Backup solo tabella classificazione
mysqldump -u user -p database classificazione > classificazione_backup.sql

# Ripristino se necessario
mysql -u user -p database < classificazione_backup.sql
```

## Come Verificare il Risultato

1. Vai su `/admin/libri/crea`
2. Scorri fino alla sezione "Classificazione Dewey"
3. Apri il dropdown "Seleziona classe..."
4. Verifica che mostri: **"000 - Computer science, information & general works"**

## Perché è Successo?

L'installer dovrebbe caricare automaticamente `data_en_US.sql` quando si sceglie inglese, ma potrebbe non funzionare se:

1. **Versione vecchia dell'installer** - Prima delle fix recenti
2. **Sessione persa** - Se la sessione è stata resettata tra gli step
3. **Accesso diretto** - Se si è saltato lo step 0 (selezione lingua)

## Fix Permanente nell'Installer

Il fix è già stato implementato:

1. **Step 0 obbligatorio** - Non si può saltare la selezione lingua
2. **Locale salvato in sessione** - `$_SESSION['app_locale']` persiste tra gli step
3. **Locale scritto in .env** - Durante lo step 2 viene scritto nel .env
4. **Dati caricati dal locale** - Lo step 3 legge `APP_LOCALE` dal .env

## Risoluzione Problemi

### Errore: "Column not found: classe"
- ❌ Script vecchio con nome colonna errato
- ✅ Usa l'ultima versione dello script (`codice` invece di `classe`)

### Errore: "File .env non trovato"
- ❌ Script eseguito dalla directory sbagliata
- ✅ Esegui dalla root del progetto dove si trova il file `.env`

### Errore: "data_en_US.sql not found"
- ❌ Repository non completo
- ✅ Verifica che esista `installer/database/data_en_US.sql`

## Contatti

Per problemi o domande, apri una issue su GitHub.

---

**Nota**: Questo script è temporaneo e non viene committato nel repository (escluso da `.gitignore`).
