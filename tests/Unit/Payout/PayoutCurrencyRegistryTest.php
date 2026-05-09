<?php

declare(strict_types=1);

namespace Tests\Unit\Payout;

use App\Payout\PayoutCurrency;
use App\Payout\PayoutCurrencyRegistry;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pins the registry contract: known codes resolve to a typed
 * PayoutCurrency, unknown codes throw, and the FaucetPay /
 * onchain filter helpers honour the per-currency support flags.
 */
class PayoutCurrencyRegistryTest extends TestCase
{
    public function test_get_returns_typed_currency_for_known_code(): void
    {
        $reg = new PayoutCurrencyRegistry;

        $btc = $reg->get('BTC');

        $this->assertInstanceOf(PayoutCurrency::class, $btc);
        $this->assertSame('BTC', $btc->code);
        $this->assertSame(8, $btc->decimals);
        $this->assertSame('BTC', $btc->faucetpayCode);
        $this->assertTrue($btc->faucetpaySupported);
    }

    public function test_get_is_case_insensitive(): void
    {
        $reg = new PayoutCurrencyRegistry;
        $this->assertSame('USDT_TRC20', $reg->get('usdt_trc20')->code);
    }

    public function test_get_throws_on_unknown_code(): void
    {
        $reg = new PayoutCurrencyRegistry;
        $this->expectException(InvalidArgumentException::class);
        $reg->get('FAKECOIN');
    }

    public function test_faucetpay_supported_includes_all_phase1_currencies(): void
    {
        $reg = new PayoutCurrencyRegistry;
        $codes = array_map(fn ($c) => $c->code, $reg->faucetpaySupported());

        // The 7 currencies user-explicitly-requested for the multi-currency phase.
        foreach (['BTC', 'LTC', 'ETH', 'USDT_TRC20', 'TRX', 'DASH', 'XMR'] as $expected) {
            $this->assertContains($expected, $codes);
        }
    }

    public function test_onchain_supported_is_empty_in_phase1(): void
    {
        // Phase 1 ships only FaucetPay; onchain gateways arrive in Phase 2+.
        // This test fails the moment a per-chain gateway flips its flag —
        // alerting the next reader that they need to add a real gateway.
        $reg = new PayoutCurrencyRegistry;
        $this->assertSame([], $reg->onchainSupported());
    }
}
