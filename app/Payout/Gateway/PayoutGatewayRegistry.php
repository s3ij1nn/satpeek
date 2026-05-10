<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use InvalidArgumentException;

/**
 * Lookup table for the {@see PayoutGateway} implementations.
 *
 * `ProcessWithdrawalJob` calls `forMethod($withdrawal->payout_method)`
 * to pick the right gateway. Each registered gateway maps its
 * `name()` (e.g. `faucetpay`, `onchain_btc`, `onchain_eth`) to the
 * payout-method values stored on `Withdrawal.payout_method`. Adding
 * a new chain is one call to `register()` from the service provider.
 *
 * Wired up in App\Providers\AppServiceProvider — the container
 * instantiates each gateway and the registry holds them by name.
 */
class PayoutGatewayRegistry
{
    /** @var array<string, PayoutGateway> */
    private array $gateways = [];

    public function register(PayoutGateway $gateway): void
    {
        $this->gateways[$gateway->name()] = $gateway;
    }

    public function forMethod(string $method): PayoutGateway
    {
        if (! isset($this->gateways[$method])) {
            throw new InvalidArgumentException("no payout gateway registered for method '{$method}'");
        }

        return $this->gateways[$method];
    }

    public function has(string $method): bool
    {
        return isset($this->gateways[$method]);
    }

    /** @return array<int, string> */
    public function methodNames(): array
    {
        return array_keys($this->gateways);
    }
}
