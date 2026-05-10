<?php

declare(strict_types=1);

namespace App\Payout\Tron;

use App\Models\Withdrawal;
use App\Payout\WalletBalanceMonitor;
use App\Payout\WalletBalanceUnavailableException;

/**
 * TRX hot-wallet balance probe via TronGrid `/wallet/getaccount`.
 *
 * `available()` returns the wallet's spendable sun balance NOW
 * (account.balance in TronGrid's parlance, expressed in sun;
 * 1 TRX = 1_000_000 sun). Frozen TRX is excluded — it counts as
 * stake collateral, not spending capacity.
 *
 * `required()` is the sum of `payout_amount` (sun, decimal(36,0)
 * stored as string) across in-flight TRX withdrawals — `queued`,
 * `hold`, `processing`, `broadcast`. The operator dashboard
 * subtracts available - required to surface the topup runway:
 * a positive gap means "fine for now"; zero / negative means
 * "wallet about to run dry, fund it before the queue stalls".
 *
 * Failure mode: any TronGrid transport / parse error throws
 * `WalletBalanceUnavailableException` so the dashboard widget can
 * render "(unavailable)" instead of falsely showing zero — a
 * silent zero would underreport runway and miss alerts.
 */
class TronWalletBalanceMonitor implements WalletBalanceMonitor
{
    public function __construct(
        private readonly TronHttpClient $http,
        private readonly string $hotWalletAddress,
    ) {}

    public function currency(): string
    {
        return 'TRX';
    }

    public function available(): string
    {
        try {
            $account = $this->http->getAccount($this->hotWalletAddress);
        } catch (TronRpcException $e) {
            throw new WalletBalanceUnavailableException(
                'tron getaccount failed: '.$e->getMessage(),
                previous: $e,
            );
        }

        // Empty array = address has no on-chain history (fresh
        // wallet). Treat as zero balance — that's accurate.
        if ($account === []) {
            return '0';
        }

        // TronGrid returns `balance` as an int (within JS safe range
        // for any realistic wallet) but PHP int parsing on big numbers
        // can overflow on 32-bit platforms — coerce via string for
        // safety. Frozen TRX (account.frozen[]) is intentionally
        // excluded; it's stake locked at consensus and not spendable.
        return (string) ($account['balance'] ?? 0);
    }

    public function required(): string
    {
        // payout_amount is decimal(36, 0) string — sum it via the
        // database (coerces to NUMERIC under Postgres) and stringify.
        // Sub-int64 result for any realistic Tron payload.
        $sum = Withdrawal::query()
            ->where('payout_method', Withdrawal::METHOD_ONCHAIN_TRX)
            ->whereIn('status', ['queued', 'hold', 'processing', 'broadcast'])
            ->sum('payout_amount');

        return (string) ($sum ?: 0);
    }
}
