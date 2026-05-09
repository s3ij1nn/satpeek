<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use App\Models\Withdrawal;
use App\Payout\FaucetPayClient;
use App\Payout\PayoutCurrencyRegistry;

/**
 * FaucetPay route — wraps the existing {@see FaucetPayClient} HTTP
 * client behind the {@see PayoutGateway} contract.
 *
 * The actual FaucetPay protocol mechanics (form-encoded POST,
 * `currency` parameter, `payout_id` reference) live in FaucetPayClient
 * because Phase 0 already proved them out + has the
 * FaucetPayUnreachableException retry semantics wired in. This class
 * just translates from a `Withdrawal` row to the client's
 * positional arguments.
 *
 * Currency mapping: `Withdrawal.payout_currency` is the SatPeek
 * internal code (e.g. `USDT_TRC20`); FaucetPay wants its own short
 * code (e.g. `USDTTRC`). The registry's `faucetpayCode` translation
 * lives there so a future code shuffle is one-place.
 *
 * Amount: `Withdrawal.payout_amount` is in the target currency's
 * smallest unit (already converted via PriceOracle at withdrawal
 * creation time). FaucetPay's `/send` takes that exact integer.
 */
class FaucetPayGateway implements PayoutGateway
{
    public function __construct(
        private readonly FaucetPayClient $client,
        private readonly PayoutCurrencyRegistry $registry,
    ) {}

    public function name(): string
    {
        return 'faucetpay';
    }

    public function send(Withdrawal $withdrawal): PayoutResult
    {
        // Legacy rows (pre-multi-currency) carry no payout_currency /
        // payout_amount. Fall back to the BTC-sat amount + 'BTC' so
        // those rows continue to settle through the same path.
        $currencyCode = $withdrawal->payout_currency ?? 'BTC';
        $currency = $this->registry->has($currencyCode)
            ? $this->registry->get($currencyCode)
            : $this->registry->get('BTC');

        // payout_amount is a decimal string (decimal(36, 0)) so ETH wei
        // doesn't overflow. FaucetPay's per-currency caps are all well
        // under int64 in practice — coercing here is safe for every FP
        // currency. Falls back to amount_sat for pre-multi-currency
        // rows where payout_amount is null.
        $amount = (int) ($withdrawal->payout_amount ?? $withdrawal->amount_sat);
        $destination = $withdrawal->destination ?? $withdrawal->faucetpay_email;

        $result = $this->client->send(
            faucetpayEmail: (string) $destination,
            amountSat: $amount,
            currency: $currency->faucetpayCode,
            referenceId: 'satpeek-withdraw-'.$withdrawal->id,
        );

        return $result['ok']
            ? PayoutResult::sent(
                externalId: $result['payout_id'],
                message: $result['message'],
                raw: $result['raw'],
            )
            : PayoutResult::failed($result['message'], $result['raw']);
    }
}
