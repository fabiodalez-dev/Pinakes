# Discogs Music Scraper Plugin

Plugin per l'integrazione delle API di Discogs nel sistema di scraping di Pinakes, pensato per catalogare supporti musicali (CD, LP, vinili, cassette).

## Funzionamento

Il plugin si aggancia al sistema di scraping tramite tre hook:

- **scrape.sources** (priorita 8) -- Registra Discogs come fonte di scraping
- **scrape.fetch.custom** (priorita 8) -- Esegue la ricerca e il recupero dei metadati
- **scrape.data.modify** (priorita 15) -- Arricchisce i dati con copertine mancanti

### Strategia di ricerca

1. Ricerca per barcode (EAN/UPC) -- `GET /database/search?barcode={ean}&type=release`
2. Se nessun risultato, ricerca per query -- `GET /database/search?q={ean}&type=release`
3. Recupero dettagli completi della release -- `GET /releases/{id}`

## Mappatura dati Discogs -> Pinakes

| Discogs | Pinakes | Note |
|---------|---------|------|
| Artist | Autore | Rimossa disambiguazione "(2)" ecc. |
| Album title | Titolo | Estratto da formato "Artist - Album" |
| Label | Editore | Prima etichetta |
| Barcode | EAN | Il codice usato per la ricerca |
| Year | Anno | Anno di uscita della release |
| Tracklist | Descrizione | Formattata come "1. Titolo (3:45)" |
| Cover image | Copertina | Immagine primaria o thumbnail |
| Format | Formato | CD -> cd_audio, Vinyl -> vinile, ecc. |
| Genre | Genere | Primo genere della release |
| Series | Serie | Se presente nella release |
| Country | Paese | Paese di pubblicazione |

### Formati supportati

| Discogs | Pinakes |
|---------|---------|
| CD, CDr, CDs, SACD | cd_audio |
| Vinyl, LP | vinile |
| Cassette | audiocassetta |
| DVD | dvd |
| Blu-ray | blu_ray |
| File | digitale |
| Altro | altro |

## Token API (opzionale)

Senza token le API di Discogs permettono 25 richieste al minuto. Con un token personale il limite sale a 60 richieste al minuto.

### Come ottenere un token

1. Accedi a https://www.discogs.com/settings/developers
2. Clicca "Generate new token"
3. Copia il token generato
4. Inseriscilo nelle impostazioni del plugin in Pinakes (chiave: `api_token`)

**Nota:** le immagini ad alta risoluzione (`images[].uri`) richiedono autenticazione. Senza token il plugin usa le thumbnail dalla ricerca.

## Installazione

1. Crea un file ZIP con tutti i file del plugin
2. Vai su **Admin -> Plugin**
3. Clicca **"Carica Plugin"**
4. Seleziona il file ZIP e clicca **"Installa"**
5. Attiva il plugin dalla lista

## Rate Limiting

Il plugin rispetta i limiti di Discogs inserendo una pausa di 1 secondo tra le chiamate API consecutive. In caso di errore 429 (rate limit exceeded) la risposta viene registrata nei log.

## Link utili

- [Discogs API Documentation](https://www.discogs.com/developers)
- [Discogs Database Search](https://www.discogs.com/developers#page:database,header:database-search)
- [Discogs Release](https://www.discogs.com/developers#page:database,header:database-release)

## Licenza

Questo plugin e parte del progetto Pinakes ed e rilasciato sotto la stessa licenza del progetto principale.

I dati di Discogs sono soggetti ai [termini di utilizzo delle API Discogs](https://www.discogs.com/developers/#page:home,header:home-general-information).
