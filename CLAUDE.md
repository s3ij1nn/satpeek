# SatPeek

PTC site (paid-to-click) + URL-shortener interstitial earnings, with intentionally adversarial captcha + bot detection. FaucetPay payouts. No faucet feature (would bleed to bots).

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
- Submit = an array of `{x, y, t, pressure}` samples (20–2000 points).
- Server validates: shape (Frechet distance), Δt jitter, jerk-entropy, completion dwell, fingerprint match, response-time window `[800 ms, 60 s]`.

The 60 s solve window is wide enough that an honest user can type credentials, drag the captcha (6–10 s), and submit without false-rejecting as `too_slow_relay`; it still rules out human-relay services (round-trip 30–90 s). The 2000-point cap accommodates high-DPI mice on long curves; the bot ceiling is enforced via `dt_jitter` / `jerk_entropy`, not raw sample count.

Why 2captcha cannot solve it:
1. No single PNG to relay — the target moves over time.
2. The answer is a continuous trajectory, not a coordinate.
3. The 60 s upper bound rules out any human relay round-trip.
4. Bezier-curve replay fails the jerk-entropy lower bound.
5. Florence-2 / VLM fine-tuning fails because every challenge has a fresh seed.

## Per-click rotating auth URLs — `/ptc/auth/{token}` + `/shortlinks/auth/{token}`

Both the PTC viewer and the shortlink hold/claim screen live behind a 28-character random URL slug minted at the moment the user clicks "Watch" / "Open & hold". Each click → fresh slug → unique URL.

Properties:
- Slug entropy: `pv_` / `sc_` + 28 chars lowercase alphanumeric ≈ 145 bits — bulk URL probing infeasible.
- **Owner-scoped** — landing page returns 404 for any user that doesn't own the click/view.
- **Single-use** — once `status` flips out of `pending`, the page returns 410 (no replay).
- Token-keyed API endpoints (`/api/ptc/auth/{token}/heartbeat|complete`, `/api/shortlinks/auth/{token}/complete`) wrap the same `runHeartbeat()` / `finishView()` helpers as the legacy by-numeric-id endpoints; both stay live for backward compat. The frontend flips to the token-keyed paths the moment a token is available so the predictable numeric ID never leaks onto the wire.
- Legacy `/ptc/{id}` entry → on Open-ad click, JS does `history.replaceState` to `/ptc/auth/{token}` so the predictable URL only flashes briefly.

Triage: the read-only `PtcView` + `ShortlinkClick` Filament resources (Operations group) expose a copyable token + an "Auth URL" row action that opens the user-facing page in a new tab. Both resources are read-only — `canCreate/canEdit/canDelete` return false because mutating a row would side-step the captcha + heartbeat + duration guards.

## Bot detection — `app/BotDetection/`

9 weighted signals → unit-interval risk score → tier policy:

| Signal | Source | Weight |
|---|---|---|
| `response_time` | challenge issue → submit interval | 0.20 |
| `trajectory_entropy` | jerk entropy across recent traces | 0.20 |
| `failure_rate` | rolling captcha rejects | 0.15 |
| `fingerprint_consistency` | browser fingerprint stability | 0.15 |
| `tls_fingerprint` | JA4 family vs claimed UA | 0.10 |
| `heartbeat_gap` | PTC heartbeat cadence outliers | 0.10 |
| `asn_datacenter` | live IpReputation composite (datacenter / vpn / proxy) | 0.10 |
| `asn_static_list` | operator-curated `DATACENTER_ASNS` env list | 0.05 |

ScoreEngine renormalises by total weight, so adding signals doesn't mute the others.

| Tier | Range | Effect |
|------|-------|--------|
| `trust` | < 0.30 | normal flow |
| `suspect` | 0.30–0.60 | harder captcha, withdrawals reviewed |
| `likely_bot` | 0.60–0.85 | PTC blocked, withdrawals held |
| `banned` | ≥ 0.85 | account/IP/fingerprint blacklisted |

### JA4 capture — `app/Http/Middleware/Ja4Capture.php`

