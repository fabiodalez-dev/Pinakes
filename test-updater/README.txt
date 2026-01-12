ISTRUZIONI PER AGGIORNAMENTO MANUALE
=====================================

Questa cartella contiene i file modificati per fixare l'updater.
NON e' un aggiornamento completo, ma solo i fix per far funzionare l'updater.

PROBLEMA: Gli utenti con versioni 0.4.1-0.4.3 hanno un Updater.php vecchio
che fallisce l'estrazione su hosting condiviso. Siccome l'updater e' rotto,
non possono scaricare la versione nuova che lo fixerebbe.

SOLUZIONE: Caricare manualmente questi file via FTP.


OPZIONE 1: Caricare i file via FTP (CONSIGLIATA)
------------------------------------------------

1. Carica la cartella "app" sovrascrivendo i file esistenti:
   - app/Support/Updater.php (fix completo con tutti i miglioramenti)
   - app/Controllers/UpdateController.php (aggiunto log viewer)
   - app/Views/admin/updates.php (fix UI progress)
   - app/Routes/web.php (aggiunta route logs)

2. Assicurati che le directory abbiano i permessi corretti:
   chmod 775 storage
   chmod 775 storage/tmp
   chmod 775 storage/logs
   chmod 775 storage/backups

3. Verifica i log accedendo a: /admin/updates/logs

4. Riprova l'aggiornamento dalla pagina admin updates


OPZIONE 2: Usare lo script manual-update.php
--------------------------------------------

1. Carica manual-update.php nella ROOT del sito (stesso livello di index.php)

2. Accedi a: https://tuodominio.com/manual-update.php

3. Lo script:
   - Verifica permessi e spazio disco (minimo 200MB)
   - Controlla estensioni richieste (ZipArchive, cURL)
   - Scarica l'ultima release da GitHub
   - Estrae in storage/tmp (non /tmp di sistema)
   - Retry automatico in caso di fallimento estrazione
   - Esegue le migrazioni database

4. ELIMINA manual-update.php dopo l'aggiornamento!


VERIFICA LOG
------------

Dopo aver caricato i file, puoi vedere i log dell'updater:
https://tuodominio.com/admin/updates/logs

I log mostrano cosa sta succedendo durante l'aggiornamento.


FILE IN QUESTA CARTELLA
-----------------------

app/Support/Updater.php (CRITICO)
  - SEMPRE usa storage/tmp invece di sys_get_temp_dir()
  - Pre-flight checks: permessi, spazio disco (200MB), estensioni
  - Pulizia automatica vecchie directory temp
  - Download con cURL (piu' affidabile) + fallback file_get_contents
  - Retry automatico estrazione ZIP (3 tentativi)
  - Aumento automatico memoria se estrazione fallisce
  - Piu' errori SQL ignorabili per migrazioni idempotenti

app/Controllers/UpdateController.php
  - Aggiunto endpoint /admin/updates/logs

app/Views/admin/updates.php
  - Fix: step marcati completati solo in caso di successo
  - Aggiunto parsing markdown per changelog

app/Routes/web.php
  - Aggiunta route per /admin/updates/logs

manual-update.php (STANDALONE)
  - Script di emergenza - NON richiede l'applicazione funzionante
  - Stessi fix di Updater.php: storage/tmp, retry, cURL
  - Controlla spazio disco (200MB minimo)
  - 10 minuti timeout, 512MB memoria
  - Pulizia automatica vecchi temp
  - 8 errori SQL ignorabili per migrazioni idempotenti


ERRORI SQL IGNORATI
-------------------
Le migrazioni ignorano questi errori MySQL per essere idempotenti:
- 1060: Colonna duplicata
- 1061: Chiave duplicata
- 1050: Tabella gia' esistente
- 1091: Impossibile eliminare (DROP)
- 1068: Chiave primaria multipla
- 1022: Entry chiave duplicata
- 1826: FK constraint duplicata
- 1146: Tabella non esiste (per DROP IF NOT EXISTS)


SUPPORTO
--------
Se l'aggiornamento continua a fallire:
1. Controlla i log: /admin/updates/logs
2. Verifica spazio disco disponibile
3. Verifica permessi cartella storage/
4. Contatta il supporto con i log
