<?php

namespace App\Offerwall;

use App\Models\User;
use App\Offerwall\Contracts\CallbackResult;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallAdapter;
use App\Offerwall\Contracts\OfferwallPerUserAdapter;
use App\Offerwall\Contracts\ViewSession;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * BitcoTasks publisher integration.
 *
 * Per the BitcoTasks publisher docs (https://bitcotasks.com/documentations,
 * fetched 2026-04-27), publishers can pull a per-user offer list from
 * three REST endpoints (PTC / Shortlink / Read Article), then send the
 * user to the per-offer URL the response carries. Reward delivery is
 * still server-to-server: when the user completes an offer at the
 * BitcoTasks-side URL, BitcoTasks calls our postback (`POST
 * /webhooks/bitcotask`) with the documented form-encoded payload.
 *
 * Endpoint shape (all three identical except for the path):
 *
 *     GET https://bitcotasks.com/<api>/<API_KEY>/<USER_ID>/<USER_IP>
 *     Authorization: Bearer <BEARER_TOKEN>
 *
 * Where <api> is one of:
 *     - api      — PTC ads
 *     - sl-api   — Shortlinks
 *     - ra-api   — Read Article tasks
 *
 * Response (HTTP 200, JSON):
 *
 *     {
 *       "status": "200",
 *       "message": "success",
 *       "data": [
 *         { "id": "...", "title": "...", "reward": "0.10",
 *           "currency_name": "Cash", "url": "https://bitcotasks.com/...",
 *           "duration": "30", ... },
 *         ...
 *       ]
 *     }
 *
 * Reward conversion: BitcoTasks `reward` is a decimal in the operator's
 * configured currency (typically USD). We multiply by
 * BITCOTASK_USD_TO_SAT (same knob the postback uses) to land satoshis
 * in OfferDescriptor.
 *
 * Postback verification (verifyCallback) is unchanged from the
 * postback-only era — see method docblock + the routes/webhooks.php
 * registration.
 */
class BitcoTaskAdapter implements OfferwallAdapter, OfferwallPerUserAdapter
{
    public function __construct(private readonly HttpFactory $http) {}

    public function name(): string
    {
        return 'bitcotask';
    }

