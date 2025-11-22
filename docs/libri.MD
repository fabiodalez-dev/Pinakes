# 📚 Gestione Libri - Documentazione Ufficiale

## 📖 PARTE 1: GUIDA PER L'UTENTE FINALE

### Benvenuto nella Gestione Libri 🎉
Questa pagina ti spiega **tutto quello che puoi fare** con i libri della tua biblioteca. Non serve essere tecnici!

### 🎯 Cosa Puoi Fare Qui

| **Funzione** | **Serve per...** | **Quando usarla** |
|--------------|-------------------|-------------------|
| **📚 Lista Libri** | Vedere tutti i libri della biblioteca | Ogni giorno |
| **🔍 Ricerca** | Trovare un libro specifico | Quando ti serve |
| **➕ Aggiungi Libro** | Inserire nuovi libri | Quando acquisti libri |
| **✏️ Modifica** | Cambiare informazioni | Quando trovi errori |
| **🏷️ Etichette** | Stampare codici per gli scaffali | Quando organizzi |
| **📊 Esporta** | Salvare liste in Excel | Per inventari |
| **🗑️ Elimina** | Rimuovere libri non più presenti | Con cautela |
| **📦 Copie** | Gestire più copie dello stesso titolo | Quando hai più esemplari |

---

### 🚀 Come Entrare

1. **Vai su**: Dashboard → **Libri**
2. **Oppure digita**: `localhost:8000/admin/libri`
3. **Vedrai**: Una grande tabella con tutti i libri!

---

### 🔍 Come Cercare Libri (Facile!)

#### Metodo 1: Ricerca Rapida
- Scrivi **qualunque cosa** nella barra in alto
- Esempi: `Dante`, `Commedia`, `9788842935780`
- **Funziona per**: titolo, autore, ISBN, editore

#### Metodo 2: Filtri Precisi
Clicca su **"Filtri"** per usare:
- **Disponibilità**: Solo libri liberi
- **Autore**: Tutti i libri di uno scrittore
- **Data**: Libri acquistati nel 2024
- **Genere**: Solo narrativa, saggistica, ecc.
- **Etichetta**: Filtra per scaffale o posizione

---

### 📊 Leggere la Tabella (Semplice!)

| **Colonna** | **Cosa Significa** | **Esempio** |
|-------------|-------------------|-------------|
| 🟢🟡🔴 | Stato del libro | 🟢=Libero, 🔴=Prestato |
| 📷 | Copertina | Clicca per vedere grande |
| 📖 | Titolo + Autore | `Divina Commedia - Dante` |
| 🏷️ | Posizione fisica | `A.2.15` = Scaffale A, Mensola 2, Posizione 15 |
| 📅 | Anno | Quando è stato pubblicato |
| ⚙️ | Azioni | Cosa puoi fare |

---

### ➕ Aggiungere un Nuovo Libro

#### Metodo A: Automatico (Consigliato)
1. Clicca **"+ Nuovo Libro"**
2. Trova l'**ISBN** sul retro del libro (13 cifre)
3. Digita l'ISBN e clicca **"Importa"**
4. **Magia!** Il sistema recupera:
   - Titolo completo ✓
   - Autore/i ✓
   - Copertina ✓
   - Descrizione ✓
   - Prezzo ✓
   - Classificazione Dewey (se disponibile)

#### Metodo B: Manuale
1. Clicca **"+ Nuovo Libro"**
2. Compila i campi:
   - **Titolo** (obbligatorio)
   - **Autore** (puoi crearne di nuovi)
   - **Posizione** (scaffale/mensola)
   - **Numero copie** (default 1)
3. Carica copertina (se vuoi)
4. Salva!

> 📚 **Vuoi i dettagli?** Leggi la [Guida Completa Inserimento Libri](/docs/inserimento_libri.MD) con tutti i campi spiegati, lo scraping automatico e 4 esempi pratici.

#### Inserimento Manuale Avanzato
- **Gestione delle copie**: inserisci il numero di copie totali; il sistema calcola le copie disponibili.
- **Gestione dei codici a barre**: se fornisci un EAN/ISBN‑13, il codice a barre viene generato automaticamente.
- **Campi opzionali**: lingua, formato, peso, dimensioni, prezzo di acquisto, data di acquisizione, categoria, genere, sottogenere, collana, serie, numero di serie.

---

### ✏️ Modificare un Libro Esistente

1. **Trova** il libro nella tabella
2. **Clicca** l'icona ✏️ nella riga
3. **Cambia** solo ciò che serve:
   - Titolo sbagliato? Correggi
   - Autore mancante? Aggiungi
   - Posizione cambiata? Aggiorna
   - Copie aggiunte o rimosse? Aggiorna il contatore
4. **Salva** e fatto!

> ⚠️ **Nota**: Se il libro è in prestito, alcuni campi (es. stato, copie disponibili) sono bloccati per sicurezza.

> 📚 **Vuoi i dettagli?** Leggi la [Guida Modifica Libro](/docs/modifica_libro.MD) con 8 passi completi, 5 casi reali e tutte le soluzioni ai problemi comuni.

