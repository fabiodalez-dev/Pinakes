# PR #512: workflow, 26 test e flessibilità delle impostazioni

Verifica del 29 ottobre 2026 sulla catena #503–#512, con #502 esclusa dalla review come richiesto. Le correzioni funzionali della #512 sono nel commit `3960c194`; la suite nel commit `d72fa1d4` comprende i 25 scenari richiesti e la regressione aggiuntiva sulla conservazione del comportamento durante gli aggiornamenti.

## Esito dei workflow

La tabella distingue la batteria avviata sulle correzioni funzionali dalla successiva verifica della nuova suite. Gli esiti si riferiscono al commit indicato, non a run precedenti della stessa PR.

Tutti i dieci workflow delle correzioni sono terminati con successo. Anche Code Quality e la matrice database sulla suite aggiornata di 26 scenari sono verdi: non sono emersi errori CI da correggere.

### Correzioni funzionali: `3960c194`

| Workflow | Esito | Run |
| --- | --- | --- |
| Code Quality | Superato | [40830554173](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830554173) |
| E2E Test Suite | Superato: generale, catalogazione e circolazione 0.9.0 | [40830557203](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830557203) |
| Browser Compatibility and DAST | Superato | [40830559955](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830559955) |
| Deep Regression Gate | Superato: quattro shard browser e cinque installazioni localizzate | [40830562699](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830562699) |
| Database Compatibility | Superato | [40830565681](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830565681) |
| Security and Supply Chain | Superato | [40830568665](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830568665) |
| Real Upgrade, interfaccia admin | Superato | [40830571304](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830571304) |
| Upgrade Smoke Test | Superato | [40830574075](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830574075) |
| Test Database Migrations | Superato | [40830576583](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830576583) |
| CodeQL Security Analysis | Superato | [40830579689](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830579689) |

### Nuova suite: `d72fa1d4`, 26 scenari

| Verifica | Esito | Run |
| --- | --- | --- |
| Esecuzione locale, MySQL isolato | 26 superati, 0 falliti | `php tests/stack-090-settings-flexibility.unit.php` |
| Code Quality | Superato | [40844504696](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40844504696) |
| Database Compatibility | Superato: 26/26 scenari su MySQL 8.0/8.4 e MariaDB 10.11/11.4 | [40844508132](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40844508132) |

## Cosa è già configurabile

Tutti gli undici moduli opzionali introdotti hanno un interruttore persistente. Otto sono in **Impostazioni → Moduli**; regole lettore, calendario e multe sono in **Impostazioni → Prestiti**. Gli interruttori sono scelte di configurazione: lo staff non può modificarli; valgono i controlli amministrativi e la capacità di configurazione previsti dall'applicazione.

Spegnere un modulo conserva i suoi dati. La modalità catalogo sospende regole lettore, calendario, multe e presenze senza cancellare le scelte salvate: uscendone, i moduli riprendono lo stato scelto.

| Ambito | Dove si spegne | Scelte già disponibili |
| --- | --- | --- |
| Revisione inventariale | Moduli → Revisione inventariale | Sede e filtri per sessione; chiusura con o senza dichiarazione di perdita delle copie mancanti; annullamento; report congelato. |
| MARC | Moduli → Import ed export MARC | Duplicati: salta, completa, sovrascrivi; copertine facoltative; export per formato e selezione di libri. |
| Schede catalografiche | Moduli → Schede catalografiche | Stampa di un libro o di una selezione; l'esportazione richiede un'azione esplicita. |
| Fornitori | Moduli → Fornitori | Fornitore facoltativo per libro o importazione; eliminare il fornitore scollega i libri senza eliminarli. |
| Tipi RDA | Moduli → Tipi RDA | Valori derivati dal materiale oppure override per record; gli override rimangono conservati quando il modulo è spento. |
| Tessere e foto | Moduli → Tessere e foto dei lettori | Impostazioni → Tessere: layout, codice a barre/QR, foglio/singola, titolo e retro; foto, logo e scadenza si possono omettere dalla stampa. |
| Import utenti CSV | Moduli → Importazione utenti da CSV | Mappatura dei campi e inviti email facoltativi; tipologia esclusa dalla mappatura e dall'assegnazione quando le regole lettore sono spente. |
| Presenze | Moduli → Registro presenze | Conservazione configurabile nel registro; `0` disattiva la cancellazione automatica. La gestione della conservazione segue il controllo amministrativo del registro. |
| Regole lettore | Prestiti → Regole per tipologia di lettore | Regole globali, tipologie e matrice per materiale; durata, rinnovi, limiti, prenotazioni, validità tessera e consultazione. |
| Calendario | Prestiti → Calendario chiusure | Giorni settimanali, paese/regione e festività selezionate, chiusure per sede, intervalli, ricorrenze, import ICS; chiusure annuali manuali disattivabili singolarmente. |
| Multe e registro | Prestiti → Multe e registro movimenti | Tariffa, tolleranza, tetto e soglia debito; override per tipologia e materiale; avviso email facoltativo. Tariffa e blocco sono indipendenti. |

