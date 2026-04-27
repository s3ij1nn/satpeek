# Changelog

All notable changes to SatPeek are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- `/shortlinks` now serves only rotation-enabled internal entries +
  BitcoTask offerwall offers — static shortlinks (no `provider_name`
  or no `source_url`) are hidden from both the Blade index AND the
  `/api/shortlinks` JSON list. Operator policy: shortener-API
  rotation OR BitcoTask offerwall, never static.
  - Filament `ShortlinkResource` form now requires `source_url` AND
    `provider_name`. The "Rotation provider" select is no longer
    optional.
  - New `ShortlinkController::servableQuery()` is the single source
    of truth used by the Blade view, the JSON index API, and the
    per-id `start` resolver — keeps every entrypoint in sync.
  - Existing static rows in DB stay (operator can clean up via
    Filament) but are unreachable from user-facing surfaces.
    `/api/shortlinks/{id}/start` 404s on static-row ids even when
    the attacker enumerates them directly.
  - 4 new feature tests in
    `tests/Feature/Shortlinks/ServableFilterTest.php` pin the
    filter at all three layers (servableQuery / index / start).
    Removed the now-contradictory
    `test_static_shortlink_returns_target_url_without_rotation`
    from RotationTest.

## [0.5.0] — 2026-04-27

Theme: anti-adblock + framework refresh. Two operator-requested
features land together — browser-side adblock/Brave detection that
gates earning surfaces, and the Laravel 12 → 13 + Filament 3 → 4
upgrade so the platform stays on the actively-developed release line.

### Changed

- **Laravel 12 → 13 + Filament 3 → 4 upgrade.** All 10 Filament
  resources + Login auth page rewritten against the Filament 4
  Schema-based API. Composer-only dependency bumps + targeted
  per-resource API porting; zero behavioural change at the user-
  facing surface.
  - laravel/framework: ^12.0 → ^13.0 (installed v13.6.0, security
    support through 2027-08-XX)
  - filament/filament: ^3.2 → ^4.0 (installed v4.11.1)
  - laravel/tinker: ^2.10 → ^3.0
  - Filament 4 API renames applied per resource:
    - `form(Form $form): Form` → `form(Schema $schema): Schema`
    - `Forms\Components\Section` → `Schemas\Components\Section`
      (and Group / Tabs / Fieldset / Wizard / Grid)
    - `Tables\Actions\*` → `Filament\Actions\*` (top-level package)
    - `Filament\Pages\Auth\Login` → `Filament\Auth\Pages\Login`
    - `Filament\Forms\Set` → `Filament\Schemas\Components\Utilities\Set`
    - `navigationGroup` / `navigationIcon` type widening to
      `string|UnitEnum|null` / `string|BackedEnum|null` (per
      Filament 4 parent class signature requirement)
  - Input components (`TextInput`, `Toggle`, `Select`, `Placeholder`,
    `Textarea`, `KeyValue`) stayed in `Filament\Forms\Components`.
  - 276 tests stayed green throughout — no test-side updates needed.

### Added

- Anti-adblock detection + earning gate. SatPeek's economics depend
  on ad impressions; users running adblockers (or Brave with shields
  on) now have earning surfaces (PTC start / shortlink start /
  withdrawal) refused at 403 until they disable.
  - **Frontend probe** (auto-injected into the authenticated layout):
    three signals combined per request — bait-element CSS hide check,
    network probe against an `/ads/` path, and `navigator.brave.isBrave()`
    for Brave detection. Posts the verdict to `/api/adblock/report`.
  - **Server-side gate** (`App\Http\Middleware\AdblockGate`,
    aliased `adblock.gate`): refuses earning routes with 403
    `adblock_detected` when the user's last report flagged either
    adblock or Brave; refuses with 403 `adblock_check_required`
    when no report has landed OR the last report is older than
    `ADBLOCK_CHECK_TTL_SECONDS` (default 300 s). The stale-equals-
    blocked rule is the anti-bypass measure — a bot that simply never
    POSTs the report can't claim "clean" by default.
  - The `/api/adblock/report` endpoint is itself exempt from the
    gate (otherwise the freshly-checking client would lock itself
    out of the very endpoint it needs to call).
  - **User-facing banner** on `/dashboard` explains the gate state
    in plain language with disable-instructions for uBlock /
    AdBlockPlus / Brave shields. Orthogonal to the tier banner —
    shows even for trust-tier users.
  - `users` table grew `adblock_status` (clean | detected | null) +
    `adblock_checked_at` columns. `UserFactory::definition()`
    defaults to a freshly-checked clean state so existing feature
    tests for earning routes aren't blocked; explicit
    `withAdblockDetected()` / `withStaleAdblockCheck()` factory
    states cover the gate-active paths.
  - 7 new feature tests in
    `tests/Feature/Adblock/AdblockGateTest.php` cover clean-passes /
    detected-blocks / never-reported-blocks / stale-blocks /
    report-endpoint-not-gated / Brave-marks-detected / withdrawal-
    also-gated.

