# Changelog

All notable changes to SatPeek are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.24.0] — 2026-05-10

Theme: Phase 2g — visibility into the onchain confirmation watcher.
Catches the silent failure mode where the
`WatchOnchainConfirmationsJob` cron stalls (queue worker dead, RPC
down for hours, hot-wallet recipient permanently blacklisted).
Without this signal, `Withdrawal::status=Broadcast` rows would
accumulate invisibly until users start asking why their payout
"hasn't shown up".

### Added

- **`OnchainConfirmationsWidget`** — Filament `StatsOverviewWidget`.
  Counts in-flight onchain Withdrawals (status=broadcast,
  payout_method LIKE 'onchain_%'), surfaces the oldest broadcast
  age, colour-coded:
  - 0 rows → success ("all confirmed")
  - oldest < 30 min → success (within finality window)
  - 30 ≤ oldest < 120 min → warning ("slower than usual")
  - oldest ≥ 120 min → danger ("watcher likely stalled")
- **`/up` `onchain_watcher` probe**. Same thresholds; reports
  `in_flight` count + `oldest_minutes`. Non-critical (the API is
  healthy; this signals an operations issue, not a service outage).
- 3 feature tests: no in-flight rows → ok; oldest 60 min → degraded;
  oldest 180 min → down (with HTTP 200 because non-critical).

527 tests pass; pint + phpstan green.

## [0.23.0] — 2026-05-10

Theme: Phase 2f — push-channel hot-wallet alerting. The 0.21
dashboard widget covers visual inspection; 0.22 added the `/up`
probe for external monitors. This release closes the operator-
notification loop with two changes:

- The Monday-morning weekly digest now includes a hot-wallet
  runway section (every registered monitor's available / required /
  gap, colour-coded).
- A new `satpeek:hot-wallet-alert` command runs every 15 min and
  emails admins when any monitor flips to `down`. Idempotent via
  6-hour cache key — the same alert isn't re-sent until the wallet
  recovers and re-degrades.

### Added

- **`HotWalletLowBalanceAlert` mailable** (HTML + text). Subject
  shows the affected currency codes; body lists each down row with
  available / required / gap (or "rpc unavailable" when the chain
  probe failed).
- **`satpeek:hot-wallet-alert` command**. Polls
  `WalletBalanceMonitorRegistry`, mails admin users on `down`,
  caches the down-set signature so a re-run is a no-op until either
  (a) the wallet recovers (cache cleared on next clean run) or
  (b) the down-set changes (different currencies / different
  status). Scheduled `everyFifteenMinutes`.
- **`WeeklySummaryBuilder::hot_wallet`** payload key — per-currency
  available / required / gap / status. Empty array for FaucetPay-
  only deploys. Wired into both HTML + text views.
- 6 feature tests for the alert command:
  - empty registry → no alert
  - all monitors ok → cache cleared, no alert
  - over-committed currency → mail queued + cache populated
  - repeat run with same set → idempotent (no second mail)
  - RPC failure surfaces as `unavailable` (still alerts)
  - `--dry-run` prints without queueing

524 tests pass; pint + phpstan green.

## [0.22.0] — 2026-05-10

Theme: Phase 2e — `/up` extension surfaces hot-wallet runway to
external monitoring. The 0.21 dashboard widget is for visual
inspection by an operator; this release lets external monitors
(Pingdom, UptimeRobot, custom probes) page-out when the hot wallet
runs dry.

### Added

- **`hot_wallet_balance` check** in `HealthController`. Iterates
  `WalletBalanceMonitorRegistry` and reports per-currency status:
  - `ok` — `gap >= required` (≥ 1× pending withdrawals worth of
    headroom)
  - `degraded` — `0 <= gap < required` (less than 1× pending —
    top up soon)
  - `down` — `gap < 0` (over-committed) OR `available()` throws
    `WalletBalanceUnavailableException` (chain probe failed)
  Non-critical → never paginates the load balancer (FaucetPay route
  is unaffected, app process is healthy). Operator with no onchain
  routes sees `ok` + `detail=no_monitors_registered`.
- 3 feature tests pin: empty registry → ok; over-committed gap →
  worst-case `down` status with overall `degraded`; RPC failure →
  `down` with `detail=rpc_unavailable`.

515 + 3 = 518 tests pass; pint + phpstan green.

## [0.21.0] — 2026-05-10

Theme: Phase 2d — operator visibility for the Tron hot wallet. The
0.18 `WalletBalanceMonitor` interface gets its first concrete
implementations (TRX + USDT-TRC20), backed by a Filament dashboard
widget that surfaces "available vs required" per currency. An
operator can now see the topup runway at a glance instead of
discovering the wallet is dry only when withdrawals start failing.

### Added

- **`TronWalletBalanceMonitor`** — TRX hot-wallet probe via
  `/wallet/getaccount`. `available()` returns the spendable sun
  balance (frozen TRX excluded). `required()` sums in-flight
  TRX withdrawals (queued / hold / processing / broadcast).
- **`TronUsdtWalletBalanceMonitor`** — USDT-TRC20 hot-wallet probe
  via the new `triggerConstantContract` (read-only contract call,
  no broadcast / no energy burn). Encodes the `balanceOf(address)`
  parameter, parses the 64-hex `constant_result[0]` uint256, and
  reports the spendable USDT base units. Required = sum of
  in-flight USDT-TRC20 payouts.
- **`TronHttpClient::triggerConstantContract(...)`** wraps
  `/wallet/triggerconstantcontract` for read-only contract reads.
  Same multi-URL fallback + transport error semantics as the rest
  of the client.
- **`WalletBalanceMonitorRegistry`** — lookup table mirroring
  `PayoutGatewayRegistry`. Wired in `AppServiceProvider` to register
  TRX + USDT monitors when the Tron gateway is enabled.
- **`HotWalletBalanceWidget`** — Filament `StatsOverviewWidget` on
  the dashboard. One Stat per registered monitor: shows
  `<available> <CODE> / required <required>` with a colour-coded
  description (success / warning / danger) and the gap value.
  Colour rules: gap < 0 = danger; gap < required = warning; else
  success. RPC failure renders "(unavailable)" instead of a
  misleading zero. Empty for FaucetPay-only deploys.

### Tests

- `tests/Feature/Payout/TronWalletBalanceMonitorTest.php` —
  6 tests pin both monitors:
  - TRX `available()` reads from `getAccount`
  - TRX `available()` returns "0" for fresh-wallet (`{}` body)
  - TRX `available()` throws `WalletBalanceUnavailableException`
    on RPC failure
  - TRX `required()` sums only the in-flight statuses for the
    correct method (FaucetPay rows + sent / failed rows excluded)
  - USDT `available()` parses the 64-hex `constant_result[0]`
  - USDT `available()` throws when `constant_result` is empty

515 tests pass; pint + phpstan green.

## [0.20.0] — 2026-05-10

Theme: Phase 2c — USDT-TRC20 onchain payouts ride alongside TRX on the
same Tron infrastructure. The 0.19 gateway / signer / watcher
machinery shipped with TRX as the only consumer; this release adds
a TRC20 contract-call gateway (`/wallet/triggersmartcontract` for
`transfer(address,uint256)`) and teaches the watcher to handle
contract reverts by refunding the user.

The pipeline is gated by the same `TRON_ONCHAIN_ENABLED` + hot wallet
env pair as TRX, plus a non-empty per-network `usdt_trc20_contract`
address in config (mainnet ships pre-populated with the canonical
USDT contract `TR7N…`; Shasta is operator-supplied via
`TRON_USDT_TRC20_CONTRACT_SHASTA`).

### Added

- **`TronUsdtTrc20Gateway`** implements `PayoutGateway` with
  `name='onchain_usdt_trc20'`. ABI-encodes the recipient + amount,
  calls `triggersmartcontract`, signs via the existing `TronTxSigner`,
  broadcasts via the existing `TronHttpClient`. 4 unit tests cover
  happy path + invalid destination + `triggersmartcontract` build
  error decode + transport-failure retry signal.
- **`TronAbi::encodeTransfer($recipientBase58, $amountBaseUnits)`** —
  pure-PHP Solidity ABI encoder for `transfer(address,uint256)`.
  Pads the address slot with 12 zero bytes, big-endian-encodes the
  uint256 left-padded to 32 bytes. 5 tests pin every byte position;
  a misaligned pad would silently send funds to the wrong address.
- **`TronAddress::toHash20($base58)`** — derives the 20-byte EVM-style
  hash from a Base58Check Tron address (drops the 0x41 version
  prefix). Used by the ABI encoder; throws on a malformed address
  (defence-in-depth — gateway already pre-validates).
- **`TronHttpClient::triggerSmartContract(...)`** wraps
  `/wallet/triggersmartcontract`. Default `fee_limit=100 TRX` covers
  cold-account TRC20 energy costs (~14 TRX) with comfortable
  headroom for fee shocks.
- **`Withdrawal::METHOD_ONCHAIN_USDT_TRC20`** const. The
  `isOnchainMethod()` prefix detector picks it up automatically.
- **`WatchOnchainConfirmationsJob` revert handling**: TRC20 contract
  calls can be in a block but REVERT (insufficient balance, paused
  contract, blacklisted recipient). The watcher checks
  `receipt.result` and:
  - `SUCCESS` (or absent for native TRX) → standard finality flow.
  - any other value → atomic `failed` + balance refund + ledger
    `withdraw_refund` row. Same race-defence guard
    (`WHERE status='broadcast'`) as the success path.
  1 new feature test pins the revert path.

### Changed

- **`WithdrawController`**: adds `onchain_usdt_trc20` to allowed
  methods (when registered) + `USDT_TRC20`-only currency list for
  that method + Tron Base58Check destination validation.
- **`config/satpeek.php`**: USDT_TRC20 `onchain_supported=true`.
- **`AppServiceProvider`**: `TronUsdtTrc20Gateway` registered
  conditionally on `TRON_ONCHAIN_ENABLED` + hot wallet env pair +
  non-empty per-network contract address.

