# Desiderata e donazioni

Plugin opzionale di Pinakes. Attivarlo da **Amministrazione → Plugin**.
Richiede anche gli hook e il supporto alla visibilità inclusi in questo branch: non installare il solo ZIP su una versione del core che non li contiene.

## Flusso biblioteca

1. Creare una scheda dal normale modulo libri, usando ISBN, scraping e tutti i metadati disponibili.
2. Prima delle copie, selezionare **Desiderata: cerchiamo questo libro**. Il numero di copie viene impostato a zero e disabilitato. Anche il server forza zero, indipendentemente dal valore inviato dal browser.
3. La scheda compare nella sezione desiderata della homepage, in `/desiderata` e nei pannelli della dashboard. Un normale libro a zero copie non diventa un desiderata.
4. In **Desiderata e donazioni** (`/admin/desiderata`) valutare le proposte. **Accetta proposta** non crea copie. La voce di menu porta un contatore delle proposte ancora da giudicare.
5. Dopo la consegna, usare **Libro arrivato: registra una copia**. Per offerte libere, creare prima la scheda con zero copie, se manca, e selezionarla tramite ricerca per titolo/ISBN. Se il donatore ha scritto un ISBN, il selettore parte già dalle schede che lo portano: è un suggerimento, la scelta resta modificabile e la ricezione viene comunque validata dal server.
6. La ricezione crea una sola copia con inventario assegnato dal core e rimuove il flag dalla stessa scheda bibliografica. Copia, disponibilità, flag e stato della proposta sono salvati in una transazione. Ripetere la ricezione non duplica la copia.

Anche aggiungere la prima copia attraverso la normale gestione delle copie converte automaticamente il desiderata in libro del catalogo. Una successiva perdita/rimozione delle copie non ripristina il flag. I libri che hanno già copie, anche fuori circolazione, non possono essere segnati come desiderata dal modulo.

### Ricezione diretta dal banco

Quando qualcuno porta un libro richiesto senza aver mandato nessuna proposta, il pulsante **Libro donato: registra la copia** — sulla scheda della richiesta, sia in `/admin/desiderata` sia nel pannello della dashboard — fa tutto in un passaggio: crea la copia fisica, toglie il flag e registra la ricezione nello storico con i dati del donatore vuoti (il pannello scrive **Ricezione diretta registrata dallo staff** al posto del nome).

La ricezione diretta **viene rifiutata** se sul libro esistono proposte aperte: chiuderla da qui lascerebbe il donatore in attesa di una risposta su un libro già entrato in catalogo. In quel caso la scheda mostra il collegamento alle proposte, e la ricezione va registrata da lì. Un secondo clic, o un invio ripetuto dello stesso modulo, risponde 422 e non crea una seconda copia.

## Sezione in homepage e testi per lingua

Attivando il plugin compare una riga **Desiderata e donazioni** in **Amministrazione → CMS → Homepage**, insieme alle sezioni native: si trascina per cambiare posizione e si spegne con lo stesso interruttore delle altre. La sezione viene disegnata esattamente nel punto scelto.

Nella stessa pagina c'è la scheda dei testi, con un pannello per ogni lingua installata: occhiello, titolo, introduzione, titolo e testo del modulo, etichetta del pulsante. Un campo lasciato vuoto usa il testo standard del plugin **tradotto in quella lingua**, quindi non serve compilare tutte le lingue per averle tutte corrette. I testi stanno nelle impostazioni del plugin, non nel CMS: se il plugin viene disattivato spariscono con lui, e la riga della homepage viene rimossa dopo aver memorizzato posizione e visibilità, che tornano com'erano alla riattivazione.

## Flusso pubblico

