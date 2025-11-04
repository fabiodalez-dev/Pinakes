# 🏠 Home Page - La Pagina Iniziale della Biblioteca

> **Accedi qui**: http://localhost:8000/

Questo è il primo impatto che i visitatori hanno con la tua biblioteca. È una pagina completamente personalizzabile dai settings e carica dinamicamente i contenuti più recenti.

---

## 📋 Sezioni della Home Page

### 1. **Hero Section (Sezione Principale)**

**A cosa serve**: Cattura l'attenzione con un'immagine di background, titolo accattivante e la barra di ricerca principale.

**Cosa contiene**:
- **Titolo**: "La Tua Biblioteca Digitale" (modificabile da **Settings → CMS**)
- **Sottotitolo**: Descrizione breve della biblioteca (modificabile da **Settings → CMS**)
- **Immagine di sfondo**: Personalizzabile dai settings
- **Barra di ricerca**: Campo per cercare subito libri
- **Quick links**: "Ultimi Arrivi" e "Sfoglia Catalogo"

**4 Statistiche Live** (caricano automaticamente):
- 📚 Libri Totali
- ✓ Libri Disponibili
- 🎭 Categorie
- 24/7 Sempre Online

✨ **Tip**: Le statistiche si caricano via JavaScript, quindi vedrai uno spinner finché non sono pronte.

---

### 2. **Features Section (Perché Scegliere)**

**A cosa serve**: Mostrare i vantaggi principali della biblioteca.

**Cosa contiene**: 4 card con:
- **Icona** (emoji)
- **Titolo della feature**
- **Descrizione breve**

Tutti questi contenuti sono **completamente personalizzabili** da:
→ **Dashboard → Impostazioni → Tab "CMS"**

**Esempi di feature predefinite**:
- "Catalogo Completo"
- "Ricerca Avanzata"
- "Prestiti Veloci"
- "Disponibile 24/7"

---

### 3. **Latest Books Section (Ultimi Libri Aggiunti)**

**A cosa serve**: Mostrare i 10 libri più recenti della biblioteca.

**Come funziona**:
1. Il server carica i 10 ultimi libri dal database
2. Li mostra in una **griglia responsive** (card con copertina, titolo, autore)
3. **"Carica Altri"** permette di sfogliare altri libri
4. **"Visualizza Tutto il Catalogo"** porta a `/catalogo`

**Responsive**:
- 📱 Mobile: 1 colonna
- 💻 Tablet: 2-3 colonne
- 🖥️ Desktop: 4 colonne

---

### 4. **Categories Section (Esplora per Categoria)**

**A cosa serve**: Permettere agli utenti di scoprire libri per categoria.

**Come funziona**:
1. Il server carica le **categorie principali con sottocategorie**
2. Per ogni categoria, mostra fino a **5 libri** degli ultimi aggiunti
3. Ogni categoria è un'**area collassabile** con i relativi libri

**Navigazione**:
- Cliccare su una categoria → vai a `/catalogo?categoria=Nome`
- Ogni libro → vai alla sua pagina dettagli

---

### 5. **Call to Action Section (Chiusura Forte)**

**A cosa serve**: Spingere l'utente all'azione finale.

**Contiene**:
- Titolo CMS personalizzabile: "Inizia la Tua Avventura Letteraria"
- Sottotitolo personalizzabile
- 2 bottoni:
  - "Esplora il Catalogo" (personalizzabile con link e testo)
  - "Contattaci"

**Background**: Gradiente colorato con pattern decorativo.

---

## 🔍 Come Funziona la Ricerca

### Ricerca Hero Section (In cima)

**Cosa fa**:
```
Inserisci query → Premi "Cerca" → Vai a /catalogo?q=tuaricerca
```

**Ricerca per**:
- Titolo del libro
- Autore
- ISBN (se conosci il codice)
- Editore
- Qualsiasi parola nel titolo/autore

**Esempio**:
```
Cerco "Dante" →
Mostra tutti i libri:
  - Con "Dante" nel titolo
  - Scritti da Dante Alighieri
  - Di editori che contengono "Dante"
```

---

## ⚙️ Come Personalizzare la Home

### Modificare Titoli, Testi, Immagini

**Vai a**: Dashboard → Impostazioni → Tab "**CMS**"

**Modifica**:
| Sezione | Campo | Esempio |
|---------|-------|---------|
| **Hero** | Titolo | "La Tua Biblioteca Digitale" |
| **Hero** | Sottotitolo | "Scopri, prenota e gestisci i tuoi libri" |
| **Hero** | Immagine di sfondo | Foto della biblioteca o libri |
| **Features** | 4 titoli | "Ricerca Avanzata", "Catalogo Completo", ecc. |
| **Features** | 4 descrizioni | Descrizione breve di ogni feature |
| **Latest Books** | Titolo sezione | "Ultimi Libri Aggiunti" |
| **Latest Books** | Sottotitolo | "Scopri le ultime novità" |
| **CTA** | Titolo | "Inizia la Tua Avventura Letteraria" |
| **CTA** | Bottone testo | "Esplora il Catalogo" |
| **CTA** | Bottone link | `/catalogo` o link personalizzato |

**Procedimento**:
1. Apri Impostazioni → CMS
2. Modifica il testo come in un editor Word
3. Aggiungi immagini trascinandole
4. **Salva**
5. Ricarica la home → Vedrai subito i cambiamenti!

