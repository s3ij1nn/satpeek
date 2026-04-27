<?php

namespace App\Payout;

use App\Mail\WithdrawalRejectedEmail;
use App\Mail\WithdrawalSentEmail;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
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
 * Retry policy (transient-only):
 *   - $tries = 3 with exponential backoff (1 m, 5 m, 30 m).
 *   - The ONLY exception that triggers a retry is
 *     {@see FaucetPayUnreachableException} — thrown by FaucetPayClient when
 *     the API host could not be reached at the TCP / DNS layer. In that
 *     case the request never made it onto the wire, so re-issuing it on
 *     retry cannot double-pay.
 *   - All other failures (HTTP error response, body status != 200,
 *     timeout mid-request) are treated as terminal in `handle()` itself:
 *     the withdrawal is marked `failed`, the user's balance is refunded,
 *     and a rejection email is queued. We do NOT retry these because we
 *     cannot tell whether FaucetPay processed the payout — a duplicate
 *     send is much worse than a delayed one.
 *
 * `ShouldBeUnique` (keyed by withdrawal id, 5-minute lock) prevents the
 * cron's `satpeek:process-withdrawals` from racing the active retry: the
 * second dispatch is rejected before it starts, so two workers can't
 * both call FaucetPay for the same row.
 *
 * `failed()` is the dead-letter path: triggered when $tries is exhausted
 * (FaucetPay still unreachable after 3 attempts) or any unhandled
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

    public function handle(FaucetPayClient $client): void
    {
        /** @var Withdrawal|null $w */
        $w = Withdrawal::find($this->withdrawalId);
        if (! $w || ! in_array($w->status, ['queued', 'processing'], true)) {
            return;
        }
        if ($w->requires_review) {
            $w->update(['status' => 'hold']);

            return;
        }

        DB::transaction(function () use ($w) {
            $meta = (array) $w->meta;
            $meta['attempts'] = (int) ($meta['attempts'] ?? 0) + 1;
            $meta['last_attempted_at'] = Carbon::now()->toIso8601String();
            $w->update(['status' => 'processing', 'meta' => $meta]);
        });

        // Lets FaucetPayUnreachableException escape on purpose — Laravel's
        // retry machinery picks it up and re-enqueues with backoff.
        $result = $client->send(
            faucetpayEmail: $w->faucetpay_email,
            amountSat: (int) $w->amount_sat,
            currency: $w->currency ?? 'BTC',
            referenceId: 'satpeek-withdraw-'.$w->id,
        );

        $sent = false;
        DB::transaction(function () use ($w, $result, &$sent) {
            if ($result['ok']) {
                $w->update([
                    'status' => 'sent',
                    'faucetpay_payout_id' => $result['payout_id'],
                    'processed_at' => Carbon::now(),
                    'meta' => array_merge((array) $w->meta, ['response' => $result['raw']]),
                ]);
                $w->user->increment('total_withdrawn_sat', $w->amount_sat);
                $sent = true;
            } else {
                self::markFailedAndRefund($w, $result['message'], $result['raw']);
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
        if (! $w || ! in_array($w->status, ['queued', 'processing'], true)) {
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
     * @param  array<string, mixed>  $rawResponse
     */
    private static function markFailedAndRefund(Withdrawal $w, string $reason, array $rawResponse): void
    {
        $w->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'meta' => array_merge((array) $w->meta, ['response' => $rawResponse]),
        ]);
        BalanceLedger::create([
            'user_id' => $w->user_id,
            'delta_sat' => $w->amount_sat,
            'reason' => 'withdraw_refund',
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
