# Backup e Ripristino

Guida alla gestione dei backup in Pinakes.

## Tipi di Backup

### Backup Database

Include:
- Tutte le tabelle del database
- Dati utenti, libri, prestiti
- Configurazioni e impostazioni

Formato: SQL compresso (.sql.gz)

### Backup Completo

Include:
- Database completo
- File caricati (`storage/uploads/`)
- Plugin installati (`storage/plugins/`)
- Configurazioni (`.env`, `config.local.php`)

Formato: ZIP

## Creazione Backup

### Backup Manuale

1. Vai in **Amministrazione → Backup**
2. Clicca **Crea backup**
3. Seleziona il tipo (Database/Completo)
4. Attendi il completamento
5. Il backup appare nella lista

### Backup Automatico

Il sistema crea backup automatici:
- Prima di ogni aggiornamento
- Opzionalmente su base programmata (richiede cron)

### Cron Job

Per backup programmati, aggiungi al crontab:

```bash
# Backup giornaliero alle 3:00
0 3 * * * cd /path/to/pinakes && php cli/backup.php
```

## Gestione Backup

### Visualizzazione

La lista mostra:
- Data e ora creazione
- Tipo (database/completo)
- Dimensione file
- Azioni disponibili

### Download

1. Trova il backup nella lista
2. Clicca **Scarica**
3. Il file viene scaricato sul tuo computer

### Eliminazione

1. Seleziona i backup da eliminare
2. Clicca **Elimina selezionati**
3. Conferma l'eliminazione

## Ripristino

### Da Interfaccia

1. Vai in **Amministrazione → Backup**
2. Trova il backup desiderato
3. Clicca **Ripristina**
4. Conferma l'operazione
5. Attendi il completamento

**Attenzione**: Il ripristino sovrascrive i dati attuali.

### Da File Esterno

1. Clicca **Carica backup**
2. Seleziona il file backup
3. Clicca **Carica e ripristina**

### Manuale (Emergenza)

Se l'interfaccia non funziona:

```bash
# Ripristino database
gunzip -c storage/backups/backup_2024-01-15.sql.gz | mysql -u user -p database

# Ripristino file
unzip storage/backups/backup_2024-01-15.zip -d /path/to/pinakes
```

## Storage

### Posizione

I backup sono salvati in:
```
storage/backups/
├── db_2024-01-15_10-30-00.sql.gz
├── full_2024-01-14_03-00-00.zip
└── ...
```

### Pulizia Automatica

Il sistema può eliminare automaticamente backup vecchi:
- Configurabile in Impostazioni
- Mantiene gli ultimi N backup
- Rispetta un periodo minimo di conservazione

### Spazio Disco

Monitora lo spazio disponibile:
- I backup completi possono essere grandi
- Considera backup su storage esterno
- Usa compressione per risparmiare spazio

## Best Practice

1. **Backup regolari**: almeno giornalieri per database
2. **Test ripristino**: verifica periodicamente che i backup funzionino
3. **Copia esterna**: conserva backup fuori dal server
4. **Prima di modifiche**: backup manuale prima di operazioni critiche
5. **Rotazione**: elimina backup vecchi per risparmiare spazio

---

## Domande Frequenti (FAQ)

### 1. Ogni quanto devo fare il backup?

Dipende dall'attività della biblioteca:

| Volume attività | Frequenza consigliata |
|-----------------|----------------------|
| Alta (molti prestiti/giorno) | Giornaliero |
| Media | Ogni 2-3 giorni |
| Bassa | Settimanale |
| Dopo modifiche importanti | Sempre |

**Consiglio**: Configura il backup automatico giornaliero e tieni i backup degli ultimi 7 giorni.

---

### 2. Quanto spazio occupano i backup?

Stima approssimativa:

- **Backup database** (solo dati): 1-50 MB (dipende dal catalogo)
- **Backup completo** (database + file): 50-500 MB

