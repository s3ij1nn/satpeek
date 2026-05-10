<?php

declare(strict_types=1);

namespace Tests\Unit\Payout\Tron;

use App\Payout\Tron\TronHttpClient;
use App\Payout\Tron\TronRpcException;
use App\Payout\Tron\TronUnreachableException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the TronHttpClient contract:
 *   - happy-path RPC call decodes the JSON envelope
 *   - empty `{}` body for unknown txid returns []
 *   - first RPC URL connect-failure falls through to the next URL
 *   - all-URLs-failed surfaces TronRpcException
 *   - non-2xx HTTP error does NOT fall through (could be ambiguous —
 *     might mean our request was already processed)
 *   - empty constructor URL list throws upfront
 */
class TronHttpClientTest extends TestCase
{
    public function test_get_now_block_decodes_block_number(): void
    {
        $client = $this->clientFromResponses([
            new Response(200, [], json_encode([
                'block_header' => ['raw_data' => ['number' => 65000000]],
            ])),
        ]);

        $this->assertSame(65000000, $client->getNowBlock());
    }

    public function test_empty_object_body_returns_empty_array(): void
    {
        // TronGrid returns `{}` for getTransactionInfoById on a tx that
        // isn't in a block yet. Return [] (not null) so callers don't
        // have to guard with ?-> across every read.
        $client = $this->clientFromResponses([
            new Response(200, [], '{}'),
        ]);

        $this->assertSame([], $client->getTransactionInfo('0xabc'));
    }

    public function test_falls_through_to_second_url_on_connect_failure(): void
    {
        $client = $this->clientFromResponses([
            new ConnectException('refused', new Psr7Request('POST', 'https://api.trongrid.io')),
            new Response(200, [], json_encode([
                'block_header' => ['raw_data' => ['number' => 65000001]],
            ])),
        ], rpcUrls: ['https://api.trongrid.io', 'https://tron-rpc.publicnode.com']);

        $this->assertSame(65000001, $client->getNowBlock());
    }

    public function test_all_urls_failed_throws_tron_rpc_exception(): void
    {
        $client = $this->clientFromResponses([
            new ConnectException('refused 1', new Psr7Request('POST', 'https://api.trongrid.io')),
            new ConnectException('refused 2', new Psr7Request('POST', 'https://tron-rpc.publicnode.com')),
        ], rpcUrls: ['https://api.trongrid.io', 'https://tron-rpc.publicnode.com']);

        $this->expectException(TronRpcException::class);
        $this->expectExceptionMessageMatches('/unreachable/i');
        $client->getNowBlock();
    }

    public function test_all_urls_failed_throws_unreachable_subclass(): void
    {
        // ProcessWithdrawalJob distinguishes "request never reached the
        // wire" (safe to retry) from "RPC accepted but errored" (treat
        // as terminal — could already be processed). The Unreachable
        // subclass is the retry signal; pin that all-down throws the
        // specific class, not just the parent TronRpcException.
        $client = $this->clientFromResponses([
            new ConnectException('refused 1', new Psr7Request('POST', 'https://api.trongrid.io')),
            new ConnectException('refused 2', new Psr7Request('POST', 'https://tron-rpc.publicnode.com')),
        ], rpcUrls: ['https://api.trongrid.io', 'https://tron-rpc.publicnode.com']);

        $this->expectException(TronUnreachableException::class);
        $client->getNowBlock();
    }

    public function test_http_error_does_not_fall_through(): void
    {
        // A 5xx from one provider could mean the broadcast WAS processed
        // — falling through to the next URL would risk a double-send.
        // Surface the error and let the caller (Phase 2b TronGateway)
        // decide via the same retry rules ProcessWithdrawalJob already
        // uses for FaucetPay.
        $client = $this->clientFromResponses([
            new RequestException(
                '500 server error',
                new Psr7Request('POST', 'https://api.trongrid.io'),
                new Response(500),
            ),
            // Second URL response that we MUST NOT consume.
            new Response(200, [], json_encode(['block_header' => ['raw_data' => ['number' => 999]]])),
        ], rpcUrls: ['https://api.trongrid.io', 'https://tron-rpc.publicnode.com']);

        $this->expectException(TronRpcException::class);
        $client->getNowBlock();
    }

    public function test_empty_url_list_throws_at_construction(): void
    {
        $this->expectException(RuntimeException::class);
        new TronHttpClient(new Client, [], 10);
    }

    /**
     * @param  array<int, mixed>  $responses
     * @param  array<int, string>|null  $rpcUrls
     */
    private function clientFromResponses(array $responses, ?array $rpcUrls = null): TronHttpClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new TronHttpClient(
            new Client(['handler' => $stack]),
            $rpcUrls ?? ['https://api.trongrid.io'],
            10,
        );
    }
}
