# 📚 Gestione Libri - Documentazione Ufficiale

## 📖 PARTE 1: GUIDA PER L'UTENTE FINALE

### Ciao! Benvenuto nella Gestione Libri 🎉

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

---

### 🚀 Come Entrare

1. **Vai su**: Dashboard → **Libri**
2. **Oppure digita**: `localhost:8000/admin/libri`
3. **Vedrai**: Una grande tabella con tutti i libri!

---

### 🔍 Come Cercare Libri (Facile!)

#### **Metodo 1: Ricerca Rapida**
- Scrivi **qualunque cosa** nella barra in alto
- Esempi: `Dante`, `Commedia`, `9788842935780`
- **Funziona per**: titolo, autore, ISBN, editore

#### **Metodo 2: Filtri Precisi**
Clicca su **"Filtri"** per usare:
- **Disponibilità**: Solo libri liberi
- **Autore**: Tutti i libri di uno scrittore
- **Data**: Libri acquistati nel 2024
- **Genere**: Solo narrativa, saggistica, ecc.

---

### 📊 Leggere la Tabella (Semplice!)

| **Colonna** | **Cosa Significa** | **Esempio** |
|-------------|-------------------|-------------|
| 🟢🟡🔴 | Stato del libro | 🟢=Libero, 🔴=Prestato |
| 📷 | Copertina | Clicca per vedere grande |
| 📖 | Titolo + Autore | "Divina Commedia - Dante" |
| 🏷️ | Posizione fisica | "A.2.15" = Scaffale A, Mensola 2, Posizione 15 |
| 📅 | Anno | Quando è stato pubblicato |
| ⚙️ | Azioni | Cosa puoi fare |

---

### ➕ Aggiungere un Nuovo Libro

#### **Metodo A: Automatico (Consigliato)**
1. Clicca **"+ Nuovo Libro"**
2. Trova l'**ISBN** sul retro del libro (13 cifre)
3. Digita l'ISBN e clicca **"Importa"**
4. **Magia!** Il sistema trova:
   - Titolo completo ✓
   - Autore/i ✓
   - Copertina ✓
   - Descrizione ✓
   - Prezzo ✓

#### **Metodo B: Manuale**
1. Clicca **"+ Nuovo Libro"**
2. Compila i campi:
   - **Titolo** (obbligatorio)
   - **Autore** (puoi crearne di nuovi)
   - **Posizione** (scaffale/mensola)
3. Carica copertina (se vuoi)
4. Salva!

> 📚 **Vuoi i dettagli?** Leggi la [Guida Completa Inserimento Libri](/docs/inserimento_libri.MD) con tutti i campi spiegati, lo scraping automatico e 4 esempi pratici.

---

### ✏️ Modificare un Libro Esistente

1. **Trova** il libro nella tabella
2. **Clicca** l'icona ✏️ nella riga
3. **Cambia** solo ciò che serve:
   - Titolo sbagliato? Correggi
   - Autore mancante? Aggiungi
   - Posizione cambiata? Aggiorna
4. **Salva** e fatto!

> ⚠️ **Nota**: Se il libro è in prestito, alcuni campi sono bloccati per sicurezza.

> 📚 **Vuoi i dettagli?** Leggi la [Guida Modifica Libro](/docs/modifica_libro.MD) con 8 passi completi, 5 casi reali e tutte le soluzioni ai problemi comuni.

---

### 🏷️ Stampare le Etichette

Le etichette servono per **trovare i libri sugli scaffali**.

#### **Procedura**
1. Clicca sul **titolo del libro** nella tabella
2. Clicca **"Stampa Etichetta"**
3. **PDF** pronto! Contiene:
   - Nome della biblioteca
   - Titolo abbreviato
   - Autore
   - Posizione (es. A.2.15)
   - Codice a barre

#### **Formati Disponibili**
- **25×38mm**: Standard per dorso libri
- **50×25mm**: Orizzontale
- **70×36mm**: Grande

> 📚 **Vuoi i dettagli?** Leggi la [Guida Stampa Etichette](/docs/stampa_etichette.MD) con come configurare il formato, dove comprare la carta, istruzioni di stampa e come applicarle.

---

### 👁️ Vedere i Dettagli di un Libro

1. **Clicca** sul titolo del libro
2. **Vedrai**:
   - Copertina grande
   - Tutte le informazioni
   - Storico prestiti
   - Bottoni per modificare

> 📚 **Vuoi i dettagli?** Leggi la [Guida Scheda Libro](/docs/scheda_libro.MD) che spiega le 10 sezioni della scheda, i bottoni disponibili e come navigare tra modifica, stampa e visualizzazione.

---

### 📤 Esportare la Lista

