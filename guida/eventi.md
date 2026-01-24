# Gestione Eventi

Guida completa alla gestione degli eventi biblioteca in Pinakes.

## Panoramica

Il modulo eventi permette di:
- Creare e pubblicare eventi della biblioteca
- Gestire immagini e contenuti multimediali
- Configurare SEO completo per ogni evento
- Abilitare/disabilitare la sezione eventi globalmente

## Accesso

La gestione eventi si trova in:
- **Admin → CMS → Eventi**

## Abilitare la Sezione Eventi

La sezione eventi può essere abilitata o disabilitata globalmente:

1. Vai in **CMS → Eventi**
2. Usa il toggle **Sezione Eventi**
3. Quando disabilitata:
   - Il menu "Eventi" non appare nel frontend
   - Le pagine evento restituiscono 404
   - Gli eventi esistenti vengono preservati

Impostazione: `events_page_enabled` in `system_settings` (categoria: `cms`)

## Creare un Evento

### Accesso

1. Vai in **CMS → Eventi**
2. Clicca **Nuovo Evento**

### Campi Evento

| Campo | Descrizione | Obbligatorio |
|-------|-------------|--------------|
| **Titolo** | Nome dell'evento | Sì |
| **Contenuto** | Descrizione HTML (TinyMCE) | No |
| **Data evento** | Data (formato: YYYY-MM-DD) | Sì |
| **Ora evento** | Orario (formato: HH:MM) | No |
| **Immagine** | Immagine in evidenza | No |
| **Attivo** | Visibile nel frontend | No (default: no) |

### Validazioni

- **Data**: deve essere nel formato `YYYY-MM-DD`
- **Ora**: deve essere nel formato `HH:MM` o `HH:MM:SS`
- **Immagine**: JPG, PNG, WebP - max 5MB

### Slug Automatico

Il sistema genera automaticamente uno slug SEO-friendly:
- Titolo convertito in minuscolo
- Accenti rimossi (UTF-8 → ASCII)
- Caratteri speciali rimossi
- Spazi convertiti in trattini
- Unicità garantita (aggiunge `-1`, `-2` se necessario)

Esempio: "Presentazione Libro Nuovo" → `presentazione-libro-nuovo`

## Immagine Evento

### Upload Immagine

Formati supportati:
- JPG/JPEG
- PNG
- WebP

Limite dimensione: **5 MB**

### Percorso File

Le immagini vengono salvate in:
```
public/uploads/events/event_YYYYMMDD_HHMMSS_[random].ext
```

Il nome include:
- Prefisso `event_`
- Data e ora upload
- 8 caratteri random (sicurezza)
- Estensione originale

### Rimuovere Immagine

1. Modifica l'evento
2. Seleziona "Rimuovi immagine"
3. Salva

## SEO Evento

Ogni evento ha campi SEO dedicati per ottimizzare la visibilità sui motori di ricerca.

### Meta Tag Base

| Campo | Descrizione | Lunghezza consigliata |
|-------|-------------|----------------------|
| `seo_title` | Title tag | 50-60 caratteri |
| `seo_description` | Meta description | 150-160 caratteri |
| `seo_keywords` | Meta keywords | Parole chiave separate da virgola |

### Open Graph (Facebook/LinkedIn)

| Campo | Descrizione |
|-------|-------------|
| `og_title` | Titolo per social |
| `og_description` | Descrizione per social |
| `og_image` | URL immagine social |
| `og_type` | Tipo contenuto (default: `article`) |
| `og_url` | URL canonico |

### Twitter Card

| Campo | Descrizione |
|-------|-------------|
| `twitter_card` | Tipo card (default: `summary_large_image`) |
| `twitter_title` | Titolo per Twitter |
| `twitter_description` | Descrizione per Twitter |
| `twitter_image` | URL immagine Twitter |

## Lista Eventi

### Paginazione

La lista eventi admin mostra:
- 10 eventi per pagina
- Ordinamento: data evento DESC, data creazione DESC

### Informazioni Visualizzate

Per ogni evento:
- Titolo
- Slug
- Data e ora evento
- Immagine (miniatura)
- Stato (attivo/bozza)
- Data creazione

## Modificare un Evento

1. Vai in **CMS → Eventi**
2. Clicca sull'evento da modificare
3. Modifica i campi
4. Salva

### Aggiornamento Slug

Quando modifichi il titolo:
- Lo slug viene rigenerato automaticamente
- Se lo slug esiste già, viene aggiunto un suffisso numerico
- L'ID evento viene escluso dal controllo unicità

## Eliminare un Evento

1. Clicca l'icona **Elimina** sull'evento
2. Conferma l'eliminazione

> **Attenzione**: L'eliminazione è permanente. L'immagine associata resta nel filesystem.

## Tabella Database

