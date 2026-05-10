<?php

declare(strict_types=1);

namespace App\Payout;

use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Polls every Withdrawal row in `Broadcast` status and promotes it to
 * `Sent` once the chain reaches SatPeek's per-currency finality
 * threshold. The companion to {@see ProcessWithdrawalJob} which only
 * gets a row to `Broadcast` (gateway accepted the tx for relay) —
 * this job is what closes the loop on "the tx is now safe to call
 * settled".
 *
 * Scheduled `everyMinute` from `routes/console.php`. `ShouldBeUnique`
 * with a 60 s window prevents overlapping invocations from racing on
 * the same row when an RPC call takes longer than the cron interval.
 *
 * Per-currency thresholds (Phase 2b):
 *   - TRX:        19 confirmations (~57 s, official Tron finality)
 *
 * BTC / ETH thresholds slot in here as their gateways land. Each new
 * chain pairs an HTTP client + a `chainConfirmations()` helper +
 * a `case` in the dispatch switch — kept centralised so an operator
 * can audit the full finality matrix in one place.
 *
 * Failure modes (every one is non-fatal — the cron tries again next
 * minute):
 *   - Chain head fetch fails → skip the entire run, log warning.
 *     A stuck oracle would otherwise mark every row as "0
 *     confirmations" and stall forever.
 *   - Per-row RPC fails → skip that row, log warning, continue.
 *   - Tx not yet in a block (`blockNumber=0` or empty body) → skip
 *     the row, no DB write. Next run picks it up.
 *
 * The promotion to `Sent` uses an atomic UPDATE WHERE status='broadcast'
 * — same race-defence pattern ProcessWithdrawalJob's settle predicate
 * uses. A second worker that races in after the first promotes sees
 * affected_rows=0 and silently no-ops.
 */
class WatchOnchainConfirmationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Polling job — a transient RPC failure is handled per-row inside
     * handle(); no value in re-queuing the whole sweep. The cron will
     * re-fire next minute either way.
     */
    public int $tries = 1;

    /** Lock window — overlap prevention only, NOT a retry signal. */
    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'watch-onchain-confirmations';
    }

    /** TRX mainnet finality (19 blocks). Same value applies on Shasta. */
    private const TRX_FINALITY = 19;

    public function handle(TronHttpClient $http): void
    {
        // Both Tron-family methods share TronHttpClient — single sweep,
        // single chain-head fetch. Future onchain_btc / onchain_eth
        // gateways will dispatch off `payout_method` to their own RPC
        // clients; the loop body stays the same shape.
        $rows = Withdrawal::query()
            ->where('status', 'broadcast')
            ->whereIn('payout_method', [
                Withdrawal::METHOD_ONCHAIN_TRX,
                Withdrawal::METHOD_ONCHAIN_USDT_TRC20,
            ])
            ->whereNotNull('onchain_tx_hash')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Cache the chain head once per run so we don't burn a getNowBlock
        // RPC call per row. Tron block time is ~3 s; using a single head
        // for the whole sweep is at most one block "behind" — acceptable
        // because a row at threshold-1 just waits for next sweep.
        try {
            $tronHead = $http->getNowBlock();
        } catch (TronRpcException $e) {
            Log::warning('onchain confirmations: cannot fetch chain head, skipping run', [
                'err' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($rows as $w) {
            $this->checkTronRow($w, $http, $tronHead);
        }
    }

    private function checkTronRow(Withdrawal $w, TronHttpClient $http, int $tronHead): void
    {
        $txHash = (string) $w->onchain_tx_hash;
        try {
            $info = $http->getTransactionInfo($txHash);
        } catch (TronRpcException $e) {
            Log::warning('onchain confirmations: tx-info rpc failed', [
                'withdrawal_id' => $w->id,
                'tx_hash' => $txHash,
                'err' => $e->getMessage(),
            ]);

            return;
        }

        // Empty body or blockNumber=0 = tx not in a block yet. Normal
        // for the first ~3 s after broadcast; not an error.
        $txBlock = (int) ($info['blockNumber'] ?? 0);
        if ($txBlock === 0) {
            return;
        }

        $confirmations = max(0, $tronHead - $txBlock + 1);

        // TRC20 contract calls can be in a block but REVERT (insufficient
        // balance, paused contract, blacklisted recipient). receipt.result
        // is the canonical "did the contract succeed" signal. Native TRX
        // transfers never revert once they're in a block — receipt.result
        // is absent or 'SUCCESS' so the same check is harmless for TRX.
        $receiptResult = (string) ($info['receipt']['result'] ?? '');
        if ($receiptResult !== '' && $receiptResult !== 'SUCCESS') {
            $this->refundReverted($w, $confirmations, $receiptResult, $info);

            return;
        }

        if ($confirmations < self::TRX_FINALITY) {
            // Not yet final — just tick the counter so an operator
            // watching the dashboard can see progress. Don't write
            // confirmed_at yet; that's the finality marker.
            $w->forceFill([
                'confirmations_seen' => $confirmations,
            ])->save();

            return;
        }

        // Reached finality. Atomic flip: only promote if status is still
        // 'broadcast' (a parallel worker that won the unique-lock race
        // would have already promoted; affected_rows=0 → silent no-op).
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
                'withdrawal_id' => $w->id,
                'tx_hash' => $txHash,
                'confirmations' => $confirmations,
            ]);
        }
    }

    /**
     * TRC20 (or any contract) reverted on-chain. The tx is mined but
     * the state change never happened, so SatPeek's debit at withdrawal
     * creation time is now incorrect — refund the user and mark the
     * row failed.
     *
     * Atomic settle: same WHERE status='broadcast' guard as the success
     * path so a parallel run can't double-refund. The
     * `balance_ledgers (reason, reference_type, reference_id)` partial
     * UNIQUE backstop catches even a bypassed affected_rows check.
     *
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
                    'meta' => array_merge((array) $w->meta, ['receipt' => $info['receipt'] ?? null]),
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
            'withdrawal_id' => $w->id,
            'tx_hash' => $w->onchain_tx_hash,
            'receipt_result' => $receiptResult,
        ]);
    }
}
