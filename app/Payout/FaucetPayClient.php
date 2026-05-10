<?php

namespace App\Payout;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

class FaucetPayClient
{
    public function __construct(private readonly Client $http) {}

    /**
     * Send a payout via FaucetPay's `/api/v1/send`.
     *
     * Two failure modes the caller MUST distinguish:
     *
     *   - **Unreachable** — TCP / DNS-layer failure before the request hit
     *     the wire (Guzzle `ConnectException`). Throws
     *     {@see FaucetPayUnreachableException}; the caller is free to retry
     *     because the server never started processing.
     *   - **Anything else** — request reached FaucetPay but came back with
     *     an error response, timed out mid-request, or returned an
     *     unparseable body. Returns `['ok' => false, ...]`. The caller
     *     MUST NOT retry: we cannot tell whether FaucetPay processed the
     *     payout, and a retry could double-pay.
     *
     * @return array{ok: bool, payout_id: ?string, message: string, raw: array}
     *
     * @throws FaucetPayUnreachableException when the API host is unreachable
     */
    public function send(string $faucetpayEmail, int $amountSat, string $currency = 'BTC', string $referenceId = ''): array
    {
        $cfg = config('satpeek.faucetpay');
        $url = rtrim((string) $cfg['api_base'], '/').'/send';
        try {
            $response = $this->http->request('POST', $url, [
                'form_params' => [
                    'api_key' => (string) $cfg['api_key'],
                    'amount' => (string) $amountSat,
                    'to' => $faucetpayEmail,
                    'currency' => $currency,
                    'referral' => $referenceId,
                    'ip_address' => '',
                ],
                'timeout' => 15,
            ]);
            $decoded = json_decode((string) $response->getBody(), true) ?: [];
            $ok = (int) ($decoded['status'] ?? 0) === 200;
            $payoutId = $decoded['payout_id'] ?? null;

            return [
                'ok' => $ok,
                'payout_id' => is_scalar($payoutId) ? (string) $payoutId : null,
                'message' => (string) ($decoded['message'] ?? ($ok ? 'sent' : 'unknown')),
                'raw' => $decoded,
            ];
        } catch (ConnectException $e) {
            // Pre-flight failure — server never saw the request. Safe to
            // retry without risking a duplicate payout.
            throw new FaucetPayUnreachableException(
                'faucetpay api unreachable: '.$e->getMessage(),
                previous: $e,
            );
        } catch (GuzzleException $e) {
            return [
                'ok' => false,
                'payout_id' => null,
                'message' => 'http_error: '.$e->getMessage(),
                'raw' => [],
            ];
        }
    }

    /**
     * Returns the FaucetPay account balance for `$currency` (in BTC sats
     * for BTC, currency-smallest-unit for non-BTC routes).
     *
     * IMPORTANT for callers: the `balance_sat: 0` returned in the failure
     * branch is a SENTINEL, not a "real zero balance". Always check `ok`
     * before trusting `balance_sat` — using 0 as if it were valid would
     * trigger false "insufficient FaucetPay balance" alerts and could
     * suppress legitimate withdrawals. The /up health probe is the only
     * current caller; new callers MUST mirror its `if (! $r['ok']) return`
     * pattern.
     *
     * @return array{ok: bool, balance_sat: int, raw: array}
     */
    public function balance(string $currency = 'BTC'): array
    {
        $cfg = config('satpeek.faucetpay');
        $url = rtrim((string) $cfg['api_base'], '/').'/balance';
        try {
            $response = $this->http->request('POST', $url, [
                'form_params' => [
                    'api_key' => (string) $cfg['api_key'],
                    'currency' => $currency,
                ],
                'timeout' => 10,
            ]);
            $decoded = json_decode((string) $response->getBody(), true) ?: [];
            $balance = (int) ($decoded['balance'] ?? 0);

            return [
                'ok' => (int) ($decoded['status'] ?? 0) === 200,
                'balance_sat' => $balance,
                'raw' => $decoded,
            ];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'balance_sat' => 0, 'raw' => ['err' => $e->getMessage()]];
        }
    }
}
