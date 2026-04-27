# Changelog

All notable changes to SatPeek are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `/up` health probe now reports a `faucetpay` block:
  - `unconfigured` (degraded, 200) — `FAUCETPAY_API_KEY` blank.
    Withdrawals would all permanent-fail; ops needs this surfaced
    before a user files a support ticket.
  - `backlogged` (degraded, 200) — > 0 `queued` withdrawals older than
    1 h. Either the queue worker is dead or FaucetPay has been
    unreachable past the 35-min retry budget. The `backlog` field
    carries the count so dashboards can graph the trend.
  - `ok` — configured + queue draining within the 1-h grace.
  - Structural only — no live FaucetPay HTTP probe, because that
    would add cost and flakiness to a /up that load balancers hit
    every few seconds. 3 new feature tests cover the three states;
    the existing all-OK fixture grew the FAUCETPAY_API_KEY config
    so overall stays `ok`.
- Two operator-facing widgets on the Filament `/admin` dashboard:
  - **In-flight withdrawals** — count + total `amount_sat` of
    `queued`/`processing` rows, plus separate cards for the manual-
    review `hold` queue and the rolling 24-h `failed` count. Each card
    taps through to the `/admin/withdrawals` list filtered to that
    status. Catches the failure mode where the queue worker dies and
    `In flight` rises monotonically without any other obvious signal.
  - **Bot tier distribution** — count of users per `bot_scores.tier`
    (trust / suspect / likely_bot / banned). Surfaces population
    shifts (a new attack wave pushing `likely_bot` upward, or
    `banned` swelling after a fingerprint rotation) at a glance.
  - Both widgets use single grouped queries against indexed columns
    (`bot_scores.tier`, `withdrawals.(status, created_at)`), so they
    add no measurable load to the dashboard render. Admin-only via
    the existing `User::canAccessPanel()` panel guard. 7 new tests
    in `tests/Feature/Admin/DashboardWidgetTest.php` lock the
    aggregate-query layer (zero-state, status-bucket isolation,
    24-h window cutoff, tier grouping).

## [0.2.0] — 2026-04-27

Theme: BitcoTasks publisher integration lands end-to-end, plus operational
hardening across payout retry, health surface, admin toggles, and captcha
regression coverage. The platform still ships zero-config — every external
integration is opt-in, gated on credentials being present, and degrades to
the internal inventory when not configured.

### Added

#### BitcoTasks publisher integration

