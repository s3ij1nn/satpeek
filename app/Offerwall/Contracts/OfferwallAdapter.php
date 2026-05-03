<?php

namespace App\Offerwall\Contracts;

use App\Models\User;
use Illuminate\Http\Request;

interface OfferwallAdapter
{
    public function name(): string;

    /**
     * Bulk PTC offer pull, called by `satpeek:sync-offerwalls` to upsert
     * publisher inventory into the local `ptc_ads` table. Adapters that
     * don't expose a global PTC catalog should return an empty array.
     *
     * Shortlink offers used to have a sibling method here, but post-v0.6.0
     * the /shortlinks surface reads operator-managed providers
     * (ShortlinkProviderCredential) for internal inventory plus per-user
     * OfferwallPerUserAdapter::fetchShortlinkOffersFor() for partner-
     * network offers. There was no remaining consumer for upserted bulk
     * shortlink rows so the method was dropped.
     *
     * @return array<int, OfferDescriptor>
     */
    public function fetchPtcOffers(): array;

    public function startView(User $user, OfferDescriptor $offer): ViewSession;

    /**
     * Verify an inbound S2S callback. Return null if it cannot be attributed
     * to this adapter or if the signature is invalid.
     */
    public function verifyCallback(Request $request): ?CallbackResult;
}
