<?php

namespace App\Payout;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class FaucetPayClient
{
    public function __construct(private readonly Client $http) {}

    /**
     * Send a payout via FaucetPay's `/api/v1/send`.
     *
     * @return array{ok: bool, payout_id: ?string, message: string, raw: array}
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
