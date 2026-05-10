<?php

declare(strict_types=1);

namespace App\Payout;

use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use App\Payout\Btc\BtcHttpClient;
use App\Payout\Btc\BtcRpcException;
use App\Payout\Eth\EthHttpClient;
use App\Payout\Eth\EthRpcException;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Polls every Withdrawal row in `Broadcast` status and promotes it to
 * `Sent` once the chain reaches SatPeek's per-currency finality
 * threshold. The companion to {@see ProcessWithdrawalJob} which only
 * gets a row to `Broadcast` (gateway accepted the tx for relay).
 *
 * Scheduled `everyMinute` from `routes/console.php`. `ShouldBeUnique`
 * with a 60 s window prevents overlapping invocations from racing
 * on the same row when an RPC call takes longer than the cron
 * interval.
 *
 * Per-currency thresholds:
 *   - TRX / USDT-TRC20:  19 confirmations (~57 s, official Tron finality)
 *   - ETH:               12 confirmations (~2.5 min, beacon chain finality)
 *   - BTC:               3 confirmations (~30 min, conservative below
 *                        exchange standard 6, traded for faster UX)
 *
 * Failure modes (every one is non-fatal — the cron tries again next
 * minute):
 *   - Chain head fetch fails → skip THAT chain's rows for this run.
 *     A stuck oracle for one chain shouldn't block the others.
 *   - Per-row RPC fails → skip that row, log warning, continue.
 *   - Tx not yet in a block → skip the row, no DB write. Next run
 *     picks it up.
 *
 * The promotion to `Sent` uses an atomic UPDATE WHERE status='broadcast'
 * — a parallel worker that won the unique-lock race silently no-ops
 * via affected_rows=0.
 */
class WatchOnchainConfirmationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'watch-onchain-confirmations';
    }

    /** TRX mainnet finality (19 blocks). Same value applies on Shasta. */
    private const TRX_FINALITY = 19;

    /** ETH mainnet finality (12 blocks ≈ 2.5 min). Beacon-chain anchored. */
    private const ETH_FINALITY = 12;

    /**
     * BTC finality threshold. 3 blocks (~30 min) is conservative for a
     * payout cron — well below the 6-block exchange standard but
     * acceptable here because (a) the operator already pre-debited the
     * user's balance, (b) reorgs deeper than 3 blocks on Bitcoin
     * mainnet are vanishingly rare and cost the attacker millions of
     * dollars per attempt, (c) faster confirmations is a real UX win.
     */
    private const BTC_FINALITY = 3;

    public function handle(): void
    {
        $rows = Withdrawal::query()
            ->where('status', 'broadcast')
            ->whereIn('payout_method', [
                Withdrawal::METHOD_ONCHAIN_TRX,
                Withdrawal::METHOD_ONCHAIN_USDT_TRC20,
                Withdrawal::METHOD_ONCHAIN_ETH,
                Withdrawal::METHOD_ONCHAIN_BTC,
            ])
            ->whereNotNull('onchain_tx_hash')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Group by chain so we only fetch each chain head once per run
        // and only resolve the per-chain HTTP client when it's needed.
        $tronRows = $rows->filter(fn ($w): bool => in_array(
            $w->payout_method,
            [Withdrawal::METHOD_ONCHAIN_TRX, Withdrawal::METHOD_ONCHAIN_USDT_TRC20],
            true,
        ));
        $ethRows = $rows->filter(fn ($w): bool => $w->payout_method === Withdrawal::METHOD_ONCHAIN_ETH);
        $btcRows = $rows->filter(fn ($w): bool => $w->payout_method === Withdrawal::METHOD_ONCHAIN_BTC);

        if ($tronRows->isNotEmpty()) {
            $this->sweepTron($tronRows->all());
        }
        if ($ethRows->isNotEmpty()) {
            $this->sweepEth($ethRows->all());
        }
        if ($btcRows->isNotEmpty()) {
            $this->sweepBtc($btcRows->all());
        }
    }

    /**
     * @param  array<int, Withdrawal>  $rows
     */
    private function sweepBtc(array $rows): void
    {
        $http = App::make(BtcHttpClient::class);
        try {
            $btcHead = $http->tipHeight();
        } catch (BtcRpcException $e) {
            Log::warning('onchain confirmations: cannot fetch btc chain head, skipping btc sweep', [
                'err' => $e->getMessage(),
            ]);

            return;
        }
        foreach ($rows as $w) {
            $this->checkBtcRow($w, $http, $btcHead);
        }
    }

    private function checkBtcRow(Withdrawal $w, BtcHttpClient $http, int $btcHead): void
    {
        $txid = (string) $w->onchain_tx_hash;
        try {
            $status = $http->txStatus($txid);
        } catch (BtcRpcException $e) {
            Log::warning('onchain confirmations: btc txStatus failed', [
                'withdrawal_id' => $w->id, 'tx_hash' => $txid, 'err' => $e->getMessage(),
            ]);

            return;
        }

        // Empty array = node doesn't know the tx (yet — mempool ≠ confirmed).
        // confirmed=false = in mempool but not in a block.
        if (! (bool) ($status['confirmed'] ?? false)) {
            return;
        }
        $txBlock = (int) ($status['block_height'] ?? 0);
        if ($txBlock === 0) {
            return;
        }
        $confirmations = max(0, $btcHead - $txBlock + 1);
        // BTC has no contract-level revert path — confirmed-in-a-block
        // means the value transfer succeeded. No refund branch needed.
        $this->advanceOrPromote($w, $confirmations, self::BTC_FINALITY, $txid);
    }

    /**
     * @param  array<int, Withdrawal>  $rows
     */
    private function sweepTron(array $rows): void
    {
        $http = App::make(TronHttpClient::class);
        try {
            $tronHead = $http->getNowBlock();
        } catch (TronRpcException $e) {
            Log::warning('onchain confirmations: cannot fetch tron chain head, skipping tron sweep', [
                'err' => $e->getMessage(),
            ]);

            return;
        }
        foreach ($rows as $w) {
            $this->checkTronRow($w, $http, $tronHead);
        }
    }

    /**
     * @param  array<int, Withdrawal>  $rows
     */
    private function sweepEth(array $rows): void
    {
        $http = App::make(EthHttpClient::class);
        try {
            $ethHead = $http->blockNumber();
        } catch (EthRpcException $e) {
            Log::warning('onchain confirmations: cannot fetch eth chain head, skipping eth sweep', [
                'err' => $e->getMessage(),
            ]);

            return;
        }
        foreach ($rows as $w) {
            $this->checkEthRow($w, $http, $ethHead);
        }
    }

    private function checkTronRow(Withdrawal $w, TronHttpClient $http, int $tronHead): void
    {
        $txHash = (string) $w->onchain_tx_hash;
        try {
            $info = $http->getTransactionInfo($txHash);
        } catch (TronRpcException $e) {
            Log::warning('onchain confirmations: tron tx-info rpc failed', [
                'withdrawal_id' => $w->id, 'tx_hash' => $txHash, 'err' => $e->getMessage(),
            ]);

            return;
        }

        $txBlock = (int) ($info['blockNumber'] ?? 0);
        if ($txBlock === 0) {
            return;
        }
        $confirmations = max(0, $tronHead - $txBlock + 1);

        // TRC20 contract calls can be in a block but REVERT. receipt.result
        // is 'SUCCESS' on success; absent for native TRX transfers.
        $receiptResult = (string) ($info['receipt']['result'] ?? '');
        if ($receiptResult !== '' && $receiptResult !== 'SUCCESS') {
            $this->refundReverted($w, $confirmations, $receiptResult, $info);

            return;
        }

        $this->advanceOrPromote($w, $confirmations, self::TRX_FINALITY, $txHash);
    }

    private function checkEthRow(Withdrawal $w, EthHttpClient $http, int $ethHead): void
    {
        $txHash = (string) $w->onchain_tx_hash;
        try {
            $receipt = $http->getTransactionReceipt($txHash);
        } catch (EthRpcException $e) {
            Log::warning('onchain confirmations: eth getTransactionReceipt failed', [
                'withdrawal_id' => $w->id, 'tx_hash' => $txHash, 'err' => $e->getMessage(),
            ]);

            return;
        }

        // null/empty receipt = tx not mined yet. Normal until ~12 s after broadcast.
        if ($receipt === []) {
            return;
        }

        $blockNumberHex = (string) ($receipt['blockNumber'] ?? '');
        if ($blockNumberHex === '') {
            return;
        }
        $txBlock = (int) hexdec(self::strip0x($blockNumberHex));
        if ($txBlock === 0) {
            return;
        }
        $confirmations = max(0, $ethHead - $txBlock + 1);

        // status: '0x1' = success, '0x0' = revert. Plain ETH transfers
        // always succeed once mined — but defence-in-depth for future
        // contract-call gateways that share this watcher.
        $status = strtolower((string) ($receipt['status'] ?? ''));
        if ($status === '0x0' || $status === '0') {
            $this->refundReverted($w, $confirmations, 'eth_revert', $receipt);

            return;
        }

        $this->advanceOrPromote($w, $confirmations, self::ETH_FINALITY, $txHash);
    }

    private function advanceOrPromote(Withdrawal $w, int $confirmations, int $threshold, string $txHash): void
    {
        if ($confirmations < $threshold) {
            $w->forceFill(['confirmations_seen' => $confirmations])->save();

            return;
        }

        $now = Carbon::now();
        $promoted = Withdrawal::where('id', $w->id)
            ->where('status', 'broadcast')
            ->update([
                'status' => 'sent',
                'confirmations_seen' => $confirmations,
                'confirmed_at' => $now,
            ]);
        if ($promoted === 1) {
            Log::info('onchain confirmations: promoted to sent', [
                'withdrawal_id' => $w->id, 'tx_hash' => $txHash, 'confirmations' => $confirmations,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function refundReverted(Withdrawal $w, int $confirmations, string $receiptResult, array $info): void
    {
        DB::transaction(function () use ($w, $confirmations, $receiptResult, $info) {
            $marked = Withdrawal::where('id', $w->id)
                ->where('status', 'broadcast')
                ->update([
                    'status' => 'failed',
                    'confirmations_seen' => $confirmations,
                    'failure_reason' => "onchain_revert: {$receiptResult}",
                    'meta' => array_merge((array) $w->meta, ['receipt' => $info['receipt'] ?? $info]),
                ]);
            if ($marked === 0) {
                return;
            }

            BalanceLedger::create([
                'user_id' => $w->user_id,
                'delta_sat' => $w->amount_sat,
                'reason' => BalanceLedger::REASON_WITHDRAW_REFUND,
                'reference_type' => Withdrawal::class,
                'reference_id' => $w->id,
            ]);
            $w->user->increment('balance_sat', $w->amount_sat);
        });
        Log::warning('onchain confirmations: refunded after onchain revert', [
            'withdrawal_id' => $w->id, 'tx_hash' => $w->onchain_tx_hash, 'receipt_result' => $receiptResult,
        ]);
    }

    private static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
    }
}
