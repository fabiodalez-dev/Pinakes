# Mobile API — Architecture & Build Contract

> Authoritative spec for the **Mobile API plugin**: a secure, per-user REST API
> that lets a native app (Android first) connect to any Pinakes instance by URL,
> log in with email+password, and use search, reservations/loans, wishlist,
> profile, messaging, and native push notifications.
>
> This document is the contract the build follows. Decisions below were taken by
> the maintainer; do not relitigate them — implement them.

## Goal

Expose **everything a logged-in library user can do on the web** through a
versioned REST API, so a native app can deliver: catalog search, book detail,
loan/reservation requests, wishlist, profile, contact messaging, and push
notifications (loan due, overdue, reservation ready, new message, book back in
stock). The app stores the instance URL + a long-lived per-device token.

## Decisions (locked)

| Area | Decision |
|---|---|
| Auth model | **Opaque, revocable bearer tokens**, one per device, **hashed at rest** (store only a hash; compare with `hash_equals`). Reuse `RememberMeService` patterns. |
| Token lifetime | **Single long-lived token** per device (no refresh flow). Revocable individually. Optional far-future expiry; revocation is the primary control. |
| Delivery | A **bundled plugin** `mobile-api` (toggle on/off per instance). Mirror `storage/plugins/oai-pmh-server` and `ncip-server` structure. |
| Enable gating | A dedicated **"App access" setting** (`mobile_api.enabled`), separate from the existing `api.enabled` key. Default **off** until configured. |
| Registration | Self-registration from the app **respects the instance registration toggle**; **email verification reuses the existing web flow**. |
| Onboarding | Public **`GET /api/v1/health`** discovery endpoint: returns library name, logo URL, API version, feature flags, `app_access_enabled`, `registration_enabled`. |
| Versioning | Namespace **`/api/v1/...`**. Health advertises API version + feature flags for forward-compat. |
| Envelope | Every response is `{ "data": ..., "meta": {...}, "error": null }` or `{ "data": null, "meta": {...}, "error": { "code": "...", "message": "..." } }`. |
| Pagination | **Cursor-based** for catalog/lists: `meta.next_cursor` (opaque), `?cursor=...&limit=...`. |
| Localization | Strings in the **instance locale** (decided at install); **dates ISO-8601 UTC**; the app formats locally. |
| Write actions | reserve / request loan, **cancel reservation**, wishlist add/remove, **edit profile + change password**, **send contact message**. |
| Search filters | text (title/author/keyword), author/publisher, **genre cascade (3 levels) + language**, **availability (loanable now)**. |
| Book detail | **Full** payload (availability, copies, shelf/location, absolute cover URL, full metadata, related) **+ personal history** (has the user read/reserved/wishlisted it). |
| Caching | **ETag / Last-Modified** + cache headers on read endpoints; honor `If-None-Match` → 304. |
| Push transport | **UnifiedPush** primary (the library manager self-registers a provider and creates the credentials — minimal setup). Behind a **pluggable `PushProvider` abstraction** (UnifiedPush impl now; FCM impl optional/stub). |
| Push config | Admin pastes provider credentials in settings; **if absent → graceful fallback to polling / in-app notifications**. Never hard-fail. |
| Push triggers | loan due-soon, loan overdue, reservation approved/ready-for-pickup, new message/admin reply, **book back available** (notify users who wishlisted/reserved a now-available title). |
| Push prefs | **Per-type toggles + quiet hours**, managed from the app. |
| Transport | **HTTPS enforced**; reject `http` except `localhost`/loopback (dev). The health endpoint warns if not https. |
| Devices | **List active devices + revoke individually** from profile (name, last-seen). Admin can revoke too. |
| Data isolation | **Respect `PrivateModeMiddleware`**; each user sees **only their own** loans/reservations/wishlist/history; **soft-deleted books never exposed** (`AND deleted_at IS NULL`); staff role separation. |
| Rate limiting | Reuse `RateLimitMiddleware`: **strong throttle on login** (anti brute-force) + **per-token quotas** on other endpoints. |
| Deliverables | Plugin (endpoints, token auth, push, settings UI) + **OpenAPI 3.1** at `/api/v1/openapi.json` + **Swagger UI** at `/api/v1/docs` + **full E2E API test suite** + **i18n in all 4 locales** (it/en/fr/de, Italian as source). |
| Handover | **Branch `feature/mobile-api` + open PR, NOT merged, CI green**, self-review applied, honest `STATUS.md`. No Android code in this repo (separate repo later). |

## Data model (new tables — via plugin `ensureSchema()`, idempotent `CREATE TABLE IF NOT EXISTS`)

- `mobile_app_tokens` — `id`, `user_id` (FK), `token_hash` (unique, sha256), `device_name`, `device_id`, `platform`, `created_at`, `last_used_at`, `revoked_at` NULL, `expires_at` NULL.
- `mobile_push_subscriptions` — `id`, `user_id`, `token_id` (FK → mobile_app_tokens, cascade), `provider` (`unifiedpush`|`fcm`), `endpoint`/`registration_id`, `public_key`/`auth` (for UnifiedPush WebPush), `created_at`, `last_ok_at`, `failure_count`.
- `mobile_push_prefs` — `user_id` (PK), `loan_due` bool, `loan_overdue` bool, `reservation_ready` bool, `new_message` bool, `book_available` bool, `quiet_start` time NULL, `quiet_end` time NULL.
- `mobile_availability_watchers` — `id`, `user_id`, `libro_id`, `created_at` — who to notify when a title is loanable again (from wishlist/reservation intent).

