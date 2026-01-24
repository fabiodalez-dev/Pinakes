# Sistema Plugin

Pinakes supporta plugin per estendere le funzionalità.

## Plugin Ufficiali

| Plugin | Versione | Descrizione |
|--------|----------|-------------|
| Z39.50/SRU Integration | 1.1.0 | Server SRU e client per interoperabilità |
| API Book Scraper | 1.1.0 | Scraping metadati da API esterne |
| Scraping Pro | 1.4.0 | Scraping avanzato da siti web |
| Dewey Editor | 1.0.0 | Editor classificazioni Dewey |
| Digital Library | 1.0.0 | Gestione ebook e audiolibri |
| Open Library | 1.0.0 | Integrazione Open Library API |

## Installazione Plugin

### Da Archivio ZIP

1. Vai in **Amministrazione → Plugin**
2. Clicca **Carica plugin**
3. Seleziona il file ZIP
4. Clicca **Installa**

### Manuale

1. Estrai lo ZIP in `storage/plugins/nome-plugin/`
2. Verifica la presenza di `plugin.json`
3. Vai in **Amministrazione → Plugin**
4. Il plugin appare nella lista
5. Clicca **Attiva**

## Struttura Plugin

```
storage/plugins/mio-plugin/
├── MioPluginPlugin.php    # Classe principale
├── plugin.json            # Metadati
├── classes/               # Classi aggiuntive
├── views/                 # Template
└── assets/                # CSS, JS, immagini
```

### plugin.json

```json
{
  "name": "mio-plugin",
  "display_name": "Il Mio Plugin",
  "description": "Descrizione del plugin",
  "version": "1.0.0",
  "author": "Nome Autore",
  "requires_php": "8.0",
  "requires_app": "0.4.0",
  "main_file": "MioPluginPlugin.php"
}
```

## Gestione Plugin

### Attivazione/Disattivazione

1. Vai in **Amministrazione → Plugin**
2. Trova il plugin nella lista
3. Clicca **Attiva** o **Disattiva**

### Aggiornamento

1. Disattiva il plugin
2. Carica la nuova versione
3. Riattiva il plugin

### Rimozione

1. Disattiva il plugin
2. Clicca **Rimuovi**
3. Conferma l'eliminazione

I file vengono eliminati da `storage/plugins/`.

## Configurazione

I plugin con impostazioni mostrano un'icona ingranaggio:

1. Clicca l'icona impostazioni
2. Modifica i parametri
3. Salva

Le impostazioni sono salvate in database (`plugin_settings`).

## Hook System

I plugin si integrano tramite hook:

| Hook | Descrizione |
|------|-------------|
| `app.routes.register` | Registra nuove route |
| `assets.head` | Aggiunge CSS/meta nel head |
| `assets.footer` | Aggiunge JS nel footer |
| `book.scrape.isbn` | Provider ricerca ISBN |
| `book.save.after` | Dopo salvataggio libro |

## Sviluppo Plugin

Per creare un nuovo plugin:

1. Crea la cartella in `storage/plugins/`
2. Crea `plugin.json` con i metadati
3. Crea la classe principale che estende `PluginBase`
4. Implementa i metodi `activate()` e `deactivate()`
5. Registra gli hook necessari

Esempio classe base:

```php
<?php
namespace Plugins\MioPlugin;

use App\Support\PluginBase;

class MioPluginPlugin extends PluginBase
{
    public function activate(): void
    {
        // Codice di attivazione
    }

    public function deactivate(): void
    {
        // Codice di disattivazione
    }
}
```

## Compatibilità

Ogni plugin dichiara:
- `requires_php`: versione PHP minima
- `requires_app`: versione Pinakes minima
- `max_app_version`: versione Pinakes massima

Il sistema verifica la compatibilità prima dell'attivazione.

---

## Domande Frequenti (FAQ)

### 1. Dove trovo i plugin ufficiali da installare?

I plugin ufficiali sono disponibili in due modi:

