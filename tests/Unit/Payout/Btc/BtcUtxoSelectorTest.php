<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Btc;

use App\Payout\Btc\BtcUtxoSelector;
use PHPUnit\Framework\TestCase;

class BtcUtxoSelectorTest extends TestCase
{
    public function test_largest_first_picks_minimum_inputs(): void
    {
        $selector = new BtcUtxoSelector;
        // Three confirmed UTXOs of varying size.
        $utxos = [
            $this->utxo('aaa', 0, 5_000, true),
            $this->utxo('bbb', 0, 100_000, true),
            $this->utxo('ccc', 0, 50_000, true),
        ];

        // 80_000 sat send + 1 sat/vB fee → 100k input alone covers it
        // (largest-first picks bbb).
        $sel = $selector->select($utxos, 80_000, 1);

        $this->assertCount(1, $sel['inputs']);
        $this->assertSame('bbb', $sel['inputs'][0]['txid']);
        $this->assertGreaterThan(0, $sel['change']); // 100k - 80k - fee
        $this->assertGreaterThan(0, $sel['fee']);
    }

    public function test_drops_unconfirmed_utxos(): void
    {
        $selector = new BtcUtxoSelector;
        $utxos = [
            $this->utxo('confirmed', 0, 50_000, true),
            $this->utxo('mempool', 0, 1_000_000, false), // unconfirmed → ignored
        ];

        $sel = $selector->select($utxos, 10_000, 1);

        $this->assertCount(1, $sel['inputs']);
        $this->assertSame('confirmed', $sel['inputs'][0]['txid']);
    }

    public function test_throws_when_funds_insufficient(): void
    {
        $selector = new BtcUtxoSelector;
        $utxos = [$this->utxo('aaa', 0, 5_000, true)];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/insufficient/');
        $selector->select($utxos, 100_000, 1);
    }

    public function test_dust_change_absorbed_into_fee(): void
    {
        // Single 50_000 sat input. Recipient asks for 49_700 sats.
        // 1-output fee at 1 sat/vB = (11 + 68 + 31) = 110 sats
        //   → 50_000 - 49_700 = 300 sats available; covers the
        //   110-sat 1-output fee, leaves 190 sats theoretical change.
        // 2-output fee at 1 sat/vB = (11 + 68 + 62) = 141 sats
        //   → would need 49_700 + 141 + 294 (dust limit) = 50_135 sats —
        //   insufficient. So selector falls back to 1-output path:
        //   change=0, fee=300 (the entire surplus accrues to miner).
        $selector = new BtcUtxoSelector;
        $utxos = [$this->utxo('aaa', 0, 50_000, true)];

        $sel = $selector->select($utxos, 49_700, 1);

        $this->assertSame(0, $sel['change']);
        $this->assertSame(300, $sel['fee']);
    }

    private function utxo(string $txid, int $vout, int $value, bool $confirmed): array
    {
        return [
            'txid' => $txid,
            'vout' => $vout,
            'value' => $value,
            'status' => ['confirmed' => $confirmed],
        ];
    }
}
