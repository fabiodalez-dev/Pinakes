---
name: Pinakes
description: Un sistema bibliotecario moderno in cui libri, informazioni e azioni restano protagonisti.
colors:
  primary: "#d70161"
  primary-deep: "#b8014f"
  action: "#d70262"
  ink: "#111827"
  text: "#0f172a"
  text-muted: "#6b7280"
  surface: "#fdfcfd"
  surface-subtle: "#f8f9fa"
  divider: "#e5e7eb"
  success: "#10b981"
  danger: "#ef4444"
  warning: "#f59e0b"
  info: "#3b82f6"
typography:
  display:
    fontFamily: "Fraunces, Georgia, serif"
    fontSize: "3.5rem"
    fontWeight: 500
    lineHeight: 1
    letterSpacing: "-0.035em"
  headline:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif"
    fontSize: "2rem"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "-0.015em"
  body:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "-0.008em"
  label:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "0.08em"
rounded:
  sm: "4px"
  md: "8px"
  lg: "12px"
  xl: "16px"
  pill: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
  section: "64px"
components:
  button-primary:
    backgroundColor: "{colors.action}"
    textColor: "{colors.surface}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "12px 20px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.primary-deep}"
    textColor: "{colors.surface}"
  button-secondary:
    backgroundColor: "{colors.surface-subtle}"
    textColor: "{colors.text}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "12px 20px"
    height: "44px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "10px 12px"
    height: "44px"
  status:
    backgroundColor: "{colors.surface-subtle}"
    textColor: "{colors.text}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "6px 10px"
---

# Design System: Pinakes

## Overview

**Creative North Star: "La Scrivania del Bibliotecario"**

Pinakes deve sembrare uno strumento curato che scompare mentre bibliotecari e lettori cercano, comprendono e agiscono. La composizione è ordinata, leggibile e accogliente, con densità sufficiente per metadati complessi ma senza trasformare ogni informazione in una card.

L'identità configurabile della biblioteca resta strutturale: il colore primario, il colore dei pulsanti, le immagini e i contenuti impostati dall'amministratore governano tutti i temi. Editoriale può usare Fraunces per i titoli espressivi; Workspace, Command e Soft mantengono Inter per una gerarchia più operativa. Sono proibiti mosaici di card, bordi attorno a ogni elemento, gradienti decorativi, ombre pesanti, controlli sovradimensionati, colori saturi senza gerarchia e interfacce da template amministrativo datato.

**Key Characteristics:**

- Gerarchia tipografica netta e testo importante sempre ad alto contrasto.
- Superfici piatte, spazio variato e divisori usati solo quando chiariscono una relazione.
- Un'unica struttura HTML compatibile con Editoriale, Workspace, Command e Soft.
- Azione primaria immediatamente riconoscibile, azioni secondarie calme ma non spente.
- Movimento breve, funzionale e disattivabile con `prefers-reduced-motion`.

## Colors

La palette usa neutri appena intonati e un solo accento configurabile, raro e intenzionale.

### Primary

- **Magenta Editoriale:** identifica azioni primarie, focus, selezione e piccoli accenti. Il valore predefinito è sostituito a runtime dal tema scelto dall'amministratore.
- **Magenta Profondo:** è riservato agli stati hover e active del colore primario.

### Neutral

- **Inchiostro:** testo forte, header Command e pulsanti che richiedono massima autorevolezza.
- **Testo:** corpo, titoli operativi e valori dei metadati.
- **Testo Attenuato:** solo informazioni secondarie di dimensione normale; mai testi piccoli o informazioni necessarie.
- **Carta:** superficie principale, leggermente tinta e mai bianco ottico.
- **Carta Secondaria:** pannelli funzionali e raggruppamenti senza bordo.
- **Divisore:** linee da un pixel, solo quando lo spazio non basta a spiegare la struttura.

### Named Rules

**The One Accent Rule.** Il colore primario occupa meno del 10% della schermata e comunica azione, selezione o stato, mai decorazione casuale.

**The Contrast Before Muting Rule.** Un testo secondario può essere più quieto, ma deve restare leggibile; sotto 14px non usare mai il grigio attenuato per informazioni essenziali.

## Typography

**Display Font:** Fraunces con fallback Georgia, riservato a Editoriale.
**Body Font:** Inter con fallback ai font di sistema.
**Label Font:** Inter.

**Character:** Inter porta precisione e velocità; Fraunces aggiunge una voce editoriale solo dove il contenuto lo giustifica. I controlli e i dati non usano mai un display font.

### Hierarchy

- **Display** (500, 3.5rem, 1): hero e titoli editoriali di primo livello.
- **Headline** (700, 2rem, 1.1): titoli pagina e titoli libro nei temi operativi.
- **Title** (700, 1.25rem, 1.25): sezioni e raggruppamenti principali.
- **Body** (400, 1rem, 1.6): testo e descrizioni, con lunghezza massima di 72 caratteri.
- **Label** (700, 0.75rem, 0.08em): etichette compatte; mai più di poche parole in maiuscolo.

### Named Rules

**The Information Hierarchy Rule.** Titolo, autore, disponibilità e azione primaria devono essere distinguibili in meno di tre secondi.

## Elevation

Il sistema è piatto per impostazione predefinita. La profondità nasce da superfici tonali e spazio; le ombre sono ambientali, leggere e limitate a header sospesi, copertine, menu e stati hover che devono apparire sopra il piano corrente.

### Shadow Vocabulary

