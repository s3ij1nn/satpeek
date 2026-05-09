<?php

declare(strict_types=1);

namespace App\Payout\Gateway;

use InvalidArgumentException;

/**
 * Lookup table for the {@see PayoutGateway} implementations.
 *
 * `ProcessWithdrawalJob` calls `forMethod($withdrawal->payout_method)`
 * to pick the right gateway. Phase 1 ships only `faucetpay`; per-chain
 * onchain gateways register here (`onchain_btc`, `onchain_eth`,
 * `onchain_trx`, `onchain_usdt_trc20`) as they land in Phase 2+.
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
