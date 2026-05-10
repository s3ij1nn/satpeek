<?php

declare(strict_types=1);

namespace App\Payout\Tron;

use App\Models\Withdrawal;
use App\Payout\WalletBalanceMonitor;
use App\Payout\WalletBalanceUnavailableException;

/**
 * USDT-TRC20 hot-wallet balance probe via TronGrid
 * `/wallet/triggerconstantcontract` (a read-only contract call —
 * doesn't broadcast, doesn't burn energy).
 *
 * The TRC20 standard exposes `balanceOf(address) → uint256`. We
 * encode the hot wallet's 20-byte hash, call the contract, and
 * parse the 32-byte uint256 result. Returns the balance in USDT's
 * smallest unit (6 decimals — 1_000_000 base units = 1 USDT).
 *
 * Why a separate class from `TronWalletBalanceMonitor`? Each
 * `WalletBalanceMonitor` reports on ONE currency by contract
 * (`currency()` returns the SatPeek code) — composing the two on
 * the dashboard means two simple monitors instead of one branching
 * class. A future TronUsdcTrc20WalletBalanceMonitor slots in here.
 *
 * Failure modes:
 *   - Transport / RPC failure → `WalletBalanceUnavailableException`
 *     (caller renders "(unavailable)").
 *   - constant_result missing / unparseable → throws too. Better
 *     surface the issue than report a wrong zero.
 */
class TronUsdtWalletBalanceMonitor implements WalletBalanceMonitor
{
    public function __construct(
        private readonly TronHttpClient $http,
        private readonly string $hotWalletAddress,
        private readonly string $contractAddress,
    ) {}

    public function currency(): string
    {
        return 'USDT_TRC20';
    }

    public function available(): string
    {
        // ABI parameter for balanceOf(address) — single 32-byte slot
        // holding the 20-byte address right-aligned (12 zero bytes
        // pad). Same encoding shape as TronAbi::encodeTransfer's
        // address slot.
        try {
            $hash = TronAddress::toHash20($this->hotWalletAddress);
        } catch (\InvalidArgumentException $e) {
            // Misconfigured hot wallet address would have failed at
            // gateway boot too — surface as unavailable so the
            // operator sees the dashboard signal.
            throw new WalletBalanceUnavailableException(
                'invalid hot wallet address: '.$e->getMessage(),
                previous: $e,
            );
        }
        $parameter = str_repeat('0', 24).$hash;

        try {
            $response = $this->http->triggerConstantContract(
                ownerAddress: $this->hotWalletAddress,
                contractAddress: $this->contractAddress,
                functionSelector: 'balanceOf(address)',
                parameter: $parameter,
            );
        } catch (TronRpcException $e) {
            throw new WalletBalanceUnavailableException(
                'tron balanceOf rpc failed: '.$e->getMessage(),
                previous: $e,
            );
        }

        // constant_result is a 0-indexed array of hex strings; for a
        // single uint256 return we want the first one as a 64-char
        // big-endian hex.
        $hex = (string) ($response['constant_result'][0] ?? '');
        if ($hex === '' || ! ctype_xdigit($hex)) {
            throw new WalletBalanceUnavailableException(
                'tron balanceOf returned no parseable result',
            );
        }

        // hexdec works for values up to PHP_INT_MAX (~9.2e18); USDT
        // base units (6 decimals) on a hot wallet stay well under
        // 1e18, so coercion to int is safe. For paranoia we keep the
        // string return type.
        return (string) hexdec($hex);
    }

    public function required(): string
    {
        $sum = Withdrawal::query()
            ->where('payout_method', Withdrawal::METHOD_ONCHAIN_USDT_TRC20)
            ->whereIn('status', ['queued', 'hold', 'processing', 'broadcast'])
            ->sum('payout_amount');

        return (string) ($sum ?: 0);
    }
}