---

## 📊 Statistiche Live

Le 4 statistiche in cima si caricano automaticamente via JavaScript quando la pagina si apre.

**Come funzionano**:
```javascript
1. Pagina carica
2. JavaScript chiama /api/catalogo
3. Riceve: numero totale libri, disponibili, categorie
4. Mostra i numeri con animazione
```

**Se non compaiono**:
- ⏳ Aspetta 2-3 secondi (potrebbero essere lente)
- 🔄 Ricarica la pagina (F5 o CMD+R)
- ⚠️ Se continuano a non apparire, controlla la console del browser (F12 → Console)

---

## 🎨 Mobile vs Desktop

### Come Cambia la Home su Mobile

| Elemento | Desktop | Mobile |
|----------|---------|--------|
| **Hero Section** | Piena altezza con ricerca accanto | Compatta, ricerca sotto |
| **Statistiche** | 4 in riga | 2×2 oppure scorrevoli |
| **Book Grid** | 4 colonne | 1-2 colonne |
| **Categorie** | Espanso in griglia | Accordion collassato |
| **Bottoni CTA** | Fianco a fianco | Uno sotto l'altro |

✅ **La home è completamente responsive** - funziona benissimo su qualunque dispositivo.

---

## 🔗 Link e Navigazione

**Dalle sezioni della home puoi andare a**:

| Da | Clicca su | Vai a |
|----|-----------|-------|
| **Hero** | "Ultimi Arrivi" | Scroll a #latest-books |
| **Hero** | "Sfoglia Catalogo" | /catalogo |
| **Hero** | Barra di ricerca | /catalogo?q=tuaricerca |
| **Latest Books** | Libro | /libro/61/titolo-del-libro |
| **Latest Books** | "Visualizza Tutto" | /catalogo |
| **Categorie** | Categoria | /catalogo?categoria=Nome |
| **Categorie** | Libro | /libro/{id}/titolo |
| **CTA** | "Esplora Catalogo" | Personalizzabile da CMS |
| **CTA** | "Contattaci" | #contact (se esiste sezione) |

---

## ❓ Domande Frequenti

### **D: Come aggiungo nuovi libri che compaiano nella sezione "Ultimi Libri"?**

✅ Vai a **Dashboard → Libri → Aggiungi libro**. I nuovi libri compariranno automaticamente nella sezione "Ultimi Libri" in cima alla home.

### **D: Posso nascondere alcune sezioni della home?**

✅ Non da un'interfaccia, ma il tuo admin IT può modificare il file `home.php` per commentare le sezioni che non vuoi mostrare.

### **D: Come cambio l'immagine di background della hero?**

✅ **Impostazioni → CMS → sezione "Hero"** → Carica una nuova immagine trascinandola nel campo.

### **D: La ricerca trova anche i libri non disponibili?**

✅ **Sì**, la ricerca della home mostra TUTTI i libri (disponibili e prestati). Per filtrare solo disponibili, vai a **/catalogo** e usa il filtro "Disponibilità".

### **D: Perché le statistiche a volte non caricano?**

⚠️ Possibili cause:
- La pagina sta ancora caricando i dati (aspetta)
- JavaScript disabilitato nel browser
- Problemi di rete (ricarica)
- Il database è offline (contatta admin)

### **D: Posso cambiare i colori della home?**

❌ No da interfaccia utente. È necessario che un sviluppatore modifichi il CSS nel file `home.php`.

---

## 🎬 Workflow Tipico Utente sulla Home

```
1. Utente accede a http://localhost:8000/
   ↓
2. Vede hero section con barra di ricerca
   ↓
3. OPZIONE A: Cerca qualcosa
   ↓ → Va a /catalogo con la ricerca

   OPZIONE B: Scorri verso il basso
   ↓ → Vede ultimi libri
   ↓ → Clicca un libro
   ↓ → Va alla pagina dettagli del libro

   OPZIONE C: Clicca categoria
   ↓ → Va a /catalogo filtrato per categoria

   OPZIONE D: Clicca "Esplora Catalogo"
   ↓ → Va a /catalogo con filtri completi
```

---

## 📱 Esperienza Mobile Ottimale

✅ Tutto è ottimizzato per mobile:
- Testo leggibile senza zoom
- Bottoni abbastanza grandi per toccare
- Immagini responsive
- Caricamento rapido anche su 4G

---

## 🔐 Note Tecniche

**API Utilizzate**:
- `/api/catalogo` - Per le statistiche (numero totali, disponibili)
- `/api/home/latest?page=1` - Per i libri ultimi con paginazione

**Storage**: Nessun cookie o login necessario per la home - è completamente pubblica.

**Performance**: Le immagini delle copertine sono ottimizzate e cachate dal browser.

---

## 📚 Prossimi Passi

- ➡️ **Vuoi cercare libri specifici?** [Vai a Catalogo](./catalogo.md)
- ➡️ **Vuoi vedere i dettagli di un libro?** [Vai a Scheda Libro](./scheda_libro.md)
- ➡️ **Vuoi personalizzare i contenuti?** Leggi [Impostazioni CMS](../settings.md#3--contenuti-del-sito---cosa-dici-pubblicamente)

---

*Ultima lettura: 19 Ottobre 2025*
*Tempo lettura: 8 minuti*