1. **Applica** i filtri (se vuoi)
2. **Clicca** i bottoni in alto:
   - **CSV**: Per Excel
   - **PDF**: Per stampare
   - **STAMPA**: Diretta

---

### 🗑️ Eliminare un Libro

> ⚠️ **Attenzione**: L'eliminazione è **permanente**

1. Trova il libro
2. Clicca 🗑️
3. **Conferma** nel popup

> **Non puoi eliminare** se:
> - Il libro è in prestito
> - Ha prestiti non restituiti

---

### ❓ Domande Frequenti

**Q: Perché alcuni libri non hanno la copertina?**
- Puoi aggiungerla manualmente
- Alcune copertine non sono online

**Q: Come so se un libro è disponibile?**
- Guarda il pallino: 🟢=libero, 🔴=prestato

**Q: Posso modificare più libri insieme?**
- No, uno alla volta per sicurezza

---

## 🔧 PARTE 2: DOCUMENTAZIONE TECNICA PER SVILUPPATORI

### Architettura del Sistema

#### Stack Tecnologico
- **Backend**: PHP 8.1+ (Slim 4 Framework)
- **Database**: MySQL 8.0+ con InnoDB
- **Frontend**: Vanilla JavaScript + DataTables
- **PDF**: TCPDF per etichette
- **API**: RESTful endpoints

#### Pattern Architetturali
- **MVC**: Model-View-Controller
- **Repository Pattern**: Isolamento logica database
- **Service Layer**: Business logic
- **CSRF Protection**: Token validation
- **Prepared Statements**: SQL Injection protection

---

### Struttura File

```
app/
├── Controllers/
│   ├── LibriController.php      # CRUD operations
│   ├── LibriApiController.php   # DataTables API
│   └── ScrapeController.php     # ISBN scraping
├── Models/
│   ├── BookRepository.php       # Database operations
│   ├── AuthorRepository.php     # Author management
│   └── CollocationRepository.php # Position system
├── Views/
│   └── libri/
│       ├── index.php            # DataTables view
│       ├── crea_libro.php       # Create form
│       ├── modifica_libro.php   # Edit form
│       └── scheda_libro.php     # Details view
```

---

### Database Schema

