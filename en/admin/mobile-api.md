# Mobile API and Android app

Since **0.7.21** Pinakes ships a versioned **Mobile API** (`/api/v1`) and a free
companion **Android app** that consumes it. Any library can point the app at its
own instance URL and hand it to members.

## The Mobile API plugin

A bundled plugin (**Mobile API**) exposes `/api/v1`:

- **Discovery** (`/health`) — the app learns the instance name, language,
  catalogue-only mode and push availability.
- **Login** with email/password and a per-device **bearer token**.
- **Catalog:** search, browse, real availability, book detail.
- **Loans and reservations:** request, status, return (same rules as the
  website: overlap, max active loans, queues).
- **Wishlist**, **profile**, **messages**.
- Optional **push notifications** and **streaming** of ebooks/audiobooks.

The API is server-agnostic and adapts to each instance's settings (language,
catalogue-only mode, push availability).

### Enabling it

- **Fresh install:** the plugin is **active by default**, `/api/v1` works
  immediately.
- **Upgrade:** it ships **disabled** so existing sites don't change behaviour.
  Enable it from **Admin → Plugins → Mobile API**.

> Even with the plugin active, app access is governed by a second switch in the
> plugin settings (below). If the app reports *"mobile app access is disabled on
> this library"*, enable access there.

### Plugin settings

From **Admin → Plugins → Mobile API → Settings**:

- **Mobile app access** — switch that enables app authentication. While off, the
  app cannot authenticate; `/api/v1/health` and the `/api/v1/docs` documentation
  stay reachable.
- **Push notifications** (optional) — **UnifiedPush** (recommended, no central
  credential) or **Firebase Cloud Messaging** (experimental), with an optional
  VAPID subject. Without credentials the app still receives notifications through
  the in-app feed (polling): push never blocks operation.
- **Devices** — list of active devices with the ability to **revoke** a token
  (invalidated immediately).

The API requires **HTTPS** (except loopback during development).

## The Android app

**[Pinakes Android](https://github.com/fabiodalez-dev/Pinakes-Android)** is native
(Kotlin / Jetpack Compose, Material 3). From the app a member:

- enters the **library URL** and signs in (or registers / recovers the password);
- browses the catalog, checks real availability, borrows and reserves;
- reads ebooks / listens to audiobooks and manages their loans.

Security notes: the bearer token is kept in `EncryptedSharedPreferences`;
cleartext HTTP is allowed only for loopback and the emulator — real instances
must be served over **HTTPS**.

A prebuilt **APK** is published on the
[app's Releases page](https://github.com/fabiodalez-dev/Pinakes-Android/releases).

> The instance's **catalogue-only mode** is reflected in the app: it hides
> loan/reservation actions, leaving catalog browsing only.
