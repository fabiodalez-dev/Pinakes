# Issue 412 — Emeroteca Semplice e Completa

Verifica del 11 settembre 2026. Versioni: Pinakes **0.7.84**, Emeroteca **1.5.0**.

## Risultato funzionale

La modalità condivisa si modifica sia dalle impostazioni del plugin sia dall'Emeroteca. Per una collezione nuova la modalità iniziale la sceglie l'amministratore, da una delle due pagine: nulla la imposta al suo posto, e fino alla scelta il plugin si comporta come Completa. L'aggiornamento mantiene Completa dove una collezione esiste già e conserva una scelta esplicita già salvata. Il cambio di modalità non converte né elimina record.

Un contributo autonomo richiede solo il titolo: testata, annata e fascicolo non sono obbligatori. La citazione conserva data testuale, volume, numero e pagine. È possibile creare successivamente una testata e associarvi in blocco i contributi esistenti, con anteprima, controllo delle modifiche concorrenti e conferma esplicita delle riassegnazioni. L'associazione non dichiara il possesso di un fascicolo.

Sono incluse le viste amministrative e pubbliche, ricerca, spoglio precedente, PDF protetti, importazione CSV con anteprima, aggiornamenti tramite identità stabile, esportazione, traduzioni nelle cinque lingue e API mobile per i contributi pubblici. Il percorso dei PDF è incluso nei backup completi esistenti.

`libri.tipo_media` conserva il suo significato attuale. I tipi articolo sono riconosciuti dall'importazione dedicata e rifiutati dall'importazione libri con indicazione del percorso corretto. Il client Android è esterno a questo repository: qui sono implementati e verificati gli endpoint server necessari.

## Migrazione e aggiornamento

- `migrate_0.7.84.sql` inizializza la modalità senza sovrascrivere le preferenze esistenti.
- Il lifecycle del plugin crea e ripara `emeroteca_contributi`, colonne e relazioni, anche al recupero di un aggiornamento interrotto.
- Verificato l'aggiornamento attraverso la vera interfaccia amministrativa, partendo dall'archivio ufficiale 0.7.83 e usando un archivio candidato temporaneo. Conservati testata, annata, fascicolo, possesso e spoglio preesistenti; modalità finale Completa.
- Verificata separatamente una nuova installazione e l'attivazione del plugin: modalità iniziale Semplice. *Superato dopo la review:* la modalità iniziale non viene più impostata automaticamente — la sceglie l'amministratore — perché la migrazione la impostava anche su installazioni in cui il plugin non era mai stato attivato, e vinceva sulla scelta. La fase `fresh` del test di upgrade asserisce ora il nuovo comportamento.
- I test distruttivi di schema e installazione sono stati eseguiti su un'istanza MySQL temporanea dedicata. Nessuna release o tag è stato pubblicato.

## Test eseguiti

| Suite PHP | Esito |
| --- | ---: |
| `emeroteca-412.unit.php` | 69 controlli passati |
| `emeroteca.unit.php` | 161 controlli passati |
| `emeroteca-schema-140.unit.php` | 215 controlli passati |
| `emeroteca-admin-140.unit.php` | 116 controlli passati |
| `emeroteca-integration-140.unit.php` | 57 controlli passati |
| `emeroteca-export-140.unit.php` | 123 controlli passati |
| `emeroteca-interop-140.unit.php` | 119 controlli passati |
| `emeroteca-admin-quality-140.unit.php` | 52 controlli passati |

Totale: **912 controlli PHP**. La nuova suite usa servizi reali e tabelle MySQL temporanee con prefisso isolato; `migration-0.7.84.unit.php` la espone anche al gate delle migrazioni.

| Verifica browser | Esito |
| --- | --- |
| `emeroteca-412.spec.js`, `emeroteca.spec.js`, `full-test.spec.js` insieme | 167 passati, 8 saltati perché l'installazione era già completata |
| Flusso 412 dopo gli ultimi affinamenti | Passato; include PDF valido/non valido/sostituzione, privacy, associazione tardiva, spoglio, CSV e modalità |
| Installazione iniziale 0.7.83 nell'ambiente temporaneo | 8 passati |
| Installazione nuova 0.7.84 nell'ambiente temporaneo | 8 passati; copre separatamente la fase saltata dalla regressione |
| Preparazione legacy, verifica dopo upgrade, modalità nuova installazione | 3 fasi passate |
| `manual-upgrade-real.spec.js` | Upgrade amministrativo passato |
| Manifest API e contratti periodicals in `mobile-api-idempotency.spec.js` | 8 passati, incluso dettaglio articolo pubblico ed ETag/304 |

