<?php

namespace App\Offerwall;

use App\Models\User;
use App\Offerwall\Contracts\CallbackResult;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallAdapter;
use App\Offerwall\Contracts\OfferwallPerUserAdapter;
use App\Offerwall\Contracts\ViewSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Local-development fixture adapter.
 *
 * Implements both the global `OfferwallAdapter` contract (cron-synced
 * inventory) AND the `OfferwallPerUserAdapter` contract (per-render
 * inventory) so OFFERWALLS_ENABLED=mock surfaces offers identically to
 * how production BitcoTaskAdapter surfaces them. Pre-fix the mock only
 * implemented the global contract — `OfferwallMerge::ptcOffersFor()` and
 * its sibling per-user methods skip non-`OfferwallPerUserAdapter`
 * adapters entirely, so a developer running locally saw an empty
 * per-render offer list and had no way to spot the silent dev/prod
 * divergence until shipping. Now `mock` flows through both code paths.
 */
class MockAdapter implements OfferwallAdapter, OfferwallPerUserAdapter
{
    public function name(): string
    {
        return 'mock';
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchPtcOffers(): array
    {
        return $this->ptcOffers();
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchPtcOffersFor(User $user, string $ip): array
    {
        return $this->ptcOffers();
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchShortlinkOffersFor(User $user, string $ip): array
    {
        return [
            new OfferDescriptor(
                source: 'mock',
                externalId: 'mock-sl-1',
                title: 'Mock shortlink (10s hold)',
                description: 'Local development shortlink offer.',
                targetUrl: 'https://example.com/mock-sl-1',
                rewardSat: 3,
                durationSec: 10,
                dailyLimitPerUser: 5,
            ),
        ];
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchReadArticleOffersFor(User $user, string $ip): array
    {
        return [
            new OfferDescriptor(
                source: 'mock',
                externalId: 'mock-article-1',
                title: 'Mock article (60s read)',
                description: 'Local development read-and-earn offer.',
                targetUrl: 'https://example.com/mock-article-1',
                rewardSat: 7,
                durationSec: 60,
                dailyLimitPerUser: 3,
            ),
        ];
    }

    public function startView(User $user, OfferDescriptor $offer): ViewSession
    {
        return new ViewSession(
            epochToken: 'mock_'.Str::lower(Str::random(24)),
            redirectUrl: $offer->targetUrl,
            durationSec: $offer->durationSec,
        );
    }

    public function verifyCallback(Request $request): ?CallbackResult
    {
        return null;
    }

    /**
     * Shared PTC fixture — used by both the global cron path
     * ({@see fetchPtcOffers()}) and the per-render path
     * ({@see fetchPtcOffersFor()}). Returning the same set keeps
     * dev-mode behaviour symmetric whichever code path the caller takes.
     *
     * @return array<int, OfferDescriptor>
     */
    private function ptcOffers(): array
    {
        return [
            new OfferDescriptor(
                source: 'mock',
                externalId: 'mock-ptc-1',
                title: 'Mock PTC sample (15s)',
                description: 'Local development PTC ad. No real network call.',
                targetUrl: 'https://example.com/mock-ptc-1',
                rewardSat: 5,
                durationSec: 15,
                dailyLimitPerUser: 5,
            ),
            new OfferDescriptor(
                source: 'mock',
                externalId: 'mock-ptc-2',
                title: 'Mock PTC sample (30s)',
                description: 'Longer mock ad.',
                targetUrl: 'https://example.com/mock-ptc-2',
                rewardSat: 10,
                durationSec: 30,
                dailyLimitPerUser: 3,
            ),
        ];
    }
}
