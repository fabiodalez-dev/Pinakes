# Docker

Pinakes pubblica un'**immagine Docker ufficiale**, aggiornata automaticamente ad
ogni release stabile. È il modo più rapido per provare o ospitare Pinakes senza
configurare manualmente Apache/PHP.

- Docker Hub (pubblica): [`fabiodalez/pinakes`](https://hub.docker.com/r/fabiodalez/pinakes)
- GHCR: `ghcr.io/fabiodalez-dev/pinakes-docker`
- Tag: `latest` e ogni versione di Pinakes (es. `0.7.24`). Multi-arch: `linux/amd64` + `linux/arm64`.

> L'immagine impacchetta l'applicazione (GPL-3.0) e riusa il vero installer di
> Pinakes. Il packaging Docker è mantenuto nel repo
> [pinakes-docker](https://github.com/fabiodalez-dev/pinakes-docker).

## Avvio rapido (docker compose)

Il repo `pinakes-docker` fornisce un `docker-compose.yml` con app + MySQL e i
volumi già cablati.

```bash
# 1. edita .env: ADMIN_EMAIL + ADMIN_PASSWORD (install headless),
#    password DB robuste e un PLUGIN_ENCRYPTION_KEY stabile
docker compose up -d
```

Apri <http://localhost:8080> e accedi con le credenziali admin impostate.

> `docker compose up -d` scarica l'immagine pubblicata. Aggiungi `--build` per
> costruirla in locale dal `Dockerfile`.

## Avvio con `docker run` (DB esterno)

```bash
docker run -d --name pinakes -p 8080:80 \
  -e DB_HOST=mydb.example.com -e DB_NAME=pinakes \
  -e DB_USER=pinakes -e DB_PASS='strong-pass' \
  -e ADMIN_EMAIL=admin@example.com -e ADMIN_PASSWORD='strong-admin-pass' \
  -e PLUGIN_ENCRYPTION_KEY="base64:$(openssl rand -base64 32)" \
  -v pinakes_storage:/var/www/html/storage \
  -v pinakes_uploads:/var/www/html/public/uploads \
  fabiodalez/pinakes:latest
```

## Install headless vs wizard

- **`ADMIN_EMAIL` e `ADMIN_PASSWORD` entrambi impostati** → al primo avvio il
  container importa schema, dati di localizzazione, indici, impostazioni di
  default e i plugin bundled (inclusa la **Mobile API**, attiva di default),
  crea l'utente admin e scrive il lock `.installed`. Pinakes è pronto, **senza
  wizard web**.
- **Uno dei due mancante** → viene preparato tutto tranne l'utente admin: completi
  il singolo passaggio admin su `/installer/`.

## Variabili principali

| Variabile | Default | Descrizione |
|---|---|---|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | — | Connessione MySQL. |
| `DB_PORT` | `3306` | Porta MySQL. |
| `DB_SOCKET` | _(vuoto)_ | Socket unix opzionale (ha precedenza su host/porta). |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | _(vuoto)_ | Imposta **entrambi** per l'install headless. |
| `ADMIN_NAME` / `ADMIN_SURNAME` | `Admin` / `User` | Nome visualizzato dell'admin. |
| `PLUGIN_ENCRYPTION_KEY` | — | Tieni una chiave **stabile** (in `.env`) così le impostazioni cifrate dei plugin restano leggibili dopo un recreate. |

## Persistenza

Usa volumi nominati per `storage/` (sessioni, log, dati plugin) e
`public/uploads/` (copertine, file caricati), oltre al volume del database. Il
`docker-compose.yml` li cabla già tutti.

## Aggiornamenti

Due strade:

1. **Updater in-app** (consigliato per la maggior parte dei casi): *Admin →
   Aggiornamenti*. Funziona dentro il container (la code dir è scrivibile e
   OPcache rivalida i timestamp).
2. **Pull dell'immagine** alla nuova versione:

   ```bash
   PINAKES_TAG=0.7.24 docker compose pull && docker compose up -d
   ```

Ogni release stabile di Pinakes ricostruisce automaticamente l'immagine (un
`repository_dispatch` da `create-release.sh` avvia la build multi-arch verso
GHCR e Docker Hub), quindi i tag `latest` e di versione sono sempre allineati.