---

### 🏷️ Stampare le Etichette

Le etichette servono per **trovare i libri sugli scaffali**.

#### Procedura
1. Clicca sul **titolo del libro** nella tabella
2. Clicca **"Stampa Etichetta"**
3. **PDF** pronto! Contiene:
   - Nome della biblioteca
   - Titolo abbreviato
   - Autore
   - Posizione (es. `A.2.15`)
   - Codice a barre (EAN/ISBN)

#### Formati Disponibili
- **25×38 mm**: Standard per dorso libri
- **50×25 mm**: Orizzontale
- **70×36 mm**: Grande

> 📚 **Vuoi i dettagli?** Leggi la [Guida Stampa Etichette](/docs/stampa_etichette.MD) con come configurare il formato, dove comprare la carta, istruzioni di stampa e come applicarle.

---

### 👁️ Vedere i Dettagli di un Libro

1. **Clicca** sul titolo del libro
2. **Vedrai**:
   - Copertina grande
   - Tutte le informazioni (ISBN, editore, data, ecc.)
   - Storico prestiti
   - Bottoni per modificare, stampare etichetta, gestire copie

> 📚 **Vuoi i dettagli?** Leggi la [Guida Scheda Libro](/docs/scheda_libro.MD) che spiega le 10 sezioni della scheda, i bottoni disponibili e come navigare tra modifica, stampa e visualizzazione.

---

### 📤 Esportare la Lista

1. **Applica** i filtri (se vuoi)
2. **Clicca** i bottoni in alto:
   - **CSV**: Per Excel
   - **PDF**: Per stampare
   - **Stampa**: Diretta su stampante

---

### 🗑️ Eliminare un Libro

> ⚠️ **Attenzione**: L'eliminazione è **permanente** e non può essere annullata.

1. Trova il libro
2. Clicca 🗑️
3. **Conferma** nel popup

> **Non puoi eliminare** se:
> - Il libro è in prestito
> - Ha prestiti non restituiti
> - È collegato a record di storico (es. recensioni)

---

### 📦 Gestione delle Copie

- **Aggiungere copie**: nella pagina di modifica, aumenta il campo *Copie totali*; il sistema aggiorna automaticamente *Copie disponibili*.
- **Rimuovere copie**: diminuisci *Copie totali*; il sistema verifica che non ci siano copie in prestito prima di consentire la riduzione.
- **Stato delle copie**: ogni copia ha uno stato interno (`Disponibile`, `Prestato`, `Danneggiato`, `Perso`). Il conteggio è mostrato nella colonna *Stato*.

---

### 🛡️ Sicurezza (CSRF, Validazione, Permessi)

#### CSRF Protection
- **Token CSRF**: ogni form di inserimento/modifica include un campo nascosto `_csrf` generato dal server.
- **Validazione**: il middleware `CsrfMiddleware` verifica il token su ogni POST, PUT, DELETE.
- **Rotazione**: il token viene rigenerato ad ogni login e scade dopo 30 minuti di inattività.

#### Validazione dei Dati
- **Server‑side**: il `BookValidator` controlla:
  - ISBN valido (10 o 13 cifre)
  - Titolo non vuoto
  - Numero di copie ≥ 1
  - Formato data (`Y-m-d`) per data di acquisizione
- **Client‑side**: HTML5 `required`, `pattern` e messaggi di errore personalizzati.

#### Controlli di Accesso
- **Ruoli**: `admin`, `librarian`, `assistant`.
- **Policy**: solo `admin` e `librarian` possono creare, modificare o eliminare libri; `assistant` può solo visualizzare e stampare etichette.
- **Middleware**: `AuthMiddleware` + `RoleMiddleware` proteggono le rotte `/admin/libri/*`.

---

### 🤖 Scraping Automatico (ISBN)

Il controller `ScrapeController` utilizza servizi esterni (Google Books, Open Library) per recuperare metadati a partire da un ISBN.

1. **Richiesta**: `GET /api/scrape/isbn?isbn=9788842935780`
2. **Flusso**:
   - Verifica formato ISBN
   - Chiama API esterne in parallelo
   - Normalizza i dati (titolo, autore, descrizione, copertina)
   - Restituisce JSON con i campi pronti per l'inserimento
3. **Gestione errori**:
   - 404 se nessun risultato
   - 429 se superi il rate limit
   - Log su `storage/logs/scrape.log`