## [0.4.3] — 2026-04-27

Patch release: surface previously-hidden state to both operators and end
users so silent degradations stop looking like glitches. All additive.

### Added

- Tier-driven status banner on `/dashboard`. Trust users see no
  banner (the dashboard stays clean for the overwhelming majority of
  legitimate users); suspect / likely_bot / banned each get a
  tier-specific message in plain language. Two motivations:
  - Legit users hit by a shared-NAT false positive (campus / mobile
    / corporate proxy) can self-diagnose without a support ticket.
  - Real bot operators get a clear "you've been caught" signal
    instead of a silent rate-limit they might attribute to a glitch.
  - Manually-banned users (operator clicked the toggle on
    `/admin/users/{id}/edit`) see the suspension banner even when
    no `bot_scores` row exists, so the manual-action path also
    surfaces a clear reason in the UI.
  - 6 new feature tests in `tests/Feature/Dashboard/TierBannerTest.php`
    pin all four tier paths plus the manual-ban + no-eval edge cases.
- WithdrawalResource (admin) now surfaces FaucetPay job retry
  telemetry that ProcessWithdrawalJob has been writing since v0.2.0
  but had no admin surface:
  - **Tries** column in the table (badge — gray 0, warning 1-2,
    danger 3+) so the operator can spot a row that's been retrying
    for a while at a glance.
  - **Job retry telemetry** collapsible section on the edit page
    showing attempt count, last-attempted relative timestamp, and
    the raw FaucetPay response JSON for the failure case. Lets the
    operator distinguish "still retrying through backoff" from
    "stuck for an external reason that needs intervention".

## [0.4.2] — 2026-04-27

Patch release: three operator-visibility additions in `/admin`. All
additive — no behavioural breaks. Same release theme as v0.4.0/v0.4.1
("close the operator-tooling loop on the bot scoring pipeline").

### Added

- `SharedIpDetectionsWidget` on the `/admin` dashboard — three cards
  for "is the platform under sock-puppet attack right now?":
  - **Shared-IP hits (24 h)** — count of recent observations on
    IPs that have 2+ distinct users
  - **Distinct shared IPs** — how many IPs are bleeding
  - **Distinct users on those IPs** — sock-puppet pool size
  - All cards tap-through to `/admin/user-ip-observations` with the
    "Shared IPs only" ternary filter pre-applied. Color shifts
    (gray → warning → danger) so a quiet day looks neutral and a
    sudden burst draws the eye.
- Inline "Recent IP history" section on the user edit page. Last 10
  auth observations rendered as a server-side table with hit_count,
  source, last-seen, and per-row sibling count (distinct OTHER
  user_ids on the same IP, color-coded green/yellow/red). Saves an
  operator triaging a suspect user one navigation hop. Tap-through
  link to `/admin/user-ip-observations` filtered by username for the
  full history.