#### Tabella `libri`
```sql
CREATE TABLE libri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titolo VARCHAR(255) NOT NULL,
    sottotitolo VARCHAR(500),
    isbn10 VARCHAR(10),
    isbn13 VARCHAR(13),
    ean VARCHAR(13),
    editore_id INT NULL,
    data_pubblicazione VARCHAR(100),
    numero_pagine INT,
    formato VARCHAR(50),
    lingua VARCHAR(50),
    peso DECIMAL(6,3),
    dimensioni VARCHAR(50),
    prezzo DECIMAL(8,2),
    data_acquisizione DATE,
    tipo_acquisizione VARCHAR(100),
    numero_inventario VARCHAR(100),
    collana VARCHAR(200),
    numero_serie INT,
    categoria_id INT NULL,
    genere_id INT NULL,
    sottogenere_id INT NULL,
    classificazione_dowey VARCHAR(50),
    descrizione TEXT,
    parole_chiave TEXT,
    note_varie TEXT,
    copie_totali INT DEFAULT 1,
    copie_disponibili INT DEFAULT 1,
    stato ENUM('Disponibile','Prestato','Riservato','Danneggiato','Perso','In_Riparazione','Non_Disponibile') DEFAULT 'Disponibile',
    scaffale_id INT NULL,
    mensola_id INT NULL,
    posizione_progressiva INT NULL,
    collocazione VARCHAR(50),
    copertina_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (editore_id) REFERENCES editori(id) ON DELETE SET NULL,
    FOREIGN KEY (scaffale_id) REFERENCES scaffali(id) ON DELETE SET NULL,
    FOREIGN KEY (mensola_id) REFERENCES mensole(id) ON DELETE SET NULL,
    INDEX idx_isbn13 (isbn13),
    INDEX idx_isbn10 (isbn10),
    INDEX idx_titolo (titolo),
    INDEX idx_stato (stato),
    INDEX idx_collocazione (scaffale_id, mensola_id, posizione_progressiva)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Tabella `libri_autori` (Relazione Many-to-Many)
```sql
CREATE TABLE libri_autori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    autore_id INT NOT NULL,
    ordine INT DEFAULT 0,
    FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE,
    FOREIGN KEY (autore_id) REFERENCES autori(id) ON DELETE CASCADE,
    UNIQUE KEY unique_libro_autore (libro_id, autore_id),
    INDEX idx_libro (libro_id),
    INDEX idx_autore (autore_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### API Endpoints

#### GET `/api/libri`
Server-side processing per DataTables con filtri avanzati.

**Parametri Query**:
```javascript
{
  draw: number,           // DataTables counter
  start: number,          // Pagination offset
  length: number,         // Records per page
  search: { value: string }, // Global search
  order: [{column: number, dir: 'asc'|'desc'}],
  
  // Custom filters
  search_text: string,
  search_isbn: string,
  stato_filter: string,
  autore_id: number,
  editore_filter: number,
  genere_filter: number,
  posizione_id: number,
  date_range: object
}
```

**Response**:
```json
{
  "draw": 1,
  "recordsTotal": 1500,
  "recordsFiltered": 45,
  "data": [
    {
      "id": 123,
      "titolo": "La Divina Commedia",
      "autori": [{"id": 1, "nome": "Dante Alighieri"}],
      "editore_nome": "Mondadori",
      "stato": "Disponibile",
      "collocazione": "A.2.15",
      "copertina_url": "/uploads/copertine/123.jpg"
    }
  ]
}
```

#### GET `/api/scrape/isbn`
Scraping informazioni libro da fonti esterne.

**Parametri**: `isbn` (string)
**Response**: JSON con dati libro o errore 404

---

### Sicurezza e Performance

#### Protezioni Implementate
- **SQL Injection**: Prepared statements
- **XSS**: `htmlspecialchars()` su tutti gli output
- **CSRF**: Token validation
- **File Upload**: MIME type validation
- **SSRF**: Domain whitelist per scraping

#### Performance Optimization
```sql
-- Indici critici per performance
CREATE INDEX idx_composite_search ON libri(stato, editore_id, genere_id);
CREATE INDEX idx_isbn_search ON libri(isbn13, isbn10, ean);
CREATE INDEX idx_title_fulltext ON libri(titolo) USING FULLTEXT;
```

#### Caching Strategy
- **APCu caching** per liste statiche
- **Database query cache** per ricerche frequenti
- **Image optimization** per copertine

---

### Testing e Debugging

#### PHPUnit Tests
```php
class BookRepositoryTest extends TestCase
{
    public function testCreateBook(): void
    {
        $data = [
            'titolo' => 'Test Book',
            'isbn13' => '9781234567890',
            'copie_totali' => 3,
            'copie_disponibili' => 3
        ];
        
        $bookId = $repo->create($data);
        $this->assertGreaterThan(0, $bookId);
    }
}
```

#### Debug Console
```bash
# Verifica API
curl -X GET "http://localhost:8000/api/libri?length=10"

# Verifica scraping
curl -X GET "http://localhost:8000/api/scrape/isbn?isbn=9788842935780"

# Log errors
tail -f storage/logs/app.log
```

---

## 📚 INDICE COMPLETO DELLE GUIDE

### Guide Dettagliate per Ogni Operazione

| **Operazione** | **Guida Completa** | **Tempo di Lettura** |
|----------------|-------------------|---------------------|
| ➕ Aggiungere un libro | [Guida Inserimento Libri](/docs/inserimento_libri.MD) | 15 min |
| ✏️ Modificare un libro | [Guida Modifica Libro](/docs/modifica_libro.MD) | 10 min |
| 👁️ Visualizzare i dettagli | [Guida Scheda Libro](/docs/scheda_libro.MD) | 8 min |
| 🏷️ Stampare etichette | [Guida Stampa Etichette](/docs/stampa_etichette.MD) | 10 min |
| 📍 Organizzare scaffali | [Guida Collocazione](/docs/collocazione.MD) | 12 min |
| 📕 Gestire prestiti | [Guida Prestiti](/docs/prestiti.MD) | 10 min |

### Argomenti Approfonditi

- **Scraping Automatico**: Come il sistema recupera automaticamente i dati da ISBN/EAN → vedi [Guida Inserimento Libri - Sezione Scraping](/docs/inserimento_libri.MD#-come-funziona-lo-scraping-automatico)

- **Classificazione Dewey**: Come classificare i libri per argomento → vedi [Guida Inserimento Libri - Sezione Dewey](/docs/inserimento_libri.MD#-classificazione-dewey-dewey-decimal-classification)

- **Impostazioni Etichette**: Come configurare formati e tipo di carta → vedi [Guida Stampa Etichette - Sezione Configurazione](/docs/stampa_etichette.MD#-configurare-il-formato-etichette)

---

## 🎯 Quick Start per Utente Finale

**Vuoi aggiungere subito un libro?**
1. Vai a **Dashboard → Libri**
2. Clicca **"+ Nuovo Libro"**
3. Digita l'ISBN e clicca "Importa"
4. Assegna la posizione fisica
5. Clicca "Salva"
6. ✅ Fatto! → Stampa etichetta e applica

> 💡 Se hai dubbi, consulta una delle guide correlate qui sopra!

---

*Ultimo aggiornamento: 19 Ottobre 2025*
*Versione documentazione: 4.0.0*
*Versione guide correlate: 3.0.0* 🎉
