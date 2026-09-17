# Desiderata e donazioni

Plugin opzionale di Pinakes. Attivarlo da **Amministrazione → Plugin**.
Richiede anche gli hook e il supporto alla visibilità inclusi in questo branch: non installare il solo ZIP su una versione del core che non li contiene.

## Flusso biblioteca

1. Creare una scheda dal normale modulo libri, usando ISBN, scraping e tutti i metadati disponibili.
2. Prima delle copie, selezionare **Desiderata: cerchiamo questo libro**. Il numero di copie viene impostato a zero e disabilitato. Anche il server forza zero, indipendentemente dal valore inviato dal browser.
3. La scheda compare nella sezione desiderata della homepage e in `/desiderata`, esclusa dal catalogo pubblico ordinario, dalla ricerca pubblica, dal feed e dalla sitemap. Un normale libro a zero copie non diventa un desiderata.
4. In **Desiderata e donazioni** (`/admin/desiderata`) valutare le offerte. **Accetta proposta** non crea copie.
5. Dopo la consegna, usare **Libro arrivato: registra una copia**. Per offerte libere, creare prima la scheda con zero copie, se manca, e selezionarla tramite ricerca per titolo/ISBN.
6. La ricezione crea una sola copia con inventario assegnato dal core e rimuove il flag dalla stessa scheda bibliografica. Copia, disponibilità, flag e stato della proposta sono salvati in una transazione. Ripetere la ricezione non duplica la copia.

Anche aggiungere la prima copia attraverso la normale gestione delle copie converte automaticamente il desiderata in libro del catalogo. Una successiva perdita/rimozione delle copie non ripristina il flag. I libri che hanno già copie, anche fuori circolazione, non possono essere segnati come desiderata dal modulo.

## Flusso pubblico

- Elenco iniziale degli ultimi 12 desiderata; ricerca AJAX da tre caratteri, massimo 30 risultati per ricerca, titolo/autore/ISBN.
- **Ce l’ho, posso donarlo** collega la proposta al libro richiesto e precompila i dati.
- Lo stesso modulo permette proposte libere: nome, email, titolo, autore/editore/ISBN facoltativi, condizioni e note.
- Le proposte restano nella tabella del plugin e non creano libri o copie.
- CSRF, controllo dei campi, consenso al contatto, honeypot e limite per IP/sessione. Nessun contatto personale è restituito dalla ricerca pubblica. Il personale contatta il donatore tramite l’indirizzo riportato nel pannello; non sono inviate email automatiche.
- Homepage compatibile con sessione anonima differita: il token CSRF viene richiesto al momento dell’invio. Senza JavaScript è disponibile il modulo nella pagina dedicata.

## Dati e disattivazione

`libri.is_desiderata` è aggiunto idempotentemente dal plugin; `desiderata_offers` contiene offerte e riferimenti alla ricezione. `expectedTables()` e `expectedColumns()` supportano il ripristino dello schema previsto dal gestore plugin. Non si modificano tabelle di copie all’attivazione.

Disattivazione e disinstallazione conservano dati e metadati; il core continua a escludere i desiderata dal catalogo e a convertirli alla prima copia. Riattivare il plugin per riaprire pagina e gestione. Le copie ricevute sono normali copie Pinakes.

## Import ed export CSV

Sulle installazioni che hanno il plugin, l'export CSV standard aggiunge in coda la colonna `is_desiderata`; dove il plugin non c'è, la colonna non compare e il file resta identico a prima, byte per byte. L'import riconosce la colonna (anche come `desiderata`/`wanted`) e per una riga contrassegnata scrive il flag, azzera i conteggi e non crea alcuna copia fisica. Un import che aggiorna una scheda già presente non riporta mai un libro del catalogo allo stato di richiesta.

L'export verso LibraryThing **esclude di proposito** le richieste: quel formato è uno schema di terze parti a colonne fisse e non può esprimere la differenza fra un libro posseduto e uno cercato, quindi una richiesta vi arriverebbe indistinguibile da una copia in inventario.

## Verifica

`php tests/desiderata.integration.php` usa il database di sviluppo configurato in `.env` (override `E2E_DB_*`); crea fixture riconoscibili, verifica proposta, ricezione, rollback e doppio invio, e rimuove le proprie fixture al termine. Eseguire su ambiente di sviluppo/CI.

### Suite completa

- `php tests/desiderata.integration.php`: 118 verifiche, inclusi limiti Unicode, input anomali, ricerca letterale e paginazione, schede cancellate/ripristinate, copie preesistenti, antispam, XSS, rollback, ricezioni simultanee, round trip CSV verso un'altra installazione e middleware HTTP per permessi/CSRF. I processi concorrenti usano connessioni separate al database.
- `npx playwright test --config=tests/playwright.config.js tests/desiderata.spec.js`: 4 scenari end-to-end, con credenziali `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASS` da ambiente o `tests/.env.test`. Usa l’app locale (`APP_URL`, default `http://localhost:8081`) e rimuove le proprie fixture al termine. Controlla anche l’allineamento dei titoli dei generi a 1440, 1024, 768 e 390 px; se la homepage non contiene generi, renderizza il template reale con contenuti di prova senza cambiare la configurazione della biblioteca.
