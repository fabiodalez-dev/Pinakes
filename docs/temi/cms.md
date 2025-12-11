# 📝 Sistema CMS - Content Management System

## Cos'è il CMS in Pinakes?

Il **CMS** (Content Management System) di Pinakes è un sistema integrato che permette di modificare i contenuti delle pagine pubbliche del sito **senza toccare il codice**. Include un **editor WYSIWYG** (What You See Is What You Get) simile a Word per creare e modificare contenuti in modo visuale.

**Accessibile da:** Dashboard → Impostazioni → Tab "CMS"

---

## 🎯 Pagine Gestibili dal CMS

### 1. Homepage

La homepage è completamente personalizzabile tramite CMS con diverse sezioni indipendenti.

#### Hero Section (Sezione Principale)

**Cosa contiene:**
- Titolo principale (grande)
- Sottotitolo/descrizione
- Immagine di sfondo
- Barra di ricerca (integrata, non modificabile)
- Statistiche live (automatiche)

**Come modificare:**
1. Dashboard → Impostazioni → CMS → "Homepage"
2. Sezione "Hero"
3. **Titolo**: Scrivi il titolo principale (es: "Benvenuti nella Biblioteca Marconi")
4. **Sottotitolo**: Descrizione breve (es: "Scopri, prenota e leggi migliaia di libri")
5. **Immagine background**:
   - Clicca "Carica Immagine"
   - Seleziona foto (JPG/PNG, max 5MB)
   - Dimensioni consigliate: 1920×1080px (Full HD)
6. Salva

**Best practices:**
- Titolo: Max 50 caratteri (leggibilità)
- Sottotitolo: Max 150 caratteri
- Immagine: Usa foto luminose con buon contrasto
- Evita testo nell'immagine (coprirebbe il titolo sovrapposto)

#### Features Section (Vantaggi)

**Cosa contiene:**
Fino a 6 "card" con icona, titolo e descrizione dei vantaggi della biblioteca.

**Come modificare:**
1. CMS → Homepage → "Features"
2. Per ogni feature (1-6):
   - **Icona**: Scegli emoji o codice FontAwesome
   - **Titolo**: Nome feature (es: "Catalogo Completo")
   - **Descrizione**: Breve testo (es: "Oltre 10.000 libri catalogati")
3. Toggle per abilitare/disabilitare singole features
4. Salva

**Esempi predefiniti:**
- 📚 Catalogo Completo
- 🔍 Ricerca Avanzata
- 📕 Prestiti Facili
- 🌐 Disponibile 24/7
- 📱 Mobile Friendly
- 🎓 Per Tutti

#### Ultimi Libri Section

**Automatica** - Non modificabile dal CMS

Mostra automaticamente gli ultimi 10 libri aggiunti al catalogo. Puoi solo:
- Nascondere/mostrare l'intera sezione (toggle on/off)
- Modificare titolo sezione (es: "Novità" invece di "Ultimi Libri")

#### Categorie Section

**Automatica** - Non modificabile dal CMS

Mostra automaticamente le categorie con libri. Puoi solo:
- Nascondere/mostrare l'intera sezione
- Modificare titolo sezione

#### Call to Action (CTA)

**Cosa contiene:**
- Titolo grande
- Sottotitolo
- 2 bottoni con link personalizzabili

**Come modificare:**
1. CMS → Homepage → "Call to Action"
2. **Titolo**: Es. "Inizia la Tua Avventura Letteraria"
3. **Sottotitolo**: Es. "Unisciti alla nostra community di lettori"
4. **Bottone 1**:
   - Testo: Es. "Esplora il Catalogo"
   - Link: `/catalogo` (o URL custom)
   - Stile: Primario (blu) o Secondario (bianco)
5. **Bottone 2**:
   - Testo: Es. "Registrati Ora"
   - Link: `/register`
   - Stile: Primario o Secondario
6. Salva

---

### 2. Pagina "Chi Siamo"

Pagina dedicata alla presentazione della biblioteca.

**Accessibile da:** `/chi-siamo` (frontend) o Dashboard → CMS → "Chi Siamo"

**Cosa puoi inserire:**
- Storia della biblioteca
- Missione e valori
- Team (bibliotecari, staff)
- Foto della biblioteca
- Orari di apertura
- Contatti

