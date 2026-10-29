# PR #512: interruttori, aggiornamenti e verifiche

Aggiornamento del 29 ottobre 2026 sulla catena #503–#512, con #502 esclusa dalla review. Gli undici moduli hanno ora **18 interruttori persistenti**: dieci in **Impostazioni → Moduli**, otto in **Impostazioni → Prestiti**. I controlli nuovi riprendono etichette, descrizioni, accessibilità e stile degli interruttori esistenti.

## Controlli applicati

| Dove | Controllo | Comportamento |
| --- | --- | --- |
| Moduli → Utenti | Stampa delle tessere | Abilita PDF singoli e multipli. Le impostazioni di stampa si possono preparare anche a modulo spento. |
| Moduli → Utenti | Foto dei lettori | Acquisizione, visualizzazione, modifica e cancellazione indipendenti dalla stampa. Spento, blocca anche gli URL diretti ed esclude le foto dai PDF; conserva i file esistenti. |
| Moduli → Servizi al pubblico | Registro presenze | Abilita la raccolta di nuovi ingressi. |
| Moduli → Servizi al pubblico | Conservazione a registro sospeso | Consente di applicare la conservazione mentre la raccolta è spenta, anche in modalità catalogo. Parte spento; non avvia cancellazioni durante l’aggiornamento. |
| Moduli → Servizi al pubblico | Giorni di conservazione | Modificabili anche a registro spento. `0` impedisce sempre la cancellazione automatica. |
| Prestiti → Regole lettore | Regole per tipologia | Attiva tipologie, matrice, durata e limiti per lettore e materiale. Gli amministratori possono preparare la configurazione a modulo spento. |
| Prestiti → Regole lettore | Copie in sola consultazione | Applica il vincolo fisico anche con le regole per tipologia spente. Controllo condiviso da banco, sito, app, allocazione SQL e integrazioni. |
| Prestiti → Regole lettore | Validità automatica delle tessere | Applica la durata della tipologia a nuove iscrizioni e approvazioni. Spento, conserva la scadenza già salvata; il rinnovo manuale resta disponibile. |
| Prestiti → Regole lettore | Base del rinnovo tessera | Scelta tra oggi, comportamento precedente, e scadenza futura esistente. Una scadenza già passata riparte da oggi. |
| Prestiti → Calendario | Calendario chiusure | Sospende l’applicazione del calendario senza cancellarlo. Gli amministratori possono preparare chiusure e festività e vederne l’anteprima anche a modulo spento. Anno delle festività selezionabile dal 1900 al 2199. |
| Prestiti → Multe | Registro movimenti | Addebiti manuali, pagamenti, ricevute, condoni e saldo. Può funzionare con tutti e tre gli automatismi successivi spenti. |
| Prestiti → Multe | Multe automatiche per ritardo | Richiede registro attivo, interruttore dedicato e tariffa positiva. Le tariffe per tipologia e materiale non aggirano il controllo. |
| Prestiti → Multe | Blocco per debito | Richiede registro attivo, interruttore dedicato e soglia positiva. Funziona senza nuove multe automatiche. |
| Prestiti → Multe | Riporto penalità per perdita e danno | Riporta soltanto importi già assegnati, compresi quelli precedenti. Il form mostra quantità e totale degli importi da riportare. La riattivazione riapre la scansione idempotente. |
| Prestiti → Multe | Modalità di tolleranza | Soglia, come prima: oltre la tolleranza si conta dal primo giorno. Oppure abbuono: si sottraggono sempre i giorni di tolleranza. |

Gli altri controlli restano nelle sezioni esistenti: inventario, MARC, schede catalografiche, fornitori, tipi RDA e importazione CSV. Inventario senza dichiarazione di perdita, scelta dei duplicati MARC, inviti email, campi facoltativi e contenuti della tessera continuano a richiedere le rispettive scelte esplicite.

Accanto ai campi ereditabili sono indicati i significati di vuoto e zero: rinnovi vietati, nessun limite, nessun tetto, nessuna tolleranza oppure disattivazione per tipologia. Il riepilogo delle regole e i valori ereditati della matrice restano disponibili.

## Garanzia sugli aggiornamenti

**Se le multe non erano previste, l’aggiornamento non le introduce.**