- Read-only Filament Operations resource at
  `/admin/balance-ledgers` for the most common operator workflow:
  "user X says they didn't get paid for view Y" → search by user,
  filter by reason (`ptc_view` / `shortlink` / `bitcotask_postback` /
  `referral_commission` / `withdraw_*`), the reference id is right
  there. Signed-Δ rendering with green for credits, red for debits.
  - canCreate / canEdit / canDelete pinned to false. The ledger is
    the source of truth for `users.balance_sat` — an admin write
    here would either silently desync the cached balance or
    (worse) hide a real accounting bug. Corrections still flow
    through the existing typed actions (refund a withdrawal, etc.)
    that write matched ledger pairs.
  - 3 new feature tests pin the read-only contract, the non-admin
    gate, and the signed-Δ rendering.

## [0.4.1] — 2026-04-27

Patch release: tier-driven captcha difficulty actually wired, SharedIpSignal
gets an allowlist for known shared NATs, end-to-end PolicyEnforcer
integration tests, GitHub Actions bumped past the Node 20 deprecation. All
additive.

### Changed

- `PolicyEnforcer::captchaDifficulty` is no longer dead code. The 1/2/3
  level (driven by user tier — trust / suspect / likely_bot) now
  shapes the issued trajectory captcha:
  - **suspect (2)**: 1.5× amplitude, +1 frequency
  - **likely_bot (3)**: 2.0× amplitude, +2 frequency, +1 s duration
  - **trust (1) / anonymous**: defaults unchanged (login + register
    forms keep difficulty 1 since there's no user to score yet)
  - `ChallengeBuilder` now injects `PolicyEnforcer` and looks up the
    user's difficulty before calling `provider->issue()`. Out-of-range
    values are clamped to [1, 3] inside the provider so a typo
    can't mint a 50× amplitude curve no human could trace.
  - 4 new unit tests in `tests/Unit/Captcha/CaptchaDifficultyTest.php`
    pin amplitude monotonicity, frequency floor lift, range clamp,
    and default-equals-trust behaviour.
  - `CaptchaProvider::issue()` interface gained an `int $difficulty = 1`
    parameter (backward-compatible default).

### Added

- `App\BotDetection\IpAllowlist` — CIDR-aware match for an
  operator-supplied list of "this IP is a known shared NAT, don't
  flag cross-account use of it" prefixes. `SharedIpSignal` now skips
  any of the user's IPs that match `BOTSCORE_SHARED_IP_ALLOWLIST`
  (comma-separated CIDR / single-IP list), so legit users on
  campus wifi / mobile carriers / household routers / corporate
  proxies don't false-positive into suspect/likely_bot.
  - IPv4 and IPv6 supported. CIDR matching at byte- AND bit-
    boundary is exercised in 10 new unit tests
    (`tests/Unit/BotDetection/IpAllowlistTest.php`).
  - Allowlist is "skip from cross-account count" — a clean home IP
    can't redeem a sock-puppet IP. SharedIpSignal still uses the
    worst-IP-wins rule for the remaining (non-allowlisted) IPs,
    so an attacker who happens to also touch a campus IP still
    gets caught on their throwaway IP.
  - 2 new SharedIpSignal tests cover the
    "allowlisted IP doesn't score" and "allowlist doesn't mask a
    non-allowlisted shared IP" paths.
- End-to-end PolicyEnforcer integration tests in
  `tests/Feature/BotDetection/PolicyEnforcerIntegrationTest.php`.
  Confirms the `bot_scores.tier` → action chain holds at the HTTP
  layer, not just inside PolicyEnforcer's unit tests:
  - `trust` tier → PTC start works, withdrawal goes straight to
    `queued` (no review).
  - `suspect` tier → PTC start works, withdrawal flips to `hold`
    with `requires_review = true`.
  - `likely_bot` tier → PTC start returns 403 `tier_blocked`,
    same shared-ban-list applies to shortlink start.
  - `banned` tier → BotScoreGate middleware short-circuits every
    `/api/*` call with 403 `tier_banned` before any controller runs.
  - `is_banned` flag → BotScoreGate's first branch returns 403
    `banned` independently of tier, so a manual operator ban with
    no `bot_scores` row still blocks correctly.
  - 8 new tests pin the chain so a future change that drops the
    `bot.gate` middleware from a route group, or forgets to inject
    PolicyEnforcer into a new controller, fails CI immediately
    instead of surfacing in production.

## [0.4.0] — 2026-04-27

Theme: bot scoring goes live + Laravel 12 EOL fix. The signal-and-engine
infrastructure shipped in v0.3.0 was dormant — `ScoreEngine` was registered
in the container but never invoked outside unit tests, so SharedIpSignal
and the rest of the captcha-driven signals only fed test fixtures, not
real user scoring. This release wires the engine into the auth and
captcha-verify paths, surfaces the verdict on the operator's user-detail
page, and bumps off the security-EOL'd Laravel 11.

### Added

- Bot detection panel on the `/admin/users/{id}/edit` page.
  Surfaces tier, score, last-evaluated timestamp, and a per-signal
  breakdown table (weight · raw) so an operator triaging a `suspect`
  / `likely_bot` user can see WHICH signals fired without reading
  raw `bot_scores` rows by hand.
  - New "Re-score" row action on the user list bypasses the
    `evaluateThrottled` window — useful for triaging a manual ban /
    unban decision when the operator wants a fresh signal sweep.
  - Server-side rendered table (HtmlString) so the breakdown lives
    in the initial HTML response, not a Livewire round-trip — also
    means it's printable / screenshottable for incident reports.
  - 3 new feature tests in
    `tests/Feature/Admin/UserResourceBotPanelTest.php` cover the
    no-eval / with-eval / non-admin-gate paths.
- **`ScoreEngine::evaluateThrottled()`** — production code finally
  invokes the bot scoring pipeline. Previously every signal (including
  `SharedIpSignal` shipped in v0.3.0) was registered in the engine but
  the engine itself was never called outside unit tests, so
  `bot_scores.tier` only updated through manual admin edits.
  - `UserIpObserver::record()` now triggers an
    `evaluateThrottled()` after every login / register IP write so a
    sock-puppet operator's first sibling-account login lands on the
    correct tier within the same request, rather than waiting for a
    captcha verify path that may never come.
  - `ChallengeVerifier::verify()` triggers `evaluateThrottled()` on a
    successful captcha pass when the challenge has a `user_id`. Catches
    every captcha-driven signal (response_time, trajectory_entropy,
    failure_rate, fingerprint_consistency, heartbeat_gap) so a bot
    grinding PTC views or shortlinks gets tier-bumped from its own
    captcha behaviour without needing to log in again. Anonymous
    challenges (pre-auth login form) are skipped — no user to score.
    Failed captchas don't trigger re-eval (the failure_rate signal
    picks up the rejection on the next score eval).
  - 3 new feature tests in
    `tests/Feature/Captcha/ChallengeVerifierScoresUserTest.php`
    cover passing-captcha-scores / failing-captcha-skips /
    anonymous-challenge-skips paths.
  - Throttle window: 5 min by default
    (`BOTSCORE_MIN_REEVAL_SECONDS`). Tight enough that a login burst
    from an attacker still triggers a fresh score on the first hit;
    loose enough that a chatty user doesn't bombard the signal queries.
  - Defensive try/catch around the score eval inside the IP observer
    so a scoring failure (DB blip, signal exception) never breaks
    the auth flow itself — the warning log surfaces it for review.
  - 7 new tests across
    `tests/Unit/BotDetection/ScoreEngineThrottleTest.php` (4) and
    `tests/Feature/BotDetection/SharedIpScoresAtAuthTest.php` (3)
    cover throttle behaviour + end-to-end shared-IP → tier transition.