**Come modificare:**
1. Dashboard → Impostazioni → CMS → "Chi Siamo"
2. Usa l'**editor WYSIWYG** (tipo Word):
   - Scrivi testo
   - Formatta (grassetto, corsivo, elenchi)
   - Inserisci immagini (drag & drop)
   - Aggiungi link
   - Crea tabelle (utile per orari)
3. Anteprima in tempo reale
4. Salva

**Template consigliato:**
```
# Chi Siamo

## Storia
[La vostra storia...]

## La Nostra Missione
[Obiettivi...]

## Il Team
[Foto e nomi staff...]

## Orari di Apertura
Lunedì-Venerdì: 9:00-18:00
Sabato: 9:00-13:00
Domenica: Chiuso

## Come Raggiungerci
[Indirizzo e mappa...]
```

---

### 3. Pagina "Contatti"

Pagina con form di contatto e informazioni.

**Accessibile da:** `/contatti` (frontend) o Dashboard → CMS → "Contatti"

**Cosa puoi inserire:**
- Email biblioteca
- Telefono
- Indirizzo fisico
- Orari
- Mappa Google (embed)
- Social media

**Come modificare:**
1. Dashboard → Impostazioni → CMS → "Contatti"
2. **Informazioni Base**:
   - Email: `info@biblioteca.it`
   - Telefono: `+39 012 3456789`
   - Indirizzo: `Via Roma 1, 00100 Roma`
3. **Form Contatto**:
   - Toggle abilita/disabilita form
   - Email destinatario (dove arrivano i messaggi)
4. **Mappa Google** (opzionale):
   - Vai su Google Maps
   - Cerca la tua biblioteca
   - Clicca "Condividi" → "Incorpora mappa"
   - Copia codice HTML
   - Incolla nel campo "Embed Map"
5. **Social Media**:
   - Facebook URL
   - Twitter URL
   - Instagram URL
   - YouTube URL
6. Salva

**Il form contatto invia email** all'indirizzo configurato in "Email destinatario". Assicurati che le email SMTP siano configurate.

---

### 4. Pagina "Privacy Policy"

Pagina con informativa privacy (obbligatoria per GDPR).

**Accessibile da:** `/privacy` (frontend) o Dashboard → CMS → "Privacy"

**Cosa contiene:**
- Informativa sul trattamento dati personali
- Cookie policy
- Diritti utenti (GDPR)
- Contatti DPO (Data Protection Officer)

**Come modificare:**
1. Dashboard → Impostazioni → CMS → "Privacy"
2. Usa il template predefinito (già GDPR-compliant)
3. **Personalizza** le sezioni con i tuoi dati:
   - Nome biblioteca
   - Indirizzo
   - Email contatto
   - Nome responsabile trattamento dati
4. Salva

**⚠️ Importante:** Non eliminare sezioni obbligatorie per legge. Se hai dubbi, consulta un legale o usa il template predefinito.

---

## 🛠️ Editor WYSIWYG

### Barra Strumenti

**Formattazione testo:**
- **B** - Grassetto
- *I* - Corsivo
- <u>U</u> - Sottolineato
- ~~S~~ - Barrato
- H1, H2, H3 - Titoli (heading)

**Liste:**
- Lista puntata
- Lista numerata
- Indenta/Riduci indentazione

**Inserimenti:**
- 🖼️ **Immagine**: Carica da PC o URL
- 🔗 **Link**: Aggiungi link interni o esterni
- 📊 **Tabella**: Crea tabelle (utile per orari)
- 📹 **Video**: Embed video YouTube/Vimeo
- 💻 **Codice**: Blocchi codice (per developer)

**Layout:**
- Allineamento (sinistra/centro/destra)
- Colori testo e sfondo
- Rimuovi formattazione
- Annulla/Ripristina

### Come Inserire Immagini

**Metodo 1: Upload**
1. Clicca icona 🖼️
2. "Carica Immagine"
3. Seleziona file (JPG/PNG, max 5MB)
4. Opzionale: Aggiungi didascalia
5. Opzionale: Aggiungi link
6. Inserisci

**Metodo 2: URL**
1. Clicca icona 🖼️
2. "Inserisci da URL"
3. Incolla URL immagine
4. Inserisci

**Metodo 3: Drag & Drop**
1. Trascina immagine direttamente nell'editor
2. Rilascia
3. ✅ Inserita automaticamente

**Best practices immagini:**
- Formato: JPG per foto, PNG per loghi/grafici
- Dimensioni: Max 1920px larghezza
- Peso: Max 2MB per foto (5MB limite assoluto)
- Alt text: Sempre compilare per accessibilità

