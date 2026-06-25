# Mobile API e app Android

Dalla **0.7.21** Pinakes include una **Mobile API** REST versionata (`/api/v1`)
e una **app Android** companion gratuita che la consuma. Ogni biblioteca può
puntare l'app al proprio URL e consegnarla ai soci.

## Il plugin Mobile API

Un plugin bundled (**Mobile API**) espone `/api/v1`:

- **Discovery** (`/health`) — l'app scopre nome, lingua, modalità catalogo e
  disponibilità push dell'istanza.
- **Login** email/password con **token bearer** per dispositivo.
- **Catalogo:** ricerca, sfoglia, disponibilità reale, scheda libro.
- **Prestiti e prenotazioni:** richiesta, stato, restituzione (stessi vincoli del
  sito: overlap, max prestiti attivi, code).
- **Wishlist**, **profilo**, **messaggi**.
- **Notifiche push** (facoltative) e **streaming** di ebook/audiolibri.

L'API è server-agnostica e si adatta alle impostazioni dell'istanza (lingua,
modalità solo-catalogo, disponibilità push).

### Abilitazione

- **Installazione nuova:** il plugin è **attivo di default**, `/api/v1` funziona
  subito.
- **Aggiornamento:** parte **disattivato** per non cambiare comportamento ai siti
  esistenti. Attivalo da **Admin → Plugin → Mobile API**.

> Anche con il plugin attivo, l'accesso dell'app è regolato da un secondo
> interruttore nelle impostazioni del plugin (vedi sotto). Se l'app riceve
> *"l'accesso da app mobile è disattivato su questa biblioteca"*, abilita
> l'accesso lì.

### Impostazioni del plugin

Da **Admin → Plugin → Mobile API → Impostazioni**:

- **Accesso app mobile** — interruttore che abilita l'autenticazione dell'app.
  Quando è disattivato l'app non può autenticarsi; `/api/v1/health` e la
  documentazione `/api/v1/docs` restano comunque raggiungibili.
- **Notifiche push** (facoltative) — provider **UnifiedPush** (consigliato,
  nessuna credenziale centrale) o **Firebase Cloud Messaging** (sperimentale),
  con eventuale soggetto VAPID. Senza credenziali l'app riceve comunque le
  notifiche tramite il feed in-app (polling): le push non bloccano mai il
  funzionamento.
- **Dispositivi** — elenco dei dispositivi attivi con possibilità di **revocare**
  un token (invalidato immediatamente).

L'API richiede **HTTPS** (eccetto loopback in sviluppo).

## L'app Android

La **[Pinakes Android](https://github.com/fabiodalez-dev/Pinakes-Android)** è
nativa (Kotlin / Jetpack Compose, Material 3). Dall'app il socio:

- inserisce l'**URL della biblioteca** e accede (o si registra / recupera la
  password);
- sfoglia il catalogo, controlla la disponibilità reale, prende in prestito e
  prenota;
- legge ebook / ascolta audiolibri e gestisce i propri prestiti.

Note di sicurezza: il token bearer è custodito in `EncryptedSharedPreferences`;
il traffico in chiaro (HTTP) è ammesso solo per loopback e l'emulatore — le
istanze reali vanno servite in **HTTPS**.

Un **APK** prebuilt è pubblicato sulla
[pagina Releases dell'app](https://github.com/fabiodalez-dev/Pinakes-Android/releases).

> La modalità **solo catalogo** dell'istanza si riflette nell'app: nasconde le
> azioni di prestito/prenotazione, lasciando la sola consultazione del catalogo.