```sql
CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` text,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `seo_title` varchar(255) DEFAULT NULL,
  `seo_description` text,
  `seo_keywords` varchar(500) DEFAULT NULL,
  `og_image` varchar(500) DEFAULT NULL,
  `og_title` varchar(255) DEFAULT NULL,
  `og_description` text,
  `og_type` varchar(50) DEFAULT 'article',
  `og_url` varchar(500) DEFAULT NULL,
  `twitter_card` varchar(50) DEFAULT 'summary_large_image',
  `twitter_title` varchar(255) DEFAULT NULL,
  `twitter_description` text,
  `twitter_image` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '0',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
);
```

## Sicurezza

### Sanitizzazione Input

- **Campi testo**: `strip_tags()` rimuove tutti i tag HTML
- **Contenuto TinyMCE**: `HtmlHelper::sanitizeHtml()` con whitelist tag permessi
- **File upload**: validazione MIME type, estensione, dimensione

### Protezione Path Traversal

- Verifica `realpath()` del percorso upload
- Controllo che il percorso finale sia dentro `public/uploads`
- Rimozione null bytes dal nome file

### CSRF

Tutte le operazioni (create, update, delete, toggle) richiedono token CSRF valido.

## URL Frontend

Gli eventi sono accessibili nel frontend a:
```
/eventi                    # Lista eventi
/evento/[slug]             # Singolo evento
```

L'URL esatto dipende dalla configurazione delle route localizzate.

## Best Practices

### Immagini

- Usa dimensioni ottimizzate (1200x630px per social)
- Preferisci formato WebP per minor peso
- Comprimi le immagini prima dell'upload

### SEO

- Compila sempre title e description
- Usa Open Graph per una migliore condivisione social
- Lo slug viene generato dal titolo, sceglilo con cura

### Contenuto

- Usa l'editor TinyMCE per formattazione ricca
- Evita stili inline - usa le classi predefinite
- Verifica l'anteprima prima di pubblicare

---

## Domande Frequenti (FAQ)

### 1. Come abilito la sezione eventi nel sito?

1. Vai in **CMS → Eventi**
2. Attiva il toggle **Sezione Eventi**
3. Il menu "Eventi" appare automaticamente nel frontend

Se disabilitato, tutte le pagine evento restituiscono 404.

---

### 2. Qual è la differenza tra evento "attivo" e "bozza"?

| Stato | Visibile nel frontend | Modificabile |
|-------|----------------------|--------------|
| **Attivo** | Sì | Sì |
| **Bozza** (non attivo) | No | Sì |

Gli eventi bozza sono utili per preparare contenuti in anticipo.

---

### 3. Come creo un evento ricorrente (es. ogni settimana)?

Attualmente Pinakes non supporta eventi ricorrenti automatici. Devi:
1. Creare ogni occorrenza separatamente
2. Oppure usare un unico evento con descrizione "Ogni martedì ore 17"

---

### 4. Che dimensioni deve avere l'immagine evento?

**Dimensioni consigliate**:
- **Per sito**: 1200 x 630 pixel (formato social ottimale)
- **Formato**: WebP (più leggero) o JPG
- **Peso massimo**: 5 MB (comprimi prima dell'upload)

Immagini più grandi vengono accettate ma rallentano il caricamento.

---

### 5. Come ottimizzare il SEO di un evento?

Compila tutti i campi SEO:
1. **Title SEO**: 50-60 caratteri, includi parole chiave
2. **Description**: 150-160 caratteri, descrizione accattivante
3. **Open Graph**: per condivisione social ottimale
4. **Immagine**: 1200x630px per anteprima social

---

### 6. Posso incorporare video nell'evento?

Sì, usando l'editor TinyMCE:
1. Clicca su **Inserisci/Modifica media**
2. Incolla URL YouTube o Vimeo
3. Il video viene incorporato nel contenuto

---

### 7. Come cancello un evento passato?

1. Vai in **CMS → Eventi**
2. Trova l'evento nella lista
3. Clicca **Elimina**
4. Conferma

**Nota**: l'immagine associata resta sul server.

---

### 8. Gli eventi appaiono nel calendario della dashboard?

No, il calendario della dashboard mostra solo prestiti e prenotazioni.

Gli eventi biblioteca appaiono:
- Nella pagina pubblica `/eventi`
- Nel feed ICS se configurato

---

### 9. Come modifico lo slug URL di un evento?

Lo slug viene generato automaticamente dal titolo. Per cambiarlo:
1. Modifica il **titolo** dell'evento
2. Lo slug viene rigenerato
3. Salva

Se vuoi uno slug specifico, modifica il titolo di conseguenza.

---

### 10. Posso programmare la pubblicazione automatica di un evento?

No, la pubblicazione richiede attivazione manuale. Per eventi futuri:
1. Crea l'evento con data futura
2. Imposta come "Attivo"
3. Sarà visibile immediatamente ma con data futura

Gli utenti vedranno che l'evento è programmato per quella data.
