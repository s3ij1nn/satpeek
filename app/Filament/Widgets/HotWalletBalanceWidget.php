<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Payout\PayoutCurrencyRegistry;
use App\Payout\WalletBalanceMonitor;
use App\Payout\WalletBalanceMonitorRegistry;
use App\Payout\WalletBalanceUnavailableException;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Per-currency hot-wallet runway. One Stat per registered
 * {@see WalletBalanceMonitor}: shows available + required + the gap
 * (= "topup runway"), colour-coded so the operator can see at a
 * glance whether any currency is about to run dry.
 *
 * Colour rules:
 *   - gap < 0          → danger  (already over-committed)
 *   - gap < 1.0× req   → warning (one batch from dry)
 *   - else             → success
 *
 * Empty when no monitors are registered (FaucetPay-only deploys
 * never see this widget on the dashboard). Per-currency RPC failure
 * renders "(unavailable)" — explicit signal that the chain probe is
 * down, not a misleading "0".
 */
class HotWalletBalanceWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    public function __construct()
    {
        // Filament instantiates widgets via container resolution; no
        // explicit construction needed. The monitors are pulled lazily
        // inside getStats() so a per-request scope works correctly.
    }

    protected function getStats(): array
    {
        $monitors = app(WalletBalanceMonitorRegistry::class)->all();
        if ($monitors === []) {
            return [];
        }

        $registry = app(PayoutCurrencyRegistry::class);
        $stats = [];
        foreach ($monitors as $monitor) {
            $stats[] = $this->buildStat($monitor, $registry);
        }

        return $stats;
    }

    private function buildStat(WalletBalanceMonitor $monitor, PayoutCurrencyRegistry $registry): Stat
    {
        $code = $monitor->currency();
        $currency = $registry->has($code) ? $registry->get($code) : null;
        // Explicit branch — PHPStan flags `?->prop ?? default` as
        // unnecessary nullsafe because `$currency`'s static type is
        // always-non-null after `has()`. We keep the runtime guard so
        // a future code path that registers a monitor without a
        // matching PayoutCurrency entry doesn't fatal.
        $decimals = $currency !== null ? $currency->decimals : 0;
        $label = $currency !== null ? $currency->label : $code;

        // available() is the only call that hits the chain — required()
        // is a DB sum and never fails. Wrap only the fragile call.
        try {
            $availableRaw = $monitor->available();
        } catch (WalletBalanceUnavailableException) {
            return Stat::make("Hot wallet — {$label}", '(unavailable)')
                ->description('chain probe failed; check RPC')
                ->descriptionIcon('heroicon-m-signal-slash')
                ->color('danger');
        }
        $requiredRaw = $monitor->required();

        $availableMain = $this->toMain($availableRaw, $decimals);
        $requiredMain = $this->toMain($requiredRaw, $decimals);
        // bcsub for arbitrary precision — wei / sun / atomic units
        // overflow int64 in pathological cases.
        $gap = bcsub($availableRaw, $requiredRaw, 0);
        $gapMain = $this->toMain($gap, $decimals);

        // Colour rule: danger if gap is negative, warning if gap is
        // less than required (one batch from dry), success otherwise.
        $cmpGap = bccomp($gap, '0', 0);
        if ($cmpGap < 0) {
            $color = 'danger';
            $description = 'over-committed — fund hot wallet now';
            $icon = 'heroicon-m-exclamation-triangle';
        } elseif (bccomp($gap, $requiredRaw, 0) < 0 && bccomp($requiredRaw, '0', 0) > 0) {
            $color = 'warning';
            $description = 'less than 1× pending — top up soon';
            $icon = 'heroicon-m-clock';
        } else {
            $color = 'success';
            $description = 'comfortable runway';
            $icon = 'heroicon-m-check';
        }

        $value = "{$availableMain} {$code} / required {$requiredMain}";

        return Stat::make("Hot wallet — {$label}", $value)
            ->description("{$description} (gap: {$gapMain} {$code})")
            ->descriptionIcon($icon)
            ->color($color);
    }

    /**
     * Format a smallest-unit decimal string as a main-unit display
     * string. e.g. (1500000, 6) → "1.500000". bcdiv with the unit's
     * decimals so wei / sun stays exact.
     */
    private function toMain(string $smallest, int $decimals): string
    {
        if ($decimals === 0) {
            return $smallest;
        }
        $divisor = bcpow('10', (string) $decimals, 0);

        return bcdiv($smallest, $divisor, $decimals);
    }
}
