<?php

declare(strict_types=1);

namespace App\Payout\Eth;

use App\Payout\Tron\TronHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin JSON-RPC wrapper around the public Ethereum execution-layer
 * endpoint family (publicnode, Cloudflare ETH, llamarpc, etc.).
 *
 * Methods we need for the onchain payout pipeline:
 *   - eth_blockNumber              chain head (for confirmations math)
 *   - eth_getBalance               hot-wallet balance probe
 *   - eth_getTransactionCount      next nonce for the hot wallet
 *   - eth_feeHistory               fee oracle (replaces deprecated eth_gasPrice)
 *   - eth_chainId                  for EIP-155 / EIP-1559 signing
 *   - eth_sendRawTransaction       broadcast
 *   - eth_getTransactionReceipt    confirmation + status
 *
 * Multi-URL fallback: identical to {@see TronHttpClient}.
 * publicnode + Cloudflare ETH gives us two independent operators.
 * Connect failures fall through; HTTP errors do NOT (could already
 * be processed). Same retry semantics ProcessWithdrawalJob already
 * encodes for FaucetPay + Tron.
 */
class EthHttpClient
{
    /**
     * @param  array<int, string>  $rpcUrls
     */
    public function __construct(
        private readonly Client $http,
        private readonly array $rpcUrls,
        private readonly int $requestTimeoutSeconds = 10,
    ) {
        if ($rpcUrls === []) {
            throw new RuntimeException('EthHttpClient requires at least one RPC URL');
        }
    }

    /** Latest block number on the chain. Returns int (well within int64 for any realistic chain). */
    public function blockNumber(): int
    {
        $hex = (string) $this->call('eth_blockNumber', []);

        return (int) hexdec(self::strip0x($hex));
    }

    /** EIP-155 chain ID (1 = mainnet, 11155111 = sepolia, etc.). */
    public function chainId(): int
    {
        $hex = (string) $this->call('eth_chainId', []);

        return (int) hexdec(self::strip0x($hex));
    }

    /**
     * Wallet's spendable balance in wei. Returns a decimal STRING
     * (wei × multi-ETH overflows int64).
     */
    public function getBalance(string $address): string
    {
        $hex = (string) $this->call('eth_getBalance', [$address, 'latest']);

        return gmp_strval(gmp_init(self::strip0x($hex), 16), 10);
    }

    /** Pending nonce for `$address` (count of confirmed + pending txs). */
    public function getTransactionCount(string $address): int
    {
        $hex = (string) $this->call('eth_getTransactionCount', [$address, 'pending']);

        return (int) hexdec(self::strip0x($hex));
    }

    /**
     * Recent base-fee + tip history for fee oracle. Returns the raw
     * envelope; callers extract baseFeePerGas + reward arrays. We
     * always request the last 5 blocks at the 50th percentile —
     * enough signal for a hot-wallet payout cron (no urgency).
     *
     * @return array<string, mixed>
     */
    public function feeHistory(int $blockCount = 5, int $rewardPercentile = 50): array
    {
        $blockCountHex = '0x'.dechex($blockCount);
        $result = $this->call('eth_feeHistory', [$blockCountHex, 'latest', [$rewardPercentile]]);

        return is_array($result) ? $result : [];
    }

    /**
     * Broadcast a signed raw transaction. `$rawHex` MUST start with
     * `0x` and contain the type-2 envelope (0x02 || RLP-encoded
     * signed list). Returns the txHash.
     */
    public function sendRawTransaction(string $rawHex): string
    {
        return (string) $this->call('eth_sendRawTransaction', [$rawHex]);
    }

    /**
     * Receipt for a previously-broadcast tx. Returns the parsed
     * envelope (`blockNumber` hex, `status` hex `0x1` / `0x0`,
     * etc.) or [] when the tx hasn't landed yet (RPC returns
     * null which we coerce to []).
     *
     * @return array<string, mixed>
     */
    public function getTransactionReceipt(string $txHash): array
    {
        $r = $this->call('eth_getTransactionReceipt', [$txHash]);

        // null = not mined yet; [] makes consumer code uniform.
        return is_array($r) ? $r : [];
    }

    /**
     * @param  array<int, mixed>  $params
     */
    private function call(string $method, array $params): mixed
    {
        $body = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ];
        $lastError = null;
        foreach ($this->rpcUrls as $base) {
            try {
                $response = $this->http->request('POST', $base, [
                    'json' => $body,
                    'timeout' => $this->requestTimeoutSeconds,
                    'headers' => ['Accept' => 'application/json'],
                ]);
                $decoded = json_decode((string) $response->getBody(), true);
                if (! is_array($decoded)) {
                    throw new EthRpcException("eth rpc {$method}: non-json body");
                }
                if (isset($decoded['error'])) {
                    $err = $decoded['error'];
                    $msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err)) : (string) $err;
                    throw new EthRpcException("eth rpc {$method}: {$msg}");
                }
                if (! array_key_exists('result', $decoded)) {
                    throw new EthRpcException("eth rpc {$method}: missing result");
                }

                return $decoded['result'];
            } catch (ConnectException $e) {
                $lastError = $e;
                Log::debug('eth rpc connect failed, trying fallback', [
                    'url' => $base, 'method' => $method, 'err' => $e->getMessage(),
                ]);

                continue;
            } catch (GuzzleException $e) {
                throw new EthRpcException(
                    "eth rpc http error ({$method}): ".$e->getMessage(),
                    previous: $e,
                );
            }
        }

        throw new EthUnreachableException(
            "all eth rpc urls unreachable ({$method}); last error: ".($lastError?->getMessage() ?? 'unknown'),
            previous: $lastError,
        );
    }

    private static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
    }
}