- REST publisher API integration against the documented spec
  (https://bitcotasks.com/documentations, fetched 2026-04-27). Three
  per-(user, IP) endpoints — PTC `/api/`, Shortlink `/sl-api/`, Read
  Article `/ra-api/` — each carrying `Authorization: Bearer
  <BITCOTASK_BEARER_TOKEN>`. `BitcoTaskAdapter` implements the new
  `OfferwallPerUserAdapter` contract alongside the zero-arg
  `OfferwallAdapter`; the global `satpeek:sync-offerwalls` cron stays a
  safe no-op (per-user adapters return `[]` from the zero-arg fetchers).
- Per-(user, IP) merge surface: `/ptc`, `/shortlinks`, and a new
  `/read-articles` page render external publisher offers alongside (or,
  for read-articles, instead of) internal inventory via the new
  `App\Offerwall\OfferwallMerge` service. Per-adapter exceptions are
  caught and logged so one bad partner cannot 500 the page.
- New `App\Offerwall\Contracts\OfferwallPerUserAdapter` interface with
  `fetchPtcOffersFor(User, string $ip)` /
  `fetchShortlinkOffersFor(User, string $ip)` /
  `fetchReadArticleOffersFor(User, string $ip)`. The contract describes
  publishers that scope their offer set to a (user, IP) pair rather
  than exposing a global inventory.
- `BITCOTASK_BEARER_TOKEN` env var (separate from `BITCOTASK_API_KEY`,
  which sits in the URL path). Both come from different fields on the
  publisher dashboard.

#### Operator UX

- Filament admin can flip offerwall publisher integrations on / off
  without a redeploy. New `offerwall_provider_settings` table +
  `OfferwallProviderSetting` model + Filament resource at
  `/admin/offerwall-provider-settings` (Inventory group, admin-only).
  `AppServiceProvider::applyOfferwallDbOverrides()` merges DB rows over
  `OFFERWALLS_ENABLED` at request time — `is_enabled=true` includes
  the adapter even when env omits it, `is_enabled=false` excludes it
  even when env lists it (an emergency disable lever).
- `AdapterRegistry` binding switched from `singleton` to `scoped` so
  per-request DB reads pick up Filament edits without a queue worker
  restart. Same lifecycle as `ShortlinkProviderRegistry`.
- Credentials intentionally stay in env. Putting `BITCOTASK_*` keys in
  DB would widen the secret-leak surface (DB dumps, replicas) for a
  convenience win that doesn't apply to the operator's one-time
  deploy-step.

#### Health & observability

- `/up` health probe now reports an `offerwall_providers` block so an
  operator can spot a misconfigured BitcoTasks integration without
  hitting the partner's first request:
  - `unconfigured` (degraded, 200) — `OFFERWALLS_ENABLED` empty
    (default until publisher review approves access). Not a paging
    condition.
  - `credentials_missing` (degraded, 200) — adapter enabled but one of
    `api_key` / `bearer_token` / `s2s_secret` blank. The `missing`
    field lists exactly which env vars are empty, e.g.
    `["bitcotask:bearer_token", "bitcotask:s2s_secret"]`.
  - `ok` — enabled and all credentials present.

#### FaucetPay payout reliability

- `ProcessWithdrawalJob` grew an automatic-retry + dead-letter path
  (`$tries = 3`, `backoff() = [60, 300, 1800]`) so a brief FaucetPay
  outage no longer strands withdrawals at `processing` waiting for an
  operator. The retry policy is deliberately conservative — only the
  pre-flight `FaucetPayUnreachableException` (Guzzle `ConnectException`,
  request never made it onto the wire) re-tries; everything else
  (HTTP error, body status != 200, mid-request timeout) is terminal
  because we can't tell whether FaucetPay processed the payout, and a
  duplicate send is much worse than a delayed one.
- `ShouldBeUnique` (keyed by withdrawal id, 40-min lock) prevents the
  cron from racing the active retry and double-dispatching. `failed()`
  is the dead-letter callback: same refund + notify sequence as a
  permanent failure, plus a `withdrawal job dead-lettered` warning
  log. Idempotent on already-settled rows.

#### Captcha regression coverage

- 3 additional `tests/BotSimulation/` scenarios so each verifier gate
  has a regression test against a realistic attack pattern:
  - **Recorded-trace replay against fresh challenge** — bot harvests
    a known-good point stream from a previously-solved challenge and
    replays it. The fresh-seed-per-issue invariant means it hits
    `shape_mismatch` regardless of how plausible the inner timing /
    jerk / dwell signals look. This is the single most dangerous
    regression we can ship — locked here.
  - **No post-arrival dwell** — naive bot solves the curve correctly
    but submits the moment the cursor reaches the goal, no settle
    window. `no_completion_dwell` catches it.
  - **Sub-minimum solve time** (<800 ms) — pins the lower-bound gate
    so a future config bump for accessibility cannot drop it below
    the human-plausible floor.

### Changed

#### BitcoTasks postback contract

- `BitcoTaskAdapter::verifyCallback` rewritten against the documented
  spec: form-encoded fields (`subId` / `transId` / `reward` / `payout`
  / `status` / `signature` / `debug`), MD5 signature
  `md5(subId.transId.reward.s2s_secret)` (the previous HMAC-SHA256
  over JSON body never matched a real BitcoTasks postback), and a
  config-driven IP allow-list defaulting to BitcoTasks's published
  `45.14.135.48`.
- `BitcoTaskCallbackController` returns the literal string `ok`
  (no JSON, no whitespace) — BitcoTasks treats anything else as
  failure and retries up to its 60-s timeout. `status=1` credits,
  `status=2` chargebacks (negative ledger row + decrement). Unknown
  status codes are logged + acked but not credited.
- `debug=1` test postbacks are acked without crediting.
- Idempotency via the new `balance_ledgers.external_ref` column with a
  unique index on `(reason, external_ref)` — duplicate `transId`
  arrivals short-circuit with `ok` and zero balance change.
- Webhook route changed from `/webhooks/bitcotask/{token}` to
  `/webhooks/bitcotask` — security comes from the form-field signature
  + IP allow-list, the legacy `{token}` URL segment was pre-spec
  defence-in-depth that the documented signature scheme makes redundant.
- `startView` throws `LogicException` instead of pretending to call a
  nonexistent endpoint.

#### Static-analysis baseline

- PHPStan baseline reduced from 26 → 13 → 0 errors. The
  `phpstan-baseline.neon` file is gone and CI fails on the first new
  type error — no more "shrinks over time" grace period.
- `@property` / `@property-read` PHPDoc added to PtcView,
  ShortlinkClick, Shortlink, PtcAd, Withdrawal, BotScore,
  CaptchaChallenge, and User (botScore relation). Larastan now
  resolves Eloquent magic accessors through the typed properties
  instead of falling back to `Model::$xxx → undefined`.
- ChallengeVerifier / ResponseTimeSignal / Api PtcController +
  ShortlinkController + ShortlinkAuthController dropped redundant
  `?->` / `??` guards on schema-non-nullable columns. Carbon 3
  signed-float diffs are now bounded with
  `(int) abs($x->diffInSeconds(now()))`.

### Notes

- The single remaining `@phpstan-ignore-next-line nullsafe.neverNull`
  on `PolicyEnforcer::tier` documents a Larastan limitation (it
  narrows `HasOne::botScore` to non-null even with
  `@property-read BotScore|null` on User). Runtime nullability is
  real, so the `?->` + `??` guards stay; the suppressed scope is
  one expression so any future false-positive elsewhere still
  surfaces.
- 47 new tests added across the release; total now 176 passing
  (524 assertions), Pint clean, PHPStan zero.

## [0.1.0] — 2026-04-27

First public release. Cuts the line between the initial baseline import and
the public push to https://github.com/s3ij1nn/satpeek with full CI + lint +
static analysis green and 130 tests / 393 assertions passing.

### Added

#### Shortlinks (ouo.io-family monetisation)

- `App\Shortlinks\Providers\GenericShortenerClient` covering the query-token
  shortener family (btcut.io / cuty.io / exe.io / shrtfly.com) — single class
  for the whole `?api=&url=&alias=&format=json` shape.
- `App\Shortlinks\Providers\OuoShortenerClient` for ouo.io's path-token
  variant (`<api_base>/<token>?s=<url>`, plain-text response, defensive URL
  validation against Cloudflare HTML challenge bodies).
- `App\Shortlinks\Providers\ShortenerClient` interface so the registry
  holds either transport without leaking the concrete class.
- `App\Shortlinks\ShortlinkProviderRegistry` (per-request scoped) — resolves
  clients by config key, exposes `configuredNames()` for the admin UI to
  filter unconfigured providers out of pickers.
- Per-click rotation in `ShortlinkController` with a `?_r=…` cache-buster so
  shorteners that de-dupe by destination URL (btcut family) still mint a
  distinct slug per follow.
- Server-side `/sl/{token}` redirector — destination URL is minted at
  follow time and 302'd; never returned via JSON, so an XHR-scrape bot
  can't learn the destination without burning a click.
- `/shortlinks/auth/{token}` per-click landing page — 28-char unguessable
  slug, owner-scoped (404 cross-user), single-use (410 once resolved),
  token-keyed completion endpoint.
- `App\Models\ShortlinkProviderCredential` with encrypted-at-rest
  `api_token` cast + Filament resource so operators rotate API keys from
  `/admin/shortlink-provider-credentials` instead of `.env`.

#### PTC

- `display_mode` field on `ptc_ads` (`window` default, `iframe` opt-in)
  exposed in both the user-facing `/advertise` form and the Filament admin.
- `/ptc/auth/{token}` per-watch landing — same security profile as the
  shortlink auth landing (rotating slug, owner-scoped, single-use).
- `/advertise/{id}/edit` self-serve campaign edit. Editable: title,
  description, display_mode, daily_limit_per_user, is_active. Locked:
  target_url, reward_sat, total_views_purchased, status. Pause flips
  is_active=false but keeps status='approved' so the ad resumes when
  toggled back on.

#### Bot detection

- `App\Http\Middleware\Ja4Capture` — normalises upstream JA4 TLS
  fingerprint headers (cf-ja4 / x-tls-ja4 / x-ja4 / x-sp-ja4) into a
  canonical `X-SP-JA4`. Format-validates so a spoofing client can't seed
  the captcha_challenges.ja4 column with garbage.
- `App\BotDetection\Signals\AsnStaticListSignal` — defence-in-depth check
  against an operator-curated `DATACENTER_ASNS` list, weight 0.05.
- `App\IpReputation\Adapters\MaxMindAsnProvider` — local GeoLite2-ASN
  `.mmdb` lookup. Operator-supplied file (license-restricted), graceful
  degradation to no-signal when absent. Wired first in the composite so
  the cheap lookup short-circuits IPHub / ProxyCheck.

#### Admin / operations

- Read-only `/admin/ptc-views` + `/admin/shortlink-clicks` Filament
  resources for triage. canCreate / canEdit / canDelete pinned to false
  so admins can't side-step reward guards.
- "Auth URL" row action in both views — opens the user-facing
  `/{ptc,shortlinks}/auth/{token}` page in a new tab for support work.
- `/up` JSON health endpoint reporting database / redis / maxmind /
  shortlink_providers / ip_reputation_providers status. 503 on critical
  down (db / redis), 200 + `status: degraded` on optional component
  issues with stable detail codes for dashboards.
- `satpeek:cleanup-captcha` artisan command — flips stale issued
  captcha rows to expired and prunes resolved rows older than `--days`
  (default 30). Scheduled `dailyAt('03:00')` in `routes/console.php`.

#### CI / tooling

- GitHub Actions workflow (`.github/workflows/ci.yml`):
  - `lint` job: `composer validate --strict`, Laravel Pint `--test`,
    PHPStan / Larastan level 5 with baseline.
  - `test` job: full `php artisan test` (124+ tests, BotSimulation suite
    included). Uses sqlite `:memory:` + Predis so no service container
    is required.
- `larastan/larastan ^3.0` static analysis with `phpstan-baseline.neon`
  (26 errors captured, contract: shrinks over time, never grows).
- Dependabot config (`.github/dependabot.yml`): weekly Monday 09:00 JST
  composer + github-actions PRs grouped by family (laravel / filament /
  dev-dependencies / production-minor / actions-minor).
- Claude GitHub App workflows (`.github/workflows/claude{,−code-review}.yml`)
  with concurrency caps, 15-min timeouts, doc/Dependabot path filters.

#### Docs

- MIT LICENSE.
- CHANGELOG.md (this file).
- README.md badges (CI status, MIT license, PHP 8.3, Laravel 11).
- CLAUDE.md / README.md rewritten to reflect 9 bot-detection signals,
  rotating auth URLs, shortlink rotation, three-provider IP reputation
  stack, /up health, advertise self-edit, display_mode, admin debug
  resources.

### Changed

- Captcha policy in `config/satpeek.php` defaults bumped to
  `max_solve_ms=60000` (from 25000) and `max_points=1500` (from 600) so
  honest user flows (type credentials → drag captcha → submit) don't
  false-reject as `too_slow_relay` / `too_many_points`.
- `IpReputationProvider` composite now registers MaxMind first (sub-ms
  local lookup) before IPHub / ProxyCheck.
- `ShortlinkProviderRegistry` binding moved from `singleton` to `scoped`
  so admin credential updates take effect on the next request without
  app restart.
- `bootstrap/app.php`: drops Laravel's `health: '/up'` shortcut in favour
  of the structured `HealthController`.
- Codebase-wide Pint formatting pass to align with the Laravel preset
  (170 files touched, no behavioural change).

### Fixed

- `ChallengeVerifier` solve-ms calculation switched to
  `Carbon::getPreciseTimestamp(3)` arithmetic — Carbon 3 (Laravel 11)
  returns signed floats from `diffInMilliseconds`, which used to drive
  the value negative when `now > issued_at` and falsely reject valid
  captchas.
- CI workflow: `cp .env.example .env` now runs BEFORE `composer install`
  so `package:discover` (which boots the View compiler) finds a
  `view.compiled` path. Without this, both lint and test jobs aborted
  on the first push.
- `storage/framework/{cache/data,sessions,views,testing}` and
  `storage/logs` ship with `.gitkeep` so the directories exist on a
  fresh clone — Laravel's View compiler refuses a null cache path.

### Security

- Captcha trace + raw shape are not regulatory data; cleanup-captcha
  prunes resolved rows after 30 days so a DB dump leak surfaces less.
- `ShortlinkProviderCredential.api_token` encrypted at rest via Eloquent
  cast; raw DB row never contains the plaintext token.
- Per-click `/sl/{token}` redirector keeps shortened destinations off
  the JSON wire — bots scraping `/api/shortlinks/{id}/start` only learn
  a SatPeek redirector URL, not the destination.
- Per-watch / per-click rotating auth URLs (28-char ≈ 145 bits entropy)
  defeat URL probing, browser-history pivoting, and shareable-link
  replay.
- `Ja4Capture` middleware validates JA4 shape so a spoofing client can't
  flood `captcha_challenges.ja4` with garbage.
- `composer.json` license flipped from `proprietary` to `MIT` to match
  the published LICENSE file (consistency, not a security concern, but
  prevents SPDX-tooling confusion).

[Unreleased]: https://github.com/s3ij1nn/satpeek/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/s3ij1nn/satpeek/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/s3ij1nn/satpeek/releases/tag/v0.1.0
