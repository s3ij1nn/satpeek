<?php

declare(strict_types=1);

namespace Tests\Unit\Payout;

use App\Payout\PayoutCurrencyRegistry;
use App\Payout\PriceOracle;
use App\Payout\PriceOracleUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the BTC-sat → target conversion contract:
 *   - identity for BTC→BTC (no oracle hit)
 *   - decimal-precision-correct conversion for ETH (18 decimals)
 *     and USDT-TRC20 (6 decimals)
 *   - cache hit serves stale rate without re-fetching
 *   - CoinGecko outage with no cache → throws PriceOracleUnavailableException
 *
 * We mock Guzzle so the test never touches the public network.
 */
class PriceOracleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_btc_to_btc_is_identity(): void
    {
        $oracle = new PriceOracle($this->mockClient([]), $this->app->make(PayoutCurrencyRegistry::class));

        [$amount, $rate] = $oracle->convertBtcSatToTarget(50_000, 'BTC');

        $this->assertSame('50000', $amount);
        $this->assertSame('1', $rate);
    }

    public function test_btc_to_eth_uses_decimals_correctly(): void
    {
        // BTC at $60,000, ETH at $3,000 → 1 BTC = 20 ETH.
        // 100,000,000 sats (1 BTC) → 20 ETH = 20 * 1e18 wei.
        $http = $this->mockClient([
            new Response(200, [], json_encode([
                'bitcoin' => ['usd' => 60000.0],
                'ethereum' => ['usd' => 3000.0],
                'litecoin' => ['usd' => 80.0],
                'tether' => ['usd' => 1.0],
                'tron' => ['usd' => 0.10],
                'dash' => ['usd' => 30.0],
                'monero' => ['usd' => 150.0],
            ])),
        ]);
        $oracle = new PriceOracle($http, $this->app->make(PayoutCurrencyRegistry::class));

        [$amount, $rate] = $oracle->convertBtcSatToTarget(100_000_000, 'ETH');

        // 1 BTC at $60k / $3k per ETH = 20 ETH = 20e18 wei. The string
        // representation of 20 * 10^18 = 20000000000000000000 — overflows
        // signed-64-bit (max ~9.2e18) so PriceOracle returns it as a
        // decimal string and the caller persists it into a decimal(36,0)
        // column.
        $this->assertSame('20000000000000000000', $amount);
        // Rate: 1 ETH costs $3000 / $60000 BTC = 0.05 BTC = 5,000,000 sats.
        $this->assertSame('5000000', explode('.', $rate)[0]);
    }

    public function test_btc_to_usdt_trc20_with_6_decimals(): void
    {
        // BTC at $60k, USDT at $1.
        // 100,000 sats = 0.001 BTC × $60,000 = $60 = 60 USDT.
        // 60 USDT × 10^6 = 60,000,000 USDT-TRC20 smallest units.
        $http = $this->mockClient([
            new Response(200, [], json_encode([
                'bitcoin' => ['usd' => 60000.0],
                'ethereum' => ['usd' => 3000.0],
                'litecoin' => ['usd' => 80.0],
                'tether' => ['usd' => 1.0],
                'tron' => ['usd' => 0.10],
                'dash' => ['usd' => 30.0],
                'monero' => ['usd' => 150.0],
            ])),
        ]);
        $oracle = new PriceOracle($http, $this->app->make(PayoutCurrencyRegistry::class));

        [$amount, $rate] = $oracle->convertBtcSatToTarget(100_000, 'USDT_TRC20');

        $this->assertSame('60000000', $amount);
    }

    public function test_cache_returns_warm_value_without_second_fetch(): void
    {
        $http = $this->mockClient([
            new Response(200, [], json_encode(['bitcoin' => ['usd' => 60000.0], 'tether' => ['usd' => 1.0], 'ethereum' => ['usd' => 3000.0], 'litecoin' => ['usd' => 80.0], 'tron' => ['usd' => 0.1], 'dash' => ['usd' => 30.0], 'monero' => ['usd' => 150.0]])),
            // No second response queued — if oracle hits the network
            // a second time the mock handler throws.
        ]);
        $oracle = new PriceOracle($http, $this->app->make(PayoutCurrencyRegistry::class));

        $oracle->convertBtcSatToTarget(100_000, 'USDT_TRC20');
        // Second call MUST hit the cache, not re-fetch.
        [$amount] = $oracle->convertBtcSatToTarget(100_000, 'USDT_TRC20');

        $this->assertSame('60000000', $amount);
    }

    public function test_coingecko_outage_with_cold_cache_throws(): void
    {
        $http = $this->mockClient([
            new ConnectException('connection refused', new Psr7Request('GET', 'https://api.coingecko.com/api/v3/simple/price')),
        ]);
        $oracle = new PriceOracle($http, $this->app->make(PayoutCurrencyRegistry::class));

        $this->expectException(PriceOracleUnavailableException::class);
        $oracle->convertBtcSatToTarget(100_000, 'USDT_TRC20');
    }

    /**
     * @param  array<int, mixed>  $responses
     */
    private function mockClient(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new Client(['handler' => $stack]);
    }
}
