<?php

namespace App\Payout;

use App\Enums\WithdrawalStatus;
use App\Mail\WithdrawalRejectedEmail;
use App\Mail\WithdrawalSentEmail;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use App\Payout\Gateway\PayoutGatewayRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Process a single queued withdrawal.
 *
 * Dispatch shape (post-Phase-1): the job receives a
 * {@see PayoutGatewayRegistry}, looks up the gateway matching
 * `Withdrawal.payout_method` (faucetpay / onchain), and lets the
 * gateway's `send()` produce a {@see Gateway\PayoutResult}. The job
 * itself is gateway-agnostic.
 *
 * Retry policy (transient-only):
 *   - $tries = 3 with exponential backoff (1 m, 5 m, 30 m).
 *   - The only exceptions that trigger a retry are the gateway-specific
 *     "unreachable" exceptions (e.g. {@see FaucetPayUnreachableException}
 *     for the FaucetPay route, future Tron/ETH equivalents for onchain).
 *     Each gateway throws its own marker class when the request never
 *     reached the wire (TCP/DNS pre-flight failure). In that case re-
 *     issuing it on retry cannot double-pay.
 *   - All other failures (HTTP error response, body indicates failure,
 *     timeout mid-request) come back as `PayoutResult::failed()` from
 *     the gateway and are treated as terminal in `handle()` itself: the
 *     withdrawal is marked `failed`, the user's balance is refunded,
 *     and a rejection email is queued. We do NOT retry these because we
 *     cannot tell whether the gateway processed the payout — a duplicate
 *     send is much worse than a delayed one.
 *
 * `ShouldBeUnique` (keyed by withdrawal id, **40-minute** lock — see
 * `$uniqueFor` below) prevents the cron's `satpeek:process-withdrawals`
 * from racing the active retry: the second dispatch is rejected before
 * it starts, so two workers can't both call the gateway for the same
 * row. The 40-minute window covers the full backoff budget (60 + 300 +
 * 1800 = 2160 s) plus actual `handle()` runtime.
 *
 * `failed()` is the dead-letter path: triggered when $tries is exhausted
 * (gateway still unreachable after 3 attempts) or any unhandled
 * exception escapes `handle()`. It performs the same refund + notify
 * sequence as a permanent failure.
 */
class ProcessWithdrawalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $withdrawalId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function uniqueId(): string
    {
        return (string) $this->withdrawalId;
    }

    /**
     * Lock window — wide enough to cover the longest backoff (30 min) plus
     * the actual `handle()` runtime. Without this, two cron ticks ~10 min
     * apart could each enqueue the same withdrawal during the backoff
     * window and end up double-paying.
     */
    public int $uniqueFor = 2400;

    public function handle(PayoutGatewayRegistry $gateways): void
    {
        /** @var Withdrawal|null $w */
        $w = Withdrawal::find($this->withdrawalId);
        if (! $w || ! in_array($w->status, [WithdrawalStatus::Queued, WithdrawalStatus::Processing], true)) {
            return;
        }
        if ($w->requires_review) {
            $w->update(['status' => 'hold']);

            return;
        }

        // Atomic claim: only ONE worker can flip the row out of queued
        // (or stay on processing for a retry that re-entered after a
        // gateway-unreachable exception). ShouldBeUnique above is the
        // primary mutex via the cache lock — this is the DB-level
        // backstop for the cache-evicted edge case where two workers
        // could both pass the precheck and both call the gateway.
        $meta = (array) $w->meta;
        $meta['attempts'] = (int) ($meta['attempts'] ?? 0) + 1;
        $meta['last_attempted_at'] = Carbon::now()->toIso8601String();
        $claimed = Withdrawal::where('id', $w->id)
            ->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'processing', 'meta' => $meta]);
        if ($claimed === 0) {
            // Another worker already settled this withdrawal. Bail
            // silently — no gateway call, no balance mutation.
            Log::info('withdrawal claim lost; another worker holds it', [
                'withdrawal_id' => $w->id,
            ]);

            return;
        }
        $w = $w->fresh();

        // Pick the gateway by `payout_method`. Pre-Phase-1 rows have
        // payout_method=null on legacy data — coalesce to 'faucetpay'
        // so they continue settling through the same path.
        $method = $w->payout_method ?? Withdrawal::METHOD_FAUCETPAY;
        $gateway = $gateways->forMethod($method);

        // Gateway-unreachable exceptions (e.g. FaucetPayUnreachableException
        // for the FaucetPay route, future Tron / ETH equivalents for the
        // onchain gateways) escape on purpose — Laravel's retry machinery
        // picks them up and re-enqueues with backoff. The request never
        // reached the wire, so a retry can safely re-claim and try again.
        $result = $gateway->send($w);

        $sent = false;
        DB::transaction(function () use ($w, $result, &$sent) {
            if ($result->ok) {
                // Atomic settle: status MUST still be `processing` for
                // this update to fire. Parallel-worker race (cache-lock
                // evicted) → losing worker sees affected_rows=0 and
                // skips the total_withdrawn_sat increment.
                $update = [
                    'status' => 'sent',
                    'processed_at' => Carbon::now(),
                    'meta' => array_merge((array) $w->meta, ['response' => $result->raw]),
                ];
                // Stamp the gateway-specific external reference into
                // the right column. FaucetPay → faucetpay_payout_id;
                // onchain → onchain_tx_hash.
                if ($w->payout_method === Withdrawal::METHOD_ONCHAIN) {
                    $update['onchain_tx_hash'] = $result->externalId;
                } else {
                    $update['faucetpay_payout_id'] = $result->externalId;
                }
                $settled = Withdrawal::where('id', $w->id)
                    ->where('status', 'processing')
                    ->update($update);
                if ($settled === 1) {
                    $w->user->increment('total_withdrawn_sat', $w->amount_sat);
                    $sent = true;
                } else {
                    Log::warning('withdrawal settle race: row already sent by another worker', [
                        'withdrawal_id' => $w->id,
                        'external_id' => $result->externalId,
                    ]);
                }
            } else {
                self::markFailedAndRefund($w, $result->message, $result->raw);
            }
        });

        $this->notify($w->fresh(), $sent);
    }

    /**
     * Final-failure dead-letter handler: invoked by Laravel when $tries
     * runs out (FaucetPay never came back up) or any non-Withdrawal
     * exception escapes `handle()`. Refunds the user and emails them so
     * funds are never silently stranded mid-flight.
     */
    public function failed(?Throwable $e): void
    {
        $w = Withdrawal::find($this->withdrawalId);
        if (! $w || ! in_array($w->status, [WithdrawalStatus::Queued, WithdrawalStatus::Processing], true)) {
            // Either deleted or already settled — nothing to refund.
            return;
        }
        $reason = $e ? 'job_failed: '.$e->getMessage() : 'job_failed: unknown';
        DB::transaction(function () use ($w, $reason) {
            self::markFailedAndRefund($w, $reason, []);
        });
        Log::warning('withdrawal job dead-lettered', [
            'withdrawal_id' => $w->id,
            'reason' => $reason,
        ]);
        $this->notify($w->fresh(), sent: false);
    }

    /**
     * Pure helper: writes the `failed` row + refund ledger + balance bump
     * inside the caller's DB transaction. Pulled out so the success-path
     * settle and the dead-letter path go through identical accounting.
     *
     * Idempotent: the atomic UPDATE filters on the in-flight statuses so
     * a second invocation (from `failed()` after a retry-storm dead-
     * letter, or a parallel worker losing the cache lock) sees
     * affected_rows=0 and skips the refund. The
     * `balance_ledgers (reason, reference_type, reference_id)` partial
     * UNIQUE backstop is the second line of defence — even if the
     * affected_rows check were ever bypassed, the ledger insert would
     * fatal at the DB layer rather than silently double-refund.
     *
     * @param  array<string, mixed>  $rawResponse
     */
    private static function markFailedAndRefund(Withdrawal $w, string $reason, array $rawResponse): void
    {
        $marked = Withdrawal::where('id', $w->id)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'meta' => array_merge((array) $w->meta, ['response' => $rawResponse]),
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
    }

    private function notify(?Withdrawal $w, bool $sent): void
    {
        if (! $w) {
            return;
        }
        try {
            $mail = $sent ? new WithdrawalSentEmail($w) : new WithdrawalRejectedEmail($w);
            Mail::to($w->user->email)->queue($mail);
        } catch (Throwable) {
            // Don't fail the job over a mail error — payout (or refund)
            // already settled in the DB.
        }
    }
}