- **Ambient Low** (`0 1px 3px rgb(0 0 0 / 0.10)`): menu e piccoli elementi sospesi.
- **Ambient Medium** (`0 12px 30px rgb(15 23 42 / 0.08)`): hover di una card cliccabile.
- **Book Lift** (`0 22px 52px rgb(15 23 42 / 0.14)`): esclusivamente copertine in evidenza.

### Named Rules

**The Flat By Default Rule.** Una superficie a riposo non riceve ombra se spazio, tono o gerarchia tipografica la rendono già comprensibile.

## Components

### Buttons

- **Shape:** angoli moderni e misurati (8px), altezza minima 44px.
- **Primary:** tinta piena configurabile, testo ad alto contrasto e padding orizzontale da 20px; nessun gradiente.
- **Hover / Focus:** variazione solida di tono in 180–200ms, sollevamento massimo di 1px e focus ring visibile da 3px.
- **Secondary / Ghost:** superficie neutra tinta o trasparente; niente bordo se il contrasto della superficie è sufficiente.
- **Plugin:** le azioni pubbliche usano lo stesso vocabolario (`ui-button`, `bc-btn` o le classi semantiche `plugin-*`); non incorporano colori, raggi o pile di utility nella view. GoodLib, Digital Library, Book Club, Archivio e FRBR ereditano sempre token e geometria del layout attivo.

### Chips

- **Style:** pillola compatta o testo con punto di stato; sfondo con tinta semantica al 10–14%.
- **State:** colore e icona/testo comunicano insieme; lo stato non dipende mai dal solo colore.

### Cards / Containers

- **Corner Style:** 12–16px solo per veri raggruppamenti funzionali.
- **Background:** Carta o Carta Secondaria.
- **Shadow Strategy:** piatta a riposo.
- **Border:** assente per default; un pixel solo per separare elementi interattivi confinanti.
- **Internal Padding:** 24px desktop, 16px mobile; il testo non tocca mai i bordi.

### Inputs / Fields

- **Style:** superficie Carta, bordo Divisore da 1px, raggio 8px e altezza minima 44px.
- **Focus:** bordo primario e ring visibile senza spostare il layout.
- **Error / Disabled:** errore con testo esplicito; disabilitato con contrasto sufficiente e cursore coerente.

### Date Selection Modal

La richiesta prestito usa un solo popup scrollabile. Il calendario resta nel flusso del campo, non copre contenuti o azioni e non viene mai fissato al documento. Quando lo spazio verticale è insufficiente scorre esclusivamente il popup; la pagina sottostante resta bloccata. Titolo, campi e azioni usano Inter, target da almeno 44px e colori configurati dal tema.

### Navigation

L'header usa Inter, icone locali e azioni ordinate per frequenza. Il menu attivo è indicato da tono o peso, non da linee decorative. Su desktop il burger è sempre nascosto; su mobile la ricerca e le azioni mantengono target da almeno 44px.

### Email

Le email non seguono i quattro temi del frontend: usano un unico sistema visivo editoriale e compatibile con i client di posta. Canvas grigio caldo, foglio bianco da 600px, testo `#111827`/`#374151`, divisori `#e5e7eb` e un solo accento `#d70262`. I pulsanti sono solidi, con raggio 8px e diventano a tutta larghezza su schermi stretti. Niente gradienti, font remoti, CDN, JavaScript, colori casuali o dipendenze da Tailwind; struttura a tabelle e stili critici inline. Contenuti, traduzioni, placeholder e link restano gestiti dai template del backend.

### Backend Actions

Il backend usa una gerarchia unica: azione primaria scura e solida, secondaria su carta con bordo visibile, distruttiva rosso profondo. I gruppi hanno almeno 10px di separazione e vanno a capo senza sovrapporsi; sotto 640px le azioni lunghe si espandono o si impilano. Le azioni a icona nelle tabelle hanno superficie, bordo e target minimo di 34px. Le sezioni Impostazioni usano una navigazione tab strutturata, a griglia su desktop e scorrevole orizzontalmente su mobile.

### Book Identity

La scheda libro presenta in ordine: breadcrumb, tipo/editore, titolo, sottotitolo, autore, genere, disponibilità e azioni. Autore e genere sono link testuali, non blocchi colorati. La disponibilità è una pillola compatta con icona e testo; il pannello informazioni usa superficie tonale senza bordi interni.

## Do's and Don'ts

### Do:

- **Do** usare spazio da 16, 24 e 32px per rendere evidente la gerarchia prima di aggiungere divisori.
- **Do** usare il colore configurato dall'amministratore per azioni primarie, focus e selezione.
- **Do** mantenere titolo, autore, disponibilità e azioni complete in tutti e quattro i temi.
- **Do** garantire controlli da almeno 44px, focus visibile e contrasto WCAG 2.1 AA.
- **Do** mantenere transizioni funzionali tra 150 e 250ms e rispettare `prefers-reduced-motion`.

### Don't:

- **Don't** creare dashboard composte da mosaici di card.
- **Don't** mettere bordi attorno a ogni elemento o strisce laterali colorate maggiori di 1px.
- **Don't** usare gradienti decorativi o gradienti nei pulsanti.
- **Don't** usare ombre pesanti, controlli sovradimensionati o colori saturi senza gerarchia.
- **Don't** produrre interfacce che sembrano template amministrativi datati.
- **Don't** annidare card, usare glassmorphism decorativo o trasformare autore, genere e stato in grandi mattonelle.
- **Don't** usare testo attenuato quando l'informazione è piccola, essenziale o interattiva.
