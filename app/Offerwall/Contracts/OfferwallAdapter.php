<?php

namespace App\Offerwall\Contracts;

use App\Models\User;
use Illuminate\Http\Request;

interface OfferwallAdapter
{
    public function name(): string;

    /**
     * @return array<int, OfferDescriptor>
     */
    public function fetchPtcOffers(): array;

    /**
     * @return array<int, OfferDescriptor>
     */
    public function fetchShortlinkOffers(): array;

    public function startView(User $user, OfferDescriptor $offer): ViewSession;

    /**
     * Verify an inbound S2S callback. Return null if it cannot be attributed
     * to this adapter or if the signature is invalid.
     */
    public function verifyCallback(Request $request): ?CallbackResult;
}
