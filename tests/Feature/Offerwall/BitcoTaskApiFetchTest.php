<?php

declare(strict_types=1);

namespace Tests\Feature\Offerwall;

use App\Models\User;
use App\Offerwall\BitcoTaskAdapter;
use App\Offerwall\Contracts\OfferDescriptor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Locks the BitcoTasks REST publisher integration against the published
 * spec (https://bitcotasks.com/documentations, fetched 2026-04-27):
 *
 *   - GET /<api>/<API_KEY>/<USER_ID>/<USER_IP>
 *     where <api> is one of: api (PTC), sl-api (Shortlink), ra-api (Read Article)
 *   - Authorization: Bearer <BEARER_TOKEN>
 *   - Response { status, message, data: [{id, title, reward, url, ...}] }
 *
 * Plus the local mapping rules:
 *   - reward (USD decimal) × usd_to_sat → integer satoshis
 *   - PTC `duration` honoured; sl-api / ra-api fall back to family default
 *   - missing config / non-2xx / malformed body → empty array (logged)
 *   - garbage IP → empty array, no HTTP call
 */
class BitcoTaskApiFetchTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'pub-key-XYZ';

    private const BEARER = 'bearer-token-ABC';

    private const USER_IP = '198.51.100.42';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('satpeek.bitcotask', [
            'publisher_id' => 'PUB-TEST',
            'api_key' => self::API_KEY,
            'bearer_token' => self::BEARER,
            'api_base' => 'https://bitcotasks.com',
            's2s_secret' => 'unused-here',
            // 100k sat per USD — round number for assertions.
            'usd_to_sat' => 100000.0,
            'ip_allowlist' => ['45.14.135.48'],
        ]);
    }

    public function test_ptc_fetch_hits_api_path_with_bearer_and_maps_descriptor(): void
    {
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake([
            'bitcotasks.com/api/*' => $http->response([
                'status' => '200',
                'message' => 'success',
                'data' => [
                    [
                        'id' => 'OFFER-1',
                        'title' => 'Click my ad',
                        'reward' => '0.10',
                        'currency_name' => 'Cash',
                        'url' => 'https://bitcotasks.com/ptc/visit/OFFER-1',
                        'duration' => '45',
                    ],
                ],
            ]),
        ]);

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, self::USER_IP);

        $this->assertCount(1, $offers);
        $offer = $offers[0];
        $this->assertInstanceOf(OfferDescriptor::class, $offer);
        $this->assertSame('bitcotask', $offer->source);
        $this->assertSame('OFFER-1', $offer->externalId);
        $this->assertSame('Click my ad', $offer->title);
        $this->assertSame('https://bitcotasks.com/ptc/visit/OFFER-1', $offer->targetUrl);
        // 0.10 USD × 100k sat/USD = 10000 sat.
        $this->assertSame(10000, $offer->rewardSat);
        // PTC honours `duration` field when present.
        $this->assertSame(45, $offer->durationSec);

        $expectedUrl = sprintf(
            'https://bitcotasks.com/api/%s/%d/%s',
            self::API_KEY,
            $user->id,
            self::USER_IP,
        );
        $http->assertSent(fn (ClientRequest $r): bool => $r->url() === $expectedUrl
            && $r->hasHeader('Authorization', 'Bearer '.self::BEARER));
    }

    public function test_shortlink_fetch_uses_sl_api_path_with_default_duration(): void
    {
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake([
            'bitcotasks.com/sl-api/*' => $http->response([
                'status' => '200',
                'message' => 'success',
                'data' => [
                    [
                        'id' => 'SL-1',
                        'title' => 'Shorten me',
                        'reward' => '0.05',
                        'url' => 'https://bitcotasks.com/sl/SL-1',
                        'limit' => '5',
                        // No `duration` field on shortlinks.
                    ],
                ],
            ]),
        ]);

        $offers = (new BitcoTaskAdapter($http))->fetchShortlinkOffersFor($user, self::USER_IP);

        $this->assertCount(1, $offers);
        $offer = $offers[0];
        // sl-api family default = 10 s.
        $this->assertSame(10, $offer->durationSec);
        $this->assertSame(5000, $offer->rewardSat);
        $this->assertSame(5, $offer->dailyLimitPerUser);

        $expectedUrl = sprintf(
            'https://bitcotasks.com/sl-api/%s/%d/%s',
            self::API_KEY,
            $user->id,
            self::USER_IP,
        );
        $http->assertSent(fn (ClientRequest $r): bool => $r->url() === $expectedUrl);
    }

    public function test_read_article_fetch_uses_ra_api_path_with_default_duration(): void
    {
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake([
            'bitcotasks.com/ra-api/*' => $http->response([
                'status' => '200',
                'message' => 'success',
                'data' => [
                    [
                        'id' => 'RA-1',
                        'title' => 'Read this',
                        'reward' => '0.03',
                        'url' => 'https://bitcotasks.com/ra/RA-1',
                    ],
                ],
            ]),
        ]);

        $offers = (new BitcoTaskAdapter($http))->fetchReadArticleOffersFor($user, self::USER_IP);

        $this->assertCount(1, $offers);
        // ra-api family default = 60 s.
        $this->assertSame(60, $offers[0]->durationSec);

        $expectedUrl = sprintf(
            'https://bitcotasks.com/ra-api/%s/%d/%s',
            self::API_KEY,
            $user->id,
            self::USER_IP,
        );
        $http->assertSent(fn (ClientRequest $r): bool => $r->url() === $expectedUrl);
    }

    public function test_missing_bearer_skips_http_call(): void
    {
        config()->set('satpeek.bitcotask.bearer_token', '');
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake();

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, self::USER_IP);

        $this->assertSame([], $offers);
        $http->assertNothingSent();
    }

    public function test_missing_api_key_skips_http_call(): void
    {
        config()->set('satpeek.bitcotask.api_key', '');
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake();

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, self::USER_IP);

        $this->assertSame([], $offers);
        $http->assertNothingSent();
    }

    public function test_invalid_ip_short_circuits_without_burning_quota(): void
    {
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake();

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, 'not-an-ip');

        $this->assertSame([], $offers);
        $http->assertNothingSent();
    }

    public function test_non_2xx_response_returns_empty_and_logs(): void
    {
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake([
            'bitcotasks.com/*' => $http->response('upstream down', 503),
        ]);

        Log::spy();

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, self::USER_IP);

        $this->assertSame([], $offers);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'bitcotask fetch non-2xx'))
            ->once();
    }

    public function test_malformed_body_returns_empty_and_logs(): void
    {
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake([
            'bitcotasks.com/*' => $http->response(['status' => '500', 'message' => 'oops']),
        ]);

        Log::spy();

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, self::USER_IP);

        $this->assertSame([], $offers);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'unexpected body shape'))
            ->once();
    }

    public function test_zero_usd_to_sat_yields_zero_reward_sat(): void
    {
        config()->set('satpeek.bitcotask.usd_to_sat', 0);
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake([
            'bitcotasks.com/*' => $http->response([
                'status' => '200',
                'message' => 'success',
                'data' => [[
                    'id' => 'A', 'title' => 'X', 'reward' => '5.00',
                    'url' => 'https://x.test/a',
                ]],
            ]),
        ]);

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, self::USER_IP);

        $this->assertCount(1, $offers);
        $this->assertSame(0, $offers[0]->rewardSat);
    }

    public function test_rows_missing_required_fields_are_dropped_not_fatal(): void
    {
        $user = User::factory()->create();
        $http = new HttpFactory;
        $http->fake([
            'bitcotasks.com/*' => $http->response([
                'status' => '200',
                'message' => 'success',
                'data' => [
                    ['id' => '', 'url' => 'https://x.test', 'reward' => '0.10'],   // empty id
                    ['id' => 'B', 'url' => '', 'reward' => '0.10'],                 // empty url
                    ['id' => 'C', 'url' => 'https://x.test', 'reward' => ''],       // empty reward
                    ['id' => 'D', 'url' => 'https://x.test/d', 'reward' => '0.10', 'title' => 'OK'],
                ],
            ]),
        ]);

        $offers = (new BitcoTaskAdapter($http))->fetchPtcOffersFor($user, self::USER_IP);

        $this->assertCount(1, $offers);
        $this->assertSame('D', $offers[0]->externalId);
    }

    public function test_zero_arg_fetchers_return_empty_so_global_sync_stays_safe(): void
    {
        $http = new HttpFactory;
        $http->fake();
        $adapter = new BitcoTaskAdapter($http);

        // Only fetchPtcOffers remains on the OfferwallAdapter contract;
        // the bulk-shortlink sibling was dropped in cleanup. The PTC
        // bulk pull stays safe (no HTTP) so satpeek:sync-offerwalls
        // doesn't accidentally hammer BitcoTask on every cron tick.
        $this->assertSame([], $adapter->fetchPtcOffers());
        $http->assertNothingSent();
    }
}
