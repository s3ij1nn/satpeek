<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Tron;

use App\Models\Withdrawal;
use App\Payout\Gateway\TronOnchainGateway;
use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use App\Payout\Tron\TronTxSigner;
use App\Payout\Tron\TronUnreachableException;
use Mockery;
use Tests\TestCase;

/**
 * Unit-level coverage for the TRX onchain payout path. The gateway
 * is the seam between three collaborators (HTTP client, signer,
 * address validator) so the tests double the first two and exercise
 * the gateway against a fixture Withdrawal.
 *
 * What we pin here:
 *   - Happy path: createTransaction → sign → broadcast → PayoutResult::sent
 *     with txID as externalId.
 *   - Address validation: a malformed destination short-circuits before
 *     any RPC call (defence-in-depth — controller already rejects but
 *     the gateway must not assume that).
 *   - Unreachable propagation: TronUnreachableException from either
 *     create or broadcast bubbles unchanged so the job's retry
 *     machinery picks it up.
 *   - HTTP error → terminal failed (must NOT retry).
 *   - Broadcast rejection (`result != true`) → terminal failed with
 *     decoded TronGrid error message.
 */
class TronOnchainGatewayTest extends TestCase
{
    /** Real Base58Check-valid mainnet address (USDT-TRC20 contract). */
    private const VALID_ADDRESS = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    /** 64-char hex private key (test value). */
    private const TEST_PRIV = '0000000000000000000000000000000000000000000000000000000000000001';

    private const HOT_WALLET_ADDRESS = 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8';

    public function test_happy_path_returns_sent_with_txid(): void
    {
        $unsignedTx = [
            'txID' => 'cafebabe1234',
            'raw_data_hex' => 'deadbeef',
            'raw_data' => ['expiration' => 99],
            'visible' => true,
        ];

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('createTransaction')
            ->once()
            ->with(self::HOT_WALLET_ADDRESS, self::VALID_ADDRESS, 1_000_000)
            ->andReturn($unsignedTx);
        $http->shouldReceive('broadcastTransaction')
            ->once()
            // Envelope MUST contain raw_data_hex AND signature[].
            ->withArgs(function (array $envelope) {
                return ($envelope['raw_data_hex'] ?? null) === 'deadbeef'
                    && is_array($envelope['signature'] ?? null)
                    && count($envelope['signature']) === 1;
            })
            ->andReturn(['result' => true, 'txid' => 'cafebabe1234']);

        $signer = Mockery::mock(TronTxSigner::class);
        $signer->shouldReceive('sign')
            ->once()
            ->with('deadbeef', self::TEST_PRIV)
            ->andReturn(str_repeat('aa', 65));

        $gateway = new TronOnchainGateway($http, $signer, self::HOT_WALLET_ADDRESS, self::TEST_PRIV);

        $w = new Withdrawal([
            'destination' => self::VALID_ADDRESS,
            'payout_amount' => '1000000', // 1 TRX in sun
        ]);

        $result = $gateway->send($w);

        $this->assertTrue($result->ok);
        $this->assertSame('cafebabe1234', $result->externalId);
    }

    public function test_invalid_destination_short_circuits_before_any_rpc(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldNotReceive('createTransaction');
        $http->shouldNotReceive('broadcastTransaction');
        $signer = Mockery::mock(TronTxSigner::class);
        $signer->shouldNotReceive('sign');

        $gateway = new TronOnchainGateway($http, $signer, self::HOT_WALLET_ADDRESS, self::TEST_PRIV);
        $w = new Withdrawal([
            'destination' => 'not-a-tron-address',
            'payout_amount' => '1000000',
        ]);

        $result = $gateway->send($w);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('invalid_destination', $result->message);
    }

    public function test_zero_amount_returns_failed(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldNotReceive('createTransaction');
        $signer = Mockery::mock(TronTxSigner::class);

        $gateway = new TronOnchainGateway($http, $signer, self::HOT_WALLET_ADDRESS, self::TEST_PRIV);
        $w = new Withdrawal([
            'destination' => self::VALID_ADDRESS,
            'payout_amount' => '0',
        ]);

        $result = $gateway->send($w);

        $this->assertFalse($result->ok);
        $this->assertSame('amount_zero', $result->message);
    }

    public function test_create_transaction_unreachable_throws_for_retry(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('createTransaction')
            ->once()
            ->andThrow(new TronUnreachableException('all rpc down'));
        $signer = Mockery::mock(TronTxSigner::class);

        $gateway = new TronOnchainGateway($http, $signer, self::HOT_WALLET_ADDRESS, self::TEST_PRIV);
        $w = new Withdrawal([
            'destination' => self::VALID_ADDRESS,
            'payout_amount' => '1000000',
        ]);

        $this->expectException(TronUnreachableException::class);
        $gateway->send($w);
    }

    public function test_broadcast_unreachable_throws_for_retry(): void
    {
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('createTransaction')->once()->andReturn([
            'txID' => 'abc', 'raw_data_hex' => 'dead', 'visible' => true,
        ]);
        $http->shouldReceive('broadcastTransaction')
            ->once()
            ->andThrow(new TronUnreachableException('rpc down mid-broadcast'));
        $signer = Mockery::mock(TronTxSigner::class);
        $signer->shouldReceive('sign')->once()->andReturn(str_repeat('00', 65));

        $gateway = new TronOnchainGateway($http, $signer, self::HOT_WALLET_ADDRESS, self::TEST_PRIV);
        $w = new Withdrawal([
            'destination' => self::VALID_ADDRESS,
            'payout_amount' => '1000000',
        ]);

        $this->expectException(TronUnreachableException::class);
        $gateway->send($w);
    }

    public function test_create_http_error_returns_failed_terminal(): void
    {
        // HTTP 5xx from createtransaction → could mean the tx was never
        // built. Treated as terminal because we can't tell, and
        // re-creating + broadcasting could double-send. Mirrors
        // FaucetPay's "any non-Connect failure is terminal" logic.
        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('createTransaction')
            ->once()
            ->andThrow(new TronRpcException('500 server error'));
        $signer = Mockery::mock(TronTxSigner::class);

        $gateway = new TronOnchainGateway($http, $signer, self::HOT_WALLET_ADDRESS, self::TEST_PRIV);
        $w = new Withdrawal([
            'destination' => self::VALID_ADDRESS,
            'payout_amount' => '1000000',
        ]);

        $result = $gateway->send($w);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('create_failed', $result->message);
    }

    public function test_broadcast_rejection_decodes_hex_message(): void
    {
        // TronGrid encodes failure messages as hex bytes (legacy
        // protobuf habit). Decode for operator-readable logs.
        $hexMessage = bin2hex('CONTRACT_VALIDATE_ERROR : balance is not sufficient');

        $http = Mockery::mock(TronHttpClient::class);
        $http->shouldReceive('createTransaction')->once()->andReturn([
            'txID' => 'abc', 'raw_data_hex' => 'dead', 'visible' => true,
        ]);
        $http->shouldReceive('broadcastTransaction')->once()->andReturn([
            'result' => false,
            'code' => 'CONTRACT_VALIDATE_ERROR',
            'message' => $hexMessage,
        ]);
        $signer = Mockery::mock(TronTxSigner::class);
        $signer->shouldReceive('sign')->once()->andReturn(str_repeat('00', 65));

        $gateway = new TronOnchainGateway($http, $signer, self::HOT_WALLET_ADDRESS, self::TEST_PRIV);
        $w = new Withdrawal([
            'destination' => self::VALID_ADDRESS,
            'payout_amount' => '1000000',
        ]);

        $result = $gateway->send($w);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('balance is not sufficient', $result->message);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
