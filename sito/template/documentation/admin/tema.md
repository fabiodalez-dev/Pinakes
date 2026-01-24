# Personalizzazione Tema

Pinakes include un sistema di temi che permette di personalizzare l'aspetto dell'applicazione.

## Temi Predefiniti

L'applicazione include 10 temi pronti all'uso:

| Tema | Descrizione |
|------|-------------|
| **Pinakes Classic** | Tema predefinito con tonalità magenta |
| **Minimal** | Design pulito e minimalista |
| **Ocean Blue** | Tonalità blu professionali |
| **Forest Green** | Tonalità verdi naturali |
| **Sunset Orange** | Tonalità arancioni calde |
| **Burgundy** | Rosso bordeaux elegante |
| **Teal Professional** | Verde acqua professionale |
| **Slate Gray** | Grigio ardesia sobrio |
| **Coral Warm** | Corallo caldo e accogliente |
| **Navy Classic** | Blu navy classico |

## Gestione Temi

### Accedere ai Temi

1. Vai in **Impostazioni → Temi**
2. Visualizzi la griglia con tutti i temi disponibili

### Attivare un Tema

1. Individua il tema desiderato
2. Clicca **Attiva**
3. Il cambio è immediato per tutti gli utenti

Il tema attivo mostra un badge verde "Attivo".

### Informazioni Mostrate

Per ogni tema la card mostra:
- **Nome** e **versione**
- **Autore**
- **Descrizione**
- **Palette colori** (anteprima dei 4 colori)

## Editor Colori

Ogni tema può essere personalizzato modificando 4 colori.

### Accedere all'Editor

1. Nella griglia temi, clicca **Personalizza** sul tema
2. Si apre la pagina di personalizzazione

### I 4 Colori Configurabili

| Colore | Uso | Default |
|--------|-----|---------|
| **Primario** | Link, accenti, elementi interattivi | `#d70161` |
| **Secondario** | Bottoni principali (es. "Richiedi Prestito") | `#111827` |
| **Bottoni CTA** | Bottoni nelle card (es. "Dettagli") | `#d70262` |
| **Testo Bottoni** | Colore del testo nei bottoni CTA | `#ffffff` |

### Anteprima in Tempo Reale

Mentre modifichi i colori, l'anteprima sulla destra mostra:
- Un link di esempio con il colore primario
- Il bottone CTA con sfondo e testo
- Il bottone primario con il colore secondario

### Selezione Automatica Testo

Il pulsante "bacchetta magica" accanto al colore testo bottoni calcola automaticamente se usare bianco o nero in base alla luminosità dello sfondo.

## Verifica Accessibilità (WCAG)

L'editor include un controllo automatico del contrasto tra sfondo e testo dei bottoni.

### Livelli di Conformità

| Stato | Rapporto | Significato |
|-------|----------|-------------|
| **Verde** | ≥ 4.5:1 | WCAG AA conforme (testo normale) |
| **Giallo** | ≥ 3.0:1 | AA solo per testo grande |
| **Rosso** | < 3.0:1 | Insufficiente, difficile da leggere |

Il sistema consiglia di mantenere almeno un rapporto 4.5:1 per garantire la leggibilità.

## CSS Personalizzato

Per modifiche avanzate oltre ai colori:

### Aggiungere CSS

1. Nella sezione **Avanzate** trovi il campo CSS personalizzato
2. Inserisci le tue regole CSS
3. Salva

### Esempi Comuni

#### Nascondere un elemento
```css
.element-da-nascondere {
  display: none !important;
}
```

#### Cambiare font
```css
body {
  font-family: 'Georgia', serif !important;
}
```

#### Personalizzare header
```css
header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
```

#### Arrotondare angoli card
```css
.card {
  border-radius: 16px;
}
```

### Best Practice

- Usa `!important` solo quando necessario
- Testa su mobile e desktop
- Evita di sovrascrivere troppe regole base
- Documenta le modifiche con commenti CSS

## Ripristino Colori

Per tornare ai colori originali del tema:

1. Clicca **Ripristina** nella pagina di personalizzazione
2. Conferma l'operazione
3. Tutti i colori tornano ai valori predefiniti del tema

> **Nota**: Il ripristino non elimina il CSS personalizzato.

## Risoluzione Problemi

### I colori non vengono applicati

1. Svuota la cache del browser (Ctrl+Shift+R o Cmd+Shift+R)
2. Verifica che il CSS personalizzato non sovrascriva i colori
3. Controlla che il tema sia effettivamente attivo

### Il contrasto è insufficiente

1. Usa il selettore automatico del testo (bacchetta magica)
2. Scegli colori con maggiore differenza di luminosità
3. Evita combinazioni come giallo su bianco o blu scuro su nero

### Il CSS personalizzato non funziona

1. Verifica la sintassi CSS (parentesi, punti e virgola)
2. Usa la console del browser (F12) per vedere errori
3. Assicurati che i selettori siano corretti

---

## Domande Frequenti (FAQ)

### 1. Come cambio il tema della mia biblioteca?

Cambiare tema è immediato:

