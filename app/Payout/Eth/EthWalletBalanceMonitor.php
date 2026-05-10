<?php

declare(strict_types=1);

namespace App\Payout\Eth;

use App\Models\Withdrawal;
use App\Payout\WalletBalanceMonitor;
use App\Payout\WalletBalanceUnavailableException;

/**
 * ETH hot-wallet balance probe via `eth_getBalance`.
 *
 * `available()` returns the wallet's spendable wei balance NOW
 * (decimal string — wei × multi-ETH overflows int64).
 * `required()` sums the in-flight ETH withdrawals (queued / hold /
 * processing / broadcast). The dashboard widget + `/up` probe +
 * weekly digest + alert mail all consume this without further
 * branching — the registry pattern lights every consumer up.
 */
class EthWalletBalanceMonitor implements WalletBalanceMonitor
{
    public function __construct(
        private readonly EthHttpClient $http,
        private readonly string $hotWalletAddress,
    ) {}

    public function currency(): string
    {
        return 'ETH';
    }

    public function available(): string
    {
        try {
            return $this->http->getBalance($this->hotWalletAddress);
        } catch (EthRpcException $e) {
            throw new WalletBalanceUnavailableException(
                'eth getBalance failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    public function required(): string
    {
        $sum = Withdrawal::query()
            ->where('payout_method', Withdrawal::METHOD_ONCHAIN_ETH)
            ->whereIn('status', ['queued', 'hold', 'processing', 'broadcast'])
            ->sum('payout_amount');

        return (string) ($sum ?: 0);
    }
}
