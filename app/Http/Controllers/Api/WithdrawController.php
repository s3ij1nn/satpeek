<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Mail\WithdrawalQueuedEmail;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WithdrawController extends Controller
{
    public function __construct(private readonly PolicyEnforcer $policy) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->policy->canWithdraw($user)) {
            return response()->json(['error' => 'banned_or_blocked'], 403);
        }
        $validated = $request->validate([
            'amount_sat' => ['required', 'integer', 'min:'.((int) config('satpeek.faucetpay.min_withdraw_sat', 1000))],
            'faucetpay_email' => ['required', 'email'],
            'currency' => ['nullable', 'string', 'in:BTC,DOGE,LTC,ETH,USDT,TRX'],
        ]);
        if ($user->balance_sat < $validated['amount_sat']) {
            return response()->json(['error' => 'insufficient_balance'], 422);
        }

        $withdrawal = DB::transaction(function () use ($user, $validated) {
            $needsReview = $this->policy->withdrawalNeedsReview($user);
            $w = Withdrawal::create([
                'user_id' => $user->id,
                'amount_sat' => $validated['amount_sat'],
                'faucetpay_email' => $validated['faucetpay_email'],
                'currency' => $validated['currency'] ?? 'BTC',
                'status' => $needsReview ? 'hold' : 'queued',
                'requires_review' => $needsReview,
            ]);
            BalanceLedger::create([
                'user_id' => $user->id,
                'delta_sat' => -1 * (int) $validated['amount_sat'],
                'reason' => BalanceLedger::REASON_WITHDRAW_REQUEST,
                'reference_type' => Withdrawal::class,
                'reference_id' => $w->id,
            ]);
            $user->decrement('balance_sat', (int) $validated['amount_sat']);

            return $w;
        });

        // Persist user's preferred FaucetPay address so it pre-fills next time.
        if ($user->faucetpay_email !== $validated['faucetpay_email']) {
            $user->forceFill(['faucetpay_email' => $validated['faucetpay_email']])->save();
        }

        try {
            Mail::to($user->email)->queue(new WithdrawalQueuedEmail($withdrawal));
        } catch (\Throwable $e) {
            Log::warning('withdrawal queued mail failed', ['id' => $withdrawal->id, 'err' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $withdrawal->id,
            'status' => $withdrawal->status,
            'requires_review' => $withdrawal->requires_review,
        ], 202);
    }
}
