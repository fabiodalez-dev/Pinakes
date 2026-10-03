# Digital Library Plugin

**Versione:** 1.4.0
**Autore:** Pinakes Team
**Licenza:** GPL-3.0

## 📚 Descrizione

Il plugin **Digital Library** estende Pinakes con funzionalità avanzate per la gestione di contenuti digitali, permettendo di:

- 📕 **Caricare e distribuire eBook** (PDF, ePub)
- 🎧 **Caricare e riprodurre audiobook** (MP3, M4A, OGG)
- 🎵 **Player audio integrato** con Green Audio Player
- 📥 **Download diretto** di contenuti digitali
- **Più allegati per libro**: edizioni PDF/ePub, recensioni, articoli sul libro e più file audio, ciascuno con un titolo
- **Compatibilità**: gli allegati esistenti restano disponibili; rimuovere un collegamento non elimina il file dal disco
- 🏷️ **Icone nei badge** per indicare disponibilità contenuti

## ✨ Caratteristiche Principali

### Per gli Amministratori

- **Upload Semplificato**: Interfaccia drag-and-drop per caricare file (usa Uppy, già integrato)
- **Gestione Flessibile**: Supporto per URL esterni o file caricati localmente
- **Limiti Configurabili**: eBook max 100MB, audiobook max 500MB
- **Formati Multipli**: PDF, ePub per eBook | MP3, M4A, OGG per audio

### Per gli Utenti

- **Download Immediato**: Bottone "Scarica eBook" nelle schede libro
- **Player Moderno**: Green Audio Player con controlli accessibili
- **Seek & Volume**: Barra di progresso e controllo volume integrati
- **Keyboard Support**: Navigazione completa da tastiera
- **Responsive**: Funziona perfettamente su mobile e desktop

### Integrazione Trasparente

- **Opzionale**: Disattivabile in qualsiasi momento senza perdere dati
- **Non Invasivo**: Non modifica file core dell'applicazione
- **Hooks Based**: Usa il sistema di hooks di Pinakes
- **Performance**: Asset locali con fallback CDN

## 🚀 Installazione

### Requisiti

- Pinakes versione compatibile con sistema hooks
- PHP 8.0+
- Estensioni PHP: `gd`, `fileinfo`
- Apache con `mod_headers` (per CORS e range requests)

### Passaggi di Installazione

1. **Carica il plugin**:
   ```bash
   # Via interfaccia admin: Admin → Plugin → Carica Plugin
   # Oppure manualmente:
   cp -r digital-library/ /path/to/pinakes/storage/plugins/
   ```

2. **Installa il plugin**:
   - Vai su **Admin → Plugin**
   - Trova "Digital Library" nella lista
   - Clicca **Installa**

3. **Attiva il plugin**:
   - Dopo l'installazione, clicca **Attiva**
   - Il plugin registrerà automaticamente gli hooks

4. **Verifica configurazione**:
   - Controlla che `/public/uploads/digital/` sia scrivibile
   - Verifica che `.htaccess` contenga le regole CORS

## 📖 Guida all'Uso

### Aggiungere Contenuti Digitali

1. **Vai su Admin → Libri → Crea/Modifica libro**

2. **Scorri fino alla sezione "Contenuti Digitali"** (viola/indaco)

3. **Per un eBook**:
   - Inserisci l'URL nel campo "eBook (PDF/ePub)"
   - OPPURE clicca **Carica** e trascina il file PDF/ePub
   - Supporto per file fino a 100 MB

4. **Per un Audiobook**:
   - Inserisci l'URL nel campo "Audiobook (MP3/M4A/OGG)"
   - OPPURE clicca **Carica** e trascina il file audio
   - Supporto per file fino a 500 MB

5. **Salva il libro**

### Visualizzazione Frontend

Una volta caricati i contenuti:

#### Nelle Liste/Catalogo

- **Badge con icone**: Appare un'icona 📕 (PDF) e/o 🎧 (audio) accanto allo stato "Disponibile"
- Visibile in: Home, Catalogo, Archivio generi, Libri correlati

#### Nella Scheda Libro

- **Bottone "Scarica eBook"**: Download diretto del file PDF/ePub
- **Bottone "Ascolta Audiobook"**: Apre il player audio
- **Player Green Audio**: Interfaccia moderna con:
  - Play/Pause
  - Barra di progresso (seek)
  - Controllo volume
  - Tempo corrente / totale
  - Tasti rapidi da tastiera

### Tasti Rapidi Player Audio

