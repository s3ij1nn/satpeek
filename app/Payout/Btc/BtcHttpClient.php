<?php

declare(strict_types=1);

namespace App\Payout\Btc;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * REST wrapper over the public Esplora-shaped Bitcoin endpoints
 * (mempool.space, blockstream.info). Same multi-URL fallback pattern
 * as the Tron / ETH clients: connect failures fall through; HTTP
 * errors do NOT (broadcast might have been processed).
 *
 * Methods we need for the onchain payout pipeline:
 *   - tipHeight           current chain head
 *   - addressUtxos        list spendable UTXOs for the hot wallet
 *   - feeRecommended      sat/vB fee oracle
 *   - broadcast           POST /tx with raw hex body
 *   - txStatus            confirmation depth + block height
 *
 * Esplora returns JSON for everything except `/tx` POST (returns
 * the txid as plain text body) and the legacy fee endpoint.
 */
class BtcHttpClient
{
    /**
     * @param  array<int, string>  $apiBases  e.g. ['https://mempool.space/api', 'https://blockstream.info/api']
     */
    public function __construct(
        private readonly Client $http,
        private readonly array $apiBases,
        private readonly int $requestTimeoutSeconds = 10,
    ) {
        if ($apiBases === []) {
            throw new RuntimeException('BtcHttpClient requires at least one API base URL');
        }
    }

    /** Current chain tip height. */
    public function tipHeight(): int
    {
        return (int) $this->getRaw('/blocks/tip/height');
    }

    /**
     * Spendable UTXOs for an address. Returns the parsed JSON array;
     * each element has `txid`, `vout`, `value` (sats), `status:
     * {confirmed, block_height}`. Caller filters by `confirmed=true`
     * before selection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function addressUtxos(string $address): array
    {
        $decoded = $this->getJson("/address/{$address}/utxo");

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * sat/vB fee recommendations. Returns
     * `{fastestFee, halfHourFee, hourFee, economyFee, minimumFee}`.
     * Hot-wallet payouts can tolerate `hourFee`; the cron is not
     * latency-critical.
     *
     * @return array<string, int>
     */
    public function feeRecommended(): array
    {
        $decoded = $this->getJson('/v1/fees/recommended');

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Broadcast a signed raw transaction. `$rawHex` is the full
     * segwit-serialised tx (with 0x00 0x01 marker, witness data).
     * Returns the txid (plain text body).
     */
    public function broadcast(string $rawHex): string
    {
        return trim($this->postRaw('/tx', $rawHex));
    }

    /**
     * Tx status: `{confirmed: bool, block_height?, block_hash?, block_time?}`.
     * Empty array when the tx isn't yet known to the node.
     *
     * @return array<string, mixed>
     */
    public function txStatus(string $txid): array
    {
        $decoded = $this->getJson("/tx/{$txid}/status");

        return is_array($decoded) ? $decoded : [];
    }

    private function getJson(string $path): mixed
    {
        $body = $this->getRaw($path);
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new BtcRpcException("btc rest {$path}: invalid json body");
        }

        return $decoded;
    }

    private function getRaw(string $path): string
    {
        return $this->request('GET', $path, null);
    }

    private function postRaw(string $path, string $body): string
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?string $body): string
    {
        $lastError = null;
        foreach ($this->apiBases as $base) {
            try {
                $opts = [
                    'timeout' => $this->requestTimeoutSeconds,
                    'headers' => ['Accept' => 'application/json, text/plain'],
                ];
                if ($body !== null) {
                    $opts['body'] = $body;
                    $opts['headers']['Content-Type'] = 'text/plain';
                }
                $response = $this->http->request($method, rtrim($base, '/').$path, $opts);

                // 404 on /address/.../utxo + similar means "no data" not error;
                // Esplora returns 200 with empty array in normal operation, so
                // we don't need a special path.
                return (string) $response->getBody();
            } catch (ConnectException $e) {
                $lastError = $e;
                Log::debug('btc rest connect failed, trying fallback', [
                    'url' => $base, 'path' => $path, 'err' => $e->getMessage(),
                ]);

                continue;
            } catch (GuzzleException $e) {
                throw new BtcRpcException(
                    "btc rest http error ({$method} {$path}): ".$e->getMessage(),
                    previous: $e,
                );
            }
        }

        throw new BtcUnreachableException(
            "all btc rest urls unreachable ({$method} {$path}); last error: ".($lastError?->getMessage() ?? 'unknown'),
            previous: $lastError,
        );
    }
}
