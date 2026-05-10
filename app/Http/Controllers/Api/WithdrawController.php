<?php

namespace App\Http\Controllers\Api;

use App\BotDetection\PolicyEnforcer;
use App\Http\Controllers\Controller;
use App\Mail\WithdrawalQueuedEmail;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use App\Payout\Eth\EthAddress;
use App\Payout\Gateway\PayoutGatewayRegistry;
use App\Payout\PayoutCurrencyRegistry;
use App\Payout\PriceOracle;
use App\Payout\PriceOracleUnavailableException;
use App\Payout\Tron\TronAddress;
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
        private readonly PayoutGatewayRegistry $gateways,
    ) {}

    /**
     * Return the currency codes the user may pick under `$method`.
     * FaucetPay route shows everything FP supports; onchain_trx is
     * single-currency by definition (TRX). Future onchain_btc /
     * onchain_eth gateways slot in here as their own per-currency
     * single-element arrays.
     *
     * @return array<int, string>
     */
    private function currenciesForMethod(string $method): array
    {
        if ($method === Withdrawal::METHOD_ONCHAIN_TRX) {
            return ['TRX'];
        }
        if ($method === Withdrawal::METHOD_ONCHAIN_USDT_TRC20) {
            return ['USDT_TRC20'];
        }
        if ($method === Withdrawal::METHOD_ONCHAIN_ETH) {
            return ['ETH'];
        }

        return array_map(
            fn ($c) => $c->code,
            $this->currencies->faucetpaySupported(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->policy->canWithdraw($user)) {
            return response()->json(['error' => 'banned_or_blocked'], 403);
        }

        // The form posts `payout_method=faucetpay` by default. Onchain
        // methods are only listed when the matching gateway is actually
        // registered — keeps the validator honest with what
        // `PayoutGatewayRegistry::forMethod()` will accept on dispatch.
        $allowedMethods = [Withdrawal::METHOD_FAUCETPAY];
        if ($this->gateways->has(Withdrawal::METHOD_ONCHAIN_TRX)) {
            $allowedMethods[] = Withdrawal::METHOD_ONCHAIN_TRX;
        }
        if ($this->gateways->has(Withdrawal::METHOD_ONCHAIN_USDT_TRC20)) {
            $allowedMethods[] = Withdrawal::METHOD_ONCHAIN_USDT_TRC20;
        }
        if ($this->gateways->has(Withdrawal::METHOD_ONCHAIN_ETH)) {
            $allowedMethods[] = Withdrawal::METHOD_ONCHAIN_ETH;
        }
        // Allowed currencies depend on the chosen method; default to
        // FaucetPay-supported until we see the request's `payout_method`.
        $methodForCurrencies = (string) $request->input('payout_method', Withdrawal::METHOD_FAUCETPAY);
        $allowedCurrencies = $this->currenciesForMethod($methodForCurrencies);

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

        // Per-method destination shape check. FaucetPay keys accounts
        // on email; onchain_trx requires a valid Base58Check Tron
        // address (TronAddress::isValid runs the double-SHA256
        // checksum so a typo'd address is refused before the funds
        // ever leave SatPeek's custody).
        if ($method === Withdrawal::METHOD_FAUCETPAY
            && filter_var($validated['destination'], FILTER_VALIDATE_EMAIL) === false) {
            return response()->json([
                'error' => 'invalid_destination',
                'reason' => 'faucetpay_requires_email',
            ], 422);
        }
        if ($method === Withdrawal::METHOD_ONCHAIN_TRX
            && ! TronAddress::isValid($validated['destination'])) {
            return response()->json([
                'error' => 'invalid_destination',
                'reason' => 'onchain_trx_requires_tron_address',
            ], 422);
        }
        // USDT-TRC20 lives on the Tron chain → same address shape.
        if ($method === Withdrawal::METHOD_ONCHAIN_USDT_TRC20
            && ! TronAddress::isValid($validated['destination'])) {
            return response()->json([
                'error' => 'invalid_destination',
                'reason' => 'onchain_usdt_trc20_requires_tron_address',
            ], 422);
        }
        if ($method === Withdrawal::METHOD_ONCHAIN_ETH
            && ! EthAddress::isValid($validated['destination'])) {
            return response()->json([
                'error' => 'invalid_destination',
                'reason' => 'onchain_eth_requires_eth_address',
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
