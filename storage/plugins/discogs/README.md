# Music Scraper Plugin (Discogs, MusicBrainz, Deezer)

Plugin multi-sorgente per lo scraping di metadati musicali in Pinakes, pensato per catalogare supporti musicali (CD, LP, vinili, cassette). Interroga Discogs, MusicBrainz + Cover Art Archive e Deezer per massimizzare la copertura dei dati.

## Funzionamento

Il plugin si aggancia al sistema di scraping tramite quattro hook:

- **scrape.sources** (priorita 8) -- Registra il plugin come fonte di scraping
- **scrape.fetch.custom** (priorita 8) -- Esegue la ricerca e il recupero dei metadati
- **scrape.data.modify** (priorita 15) -- Arricchisce i dati con copertine mancanti
- **scrape.isbn.validate** (priorita 8) -- Accetta codici UPC/EAN a 12-13 cifre oltre agli ISBN

### Strategia di ricerca

1. Ricerca per barcode (EAN/UPC) su Discogs -- `GET /database/search?barcode={ean}&type=release`
2. Recupero dettagli completi della release Discogs -- `GET /releases/{id}`
3. **Fallback MusicBrainz** -- se Discogs non trova risultati, cerca su MusicBrainz per barcode
4. **Arricchimento Deezer** -- se manca la copertina, cerca su Deezer per titolo+artista

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

## MusicBrainz (fallback barcode)

Quando Discogs non trova risultati per un barcode, il plugin cerca automaticamente su [MusicBrainz](https://musicbrainz.org/), un database musicale open data.

- **Ricerca per barcode** -- `GET /ws/2/release?query=barcode:{ean}&fmt=json`
- **Dettagli release** -- `GET /ws/2/release/{mbid}?inc=artists+labels+recordings+release-groups&fmt=json`
- **Cover Art Archive** -- le copertine vengono recuperate da [Cover Art Archive](https://coverartarchive.org/), un archivio gratuito collegato a MusicBrainz

### Mappatura MusicBrainz -> Pinakes

| MusicBrainz | Pinakes | Note |
|-------------|---------|------|
| title | Titolo | Titolo della release |
| artist-credit | Autore/i | Array di crediti artista con joinphrase |
| media.tracks | Descrizione | Tracklist HTML con durate (ms -> mm:ss) |
| date | Anno | Primi 4 caratteri della data |
| label-info.label.name | Editore | Prima etichetta |
| media.format | Formato | CD -> cd_audio, Vinyl -> vinile, ecc. |
| Cover Art Archive | Copertina | Preferisce front cover, poi prima immagine |

Non e richiesta autenticazione. Rate limit: 1 richiesta/secondo (rispettato automaticamente).

## Deezer (copertine HD)

Se dopo Discogs e MusicBrainz la copertina e ancora mancante, il plugin cerca su [Deezer](https://developers.deezer.com/) per titolo+artista (solo copertine HD, non generi).

- **Ricerca album** -- `GET /search/album?q={artista}+{titolo}&limit=1`
- **Copertina HD** -- usa `cover_xl` (1000x1000px) per la massima qualita
- Non richiede autenticazione ne API key
- Rate limit: 1 secondo tra le richieste

## Rate Limiting

Il plugin rispetta i limiti di ciascuna API con throttling adattivo:

| Sorgente | Rate limit | Intervallo |
|----------|-----------|------------|
| Discogs (con token) | 60 req/min | 1s tra le chiamate |
| Discogs (senza token) | 25 req/min | 2.5s tra le chiamate |
| MusicBrainz | 1 req/s | 1.1s tra le chiamate |
| Cover Art Archive | Nessun limite | Nessun ritardo aggiuntivo |
| Deezer | 50 req/5s | 1s tra le chiamate |

In caso di errore 429 (rate limit exceeded) la risposta viene registrata nei log.

## Link utili

- [Discogs API Documentation](https://www.discogs.com/developers)
- [Discogs Database Search](https://www.discogs.com/developers#page:database,header:database-search)
- [Discogs Release](https://www.discogs.com/developers#page:database,header:database-release)
- [MusicBrainz API Documentation](https://musicbrainz.org/doc/MusicBrainz_API)
- [Cover Art Archive API](https://wiki.musicbrainz.org/Cover_Art_Archive/API)
- [Deezer API Documentation](https://developers.deezer.com/api)

## Licenza

Questo plugin e parte del progetto Pinakes ed e rilasciato sotto la stessa licenza del progetto principale.

I dati di Discogs sono soggetti ai [termini di utilizzo delle API Discogs](https://www.discogs.com/developers/#page:home,header:home-general-information).
I dati di MusicBrainz sono disponibili sotto [licenza CC0](https://creativecommons.org/publicdomain/zero/1.0/) (dominio pubblico).
Le copertine di Cover Art Archive seguono le rispettive licenze indicate per ciascuna immagine.