**Download diretto**:
- Vai su [GitHub Releases di Pinakes](https://github.com/fabiodalez-dev/Pinakes/releases)
- Scarica gli archivi ZIP dei plugin

**Dalla pagina Plugin**:
- **Amministrazione → Plugin → Catalogo Plugin** (se disponibile)
- Lista dei plugin compatibili con la tua versione

---

### 2. Il plugin non si attiva, cosa controllo?

Cause comuni e soluzioni:

| Problema | Soluzione |
|----------|-----------|
| PHP troppo vecchio | Verifica che PHP soddisfi `requires_php` |
| Pinakes troppo vecchio | Aggiorna Pinakes alla versione richiesta |
| Pinakes troppo nuovo | Cerca versione plugin aggiornata |
| Permessi cartella | `chmod 755 storage/plugins/` |
| plugin.json mancante | Verifica struttura archivio ZIP |

**Controlla i log**: `storage/logs/app.log` per errori dettagliati.

---

### 3. Come aggiorno un plugin a una nuova versione?

Procedura sicura:

1. **Disattiva** il plugin attuale
2. **Backup** della cartella plugin (opzionale ma consigliato)
3. **Carica** il nuovo ZIP tramite interfaccia
4. Oppure **sovrascrivi** i file manualmente in `storage/plugins/nome-plugin/`
5. **Riattiva** il plugin

Le impostazioni sono salvate in database e vengono mantenute.

---

### 4. Posso modificare un plugin esistente?

Sì, ma con attenzione:

**Modifiche semplici**:
- Modifica i file PHP in `storage/plugins/nome-plugin/`
- Cambia CSS/JS in `assets/`
- Modifica template in `views/`

**Attenzione**:
- Le modifiche vengono perse aggiornando il plugin
- Crea un fork o plugin derivato per modifiche permanenti
- Non modificare il `plugin.json` senza capire le conseguenze

---

### 5. Come rimuovo completamente un plugin?

**Da interfaccia**:
1. **Disattiva** il plugin
2. Clicca **Rimuovi**
3. Conferma l'eliminazione

**Manualmente**:
```bash
# Elimina la cartella
rm -rf storage/plugins/nome-plugin/

# Rimuovi impostazioni dal database (opzionale)
DELETE FROM plugin_settings WHERE plugin_name = 'nome-plugin';
DELETE FROM plugin_hooks WHERE plugin_name = 'nome-plugin';
```

---

### 6. I plugin rallentano il sistema?

Dipende dal plugin:

**Plugin leggeri** (nessun impatto percepibile):
- Dewey Editor
- Open Library Integration

**Plugin con impatto** (richieste esterne):
- API Book Scraper (chiamate HTTP)
- Z39.50 Server (se usato attivamente)
- Scraping Pro (parsing pagine web)

**Suggerimento**: attiva solo i plugin che usi realmente.

---

### 7. Come faccio a creare un mio plugin?

Passi essenziali:

1. **Crea cartella**: `storage/plugins/mio-plugin/`
2. **Crea plugin.json** con metadati
3. **Crea classe principale** che estende `PluginBase`
4. **Implementa** `activate()` e `deactivate()`
5. **Registra hook** per integrare funzionalità

Consulta la [guida sviluppo plugin](/tecnico/plugin-dev.md) per dettagli.

---

### 8. Cosa succede ai dati del plugin se lo disattivo?

**Dati preservati**:
- Impostazioni in `plugin_settings`
- Hook registrati in `plugin_hooks`
- Eventuali tabelle database create dal plugin

**Dati rimossi** (solo su "Rimuovi"):
- File della cartella plugin
- Impostazioni e hook (opzionale, dipende dal plugin)

Disattivare un plugin è sicuro: puoi riattivarlo senza perdere configurazioni.

---

### 9. Come verifico se un plugin è compatibile con la mia versione?

Controlla il file `plugin.json`:

```json
{
  "requires_php": "8.0",      // PHP minimo
  "requires_app": "0.4.0",    // Pinakes minimo
  "max_app_version": "1.0.0"  // Pinakes massimo (opzionale)
}
```

**Nella pagina Plugin**:
- Plugin incompatibili mostrano un avviso
- Il pulsante "Attiva" è disabilitato se non compatibile

---

### 10. Dopo l'aggiornamento di Pinakes i plugin funzionano ancora?

Dipende:

**Plugin ufficiali**: Generalmente sì, sono testati con le nuove versioni. Controlla le note di rilascio per eventuali breaking changes.

**Plugin di terze parti**: Potrebbero richiedere aggiornamenti. Verifica con l'autore.

**Best practice dopo aggiornamento**:
1. Controlla la pagina Plugin per avvisi compatibilità
2. Testa le funzionalità principali di ogni plugin
3. Controlla i log per errori
4. Aggiorna i plugin se disponibili nuove versioni