All FKs respect existing `utenti`/`libri` schema. Follow the soft-delete rule on every `libri` query.

## Endpoint manifest (`/api/v1`)

**Public (no token):**
- `GET /health` — discovery: `{ name, logo, version, api_version, features{...}, app_access_enabled, registration_enabled, private_mode }`.
- `GET /openapi.json` — OpenAPI 3.1 document.
- `GET /docs` — Swagger UI page.
- `POST /auth/login` — `{ email, password, device_name, device_id, platform }` → `{ token, user{...} }`. Throttled.
- `POST /auth/register` — only if instance registration enabled; reuses web validation + email verification.
- `POST /auth/forgot-password` — reuse web reset flow.

**Authenticated (Bearer token; `AppAuthMiddleware`):**
- `POST /auth/logout` — revoke current token.
- `GET /me` — profile. `PATCH /me` — edit profile. `POST /me/password` — change password.
- `GET /me/devices` — list devices. `DELETE /me/devices/{id}` — revoke a device.
- `GET /catalog/search` — filters: `q`, `author`, `publisher`, `genre` (cascade id), `language`, `available` (bool); cursor pagination.
- `GET /catalog/books/{id}` — full detail + personal history.
- `GET /catalog/genres` — genre cascade tree (for filter UI).
- `GET /me/loans` — own loans (active + history). `GET /me/reservations`.
- `POST /reservations` — request a loan/reservation (honor existing overlap/availability rules). `DELETE /reservations/{id}` — cancel own pending reservation.
- `GET /me/wishlist`. `POST /me/wishlist` `{book_id}`. `DELETE /me/wishlist/{book_id}`.
- `POST /messages` — send a contact message (same as web contact form).
- `GET /me/notifications` — in-app notification feed (fallback when push off). 
- `PUT /me/push/prefs` — set per-type prefs + quiet hours. `GET /me/push/prefs`.
- `POST /me/push/subscribe` — register a UnifiedPush/FCM endpoint for the current device. `DELETE /me/push/subscribe`.

## Auth flow

1. App collects instance URL → calls `GET /health` → shows library identity + checks `app_access_enabled` and `https`.
2. `POST /auth/login` with credentials + device info → server validates against `utenti` (same hashing as web), issues a random 256-bit token, stores **only its sha256 hash** in `mobile_app_tokens`, returns the plaintext token once.
3. App sends `Authorization: Bearer <token>` on every call. `AppAuthMiddleware` hashes the presented token, looks it up (not revoked, not expired), sets the authenticated user, updates `last_used_at`.
4. Logout / device revoke sets `revoked_at`.

## Push architecture

- `PushProvider` interface: `send(subscription, payload): bool`. Implementations: `UnifiedPushProvider` (WebPush to the user-provided endpoint), `FcmProvider` (HTTP v1 — optional/stub). Selected by instance settings; if no credentials → `NullProvider` and the dispatcher logs + relies on in-app feed/polling.
- **Hook into the existing loan/notification scheduler** (the cron that already sends email reminders): for each due/overdue/reservation/message/availability event, also enqueue a push to subscribed devices whose `mobile_push_prefs` allow it and outside quiet hours.
- `book back available`: when a copy returns and a title has `mobile_availability_watchers`, notify them and clear the watcher.

## Security & isolation (non-negotiable)

- HTTPS enforced (except loopback). All token comparison via `hash_equals`.
- Every `libri` query: `AND deleted_at IS NULL`.
- Users access only their own rows (loans/reservations/wishlist/devices/prefs); never another user's `user_id`.
- Respect `PrivateModeMiddleware` for catalog reads.
- Reuse `RateLimitMiddleware` (login: tight; others: per-token quota).
- `\Throwable` not `\Exception`; `SecureLogger` for sensitive logs; JSON error bodies never leak internals.

## Conventions (from CLAUDE.md — MANDATORY)

- Plugin schema rule: `ensureSchema()` called from **both** `onActivate()` and `onInstall()`; `CREATE TABLE IF NOT EXISTS`; throw `\RuntimeException` on failure in `onActivate()`.
- Plugin `onActivate()` must NOT call `HookManager::doAction()/applyFilters()` (only `registerHookInDb()`).
- Admin/settings routes are **English literals** via `url('/admin/...')` — never `route_path()`.
- View escaping: `htmlspecialchars(url(...), ENT_QUOTES, 'UTF-8')`; `json_encode(..., JSON_HEX_TAG)` for PHP→JS.
- i18n: every user-facing string via `__()`, Italian as source, key added to **all 4** locale JSONs in the same change.
- PHPStan **level 5** must pass (pre-commit gate). Migration file version ≤ release version if any migration is added.

## Testing

- E2E API suite (Playwright `request` context, like the repo's other specs, gated on creds, `--workers=1`, run via `/tmp/run-e2e.sh`): login→token, bad creds rejected, token revoke, search + each filter, book detail + personal history, reserve + cancel, wishlist add/remove, send message, push subscribe + prefs, **data isolation** (user A cannot read user B's loans/wishlist/devices), HTTPS/`http` rejection, rate-limit on login.

## Handover

- Build on `feature/mobile-api`. Final commit(s) clean (no AI attribution). Open a PR to `main`, **not merged**.
- Write `STATUS.md`: what is complete / partial / TODO, honestly. Priority order if time-bound: **core (auth, health, catalog, loans/reservations, wishlist, profile, isolation, tests) first**, then messaging, then push (UnifiedPush), then Swagger UI polish.