### Changed

- **Laravel 11 → 12 upgrade** to close the security-EOL window
  (Laravel 11 reached EOL on 2026-04-12). Composer-only change —
  219 tests / 634 assertions stay green, Pint clean, PHPStan zero,
  no production code touched. Installed `v12.58.0`. Transitive
  bumps to livewire, carbon, collision, termwind, sanctum,
  blade-capture-directive, eloquent-power-joins, filament/*
  (still 3.3.x — supports Laravel 12).
  - **Laravel 13 deferred deliberately.** Filament 3.3 does not
    support Laravel 13, only Filament 4 does. Filament 4 is a
    major API rewrite (Schema-based forms / tables, type-signature
    widening across navigation*, Action / Notification namespace
    moves). Doing both jumps in one shot would land a risky,
    unreviewed diff. Laravel 12 buys a ~9-month security runway
    (support through 2027-02-04) to plan the Filament 4 migration
    properly as a dedicated PR.

### Added

- Read-only Filament Operations resource at
  `/admin/user-ip-observations` for the multi-account-by-IP audit
  trail. Each row is "user X authenticated from IP Y", appended by
  `UserIpObserver` at login / register submit. Operator can:
  - search by user / IP
  - filter by source (login / register) and "shared IPs only" (uses
    the indexed `ip` column for cheap WHERE EXISTS lookups)
  - sort by hit count / first-seen / last-seen
  - tap through to the User row to take action (warn, hold
    withdrawals, ban)
  - see a per-row "siblings" badge counting distinct OTHER user_ids
    on the same IP — green at 0, warning at 1-2, danger at 3+
  - Resource is read-only by design (canCreate / canEdit / canDelete
    return false). The audit trail is append-only signal that
    `SharedIpSignal` reads; an accidental admin delete would silently
    remove evidence the bot scoring engine depends on.

## [0.3.0] — 2026-04-27

Theme: identity capture + operator visibility. Every change in this release
either sharpens who SatPeek thinks the visitor is (Cloudflare-aware client
IP, multi-account-by-IP detection, ProxyCheck primary detection with IPHub
fallback) or hands the operator new controls / observability for the
defences that already exist (Filament offerwall toggle, dashboard widgets,
`/up` blocks for FaucetPay backlog and offerwall credentials, affiliate
program on every earnings surface).

### Added

- `App\BotDetection\Signals\SharedIpSignal` — the multi-account-by-IP
  observations recorded by `UserIpObserver` now feed an actual scoring
  signal, not just a warning log. For each IP this user has
  authenticated from, the signal counts distinct OTHER user_ids on
  the same IP and scores by the worst (most cross-account) IP in the
  user's history. ScoreEngine weight 0.15 — high enough that 3+
  sibling accounts on a single IP push the user past the suspect
  threshold (0.30) even with every other signal clean.
  - Tunable via `BOTSCORE_SHARED_IP_*` env vars
    (`min_others_for_signal`, `score_per_other`, `max_score`) so
    operators in shared-NAT-heavy environments (campus, mobile,
    household routers) can relax the threshold without code changes.
  - Worst-IP-not-average strategy means a clean home IP can't
    redeem a sock-puppet IP — the intuition is "even one shared IP
    is suspicious".
  - 7 new unit tests in `tests/Unit/BotDetection/Signals/SharedIpSignalTest.php`
    cover empty / unique / single-shared / cap / mixed-history /
    threshold-suppression / distinct-user-count paths.
- Affiliate program now applies to every earnings surface, not just
  PTC. New `App\Services\ReferralPayout` is the single source of truth
  for referral commission settlement, wired into:
  - `Api\PtcController` (was inline; extracted)
  - `Api\ShortlinkController` (newly covered)
  - `Webhook\BitcoTaskCallbackController` (newly covered — postback
    credits)
  - The funding rule is now explicit AND enforced: commission is
    `min(referral_pct, ads.commission_pct)` of the referee's reward,
    so the affiliate program NEVER reduces what the referee earns
    AND never exceeds the platform's collected commission. A
    misconfigured `referral_pct > ads.commission_pct` is silently
    capped instead of bleeding the operator dry.
  - `/referral` page now states the funding rule on the share card so
    invitees and referrers both understand referrals don't reduce
    friends' earnings.
  - 6 new feature tests in `tests/Feature/Referral/ReferralPayoutTest.php`
    cover happy path / cap / no referrer / zero reward / pct rounds-
    to-zero / lifetime accumulation.
- Multi-account-by-IP detection. New `user_ip_observations` table +
  `App\Services\UserIpObserver` records the IP each user authenticates
  from at login submit / register submit (the moments where SatPeek
  can confidently say "this human-controlled action came from this
  IP"). When the same IP appears under a different `user_id`, the
  observer logs a `shared_ip_multi_account` warning the operator
  dashboard can consume.
  - **Cookie-only multi-account dedup misses the operator who clears
    cookies / opens incognito; IP-only dedup misses the operator who
    hops to mobile data.** Together they catch the common cases.
  - The recorder is upsert-style: composite UNIQUE on `(user_id, ip)`,
    so a noisy mobile-NAT user produces one row with an incrementing
    `hit_count`, not a row per request.
  - Indexed by `ip` to make the cross-user lookup
    (`WHERE ip = ? AND user_id != ?`) cheap.

### Changed

- IP reputation provider precedence reordered: ProxyCheck before IPHub.
  ProxyCheck has stronger detection coverage (catches ISP / residential
  proxies and datacenter fronting that IPHub misses) and supports
  anonymous queries on a lower quota when no key is configured. IPHub
  stays as fallback for IPs ProxyCheck returns no verdict on.
- New `App\IpReputation\ProviderRateLimit` cache marker lets a provider
  signal "I'm out of quota" so the next ~hour of lookups skip the API
  call entirely. ProxyCheck sets the marker on the documented
  `status: denied` body; IPHub sets it on HTTP 429 / 403. Combined with
  CompositeProvider's first-non-null-wins fallback, this means IPHub
  takes over for ProxyCheck the moment ProxyCheck hits its daily limit
  — without burning IPHub's quota on lookups ProxyCheck would have
  served if it had room. 7 new feature tests in
  `tests/Unit/IpReputation/RateLimitFallbackTest.php` lock the
  marker / skip / fallback contract.

### Notes

- IP-reputation lookups (ProxyCheck / IPHub) are NOT triggered by
  public landing-page or login-form GET requests. They fire only at:
  `POST /register` (gated by `ip.gate:70` middleware) and any
  captcha-verify path (login submit, register submit, PTC complete,
  shortlink complete) via the `IpReputationSignal` consumed by the
  bot scoring engine. `CachedProvider` then serves repeat lookups
  from a 24-h cache. With this shape, an 8000 PV/day landing page
  generates zero API calls.

- `App\Http\Middleware\CloudflareClientIp` — when the deployment sits
  behind Cloudflare orange-cloud proxy mode, promotes the
  `CF-Connecting-IP` header to `REMOTE_ADDR` so every IP-consuming
  code path (bot detection asn signals, IpReputationGate, captcha
  client_ip locking, BitcoTask offer URL `USER_IP` segment, webhook
  IP allow-list) sees the real visitor instead of a Cloudflare edge.
  Off by default — `TRUST_CLOUDFLARE_PROXY=false` ships in
  `.env.example`, so dev / non-CF deployments behave unchanged.
  - Uses `CF-Connecting-IP` (which Cloudflare overwrites on every
    relayed request) instead of `X-Forwarded-For` (which Cloudflare
    appends to, leaving the leftmost slot spoofable). Garbage / empty
    header values are ignored — the connection IP wins, never falls
    back to a spoofable fallback.
  - **Operator note**: when enabling this flag, the origin firewall
    MUST restrict inbound traffic to Cloudflare's published IP ranges
    (https://www.cloudflare.com/ips/). Otherwise an attacker reaching
    the origin directly could spoof CF-Connecting-IP and bypass bot
    detection / IP reputation / captcha fingerprint locking. Documented
    in `.env.example` and CLAUDE.md.
  - 5 new unit tests in
    `tests/Unit/Http/Middleware/CloudflareClientIpTest.php`
    cover off / on × header present / missing / garbage paths plus
    IPv6 handling.
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

[Unreleased]: https://github.com/s3ij1nn/satpeek/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/s3ij1nn/satpeek/compare/v0.4.3...v0.5.0
[0.4.3]: https://github.com/s3ij1nn/satpeek/compare/v0.4.2...v0.4.3
[0.4.2]: https://github.com/s3ij1nn/satpeek/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/s3ij1nn/satpeek/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/s3ij1nn/satpeek/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/s3ij1nn/satpeek/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/s3ij1nn/satpeek/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/s3ij1nn/satpeek/releases/tag/v0.1.0
