# SatPeek

PTC site (paid-to-click) with intentionally adversarial captcha + bot detection. FaucetPay payouts. No faucet feature (would bleed to bots).

## Stack

- PHP 8.3 + Laravel 11
- Filament 3 admin panel at `/admin`
- PostgreSQL 16 + Redis 7
- Docker Compose for everything (no host PHP/composer needed)

## Boot

```bash
docker compose up -d   # entrypoint handles composer install / .env / key:generate / migrate / seed
docker compose exec app php artisan test
open http://localhost:8080
```

Admin login (after seed): `admin@satpeek.local` / `admin123`.

The entrypoint serialises composer downloads (`COMPOSER_MAX_PARALLEL_HTTP=1`) to avoid macOS Docker volume races during the initial install. Seeding runs once per volume — `storage/.seeded` marks completion.

## Why this exists

`/Users/seiji/Development/viefaucet/` (sibling project) contains 4 PTC-automation bots that defeat hCaptcha, Turnstile, IconCaptcha, and custom click captchas via 2captcha + OpenRouter VLM + Florence-2 LoRA fine-tune. SatPeek is the inverse: a PTC platform whose captcha and bot scoring are designed so those exact attack patterns fail.

## Captcha — `app/Captcha/TrajectoryTraceProvider.php`

The "trajectory trace" challenge:
- Server issues a parametric curve (linear/sine/lissajous) bound to a per-issue seed.
- Client renders a moving target on `<canvas>`; user drags a token along the live target into a goal marker.
- Submit = an array of `{x, y, t, pressure}` samples (50–600 points).
- Server validates: shape (Frechet distance), Δt jitter, jerk-entropy, completion dwell, fingerprint match, response-time window `[800ms, 25000ms]`.

Why 2captcha cannot solve it:
1. No single PNG to relay — the target moves over time.
2. The answer is a continuous trajectory, not a coordinate.
3. The 25 s upper bound rules out any human relay.
4. Bezier-curve replay fails the jerk-entropy lower bound.
5. Florence-2 / VLM fine-tuning fails because every challenge has a fresh seed.

## Bot detection — `app/BotDetection/`

7 weighted signals → unit-interval risk score → tier policy:

| Tier | Range | Effect |
|------|-------|--------|
| `trust` | < 0.30 | normal flow |
| `suspect` | 0.30–0.60 | harder captcha, withdrawals reviewed |
| `likely_bot` | 0.60–0.85 | PTC blocked, withdrawals held |
| `banned` | ≥ 0.85 | account/IP/fingerprint blacklisted |

## Offerwall adapters — `app/Offerwall/`

`OfferwallAdapter` interface; concrete adapters for `MockAdapter` (development) and `BitcoTaskAdapter` (production). Add new networks (AdGate, Adscend, CPALead) by implementing the interface and registering in `AppServiceProvider`. Sync via `php artisan satpeek:sync-offerwalls` (cron every 15 min).

## Payout — `app/Payout/FaucetPayClient.php`

Calls `POST {FAUCETPAY_API_BASE}/send`. Withdrawals enter `withdrawals` table → `ProcessWithdrawalJob` runs from the queue, with `requires_review` for `suspect+` tiers held for admin approval in Filament.

## Tests

- `tests/Unit/Captcha/TrajectoryVerifierTest.php` — locks down the captcha algorithm against bezier replay, fast-script attempts, and 2captcha-style relays.
- `tests/Unit/BotDetection/ScoreEngineTest.php` — weighted scoring + tier promotion to banned.
- `tests/BotSimulation/PlaywrightHeadlessTest.php` — synthetic uniform-Δt CDP-style attacks must be rejected.

CI must keep `tests/BotSimulation/` green — that is how captcha strength is measured over time.

## Open follow-ups

- Confirm BitcoTask publisher API endpoint shape and S2S signature scheme.
- Wire JA4 fingerprint capture (Nginx or upstream Cloudflare).
- Connect ASN datacenter lookup to maxmind / ipinfo.
