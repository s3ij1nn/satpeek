<?php

namespace App\Http\Controllers;

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
