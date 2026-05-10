<?php

namespace App\Http\Controllers;

use App\Models\BotScoreHistory;
use App\Models\InternalArticle;
use App\Models\PtcAd;
use App\Models\ShortlinkProviderCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Offerwall\AdapterRegistry;
use App\Payout\WalletBalanceMonitorRegistry;
use App\Payout\WalletBalanceUnavailableException;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Structured health endpoint at `/up`.
 *
 * Each component reports `status` (ok | degraded | down) and a `critical`
 * flag. Critical components down → 503 (orchestrators / load balancers
 * pull the node out of rotation). Non-critical degradation → 200 with
 * `status: degraded` so monitoring tooling can alert without paging.
 *
 * Deliberately leaks NO version strings, error stack traces, or
 * configuration paths — just a fixed-shape signal a load balancer or
 * dashboard can consume.
 */
class HealthController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'maxmind_asn' => $this->checkMaxmind(),
            'shortlink_providers' => $this->checkShortlinkProviders(),
            'ip_reputation_providers' => $this->checkIpReputation(),
            'offerwall_providers' => $this->checkOfferwallProviders(),
            'faucetpay' => $this->checkFaucetPay(),
            'bot_detection' => $this->checkBotDetection(),
            'earning_inventory' => $this->checkEarningInventory(),
            'trusted_proxies' => $this->checkTrustedProxies($request),
            'hot_wallet_balance' => $this->checkHotWalletBalance(),
        ];

        $hasCriticalDown = false;
        $hasDegraded = false;
        foreach ($checks as $check) {
            if ($check['status'] !== 'ok') {
                if (! empty($check['critical']) && $check['status'] === 'down') {
                    $hasCriticalDown = true;
                } else {
                    $hasDegraded = true;
                }
            }
        }

        $overall = $hasCriticalDown ? 'down' : ($hasDegraded ? 'degraded' : 'ok');
        $status = $hasCriticalDown ? 503 : 200;

        return response()->json([
            'status' => $overall,
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $status);
    }

    /** @return array{status: string, critical: bool, detail?: string} */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            // Lightweight round-trip — opens a connection if pooled but
            // doesn't query a real table that might be migrating.
            DB::connection()->select('select 1 as ok');

            return ['status' => 'ok', 'critical' => true];
        } catch (Throwable $e) {
            return ['status' => 'down', 'critical' => true, 'detail' => 'connect_failed'];
        }
    }

    /** @return array{status: string, critical: bool, detail?: string} */
    private function checkRedis(): array
    {
        try {
            $reply = Redis::connection()->ping();
            // Predis returns the literal string "PONG"; phpredis returns
            // either true or "+PONG" depending on the driver mode.
            $ok = $reply === true || (is_string($reply) && stripos($reply, 'PONG') !== false);

            return $ok
                ? ['status' => 'ok', 'critical' => true]
                : ['status' => 'down', 'critical' => true, 'detail' => 'unexpected_ping_reply'];
        } catch (Throwable $e) {
            return ['status' => 'down', 'critical' => true, 'detail' => 'connect_failed'];
        }
    }

    /** @return array{status: string, critical: bool, detail?: string} */
    private function checkMaxmind(): array
    {
        $path = (string) config('satpeek.ip_reputation.maxmind.asn_db', '');
        if ($path === '') {
            return ['status' => 'degraded', 'critical' => false, 'detail' => 'unconfigured'];
        }
        if (! is_file($path) || ! is_readable($path)) {
            return ['status' => 'down', 'critical' => false, 'detail' => 'file_missing'];
        }

        return ['status' => 'ok', 'critical' => false];
    }

    /** @return array{status: string, critical: bool, detail?: string, configured?: int} */
    private function checkShortlinkProviders(): array
    {
        try {
            $registry = app(ShortlinkProviderRegistry::class);
            $configured = count($registry->configuredNames());
            if ($configured === 0) {
                return ['status' => 'degraded', 'critical' => false, 'detail' => 'no_token_set', 'configured' => 0];
            }

            return ['status' => 'ok', 'critical' => false, 'configured' => $configured];
        } catch (Throwable) {
            return ['status' => 'down', 'critical' => false, 'detail' => 'registry_unavailable'];
        }
    }

    /**
     * Reports the per-publisher offerwall integrations (BitcoTasks today).
     *
     *   - `unconfigured` — adapter not in OFFERWALLS_ENABLED. Default state
     *     while the operator waits for a publisher account review; not an
     *     error, just "the surface is opt-in and you haven't opted in".
     *   - `degraded` — adapter is enabled but the credentials it needs are
     *     missing (e.g. the operator flipped the env var on but forgot to
     *     paste BITCOTASK_BEARER_TOKEN). The adapter would silently return
     *     `[]` from every fetch — easy to miss without a probe.
     *   - `ok` — enabled and all required credentials present.
     *
     * @return array{status: string, critical: bool, detail?: string, enabled?: array<int, string>, missing?: array<int, string>}
     */
    private function checkOfferwallProviders(): array
    {
        $enabled = (array) config('satpeek.offerwalls.enabled', []);
        $enabled = array_values(array_filter(array_map('strval', $enabled), fn (string $n): bool => $n !== ''));

        if ($enabled === []) {
            return ['status' => 'degraded', 'critical' => false, 'detail' => 'unconfigured', 'enabled' => []];
        }

        try {
            $registry = app(AdapterRegistry::class);
        } catch (Throwable) {
            return ['status' => 'down', 'critical' => false, 'detail' => 'registry_unavailable'];
        }

        $missing = [];
        foreach ($enabled as $name) {
            if ($registry->get($name) === null) {
                $missing[] = $name.':not_registered';

                continue;
            }
            foreach (self::missingCredentials($name) as $field) {
                $missing[] = $name.':'.$field;
            }
        }

        if ($missing !== []) {
            return [
                'status' => 'degraded',
                'critical' => false,
                'detail' => 'credentials_missing',
                'enabled' => $enabled,
                'missing' => $missing,
            ];
        }

        return ['status' => 'ok', 'critical' => false, 'enabled' => $enabled];
    }

    /**
     * Per-adapter credential expectations. Returning [] means "all set".
     * Kept as a static map rather than a method on each adapter because
     * the health probe shouldn't trigger any side effects (HTTP probes,
     * connection opens) — a structural check is enough.
     *
     * @return array<int, string>
     */
    private static function missingCredentials(string $name): array
    {
        return match ($name) {
            'bitcotask' => array_values(array_filter([
                config('satpeek.bitcotask.api_key') ? null : 'api_key',
                config('satpeek.bitcotask.bearer_token') ? null : 'bearer_token',
                config('satpeek.bitcotask.s2s_secret') ? null : 's2s_secret',
            ])),
            default => [],
        };
    }

    /**
     * Reports the FaucetPay payout integration's wire-up state plus a cheap
     * queue-backlog probe. Structural only — no live HTTP probe to FaucetPay,
     * because that would add cost and flakiness to a health endpoint a load
     * balancer hits every few seconds.
     *
     *   - `unconfigured` (degraded, 200) — `FAUCETPAY_API_KEY` blank.
     *     Withdrawals would all permanent-fail; ops needs this surfaced
     *     before a user files a support ticket.
     *   - `backlogged` (degraded, 200) — > 0 `queued` withdrawals older
     *     than 1 h. The queue worker is dead OR FaucetPay has been
     *     unreachable longer than the 35-min retry budget. The backlog
     *     count goes in `detail` so dashboards can graph it.
     *   - `ok` — configured + queue draining.
     *
     * Threshold is 1 h because the retry backoff caps at 30 min — anything
     * older than 1 h is past the dead-letter callback's window and stuck
     * for an external reason.
     *
     * @return array{status: string, critical: bool, detail?: string, backlog?: int}
     */
    private function checkFaucetPay(): array
    {
        $apiKey = (string) config('satpeek.faucetpay.api_key', '');
        if ($apiKey === '') {
            return ['status' => 'degraded', 'critical' => false, 'detail' => 'unconfigured'];
        }

        try {
            $backlog = (int) Withdrawal::where('status', 'queued')
                ->where('created_at', '<', now()->subHour())
                ->count();
        } catch (Throwable) {
            return ['status' => 'down', 'critical' => false, 'detail' => 'backlog_probe_failed'];
        }

        if ($backlog > 0) {
            return [
                'status' => 'degraded',
                'critical' => false,
                'detail' => 'backlogged',
                'backlog' => $backlog,
            ];
        }

        return ['status' => 'ok', 'critical' => false];
    }

    /**
     * Did ScoreEngine actually run in the last 24 h? `bot_score_history`
     * appends one row per evaluate(), so a 24-h gap on a non-empty user
     * base means the signal pipeline has stalled — captcha verifies
     * stopped firing, the cron is dead, or a new framework upgrade
     * silently regressed the auto-trigger paths.
     *
     * `quiet_acceptable` (degraded → ok) when there are 0 users yet:
     * a fresh install before its first signup shouldn't false-positive.
     *
     * @return array{status: string, critical: bool, detail?: string, evaluations_24h?: int}
     */
    private function checkBotDetection(): array
    {
        try {
            // Two count queries cached together for HEALTH_PROBE_CACHE_SECONDS
            // (default 30 s). /up is hit every few seconds by load balancers
            // and uptime probes; the user count + 24-h evaluation count
            // change on a much slower cadence than that, so re-issuing the
            // queries on every request is pure DB load.
            [$userCount, $recent] = self::probeCached('health:bot_detection', fn (): array => [
                (int) User::query()->count(),
                (int) BotScoreHistory::query()
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ]);
            if ($userCount === 0) {
                return ['status' => 'ok', 'critical' => false, 'detail' => 'no_users_yet'];
            }
        } catch (Throwable) {
            return ['status' => 'down', 'critical' => false, 'detail' => 'probe_failed'];
        }

        if ($recent === 0) {
            return [
                'status' => 'degraded',
                'critical' => false,
                'detail' => 'no_evaluations_24h',
                'evaluations_24h' => 0,
            ];
        }

        return ['status' => 'ok', 'critical' => false, 'evaluations_24h' => $recent];
    }

    /**
     * Does the platform have ANYTHING for users to earn against right now?
     * Counts active rows across the three earning surfaces:
     *   - PtcAd: status=approved AND is_active=true
     *   - ShortlinkProviderCredential: is_active=true AND api_token set
     *   - InternalArticle: is_active=true
     *
     * If all three buckets are zero we flag `no_inventory_active` so a
     * silent state where users hit /ptc /shortlinks /read-articles to
     * find empty pages doesn't go undetected. Pure structural — counts
     * only, no probe of upstream availability.
     *
     * @return array{status: string, critical: bool, detail?: string, ptc_ads?: int, shortlink_providers?: int, internal_articles?: int}
     */
    private function checkEarningInventory(): array
    {
        try {
            // Three count queries cached together for HEALTH_PROBE_CACHE_SECONDS
            // (default 30 s). Inventory turnover is admin-driven and minutes-
            // scale at fastest; the cached snapshot is plenty fresh for a
            // health endpoint. Bypassed in tests via TTL=0 — see config/satpeek.
            [$ptc, $shortlink, $articles] = self::probeCached('health:earning_inventory', fn (): array => [
                (int) PtcAd::query()
                    ->where('is_active', true)
                    ->where('status', 'approved')
                    ->count(),
                (int) ShortlinkProviderCredential::query()
                    ->where('is_active', true)
                    ->whereNotNull('api_token')
                    ->count(),
                (int) InternalArticle::query()
                    ->where('is_active', true)
                    ->count(),
            ]);
        } catch (Throwable) {
            return ['status' => 'down', 'critical' => false, 'detail' => 'probe_failed'];
        }

        $totalActive = $ptc + $shortlink + $articles;
        $payload = [
            'ptc_ads' => $ptc,
            'shortlink_providers' => $shortlink,
            'internal_articles' => $articles,
        ];

        if ($totalActive === 0) {
            return array_merge([
                'status' => 'degraded',
                'critical' => false,
                'detail' => 'no_inventory_active',
            ], $payload);
        }

        return array_merge(['status' => 'ok', 'critical' => false], $payload);
    }

    /**
     * Detects the silent-misconfiguration class where a CDN deployment
     * forgets to set TRUSTED_PROXIES. The symptom is severe but
     * invisible: every IP-keyed signal (SharedIpSignal, per-IP rate
     * limits, BitcoTask webhook IP allowlist, IpReputationGate) sees
     * the CDN edge IP rather than the visitor's real address — the
     * whole bot-detection stack quietly falls over.
     *
     * Heuristic: if the request hitting /up carries an
     * `X-Forwarded-For` header AND `TRUSTED_PROXIES` is unset/empty,
     * we're definitely behind a proxy that's writing the header but
     * the framework will discard it. Flag degraded with detail
     * `proxy_unconfigured` so the operator's monitoring catches the
     * gap before it spreads through the rest of the platform.
     *
     * @return array{status: string, critical: bool, detail?: string}
     */
    private function checkTrustedProxies(Request $request): array
    {
        // env() is the right tool here despite Larastan's nudge: the
        // Laravel framework reads the same env at boot to decide
        // whether to engage trustProxies (see bootstrap/app.php).
        // Mirroring it via env() keeps the probe truthful — a
        // config-cached value would lie about the live behaviour.
        // @phpstan-ignore-next-line larastan.noEnvCallsOutsideOfConfig
        $envValue = (string) env('TRUSTED_PROXIES', '');
        $hasXff = $request->headers->has('X-Forwarded-For');

        if ($envValue === '' && $hasXff) {
            return [
                'status' => 'degraded',
                'critical' => false,
                'detail' => 'proxy_unconfigured',
            ];
        }

        return ['status' => 'ok', 'critical' => false];
    }

    /**
     * TTL for probe-result caching. Reads from config so tests can pin it
     * to 0 (bypass cache, every probe re-queries) while production stays
     * at a few-tens-of-seconds default.
     */
    private static function probeCacheTtl(): int
    {
        return max(0, (int) config('satpeek.health.probe_cache_seconds', 30));
    }

    /**
     * Wraps a probe callback in cache when TTL > 0; otherwise invokes
     * directly. Used to keep tests deterministic (TTL pinned to 0 in
     * phpunit.xml) without forcing them to flush cache between probes.
     *
     * @template T
     *
     * @param  callable(): T  $probe
     * @return T
     */
    private static function probeCached(string $key, callable $probe): mixed
    {
        $ttl = self::probeCacheTtl();
        if ($ttl <= 0) {
            return $probe();
        }

        return Cache::remember($key, $ttl, $probe);
    }

    /** @return array{status: string, critical: bool, detail?: string, sources?: array<int, string>} */
    private function checkIpReputation(): array
    {
        $cfg = (array) config('satpeek.ip_reputation', []);
        $sources = [];
        if (! empty($cfg['iphub']['api_key'])) {
            $sources[] = 'iphub';
        }
        if (! empty($cfg['proxycheck']['api_key'])) {
            $sources[] = 'proxycheck';
        }
        $maxmindPath = (string) ($cfg['maxmind']['asn_db'] ?? '');
        if ($maxmindPath !== '' && is_file($maxmindPath)) {
            $sources[] = 'maxmind';
        }

        if ($sources === []) {
            return ['status' => 'degraded', 'critical' => false, 'detail' => 'no_provider_configured', 'sources' => []];
        }

        return ['status' => 'ok', 'critical' => false, 'sources' => $sources];
    }

    /**
     * Per-currency hot-wallet runway probe. Iterates the
     * {@see WalletBalanceMonitorRegistry} and reports each monitor's
     * available + required + gap. Per-currency status:
     *   - ok       — gap >= required (≥ 1× pending withdrawals worth of headroom)
     *   - degraded — 0 <= gap < required (less than 1× pending — top up soon)
     *   - down     — gap < 0 (over-committed — fund hot wallet now)
     *               OR available() throws (chain probe failed)
     *
     * Non-critical (200 OK overall, just `status: degraded` so
     * monitoring tooling can alert without paging). Operator with
     * FaucetPay-only routes sees an empty `currencies: []` and a
     * `no_monitors_registered` detail — not an alert condition.
     *
     * Live RPC calls for every probe — kept off the hot path because
     * /up is hit by external monitors, not user requests, and chain-
     * head fetches are sub-second on TronGrid + publicnode.
     *
     * @return array{status: string, critical: bool, detail?: string, currencies: array<int, array<string, mixed>>}
     */
    private function checkHotWalletBalance(): array
    {
        $registry = app(WalletBalanceMonitorRegistry::class);
        $monitors = $registry->all();
        if ($monitors === []) {
            return [
                'status' => 'ok',
                'critical' => false,
                'detail' => 'no_monitors_registered',
                'currencies' => [],
            ];
        }

        $currencies = [];
        $worst = 'ok';
        foreach ($monitors as $monitor) {
            $code = $monitor->currency();
            try {
                $available = $monitor->available();
            } catch (WalletBalanceUnavailableException) {
                $currencies[] = [
                    'code' => $code,
                    'status' => 'down',
                    'detail' => 'rpc_unavailable',
                ];
                $worst = 'down';

                continue;
            }
            $required = $monitor->required();
            $gap = bcsub($available, $required, 0);
            if (bccomp($gap, '0', 0) < 0) {
                $status = 'down';
            } elseif (bccomp($required, '0', 0) > 0 && bccomp($gap, $required, 0) < 0) {
                $status = 'degraded';
            } else {
                $status = 'ok';
            }
            // Worst-case promotion: down > degraded > ok.
            if ($status === 'down') {
                $worst = 'down';
            } elseif ($status === 'degraded' && $worst === 'ok') {
                $worst = 'degraded';
            }
            $currencies[] = [
                'code' => $code,
                'status' => $status,
                'available' => $available,
                'required' => $required,
                'gap' => $gap,
            ];
        }

        return [
            'status' => $worst,
            // NOT critical — hot-wallet dry should alert the operator
            // but never page-out a load balancer (FaucetPay route is
            // unaffected, app process is healthy).
            'critical' => false,
            'currencies' => $currencies,
        ];
    }
}