- **Spazio/Invio**: Play/Pause
- **→ Freccia Destra**: Avanti 10 secondi
- **← Freccia Sinistra**: Indietro 10 secondi
- **↑ Freccia Su**: Aumenta volume
- **↓ Freccia Giù**: Diminuisci volume

## ⚙️ Configurazione Avanzata

### Limiti di Upload

Modifica `views/admin-form-fields.php`:

```php
// eBook (default: 100MB)
maxFileSize: 100 * 1024 * 1024

// Audiobook (default: 500MB)
maxFileSize: 500 * 1024 * 1024
```

### CORS e Streaming

Il file `.htaccess` deve contenere:

```apache
# Range Requests per audio streaming
<FilesMatch "\.(mp3|m4a|ogg)$">
    Header set Accept-Ranges bytes
    Header set Access-Control-Allow-Origin "*"
</FilesMatch>
```

### Formati Supportati

**eBook**:
- `application/pdf` (.pdf)
- `application/epub+zip` (.epub)

**Audiobook**:
- `audio/mpeg` (.mp3)
- `audio/mp4` (.m4a)
- `audio/ogg` (.ogg)

## 🔧 Risoluzione Problemi

### Il player audio non appare

**Causa**: Green Audio Player non caricato

**Soluzione**:
1. Verifica che `/public/assets/vendor/green-audio-player/` esista
2. Controlla la console browser per errori
3. Il plugin usa fallback CDN automaticamente

### Upload fallisce

**Causa**: Directory non scrivibile

**Soluzione**:
```bash
chmod 755 public/uploads/digital/
chown www-data:www-data public/uploads/digital/
```

### Audio non riproduce su Safari

**Causa**: MIME type non corretto

**Soluzione**:
Verifica `.htaccess`:
```apache
AddType audio/mpeg .mp3
AddType audio/mp4 .m4a
```

### Seek non funziona

**Causa**: Range requests non supportati

**Soluzione**:
1. Abilita `mod_headers` in Apache
2. Aggiungi `Header set Accept-Ranges bytes` nel `.htaccess`

## 🎨 Personalizzazione

### Modificare Colori Player

Modifica `assets/css/digital-library.css`:

```css
.player-digital-library .play-pause-btn {
    background-color: #YOUR_COLOR !important;
}

.player-digital-library .slider .gap-progress {
    background-color: #YOUR_COLOR !important;
}
```

### Cambiare Icone Badge

Modifica `views/badge-icons.php`:

```php
// Cambia fa-file-pdf con la tua icona
<i class="fas fa-YOUR-ICON ..."></i>
```

## 🔌 Hooks Utilizzati

Questo plugin si collega ai seguenti hook:

| Hook | Scopo |
|------|-------|
| `book.form.digital_fields` | Aggiunge campi upload nel form libro |
| `book.detail.digital_buttons` | Aggiunge bottoni download nel dettaglio |
| `book.detail.digital_player` | Inserisce player audio |
| `book.badge.digital_icons` | Mostra icone nei badge di stato |
| `assets.head` | Carica CSS e JS nel `<head>` |

## 📊 Compatibilità

- ✅ PHP 8.0+
- ✅ MySQL 5.7+ / MariaDB 10.3+
- ✅ Apache 2.4+ (con mod_rewrite, mod_headers)
- ✅ Nginx (con configurazione equivalente)
- ✅ Chrome, Firefox, Safari, Edge (ultime 2 versioni)

## 🛡️ Sicurezza

- ✅ Validazione MIME type lato server
- ✅ Limiti dimensione file
- ✅ Protezione directory listing
- ✅ Sanitizzazione URL output
- ✅ CORS configurato per GET only
- ✅ Nessun upload eseguibile

## 📝 Changelog

### Versione 1.0.0 (2025-11-18)

- ✨ Release iniziale
- 📕 Supporto eBook (PDF/ePub)
- 🎧 Supporto audiobook (MP3/M4A/OGG)
- 🎵 Green Audio Player integrato
- 🏷️ Icone badge status
- 📥 Upload Uppy drag-and-drop
- ♿ Accessibilità WCAG 2.1
- 📱 Design responsive

## 🤝 Supporto

Per problemi o richieste:

1. Controlla la [documentazione completa](https://pinakes.example.com/docs)
2. Cerca nei [problemi noti](https://github.com/pinakes/issues)
3. Apri un nuovo issue su GitHub

## 📄 Licenza

GPL-3.0-only - Vedi file LICENSE nella root del progetto.

---

**Sviluppato con ❤️ dal Team Pinakes**