I valori numerici hanno significati diversi che vanno conservati: `0` rinnovi vieta i rinnovi; `0` per i limiti quantitativi significa nessun limite; tariffa o soglia debito a `0` disattivano quel singolo automatismo. Il valore vuoto nei campi di ereditarietà lascia applicare il livello sottostante. Queste combinazioni sono verificate dai test, non soltanto dalla presenza dei campi nel form.

Per usare solo il registro contabile mantenere il modulo multe acceso e impostare tariffa e soglia debito a zero. Per addebitare ritardi senza bloccare i prestiti, scegliere una tariffa positiva e una soglia a zero. Per bloccare il debito senza nuove multe automatiche, usare tariffa zero e soglia positiva.

## I 26 nuovi scenari

File: [`tests/stack-090-settings-flexibility.unit.php`](../../tests/stack-090-settings-flexibility.unit.php). Esecuzione locale aggiornata: **26/26 superati, nessun fallimento**.

La suite usa controller reali, impostazioni salvate e servizi applicativi. Non forza i moduli con `Features::override()`. Crea un database casuale con schema, dati iniziali e trigger reali, e lo elimina alla fine; non modifica i dati della biblioteca. La CI la raccoglie automaticamente come suite `*.unit.php`.

| N. | Scenario |
| --- | --- |
| 1 | Salvataggio di una selezione mista dei moduli e lettura effettiva da parte del runtime. |
| 2 | Un salvataggio della scheda Moduli non altera gli interruttori della scheda Prestiti. |
| 3 | Lo staff non può modificare i moduli opzionali. |
| 4 | Gli interruttori della circolazione sono indipendenti e gli zeri espliciti vengono conservati. |
| 5 | Lo staff non può modificare interruttori della circolazione o importi delle multe. |
| 6 | La modalità catalogo sospende e ripristina le scelte di circolazione senza cancellarle. |
| 7 | Un modulo spento restituisce `feature_disabled` ai client JSON senza eseguire l'endpoint. |
| 8 | La chiusura di tutti e sette i giorni viene rifiutata senza sovrascrivere il calendario precedente. |
| 9 | Festività globali e chiusure di sede producono scadenze diverse per le copie di due sedi. |
| 10 | Riaccendere il calendario applica subito una festività lontana senza attendere la manutenzione. |
| 11 | Rimuovere una regione elimina la sua festività senza alterare una chiusura manuale disattivata. |
| 12 | L'applicazione delle festività partecipa alla transazione del chiamante e ne segue il rollback. |
| 13 | Una cella della matrice vieta i rinnovi con `0` e continua a ereditare la durata lasciata vuota. |
| 14 | Spegnere le regole ripristina durata globale e prestabilità; riaccenderle recupera tipologia e consultazione. |
| 15 | Il blocco per debito funziona con le nuove multe automatiche disattivate. |
| 16 | Le multe automatiche funzionano con il blocco per debito disattivato. |
| 17 | Una tipologia può escludere tariffa e blocco globali con zeri espliciti. |
| 18 | Un importer CSV già creato smette di assegnare tipologie quando il modulo viene spento e riprende alla riaccensione. |
| 19 | Il codice CSV identifica la tipologia esatta anche quando due tipologie hanno lo stesso nome. |
| 20 | MARC completa/sovrascrivi rispettano scelte diverse sul materiale e preservano prezzo e formato del libro esistente. |
| 21 | Disattivare e riattivare RDA conserva gli override manuali. |
| 22 | La sospensione dei fornitori conserva l'associazione; eliminarne uno conserva il libro. |
| 23 | Layout tessera, QR, formato e omissione di foto/logo/scadenza rimangono indipendenti dalla visibilità del modulo. |
| 24 | La conservazione delle presenze supporta sia nessuna cancellazione automatica sia una finestra personalizzata. |
| 25 | L'inventario si chiude senza marcare perdite e conserva i report quando il modulo viene spento. |
| 26 | La migrazione completa, ripetuta, conserva preferenze salvate, dati e comportamento effettivo: anche con tutti i moduli spenti, tutti accesi, scelte miste e aggiornamento dallo schema 0.8.9. |

## Vincolo permanente per gli aggiornamenti

Gli utenti devono mantenere il comportamento già scelto a ogni aggiornamento. Un default nuovo si applica a una preferenza assente; non sostituisce un valore salvato, compresi `0`, stringa vuota o modulo esplicitamente spento. La presenza di dati del modulo non autorizza a riaccenderlo se l'utente lo ha disattivato.

