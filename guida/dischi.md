# Dischi e Musica

Pinakes gestisce non solo libri ma anche **materiale musicale e
audiovisivo**: CD, vinili, LP, cassette, DVD, audiolibri e qualsiasi
altro supporto fisico o digitale. L'interfaccia si adatta in modo
intelligente al tipo di contenuto, mostrando etichette e campi
appropriati.

> Introdotto in v0.5.5 — [PR #100](https://github.com/fabiodalez-dev/Pinakes/pull/100).

## Il campo "Tipo Media"

Ogni voce del catalogo ha un campo **Tipo Media** (`tipo_media`) che
determina come Pinakes interpreta e visualizza la scheda:

| Valore | Descrizione | Icona |
|--------|-------------|-------|
| `libro` | Libro cartaceo o ebook | Libro |
| `disco` | CD, vinile, LP, cassetta, musicassetta | Disco |
| `audiolibro` | Libro letto (MP3, audiobook) | Cuffie |
| `dvd` | DVD, Blu-ray, film | Pellicola |
| `altro` | Qualunque altro supporto | Archivio |

Nel form di inserimento e modifica, la dropdown **Tipo Media** si trova
nella sezione "Classificazione". Una volta salvato, l'icona corrispondente
appare nella lista admin e nella pagina pubblica.

## Etichette dinamiche

Quando il tipo media è `disco`, le etichette dei campi cambiano
automaticamente per riflettere la terminologia musicale:

| Campo generico | Etichetta per dischi |
|----------------|----------------------|
| Autore | Artista |
| Editore | Etichetta discografica |
| Anno Pubblicazione | Anno di Uscita |
| Numero Pagine | Tracce |
| ISBN-13 | Barcode (EAN-13 / UPC-A) |
| Descrizione | Tracklist |
| Collana | Discografia |

Queste etichette sono gestite dalla classe `app/Support/MediaLabels.php`
e si applicano uniformemente a form admin, pagina dettaglio e export CSV.

## Inserimento manuale di un disco

1. Vai in **Amministrazione → Catalogo → Nuovo** (oppure **Nuovo libro**
   dalla barra laterale).
2. Nella sezione **Classificazione**, scegli il **Tipo Media** → `disco`.
3. I campi si aggiornano istantaneamente con le etichette musicali.
4. Compila i campi principali:
   - **Artista** — nome dell'artista o del gruppo.
   - **Titolo album** — titolo esatto come stampato sul disco.
   - **Etichetta discografica** — casa discografica (es. "EMI", "Capitol").
   - **Anno di Uscita** — anno di pubblicazione.
   - **Barcode** — il codice EAN-13 o UPC-A stampato sul retro.
   - **Formato** — seleziona da: CD Audio, Vinile, LP, Musicassetta, ecc.
5. Nella sezione **Contenuto**, compila la **Tracklist** (formato HTML,
   il TinyMCE mostra le tracce come lista ordinata).
6. Salva. La copertina può essere caricata manualmente o tramite
   arricchimento automatico.

> **Consiglio** — Se hai il barcode, usa la funzione **Arricchimento
> ISBN** per compilare automaticamente artista, titolo, tracklist e
> copertina tramite Discogs, MusicBrainz o Deezer.

## Identificatori per i dischi

I dischi utilizzano sistemi di identificazione diversi dai libri:

| Identificatore | Descrizione | Esempio |
|----------------|-------------|---------|
| EAN-13 | Barcode europeo a 13 cifre | `0724349681422` |
| UPC-A | Barcode americano a 12 cifre | `724349681422` |
| Cat# | Catalog Number stampato su spine/label | `CDP 7912682`, `SRX-6272` |

Nel campo **Barcode** (ex ISBN-13) puoi inserire sia EAN che UPC. Il
campo **Cat#** accetta identificatori alfanumerici come quelli usati
da Discogs (es. `EMI 2C 068-82892`).

> **Nota sul Cat#** — A partire da v0.5.9, il plugin Discogs riconosce
> automaticamente i Catalog Number alfanumerici che non sono barcode
> numerici, evitando confusione con ISBN-10 validi (fix per il caso
> "Bonnie Raitt — Nick Of Time, Capitol CDP 7912682").

## Arricchimento automatico tramite plugin

Pinakes include tre plugin bundled per il recupero automatico di
metadati musicali:

### Plugin Discogs

Attivalo da **Amministrazione → Plugin → Discogs Music Scraper → Attiva**.

Discogs è il database discografico più completo disponibile e supporta:
- Ricerca per barcode EAN-13 e UPC-A
- Ricerca per Catalog Number (Cat#)
- Recupero: titolo, artista, etichetta, anno, tracklist, generi,
  formato, note di produzione, copertina

**Configurazione opzionale:** inserisci un token Discogs nella pagina
impostazioni del plugin per avere un rate limit più elevato (1 req/s
con token vs 1 req ogni 2,5 s senza).

### Plugin MusicBrainz

MusicBrainz è un database musicale open-source. Usato come fallback
quando Discogs non trova risultati. Integra anche il **Cover Art Archive**
per le copertine.

Attivalo da **Amministrazione → Plugin → MusicBrainz → Attiva**.

### Plugin Deezer

Deezer è ottimo per le copertine ad alta definizione e le tracklist
aggiornate. Si attiva da **Amministrazione → Plugin → Deezer Music
Search → Attiva**.

Recupera:
- Copertine album in alta risoluzione
- Tracklist completa con durata delle tracce
- Titolo album e nome artista

## Ricerca e filtri

Nella lista admin, usa il filtro **Tipo Media** per visualizzare solo i
dischi. Il filtro è accessibile dalla toolbar della lista libri.

Nella ricerca pubblica, i dischi vengono inclusi nei risultati insieme
ai libri. La pagina di dettaglio mostra l'icona del tipo media e usa le
etichette appropriate (Artista, Etichetta, Tracce).

## Export e import

I campi `tipo_media` e `barcode` sono inclusi nell'export CSV/TSV.
Nell'import CSV/TSV, la colonna `tipo_media` accetta i valori:
`libro`, `disco`, `audiolibro`, `dvd`, `altro`.

Il campo `barcode` viene mappato automaticamente a `isbn13` in
`IMPORT_COLUMN_MAP`.

## Schema.org

Le pagine pubbliche dei dischi utilizzano il markup Schema.org
`MusicAlbum` con i seguenti campi:

```json
{
  "@type": "MusicAlbum",
  "byArtist": "Nome Artista",
  "recordLabel": "Nome Etichetta",
  "numTracks": 12,
  "name": "Titolo Album"
}
```

Questo migliora l'indicizzazione nei motori di ricerca e la leggibilità
da parte di assistenti vocali e aggregatori.
