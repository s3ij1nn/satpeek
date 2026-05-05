<?php

declare(strict_types=1);

namespace Tests\Unit\Offerwall;

use App\Models\User;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallAdapter;
use App\Offerwall\Contracts\OfferwallPerUserAdapter;
use App\Offerwall\MockAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Pins MockAdapter against BOTH offerwall contracts.
 *
 * Pre-fix the local-dev mock only implemented the global
 * `OfferwallAdapter` — `OfferwallMerge` skips non-`OfferwallPerUserAdapter`
 * adapters in the per-render code path, so a developer running with
 * OFFERWALLS_ENABLED=mock saw an empty per-user offer list and had no way
 * to spot the silent dev/prod divergence until shipping. This test stops
 * the regression: if either contract is dropped from MockAdapter, the
 * suite turns red.
 */
class MockAdapterContractTest extends TestCase
{
    public function test_mock_adapter_implements_both_offerwall_contracts(): void
    {
        $adapter = new MockAdapter;

        $this->assertInstanceOf(OfferwallAdapter::class, $adapter);
        $this->assertInstanceOf(OfferwallPerUserAdapter::class, $adapter);
    }

    public function test_per_user_methods_return_offer_descriptors(): void
    {
        $adapter = new MockAdapter;
        $user = new User;

        $ptc = $adapter->fetchPtcOffersFor($user, '127.0.0.1');
        $shortlink = $adapter->fetchShortlinkOffersFor($user, '127.0.0.1');
        $articles = $adapter->fetchReadArticleOffersFor($user, '127.0.0.1');

        // Each surface must return at least one offer so a developer
        // running locally always has something to click — dev parity
        // with the production adapter, which can return empty arrays
        // legitimately when the upstream API has nothing for the user.
        $this->assertNotEmpty($ptc);
        $this->assertNotEmpty($shortlink);
        $this->assertNotEmpty($articles);

        $allOffers = [...$ptc, ...$shortlink, ...$articles];
        foreach ($allOffers as $offer) {
            $this->assertInstanceOf(OfferDescriptor::class, $offer);
            $this->assertSame('mock', $offer->source);
        }
    }

    public function test_per_user_ptc_offers_match_global_offers(): void
    {
        // Symmetry: a developer flipping between cron-sync and
        // per-render code paths should see the SAME mock PTC inventory
        // either way. Asserting structural equality on externalId is
        // enough — descriptor identity is captured by that key.
        $adapter = new MockAdapter;
        $globalIds = array_map(fn (OfferDescriptor $o) => $o->externalId, $adapter->fetchPtcOffers());
        $perUserIds = array_map(
            fn (OfferDescriptor $o) => $o->externalId,
            $adapter->fetchPtcOffersFor(new User, '127.0.0.1'),
        );

        $this->assertSame($globalIds, $perUserIds);
    }
}