### Come Inserire Link

**Link interno (alla tua biblioteca):**
1. Seleziona testo da linkare
2. Clicca icona 🔗
3. URL: `/catalogo` (esempio)
4. Testo: "Sfoglia il catalogo"
5. Apri in nuova scheda: No
6. Inserisci

**Link esterno:**
1. Seleziona testo
2. Clicca icona 🔗
3. URL: `https://www.example.com`
4. Apri in nuova scheda: Sì (raccomandato)
5. Inserisci

### Come Inserire Video

**Video YouTube:**
1. Vai su YouTube
2. Sotto il video clicca "Condividi" → "Incorpora"
3. Copia codice iframe
4. Nell'editor: clicca icona 📹
5. Incolla codice
6. Inserisci

**Video Vimeo:** Stessa procedura di YouTube

---

## 🎨 Sezioni Home Riorganizzabili

**Dalla v0.4.0** puoi riordinare le sezioni della homepage con **drag & drop**.

**Come fare:**
1. Dashboard → CMS → "Homepage" → "Organizza Sezioni"
2. Vedi lista sezioni con handle (⋮⋮)
3. **Trascina** le sezioni nell'ordine desiderato
4. **Toggle** per abilitare/disabilitare singole sezioni
5. **Anteprima** in tempo reale
6. Salva layout

**Sezioni disponibili:**
1. Hero Section (ricerca + statistiche)
2. Features (vantaggi biblioteca)
3. Ultimi Libri Aggiunti
4. Esplora per Categoria
5. Eventi Prossimi (se plugin Eventi attivo)
6. Call to Action (iscrizione/catalogo)

**Ordine predefinito:** 1→2→3→4→5→6

**Esempio ordine alternativo:**
- Hero Section
- Eventi Prossimi
- Ultimi Libri
- Features
- Categorie
- CTA

---

## 📱 Responsive e Mobile

Tutti i contenuti CMS sono **automaticamente responsive**:
- ✅ Testo si adatta alla larghezza schermo
- ✅ Immagini si ridimensionano
- ✅ Tabelle diventano scrollabili
- ✅ Video mantengono proporzioni

**Non serve fare nulla** - il sistema gestisce automaticamente.

---

## 🔒 Permessi

**Chi può modificare il CMS:**
- ✅ Admin (accesso completo)
- ✅ Librarian (accesso completo)
- ❌ Assistant (solo lettura)
- ❌ User (nessun accesso)

---

## 🆕 Novità v0.4.0

### Sezioni Riorganizzabili
- Drag & drop per ordinare sezioni homepage
- Toggle per abilitare/disabilitare sezioni
- Anteprima live

### Editor Migliorato
- Upload immagini più veloce
- Drag & drop immagini nell'editor
- Validazione dimensioni/formato
- Crop automatico immagini troppo grandi

### Nuove Sezioni
- Eventi prossimi (se plugin attivo)
- Testimonianze utenti (opzionale)

---

## ❓ Domande Frequenti

**D: Posso aggiungere pagine custom oltre a quelle predefinite?**
R: Sì, dalla v0.4.0. Dashboard → CMS → "Nuova Pagina".

**D: Le modifiche sono immediate?**
R: Sì, salvando il CMS le modifiche sono visibili immediatamente sul sito pubblico.

**D: Posso tornare alla versione precedente?**
R: Sì, il CMS mantiene uno storico delle ultime 10 versioni. Clicca "Cronologia" per ripristinare.

**D: Posso usare HTML personalizzato?**
R: Sì, c'è un bottone "Codice HTML" nell'editor per utenti avanzati. Attenzione: codice errato può rompere il layout.

**D: Come faccio a vedere come appare prima di salvare?**
R: Usa il pulsante "Anteprima" in alto a destra nell'editor.

**D: Posso copiare contenuti da Word?**
R: Sì, ma usa "Incolla come testo semplice" (Ctrl+Shift+V) per evitare formattazione problematica.

---

## 🔗 Collegamenti Utili

- [→ Temi e Personalizzazione](./README.md)
- [→ Eventi](./eventi.md)
- [→ Logo e Branding](./branding.md)
- [→ Impostazioni](../guida-admin/impostazioni.md)

---

**Ultimo aggiornamento:** Dicembre 2025
**Versione documentazione:** 1.0.0
**Compatibile con:** Pinakes v0.4.1+
