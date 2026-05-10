<?php

declare(strict_types=1);

namespace App\Payout\Btc;

use App\Models\Withdrawal;
use App\Payout\WalletBalanceMonitor;
use App\Payout\WalletBalanceUnavailableException;

/**
 * BTC hot-wallet balance probe. Sums confirmed UTXO values via
 * mempool.space `/address/.../utxo`.
 *
 * `available()` returns the wallet's spendable sats NOW (excluding
 * unconfirmed UTXOs — those can't be safely spent in a payout).
 * `required()` sums in-flight BTC withdrawals (queued / hold /
 * processing / broadcast). The dashboard widget + `/up` probe +
 * weekly digest + alert mail all consume this without further
 * branching — the registry pattern carries.
 */
class BtcWalletBalanceMonitor implements WalletBalanceMonitor
{
    public function __construct(
        private readonly BtcHttpClient $http,
        private readonly string $hotWalletAddress,
    ) {}

    public function currency(): string
    {
        return 'BTC';
    }

    public function available(): string
    {
        try {
            $utxos = $this->http->addressUtxos($this->hotWalletAddress);
        } catch (BtcRpcException $e) {
            throw new WalletBalanceUnavailableException(
                'btc addressUtxos failed: '.$e->getMessage(),
                previous: $e,
            );
        }

        $sum = 0;
        foreach ($utxos as $u) {
            // Confirmed only — unconfirmed UTXOs aren't safe to spend.
            if (! (bool) ($u['status']['confirmed'] ?? false)) {
                continue;
            }
            $sum += (int) ($u['value'] ?? 0);
        }

        return (string) $sum;
    }

    public function required(): string
    {
        $sum = Withdrawal::query()
            ->where('payout_method', Withdrawal::METHOD_ONCHAIN_BTC)
            ->whereIn('status', ['queued', 'hold', 'processing', 'broadcast'])
            ->sum('payout_amount');

        return (string) ($sum ?: 0);
    }
}
