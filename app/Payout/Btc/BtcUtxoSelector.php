<?php

declare(strict_types=1);

namespace App\Payout\Btc;

/**
 * Largest-first UTXO selector for the hot wallet's confirmed
 * outputs. Picks UTXOs greedily until the running sum covers
 * `amount + fee + dust-buffer`, then computes the change output
 * value.
 *
 * Why largest-first (not branch-and-bound or knapsack)?
 *   - Hot wallets typically have a small UTXO set (operator
 *     consolidates periodically).
 *   - Largest-first minimises the number of inputs → minimises
 *     fee (each P2WPKH input is ~68 vBytes).
 *   - Predictable, easy to reason about during incident response.
 *
 * The fee estimate is computed from the segwit virtual-byte size
 * formula: each P2WPKH input ≈ 68 vBytes, each P2WPKH output
 * ≈ 31 vBytes, plus 11 vBytes of fixed overhead. The result is
 * an UPPER bound — actual size depends on signature randomness
 * (DER encoding may save 1 byte per signature 50% of the time).
 */
class BtcUtxoSelector
{
    /** vBytes per P2WPKH input (BIP141 §SegWit weight calculation). */
    private const VBYTES_INPUT_P2WPKH = 68;

    /** vBytes per P2WPKH output. */
    private const VBYTES_OUTPUT_P2WPKH = 31;

    /** Fixed tx overhead (version + marker + flag + locktime + io-counts). */
    private const VBYTES_OVERHEAD = 11;

    /** Dust threshold — outputs below this can't be relayed. */
    private const DUST_LIMIT_SAT = 294;

    /**
     * Pick UTXOs to cover `amountSat` + estimated fee. Returns the
     * selected inputs + the computed change value (may be 0 when
     * change would be dust — in that case the dust accrues to the
     * miner as fee).
     *
     * Throws if the available UTXOs can't cover amount + fee.
     *
     * @param  array<int, array<string, mixed>>  $utxos  esplora `/address/.../utxo` rows
     * @return array{inputs: array<int, array<string, int|string>>, change: int, fee: int}
     */
    public function select(array $utxos, int $amountSat, int $feeRateSatPerVByte): array
    {
        // Drop unconfirmed and zero-value entries; sort confirmed UTXOs
        // largest-first (BIP-style greedy selection).
        $confirmed = array_filter(
            $utxos,
            fn ($u): bool => (bool) ($u['status']['confirmed'] ?? false)
                && (int) ($u['value'] ?? 0) > 0,
        );
        usort(
            $confirmed,
            fn ($a, $b): int => (int) $b['value'] <=> (int) $a['value'],
        );

        $selected = [];
        $sum = 0;
        // Try with no change (1 output) first; if change is non-dust
        // we'll add a 2nd output and recompute fee.
        foreach ($confirmed as $u) {
            $selected[] = $u;
            $sum += (int) $u['value'];

            $feeOneOut = $this->estimateFee(count($selected), 1, $feeRateSatPerVByte);
            $feeTwoOut = $this->estimateFee(count($selected), 2, $feeRateSatPerVByte);

            // If sum covers amount + 2-output fee + dust → use change.
            if ($sum >= $amountSat + $feeTwoOut + self::DUST_LIMIT_SAT) {
                return [
                    'inputs' => $this->normaliseInputs($selected),
                    'change' => $sum - $amountSat - $feeTwoOut,
                    'fee' => $feeTwoOut,
                ];
            }
            // If sum covers amount + 1-output fee but change would be dust
            // → no change output; absorb the difference into the miner fee.
            if ($sum >= $amountSat + $feeOneOut) {
                return [
                    'inputs' => $this->normaliseInputs($selected),
                    'change' => 0,
                    'fee' => $sum - $amountSat,
                ];
            }
        }

        throw new \RuntimeException(sprintf(
            'btc utxo selection: insufficient confirmed funds (have %d sat, need ≥ %d sat)',
            $sum,
            $amountSat + $this->estimateFee(count($selected), 2, $feeRateSatPerVByte),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $utxos
     * @return array<int, array<string, int|string>>
     */
    private function normaliseInputs(array $utxos): array
    {
        return array_map(
            fn ($u): array => [
                'txid' => (string) $u['txid'],
                'vout' => (int) $u['vout'],
                'value' => (int) $u['value'],
            ],
            $utxos,
        );
    }

    private function estimateFee(int $inputCount, int $outputCount, int $feeRateSatPerVByte): int
    {
        $vbytes = self::VBYTES_OVERHEAD
            + $inputCount * self::VBYTES_INPUT_P2WPKH
            + $outputCount * self::VBYTES_OUTPUT_P2WPKH;

        return $vbytes * $feeRateSatPerVByte;
    }
}
