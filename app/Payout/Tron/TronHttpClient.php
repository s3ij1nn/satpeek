<?php

declare(strict_types=1);

namespace App\Payout\Tron;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP wrapper around the public Tron node RPC family
 * (TronGrid, publicnode TRON, getblock-style providers).
 *
 * Phase 2a (this commit): READ + BROADCAST surface only — no signing.
 * Signing requires ext-gmp + a secp256k1 implementation and lands in
 * Phase 2b along with the actual TronGateway. Without signing this
 * class can't move funds; it can only:
 *
 *   - resolve the latest block (sanity / chain-head check)
 *   - read account balance + tx history
 *   - broadcast an already-signed transaction
 *
 * That's enough to support the deposit-detection use case (operator
 * watches the hot-wallet address and credits ad-funding when
 * USDT-TRC20 lands) and to scaffold the eventual send path.
 *
 * Multi-RPC fallback: the constructor takes a list of URLs and tries
 * each in order on transport failure. publicnode + TronGrid as a pair
 * gives us two independent operators so a single outage doesn't halt
 * SatPeek's payout queue. The first 2xx response wins; subsequent
 * failures fall through to the next URL. Treat-all-as-equal semantics
 * — we don't pin "primary" vs "secondary" because both have similar
 * SLAs and rotating through both keeps either provider's rate-limit
 * counter low.
 */
class TronHttpClient
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
            throw new RuntimeException('TronHttpClient requires at least one RPC URL');
        }
    }

    /**
     * Latest block number — useful for chain-head sanity checks (a
     * stuck RPC returns the same number forever) and the /up health
     * probe.
     */
    public function getNowBlock(): int
    {
        $body = $this->post('/wallet/getnowblock', new \stdClass);
        $rawData = $body['block_header']['raw_data'] ?? [];

        return (int) ($rawData['number'] ?? 0);
    }

    /**
     * Account info for `$address`. Returns the parsed `account` object
     * (balance in sun, frozen TRX, account creation time) or an empty
     * array when the address has no on-chain history yet.
     *
     * @return array<string, mixed>
     */
    public function getAccount(string $address): array
    {
        // visible=true tells TronGrid we're sending the address as
        // Base58 (T...) instead of hex — saves a conversion step on
        // both ends.
        return $this->post('/wallet/getaccount', [
            'address' => $address,
            'visible' => true,
        ]);
    }

    /**
     * Broadcast a signed transaction. Caller is responsible for the
     * full sign-and-package step (Phase 2b). Returns the parsed
     * response — `result=true` indicates the node accepted the tx
     * for relay; that's NOT confirmation, just acceptance.
     *
     * @param  array<string, mixed>  $signedTx
     * @return array<string, mixed>
     */
    public function broadcastTransaction(array $signedTx): array
    {
        return $this->post('/wallet/broadcasttransaction', $signedTx);
    }

    /**
     * Confirmation lookup for a previously-broadcast tx. Returns the
     * parsed `getTransactionInfoById` response — caller checks
     * `blockNumber` (must be > 0 for "in a block") and `receipt.result`
     * (must be `SUCCESS` for TRC20 contract calls). Returns an empty
     * array for a tx that hasn't landed yet (TronGrid returns `{}`).
     *
     * @return array<string, mixed>
     */
    public function getTransactionInfo(string $txid): array
    {
        return $this->post('/wallet/gettransactioninfobyid', [
            'value' => $txid,
        ]);
    }

    /**
     * @param  array<string, mixed>|\stdClass  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array|\stdClass $body): array
    {
        $lastError = null;
        foreach ($this->rpcUrls as $base) {
            try {
                $response = $this->http->request('POST', rtrim($base, '/').$path, [
                    'json' => $body,
                    'timeout' => $this->requestTimeoutSeconds,
                    'headers' => ['Accept' => 'application/json'],
                ]);
                $decoded = json_decode((string) $response->getBody(), true);
                if (! is_array($decoded)) {
                    // Empty {} body is the documented "tx not yet
                    // included" reply for getTransactionInfo — return
                    // [] not null so callers don't have to ?-> guard.
                    return [];
                }

                return $decoded;
            } catch (ConnectException $e) {
                // Transport-level failure — try the next URL. We
                // log at debug level only (the real signal is the
                // composite "all URLs failed" error below).
                $lastError = $e;
                Log::debug('tron rpc connect failed, trying fallback', [
                    'url' => $base,
                    'err' => $e->getMessage(),
                ]);

                continue;
            } catch (GuzzleException $e) {
                // HTTP-level error (4xx / 5xx). We do NOT fall through
                // to the next URL on these — a 4xx is usually our bug,
                // and a 5xx might still mean our request was processed.
                // Surface the error so the caller decides.
                throw new TronRpcException(
                    'tron rpc http error: '.$e->getMessage(),
                    previous: $e,
                );
            }
        }

        throw new TronRpcException(
            'all tron rpc urls unreachable; last error: '.($lastError?->getMessage() ?? 'unknown'),
            previous: $lastError,
        );
    }
}
