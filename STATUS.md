# Mobile API — build status (branch `feature/mobile-api`)

Honest state for the morning review. Built per `MOBILE_API_SPEC.md`. The
background workflow was interrupted mid-run (session reload); the finishing
work — SSRF hardening, PHPStan, the full test pass, and this report — was
completed by hand afterwards.

## Verification (run on Apache :8081 + MySQL)

- **PHPStan level 5**: clean on the whole plugin + `app/Support/HttpClient.php` — **0 errors**.
- **E2E suite** (`tests/mobile-api.spec.js`, 73 tests): **72 passed, 1 skipped**.
  - The 1 skip is test 62 (login rate-limit → 429). It self-skips because the dev/E2E
    server runs `PINAKES_E2E_BYPASS_RATE_LIMIT=1` (`SetEnv` in `pinakes.conf`), which
    the broader serial suite needs so its many logins don't saturate the bucket. The
    limiter **is** wired on the route; the test still asserts 429 on a server without
    the bypass.

## Complete (implemented + tested)

| Area | Notes |
|---|---|
| Plugin scaffold | `storage/plugins/mobile-api/`, default-inactive, `ensureSchema()` from both `onActivate()`+`onInstall()`, 5 tables. Registered in `BundledPlugins` + `create-release*.sh` + `.gitignore`. |
| Health / discovery | `GET /api/v1/health` — identity, version, api_version, feature flags, `app_access_enabled`, `registration_enabled`, `private_mode`, https warning. |
| Token auth | login (throttled), register (respects instance toggle), forgot-password, logout, bearer `AppAuthMiddleware` (sha256 + `hash_equals`), per-token quotas. Tokens stored hashed. |
| Devices | list + revoke single, own-only. |
| Catalog | search (text/author/publisher/genre-cascade/language/available), cursor pagination, ETag/304, `GET /catalog/books/{id}` full detail + personal history, `GET /catalog/genres` tree. |
| User actions | loans, reservations (create + cancel, reuse core overlap/availability rules), wishlist add/remove, profile GET/PATCH, change password, send contact message, in-app notifications feed. |
| Data isolation | tested: user B cannot read A's loans/wishlist/devices nor cancel A's reservation. Soft-delete (`deleted_at IS NULL`) enforced + tested. |
| Push (registration/prefs) | subscribe/unsubscribe per device, per-type prefs + quiet hours, NullProvider fallback when unconfigured (never hard-fails). |
| Docs | OpenAPI 3.1 at `/api/v1/openapi.json`, Swagger UI at `/api/v1/docs`, admin settings UI tab. |
| i18n | all new strings in **all 4** locales (it/en/fr/de). |

## Security — SSRF hardening (addresses the background review HIGH finding)

The push endpoint is user-supplied and the server POSTs to it → SSRF surface.
Fixed by **reusing `app/Support/SsrfGuard.php`** (the same guard built for covers):

- **Registration** (`PushController::isSafePushEndpoint`): https only, no userinfo,
  no bare-IP literal, port 443 only, and host must resolve where **every** A/AAAA
  record is public (blocks loopback/RFC1918/link-local/CGNAT/ULA/NAT64/IPv4-mapped).
- **Send** (`UnifiedPushProvider::send`): re-resolves to a public IP and **pins** the
  connection to it (TOCTOU/DNS-rebind defense) via a new additive `pin_ip` option on
  `HttpClient` (Guzzle `CURLOPT_RESOLVE`), with `max_redirects=0` so a 302 can't bypass.

## Partial / caveats (honest)

- **VAPID signing is advisory-only.** `UnifiedPushProvider` delivers the WebPush POST
  but does not compute a real VAPID JWT (sent as an advisory `X-Push-Subject` header).
  Distributors that strictly require VAPID will reject; most self-hosted ones accept.
  Real JWT signing is the main push TODO.
- **`FcmProvider` is a stub** (returns skipped). UnifiedPush is the primary path per
  the chosen design; FCM HTTP v1 is left as a follow-up.
- **`registration_enabled` in /health** falls back to "open unless private mode" — core
  has no single master registration switch today.
- **Push cron dispatch** is wired into `MaintenanceService` via the `mobile_api.dispatch_push`
  hook (after email reminders, failure-swallowed). The end-to-end *delivery* on the cron
  pass is not exercised by the E2E suite (no live push endpoint in CI) — registration,
  prefs, and the dispatcher unit are covered; live delivery is trusted, not tested.

## TODO (future, out of this branch)

- Real VAPID JWT signing; full FCM HTTP v1 provider.
- Android app (separate repo) consuming this API.
- An E2E that drives the cron push dispatch against a capturing endpoint.

## What to review first

1. **Security**: `PushController::isSafePushEndpoint`, `UnifiedPushProvider::send`,
   the `HttpClient` `pin_ip` option — confirm the SSRF model is airtight.
2. **Data isolation**: every authed query is scoped by the token-resolved user id.
3. **The 3 test-contract fixes** (cursor meta, `personal_history` `has_*` naming,
   push endpoint host) — confirm the canonical contract is what you want.
