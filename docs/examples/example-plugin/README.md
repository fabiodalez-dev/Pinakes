# Book Rating Plugin - Esempio

Questo è un plugin di esempio che dimostra come creare un plugin completo per Pinakes.

## Caratteristiche Dimostrate

✅ Struttura completa del plugin
✅ File `plugin.json` con metadati
✅ Ciclo di vita completo (install, activate, deactivate, uninstall)
✅ Creazione tabelle database personalizzate
✅ Registrazione hook
✅ Estensione dati libri
✅ Form personalizzati nel backend
✅ Visualizzazione nel frontend
✅ Integrazione API esterne
✅ Gestione impostazioni
✅ Sistema di logging

## Come Usare Questo Esempio

### 1. Installazione per Test

```bash
# Dalla root di Pinakes
cd docs/examples/example-plugin
zip -r book-rating.zip .
```

### 2. Carica il Plugin

1. Accedi all'admin di Pinakes
2. Vai su Plugin
3. Carica `book-rating.zip`
4. Attiva il plugin

### 3. Configurazione

Dopo l'attivazione, il plugin:
- Crea la tabella `book_rating_data`
- Aggiunge gli hook al sistema
- Attende la configurazione API key

### 4. Personalizzazione

Modifica questo esempio per creare il tuo plugin:

1. **Rinomina il plugin** in `plugin.json`
2. **Modifica la classe principale** in `BookRatingPlugin.php`
3. **Aggiungi le tue classi** in `classes/`
4. **Personalizza gli hook** secondo le tue necessità
5. **Testa** prima di distribuire

## Struttura File

```
example-plugin/
├── plugin.json                          # Metadati plugin
├── BookRatingPlugin.php                 # Classe principale
├── classes/                             # Classi helper
│   ├── BookRatingBookHandler.php       # Gestione libri
│   └── BookRatingFrontendHandler.php   # Gestione frontend
└── README.md                            # Questa documentazione
```

## Hook Utilizzati

| Hook | Tipo | Scopo |
|------|------|-------|
| `book.data.get` | Filter | Aggiunge rating ai dati libro |
| `book.save.after` | Action | Sincronizza rating dopo salvataggio |
| `book.fields.backend.form` | Action | Mostra campi rating nel backend |
| `book.frontend.details` | Action | Mostra rating nel frontend |

## API Utilizzate

- **Goodreads API** (simulata nell'esempio)
  - Endpoint: `/book/isbn/{isbn}`
  - Recupera: rating, numero valutazioni, URL

## Database

### Tabella: `book_rating_data`

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| id | INT | ID primario |
| libro_id | INT | Riferimento a libri(id) |
| goodreads_rating | DECIMAL(3,2) | Rating 0-5 |
| goodreads_ratings_count | INT | Numero valutazioni |
| goodreads_reviews_count | INT | Numero recensioni |
| goodreads_url | VARCHAR(255) | URL Goodreads |
| last_sync | DATETIME | Ultima sincronizzazione |

## Impostazioni Plugin

| Chiave | Default | Descrizione |
|--------|---------|-------------|
| `goodreads_api_key` | '' | API key Goodreads |
| `auto_sync_enabled` | 'true' | Sync automatica |
| `sync_interval_hours` | '24' | Intervallo sync (ore) |
| `show_on_frontend` | 'true' | Mostra nel frontend |

## Estensioni Possibili

Partendo da questo esempio, potresti:

- Aggiungere altre fonti di rating (Amazon, LibraryThing, etc.)
- Implementare cache per API calls
- Aggiungere statistiche avanzate
- Creare widget per dashboard
- Implementare notifiche per nuovi rating
- Aggiungere grafici storici dei rating

## Problemi Comuni

### Plugin non si installa

- Verifica che `plugin.json` sia valido
- Controlla che il file ZIP sia strutturato correttamente
- Verifica permessi directory `storage/plugins/`

### Hook non vengono eseguiti

- Verifica che il plugin sia attivo
- Controlla la registrazione hook in `plugin_hooks`
- Verifica che le classi callback esistano

### Errori database

- Verifica che le tabelle siano state create
- Controlla i permessi dell'utente database
- Rivedi i log in `plugin_logs`

## Licenza

Questo esempio è fornito come riferimento educativo.
Sentiti libero di usarlo come base per i tuoi plugin.

## Supporto

Per domande sul sistema di plugin, consulta:
- `/docs/PLUGIN_SYSTEM.md` - Documentazione completa
- `/docs/PLUGIN_HOOKS.md` - Riferimento hook