> 📚 **Vuoi i dettagli?** Leggi la sezione *Scraping* nella [Guida Inserimento Libri](/docs/inserimento_libri.MD#scraping-automatico).

---

### 📡 API REST per Libri

| Metodo | Endpoint | Descrizione | Permessi |
|--------|----------|-------------|----------|
| `GET` | `/api/libri` | Lista paginata con filtri avanzati (DataTables) | `admin`, `librarian`, `assistant` |
| `GET` | `/api/libri/{id}` | Dettaglio libro | `admin`, `librarian`, `assistant` |
| `POST` | `/api/libri` | Creazione libro (supporta JSON o form‑data) | `admin`, `librarian` |
| `PUT` | `/api/libri/{id}` | Aggiornamento libro | `admin`, `librarian` |
| `DELETE` | `/api/libri/{id}` | Eliminazione libro (soft‑delete) | `admin` |
| `POST` | `/api/libri/{id}/copy` | Aggiungi copie | `admin`, `librarian` |
| `POST` | `/api/libri/{id}/scrape` | Avvia scraping ISBN | `admin`, `librarian` |

**Esempio di creazione via API**:
```bash
curl -X POST http://localhost:8000/api/libri \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
        "titolo": "Il Nome della Rosa",
        "isbn13": "9788804671234",
        "autore_id": 5,
        "copie_totali": 3,
        "posizione": "B.1.12"
      }'
```

---

### 📦 Import/Export Bulk

#### Importazione CSV
- **Formato**: `titolo,autore,isbn13,anno,collocazione,copie`
- **Endpoint**: `POST /api/libri/import-csv`
- **Validazione**: ogni riga è controllata; le righe errate vengono riportate nel risultato.

#### Esportazione CSV
- **Endpoint**: `GET /api/libri/export-csv?filters=...`
- **Opzioni**: includi solo colonne selezionate, filtra per stato o data.

---

### 🧪 Testing & Debugging

#### PHPUnit Tests (esempio)
```php
class BookRepositoryTest extends TestCase
{
    public function testCreateAndRetrieve(): void
    {
        $repo = new BookRepository();
        $data = [
            'titolo' => 'Test Book',
            'isbn13' => '9781234567890',
            'copie_totali' => 2,
            'posizione' => 'C.3.5'
        ];
        $id = $repo->create($data);
        $this->assertGreaterThan(0, $id);

        $book = $repo->find($id);
        $this->assertEquals('Test Book', $book->titolo);
        $this->assertEquals(2, $book->copie_totali);
    }
}
```

#### Debug Console
```bash
# Verifica API
curl -X GET "http://localhost:8000/api/libri?length=10"

# Verifica scraping
curl -X GET "http://localhost:8000/api/scrape/isbn?isbn=9788842935780"

# Log errori
tail -f storage/logs/app.log
```

---

## 📚 INDICE COMPLETO DELLE GUIDE

| **Operazione** | **Guida Completa** | **Tempo di Lettura** |
|----------------|-------------------|---------------------|
| ➕ Aggiungere un libro | [Guida Inserimento Libri](/docs/inserimento_libri.MD) | 15 min |
| ✏️ Modificare un libro | [Guida Modifica Libro](/docs/modifica_libro.MD) | 10 min |
| 👁️ Visualizzare i dettagli | [Guida Scheda Libro](/docs/scheda_libro.MD) | 8 min |
| 🏷️ Stampare etichette | [Guida Stampa Etichette](/docs/stampa_etichette.MD) | 10 min |
| 📍 Organizzare scaffali | [Guida Collocazione](/docs/collocazione.MD) | 12 min |
| 📕 Gestire prestiti | [Guida Prestiti](/docs/prestiti.MD) | 10 min |
| 📦 Import/Export CSV | [Guida Import/Export](/docs/inserimento_libri.MD#bulk-import-export) | 8 min |
| 🤖 Scraping ISBN | [Guida Scraping](/docs/inserimento_libri.MD#scraping-automatico) | 7 min |
| 🛡️ Sicurezza e CSRF | [Guida Sicurezza](/docs/settings.MD#csrf-protection) | 6 min |

### Argomenti Approfonditi

- **Scraping Automatico**: Come il sistema recupera automaticamente i dati da ISBN/EAN → vedi [Guida Inserimento Libri - Sezione Scraping](/docs/inserimento_libri.MD#scraping-automatico)
- **Classificazione Dewey**: Come classificare i libri per argomento → vedi [Guida Inserimento Libri - Sezione Dewey](/docs/inserimento_libri.MD#dewey-classification)
- **Impostazioni Etichette**: Come configurare formati e tipo di carta → vedi [Guida Stampa Etichette - Sezione Configurazione](/docs/stampa_etichette.MD#configurazione-etichette)
- **CSRF & Permessi**: Dettagli tecnici su token, middleware e ruoli → vedi [Guida Sicurezza](/docs/settings.MD#csrf-protection)

---

## 🎯 Quick Start per Utente Finale

**Vuoi aggiungere subito un libro?**
1. Vai a **Dashboard → Libri**
2. Clicca **"+ Nuovo Libro"**
3. Digita l'ISBN e clicca **"Importa"** (o compila manualmente)
4. Assegna la posizione fisica
5. Clicca **"Salva"**
6. ✅ Fatto! → Stampa etichetta e applica

> 💡 Se hai dubbi, consulta una delle guide correlate qui sopra!

---

*Ultimo aggiornamento: 19 Ottobre 2025*  
*Versione documentazione: 4.1.0*  
*Versione guide correlate: 3.2.0* 🎉
