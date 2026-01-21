# Pinakes v0.4.4 - Note di Rilascio

**Data rilascio:** Gennaio 2026

Questa versione introduce importanti miglioramenti al sistema di prenotazioni, ottimizzazioni delle performance, e rafforza la sicurezza complessiva dell'applicazione. Include inoltre un aggiornamento critico per garantire la compatibilità con tutti i tipi di hosting.

---

## Novità Principali

### Sistema di Prenotazioni Potenziato
- Gestione avanzata delle code di prenotazione
- Riassegnazione automatica quando un libro diventa disponibile
- Calcolo disponibilità in tempo reale
- Notifiche ottimizzate inviate solo al completamento delle operazioni

### Performance Migliorate
- Sistema di caching completamente rivisto
- Query ottimizzate per operazioni soft-delete
- Asset frontend più leggeri e veloci
- Ridotto il tempo di caricamento delle pagine catalogo

### Sicurezza Rafforzata
- Protezione XSS migliorata nei renderer delle liste
- Gestione CSRF con pagina di sessione scaduta user-friendly
- Validazione input più rigorosa
- Controlli null difensivi per prevenire errori JavaScript

### Scraping e ISBN
- Rilevamento ISBN migliorato con cross-check EAN
- Normalizzazione automatica caratteri MARC-8 dai record SRU
- Conversione automatica URL cover da HTTP a HTTPS
- Deduplicazione intelligente nomi autori
- Gestione fallback quando i plugin restituiscono dati parziali

---

## Miglioramenti Tecnici

### Filtri e Ricerca
- Autocomplete per autore, editore, genere e posizione
- Ordinamento DataTable corretto
- Rendering robusto anche con dati incompleti

### Installer e Build
- Inclusione frontend per personalizzazioni
- Script di build verificato con controlli automatici
- Tracciamento package-lock.json per build riproducibili

### Database
- Migrazioni idempotenti (eseguibili più volte senza errori)
- Schema ottimizzato con AUTO_INCREMENT reset
- Logging transazionale migliorato

---

## Nota per Utenti su Hosting Condiviso

Gli utenti che aggiornano dalla versione 0.4.1, 0.4.2 o 0.4.3 potrebbero riscontrare problemi con l'aggiornamento automatico su alcuni hosting condivisi.

**Causa:** Le versioni precedenti utilizzavano la directory temporanea di sistema che su alcuni hosting ha restrizioni.

**Soluzione:** Abbiamo incluso nel pacchetto di rilascio una cartella `test-updater/` con i file corretti da caricare via FTP prima di eseguire l'aggiornamento. In alternativa, usare lo script `manual-update.php` incluso.

La versione 0.4.4 risolve definitivamente questo problema utilizzando sempre la directory `storage/tmp` dell'applicazione, con download via cURL e retry automatico.

---

## Changelog Completo

### v0.4.4
- Updater robusto: usa storage/tmp, cURL, retry automatico, controllo spazio disco
- Endpoint log per debug aggiornamenti (/admin/updates/logs)
- Hardening sicurezza renderer liste
- Fix ConfigStore e QueryCache
- Fix installer e consistenza cache

### v0.4.3
- Ottimizzazione caching e query
- Ottimizzazione asset frontend
- Fix filtri autocomplete
- Rendering difensivo DataTable

### v0.4.2
- Nuovo servizio riassegnazione prenotazioni
- Fix disponibilità automatica
- Notifiche differite dopo commit transazione
- Fix ordinamento DataTable

### v0.4.1
- Miglioramenti rilevamento ISBN
- Normalizzazione MARC-8 completa
- Auto-upgrade URL cover HTTP->HTTPS
- Cross-check ISBN/EAN per duplicati
- Recovery mechanism per aggiornamenti falliti
- Meccanismo di recupero aggiornamenti
- Inclusione frontend nel pacchetto release

---

## Requisiti

- PHP 8.1 o superiore
- MySQL 5.7+ / MariaDB 10.3+
- Estensione ZipArchive
- 200MB spazio disco libero (per aggiornamenti)

---

## Installazione

**Nuova installazione:**
1. Estrarre l'archivio nella directory web
2. Accedere via browser - l'installer si avvia automaticamente

**Aggiornamento da 0.4.x:**
- Da 0.4.4+: usare l'aggiornamento automatico da Admin > Aggiornamenti
- Da 0.4.1-0.4.3: seguire le istruzioni in `test-updater/README.txt`

---

## Supporto

Per segnalazioni e supporto:
- GitHub Issues: https://github.com/fabiodalez-dev/pinakes/issues
