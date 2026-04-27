<?php

namespace App\Payout;

use App\Mail\WithdrawalRejectedEmail;
use App\Mail\WithdrawalSentEmail;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ProcessWithdrawalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $withdrawalId) {}

    public function handle(FaucetPayClient $client): void
    {
        /** @var Withdrawal|null $w */
        $w = Withdrawal::find($this->withdrawalId);
        if (! $w || $w->status !== 'queued') {
            return;
        }
        if ($w->requires_review) {
            $w->update(['status' => 'hold']);

            return;
        }

        DB::transaction(function () use ($w) {
            $w->update(['status' => 'processing']);
        });

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
                $w->update([
                    'status' => 'failed',
                    'failure_reason' => $result['message'],
                    'meta' => array_merge((array) $w->meta, ['response' => $result['raw']]),
                ]);
                // Refund balance ledger.
                BalanceLedger::create([
                    'user_id' => $w->user_id,
                    'delta_sat' => $w->amount_sat,
                    'reason' => 'withdraw_refund',
                    'reference_type' => Withdrawal::class,
                    'reference_id' => $w->id,
                ]);
                $w->user->increment('balance_sat', $w->amount_sat);
            }
        });

        try {
            $mail = $sent ? new WithdrawalSentEmail($w->fresh()) : new WithdrawalRejectedEmail($w->fresh());
            Mail::to($w->user->email)->queue($mail);
        } catch (\Throwable $e) {
            // Don't fail the job over a mail error — payout already settled.
        }
    }
}