509 tests pass; pint + phpstan green.

## [0.19.0] — 2026-05-10

Theme: Phase 2b — TRX onchain payouts ship end-to-end. The 0.18
foundation (broadcast lifecycle columns + DepositObserver / wallet
contracts) gets its first real consumer: a TronOnchainGateway that
signs with simplito secp256k1 and broadcasts to TronGrid +
publicnode, and a WatchOnchainConfirmationsJob that polls every
minute and promotes Broadcast → Sent at TRX finality (19 blocks,
~57 s).

The pipeline is gated by `TRON_ONCHAIN_ENABLED=true` AND a populated
`TRON_HOT_WALLET_ADDRESS` + `TRON_HOT_WALLET_PRIVATE_KEY` pair —
without both, the gateway is never registered, the controller's
allowed-methods list excludes `onchain_trx`, and users can't even
submit a Tron withdrawal. Defence-in-depth against a misconfigured
deploy that thinks it has the gateway when it doesn't.

### Added

- **`TronTxSigner`** — pure-crypto secp256k1 ECDSA signer using
  `simplito/elliptic-php`. Signs the SHA256 of a TronGrid-returned
  `raw_data_hex` payload and emits the 65-byte `r||s||v` hex shape
  Tron's `/wallet/broadcasttransaction` endpoint expects in
  `signature: [<this>]`. Canonical low-s form (BIP62 malleability
  defence), RFC6979 deterministic-k (same input → same output, safe
  under retry), v ∈ {0, 1} (raw — not Ethereum's 27/28). 7 tests
  pin the contract.
- **`TronOnchainGateway`** implements `PayoutGateway` with
  `name='onchain_trx'`. Validates Tron Base58Check destination →
  `createTransaction` → `TronTxSigner` → `broadcastTransaction` →
  `PayoutResult::sent(txid)`. Decodes TronGrid's hex-encoded failure
  messages (CONTRACT_VALIDATE_ERROR, BANDWITH_ERROR, etc) for
  operator-readable logs. 7 unit tests + 2 controller-side feature
  tests.
- **`TronUnreachableException`** (extends `TronRpcException`) —
  explicit retry signal when ALL configured RPC URLs are TCP/DNS
  down. Mirrors `FaucetPayUnreachableException` semantics:
  `ProcessWithdrawalJob` lets it escape so Laravel's retry machinery
  re-enqueues with backoff. HTTP errors stay as plain
  `TronRpcException` and become terminal failures (the broadcast
  might already have been processed; can't safely retry).
- **`Withdrawal::METHOD_ONCHAIN_TRX` const + `isOnchainMethod()`**
  prefix helper. Every chain gets its own per-method gateway name
  (future `onchain_btc`, `onchain_eth`, `onchain_usdt_trc20`); the
  legacy bare `'onchain'` is kept ONLY so historical rows + the
  prefix detector stay coherent.
- **`WatchOnchainConfirmationsJob`** — `ShouldQueue` + `ShouldBeUnique`
  (60 s lock window). Scheduled `everyMinute` from
  `routes/console.php`. Caches the chain head once per run, polls
  `getTransactionInfo` for every Broadcast row, ticks
  `confirmations_seen`, and promotes Broadcast → Sent at the
  per-currency threshold (TRX: 19) using the same atomic
  `WHERE status='broadcast'` settle predicate ProcessWithdrawalJob
  uses. Failure modes are non-fatal — a stuck oracle aborts the
  whole run (don't poison every row with confirmations=0); a per-row
  RPC failure skips that row and continues. 6 feature tests.
- **`docker/php/Dockerfile`**: `ext-gmp` enabled (needed by simplito
  for big-integer math) + `composer require simplito/elliptic-php`
  (v1.0.12).

### Changed

- **`ProcessWithdrawalJob` settle path**: success path picks
  `status='broadcast'` for onchain (vs `'sent'` for FaucetPay) and
  stamps `broadcast_at` + `onchain_tx_hash`. The watcher promotes
  Broadcast → Sent at finality.
- **`WithdrawController`**: allowed_methods derived from the gateway
  registry, so `onchain_trx` only surfaces when the gateway is
  actually wired. Per-method destination validation:
  `TronAddress::isValid` (Base58Check + double-SHA256 checksum) for
  `onchain_trx`, email shape for FaucetPay. Allowed currencies
  switch by method (TRX-only for onchain_trx).
- **`config/satpeek.php`**: TRX `onchain_supported=true` (gated by
  env, see `AppServiceProvider`'s registry binding).
- **`TronHttpClient`**: `createTransaction(from, to, amountSun)`
  helper added. All-URLs-down case now throws the more specific
  `TronUnreachableException` (still a subclass of `TronRpcException`
  so existing catches still work).

499 tests pass; pint + phpstan green.

## [0.18.0] — 2026-05-10

Theme: Phase-2b foundation — every schema column, enum case, and
interface that the upcoming Tron / BTC / ETH onchain settlement code
will write into. Ships zero behaviour change on its own; the watcher
job, deposit pollers, and per-chain wallet monitors land on top of
this scaffold in 0.19+.

The shape pinned here came out of an architect-agent review of the
Phase 2a Tron scaffold. Three pre-Phase-2b foundation gaps were
flagged HIGH: (a) `withdrawals` table had no way to express the
broadcast→confirmed lifecycle, (b) `WithdrawalStatus` had no
intermediate state for "broadcasted, awaiting finality", (c) no
contract existed for the deposit-side polling that incoming onchain
funds will need. Closing them now means the Phase 2b commit is a
behaviour change, not a schema change — much safer to roll back if
the chain integration trips on something.

### Added

- **`withdrawals.broadcast_at` + `confirmed_at` + `confirmations_seen`
  columns + UNIQUE on `onchain_tx_hash`.** The watcher job will write
  `broadcast_at` the moment the gateway returns a tx hash, then poll
  the chain and tick `confirmations_seen` until the per-currency
  finality threshold (BTC 3, ETH 12, TRX 19), at which point
  `confirmed_at` is stamped and `status` flips to `Sent`. The UNIQUE
  constraint is the last-line-of-defence behind the watcher's own
  dedupe — even if a bug let two rows claim the same chain tx hash,
  the DB rejects the second insert. Backfill: existing `Sent` rows
  get `broadcast_at = confirmed_at = processed_at` so reporting that
  joins on these columns doesn't see NULL for historical FaucetPay
  payouts.
- **`WithdrawalStatus::Broadcast` enum case.** Sits between `Hold`
  and `Sent`. Pre-Phase-2b, `Sent` did double duty for "gateway
  accepted" and "chain confirmed" — fine for FaucetPay because FP is
  publisher-confirmed at API return — but onchain payouts have a
  real gap (BTC: ~30 min, ETH: ~3 min, TRX: ~1 min) where the tx is
  visible on the chain but hasn't reached SatPeek's finality
  threshold. From 0.18 onward `Sent` strictly means "confirmed at
  finality"; FaucetPay rows continue to skip `Broadcast` entirely.
- **`App\Deposit\DepositObserver` interface + `DepositEvent` VO +
  `DepositObserverException`.** The deposit-side mirror of
  `PayoutGateway`: where `PayoutGateway` is push-driven (we initiate
  a withdrawal), `DepositObserver` is poll-driven (we scan the chain
  for incoming funds to advertiser hot wallets). Returns an iterable
  of `DepositEvent` readonly VOs (currency, address, amount as
  string for ETH wei, txHash, confirmations, blockHeight).
  Implementations MUST be idempotent — same tx returned across calls
  until finality — so the consuming job can `firstOrCreate` ledger
  rows without dedupe logic of its own.
- **`App\Payout\WalletBalanceMonitor` interface +
  `WalletBalanceUnavailableException`.** Per-chain hot-wallet
  balance probe used by the operator dashboard + the pre-broadcast
  guard in the future watcher job (refuse to broadcast if available
  < required + buffer). Both `available()` and `required()` return
  bcmath strings because ETH wei overflows int64. Throws sentinel
  exception on RPC failure — callers MUST NOT trust a fallback zero
  (would silently allow over-commit).

### Tests

- `tests/Feature/Payout/OnchainLifecycleSchemaTest.php` — 4 tests
  pinning the schema:
  - new lifecycle columns persist + round-trip through Eloquent casts
  - UNIQUE on `onchain_tx_hash` rejects duplicate settlement
  - multiple NULL `onchain_tx_hash` rows coexist (FaucetPay rows
    must not collide on the UNIQUE)
  - `WithdrawalStatus::Broadcast` enum value round-trips

476 tests pass; pint + phpstan green.

## [0.17.0] — 2026-05-10

Theme: docs hygiene — close the comment-rot findings from the
comment-analyzer audit and the dead-code findings from the
refactor-cleaner audit. No functional change; the release is pure
maintenance to set a clean baseline before Phase 2b (Tron signing).

The most operationally significant fix is the comment in
`ProcessWithdrawalJob`: the `ShouldBeUnique` lock window was
documented as 5 minutes but is actually **40 minutes** (`$uniqueFor =
2400`). An operator reading the comment to reason about the
double-pay race window would underestimate the protection 8x. Fixed
along with seven other inaccurate / outdated docblocks across the
payout stack.

Removed dead code: the `WaitlistController` / `WaitlistSignup` /
`WaitlistConfirmation` mail / waitlist email views were never wired
into any route. The whole stack is removed; an additive migration
drops the orphan `waitlist_signups` table on environments that ran
the original create migration.

`.env.example` gains documentation for 23 env vars that were added
across v0.13–v0.16 without `.env.example` updates: per-currency
withdrawal floors, PriceOracle settings, Tron onchain scaffold,
health-probe cache, and registration / payout burst signal tunables.

472 tests pass; pint + phpstan green.

### Removed

- **Waitlist scaffold (5 files + create migration).** The
  `WaitlistController` was never registered in `routes/web.php` or
  `routes/api.php`; the model + mail + views were dead. Removed
  `app/Http/Controllers/WaitlistController.php`,
  `app/Mail/WaitlistConfirmation.php`,
  `app/Models/WaitlistSignup.php`, both
  `resources/views/emails/waitlist*.blade.php`, and the
  `2026_04_25_000011_create_waitlist_signups_table` migration. New
  `2026_05_10_000001_drop_waitlist_signups_table` migration cleans
  up environments that already ran the create.

### Fixed

- **`ProcessWithdrawalJob` class docblock: `$uniqueFor` window
  documented as 5 min, actually 40 min.** Most dangerous comment-rot
  finding from the audit — operators reasoning about the double-pay
  race window would underestimate the lock 8x. Comment now reflects
  the real 2400-second value matching the `$uniqueFor` property.
  Same docblock now also names `PayoutGatewayRegistry` as the
  dispatch path (was still describing the pre-v0.13.0 direct
  `FaucetPayClient` call), `PayoutResult` as the gateway return type
  (was still describing the legacy `['ok' => false, ...]` array
  shape), and gateway-agnostic exception handling.

- **`BalanceLedger.reason` property hint: `string` → `LedgerReason`.**
  v0.16.0 added the enum cast but the docblock `@property` was not
  updated; static analysis would infer the wrong type. Fixed. The
  `REASON_*` constants class doc now also documents the
  three-edit workflow (enum case + constant + LABELS entry +
  analytics consumer) for adding a new reason.

- **Six other comment-rot fixes:** `FaucetPayClient` docblock notes
  `FaucetPayGateway` as the direct caller; `EarnSessionClaimService`
  `$reason` param doc signals enum preference; `WithdrawController`
  drops the "Phase 1 / Phase 2+" temporal anchor in favour of
  describing the gateway-extension pattern; `PayoutGatewayRegistry`
  + `PayoutGateway` interface docblocks generalize the
  FaucetPay-specific framing; `PriceOracle` docblock trims
  migration-era prose that would be context-free noise to future
  readers.

### Changed

- **`.env.example` documentation catch-up.** 23 env vars added across
  v0.13–v0.16 without `.env.example` updates now have entries with
  the same comment style as the existing block: per-currency
  `PAYOUT_MIN_*_SAT` floors, `PRICE_ORACLE_*` settings,
  `TRON_ONCHAIN_*` scaffold + hot-wallet placeholders,
  `HEALTH_PROBE_CACHE_SECONDS`, and the
  `BOTSCORE_REG_BURST_*` / `BOTSCORE_PAYOUT_BURST_*` signal
  tunables. Defaults all match the code-level fallbacks so behaviour
  is unchanged; new operators just get visibility into the knobs
  during onboarding.

## [0.16.0] — 2026-05-10

Theme: type-design batch — close the HIGH/MEDIUM findings from the
type-design-analyzer audit on money-touching types.

The audit surfaced 3 actionable items: 2 HIGH (PriceOracle tuple
slot-swap risk + Withdrawal.status as bare strings) and 1 MEDIUM
(BalanceLedger.reason as string despite v0.10.0 constants). All 3
fixed in this release. Lower-priority recommendations
(PayoutResult sent() non-nullable, PayoutCurrency constructor
asserts, User balance_sat observer) are deferred — over-engineering
risk doesn't justify the change today.

No user-visible behaviour change. Wire format unchanged for every
JSON endpoint. The new enum casts are reads transparently — WHERE
clauses by raw string still work; new code uses enum cases.

472 tests pass; pint + phpstan green.

### Added

- **`App\Enums\WithdrawalStatus` backed enum.** From the type-design
  audit (HIGH). Pre-enum, the six lifecycle values
  (`queued`/`processing`/`hold`/`sent`/`failed`/`rejected`) lived as
  bare string literals in `ProcessWithdrawalJob`, the Filament
  resource, and tests — a typo in any WHERE clause silently skipped
  rows with no PHPStan signal, and the financial-path code in the
  job + admin reject/approve actions all gated on the status
  string. `Withdrawal.status` now casts to the enum so every
  comparison is type-checked. The underlying column stays a
  lowercase string (existing rows + reporting queries unchanged);
  WHERE clauses that pass raw strings continue to work while new
  code uses `WithdrawalStatus::Queued` etc.

- **`App\Enums\LedgerReason` backed enum.** From the type-design
  audit (MEDIUM). The v0.10.0 `BalanceLedger::REASON_*` constants
  closed the typo risk for the constants we'd named, but a future
  bare-string write site (`'reason' => 'ptc_view'` instead of
  `BalanceLedger::REASON_PTC_VIEW`) would silently route credits to
  an "unknown" bucket in the operator filter dropdown. The cast on
  `BalanceLedger.reason` makes every new write site PHPStan-
  verifiable. The string `value` of each case mirrors the existing
  constants for migration compatibility — no DB rewrite, existing
  rows continue to deserialize.

- **`App\Payout\PayoutConversion` value object.** From the type-
  design audit (HIGH). `PriceOracle::convertBtcSatToTarget()`
  returned `array{0: string, 1: string}` — a positional tuple where
  slot 0 was the target-currency smallest-unit count and slot 1
  was the BTC-sats-per-unit rate. A caller destructuring with the
  slots swapped would persist a rate as the amount and vice versa,
  an invisible over/under-payment bug PHPStan can't catch. The
  named VO (`targetAmount` + `rateSatPerUnit`) eliminates the
  slot-swap risk; field semantics are unambiguous at every call
  site.

## [0.15.0] — 2026-05-10

Theme: silent-failure batch — close the financial-correctness
findings from the silent-failure-hunter audit.

The audit surfaced 6 items in money-touching code: 2 HIGH (admin
race conditions that could double-credit users on simultaneous
clicks), 1 MEDIUM (captcha consumed-without-credit on outer-txn
failure), 3 LOW (hygiene). All 6 are fixed in this release; the
HIGH and MEDIUM patches add atomic-claim guards inside the credit
transactions so loser-of-race admins / failed credit paths bail
cleanly instead of double-crediting or stranding the user's
captcha solve.

No user-visible behaviour change in the happy path; the changes
only surface in the rare race / failure branches that were
previously incorrect. 472 tests pass; pint + phpstan green.

### Fixed

- **Withdrawal admin reject: atomic claim guard inside the credit
  transaction.** From the silent-failure-hunter audit (HIGH). Two
  admin tabs both seeing `status=hold` could both pass the
  visibility check, both enter the transaction, and both refund —
  the user ends up double-credited until the
  `balance_ledgers (reason, reference_type, reference_id)` partial
  UNIQUE catches the second insert (by which point the first
  refund's `balance_sat` increment is already committed). Reject
  action now mirrors the `ProcessWithdrawalJob::markFailedAndRefund`
  pattern: the inner UPDATE has `WHERE status IN ('hold','queued')`
  and bails on `affected_rows === 0` before the ledger row + balance
  bump fire. Approve action gets the same guard
  (`WHERE status = 'hold'`). Reject mail-failure catch now logs at
  `warning` (was a silent empty `catch`). 2 new tests pin the
  loser-of-race semantics.

- **PtcAd admin reject: stale `views_remaining` race fixed.** From
  the silent-failure-hunter audit (HIGH). Refund amount was
  computed from the Eloquent snapshot loaded at render time; a
  concurrent PTC viewer's `EarnSessionClaimService::postCredit`
  hook could decrement `views_remaining` between the load and
  the transaction, leading to over-refund. Action now re-fetches
  with `lockForUpdate()` inside the transaction and computes the
  refund from the fresh row; status guard `WHERE status IN
  ('pending_review','approved')` short-circuits the loser of any
  race. Mail-failure catch now logs.

- **Captcha unconsume on credit failure** — `CaptchaConsumer::consume()`
  commits its own transaction before the caller's outer credit
  transaction begins, so a credit failure (DB error, gateway
  exception inside `postCredit`, etc.) leaves the captcha row
  permanently `consumed` with no reward credited. New
  `CaptchaConsumer::unconsume()` reverts the row to `verified`
  (atomic, user-scoped, only flips `consumed → verified`).
  `EarnSessionClaimService` calls it from both the throwing-credit
  branch and the atomic-claim-loss branch so the user can retry
  the same earn session. 4 new tests pin the unconsume contract
  (revert, no-op for already-verified, cross-user refusal, garbage
  input).

- **Hygiene batch (LOW from audit):** `ReferralPayout::settle()`
  now casts `$commission` to int explicitly inside the raw SQL
  expression so a future refactor that loosens the upstream type
  silently fails PHPStan rather than turning into an injectable
  hole. `FaucetPayClient::balance()` docblock now warns callers
  that the `balance_sat: 0` failure-branch return is a sentinel
  not a real zero.

## [0.14.0] — 2026-05-09

Theme: Tron onchain Phase 2a — infrastructure scaffold for the next
payout route, shipped without the cryptographic signing surface so
the schema, address validation, and HTTP transport can be reviewed
+ deployed independently of the heavier ext-gmp / secp256k1 work.

The split is deliberate: Phase 2b will need a Docker image rebuild
(adding ext-gmp), a new Composer dependency for ECC math, and
careful testnet validation of the signing math. Landing those in
their own release keeps blast radius small if something needs to
roll back. The Phase 2a code in this release is dead-end safe —
without a signer registered the gateway can't broadcast, the
config kill switch defaults off, and the `WithdrawController`
validator continues to refuse `payout_method=onchain` until Phase 2b
flips the registry flag.

No user-visible behaviour change. Wire format unchanged. 466 tests
all green; pint + phpstan green.

### Added

- **Tron onchain payout — Phase 2a infrastructure scaffold (no
  signing yet).** First half of the Phase 2 onchain payout split.
  Ships only the pieces that can land safely without ext-gmp /
  secp256k1 signing: `TronAddress` Base58Check validator (5 tests
  including ground-truth checks against well-known mainnet
  addresses + checksum-tampered rejects), `TronHttpClient` Guzzle
  wrapper for TronGrid + publicnode TRON RPC with multi-URL
  fallback (transport failure rolls to the next URL; HTTP-error
  5xx does NOT fall through — preserves the "could already be
  processed" semantics ProcessWithdrawalJob already encodes for
  FaucetPay). New `config('satpeek.payout.onchain.tron')` block
  holds the RPC URL list, network code (mainnet vs Shasta
  testnet), USDT-TRC20 contract addresses, hot-wallet env keys,
  and operator fee. Master kill switch `TRON_ONCHAIN_ENABLED=false`
  defaults so a fresh deploy can never accidentally broadcast
  before the operator has provisioned a hot wallet. Phase 2b (next
  release) lands ext-gmp, the secp256k1 signing path, and the
  actual `TronGateway` registration.

## [0.13.0] — 2026-05-09

Theme: payout multi-currency (Phase 1 of the operator-requested
payout expansion). SatPeek's withdrawal flow used to pay BTC sats
through FaucetPay full-stop; this release ships a currency-agnostic
schema, gateway abstraction, price oracle, and end-to-end UI so
users can withdraw in BTC, LTC, ETH, USDT-TRC20, TRX, DASH, or XMR
through the same FaucetPay endpoint at submit-time-converted
amounts. Onchain (direct-network) payout routes for BTC / ETH / TRX /
USDT-TRC20 are reserved for Phase 2+ — the gateway abstraction is in
place so adding each chain is a one-line registration in
`AppServiceProvider`.

No user-visible behaviour change for legacy rows (existing FaucetPay
withdrawals continue to settle through the same code path); the new
schema columns are additive and backfilled. Wire format: API request
shape changes (`faucetpay_email` → `destination`, `currency` →
`payout_currency`, new `payout_method`) but the response shape is a
strict superset.

### Added

- **Multi-currency payout via FaucetPay (Phase 1 of the payout
  expansion).** SatPeek can now pay users in BTC / LTC / ETH /
  USDT-TRC20 / DASH / XMR / TRX through FaucetPay's `/send` endpoint.
  Schema additions on `withdrawals`: `payout_method` ('faucetpay' |
  'onchain'), `payout_currency` (canonical SatPeek code, e.g.
  `USDT_TRC20`), `payout_amount` (decimal(36, 0) — ETH wei overflows
  signed-64-bit so we use a bigdecimal column), `payout_rate`
  (decimal(30, 18) — BTC sats per main-unit at withdraw time, captured
  for support reproduction), `destination` (generic recipient — email
  for FP, address for onchain Phase 2+), `fee_sat` (operator fee on
  onchain; 0 for FP), `onchain_tx_hash` (null for FP, populated by
  per-chain gateways later). Existing rows are backfilled with
  `payout_method='faucetpay'`, `payout_currency=upper(currency)`,
  `destination=faucetpay_email`. Legacy `currency` and
  `faucetpay_email` columns stay until a later cleanup migration so
  existing readers keep working.

  New abstractions: `App\Payout\Gateway\PayoutGateway` interface,
  `PayoutGatewayRegistry` (lookup by `payout_method`),
  `FaucetPayGateway` wrapping the existing `FaucetPayClient` with
  multi-currency support. `ProcessWithdrawalJob` dispatches via the
  registry — adding a future onchain gateway is a one-line
  registration in `AppServiceProvider`.

  New services: `PayoutCurrency` value object + `PayoutCurrencyRegistry`
  (config-driven, single source of truth — adding a currency is a
  one-place edit in `config/satpeek.php`); `PriceOracle` (CoinGecko
  free `/simple/price` endpoint, 60 s cache, bcmath conversion to
  preserve ETH-wei precision; throws `PriceOracleUnavailableException`
  on outage so the controller bails BEFORE debiting balance — user
  retries fresh once the oracle recovers). 19 new tests pin the
  registry / oracle / gateway / withdrawal-flow contracts.

  Reserved for Phase 2+: `payout_method='onchain'` with per-chain
  gateways for BTC + ETH + TRX + USDT-TRC20 (public RPC endpoints,
  no self-hosted nodes per the operator decision). LTC / DASH / XMR
  stay FaucetPay-only by spec.

- **Multi-currency UI for `/withdraw` + Filament admin.** The user-
  facing form now reads the FaucetPay-supported currency set from
  `PayoutCurrencyRegistry` (was a hardcoded `['BTC','DOGE','LTC',...]`
  array), swaps the per-currency minimum live as the user changes the
  picker, and submits with the new field names (`destination`,
  `payout_currency`, `payout_method`). Recent-withdrawals list
  gracefully handles both Phase 1 rows (shows `payout_currency` +
  formatted payout amount) and pre-Phase-1 legacy rows (falls back to
  `currency` + `faucetpay_email`). `/admin/withdrawals` adds Route /
  Currency / Payout / Destination / Fee / Tx-hash columns; legacy
  payout-id / faucetpay-email surface as toggleable secondary columns.
  Migration relaxes `withdrawals.faucetpay_email` and
  `withdrawals.currency` to nullable so Phase 2+ onchain rows
  (which have no FP email + no FP currency code) can persist
  cleanly. 4 new view tests pin the form contract.

## [0.12.0] — 2026-05-07

Theme: operator response surface + Postgres / proxy edge bug fixes.
On the feature side, the operator gains a hard-perimeter deny list
that closes off an attacking IP without a redeploy and a captcha gate
on email-verification resend that closes the inbox-bombing pattern. On
the bug-fix side, two latent Postgres / proxy issues from earlier
releases are closed: Filament 4's database-notifications drawer was
500ing on `data->>'format'` because the `notifications.data` column
was `text`, and asset URLs through any TLS-terminating reverse proxy
(ngrok, Cloudflare, ALB) were generated as `http://` because the
bootstrap callback that wired `TRUSTED_PROXIES` ran before the env
loader. Both are fixed.

No schema changes beyond two additive migrations (`ip_block_entries`
table + alter `notifications.data` to json on Postgres). Wire format
unchanged for every existing JSON endpoint. 432 tests; CI green.

### Added

- **Operator-managed IP deny list at `/admin/ip-block-entries`.**
  Counterpart to the env-driven `BOTSCORE_SHARED_IP_ALLOWLIST`. Each
  row is one CIDR or single-IP entry, and a global
  `App\Http\Middleware\IpBlocked` middleware 403s any matching request
  before any auth check, ScoreEngine pass, or controller logic runs.
  Use case is the on-call response to an active attack: operator
  pastes the source IP / range, next request from that address gets
  rejected at the perimeter without a code change. JSON requests get
  `{"error":"ip_blocked","reason":"operator_block"}`; browser
  navigations get a bare `Forbidden.` 403. Edits are not supported
  by design — delete + re-create so the audit log reflects the exact
  set of addresses ever blocked. The `IpDenyList` cache is bust on
  every create/delete so an operator action takes effect on the very
  next request. Every CRUD writes an `admin_audit_log` row with the
  admin id + cidr + note for full provenance. CIDR matching delegates
  to the existing `IpAllowlist::matches()` so IPv4 + IPv6 + bit-
  boundary correctness is shared with the allowlist. 12 new tests pin
  the contract (deny-list service, middleware behaviour, admin
  resource scoping).

### Security

- **Captcha gate + per-user rate limit on email-verification resend.**
  `POST /email/verification-notification` was protected only by a
  per-IP `throttle:6,1` — a botnet trivially bypassed it by rotating
  IPs while reusing one stolen authenticated session, so the
  inbox-bombing pattern (bot creates account, scripts resend to
  spam target's mailbox or burn the operator's SMTP budget) was
  open. Two layers go in front of the resend now: a named limiter
  `verification-send` keyed by user id (1/min, 6/hr) replaces the
  per-IP throttle, and the same trajectory-captcha widget the
  register + login forms use must be solved on every submit.
  Ruling out botnet-rotation makes per-user the right key; the
  captcha is defence-in-depth that makes each automated attempt
  expensive (compute or 2captcha-credit cost). 5 new tests pin
  the contract: missing captcha rejects, synthetic uniform-Δt
  trace rejects, humanoid trace sends, second resend within the
  same minute returns 429, already-verified user short-circuits
  to dashboard without consuming a captcha.

### Fixed

- **`notifications.data` must be `json`, not `text`, on Postgres.**
  The base `notifications` migration shipped with `$table->text('data')`
  matching the Laravel `notifications:table` Artisan stub, but Filament
  4's database-notifications drawer filters on
  `data->>'format' = 'filament'`, and Postgres only exposes `->>` on
  json/jsonb columns. On a `text` column the request 500s with
  `operator does not exist: text ->> unknown` — reproduced from the
  user's `/admin` dashboard. SQLite (the test driver) has no static
  column types so the JSON operator works on the same TEXT-affinity
  column, which is why the test suite never caught it. Two changes:
  the original create migration's `data` is now `$table->json('data')`
  for fresh setups, and a new ALTER migration coerces existing
  `notifications.data` from text to json on Postgres
  (`USING data::json` — safe because Eloquent's array cast on the
  Notification model has always written JSON-serialised payloads).
  No-op on SQLite.

- **`TRUSTED_PROXIES` config now via `config/trustedproxy.php`,
  not the bootstrap callback.** Filament admin behind ngrok was
  generating `http://` asset URLs even with `TRUSTED_PROXIES=*`
  set in `.env`, causing every CSS/JS/font asset to be blocked
  as mixed content on the `https://` edge. Root cause: the
  `bootstrap/app.php` `withMiddleware()` callback is registered
  via `afterResolving(HttpKernel::class)`, which fires BEFORE the
  `LoadEnvironmentVariables` bootstrapper in the HTTP request flow.
  `env('TRUSTED_PROXIES')` returned null at that point so the
  `is_null` guard silently skipped `$middleware->trustProxies(...)`.
  CLI / artisan / tinker work fine because their kernel-resolution
  path runs after env load, which is why this never tripped the
  test suite. Fix: ship `config/trustedproxy.php` reading
  `env('TRUSTED_PROXIES')` and let the framework's built-in
  TrustProxies middleware pick the value up via its legacy
  `config('trustedproxy.proxies')` fallback. Config files are
  loaded after env, so `env()` works correctly there. Removed the
  dead `trustProxies()` block from `bootstrap/app.php` so the next
  reader doesn't think it's wiring anything.

- **DashboardWidgetTest captcha-outcome flake in the first hour past
  UTC midnight.** The test seeded rows with `Carbon::now()->subHour()`,
  which crosses the day boundary when "now" is in the first hour of
  a UTC day; the rows landed in yesterday's bucket and the assertion
  on today's bucket failed. Switched to `subMinute()`.

## [0.11.0] — 2026-05-06

Theme: structural debt sweep + framework currency. After the v0.10.0
performance pass, a `code-explorer` agent surveyed the codebase for
the next layer of pressure points and surfaced 9 items; this release
ships the 6 HIGH + MEDIUM ones. The triplicated 70-line credit
transaction across the three earning surfaces collapses into one
tested service, the ledger's `reason` column is no longer 12 scattered
magic strings, and earn-session `status` is now a backed enum the
type checker can verify. The Laravel framework is bumped to 13.7.0
along the way (replacing dependabot PR #7) so the structural changes
land on a current dependency baseline.

No user-visible behaviour change; no schema changes. The wire format
of every JSON endpoint is byte-identical to v0.10.0. Filament admin
gains nothing new but the ledger filter dropdown is no longer 3
entries stale (`internal_article`, `ad_funding`, `ad_refund` were
missing as of v0.10.0 — the post-refactor dropdown reads the canonical
constant map so it can't drift again).

### Added

- **`EarnSessionClaimService` — single source of truth for the credit
  pipeline.** PtcController, ShortlinkController, and InternalArticleController
  used to carry near-identical 70-line transactions for the
  status guard → token equality → captcha consumption → elapsed-time
  floor → atomic UPDATE WHERE pending → BalanceLedger row → balance
  bumps → referral payout pipeline. The shape is now expressed once on
  the service; surface-specific behaviour rides through `preClaim`
  (PTC's heartbeat-deficit gate) and `postCredit` (PTC's ad-budget
  decrement) callbacks. Net diff: ~140 lines of duplicated transaction
  logic deleted, three controllers now ~10 lines each at the credit
  step, one place to audit when the financial invariant changes. 7
  new tests pin the contract end-to-end (atomic single-credit, replay
  rejection on already-verified row, token mismatch, missing captcha,
  too_fast row marking, preClaim hook, postCredit hook).

- **`BalanceLedger::REASON_*` constants + `REASON_LABELS` map.** The
  ledger's `reason` column was being written from 12 magic-string
  literals scattered across 9 files; the Filament filter dropdown was
  copy-pasted and already 3 entries stale (`internal_article`,
  `ad_funding`, `ad_refund` were missing as of v0.10.0). All write
  sites now reference the constants on `BalanceLedger`; the Filament
  resource reads `REASON_LABELS` directly. Adding a new earning
  surface is now a one-place edit on the model class — typos at
  write time fail PHPStan rather than silently routing credits to
  an "unknown" bucket.

- **`App\Enums\EarnSessionStatus` backed enum.** PtcView,
  ShortlinkClick, and InternalArticleView now cast their `status`
  column to a shared backed enum instead of storing bare
  `pending|verified|rejected|expired` strings. Equality checks
  through the codebase are now PHPStan-verifiable
  (`$session->status === EarnSessionStatus::Pending` instead of
  string compare). The underlying column stays a lowercase string
  so WHERE clauses, JSON output, and existing data are unchanged.

- **`MockAdapter` implements `OfferwallPerUserAdapter`.** The
  local-dev mock previously implemented only the global
  `OfferwallAdapter` contract, so `OfferwallMerge`'s per-render
  code path silently skipped it — a developer running locally
  with `OFFERWALLS_ENABLED=mock` saw an empty per-user offer list
  while production BitcoTaskAdapter populated it. Mock now
  surfaces the same PTC inventory through both code paths plus
  fresh fixtures for shortlink + read-article surfaces. 3 new
  unit tests pin the dual-contract guarantee.

### Changed

- **`PolicyEnforcer::canStartPtcView` → `canStartEarningSession`.**
  The method has always guarded all three earning surfaces (PTC,
  shortlink, article) with the same `likely_bot`/`banned` rule,
  but the name implied PTC-only and tripped readers up. Direct
  rename, no shim — the only callers are inside this repo.

### Dependencies

- **`laravel/framework` v13.6.0 → v13.7.0** (minor bump within the
  Laravel 13.x line — bug fixes + opt-in enum support across various
  managers, no breaking behaviour). Brought along by the framework
  bump: `symfony/polyfill-php86 v1.37.0` (new transitive),
  `symfony/translation-contracts v2.0.12 → v2.0.13`,
  `symfony/yaml v7.4.8 → v7.4.9`. Replaces dependabot PR #7;
  applied directly after verifying 415 tests + pint + phpstan pass
  on top of the structural-debt batch.

## [0.10.0] — 2026-05-05

Theme: performance sweep + operator-trend visibility + retention
discipline. The platform's hot paths — login + register + captcha
submit + every PTC view + every shortlink start + the load-balancer-
hammered `/up` endpoint — all shed redundant queries; the dashboard
gains a captcha-trend widget pairing with the per-row triage
introduced in v0.9.0; `bot_score_history` (which grows on every
auth + captcha path) gets a retention sweep so a year-old install
isn't sitting on millions of dead JSON blobs. No schema changes
beyond a single index migration; no behaviour changes user-visible.

Released after a `performance-optimizer` agent pass surfaced 7
discrete N+1 / duplicate-write / missing-cache findings. All 7
shipped here; CI green; 405 tests (5 new for the prune command,
2 for the chart widget); 1 new index migration.

### Added

- **CaptchaOutcomeChartWidget on the admin dashboard.** 14-day
  stacked-bar chart of resolved captcha attempts by outcome
  (verified+consumed roll up as a single mint bucket; rejected
  is rose; expired is gray). Pairs with the per-row triage
  table at `/admin/captcha-challenges` shipped in v0.9.0 — the
  table answers "why was this attempt rejected?", the widget
  answers "what's the rejection trend across the platform?".
  Three operator-actionable signals: rising reject rate (tune
  tolerance or new bot wave), rising expired rate (UX abandon),
  flat-line on all three (cross-check the existing /up
  `bot_detection` probe — pipeline likely stalled). Single
  GROUP BY day,status query against the existing
  `(status, created_at)` index. 2 new tests pin the
  status-bucket pivot + zero-state shape.

- **`satpeek:prune-bot-score-history` retention command.**
  ScoreEngine appends to `bot_score_history` on every login,
  register, and captcha-success path — a moderately active
  platform mints rows continuously and the JSON `signals`
  blob isn't tiny. Default retention 90 days (well past the
  widest dashboard window) with `--days` / `--dry-run` /
  `--chunk` options. Chunked delete avoids long-held
  Postgres locks under concurrent inserts (the table is on
  the auth + captcha hot path). Scheduled at 03:15 UTC,
  staggered 15 min after the captcha cleanup so the two
  sweeps don't share an IO window. 5 new tests pin the
  retention contract (window boundary, dry-run, custom days,
  chunked drain, zero-state).

- **Composite index on `shortlink_clicks` for the per-(user,
  provider) daily-limit guard.** Every
  `/api/shortlinks/start/{provider}` call asks "how many
  verified clicks has this user already burned on this
  provider today?". The existing `(user_id, created_at)` index
  forced Postgres to filter the matched range by
  `provider_name + status` in the heap; the new
  `(user_id, provider_name, status, created_at)` covering
  index lets the planner walk one index range. Same shape
  on SQLite (covering index, no heap). Migration is
  index-only — no schema change.

### Changed

- **Filament list resources eager-load their listed
  relations.** `/admin/users` now eager-loads `botScore` (was
  one extra SELECT per row to render the tier + score
  columns), `/admin/ptc-views` eager-loads `user` + `ad` (two
  extras per row), `/admin/shortlink-clicks` eager-loads
  `user`. With default page size 25 that drops the worst
  case from ~75 queries per page render to 4. Each
  `getEloquentQuery()` selects only `id` + the column the
  table renders, so the eager load itself stays small.

- **`UserResource` "Recent IP history" panel: 1+N → 2 queries.**
  The form previously rendered 10 IP rows and ran one
  `count(distinct user_id)` per row to compute siblings (other
  users seen on the same IP). Replaced with a single grouped
  `SELECT ip, count(distinct user_id) ... GROUP BY ip` keyed
  by the 10 IPs already in hand, then pivoted to a map. Same
  output, one round-trip instead of ten.

- **`ReferralPayout::settle()`: two UPDATEs collapsed into
  one.** The referrer-credit path was issuing
  `users.balance_sat += commission` and
  `users.total_earned_sat += commission` as two separate
  `DB::table('users')->increment()` calls — one round-trip
  per counter against the same row. Collapsed to a single
  `UPDATE` with both columns in one `SET`, halving the write
  cost on every credited earning event with a referrer.

- **`PtcController::finishView()`: redundant `$ad->fresh()` dropped.**
  After `$ad->decrement('views_remaining')` Eloquent already
  refreshes the in-memory attribute, so the immediate
  `$ad->fresh()->views_remaining` was an extra `SELECT` that
  re-fetched the same row inside the credit transaction.
  Now reads `$ad->views_remaining` directly. Saves one query
  per completed PTC view that hits the budget-exhaust branch.

- **`WeeklySummaryBuilder::payoutBuckets()`: 3 queries → 1.**
  The operator weekly summary was issuing one `SELECT` per
  withdrawal status (`sent` / `failed` / `hold`) plus a fourth
  for the sent total. Collapsed to a single
  `GROUP BY status, count(*), sum(amount_sat)` and pivoted to
  the by-status map. Same payload, one round-trip.

- **`/up` health probes for `bot_detection` + `earning_inventory`
  cached for 30 s.** Load balancers and uptime monitors hit
  `/up` every few seconds; the user count + 24-h evaluation
  count + active inventory counts (5 queries total) were
  re-executing every call. New `HEALTH_PROBE_CACHE_SECONDS`
  config (default 30 s, pinned to 0 in tests via
  `phpunit.xml`) wraps both probes in `Cache::remember`. With
  `CACHE_STORE=array` in tests the cached path is bypassed
  entirely so each test still sees fresh DB state. Drops
  steady-state /up DB load by ~5 queries every 30 s.

## [0.9.0] — 2026-05-04

Theme: security follow-through + operator triage + landing-page
honesty. Closes the second CRITICAL from the v0.8.0 review
(TRUSTED_PROXIES default), tightens the captcha consumer's
user-binding rule against cross-session redemption, adds a /up
probe for the silent-misconfiguration class, lands a captcha-
attempt triage surface in /admin, and makes the public landing
hero show live platform stats instead of marketing claims.

### Added

- **Public landing surfaces live platform stats.** New
  `PublicStatsBuilder` service (cached 10 min) computes three
  trust signals: lifetime sat shipped via FaucetPay, current
  active earning inventory across PTC + shortlinks + internal
  articles, and the 30-day bot-rejection rate. Two of the hero
  value-strip cells now adopt the live numbers when there's
  enough data to be meaningful, falling back to the static
  "what we are" labels on a fresh install (a brand-new deploy
  shouldn't show "0 sat paid to users" — that would actively
  discourage signup). `Route::view` swapped for a thin
  `HomeController` to thread the stats array through. Refreshed
  the inline copy on the "View" how-it-works step + the bento
  "earn" card to mention shortener interstitials + read-and-earn
  articles alongside PTC, matching the v0.7.0 three-track surface
  area. 8 new tests pin builder semantics + view fallback
  behaviour.

### Security

- **CaptchaConsumer strict user binding (MEDIUM).** The previous
  `(row.user_id !== null && user !== null && mismatch)` check
  allowed null-on-either-side to slide. An attacker could solve
  a real captcha at the login form (where ChallengeBuilder issues
  `user_id=null`), capture the `challenge_id` from the verify
  response, then POST it to /api/{ptc,shortlinks,internal-articles}/.../complete
  from a separate authenticated session — one solve, one free
  reward. Tightened to require row.user_id matches caller.id
  exactly (caller is always non-null on the auth-gated /complete
  paths). 1 new regression test pins the cross-user anonymous
  redemption case. Test helpers across 5 files updated to bind
  user_id to the test actor; `seedChallenge(?User $user = null)`
  now stores the user when passed.

- **/up `trusted_proxies` health probe (MEDIUM).** A CDN
  deployment forgetting to set TRUSTED_PROXIES would silently
  neuter the entire bot-detection stack — every IP-keyed signal
  (SharedIpSignal, per-IP rate limits, BitcoTask webhook
  allowlist, IpReputationGate) would see the CDN edge IP. New
  probe: if the request hitting /up carries `X-Forwarded-For`
  AND `TRUSTED_PROXIES` is empty, flag `degraded` with detail
  `proxy_unconfigured`. Catches the silent misconfiguration
  before it spreads. 2 new tests pin: degraded when XFF +
  empty env, ok when either XFF absent or env populated.

### Added

- **`/admin/captcha-challenges` triage surface.** Read-only
  Filament resource exposing the captcha verifier's per-signal
  detail (solve_ms, shape_distance_px, dt_jitter_ratio,
  jerk_entropy, completion_dwell_ms, confidence) directly as
  table columns. The verifier already persisted these into
  `captcha_challenges.meta` JSON; the operator was reading them
  via psql / tinker for "why was my submission rejected?"
  triage tickets. Now a single deep-link from the Operations
  group answers the question. Mirror of PtcViewResource /
  ShortlinkClickResource — read-only by design (mutating a row
  would invalidate the audit trail). Status filter + colour-
  coded badge for issued/verified/rejected/expired/consumed.

### Security

- **`TRUSTED_PROXIES` default flipped from `*` to empty (CRITICAL).**
  The previous default trusted X-Forwarded-* from any source. An
  attacker reaching the origin directly could spoof the visitor IP
  and bypass every IP-keyed signal: BitcoTask webhook IP allowlist,
  IpReputationGate, SharedIpSignal, per-IP rate-limit buckets.
  Now the default is to trust nothing — operators behind a real
  proxy MUST set `TRUSTED_PROXIES` to a comma-separated CIDR list
  (Cloudflare's published ranges, ALB CIDRs, etc) or to `*` if
  they accept the spoofing risk and have an upstream firewall
  restricting inbound. `.env.example` documents the three forms
  with their security implications. Local Docker without a proxy
  in front works unchanged with the empty default — Laravel reads
  REMOTE_ADDR directly when trustedProxies isn't engaged.
  Local dev `.env` adds `TRUSTED_PROXIES=*` so ngrok testing
  keeps working without per-deploy guesswork.

## [0.8.0] — 2026-05-04

Theme: independent security review pass + operator visibility
deepening. Closes one CRITICAL (captcha-bypass at /complete) and
two HIGH issues (SSRF on iframe preflight, unauthenticated
faucetpay placeholder), while landing operator-tunable bot
signal weights, weekly operator summary email, two more `/up`
health checks, three new captcha curve flavours, and a fix-pass
on the prior release's TOCTOU + silent-failure findings.

### Security

- **SSRF guard on `IframeEmbedProbe` (HIGH).** The advertiser-side
  iframe preflight HEADs an attacker-controlled URL with no
  scheme allowlist or private-IP block. An authenticated user
  could point it at `file:///etc/passwd`, `gopher://internal:6379/_INFO`,
  or `http://169.254.169.254/latest/meta-data/` (AWS IMDS) and
  reach the internal target server-side. Added a pre-flight guard
  that rejects:
  - non-http/https schemes (file / gopher / dict / ftp / etc)
  - URLs with no host
  - hosts that resolve to RFC-1918 / loopback / link-local /
    cloud-metadata / multicast / unspecified addresses (covers
    127.0.0.1, ::1, 10/8, 172.16/12, 192.168/16, 169.254/16,
    fe80::/10, fc00::/7)
  Strict-mode resolution: if ANY A or AAAA record for the host
  is non-public, the URL is rejected. Catches the dual-stack
  dodge of pairing a public A record with a private AAAA record.
  Also restricted Guzzle redirect protocols to http+https as a
  belt-and-suspenders catch on 30x-into-file:// pivots.
  Connection-error messages are no longer echoed back to the
  caller — internal-service banners would otherwise leak through
  the `detail` field. 8 new tests pin: file/gopher/loopback/RFC1918/
  IPv6-loopback/AWS-metadata/malformed all rejected without HTTP,
  and the connection-error detail-leak is closed.

- **Removed unauthenticated `/webhooks/faucetpay` placeholder
  (HIGH).** The route returned `{"ok":true}` to every POST with
  no signature / IP allowlist. FaucetPay does not provide outbound
  webhooks today so the placeholder served no purpose; if a future
  integration adds them, re-add the route AND a controller that
  verifies the signature + restricts the source IP.

- **Captcha-bypass at /complete closed (CRITICAL).** All three
  earn-complete endpoints (`/api/ptc/{id}/complete`,
  `/api/shortlinks/{id}/complete`,
  `/api/internal-articles/auth/{token}/complete`) used to validate
  `captcha_challenge_id` as `required|string` but never read the
  value. A bot bypassing the captcha widget could claim the
  reward by POSTing any string. New `App\Captcha\CaptchaConsumer`
  service atomically consumes the row inside a `lockForUpdate()`
  transaction, requiring `status='verified'`, user-binding match,
  and single-use semantics (verified→consumed flip prevents
  cross-claim reuse). 4 regression tests pin: unverified-id
  rejected, fake-id rejected, consumed-id rejected on second
  use, cross-user theft rejected.

- **Login IP-only floor + /forgot-password throttle.** Login was
  throttled per `(email, IP)` only — credential stuffing across
  rotating addresses from one IP was uncapped. Added a parallel
  IP-only bucket (20/min) ticked on every attempt regardless of
  per-email outcome, so total login load from one source has a
  hard ceiling. `POST /forgot-password` had no throttle at all,
  enabling mail-bombing + timing-based account enumeration. Added
  `throttle:5,1` matching the email-verification resend rate.

- **`User` model `$fillable` defense-in-depth note.** Documented
  the maintainer-facing constraint that admin-only fields
  (`balance_sat`, `is_admin`, `is_banned`, `ban_reason`) stay
  in `$fillable` only because Filament's standard save path uses
  `fill()`. Verified that no controller in `app/Http/Controllers`
  uses `$request->all()` (zero greps in tree) so mass-assign
  promotion-to-admin is structurally impossible today. Future
  call-site additions must build payload from explicit validated
  fields.

### Fixed

- **Code-review pass on the post-v0.7.0 work.** Three issues
  caught + closed:
  - `ScoreEngine::evaluate()` had a TOCTOU window between the
    previous-tier read and the `updateOrCreate`. Two concurrent
    evaluators (e.g. captcha verify racing the Re-score row
    action that bypasses the throttle) could both observe
    `previousTier='trust'`, both flip to `banned`, and both
    fan-out duplicate admin notifications. Wrapped the
    read+write in a single transaction with `lockForUpdate()`
    so only one transition fires per evaluation race.
  - `AppServiceProvider::applyBotSignalWeightOverrides()` had a
    catch-all `\Throwable` swallow with no log. A misconfigured
    `DB_HOST` would silently regress every operator-saved weight
    override. Now distinguishes "schema-missing" (legit
    pre-migration / fresh test DB → silent skip) from any other
    error (PDO connect, container resolution → `Log::warning()`).
  - `BotSignalWeightResource` form + table read
    `config('satpeek.bot_score.weights')` for the "Default"
    column. After the boot merge, that key reflects the
    post-override values — so the operator's first saved row
    would shadow the file default the next time they viewed it.
    Fixed by snapshotting the pre-override file weights into
    `satpeek.bot_score.default_weights` at boot and reading from
    that key for the "default" surface.

### Removed

- **Dead `fetchShortlinkOffers()` bulk-pull from the offerwall
  surface.** Post-v0.6.0 the `/shortlinks` page reads operator-
  managed `ShortlinkProviderCredential` rows for internal
  inventory and per-user `OfferwallPerUserAdapter::fetchShortlinkOffersFor()`
  for partner-network offers — no consumer was reading the
  bulk-upserted legacy `shortlinks` rows. Dropped:
  - `OfferwallAdapter::fetchShortlinkOffers()` from the contract
  - The implementations on `MockAdapter` + `BitcoTaskAdapter`
  - `SyncOfferwallsCommand`'s shortlink loop + `upsertShortlink()`
    helper (was a dead write every cron tick)
  - The `Shortlink::firstOrCreate(...)` mock seed in
    `DatabaseSeeder` — replaced with a sample `InternalArticle`
    so a fresh install has something to show on `/read-articles`
  Test stubs realigned. `Shortlink` model + table stay for legacy
  `shortlink_clicks.shortlink_id` rows pre-v0.6.0 (the
  `effectiveRewardSat()` fallback still consults them).

### Added

- **Three new captcha curve flavours: damped_sine, growing_sine,
  triangle.** Brings `TrajectoryTraceProvider::CURVES` from 3 to
  6. Each issued challenge picks one uniformly at random; a bot
  fine-tuning a Bezier replay for `sine` now matches only ~1/6
  of issued challenges instead of ~1/3 — proportional reduction
  in attack hit rate without raising any user-facing difficulty
  knob.
  - `damped_sine`: amplitude tapers as u→1 (strong start, soft
    end). A uniform-amplitude bot model overshoots the late peaks.
  - `growing_sine`: inverse envelope (soft start, strong end).
    Symmetric counterpart — a single bezier-replay model can't
    cover both at once.
  - `triangle`: arcsin(sin) wave with sharp peaks. A Bezier-only
    replay collapses the corners and trips the shape check.
  Lock-in test pins the roster cardinality at >=6 + per-curve
  human-like-trace verification, so adding/removing a flavour
  fails CI loud.

- **Two new `/up` health checks.** Pull-side counterpart to the
  weekly summary's push:
  - `bot_detection`: counts ScoreEngine evaluations in the last 24 h
    via `bot_score_history`. Zero on a non-empty user base means
    the signal pipeline stalled (captcha auto-trigger regressed,
    cron dead, etc) — flagged degraded with detail
    `no_evaluations_24h`. A fresh install with zero users skips
    the check (`no_users_yet`) so a brand-new deploy doesn't
    false-positive.
  - `earning_inventory`: counts active rows across the three
    earning surfaces (PtcAd approved+active, ShortlinkProviderCredential
    active+token-set, InternalArticle active). Zero across all
    three flags `no_inventory_active` — silent state where users
    land on /ptc, /shortlinks, /read-articles to find empty pages
    goes undetected without this. Per-surface counts surface in
    the response so dashboards can graph each.

- **Weekly operator summary email.** New
  `satpeek:weekly-summary` Artisan command + `OperatorWeeklySummary`
  Mailable + `WeeklySummaryBuilder` service. Builds last-7-days
  buckets — earning activity (verified PTC views / shortlinks /
  article reads with prev-week delta), payouts (sent count + sat
  total, failed, hold), new users, and bot tier evaluations
  (suspect / likely_bot / banned counts) — and dispatches HTML +
  plain-text mail to every admin user. Scheduled Mondays 09:00
  UTC. `--dry-run` prints the JSON payload without sending. Pairs
  with the dashboard widgets — same data shape, push delivery for
  operators who don't habitually open `/admin`. 7 new tests pin
  bucket semantics + admin-only dispatch + dry-run / no-admins
  graceful exits.

- **Operator-tunable bot signal weights via Filament.** New
  `bot_signal_weights` table + `BotSignalWeight` model + Filament
  resource at `/admin/bot-signal-weights` (Inventory group). DB
  rows shadow the `satpeek.bot_score.weights` config defaults at
  boot time so noisy signals can be dialled down (or new
  high-precision signals dialled up) without a redeploy.
  `is_enabled=false` is the kill switch — the signal still
  evaluates for transparency in `BotScore.signals` JSON but
  contributes 0 to the composite score. Mirrors the merge
  pattern already used for `OfferwallProviderSetting` /
  `ShortlinkProviderCredential`. 3 new tests pin override,
  disable-zeroes, and config-passthrough cases.

## [0.7.0] — 2026-05-04

Theme: operator visibility + invariant hardening across the
earning surfaces. Adds the internal "read & earn" article
inventory as a third earning track alongside PTC + shortlinks,
notifies admins on bot tier escalations, plots the trail with
new dashboard widgets, ships a centralised operator audit log,
and applies the same atomic-claim single-credit guarantee from
shortlinks/PTC to withdraw flow + internal articles. API surface
gains six named per-user / per-IP rate limiters and the
advertiser flow gains an iframe-embeddability preflight so
campaigns with iframe-hostile destinations get caught before
viewers see a blank page.

### Added

- **`Test embed` operator action on `/admin/ptc-ads`.** Same
  IframeEmbedProbe used by the advertiser flow now surfaces as a
  one-click row action visible only on iframe-mode ads with a
  non-empty target_url. Probe verdict toasts as a Filament
  Notification (success/danger). Lets the operator spot-check an
  in-flight campaign without waiting for the advertiser-side
  warning to land.

### Fixed

- ScoreEngine tier-escalation notifications were using
  `Filament\Notifications\Actions\Action`, which does not exist
  in Filament 4 (the notification action class is
  `Filament\Actions\Action`). The outer try/catch silently
  swallowed the resulting class-not-found so notifications never
  reached the `notifications` table. Switching the import
  resurrects the feature; the unit test now exercises the full
  path. AdminPanelProvider also gains `default()` so URL helpers
  like `Resource::getUrl()` resolve from worker / CLI contexts.

### Added

- **Iframe-mode preflight on advertiser submission.** When the
  advertiser picks `display_mode=iframe` for a PTC campaign,
  `IframeEmbedProbe` HEADs the destination URL and inspects
  `X-Frame-Options` + CSP `frame-ancestors`. If the destination
  refuses cross-origin embedding (DENY / SAMEORIGIN / restrictive
  frame-ancestors), `/advertise/{id}/show` flashes a session
  warning explaining what happened so the advertiser switches
  back to "Open in new tab" before viewers see a blank page.
  Probe failures (network, 4xx/5xx, malformed CSP) deliberately
  return embeddable=true so a transient blip never blocks
  submission. Edit flow re-probes only when the advertiser is
  switching INTO iframe (no point re-probing a copy-only edit).
  Window-mode submissions skip the probe entirely. 15 new tests
  pin the verdict rules + controller wiring.

### Changed

- **Withdraw flow now applies the same atomic-claim pattern as
  shortlinks / PTC / internal articles.** Previously
  `ProcessWithdrawalJob` relied on `ShouldBeUnique`'s cache lock
  alone to keep two workers from both calling FaucetPay for the
  same withdrawal. The lock is strong but not invincible — a cache
  eviction during the long backoff window could let a second cron
  tick enqueue a duplicate. Now:
  - The claim itself is an atomic
    `UPDATE WHERE status IN (queued, processing)`. Losing workers
    bail silently with a log line — no FaucetPay call, no balance
    mutation.
  - The success-path settle is `UPDATE WHERE status='processing'`
    so a parallel worker landing first is detected and the second
    worker skips the `total_withdrawn_sat` increment.
  - The refund-path `markFailedAndRefund()` runs the same
    status-predicated UPDATE so a double dead-letter / retry
    storm can't double-credit. The
    `balance_ledgers (reason, ref_type, ref_id)` partial UNIQUE
    backstop is the second line of defence.
  3 new tests pin: claim-loses-aborts-without-FaucetPay-call,
  settle-race idempotency, and refund-path idempotency under
  double invocation.

### Added

- **Admin audit log.** New `admin_audit_log` table + `AdminAuditor`
  service centralise the "who did what to whom and when" trail
  for operator actions inside `/admin`. Wired into the existing
  custom Filament actions:
  - `user.rescore` — captures resulting tier + score
  - `ptc_ad.approve` — bare event (target is the ad row)
  - `ptc_ad.reject` — captures rejection_reason + refunded_sat
    (snapshotted BEFORE the views_remaining → 0 mutation)
  - `withdrawal.approve` — captures amount_sat
  - `withdrawal.reject` — captures failure_reason + refunded_sat
  Exposed via the read-only `/admin/admin-audit-logs` Filament
  resource (Operations group). admin_user_id is nullOnDelete so
  removing an admin account doesn't cascade-wipe the trail —
  rows stay attributable to "(deleted admin)".

- **Operator notifications on bot tier escalation.** ScoreEngine
  now compares the new tier to the previous and dispatches a
  Filament database notification to admin users when a tier
  rises (trust → suspect → likely_bot → banned). De-escalations
  stay silent — the operator doesn't need to be paged every time
  noise abates. Notifications include the user, the from→to
  transition, the resulting score, and a "Open user" action that
  deep-links to the Filament edit page (when the panel is booted —
  unit-test paths gracefully skip the link). Filament's
  `databaseNotifications()` panel feature is now enabled with a
  60s polling cadence so the bell badge surfaces new events
  without a manual refresh. New `notifications` table migration
  follows Laravel's standard schema.

- **Named per-IP / per-user rate limits across the API surface.**
  Six named limiters defined in
  `AppServiceProvider::registerRateLimiters()` and applied as
  `throttle:<name>` middleware on the relevant routes. Limits err
  on the lenient side — they're a DoS / abuse backstop, not the
  primary gate (captcha + bot-score + adblock checks remain that):
  - `captcha-issue` 60/min/IP — anonymous; covers form + AJAX
    captcha refreshes for honest multi-tab users while shutting
    down a CDP-driven seed harvester.
  - `captcha-verify` 30/min/IP — anonymous; rules out a relay
    grinding through pre-issued challenge IDs.
  - `beacon` 120/min/IP — anonymous telemetry; tight enough to
    catch a script firing 1000s/sec of fake events.
  - `earning-start` 30/min/user — covers PTC view start,
    shortlink chip click, internal article open. Well above any
    human cadence; catches XHR-driven inventory hammering.
  - `withdraw` 5/min/user — heavy endpoint (FaucetPay round-trip).
    Per-user keyed so a shared NAT can't punish neighbours.
  - `adblock-report` 30/min/user — fires on every authenticated
    page load.

- **Bot tier evaluation trail + dashboard chart.** New
  `bot_score_history` append-only table; `ScoreEngine::evaluate()`
  inserts on every run alongside the existing `bot_scores`
  updateOrCreate. Without this, only the LATEST tier per user
  survived — the dashboard's tier distribution snapshot couldn't
  show direction or rate of change.
  - New `BotTierTrendChartWidget` plots 14-day daily counts of
    evaluations grouped by resulting tier (trust / suspect /
    likely_bot / banned). Operators can spot attack-wave spikes
    or signal-pipeline gaps the live snapshot can't surface.
  - History writes are wrapped in try/catch so a fresh DB without
    the table (e.g. mid-migration tinker) doesn't break the live
    tier write — best-effort by design.

- **Two new bot-detection signals** wired into the ScoreEngine:
  - `RegistrationBurstSignal` (weight 0.08): for each IP this user
    registered from, counts distinct OTHER user registrations from
    the same IP within `window_hours` of the user's first observation.
    Narrower than `SharedIpSignal` — focuses on REGISTER events
    inside a tight time window — so a real shared NAT with users
    joining years apart doesn't false-positive while a fresh
    sock-puppet farm does. Reuses the `bot_score.shared_ip.allowlist`
    so the operator manages one list.
  - `PayoutBurstSignal` (weight 0.07): counts withdrawals (any
    status — failed/hold burst is also signal) for the user in
    the last `window_hours`. Defaults: 3 in 24h fires the floor,
    each additional adds 0.2 capped at 1.0.
  Both signals are config-tunable via `config/satpeek.php`
  (`bot_score.registration_burst.*` / `bot_score.payout_burst.*`)
  and corresponding `BOTSCORE_*` env vars. ScoreEngine renormalises
  by total weight so adding them doesn't mute the existing signals.

- **Two new admin dashboard widgets** alongside the existing
  withdrawal + bot-tier + shared-IP cards:
  - `EarningActivityWidget`: today's verified PTC views / shortlink
    clicks / internal article reads with previous-24-h delta and
    a click-through to the corresponding `verified` filter on the
    debug resource. Three lightweight COUNTs against the existing
    `(status, created_at)` shape.
  - `PayoutVolumeChartWidget`: 14-day line chart of positive ledger
    deltas grouped by surface (PTC / shortlink / read article).
    Single GROUP BY day, reason query; SQL portable across
    Postgres + SQLite for the test suite.

- **Internal "read & earn" articles.** Admin-managed inline article
  inventory rendered inside SatPeek (Markdown body, sanitised at
  view time), with the same single-credit + atomic-claim defences
  as PTC + shortlinks. Admins manage from
  `/admin/internal-articles` (full CRUD); operators triage in-flight
  reads from the read-only `/admin/internal-article-views` resource.
  Per-article tunables: `reward_sat`, `read_seconds`, `daily_limit_per_user`.
  Snapshotted on each `internal_article_views` row at start time so
  a mid-flight admin tweak doesn't retroactively change unfinished
  views' rewards. The `/read-articles` page now shows internal
  articles above the BitcoTask offerwall passthrough (when
  configured); the partner section continues to render as plain
  external links because attribution is publisher-side.

### Changed

- User-facing empty states on `/shortlinks` and `/read-articles`
  no longer name internal admin URLs or environment variables.
  Operator-only information leaking into the public surface gave
  attackers a free reconnaissance hint. The new copy just tells the
  user "no tasks right now, check back shortly".

## [0.6.0] — 2026-05-02

Theme: shortlink earn flow rewritten to a provider-keyed model
matching firefaucet's approach, with a previously-undetected
hold-loophole closed and double-credit guarantees hardened end-to-end.
The /shortlinks surface is now operator-managed entirely from one
Filament screen (Shortlink providers); each chip click forces a real
shortener traversal and the auth-landing page is captcha-only.

Operator setup just needs API tokens pasted into
`/admin/shortlink-provider-credentials` (or `*_API_TOKEN` env entries).

### Added

- Three new shortener providers: `earnow.online`, `shortano.link`,
  `shortino.link`. All three use the existing query-token transport
  shape (`?api=TOKEN&url=DESTINATION&format=json`) so they slot into
  `GenericShortenerClient` with no new code — just a config block
  + `*_API_TOKEN` / `*_API_BASE` env pair each.

### Fixed

- `GenericShortenerClient` now matches the response URL's scheme to
  the api_base scheme (HTTPS-first). earnow / shortano / shortino
  return `http://` URLs even though their /api endpoint is HTTPS;
  navigating from an HTTPS page to those was silently blocked as
  mixed content, leaving the chip click feeling broken. The
  upgrade is conservative — same host + api_base must be HTTPS —
  so a hypothetical cross-domain handoff stays untouched.

### Changed

- **Hardened single-credit guarantee on shortlink + PTC claims.**
  Two-layer defence against a user double-tapping the claim button
  (or two parallel /complete posts):
  - `ShortlinkController::finishClick()` and
    `PtcController::finishView()` now both use an atomic
    `UPDATE … SET status='verified' WHERE id=? AND status='pending'`
    and only credit when the affected-row count is 1. The losing
    request sees 0 rows updated and bails with `*_not_pending`.
  - Defence-in-depth: new partial UNIQUE index on
    `balance_ledgers (reason, reference_type, reference_id)` so any
    future regression that bypasses the application-layer claim
    fatals at the DB instead of silently double-paying. The index
    covers both surfaces from one constraint. Pre-existing
    operator-manual rows with NULL references are unaffected
    (partial index condition).

- **Shortlink flow now FORCES shortener traversal.** Previous design opened
  the shortener in a new tab AND navigated the current tab to
  `/shortlinks/auth/{token}`, which let a user close the shortener
  tab and still claim the reward — the operator earned no ad
  revenue, the user got free sats. Fixed by making the current tab
  navigate directly to the shortener URL (same-tab). The only path
  back to `/shortlinks/auth/{token}` is now the shortener's own
  redirect after its interstitial. Also dropped the post-return
  hold countdown UI on the auth page — the shortener's interstitial
  IS the hold; making the user wait again on SatPeek's side was
  gratuitous. The captcha alone gates the claim, with a
  defence-in-depth `hold_seconds` (now labelled "Minimum round-trip
  seconds") server-side floor on click→claim elapsed time.

- **`/shortlinks` adopts firefaucet's multi-button visit pattern.**
  Each provider row used to render a single "Open via {label}"
  button. It now renders one numbered chip per remaining daily view
  (1, 2, 3, …) plus a "Views Left: N/M" counter. Each chip is its
  own click → fresh `ShortlinkClick` row → new shortener URL → user
  navigates to `/shortlinks/auth/{token}` while the shortener tab
  loads in parallel. The position number is purely visual — server
  state is unchanged. Matches the operator's reference UX.

- **Shortlinks re-shaped around the provider, not the inventory row.**
  Operator clarification: SatPeek's shortlink earn flow is "user
  picks a shortener → SatPeek mints `/shortlinks/auth/{token}` →
  shortens through that provider → user completes the provider's
  interstitial (operator earns ad revenue) → user lands back on the
  token URL → SatPeek pays". There is no inventory of shortlinks —
  only providers. The previous design carried both `Shortlink` rows
  AND `ShortlinkProviderCredential` rows in Filament, which split the
  per-click economics across two surfaces.
  - **DB**: per-click economics (`reward_sat`, `hold_seconds`,
    `daily_limit_per_user`) move onto `shortlink_provider_credentials`.
    `shortlink_clicks` gains `provider_name` + snapshotted
    `reward_sat` / `hold_seconds`. `shortlink_clicks.shortlink_id`
    becomes nullable (legacy column; new clicks omit it).
  - **API**: `POST /api/shortlinks/start/{provider}` replaces
    `POST /api/shortlinks/{id}/start`. The response now returns the
    actual shortener URL (`https://btcut.io/...`) directly — there's
    no more `/sl/{token}` indirection because the new flow doesn't
    need to hide the destination (the destination IS the shortener
    interstitial, which is the operator's revenue source).
    `GET /api/shortlinks` now lists providers, not inventory rows.
  - **Filament**: `ShortlinkResource` and the `/sl/{token}`
    redirector are removed. `ShortlinkProviderCredentialResource`
    grows a "Per-click economics" section so the operator manages
    everything from one screen.
  - **Blade**: `/shortlinks` lists providers ("Open via btcut.io")
    instead of inventory rows. The auth-landing page reads its
    reward + hold from the click row's snapshot, so a later operator
    config tweak doesn't retroactively change unfinished clicks.
  - **Tests**: rewrote `ClickFlowTest` + `AuthLandingTest` against
    the new endpoint shape, removed `ServableFilterTest` and
    `RotationTest` (concepts gone — there is no inventory to filter
    and no `/sl/{token}` to rotate). Updated `DebugResourceAccessTest`
    + `PolicyEnforcerIntegrationTest` to seed the new shape.

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

[Unreleased]: https://github.com/s3ij1nn/satpeek/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/s3ij1nn/satpeek/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/s3ij1nn/satpeek/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/s3ij1nn/satpeek/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/s3ij1nn/satpeek/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/s3ij1nn/satpeek/compare/v0.4.3...v0.5.0
[0.4.3]: https://github.com/s3ij1nn/satpeek/compare/v0.4.2...v0.4.3
[0.4.2]: https://github.com/s3ij1nn/satpeek/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/s3ij1nn/satpeek/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/s3ij1nn/satpeek/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/s3ij1nn/satpeek/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/s3ij1nn/satpeek/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/s3ij1nn/satpeek/releases/tag/v0.1.0
