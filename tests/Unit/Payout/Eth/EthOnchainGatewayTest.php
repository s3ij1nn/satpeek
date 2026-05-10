<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Eth;

use App\Models\Withdrawal;
use App\Payout\Eth\EthHttpClient;
use App\Payout\Eth\EthRpcException;
use App\Payout\Eth\EthTxSigner;
use App\Payout\Eth\EthUnreachableException;
use App\Payout\Gateway\EthOnchainGateway;
use Mockery;
use Tests\TestCase;

class EthOnchainGatewayTest extends TestCase
{
    private const HOT_WALLET = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

    private const RECIPIENT = '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359';

    private const TEST_PRIV = '4646464646464646464646464646464646464646464646464646464646464646';

    public function test_happy_path_returns_sent_with_broadcast_txhash(): void
    {
        $http = Mockery::mock(EthHttpClient::class);
        $http->shouldReceive('chainId')->once()->andReturn(1);
        $http->shouldReceive('getTransactionCount')->with(self::HOT_WALLET)->once()->andReturn(7);
        $http->shouldReceive('feeHistory')->once()->andReturn([
            'baseFeePerGas' => ['0x12a05f200', '0x12a05f200', '0x12a05f200', '0x12a05f200', '0x12a05f200', '0x12a05f200'],
            'reward' => [
                ['0x59682f00'], ['0x59682f00'], ['0x59682f00'], ['0x59682f00'], ['0x59682f00'],
            ],
        ]);
        $http->shouldReceive('sendRawTransaction')
            ->once()
            ->withArgs(fn (string $raw): bool => str_starts_with($raw, '0x02'))
            ->andReturn('0xdeadbeef');

        $gateway = new EthOnchainGateway($http, new EthTxSigner, self::HOT_WALLET, self::TEST_PRIV);
        $w = new Withdrawal([
            'destination' => self::RECIPIENT,
            'payout_amount' => '1000000000000000000', // 1 ETH
        ]);

        $result = $gateway->send($w);

        $this->assertTrue($result->ok);
        $this->assertSame('0xdeadbeef', $result->externalId);
    }

    public function test_invalid_destination_short_circuits_no_rpc(): void
    {
        $http = Mockery::mock(EthHttpClient::class);
        $http->shouldNotReceive('chainId');

        $gateway = new EthOnchainGateway($http, new EthTxSigner, self::HOT_WALLET, self::TEST_PRIV);
        $w = new Withdrawal(['destination' => 'not-eth', 'payout_amount' => '1']);

        $result = $gateway->send($w);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('invalid_destination', $result->message);
    }

    public function test_zero_amount_returns_failed(): void
    {
        $http = Mockery::mock(EthHttpClient::class);
        $http->shouldNotReceive('chainId');

        $gateway = new EthOnchainGateway($http, new EthTxSigner, self::HOT_WALLET, self::TEST_PRIV);
        $w = new Withdrawal(['destination' => self::RECIPIENT, 'payout_amount' => '0']);

        $result = $gateway->send($w);
        $this->assertFalse($result->ok);
        $this->assertSame('amount_zero', $result->message);
    }

    public function test_state_read_unreachable_throws_for_retry(): void
    {
        $http = Mockery::mock(EthHttpClient::class);
        $http->shouldReceive('chainId')->once()
            ->andThrow(new EthUnreachableException('all rpc down'));

        $gateway = new EthOnchainGateway($http, new EthTxSigner, self::HOT_WALLET, self::TEST_PRIV);
        $w = new Withdrawal(['destination' => self::RECIPIENT, 'payout_amount' => '1000']);

        $this->expectException(EthUnreachableException::class);
        $gateway->send($w);
    }

    public function test_broadcast_http_error_returns_failed_terminal(): void
    {
        $http = Mockery::mock(EthHttpClient::class);
        $http->shouldReceive('chainId')->once()->andReturn(1);
        $http->shouldReceive('getTransactionCount')->once()->andReturn(0);
        $http->shouldReceive('feeHistory')->once()->andReturn([
            'baseFeePerGas' => ['0x1', '0x1'],
            'reward' => [['0x0']],
        ]);
        $http->shouldReceive('sendRawTransaction')->once()
            ->andThrow(new EthRpcException('replacement transaction underpriced'));

        $gateway = new EthOnchainGateway($http, new EthTxSigner, self::HOT_WALLET, self::TEST_PRIV);
        $w = new Withdrawal(['destination' => self::RECIPIENT, 'payout_amount' => '1000000000000000000']);

        $result = $gateway->send($w);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('broadcast_failed', $result->message);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
