<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Gateway;

use App\Models\Withdrawal;
use App\Payout\Gateway\PayoutGateway;
use App\Payout\Gateway\PayoutGatewayRegistry;
use App\Payout\Gateway\PayoutResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the dispatch contract:
 *   - register + look up by name
 *   - unknown method throws (don't silently fall back to a default
 *     route — sending an onchain payout via FaucetPay because of a
 *     typo would be very bad)
 */
class PayoutGatewayRegistryTest extends TestCase
{
    public function test_registered_gateway_can_be_looked_up_by_name(): void
    {
        $registry = new PayoutGatewayRegistry;
        $registry->register($this->stubGateway('faucetpay'));
        $registry->register($this->stubGateway('onchain_btc'));

        $this->assertSame('faucetpay', $registry->forMethod('faucetpay')->name());
        $this->assertSame('onchain_btc', $registry->forMethod('onchain_btc')->name());
        $this->assertEqualsCanonicalizing(['faucetpay', 'onchain_btc'], $registry->methodNames());
    }

    public function test_unknown_method_throws_rather_than_falling_back(): void
    {
        $registry = new PayoutGatewayRegistry;
        $registry->register($this->stubGateway('faucetpay'));

        $this->expectException(InvalidArgumentException::class);
        $registry->forMethod('onchain_eth');
    }

    private function stubGateway(string $name): PayoutGateway
    {
        return new class($name) implements PayoutGateway
        {
            public function __construct(private readonly string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function send(Withdrawal $withdrawal): PayoutResult
            {
                return PayoutResult::sent('stub-id', 'stub', []);
            }
        };
    }
}