- Elenco iniziale degli ultimi 12 desiderata con copertina (o segnaposto); ricerca AJAX da tre caratteri, massimo 30 risultati per ricerca, per **titolo, autore, editore o ISBN**. L'editore viene cercato sia nel campo della scheda sia fra gli editori collegati alle schede con più editori.
- **Ce l'ho, posso donarlo** collega la proposta al libro richiesto e precompila i dati.
- Lo stesso modulo permette proposte libere: nome, email, titolo, autore/editore/ISBN facoltativi, condizioni e note.
- Il modulo compare anche **sulla pagina del singolo libro cercato**, già legato a quella scheda: dopo l'invio il donatore torna sulla pagina da cui era partito, non sull'elenco generale.
- Le proposte restano nella tabella del plugin e non creano libri o copie.
- CSRF, controllo dei campi, consenso al contatto, honeypot e limite per IP/sessione. Nessun contatto personale è restituito dalla ricerca pubblica.
- **reCAPTCHA v3** sul modulo di donazione, in tutte e tre le collocazioni. Usa le **stesse chiavi del modulo contatti** (Impostazioni → Contatti): se la chiave segreta è vuota la verifica non viene nemmeno tentata e il modulo funziona come prima; se è configurata, una proposta senza token viene respinta. L'azione dichiarata è `desiderata_offer` e la soglia di punteggio è 0.5.
- Homepage compatibile con sessione anonima differita: il token CSRF viene richiesto al momento dell'invio. Senza JavaScript è disponibile il modulo nella pagina dedicata.

## Dove si vede un libro cercato, e dove no

Questa distinzione è voluta ed è il cuore della funzione. Con il plugin attivo un libro che la biblioteca cerca:

- **si trova cercandolo per nome** — ricerca del catalogo, anteprima dei risultati nella barra di ricerca, ricerca rapida dello staff — e la sua **pagina è raggiungibile per collegamento diretto**, con l'etichetta *Cercato dalla biblioteca* al posto della disponibilità e senza pulsanti di prestito o prenotazione;
- **non compare sfogliando**: catalogo senza ricerca, carosello dei generi, archivi per autore/editore/genere, ultimi arrivi, feed RSS, sitemap, API mobile e protocolli di interoperabilità (OAI-PMH, SRU, NCIP, OpenURL, BIBFRAME) continuano a ignorarlo, e nessun conteggio del catalogo lo include.

Il motivo è che il momento in cui si decide una donazione è quello in cui qualcuno cerca proprio quel titolo; riempire invece lo sfoglio di libri che la biblioteca non possiede cambierebbe il significato del catalogo. Con il plugin disattivato cade anche la prima metà: le richieste tornano invisibili ovunque, esattamente come prima che la funzione esistesse.

## Pannelli in dashboard

Per amministratori e staff la dashboard mostra due pannelli: i libri che la biblioteca cerca (con copertina, pulsante di ricezione diretta o collegamento alle proposte aperte) e le proposte da valutare. Il primo compare anche quando è vuoto, così chi non ha mai usato la funzione la trova dalla pagina che apre per prima; il secondo solo quando c'è qualcosa da decidere. Ai lettori normali la dashboard non mostra nulla di tutto questo.

## Notifiche

Ogni **nuova proposta** e ogni **libro effettivamente ricevuto** — registrato da una proposta, dalla ricezione diretta, oppure togliendo la spunta e chiedendo le copie dal modulo libri — generano:

- una **notifica nella campanella** dell'amministrazione, scritta nella lingua dell'installazione perché è una riga sola condivisa da tutti, con il collegamento alle proposte o alla scheda del libro ricevuto;
- una **email a ogni amministratore e membro dello staff attivo**, ciascuna **nella lingua del destinatario** (`utenti.locale`), come tutte le altre email di Pinakes.

Gli utenti non attivi non ricevono nulla, e un indirizzo ripetuto riceve una sola copia. Se il server di posta non risponde la campanella viene scritta comunque e l'invio viene saltato, con una riga nel log: una proposta appena registrata non deve mai essere persa perché la posta non partiva. Anche chi registra la ricezione riceve la notifica: in una biblioteca con più persone al banco sono gli altri quelli che devono saperlo.

Non genera notifiche, invece, la conversione automatica che avviene aggiungendo la prima copia dalla gestione copie: lì l'operatore sta lavorando sulla scheda e la sta già guardando.

Il donatore, invece, continua a **non** ricevere nessuna email automatica: il contatto lo prende il personale, usando l'indirizzo riportato nel pannello.

## Dati, disattivazione e disinstallazione

`libri.is_desiderata` è aggiunto idempotentemente dal plugin; `desiderata_offers` contiene proposte e riferimenti alla ricezione. `expectedTables()` e `expectedColumns()` supportano il ripristino dello schema previsto dal gestore plugin. Non si modificano tabelle di copie all'attivazione.