    /**
     * Global zero-arg fetch: no global inventory exists; offers are
     * (user, IP)-scoped. SyncOfferwallsCommand stays a safe no-op.
     *
     * @return array<int, OfferDescriptor>
     */
    public function fetchPtcOffers(): array
    {
        return [];
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchShortlinkOffers(): array
    {
        return [];
    }

    public function startView(User $user, OfferDescriptor $offer): ViewSession
    {
        // Per-offer "start" is whatever the user-facing URL in the
        // OfferDescriptor does — there's no separate session-creation
        // endpoint. Throwing here keeps the call site honest: if you
        // got an OfferDescriptor from this adapter, send the user to
        // $offer->targetUrl directly.
        throw new LogicException(
            'BitcoTask offers are followed by sending the user to '
            .'OfferDescriptor::targetUrl directly; there is no '
            .'startView endpoint to call.'
        );
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchPtcOffersFor(User $user, string $ip): array
    {
        return $this->fetchOffers('api', $user, $ip, defaultDurationSec: 30);
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchShortlinkOffersFor(User $user, string $ip): array
    {
        return $this->fetchOffers('sl-api', $user, $ip, defaultDurationSec: 10);
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchReadArticleOffersFor(User $user, string $ip): array
    {
        return $this->fetchOffers('ra-api', $user, $ip, defaultDurationSec: 60);
    }

    /**
     * Shared HTTP shape for all three endpoint families. Returns an empty
     * list on any failure (network, non-200, malformed body, missing
     * config) so the caller can merge with internal inventory without a
     * conditional. All failure modes log a warning so an operator can
     * spot a bad bearer token in dashboards.
     *
     * @return array<int, OfferDescriptor>
     */
    private function fetchOffers(string $apiPath, User $user, string $ip, int $defaultDurationSec): array
    {
        $cfg = config('satpeek.bitcotask');
        $apiKey = (string) ($cfg['api_key'] ?? '');
        $bearer = (string) ($cfg['bearer_token'] ?? '');
        $base = rtrim((string) ($cfg['api_base'] ?? 'https://bitcotasks.com'), '/');

        if ($apiKey === '' || $bearer === '') {
            return [];
        }
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            // Per the spec, USER_IP is part of the URL path. An empty or
            // garbage IP would either 404 or get a low-fill response;
            // bail locally instead of burning a quota slot.
            return [];
        }

        $url = sprintf('%s/%s/%s/%d/%s', $base, $apiPath, rawurlencode($apiKey), $user->id, rawurlencode($ip));

        try {
            $response = $this->http
                ->withHeaders(['Authorization' => 'Bearer '.$bearer])
                ->timeout(10)
                ->get($url);
        } catch (Throwable $e) {
            Log::warning('bitcotask fetch failed (transport)', [
                'api' => $apiPath,
                'err' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('bitcotask fetch non-2xx', [
                'api' => $apiPath,
                'status' => $response->status(),
            ]);

            return [];
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            Log::warning('bitcotask fetch unexpected body shape', [
                'api' => $apiPath,
                'status' => $body['status'] ?? null,
                'message' => $body['message'] ?? null,
            ]);

            return [];
        }

        $usdToSat = (float) ($cfg['usd_to_sat'] ?? 0);
        $offers = [];
        foreach ($body['data'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $offer = self::rowToDescriptor($row, $usdToSat, $defaultDurationSec);
            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    /**
     * Map a single response row into an OfferDescriptor. Returns null
     * for rows that lack the minimum (id + url + reward) so a future
     * shape change doesn't crash the whole list.
     *
     * @param  array<string, mixed>  $row
     */
    private static function rowToDescriptor(array $row, float $usdToSat, int $defaultDurationSec): ?OfferDescriptor
    {
        $id = (string) ($row['id'] ?? '');
        $url = (string) ($row['url'] ?? '');
        $rewardStr = (string) ($row['reward'] ?? '');
        if ($id === '' || $url === '' || $rewardStr === '') {
            return null;
        }

        $rewardSat = $usdToSat > 0 ? (int) round(((float) $rewardStr) * $usdToSat) : 0;

        // duration only present on PTC; sl-api / ra-api fall back to the
        // family default (10 s for shortlinks, 60 s for read articles).
        $durationSec = isset($row['duration']) && is_numeric($row['duration'])
            ? max(1, (int) $row['duration'])
            : $defaultDurationSec;

        // limit (per-day cap) on shortlink + read-article rows; default 1.
        $dailyLimit = isset($row['limit']) && is_numeric($row['limit'])
            ? max(1, (int) $row['limit'])
            : 1;

        return new OfferDescriptor(
            source: 'bitcotask',
            externalId: $id,
            title: (string) ($row['title'] ?? 'BitcoTasks offer'),
            description: isset($row['description']) ? (string) $row['description'] : null,
            targetUrl: $url,
            rewardSat: $rewardSat,
            durationSec: $durationSec,
            dailyLimitPerUser: $dailyLimit,
            meta: $row,
        );
    }

    public function verifyCallback(Request $request): ?CallbackResult
    {
        $secret = (string) config('satpeek.bitcotask.s2s_secret');
        if ($secret === '') {
            return null;
        }

        // IP allow-list — defence-in-depth alongside the MD5 signature.
        // BitcoTasks publishes their postback IP and updates it rarely;
        // operator-overridable via env when it changes.
        $allowed = (array) config('satpeek.bitcotask.ip_allowlist', []);
        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            Log::warning('bitcotask postback from non-whitelisted IP', [
                'ip' => $request->ip(),
            ]);

            return null;
        }

        $subId = (string) $request->input('subId', '');
        $transId = (string) $request->input('transId', '');
        $reward = (string) $request->input('reward', '');
        $signature = (string) $request->input('signature', '');

        if ($subId === '' || $transId === '' || $reward === '' || $signature === '') {
            return null;
        }

        // Spec: md5($subId . $transId . $reward . $secretKey).
        $expected = md5($subId.$transId.$reward.$secret);
        if (! hash_equals($expected, strtolower($signature))) {
            Log::warning('bitcotask postback signature mismatch', [
                'transId' => $transId,
                'subId' => $subId,
            ]);

            return null;
        }

        // BitcoTasks reports `payout` in USD (decimal). The operator
        // configures a USD→sat conversion rate so the credit lands in
        // the same unit as the rest of the platform.
        $payoutUsd = (float) $request->input('payout', 0);
        $usdToSat = (float) config('satpeek.bitcotask.usd_to_sat', 0);
        $rewardSat = $usdToSat > 0 ? (int) round($payoutUsd * $usdToSat) : 0;

        $statusCode = (int) $request->input('status', 0);
        $userId = ctype_digit($subId) ? (int) $subId : null;

        return new CallbackResult(
            source: $this->name(),
            // externalId carries transId so the controller can use it as
            // the idempotency key (balance_ledgers.external_ref).
            externalId: $transId,
            userId: $userId,
            rewardSat: $rewardSat,
            // status=1 → credit, status=2 → chargeback. Anything else is
            // surfaced verbatim so a future BitcoTasks status doesn't get
            // silently dropped.
            status: match ($statusCode) {
                1 => 'completed',
                2 => 'chargeback',
                default => 'unknown_status_'.$statusCode,
            },
            meta: $request->all(),
        );
    }
}
