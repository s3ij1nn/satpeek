<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Offerwall\AdapterRegistry;
use App\Shortlinks\ShortlinkProviderRegistry;
use Illuminate\Http\JsonResponse;
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
    public function show(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'maxmind_asn' => $this->checkMaxmind(),
            'shortlink_providers' => $this->checkShortlinkProviders(),
            'ip_reputation_providers' => $this->checkIpReputation(),
            'offerwall_providers' => $this->checkOfferwallProviders(),
            'faucetpay' => $this->checkFaucetPay(),
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
}
