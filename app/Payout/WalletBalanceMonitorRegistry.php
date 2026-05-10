<?php

declare(strict_types=1);

namespace App\Payout;

use App\Providers\AppServiceProvider;

/**
 * Lookup table for {@see WalletBalanceMonitor} implementations,
 * keyed by SatPeek currency code.
 *
 * Mirrors the {@see Gateway\PayoutGatewayRegistry} pattern: each
 * monitor registers under its own `currency()` and the dashboard /
 * `/up` consumer iterates `all()` to render per-currency runway.
 *
 * Wired up in {@see AppServiceProvider} only when the
 * matching gateway is registered — same gating story (TRON_ONCHAIN_ENABLED
 * + hot-wallet env pair). An operator with FaucetPay-only routes sees
 * no monitor entries on the dashboard.
 */
class WalletBalanceMonitorRegistry
{
    /** @var array<string, WalletBalanceMonitor> */
    private array $monitors = [];

    public function register(WalletBalanceMonitor $monitor): void
    {
        $this->monitors[$monitor->currency()] = $monitor;
    }

    /** @return array<string, WalletBalanceMonitor> */
    public function all(): array
    {
        return $this->monitors;
    }

    public function has(string $currency): bool
    {
        return isset($this->monitors[$currency]);
    }
}
