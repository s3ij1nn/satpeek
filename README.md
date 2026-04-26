# SatPeek

A FaucetPay-payout PTC (paid-to-click) site with intentionally adversarial captcha + bot detection. Built so that bot frameworks using 2captcha, hCaptcha-relay services, OpenRouter VLM, or self-hosted vision LoRAs cannot earn rewards.

## Quick start

```bash
docker compose up -d
# The app entrypoint runs composer install, copies .env from .env.example,
# generates APP_KEY, runs migrations, and seeds the database — no extra
# commands needed.

open http://localhost:8080
open http://localhost:8080/admin   # email: admin@satpeek.local  pw: admin123
```

Stack: PHP 8.3 + Laravel 11 + Filament 3, **PostgreSQL 16**, **Redis 7**, all wired via Docker Compose.

## What's in the box

- `/` Public landing
- `/login`, `/register` Auth (Laravel default + custom captcha gate)
- `/dashboard`, `/ptc`, `/shortlinks`, `/withdraw`, `/referral` User flow
- `/admin` Filament admin (User / BotScore / Withdrawal review / Offer sync)
- `/api/captcha/issue`, `/api/captcha/verify` Custom captcha endpoints
- `/api/ptc/*`, `/api/shortlinks/*` PTC + shortlink claim flow
- `/webhooks/bitcotask/{token}` BitcoTask S2S callback

## Architecture highlights

- **Captcha** — `app/Captcha/TrajectoryTraceProvider.php` — moving-target trajectory trace, validated against shape (Frechet distance), Δt jitter, jerk entropy, completion dwell, fingerprint binding, and a hard `[800 ms, 25 s]` solve-time window.
- **Bot scoring** — `app/BotDetection/ScoreEngine.php` — 7 weighted signals → tier (`trust` → `suspect` → `likely_bot` → `banned`).
- **Offerwall integration** — `app/Offerwall/` — adapter pattern; `BitcoTaskAdapter` ships out of the box.
- **Payout** — `app/Payout/FaucetPayClient.php` — `POST /api/v1/send`.

## Testing

```bash
docker compose exec app php artisan test --testsuite=Unit
docker compose exec app php artisan test --testsuite=BotSimulation
```

`tests/BotSimulation/` is the security regression suite — it must stay green.
