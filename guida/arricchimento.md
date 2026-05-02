# Arricchimento Massivo ISBN

Pinakes include uno strumento di arricchimento automatico per aggiungere
copertine, descrizioni e metadati ai libri che ne sono privi — senza
doverli modificare uno per uno.

> Introdotto in v0.5.5 — [PR #100](https://github.com/fabiodalez-dev/Pinakes/pull/100).

## Come funziona

L'arricchimento massivo individua tutti i libri nel catalogo che hanno
uno o più campi vuoti (copertina, descrizione, editore, anno…) e interroga
automaticamente i plugin di scraping attivi per recuperare le informazioni
mancanti, nell'ordine: Open Library → Google Books → Discogs →
MusicBrainz → Deezer → Scraping Pro (se installato).

**Principio fondamentale:** l'arricchimento è non-distruttivo.
Vengono compilati **solo i campi NULL o vuoti**; i dati già presenti non
vengono mai sovrascritti.

## Avvio manuale

1. Accedi ad **Amministrazione → Arricchimento ISBN** (barra laterale).
2. Abilita l'interruttore se è disattivato.
3. Clicca **Avvia arricchimento**.
4. Il sistema elabora 20 libri per click; una barra di avanzamento mostra
   i progressi in tempo reale.
5. Ripeti fino a che il contatore dei libri non arricchiti scende a zero.

> **Nota** — Il tasso di richieste è rispettato automaticamente per
> non sovraccaricare le API esterne. Non occorre attendere tra un click
> e l'altro.

## Automazione via cron

Per un arricchimento continuo in background, configura un cron job sul
server:

```bash
# Esempio: ogni ora, lunedì-venerdì
0 * * * 1-5 php /var/www/pinakes/scripts/bulk-enrich-cron.php >> /var/log/pinakes-enrich.log 2>&1
```

Il file `scripts/bulk-enrich-cron.php` utilizza un lock esclusivo
(`flock`) che impedisce esecuzioni concorrenti. Se un'elaborazione è
già in corso, il nuovo processo termina subito senza errori.

## Plugin coinvolti

L'arricchimento usa tutti i plugin di scraping attivi nella seguente
catena:

| Plugin | Tipo di risorsa | Dati recuperati |
|--------|-----------------|-----------------|
| Open Library | Libri | Copertina, descrizione, autore, editore, anno |
| Google Books (fallback) | Libri | Copertina, descrizione |
| Discogs | Dischi, CD, vinili | Cover, tracklist, etichetta, anno |
| MusicBrainz | Dischi (fallback) | Metadati musicali, Cover Art Archive |
| Deezer | Audio | Cover HD, tracklist |
| Scraping Pro | Libri (premium) | Dati estesi, fonti aggiuntive |

Attiva o disattiva i plugin da **Amministrazione → Plugin** per
controllare quali sorgenti vengono usate.

## Stato e log

- La pagina di arricchimento mostra quanti libri restano da arricchire.
- Gli errori per singolo libro vengono scritti in `storage/logs/app.log`
  con il titolo e l'ISBN del libro interessato.
- Un libro viene considerato "completamente arricchito" quando copertina
  e descrizione sono entrambe presenti; i campi supplementari (editore,
  anno, tracklist) vengono riempiti se disponibili dalla fonte.

## Domande frequenti

### L'arricchimento sovrascrive i miei dati?

No. Il processo controlla il valore attuale di ogni campo prima di
aggiornarlo: se contiene già un valore (anche uno spazio vuoto) il campo
viene lasciato invariato. Solo i campi `NULL` vengono compilati.

### Posso arricchire solo i libri privi di copertina?

Sì. Il filtro applicato internamente è `cover_image_path IS NULL OR
cover_image_path = ''`. I libri con copertina vengono saltati anche se
altri campi sono vuoti — per quei campi vale comunque la regola
non-distruttiva.

### Quante richieste fa verso i servizi esterni?

Circa 1 richiesta per servizio ogni 1–2,5 secondi, a seconda del
provider. Per 100 libri da arricchire con 3 plugin attivi calcola circa
5–10 minuti totali. Con il cron questo avviene in background senza
impatto sull'interfaccia.

### Cosa succede se un plugin non riesce a trovare il libro?

Il sistema passa al plugin successivo nella catena. Se nessun plugin
produce dati, il libro rimane invariato (nessun errore visibile
all'utente; il dettaglio è nel log).