Global middleware normalises upstream JA4 headers in priority order (`cf-ja4` > `x-tls-ja4` > `x-ja4` > `x-sp-ja4`) into a canonical `X-SP-JA4` and validates the shape (`^[a-z0-9]{6,20}_[a-f0-9]{12}_[a-f0-9]{12}$`). Garbage from spoofing clients is dropped silently. `ChallengeBuilder` reads only the canonical header so app-layer code is transport-agnostic. See `docker/nginx/satpeek.conf` for the two production paths (Cloudflare orange-cloud auto, or `nginx-ja4` module for direct termination).

### IP reputation — `app/IpReputation/`

`IpReputationProvider` interface, three concrete implementations:
- **MaxMindAsnProvider** — local GeoLite2-ASN `.mmdb` lookup. Lazy-loaded, memoised, file-missing degrades to null. Operator supplies the `.mmdb` file (MaxMind license forbids redistribution); set `MAXMIND_ASN_DB` to the path.
- **IpHubProvider** — paid API, full proxy/vpn classification.
- **ProxyCheckProvider** — paid API, supports anonymous queries on a smaller quota.

Composed via `CompositeProvider` (first non-null verdict wins, MaxMind registered first because it's local + sub-millisecond) wrapped in `CachedProvider`. Local/testing env binds a MaxMind-only composite when the file is present, else `NullProvider`.

`AsnStaticListSignal` piggybacks on the same provider cache to compare returned ASNs against `DATACENTER_ASNS` — defence-in-depth against operator-known abusive ranges.

## Shortlinks — ouo.io-family monetisation

The operator wraps destination URLs through external publisher shorteners (btcut.io / cuty.io / exe.io / shrtfly.com / ouo.io). Viewers click the wrapped URL on `/shortlinks` → land on the shortener's interstitial → forwarded to the destination. The operator earns the publisher revenue; SatPeek pays a portion to the viewer.

### Provider clients — `app/Shortlinks/Providers/`

Two transport shapes covered:
- **`GenericShortenerClient`** (query-token, btcut family): `GET <api_base>?api=<token>&url=<long>&alias=<custom>&format=json` → `{status, message, shortenedUrl}`.
- **`OuoShortenerClient`** (path-token, ouo family): `GET <api_base>/<token>?s=<long>` → plain-text body containing the URL.

Both implement `App\Shortlinks\Providers\ShortenerClient`. `ShortlinkProviderRegistry` (per-request scoped) builds the client set from `config('satpeek.shortlink_providers')` — adding a new query-family provider is a one-config-entry change.

### Per-click rotation — `App\Http\Controllers\Api\ShortlinkController::resolveRedirectUrl()`

Repeating the same shortened URL trains viewers to recognise + skip past it, and lets domain-level blocklists target one stable string. When a shortlink has `provider_name` + `source_url` set, every `/start` re-runs `source_url` through the configured shortener. A short random `?_r=<8 chars>` cache-buster is appended before the shorten call so providers that de-dup server-side (btcut/cuty/exe/shrtfly all do) mint a distinct slug per rotation. Shortener failure logs a warning and falls back to the cached `target_url` rather than 500ing the click.

### Admin-managed credentials — `App\Models\ShortlinkProviderCredential`

Filament resource at `/admin/shortlink-provider-credentials` lets the operator paste API tokens without touching `.env`. The `api_token` column is encrypted at rest via Eloquent cast. The runtime registry merges DB rows over config defaults (DB token wins, transport overrides, `is_active=false` removes the provider from the picker). A "Test" row action probes the live API with a throwaway URL.

## Self-serve advertising — `/advertise/*`

User-submitted ads: `/advertise/create` charges the user's balance upfront, queues the ad as `pending_review`, mails the operator. After approval the ad is served alongside admin-created inventory.

- `display_mode` field on `ptc_ads` (`window` default, `iframe` opt-in) — viewers see "Open in new tab" CTA vs inline iframe based on this. Surfaced in both the user-facing form and the Filament admin form.
- `/advertise/{id}/edit` lets the advertiser change `title` / `description` / `display_mode` / `daily_limit_per_user` / `is_active` after launch. `target_url` / `reward_sat` / `total_views_purchased` / `status` stay locked (budget already debited / admin review). Pausing flips `is_active=false` only; `status='approved'` stays so the ad resumes when toggled back on.

## Offerwall adapters — `app/Offerwall/`

Two contracts live in `app/Offerwall/Contracts/`:

- `OfferwallAdapter` — zero-arg `fetchPtcOffers()` / `fetchShortlinkOffers()` for publishers that expose a global inventory the nightly `php artisan satpeek:sync-offerwalls` cron can pull.
- `OfferwallPerUserAdapter` — `fetchPtcOffersFor(User, $ip)` / `fetchShortlinkOffersFor(User, $ip)` / `fetchReadArticleOffersFor(User, $ip)` for publishers (BitcoTasks today) that scope the offer set to a (user, IP) pair. Controllers call these on page render and merge with internal inventory.

Concrete adapters: `MockAdapter` (development), `BitcoTaskAdapter` (production, implements both contracts). Add new networks (AdGate, Adscend, CPALead) by implementing whichever contract matches the publisher's API and registering in `AppServiceProvider`.

### BitcoTasks integration — REST APIs + S2S postback

Per the published spec (https://bitcotasks.com/documentations, fetched 2026-04-27), BitcoTasks exposes three per-(user, IP) REST endpoints for the publisher to pull offers:

| Family | Path | Default duration |
|---|---|---|
| PTC | `GET /api/<API_KEY>/<USER_ID>/<USER_IP>` | 30 s (overridable per-row) |
| Shortlink | `GET /sl-api/<API_KEY>/<USER_ID>/<USER_IP>` | 10 s |
| Read Article | `GET /ra-api/<API_KEY>/<USER_ID>/<USER_IP>` | 60 s |

All three carry `Authorization: Bearer <BITCOTASK_BEARER_TOKEN>` — the bearer token is the API auth secret, separate from `BITCOTASK_API_KEY` (which sits in the URL path). Response shape:

```json
{ "status": "200", "message": "success", "data": [
  { "id": "...", "title": "...", "reward": "0.10",
    "currency_name": "Cash", "url": "https://bitcotasks.com/...",
    "duration": "30", "limit": "5" }
]}
```

`reward` (decimal USD) × `BITCOTASK_USD_TO_SAT` → integer satoshis on the resulting `OfferDescriptor`. Any failure mode (missing config, garbage IP, transport exception, non-2xx, malformed body) returns an empty array and logs a warning so the merge with internal inventory keeps working. Send the user to `OfferDescriptor::targetUrl` directly — there's no separate `startView` endpoint.

Reward delivery is still server-to-server. SatPeek's receiver lives at `POST /webhooks/bitcotask` (no URL token — security comes from the signature + IP allow-list).

Postback contract (form-encoded, lowercase fields):
- `subId` — publisher's user ID (we send it in the API URL path; BitcoTasks echoes it back)
- `transId` — BitcoTasks transaction ID (idempotency key, stored in `balance_ledgers.external_ref`)
- `reward` / `reward_value` / `payout` — decimal strings; `payout` is USD, converted to satoshis via `BITCOTASK_USD_TO_SAT`
- `status` — `1` = credit, `2` = chargeback (negative ledger row)
- `debug` — `1` = test postback (acked, no balance change)
- `signature` — `md5(subId.transId.reward.s2s_secret)`

Receiver enforces, in order: `s2s_secret` configured → IP in `BITCOTASK_IP_ALLOWLIST` (default `45.14.135.48`) → MD5 signature match → unique `(reason, external_ref)` insert. Returns the literal lowercase string `ok` on success (not JSON — BitcoTasks treats anything else as failure and retries).

## Payout — `app/Payout/FaucetPayClient.php`

Calls `POST {FAUCETPAY_API_BASE}/send`. Withdrawals enter `withdrawals` table → `ProcessWithdrawalJob` runs from the queue, with `requires_review` for `suspect+` tiers held for admin approval in Filament.

Retry / dead-letter (transient-only): `FaucetPayClient::send()` throws `FaucetPayUnreachableException` ONLY when the API host is unreachable at the TCP / DNS layer (Guzzle `ConnectException` — request never sent). The job has `$tries = 3` + `backoff() = [60, 300, 1800]` so a brief FaucetPay outage retries automatically over ~35 min. `ShouldBeUnique` keyed by withdrawal id (40-min lock) prevents the cron from racing the active retry. Every other failure mode (HTTP error, body status != 200, timeout mid-request) is treated as terminal — `status='failed'`, balance refunded, rejection email queued — because we can't tell whether FaucetPay processed the payout and a duplicate send is much worse than a delayed one. The `failed()` callback handles the final-exhaustion path with the same refund + notify sequence so funds are never silently stranded.

## Operations

- **`/up`** — structured JSON health check (DB / Redis / MaxMind / shortlink_providers / ip_reputation_providers). Returns 503 on critical down (DB / Redis), 200 with `status: degraded` on non-critical degradation. Stable detail codes (`unconfigured`, `file_missing`, `no_token_set`, …) for dashboards. See `App\Http\Controllers\HealthController`.
- **Admin debug resources** (Operations group):
  - `/admin/ptc-views` — read-only PtcView listing with status filter + Auth URL action.
  - `/admin/shortlink-clicks` — symmetric for ShortlinkClick.
  - Both forbid create/edit/delete to prevent admins from bypassing reward guards.
- **Cloudflare orange-cloud** (`TRUST_CLOUDFLARE_PROXY=true`): `App\Http\Middleware\CloudflareClientIp` promotes `CF-Connecting-IP` → `REMOTE_ADDR` so every IP-consuming code path (bot detection, captcha trace, BitcoTask URL, webhook allow-list) sees the real visitor. Without this flag the platform sees Cloudflare edge IPs and silently mis-classifies. Uses `CF-Connecting-IP` (overwritten by Cloudflare on every request) instead of `X-Forwarded-For` (Cloudflare appends, leaving the leftmost slot spoofable). **The origin firewall MUST restrict inbound to Cloudflare's published IP ranges (https://www.cloudflare.com/ips/)** when this flag is on; otherwise an attacker reaching the origin directly can spoof CF-Connecting-IP and bypass bot detection / IP reputation / captcha fingerprint locking.

## Tests

- `tests/Unit/Captcha/TrajectoryVerifierTest.php` — locks down the captcha algorithm against bezier replay, fast-script attempts, and 2captcha-style relays.
- `tests/Unit/BotDetection/` — score engine + signals (incl. `AsnStaticListSignalTest`).
- `tests/Unit/IpReputation/` — composite + cached + MaxMind provider.
- `tests/Unit/Shortlinks/` — generic + ouo shortener clients + registry.
- `tests/Unit/Http/Middleware/Ja4CaptureTest.php` — JA4 normalisation contract.
- `tests/Feature/Auth/RegisterFlowTest.php` — registration + welcome email path.
- `tests/Feature/Captcha/Ja4PersistenceTest.php` — JA4 lands on issued challenges.
- `tests/Feature/Ptc/` — viewer flow + auth landing.
- `tests/Feature/Shortlinks/` — click flow + auth landing + rotation + credential override + provider registry boot.
- `tests/Feature/Advertise/` — display_mode + edit flow.
- `tests/Feature/Admin/DebugResourceAccessTest.php` — Filament debug resources scoping.
- `tests/Feature/Health/HealthEndpointTest.php` — `/up` payload + status-code contract.
- `tests/BotSimulation/PlaywrightHeadlessTest.php` — synthetic uniform-Δt CDP-style attacks must be rejected.

CI must keep `tests/BotSimulation/` green — that is how captcha strength is measured over time.

## Open follow-ups

(none — JA4 capture, ASN datacenter lookup, and BitcoTasks publisher API all shipped; see CHANGELOG.md for the closing commits.)
