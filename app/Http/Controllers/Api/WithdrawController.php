<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Mail\WithdrawalQueuedEmail;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use App\Payout\PayoutCurrencyRegistry;
use App\Payout\PriceOracle;
use App\Payout\PriceOracleUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class WithdrawController extends Controller
{
    public function __construct(
        private readonly PolicyEnforcer $policy,
        private readonly PayoutCurrencyRegistry $currencies,
        private readonly PriceOracle $priceOracle,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->policy->canWithdraw($user)) {
            return response()->json(['error' => 'banned_or_blocked'], 403);
        }

        // The form posts `payout_method=faucetpay` by default. Add an
        // entry here when a new gateway registers (see
        // `PayoutGatewayRegistry`) so the validator accepts it.
        $allowedMethods = [Withdrawal::METHOD_FAUCETPAY];
        $allowedCurrencies = array_map(
            fn ($c) => $c->code,
            $this->currencies->faucetpaySupported(),
        );

        $validated = $request->validate([
            'amount_sat' => ['required', 'integer', 'min:1'],
            'payout_method' => ['nullable', 'string', Rule::in($allowedMethods)],
            'payout_currency' => ['required', 'string', Rule::in($allowedCurrencies)],
            'destination' => ['required', 'string', 'max:200'],
        ]);
        $method = $validated['payout_method'] ?? Withdrawal::METHOD_FAUCETPAY;
        $currency = $this->currencies->get($validated['payout_currency']);

        // Per-currency floor — gas costs differ wildly across chains.
        if ((int) $validated['amount_sat'] < $currency->minWithdrawSat) {
            return response()->json([
                'error' => 'below_minimum',
                'min_sat' => $currency->minWithdrawSat,
                'currency' => $currency->code,
            ], 422);
        }

        // FaucetPay route → destination must be email-shaped (FP keys
        // accounts on email). Onchain route (Phase 2+) → address shape
        // checked by the per-chain gateway.
        if ($method === Withdrawal::METHOD_FAUCETPAY
            && filter_var($validated['destination'], FILTER_VALIDATE_EMAIL) === false) {
            return response()->json([
                'error' => 'invalid_destination',
                'reason' => 'faucetpay_requires_email',
            ], 422);
        }

        if ($user->balance_sat < $validated['amount_sat']) {
            return response()->json(['error' => 'insufficient_balance'], 422);
        }

        // Convert BTC sats → target currency smallest unit. Bail BEFORE
        // opening the DB transaction so the user retries without a
        // ledger row to refund. The named PayoutConversion fields
        // (was a positional tuple) eliminate the slot-swap risk where
        // a refactor could persist a rate as the amount or vice versa.
        try {
            $conversion = $this->priceOracle->convertBtcSatToTarget(
                (int) $validated['amount_sat'],
                $currency->code,
            );
            $payoutAmount = $conversion->targetAmount;
            $payoutRate = $conversion->rateSatPerUnit;
        } catch (PriceOracleUnavailableException $e) {
            Log::warning('withdraw blocked: price oracle unavailable', [
                'user_id' => $user->id,
                'currency' => $currency->code,
                'err' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'price_oracle_unavailable',
                'message' => 'Live exchange rates are temporarily unavailable. Please retry in a moment.',
            ], 503);
        }
        // payoutAmount is a decimal string (ETH wei overflows int64) —
        // bccomp instead of `<= 0` so a 20-ETH amount doesn't false-trip
        // the floor check on an int cast.
        if (bccomp((string) $payoutAmount, '0', 0) <= 0) {
            // Conversion floor — sub-unit results would round to zero
            // and FaucetPay rejects amount=0. Defensive: should already
            // be caught by minWithdrawSat but the floor is in BTC sats,
            // not target units, so a misconfigured min for a high-decimal
            // currency could slip through.
            return response()->json([
                'error' => 'amount_below_unit',
                'message' => 'Amount converts to less than 1 unit of the chosen currency.',
            ], 422);
        }

        $withdrawal = DB::transaction(function () use ($user, $validated, $method, $currency, $payoutAmount, $payoutRate) {
            $needsReview = $this->policy->withdrawalNeedsReview($user);
            $w = Withdrawal::create([
                'user_id' => $user->id,
                'amount_sat' => $validated['amount_sat'],
                'payout_method' => $method,
                'payout_currency' => $currency->code,
                'payout_amount' => $payoutAmount,
                'payout_rate' => $payoutRate,
                'destination' => $validated['destination'],
                // FaucetPay charges no publisher-side fee, so the
                // operator absorbs nothing on this route. Onchain
                // gateways override this with their per-tx fee at
                // claim creation time.
                'fee_sat' => 0,
                // Legacy column kept populated for any reporting that
                // still reads `faucetpay_email`. Drops in a later
                // cleanup migration once those readers are gone.
                'faucetpay_email' => $method === Withdrawal::METHOD_FAUCETPAY
                    ? $validated['destination']
                    : null,
                'currency' => $currency->faucetpayCode, // legacy column
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

        // Persist the user's most recent FaucetPay destination so it
        // pre-fills next time they submit. Onchain destinations change
        // too often per-currency to cache here.
        if ($method === Withdrawal::METHOD_FAUCETPAY
            && $user->faucetpay_email !== $validated['destination']) {
            $user->forceFill(['faucetpay_email' => $validated['destination']])->save();
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
            'payout_currency' => $withdrawal->payout_currency,
            'payout_amount' => $withdrawal->payout_amount,
        ], 202);
    }
}
