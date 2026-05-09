<?php

declare(strict_types=1);

namespace App\Payout;

use InvalidArgumentException;

/**
 * Single source of truth for the currencies SatPeek pays out in.
 *
 * Reads `config('satpeek.payout.currencies')` at boot and exposes
 * lookup / filter helpers. Keeping this in a singleton (resolved via
 * the container) avoids re-parsing the config on every withdrawal.
 *
 * Adding a new currency is a one-place edit in `config/satpeek.php`;
 * the registry, FaucetPayGateway, and (eventually) the per-chain
 * onchain gateways pick it up automatically.
 */
class PayoutCurrencyRegistry
{
    /** @var array<string, PayoutCurrency> */
    private array $currencies;

    public function __construct()
    {
        $cfg = (array) config('satpeek.payout.currencies', []);
        $this->currencies = [];
        foreach ($cfg as $code => $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = strtoupper((string) $code);
            $this->currencies[$code] = new PayoutCurrency(
                code: $code,
                label: (string) ($row['label'] ?? $code),
                faucetpayCode: (string) ($row['faucetpay_code'] ?? $code),
                decimals: (int) ($row['decimals'] ?? 8),
                minWithdrawSat: (int) ($row['min_withdraw_sat'] ?? 1000),
                faucetpaySupported: (bool) ($row['faucetpay_supported'] ?? true),
                onchainSupported: (bool) ($row['onchain_supported'] ?? false),
                coingeckoId: (string) ($row['coingecko_id'] ?? ''),
            );
        }
    }

    /**
     * Look up a currency by its SatPeek-internal code (BTC, USDT_TRC20…).
     * Throws on unknown codes — callers are upstream of validation, so
     * the user-facing error happens at form-validate time, not here.
     */
    public function get(string $code): PayoutCurrency
    {
        $code = strtoupper($code);
        if (! isset($this->currencies[$code])) {
            throw new InvalidArgumentException("unknown payout currency: {$code}");
        }

        return $this->currencies[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->currencies[strtoupper($code)]);
    }

    /** @return array<int, PayoutCurrency> */
    public function all(): array
    {
        return array_values($this->currencies);
    }

    /** @return array<int, PayoutCurrency> */
    public function faucetpaySupported(): array
    {
        return array_values(array_filter(
            $this->currencies,
            fn (PayoutCurrency $c): bool => $c->faucetpaySupported,
        ));
    }

    /** @return array<int, PayoutCurrency> */
    public function onchainSupported(): array
    {
        return array_values(array_filter(
            $this->currencies,
            fn (PayoutCurrency $c): bool => $c->onchainSupported,
        ));
    }
}