Altri controlli: PHPStan senza errori, build frontend completata, chiavi/placeholder/route delle traduzioni allineati, policy Playwright e `git diff --check` superati. Modulo controllato visivamente a larghezza desktop e telefono.

## Casi coperti dalla nuova suite

- Inserimento senza testata/fascicolo; citazione della richiesta originale e pagine testuali.
- Validazione di campi, ISSN, DOI, anno e tipi; titolo obbligatorio.
- Associazione, creazione tardiva della testata, riassegnazione, distacco, ripetizione della stessa operazione e rollback atomico.
- Revisione obsoleta e record eliminato; fusione delle testate; eliminazione dei contenitori senza perdita del contributo autonomo.
- Visibilità pubblica separata per record e PDF; assenza di note private, collocazione e percorso interno nelle risposte pubbliche.
- CSV: identità stabile, aggiornamento, colonne omesse/celle vuote, duplicati, errori per riga, tipi sconosciuti, UTF-8 e limiti del batch.
- Autorizzazione amministratore per modalità/eliminazione, CSRF, API con paginazione ed ETag.
- Migrazione ripetibile, preferenza preservata, riparazione di colonne e chiavi esterne mancanti.

La verifica riguarda questi flussi e le suite indicate; non equivale all'esecuzione indiscriminata di ogni suite del repository o al collaudo di un client Android esterno.


## Correzioni successive alla review del 13 settembre 2026

- L'import CSV rileva i duplicati anche nello stesso batch e ricontrolla la citazione al commit. Un lock per database serializza gli import concorrenti: viene preso una volta per l'intero batch, perché prenderlo a ogni riga moltiplicava l'attesa per il numero di righe (misurate 50 s per cinque righe, oltre un'ora sul massimo di 500) e l'import moriva sul tempo massimo di esecuzione invece di dire che un altro import è in corso.
- «Solo testata» rimuove il collegamento precedente al fascicolo, previa conferma esplicita nell'anteprima (su una selezione fino a 500 articoli la perdita non può essere dedotta); senza la spunta non viene scritto nulla. Ripetere la medesima destinazione completa resta idempotente.
- Le esportazioni grandi vengono consegnate in uno ZIP con CSV numerati, ciascuno entro 500 record e 5 MB. I piccoli export rimangono CSV singoli.
- Le API mobile degli autonomi includono `pdf_url`, nullo per documenti non pubblici; la revoca cambia anche l'ETag.
- Il confronto dei duplicati dentro il file ignora gli accenti come la collazione della colonna: prima li distingueva, quindi una variante accentata passava l'anteprima e veniva respinta solo al commit.
- Il campo «Tipo Media» della scheda libro — il punto da cui parte la issue — indica ora dove va un articolo: collegamento al modulo articolo se il plugin è attivo, alla pagina Plugins se è installato ma spento, nulla se non è installato. Disinstallare il plugin cancella la sua riga, le sue impostazioni e la sua cartella, ma non le tabelle: gli articoli catalogati restano e tornano visibili alla riattivazione.
- Suite PHP aggiornata: **112 controlli passati**. Inclusi ZIP realmente scaricabile, importabilità delle parti, duplicati (accenti compresi), revoca del PDF, import conteso da una seconda connessione e conferma richiesta per staccare i fascicoli.
- Client Android aggiornato nel repository `pinakes-android`, branch `fix/emeroteca-standalone-articles-412`: discovery compatibile, lista/ricerca/paginazione, filtro per testata, dettaglio bibliografico, collegamenti e PDF pubblico. Le interfacce dei server precedenti rimangono disponibili.
- Android: **166 test passati**, `assembleDebug` e `lintDebug` completati; Lint riporta zero errori. Controllo visivo delle schermate su emulatore con dati sintetici della issue, anche in italiano con caratteri al 130%. I componenti temporanei di anteprima sono stati rimossi prima della build definitiva.

Non sono stati pubblicati release, APK su store, commit o commenti GitHub durante questa correzione.