Il test 26 esegue l'intera `migrate_0.9.0.sql` due volte per cinque configurazioni: moduli tutti spenti, tutti accesi, scelte miste in modalità catalogo e schema 0.8.9 nelle due modalità operative. Controlla i valori persistiti e ricostruisce i servizi dopo la migrazione, verificando durata/rinnovi/prestabilità, debito, scadenze, tessere, conservazione, dati del lettore e del catalogo. Copre anche preferenze sconosciute al core e scelte di spegnimento in presenza di dati esistenti.

La fixture dello schema 0.8.9 è inclusa nel repository: la verifica del vero aggiornamento resta obbligatoria anche nei checkout CI senza cronologia o tag.

## Cosa cambierei

Le proposte seguenti ampliano le scelte dell'utente. Sono proposte di prodotto, separate dalle verifiche e dalle correzioni già pubblicate.

I nuovi controlli devono partire dall'assetto effettivo della biblioteca, conservando il comportamento esistente fino a una scelta esplicita. L'aggiornamento non deve attivare tariffe, cambiare la prestabilità di copie o avviare cancellazioni di dati come effetto collaterale dell'aggiunta di un'opzione.

Consentirei inoltre agli amministratori di preparare calendari e regole mentre il relativo modulo è spento, con un riepilogo prima dell'attivazione. Oggi alcuni pannelli seguono lo stesso blocco delle pagine operative: configurare in anticipo renderebbe più controllabile il passaggio all'uso effettivo.

### Priorità alta

1. **Aperture straordinarie per data e sede.** Oggi il calendario descrive chiusure: non si può aprire una singola domenica mantenendo tutte le altre domeniche chiuse, oppure aprire una sola festività senza deselezionarla per tutti gli anni. Aggiungerei un'eccezione di apertura esplicita per quella data e sede, prioritaria rispetto a ricorrenze e preset, visibile nell'anteprima e applicata su tutti i canali.
2. **Separare le foto dalla stampa delle tessere.** Oggi si può togliere la foto dal PDF, ma acquisizione fotografica e stampa condividono lo stesso modulo. Aggiungerei un controllo dedicato all'acquisizione/visualizzazione delle foto, lasciando utilizzabili tessere e codici a barre. La riattivazione conserverebbe le foto già registrate.
3. **Separare consultazione e regole per tipologia.** Spegnere le regole oggi rende prestabili anche le copie segnate in sola consultazione. Consentirei di mantenere quel vincolo fisico senza usare tipologie e matrice. La scelta dovrebbe valere su banco, sito, app e NCIP.
4. **Mostrare l'effetto delle modifiche sul lavoro già aperto.** Prima di applicare un calendario o riattivare il registro, offrirei un riepilogo dei ritiri interessati e delle penalità già registrate che verranno riportate nel registro. Le scadenze dei prestiti in corso resterebbero modificabili con un'azione esplicita e tracciata.

### Priorità successiva

5. **Festività selezionabili per sede.** I preset sono globali; le eccezioni di sede richiedono chiusure manuali/ICS. Aggiungerei una selezione ereditata dal calendario generale, con eccezioni di sede chiaramente visibili nell'anteprima.
6. **Separare raccolta e conservazione delle presenze.** Spegnere il registro oggi sospende anche la cancellazione automatica. Offrirei la scelta di fermare i nuovi ingressi continuando ad applicare la finestra di conservazione ai dati esistenti.
7. **Ampliare la scelta dell'anno nell'anteprima delle festività.** Il motore ora calcola anche anni lontani; il selettore dell'anteprima mostra ancora anno corrente e due successivi. Consentirei di scegliere direttamente un altro anno senza trasformare il buffer tecnico in una preferenza dell'utente.
8. **Rendere esplicita la semantica di vuoto e zero.** Mostrerei vicino a ogni campo «eredita», «senza limite» o «disattiva», secondo il suo significato, e un riepilogo della regola effettiva per lettore e materiale.
9. **Profili iniziali modificabili.** Proporrei configurazioni «catalogo», «prestiti essenziali» e «circolazione completa», accompagnate dal riepilogo degli interruttori modificati. Ogni modulo resterebbe poi configurabile singolarmente.
10. **Opzioni aggiuntive per multe e tessere.** Valuterei una scelta esplicita tra tolleranza come soglia e giorni effettivamente abbuonati, e tra validità tessera calcolata da oggi o dalla scadenza esistente. Manterrei il comportamento attuale come default e verificherei le nuove opzioni su tutti i canali.

Terrei interni dimensione dei batch, timeout dei lock e finestra di materializzazione delle festività: sono parametri operativi. Le impostazioni rivolte al bibliotecario dovrebbero descrivere regole di servizio, conservazione dei dati e presentazione dei documenti.