- Ogni nuovo controllo separato eredita lo stato del precedente controllo soltanto quando la preferenza è assente. Un valore salvato, compreso `0`, viene conservato.
- Nuovi controlli assenti e registro precedentemente spento: multe automatiche, blocco e riporto penalità vengono salvati spenti, anche in presenza di tariffe positive, tipologie configurate o penalità pregresse.
- Attivare successivamente il solo registro lascia spenti gli automatismi. Tariffe e soglie restano memorizzate per una scelta futura esplicita.
- La migrazione non riporta penalità quando il registro o il relativo controllo sono spenti. Gli importi precedenti rimangono in `prestiti.sanzione`; i movimenti già esistenti rimangono conservati.
- I vecchi moduli di salvataggio, che non inviano i nuovi campi, conservano le preferenze. Prima di cambiare il vecchio controllo vengono fissate le scelte ereditate, così non seguono accidentalmente la successiva riattivazione.
- La modalità catalogo sospende la circolazione mantenendo gli interruttori salvati. La cancellazione delle presenze a registro sospeso richiede la propria attivazione esplicita.
- Le nuove scelte di tolleranza e rinnovo mantengono i comportamenti precedenti come default. I prestiti esistenti mantengono la propria scadenza.

Gli interruttori e le nuove regole di servizio sono riservati agli amministratori e ai ruoli con capacità di configurazione. L’accesso alla configurazione di un modulo spento conserva autenticazione, CSRF e controllo del ruolo; le rotte operative restano protette.

## Verifiche dell’implementazione

- **325 suite PHP superate, 0 saltate, 0 fallite**, su database MySQL temporaneo isolato.
- **37 scenari di integrazione** in [`tests/stack-090-settings-flexibility.unit.php`](../../tests/stack-090-settings-flexibility.unit.php): i 25 richiesti, la regressione sugli aggiornamenti e 11 verifiche aggiuntive sui nuovi controlli.
- Migrazione completa ripetuta con moduli accesi, spenti, combinazioni miste, modalità catalogo e schema reale 0.8.9. Fixture inclusa nel repository per rendere la verifica obbligatoria anche nei checkout senza cronologia.
- Test specifico con registro spento, tariffe globali e di tipologia positive e penalità precedenti: due migrazioni non producono addebiti né riattivazioni. Accendere poi il solo registro lascia spenti gli automatismi.
- PHPStan 2.1.56, configurazione completa del repository: nessun errore.
- Gate delle migrazioni, schema e auto-riparazione dei plugin: superato.
- Registro dei 18 controlli, distribuzione nei form, protezioni delle rotte, seed delle cinque lingue e traduzioni: verificati.
- **25 test browser superati**: salvataggio dei controlli, tastiera, permessi, configurazione a modulo spento, calendario, foto e consultazione indipendenti.
- Controllo visivo nel browser a 1280 px e 390 px: nessun overflow orizzontale nei nuovi gruppi di impostazioni.

## Workflow già verificati prima di questa estensione

Gli esiti seguenti riguardano esplicitamente i commit indicati; non vengono attribuiti alle modifiche successive.

| Workflow sulle correzioni `3960c194` | Esito | Run |
| --- | --- | --- |
| Code Quality | Superato | [40830554173](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830554173) |
| E2E Test Suite | Superato | [40830557203](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830557203) |
| Browser Compatibility and DAST | Superato | [40830559955](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830559955) |
| Deep Regression Gate | Superato | [40830562699](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830562699) |
| Database Compatibility | Superato | [40830565681](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830565681) |
| Security and Supply Chain | Superato | [40830568665](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830568665) |
| Real Upgrade | Superato | [40830571304](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830571304) |
| Upgrade Smoke Test | Superato | [40830574075](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830574075) |
| Test Database Migrations | Superato | [40830576583](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830576583) |
| CodeQL | Superato | [40830579689](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830579689) |

La precedente suite di 26 scenari, commit `d72fa1d4`, ha superato [Code Quality](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40844504696) e la [matrice MySQL 8.0/8.4 e MariaDB 10.11/11.4](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40844508132).

## Ulteriori opzioni di prodotto già proposte

Restano le proposte che ampliano il modello del calendario e i profili iniziali: aperture straordinarie per data e sede, preset di festività con ereditarietà per sede, riepilogo dei ritiri prima di applicare nuove chiusure e profili iniziali modificabili. L’estensione attuale applica le proposte sugli interruttori e sulle opzioni di calcolo; questi sviluppi del modello rimangono distinti e richiedono ulteriori implementazioni e verifiche.