**Per ridurre lo spazio**:
- I backup sono già compressi (.gz e .zip)
- Elimina backup vecchi (tieni solo gli ultimi 5-7)
- Non fare troppi backup completi (una volta a settimana è sufficiente)

---

### 3. Il ripristino sovrascrive tutto?

Sì, il ripristino **sovrascrive completamente** i dati attuali:
- Database: tutte le tabelle vengono ricreate
- File (backup completo): vengono sostituiti

**Prima di ripristinare**:
- Assicurati di avere un backup dello stato attuale
- Avvisa gli utenti se il sito è in produzione

---

### 4. Posso ripristinare solo alcune tabelle?

Dall'interfaccia no, ma manualmente sì:

1. Scarica il backup database (.sql.gz)
2. Decomprimi: `gunzip backup.sql.gz`
3. Apri il file SQL con un editor
4. Estrai le query INSERT della tabella desiderata
5. Esegui solo quelle query nel database

**Alternativa**: Usa phpMyAdmin per importare tabelle selettive.

---

### 5. Come faccio backup esterni (cloud, Google Drive)?

Pinakes salva i backup in `storage/backups/`. Per copiarli altrove:

**Manuale**:
1. Scarica il backup dall'interfaccia
2. Carica su Google Drive/Dropbox/NAS

**Automatico (con rclone)**:
```bash
# Dopo il cron di backup, sincronizza su cloud
0 4 * * * rclone copy /path/to/storage/backups remote:pinakes-backups
```

**Hosting con cPanel**: Usa la funzione "Backup Wizard" per esportare su destinazioni esterne.

---

### 6. Il backup è fallito, cosa controllo?

Cause comuni:

1. **Spazio disco insufficiente**: Libera spazio o elimina backup vecchi
2. **Permessi**: `chmod 755 storage/backups`
3. **Timeout PHP**: Aumenta `max_execution_time` in php.ini
4. **Memoria PHP**: Aumenta `memory_limit` se il database è grande

Controlla il log: `storage/logs/app.log` per dettagli sull'errore.

---

### 7. Come ripristino un backup se non riesco ad accedere all'admin?

Ripristino manuale via terminale/phpMyAdmin:

```bash
# Database
gunzip -c storage/backups/db_2024-01-15.sql.gz | mysql -u user -p database

# File (se backup completo)
unzip storage/backups/full_2024-01-15.zip -d /var/www/biblioteca
```

**Con phpMyAdmin**:
1. Decomprimi il file .sql.gz
2. Apri phpMyAdmin
3. Seleziona il database
4. Tab "Importa" → carica il file .sql

---

### 8. Posso programmare backup automatici senza accesso SSH?

Sì, con alcuni metodi:

**cPanel → Cron Jobs**:
```
0 3 * * * cd /home/user/public_html && php cli/backup.php
```

**Servizi esterni** (cron-job.org):
- Crea un endpoint protetto che esegue il backup
- Configuralo per essere chiamato ogni giorno

**Plugin di hosting**: Alcuni provider offrono backup automatici integrati.

---

### 9. Come verifico che un backup sia funzionante?

L'unico modo certo è **testare il ripristino**:

1. Crea un ambiente di test (sottodominio, localhost)
2. Installa Pinakes pulito
3. Ripristina il backup
4. Verifica che tutti i dati siano presenti

**Controllo rapido** (non completo):
- Apri il file .sql.gz e verifica che contenga le tabelle
- Controlla la dimensione (un backup molto piccolo potrebbe essere incompleto)

---

### 10. Cosa succede ai backup durante un aggiornamento?

Il sistema di aggiornamento:
1. Crea automaticamente un backup completo **prima** di aggiornare
2. Lo salva con nome `pre_update_X.X.X_TIMESTAMP.zip`
3. Se l'aggiornamento fallisce, puoi ripristinare questo backup

**Importante**: Non eliminare i backup pre-aggiornamento finché non verifichi che tutto funzioni.
