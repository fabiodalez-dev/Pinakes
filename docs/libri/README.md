# 📚 Gestione Libri - Guida Completa

Benvenuto nella sezione dedicata alla gestione dei libri di Pinakes, il sistema gestionale progettato per piccole e medie biblioteche.

## Panoramica

Questa sezione contiene tutte le guide necessarie per gestire il catalogo della tua biblioteca: dall'inserimento di nuovi libri, alla modifica di quelli esistenti, fino allo scraping automatico dei metadati.

## 📖 Guide Disponibili

### [→ Inserimento Libri](./inserimento.md)
Guida completa per aggiungere nuovi libri al catalogo della biblioteca.

**Cosa imparerai:**
- Come inserire libri manualmente
- Come usare l'importazione automatica da ISBN/EAN
- Come gestire autori ed editori
- Come assegnare la collocazione fisica
- Tempi: inserimento automatico 2 minuti, manuale 10-15 minuti

### [→ Scraping Automatico](./scraping.md)
Tutto sul sistema di scraping che recupera automaticamente i dati dei libri da fonti online.

**Cosa imparerai:**
- Cos'è lo scraping e come funziona
- Quali fonti vengono utilizzate (Google Books, Open Library, SBN)
- Come gestire i dati recuperati
- Cosa fare quando lo scraping fallisce
- Come arricchire i metadati esistenti

### [→ Modifica Libri](./modifica.md)
Come aggiornare e correggere le informazioni dei libri già inseriti nel catalogo.

**Cosa imparerai:**
- Come modificare titolo, autori, descrizione
- Come aggiornare la posizione fisica
- Come gestire copie multiple
- Come cambiare la copertina
- Come gestire le modifiche con prestiti attivi

### [→ Gestione Copie](./gestione-copie.md)
Guida specifica per gestire più copie dello stesso libro.

**Cosa imparerai:**
- Come aggiungere copie di un libro esistente
- Come tracciare lo stato di ogni copia
- Come gestire i prestiti con copie multiple
- Come rimuovere copie

### [→ Stampa Etichette](./stampa-etichette.md)
Come stampare etichette con codici a barre per identificare fisicamente i libri.

**Cosa imparerai:**
- Formati di etichette disponibili
- Come configurare la stampante
- Come applicare le etichette sui libri
- Dove acquistare carta per etichette

## 🎯 Quick Start

**Vuoi aggiungere subito un libro?**

1. Vai a **Dashboard → Libri → + Nuovo Libro**
2. Inserisci l'**ISBN** del libro (trovalo sul retro)
3. Clicca **"Importa Dati"**
4. Verifica le informazioni importate
5. Assegna **scaffale e mensola**
6. Clicca **"Salva"**
7. ✅ Fatto! Stampa l'etichetta e applicala

**Tempo totale:** ~2 minuti per libro

## 💡 Concetti Chiave

### ISBN/EAN
Codici univoci che identificano ogni libro. L'ISBN può essere di 10 o 13 cifre. Il sistema Pinakes li usa per recuperare automaticamente tutti i dati del libro.

### Scraping
Processo automatico che cerca il libro online e scarica titolo, autore, copertina, descrizione e altri metadati senza doverli digitare manualmente.

### Collocazione
"Indirizzo" fisico del libro nella biblioteca, formato da scaffale, mensola e posizione (es: A.2.15 = Scaffale A, Mensola 2, Posizione 15).

### Copie Multiple
Stesso libro con più esemplari fisici. Il sistema gestisce le copie come un unico record con contatore (es: 3 copie totali, 2 disponibili).

## 🔗 Collegamenti Utili

- [→ Catalogazione Dewey](../catalogazione/dewey.md) - Per classificare i libri per argomento
- [→ Sistema di Collocazione](../catalogazione/collocazione.md) - Per organizzare gli scaffali
- [→ Prestiti](../prestiti/README.md) - Per gestire i prestiti dei libri
- [→ Frontend Catalogo](../frontend/catalogo.md) - Come gli utenti cercano e visualizzano i libri

## 📊 Statistiche e Numeri

Con Pinakes puoi gestire:
- ✅ Catalogo illimitato di libri
- ✅ Autori e editori con normalizzazione automatica
- ✅ Copie multiple per ogni libro
- ✅ Scraping da fonti multiple
- ✅ Copertine ad alta risoluzione
- ✅ Classificazione Dewey integrata
- ✅ Export CSV per inventari

## ❓ Domande Frequenti

**D: Devo sempre usare l'ISBN per inserire libri?**
R: No, ma è fortemente consigliato quando disponibile. L'ISBN permette l'importazione automatica che fa risparmiare 10-15 minuti per libro.

**D: Cosa faccio se un libro non ha ISBN?**
R: Usa l'inserimento manuale. È semplice, basta compilare un form con i dati del libro.

**D: Posso modificare un libro dopo averlo inserito?**
R: Sì, puoi modificare qualsiasi campo in qualsiasi momento dalla sezione Libri → Modifica.

**D: Il sistema funziona anche offline?**
R: L'inserimento manuale sì, lo scraping automatico no (richiede connessione internet per contattare le fonti online).

**D: Quanti libri posso inserire?**
R: Non ci sono limiti software. Il limite è solo lo spazio sul server/database.

## 🎓 Per Saperne di Più

Questa documentazione è pensata sia per utenti finali che per amministratori di biblioteca. Se sei uno sviluppatore e vuoi dettagli tecnici sull'implementazione, consulta la [documentazione per developer](../developer/README.md).

## 📝 Note sulla Versione

Questa documentazione è aggiornata per **Pinakes v0.4.1**.

Per le modifiche e le nuove funzionalità introdotte nelle versioni recenti, consulta il [CHANGELOG.md](../../CHANGELOG.md) nella root del progetto.

---

**Prossimo passo:** [Inizia con l'inserimento del primo libro →](./inserimento.md)
