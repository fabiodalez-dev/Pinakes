# Mobile API — build status (branch `feature/mobile-api`)

Honest state for the morning review. Built per `MOBILE_API_SPEC.md`. The
background workflow was interrupted mid-run (session reload); the finishing
work — SSRF hardening, PHPStan, the full test pass, and this report — was
completed by hand afterwards.

## Verification (run on Apache :8081 + MySQL)

- **PHPStan level 5**: clean on the whole plugin + `app/Support/HttpClient.php` — **0 errors**.
- **E2E suite** (`tests/mobile-api.spec.js`, 74 tests): **73 passed, 1 skipped**.
  - The 1 skip is test 62 (login rate-limit → 429). It self-skips because the dev/E2E
    server runs `PINAKES_E2E_BYPASS_RATE_LIMIT=1` (`SetEnv` in `pinakes.conf`), which
    the broader serial suite needs so its many logins don't saturate the bucket. The
    limiter **is** wired on the route; the test still asserts 429 on a server without
    the bypass.

## Post-review fixes (applied)

Findings from the multi-lens review on PR #177, fixed:
- **i18n gap (was blocking):** all 61 missing API `__()` strings (auth, token, rate-limit, HTTPS, loan/reservation/wishlist/profile/message errors) added to **all 4** locales (it source + en/fr/de translations).
- **`password_confirm` dead guard:** `register` + `changePassword` no longer default the confirm field to the password — the mismatch check is real again.
- **`book_available` notification durability:** the watcher is now cleared only **after** a push is actually delivered (kept on NullProvider/quiet-hours/no-subscription), and `book_available` is now emitted by `GET /me/notifications` — so a push-off user still sees it.
- **Quiet-hours dedup:** the pref + quiet-hours gate (`shouldNotify`) now runs **before** the `mobile_push_log` claim, so a quiet-hours pass no longer burns the dedup and the push re-fires on a later pass.
- **Stateless-session safety:** `AppAuthMiddleware` mirrors the token identity into `$_SESSION['user']` for the request only and **restores** the prior value afterwards (no clobbering a concurrent web session).
- **mysqli correctness:** `$stmt->affected_rows` (not connection-level) at 4 sites incl. the push `claim()` and token revoke; `get_result()` null-checked before `->num_rows` at 2 sites.
- **Docs + a11y:** stale docblocks corrected; settings-view flash banners get `role`/`aria-live`, the Revoke button a per-device `aria-label`, and the actions column an sr-only label.

Known/deferred (low, noted on the PR): `new_message` is a wired-but-unfired push trigger (no producer yet); `X-Forwarded-Proto` is honoured only when the TCP peer is in `TRUSTED_PROXIES` (otherwise the real scheme is used, so the header can't be spoofed); `HttpClient` IP-pin is curl-only.

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
- **HTTPS enforcement** (`HttpsEnforceMiddleware`): the dev loopback exemption is decided
  from the real TCP peer (`REMOTE_ADDR`), not the client-controllable `Host` header — a
  remote `Host: localhost` can no longer bypass HTTPS and leak a token in cleartext.
  (Fixes a background-review MEDIUM finding on the pushed commits.)

## VAPID signing — DONE (RFC 8292)

Real ES256 VAPID signing is implemented (`src/Push/VapidSigner.php`), no external
dependency (raw OpenSSL):

- A P-256 keypair is auto-generated per instance and stored (public plain, **private
  encrypted** via `SettingsEncryption`); the public key is exposed at `/health`
  (`vapid_public_key`, the app's `applicationServerKey`).
- Each push carries a signed `Authorization: vapid t=<JWT>, k=<pub>` header bound to
  the endpoint origin, plus `Crypto-Key: p256ecdsa=…` for older servers. Signing is
  best-effort — a failure never blocks delivery.
- Verified end-to-end: the generated ES256 signature **validates** against the public
  key (DER→raw R||S conversion confirmed); E2E test asserts `/health` advertises the key.

## Partial / caveats (honest)

- **Web Push payload encryption (RFC 8291) is NOT done.** The JSON payload is sent
  unencrypted. UnifiedPush distributors that accept application payloads (the common
  self-hosted case: ntfy/NextPush) deliver it; ECDH/HKDF encryption is a follow-up if
  end-to-end-encrypted Web Push is required.
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
