<?php

namespace App\Offerwall;

use App\Models\User;
use App\Offerwall\Contracts\CallbackResult;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallAdapter;
use App\Offerwall\Contracts\ViewSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MockAdapter implements OfferwallAdapter
{
    public function name(): string
    {
        return 'mock';
    }

    public function fetchPtcOffers(): array
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
}
