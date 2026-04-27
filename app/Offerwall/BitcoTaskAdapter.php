<?php

namespace App\Offerwall;

use App\Models\User;
use App\Offerwall\Contracts\CallbackResult;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallAdapter;
use App\Offerwall\Contracts\ViewSession;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BitcoTask publisher adapter.
 *
 * NOTE: BitcoTask's publisher API endpoints / request shape have to be
 * confirmed against current docs (see plan: Open follow-ups). This class
 * encodes the shape that fits the rest of the platform and isolates the
 * call surface so endpoint changes are localised.
 *
 * S2S callback verification uses HMAC-SHA256 over the raw request body
 * with the configured shared secret. Adjust verification once the
 * publisher portal exposes the actual signature scheme.
 */
class BitcoTaskAdapter implements OfferwallAdapter
{
    public function __construct(private readonly Client $http) {}

    public function name(): string
    {
        return 'bitcotask';
    }

    public function fetchPtcOffers(): array
    {
        $payload = $this->call('GET', '/offers/ptc');

        return array_map(fn ($o) => $this->toOffer($o, isShortlink: false), $payload['data'] ?? []);
    }

    public function fetchShortlinkOffers(): array
    {
        $payload = $this->call('GET', '/offers/shortlinks');

        return array_map(fn ($o) => $this->toOffer($o, isShortlink: true), $payload['data'] ?? []);
    }

    public function startView(User $user, OfferDescriptor $offer): ViewSession
    {
        $token = 'bt_'.Str::lower(Str::random(24));
        $payload = $this->call('POST', '/sessions', json: [
            'offer_id' => $offer->externalId,
            'subid' => (string) $user->id,
            'click_token' => $token,
        ]);

        return new ViewSession(
            epochToken: $token,
            redirectUrl: (string) ($payload['redirect_url'] ?? $offer->targetUrl),
            durationSec: (int) ($payload['duration_sec'] ?? $offer->durationSec),
            meta: ['session_id' => $payload['session_id'] ?? null],
        );
    }

    public function verifyCallback(Request $request): ?CallbackResult
    {
        $secret = (string) config('satpeek.bitcotask.s2s_secret');
        if ($secret === '') {
            return null;
        }
        $signature = (string) $request->header('X-BT-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            Log::warning('bitcotask callback signature mismatch', ['ip' => $request->ip()]);

            return null;
        }
        $body = $request->json()->all();
        $userId = isset($body['subid']) ? (int) $body['subid'] : null;

        return new CallbackResult(
            source: $this->name(),
            externalId: (string) ($body['offer_id'] ?? ''),
            userId: $userId,
            rewardSat: (int) ($body['reward_sat'] ?? 0),
            status: (string) ($body['status'] ?? 'completed'),
            meta: $body,
        );
    }

    private function toOffer(array $raw, bool $isShortlink): OfferDescriptor
    {
        return new OfferDescriptor(
            source: $this->name(),
            externalId: (string) ($raw['id'] ?? ''),
            title: (string) ($raw['title'] ?? 'BitcoTask offer'),
            description: $raw['description'] ?? null,
            targetUrl: (string) ($raw['target_url'] ?? ''),
            rewardSat: (int) ($raw['reward_sat'] ?? 0),
            durationSec: (int) ($raw['duration_sec'] ?? ($isShortlink ? 10 : 15)),
            dailyLimitPerUser: (int) ($raw['daily_limit'] ?? 1),
            meta: $raw,
        );
    }

    private function call(string $method, string $path, array $json = []): array
    {
        $cfg = config('satpeek.bitcotask');
        $url = rtrim((string) $cfg['api_base'], '/').$path;
        try {
            $response = $this->http->request($method, $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.((string) $cfg['api_key']),
                    'X-Publisher-Id' => (string) $cfg['publisher_id'],
                ],
                'json' => $json,
                'timeout' => 10,
            ]);
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        } catch (GuzzleException $e) {
            Log::warning('bitcotask call failed', ['method' => $method, 'path' => $path, 'err' => $e->getMessage()]);

            return [];
        }
    }
}
