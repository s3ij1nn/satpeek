<?php

declare(strict_types=1);

namespace App\Payout;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * BTC-sats → target-currency conversion driven by CoinGecko's free
 * `simple/price` endpoint.
 *
 * Why CoinGecko? The free tier requires no API key, has generous
 * rate limits (≈30/min), covers every currency in
 * {@see PayoutCurrencyRegistry}, and exposes USD prices as decimals
 * we can convert against without unit-juggling. The endpoint is
 * `GET /simple/price?ids=bitcoin,ethereum,...&vs_currencies=usd`.
 *
 * Caching: a single multi-currency fetch covers every supported coin
 * in one HTTP round-trip; we cache the resulting USD-price map for
 * `cache_ttl_seconds` (default 60). The withdrawal flow lands a few
 * times per minute at most, so this drops oracle traffic to a couple
 * of CoinGecko hits per minute platform-wide.
 *
 * Failure mode: if CoinGecko is unreachable AND no cache entry exists,
 * `convertBtcSatToTarget()` throws {@see PriceOracleUnavailableException}.
 * The withdrawal controller catches this and surfaces a 503-style
 * error to the user — better than guessing a stale rate that could
 * over- or under-pay. A *stale* cache (within TTL) is fine to use:
 * we'd rather pay slightly off-market than refuse the withdrawal.
 */
class PriceOracle
{
    private const CACHE_KEY = 'payout-price-oracle:usd-prices:v1';

    public function __construct(
        private readonly Client $http,
        private readonly PayoutCurrencyRegistry $registry,
    ) {}

    /**
     * Convert a BTC-sats amount to the smallest unit of `$target`.
     *
     * Returns a {@see PayoutConversion}; both fields are stringified
     * bcmath decimals (ETH wei × multi-BTC balance overflows
     * signed-64-bit; the rate carries 18 fractional digits).
     *
     * @throws PriceOracleUnavailableException
     */
    public function convertBtcSatToTarget(int $btcSat, string $targetCode): PayoutConversion
    {
        $target = $this->registry->get($targetCode);

        // Same-currency optimisation — BTC→BTC is the identity, no
        // oracle needed and no precision loss from float math.
        if ($target->code === 'BTC') {
            return new PayoutConversion((string) $btcSat, '1');
        }

        $prices = $this->usdPrices();
        $btcUsd = (float) ($prices['bitcoin'] ?? 0);
        $targetUsd = (float) ($prices[$target->coingeckoId] ?? 0);
        if ($btcUsd <= 0 || $targetUsd <= 0) {
            throw new PriceOracleUnavailableException(
                "missing price for bitcoin or {$target->coingeckoId}",
            );
        }

        // amount in target's main unit = (sat * 1e-8 * BTC/USD) / (target/USD)
        // amount in target's smallest unit = above * 10^decimals
        // Use bcmath so 18-digit ETH wei doesn't lose precision through
        // float arithmetic (PHP's float can't represent 10^18 exactly).
        $btcMain = bcdiv((string) $btcSat, '100000000', 30);
        $usdValue = bcmul($btcMain, $this->bcFloat($btcUsd, 18), 30);
        $targetMain = bcdiv($usdValue, $this->bcFloat($targetUsd, 18), 30);
        // Truncate to integer (scale=0 floors towards zero) — safe
        // direction, we never overpay the user.
        $amount = bcmul($targetMain, bcpow('10', (string) $target->decimals, 0), 0);
        if (bccomp($amount, '0', 0) < 0) {
            $amount = '0';
        }

        // Rate stored as "1 target main unit = X BTC sats" so a refund
        // can recompute amount_sat from payout_amount + rate without
        // re-hitting the oracle.
        $satPerTarget = bcmul(
            bcdiv($this->bcFloat($targetUsd, 18), $this->bcFloat($btcUsd, 18), 30),
            '100000000',
            18,
        );

        return new PayoutConversion($amount, $satPerTarget);
    }

    /**
     * @return array<string, float>
     *
     * @throws PriceOracleUnavailableException
     */
    private function usdPrices(): array
    {
        $ttl = (int) config('satpeek.payout.price_oracle.cache_ttl_seconds', 60);

        return Cache::remember(self::CACHE_KEY, max(1, $ttl), function (): array {
            $ids = array_unique(array_filter(
                array_map(fn (PayoutCurrency $c): string => $c->coingeckoId, $this->registry->all()),
            ));
            // Always include bitcoin — every conversion goes through it.
            $ids[] = 'bitcoin';
            $ids = array_values(array_unique($ids));

            $base = (string) config('satpeek.payout.price_oracle.coingecko_base', 'https://api.coingecko.com/api/v3');
            $timeout = (int) config('satpeek.payout.price_oracle.request_timeout_seconds', 5);

            try {
                $response = $this->http->request('GET', rtrim($base, '/').'/simple/price', [
                    'query' => [
                        'ids' => implode(',', $ids),
                        'vs_currencies' => 'usd',
                    ],
                    'timeout' => $timeout,
                ]);
                $decoded = json_decode((string) $response->getBody(), true);
                if (! is_array($decoded)) {
                    throw new PriceOracleUnavailableException('coingecko: invalid body');
                }

                // Flatten ['bitcoin' => ['usd' => 60000.0]] → ['bitcoin' => 60000.0]
                $flat = [];
                foreach ($decoded as $id => $row) {
                    if (is_array($row) && isset($row['usd'])) {
                        $flat[(string) $id] = (float) $row['usd'];
                    }
                }
                if ($flat === []) {
                    throw new PriceOracleUnavailableException('coingecko: empty price map');
                }

                return $flat;
            } catch (GuzzleException $e) {
                Log::warning('price oracle request failed', ['err' => $e->getMessage()]);
                throw new PriceOracleUnavailableException('coingecko unreachable: '.$e->getMessage(), previous: $e);
            }
        });
    }

    /**
     * Convert a float to a bcmath-safe decimal string with bounded
     * fractional precision. PHP's (string) cast goes scientific for
     * very small / very large floats — bcmath rejects "1.0E-8".
     */
    private function bcFloat(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