1. Vai in **Impostazioni → Temi**
2. Visualizzi la griglia con i 10 temi disponibili
3. Clicca **Attiva** sul tema desiderato
4. Il cambio è immediato per tutti gli utenti

**Temi disponibili:**
Pinakes Classic, Minimal, Ocean Blue, Forest Green, Sunset Orange, Burgundy, Teal Professional, Slate Gray, Coral Warm, Navy Classic.

---

### 2. Posso creare un tema completamente personalizzato?

Attualmente non c'è un sistema per creare nuovi temi da interfaccia. Opzioni:

**Opzione 1 - Personalizza tema esistente:**
1. Attiva un tema base (es. Minimal)
2. Vai in **Personalizza**
3. Modifica i 4 colori
4. Aggiungi CSS personalizzato nella sezione Avanzate

**Opzione 2 - CSS completo:**
Nel campo CSS personalizzato puoi sovrascrivere qualsiasi stile:
```css
:root {
  --color-primary: #your-color;
  --color-background: #ffffff;
}
```

---

### 3. Come funziona il controllo accessibilità WCAG?

L'editor colori verifica automaticamente il contrasto tra sfondo bottoni e testo:

| Rapporto | Stato | Significato |
|----------|-------|-------------|
| ≥ 4.5:1 | 🟢 Verde | WCAG AA conforme |
| 3.0-4.5:1 | 🟡 Giallo | Solo testo grande |
| < 3.0:1 | 🔴 Rosso | Non conforme |

**Calcolo:**
- Basato sulla formula WCAG per luminanza relativa
- Considera il colore CTA e il colore testo bottoni

**Consiglio:** Mantieni sempre ≥ 4.5:1 per leggibilità universale.

---

### 4. Il pulsante "bacchetta magica" cosa fa esattamente?

Il pulsante calcola automaticamente se usare testo bianco o nero:

**Logica:**
1. Calcola la luminanza del colore sfondo bottone
2. Se luminanza > 0.5 → testo nero (#000000)
3. Se luminanza ≤ 0.5 → testo bianco (#ffffff)

**Quando usarlo:**
- Dopo aver scelto un nuovo colore CTA
- Se il controllo WCAG mostra giallo/rosso
- Per garantire leggibilità automatica

---

### 5. Come aggiungo un font personalizzato?

Nel campo CSS personalizzato:

**Google Fonts:**
```css
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap');

body {
  font-family: 'Roboto', sans-serif !important;
}
```

**Font locale:**
```css
@font-face {
  font-family: 'MioFont';
  src: url('/assets/fonts/miofont.woff2') format('woff2');
}

body {
  font-family: 'MioFont', sans-serif !important;
}
```

Carica il file font in `public/assets/fonts/`.

---

### 6. Perché le mie modifiche CSS non si applicano?

**Cause comuni:**

| Problema | Soluzione |
|----------|-----------|
| Cache browser | Ctrl+Shift+R (hard refresh) |
| Specificità insufficiente | Aggiungi `!important` |
| Selettore errato | Ispeziona elemento (F12) per trovare classe corretta |
| Sintassi errata | Verifica parentesi e punti e virgola |

**Debug:**
1. Apri console browser (F12)
2. Tab "Console" per errori CSS
3. Tab "Elements" per ispezionare stili applicati

---

### 7. Come ripristino i colori originali del tema?

1. Vai in **Impostazioni → Temi → Personalizza** (sul tema attivo)
2. Clicca **Ripristina**
3. Conferma l'operazione

**Cosa viene ripristinato:**
- I 4 colori tornano ai valori originali del tema
- **NON viene cancellato** il CSS personalizzato

Per cancellare anche il CSS, svuota manualmente il campo.

---

### 8. I colori cambiano solo per me o per tutti gli utenti?

Le modifiche ai temi sono **globali per tutti gli utenti**.

**Comportamento:**
- Attivare un tema → immediato per tutti
- Modificare i colori → immediato per tutti
- CSS personalizzato → immediato per tutti

**Non c'è** un sistema di tema per utente. Tutti vedono lo stesso aspetto.

---

### 9. Come nascondo elementi dell'interfaccia che non uso?

Nel CSS personalizzato, usa `display: none`:

**Esempi comuni:**
```css
/* Nascondi sezione eventi */
.events-section {
  display: none !important;
}

/* Nascondi pulsante wishlist */
.btn-wishlist {
  display: none !important;
}

/* Nascondi footer */
footer {
  display: none !important;
}
```

**Trova il selettore:**
1. F12 → Ispeziona elemento
2. Copia la classe dell'elemento
3. Aggiungi al CSS

---

### 10. Posso avere temi diversi per desktop e mobile?

Non nativamente, ma puoi usare media queries nel CSS personalizzato:

```css
/* Desktop */
@media (min-width: 1024px) {
  header {
    background: linear-gradient(to right, #667eea, #764ba2);
  }
}

/* Mobile */
@media (max-width: 1023px) {
  header {
    background: #667eea;
  }
}
```

**Attenzione:** I 4 colori principali non supportano media queries dall'editor visuale, solo dal CSS personalizzato.
