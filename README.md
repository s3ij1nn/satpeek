# SatPeek

[![CI](https://github.com/s3ij1nn/satpeek/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/s3ij1nn/satpeek/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel 11](https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel&logoColor=white)](composer.json)

A FaucetPay-payout PTC (paid-to-click) + URL-shortener earning site with intentionally adversarial captcha + bot detection. Built so that bot frameworks using 2captcha, hCaptcha-relay services, OpenRouter VLM, or self-hosted vision LoRAs cannot earn rewards.

## Quick start

```bash
docker compose up -d
# The app entrypoint runs composer install, copies .env from .env.example,
# generates APP_KEY, runs migrations, and seeds the database — no extra
# commands needed.

open http://localhost:8080
open http://localhost:8080/admin   # email: admin@satpeek.local  pw: admin123
curl http://localhost:8080/up      # JSON health check
```

Stack: PHP 8.3 + Laravel 11 + Filament 3, **PostgreSQL 16**, **Redis 7**, all wired via Docker Compose.

## What's in the box

User-facing:
- `/` Public landing
- `/login`, `/register` Auth (Laravel default + custom captcha gate)
- `/dashboard`, `/ptc`, `/shortlinks`, `/withdraw`, `/referral`
- `/ptc/auth/{token}`, `/shortlinks/auth/{token}` — per-click rotating
  landing URLs (28-char random slug, owner-scoped, single-use)
- `/advertise`, `/advertise/create`, `/advertise/{id}`, `/advertise/{id}/edit`
  — self-serve advertising (display_mode iframe vs new-tab toggle, post-launch edit)

Admin (`/admin`, Filament):
- User / BotScore / Withdrawal review / PtcAd / Shortlink
- Shortener APIs — paste API tokens for btcut.io / cuty.io / exe.io / shrtfly.com / ouo.io without touching `.env`
- Read-only PtcView + ShortlinkClick triage views with copyable auth tokens

API:
- `/api/captcha/issue`, `/api/captcha/verify`
- `/api/ptc/*`, `/api/shortlinks/*` — both legacy by-ID + new auth/{token} variants
- `/up` — structured JSON health (DB / Redis / MaxMind / shortlink + IP-reputation provider status)
- `/webhooks/bitcotask/{token}` — BitcoTask S2S callback

## Architecture highlights

- **Captcha** — `app/Captcha/TrajectoryTraceProvider.php` — moving-target trajectory trace, validated against shape (Frechet distance), Δt jitter, jerk entropy, completion dwell, fingerprint binding, and a hard `[800 ms, 60 s]` solve-time window. The 60 s ceiling rules out human-relay services (typical round-trip 30–90 s) while leaving headroom for an honest user to type credentials before dragging.
- **Per-click rotating URLs** — every PTC watch / shortlink click navigates to `/{ptc|shortlinks}/auth/{epoch_token}` where `epoch_token` is a fresh 28-char random. Owner-scoped (404 cross-user), single-use (410 once resolved). Token-keyed API endpoints share validation with the legacy by-numeric-id paths.
- **Shortlink rotation** — `app/Http/Controllers/Api/ShortlinkController.php` — every click re-shortens the canonical destination through the configured publisher API with a `?_r=…` cache-buster so providers (btcut/cuty/exe/shrtfly) that de-dup server-side mint a distinct slug per rotation. Failure degrades to the cached `target_url` rather than 500ing the click.
- **Bot scoring** — `app/BotDetection/ScoreEngine.php` — 9 weighted signals → tier (`trust` → `suspect` → `likely_bot` → `banned`). Includes JA4 family check (`Ja4Capture` middleware normalises `cf-ja4` / `x-tls-ja4` / `x-ja4` / `x-sp-ja4` into a canonical header) and a static `DATACENTER_ASNS` defence-in-depth signal alongside the live IPHub / ProxyCheck composite.
- **IP reputation** — `app/IpReputation/` — three providers behind `CompositeProvider` + `CachedProvider`: `MaxMindAsnProvider` (offline GeoLite2-ASN .mmdb lookup), `IpHubProvider`, `ProxyCheckProvider`. MaxMind queried first because it's local + sub-millisecond.
- **Offerwall integration** — `app/Offerwall/` — adapter pattern; `BitcoTaskAdapter` ships out of the box.
- **Shortener integration** — `app/Shortlinks/Providers/` — `GenericShortenerClient` (query-token, btcut/cuty/exe/shrtfly) and `OuoShortenerClient` (path-token, ouo). Adding a new query-family provider is one config entry.
- **Admin credential UI** — `app/Filament/Resources/ShortlinkProviderCredentialResource.php` — encrypted-at-rest tokens, "Test" action that probes the live API.
- **Payout** — `app/Payout/FaucetPayClient.php` — `POST /api/v1/send`, `requires_review` gate for `suspect+` tiers.
- **Health** — `app/Http/Controllers/HealthController.php` — `/up` returns 503 on critical down (DB / Redis), 200 + `status: degraded` on optional component issues, with stable detail codes for dashboards.

## Testing

```bash
docker compose exec app php artisan test
# 124 tests / 367 assertions
docker compose exec app php artisan test --testsuite=BotSimulation
```

`tests/BotSimulation/` is the security regression suite — it must stay green. Coverage spans:
- Trajectory captcha algorithm (replay / fast-script / 2captcha relay)
- Bot scoring + each individual signal (incl. ASN static list, JA4)
- IP reputation providers (MaxMind, IPHub, ProxyCheck, composite, cached)
- Shortener clients (generic + ouo), registry boot, credential override, rotation cache-buster
- PTC + shortlink auth-token landings (owner scoping, single-use, token-keyed API)
- Advertise display_mode + post-launch edit
- Admin debug resources (read-only enforcement)
- `/up` health payload contract
