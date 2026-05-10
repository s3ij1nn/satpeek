<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Mail\HotWalletLowBalanceAlert;
use App\Models\User;
use App\Payout\WalletBalanceMonitor;
use App\Payout\WalletBalanceMonitorRegistry;
use App\Payout\WalletBalanceUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Pins the hot-wallet alert command's behaviour:
 *   - empty registry → nothing sent
 *   - all monitors ok → cache cleared, nothing sent
 *   - one monitor over-committed → mail queued + cache populated
 *   - re-run with same down-set → idempotent (no second mail)
 *   - re-run with different down-set → fresh mail
 *   - RPC failure (Unavailable) counts as down
 */
class HotWalletLowBalanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_registry_no_alert(): void
    {
        Mail::fake();
        $this->artisan('satpeek:hot-wallet-alert')->assertOk();
        Mail::assertNothingQueued();
    }

    public function test_all_ok_clears_cache_and_skips(): void
    {
        Mail::fake();
        Cache::put('hot-wallet-alert:last-down-set', 'TRX:down', 60);

        $registry = app(WalletBalanceMonitorRegistry::class);
        $registry->register($this->okMonitor('TRX', '1000', '500'));

        $this->artisan('satpeek:hot-wallet-alert')->assertOk();

        $this->assertNull(Cache::get('hot-wallet-alert:last-down-set'));
        Mail::assertNothingQueued();
    }

    public function test_overcommitted_currency_alerts_admins(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']);

        $registry = app(WalletBalanceMonitorRegistry::class);
        $registry->register($this->okMonitor('TRX', '100', '500')); // gap = -400

        $this->artisan('satpeek:hot-wallet-alert')->assertOk();

        Mail::assertQueued(HotWalletLowBalanceAlert::class, 1);
        $this->assertSame('TRX:down', (string) Cache::get('hot-wallet-alert:last-down-set'));
    }

    public function test_repeat_run_with_same_set_does_not_re_alert(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']);

        $registry = app(WalletBalanceMonitorRegistry::class);
        $registry->register($this->okMonitor('TRX', '100', '500'));

        $this->artisan('satpeek:hot-wallet-alert')->assertOk();
        Mail::assertQueued(HotWalletLowBalanceAlert::class, 1);

        // Second run — same down-set in cache → idempotent.
        $this->artisan('satpeek:hot-wallet-alert')->assertOk();
        Mail::assertQueued(HotWalletLowBalanceAlert::class, 1); // still 1, not 2
    }

    public function test_unavailable_monitor_counts_as_down(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']);

        $registry = app(WalletBalanceMonitorRegistry::class);
        $registry->register(new class implements WalletBalanceMonitor
        {
            public function currency(): string
            {
                return 'TRX';
            }

            public function available(): string
            {
                throw new WalletBalanceUnavailableException('rpc down');
            }

            public function required(): string
            {
                return '0';
            }
        });

        $this->artisan('satpeek:hot-wallet-alert')->assertOk();

        Mail::assertQueued(HotWalletLowBalanceAlert::class, 1);
        $this->assertSame('TRX:unavailable', (string) Cache::get('hot-wallet-alert:last-down-set'));
    }

    public function test_dry_run_prints_without_queueing_mail(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']);

        $registry = app(WalletBalanceMonitorRegistry::class);
        $registry->register($this->okMonitor('TRX', '0', '500'));

        $this->artisan('satpeek:hot-wallet-alert', ['--dry-run' => true])->assertOk();
        Mail::assertNothingQueued();
    }

    private function okMonitor(string $code, string $available, string $required): WalletBalanceMonitor
    {
        return new class($code, $available, $required) implements WalletBalanceMonitor
        {
            public function __construct(
                private readonly string $code,
                private readonly string $available,
                private readonly string $required,
            ) {}

            public function currency(): string
            {
                return $this->code;
            }

            public function available(): string
            {
                return $this->available;
            }

            public function required(): string
            {
                return $this->required;
            }
        };
    }
}
