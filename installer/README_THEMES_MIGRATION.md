# Theme System Migration Guide

Questa guida spiega come aggiungere il sistema di temi al tuo database Pinakes esistente.

## Metodo 1: Script PHP Automatico (Consigliato)

### Via Browser
1. Apri il browser e naviga a: `http://tuosito.com/installer/run_migration_themes.php`
2. Lo script eseguirà automaticamente la migration
3. Verrai informato del successo dell'operazione
4. **IMPORTANTE**: Elimina il file `installer/run_migration_themes.php` per sicurezza dopo l'esecuzione

### Via CLI (Command Line)
```bash
cd /path/to/pinakes
php installer/run_migration_themes.php
```

## Metodo 2: Import Manuale via phpMyAdmin

1. Apri phpMyAdmin
2. Seleziona il tuo database
3. Vai alla tab "Import" (Importa)
4. Clicca "Choose File" (Scegli file)
5. Seleziona il file: `installer/database/migration_themes.sql`
6. Clicca "Go" (Esegui)

## Metodo 3: Import via MySQL CLI

```bash
mysql -u username -p database_name < installer/database/migration_themes.sql
```

Sostituisci:
- `username` con il tuo username MySQL
- `database_name` con il nome del tuo database

## Cosa Include la Migration

La migration crea:

1. **Tabella `themes`** con i seguenti campi:
   - `id` - ID univoco del tema
   - `name` - Nome visualizzato del tema
   - `slug` - Identificatore univoco
   - `version` - Versione del tema
   - `author` - Autore del tema
   - `description` - Descrizione del tema
   - `active` - Stato attivo/inattivo (solo 1 tema può essere attivo)
   - `settings` - Impostazioni JSON (colori, tipografia, logo, CSS/JS personalizzati)
   - `created_at` / `updated_at` - Timestamp di creazione/modifica

2. **10 Temi Predefiniti**:
   - **Pinakes Classic** (default, attivo) - Magenta originale
   - **Minimal** - Nero, grigio e bianco
   - **Ocean Blue** - Blu oceano moderno
   - **Forest Green** - Verde smeraldo naturale
   - **Sunset Orange** - Arancione caldo mediterraneo
   - **Burgundy** - Rosso borgogna elegante
   - **Teal Professional** - Verde acqua professionale
   - **Slate Gray** - Grigio ardesia contemporaneo
   - **Coral Warm** - Tonalità corallo accoglienti
   - **Navy Classic** - Blu navy istituzionale

## Dopo la Migration

Una volta completata la migration, puoi:

1. **Gestire i temi**: Visita `/admin/themes`
   - Visualizza tutti i temi disponibili
   - Attiva/disattiva temi
   - Vedi anteprima dei colori

2. **Personalizzare il tema attivo**: Visita `/admin/themes/customize`
   - Modifica colori primari, secondari, pulsanti
   - Personalizza tipografia
   - Carica logo personalizzato
   - Aggiungi CSS/JS personalizzati
   - Verifica contrasto colori per accessibilità

3. **Creare nuovi temi**: Usa l'interfaccia admin per duplicare e modificare temi esistenti

## Verifica dell'Installazione

Per verificare che la migration sia andata a buon fine:

```sql
SELECT COUNT(*) FROM themes;
```

Dovresti vedere **10 temi** nel risultato.

Per verificare quale tema è attivo:

```sql
SELECT name, slug FROM themes WHERE active = 1;
```

Dovresti vedere **Pinakes Classic** come tema attivo di default.

## Troubleshooting

### Errore: "Table 'themes' already exists"
La tabella esiste già. Puoi:
- Ignorare l'errore se i dati sono già presenti
- Eliminare la tabella manualmente e rieseguire la migration: `DROP TABLE themes;`

### Errore: "Duplicate entry for key 'slug'"
Alcuni temi sono già stati inseriti. Puoi:
- Ignorare l'errore (i temi esistenti rimarranno invariati)
- Eliminare tutti i temi e rieseguire: `DELETE FROM themes;`

### Il tema non viene applicato
1. Verifica che il tema sia attivo: `SELECT * FROM themes WHERE active = 1;`
2. Svuota la cache del browser (CTRL+F5)
3. Verifica i permessi della cartella `cache/`

## Sicurezza

**IMPORTANTE**: Dopo aver eseguito la migration, elimina questi file per sicurezza:
```bash
rm installer/run_migration_themes.php
```

## Supporto

Per problemi o domande, consulta la documentazione completa o apri una issue su GitHub.
