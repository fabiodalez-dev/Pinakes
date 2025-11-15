# Scraping Pro (LibreriaUniversitaria + Feltrinelli)

Plugin ufficiale “Scraping Pro” per integrare lo scraping HTML di [LibreriaUniversitaria.it](https://www.libreriauniversitaria.it) e le copertine da [laFeltrinelli.it](https://www.lafeltrinelli.it) nel nuovo sistema a plugin.

## Funzionalità

- Recupero di titolo, sottotitolo, autori, collana, tipologia, descrizione, prezzo, pagine e altri metadati partendo dall'ISBN
- Download della copertina direttamente da laFeltrinelli (con fallback sull'immagine presente su LibreriaUniversitaria)
- Rispetto dell'intera pipeline di hook (`scrape.before`, `scrape.parse`, `scrape.data.modify`, ecc.) per mantenere compatibilità con personalizzazioni esistenti
- Priorità più alta rispetto a Open Library: se entrambi i plugin sono attivati, questo fornisce i dati principali e Open Library interviene solo come fallback
- Validazioni SSRF e limitazioni sulle redirezioni per maggior sicurezza

## Hook registrati

| Hook | Tipo | Priorità | Descrizione |
| ---- | ---- | -------- | ----------- |
| `scrape.sources` | Filter | 2 | Aggiunge le fonti `libreriauniversitaria` e `feltrinelli_cover` |
| `scrape.fetch.custom` | Filter | 2 | Implementa l'intera logica di scraping HTML + cover |

## Requisiti

- PHP >= 7.4
- Estensioni cURL e DOM abilitate
- Sistema plugin già inizializzato (HookManager e PluginManager)

## Installazione

1. Comprimi la cartella del plugin in un file zip (vedi pacchetto `LibreriaUniversitariaFeltrinelli-v1.0.0.zip` già pronto nella root del progetto)
2. Entra in **Admin → Plugin → Carica Plugin** e carica lo zip
3. Attiva il plugin dalla stessa schermata

Una volta attivato il plugin, il pulsante "Importa da ISBN" comparirà automaticamente nelle viste di creazione e modifica libro (se almeno un plugin di scraping è attivo).

## Configurazione

Il plugin non richiede configurazioni aggiuntive. Tutte le personalizzazioni (proxy, header extra, cache, ecc.) possono essere gestite tramite gli hook già esistenti (`scrape.http.options`, `scrape.data.modify`, ...).

## Log

Gli eventi principali del plugin vengono registrati attraverso i log standard (`error_log`) e tramite gli hook `scrape.error`/`scrape.validation.failed` per consentire l'aggancio di sistemi di monitoraggio esterni.