**Disattivando** il plugin restano tutti i dati: le proposte, il flag sulle schede e i testi memorizzati. Spariscono gli hook, la riga della homepage (posizione e visibilità vengono ricordate), la pagina pubblica e la gestione; le richieste tornano nascoste ovunque sul sito pubblico. Riattivare il plugin rimette tutto com'era.

**Disinstallando**, invece, il flag `is_desiderata` viene **azzerato su tutte le schede, comprese quelle cestinate**. È voluto: finché la colonna esiste il core nasconde dal catalogo qualunque scheda contrassegnata, e una richiesta rimasta contrassegnata senza più il plugin sarebbe un libro invisibile senza più nessuna casella nel modulo e niente, in amministrazione, che spieghi perché. Le schede bibliografiche e lo storico delle donazioni restano. Le copie già ricevute sono normali copie Pinakes.

## Hook usati (per chi scrive altri plugin)

Il plugin non ne dichiara di propri: usa punti di estensione del core, che restano disponibili a chiunque.

| Hook | Tipo | A cosa serve |
|---|---|---|
| `app.routes.register` | azione | registra le rotte pubbliche e amministrative |
| `admin.menu.render` | azione | voce di menu con il contatore delle proposte |
| `book.form.before_copies` / `book.form.save` / `book.save.after` | azione / filtro / azione | casella nel modulo libri, azzeramento delle copie, ricezione quando arriva la prima copia |
| `frontend.home.section` | azione | disegna la sezione nella posizione decisa dal CMS |
| `cms.home.section_name` / `cms.home.section.fields` / `cms.home.save` | filtro / azione / filtro | nome nell'elenco, scheda dei testi, salvataggio con validazione |
| `admin.dashboard.sections` | azione | pannelli in dashboard (il core lo esegue solo per admin e staff) |
| `book.frontend.details` | azione | modulo di donazione sulla pagina del libro cercato |
| `book.visibility.discoverable` | filtro | rende trovabile per nome e raggiungibile per collegamento una scheda cercata; il core accetta da questo filtro solo `'1=1'`, quindi può allargare ricerca e pagina di dettaglio e nient'altro |

## Import ed export CSV

Sulle installazioni che hanno il plugin, l'export CSV standard aggiunge in coda la colonna `is_desiderata`; dove il plugin non c'è, la colonna non compare e il file resta identico a prima, byte per byte. L'import riconosce la colonna (anche come `desiderata`/`wanted`) e per una riga contrassegnata scrive il flag, azzera i conteggi e non crea alcuna copia fisica. Un import che aggiorna una scheda già presente non riporta mai un libro del catalogo allo stato di richiesta.

L'export verso LibraryThing **esclude di proposito** le richieste: quel formato è uno schema di terze parti a colonne fisse e non può esprimere la differenza fra un libro posseduto e uno cercato, quindi una richiesta vi arriverebbe indistinguibile da una copia in inventario.

## Verifica

Le suite usano il database di sviluppo configurato in `.env` (override `E2E_DB_*`); creano fixture riconoscibili e le rimuovono al termine. Eseguire su ambiente di sviluppo/CI.

- `php tests/desiderata.integration.php`: 118 verifiche, inclusi limiti Unicode, input anomali, ricerca letterale e paginazione, schede cancellate/ripristinate, copie preesistenti, antispam, XSS, rollback, ricezioni simultanee, round trip CSV verso un'altra installazione e middleware HTTP per permessi/CSRF. I processi concorrenti usano connessioni separate al database.
- `php tests/desiderata-visibility.integration.php`: 33 verifiche sulle superfici che parlano al software di qualcun altro — API mobile e protocolli di interoperabilità — più il ciclo di vita del plugin, che gira in un database usa e getta perché la disinstallazione tocca l'intera tabella.
- `npx playwright test --config=tests/playwright.config.js tests/desiderata.spec.js`: 4 scenari end-to-end, con credenziali `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASS` da ambiente o `tests/.env.test`. Usa l'app locale (`APP_URL`, default `http://localhost:8081`) e rimuove le proprie fixture al termine. Controlla anche l'allineamento dei titoli dei generi a 1440, 1024, 768 e 390 px; se la homepage non contiene generi, renderizza il template reale con contenuti di prova senza cambiare la configurazione della biblioteca.
