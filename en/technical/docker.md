# Docker

Pinakes publishes an **official Docker image**, rebuilt automatically on every
stable release. It is the fastest way to try or host Pinakes without configuring
Apache/PHP by hand.

- Docker Hub (public): [`fabiodalez/pinakes`](https://hub.docker.com/r/fabiodalez/pinakes)
- GHCR: `ghcr.io/fabiodalez-dev/pinakes-docker`
- Tags: `latest` and every Pinakes version (e.g. `0.7.24`). Multi-arch:
  `linux/amd64` + `linux/arm64`.

> The image packages the application (GPL-3.0) and reuses the real Pinakes
> installer. The Docker packaging lives in the
> [pinakes-docker](https://github.com/fabiodalez-dev/pinakes-docker) repo.

## Quick start (docker compose)

The `pinakes-docker` repo provides a `docker-compose.yml` with app + MySQL and
the volumes already wired.

```bash
# 1. edit .env: ADMIN_EMAIL + ADMIN_PASSWORD (headless install),
#    strong DB passwords and a stable PLUGIN_ENCRYPTION_KEY
docker compose up -d
```

Open <http://localhost:8080> and sign in with the admin credentials you set.

> `docker compose up -d` pulls the published image. Add `--build` to build it
> locally from the `Dockerfile`.

## With `docker run` (external DB)

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

## Headless install vs wizard

- **Both `ADMIN_EMAIL` and `ADMIN_PASSWORD` set** → on first boot the container
  imports the schema, locale data, indexes, default settings and bundled plugins
  (including the default-active **Mobile API**), creates the admin user and
  writes the `.installed` lock. Pinakes is ready, **no web wizard**.
- **Either missing** → everything except the admin user is prepared: finish the
  single admin step at `/installer/`.

## Key variables

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | — | MySQL connection. |
| `DB_PORT` | `3306` | MySQL port. |
| `DB_SOCKET` | _(empty)_ | Optional unix socket (takes precedence over host/port). |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | _(empty)_ | Set **both** for a fully headless install. |
| `ADMIN_NAME` / `ADMIN_SURNAME` | `Admin` / `User` | Admin display name. |
| `PLUGIN_ENCRYPTION_KEY` | — | Keep a **stable** key (in `.env`) so encrypted plugin settings stay readable after a recreate. |

## Persistence

Use named volumes for `storage/` (sessions, logs, plugin data) and
`public/uploads/` (covers, uploaded files), plus the database volume. The
`docker-compose.yml` wires them all.

## Updates

Two paths:

1. **In-app updater** (simplest for most upgrades): *Admin → Updates*. It works
   inside the container (the code dir is writable and OPcache revalidates
   timestamps).
2. **Pull the image** at the new version:

   ```bash
   PINAKES_TAG=0.7.24 docker compose pull && docker compose up -d
   ```

Every stable Pinakes release rebuilds the image automatically (a
`repository_dispatch` from `create-release.sh` starts the multi-arch build to
GHCR and Docker Hub), so the `latest` and version tags always stay in sync.
