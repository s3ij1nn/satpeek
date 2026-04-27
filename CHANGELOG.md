# Changelog

All notable changes to SatPeek are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/s3ij1nn/satpeek/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/s3ij1nn/satpeek/releases/tag/v0.1.0
