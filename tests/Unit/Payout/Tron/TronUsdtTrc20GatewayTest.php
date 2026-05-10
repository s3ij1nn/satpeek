<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Tron;

use App\Models\Withdrawal;
use App\Payout\Gateway\TronUsdtTrc20Gateway;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronTxSigner;
use App\Payout\Tron\TronUnreachableException;
use Mockery;
use Tests\TestCase;

/**
 * Unit-level coverage for the TRC20 (USDT) onchain payout path.
 * Mirrors `TronOnchainGatewayTest` but exercises the contract-call
 * shape: triggersmartcontract returns the unsigned tx UNDER a
 * `transaction` key (not at the top level), so the gateway has to
 * descend before signing + broadcasting.
 */
class TronUsdtTrc20GatewayTest extends TestCase
{
    private const VALID_RECIPIENT = 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8';

    private const HOT_WALLET = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    /** Mainnet USDT-TRC20 contract — well-known. */
    private const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const TEST_PRIV = '0000000000000000000000000000000000000000000000000000000000000001';

    public function test_happy_path_returns_sent_with_txid(): void
    {
        $built = [
            'transaction' => [
                'txID' => 'usdt-cafebabe',
                'raw_data_hex' => 'aabbccdd',
                'raw_data' => ['expiration' => 99],
                'visible' => true,
            ],
            'result' => ['result' => true],
        ];

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('triggerSmartContract')
            ->once()
            ->withArgs(function (
                string $owner,
                string $contract,
                string $selector,
                string $parameter,
            ) {
                return $owner === self::HOT_WALLET
                    && $contract === self::USDT_CONTRACT
                    && $selector === 'transfer(address,uint256)'
                    && strlen($parameter) === 128;
            })
            ->andReturn($built);
        $http->shouldReceive('broadcastTransaction')
            ->once()
            ->withArgs(function (array $envelope) {
                return ($envelope['raw_data_hex'] ?? null) === 'aabbccdd'
                    && is_array($envelope['signature'] ?? null);
            })
            ->andReturn(['result' => true]);

        $signer = Mockery::mock(TronTxSigner::class);
        $signer->shouldReceive('sign')->once()->andReturn(str_repeat('aa', 65));

        $gateway = new TronUsdtTrc20Gateway(
            $http, $signer, self::HOT_WALLET, self::TEST_PRIV, self::USDT_CONTRACT,
        );

        $w = new Withdrawal([
            'destination' => self::VALID_RECIPIENT,
            'payout_amount' => '1500000', // 1.5 USDT (6 decimals)
        ]);

        $result = $gateway->send($w);

        $this->assertTrue($result->ok);
        $this->assertSame('usdt-cafebabe', $result->externalId);
    }

    public function test_invalid_destination_short_circuits(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldNotReceive('triggerSmartContract');
        $signer = Mockery::mock(TronTxSigner::class);

        $gateway = new TronUsdtTrc20Gateway(
            $http, $signer, self::HOT_WALLET, self::TEST_PRIV, self::USDT_CONTRACT,
        );
        $w = new Withdrawal([
            'destination' => 'not-a-tron-address',
            'payout_amount' => '1000000',
        ]);

        $result = $gateway->send($w);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('invalid_destination', $result->message);
    }

    public function test_create_failed_returns_failed_with_decoded_error(): void
    {
        // triggersmartcontract returns the build error under `result`
        // when it can't construct the tx (e.g. owner has no energy).
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('triggerSmartContract')->once()->andReturn([
            'result' => [
                'code' => 'CONTRACT_VALIDATE_ERROR',
                'message' => 'account does not exist',
            ],
            // No `transaction` key → gateway treats as build failure.
        ]);
        $signer = Mockery::mock(TronTxSigner::class);

        $gateway = new TronUsdtTrc20Gateway(
            $http, $signer, self::HOT_WALLET, self::TEST_PRIV, self::USDT_CONTRACT,
        );
        $w = new Withdrawal([
            'destination' => self::VALID_RECIPIENT,
            'payout_amount' => '1000000',
        ]);

        $result = $gateway->send($w);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('CONTRACT_VALIDATE_ERROR', $result->message);
    }

    public function test_unreachable_during_create_throws_for_retry(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('triggerSmartContract')
            ->once()
            ->andThrow(new TronUnreachableException('all rpc down'));
        $signer = Mockery::mock(TronTxSigner::class);

        $gateway = new TronUsdtTrc20Gateway(
            $http, $signer, self::HOT_WALLET, self::TEST_PRIV, self::USDT_CONTRACT,
        );
        $w = new Withdrawal([
            'destination' => self::VALID_RECIPIENT,
            'payout_amount' => '1000000',
        ]);

        $this->expectException(TronUnreachableException::class);
        $gateway->send($w);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
