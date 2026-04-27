<?php

namespace App\Offerwall\Contracts;

use App\Models\User;

/**
 * Per-user / per-IP offer fetcher.
 *
 * Some publisher APIs (BitcoTasks at the time of writing) scope the
 * available offer set to a single (user, IP) pair — there is no
 * "global inventory" to sync nightly. Adapters that talk to those
 * APIs implement THIS interface in addition to (or instead of)
 * `OfferwallAdapter`'s zero-arg `fetch*Offers()` methods, which they
 * stub as empty arrays.
 *
 * Caller pattern: when the user lands on `/ptc`, `/shortlinks`, or a
 * future `/read-articles` page, controller asks the registry for
 * adapters that satisfy this contract and merges the result into the
 * internal-inventory list before rendering.
 */
interface OfferwallPerUserAdapter
{
    /** @return array<int, OfferDescriptor> */
    public function fetchPtcOffersFor(User $user, string $ip): array;

    /** @return array<int, OfferDescriptor> */
    public function fetchShortlinkOffersFor(User $user, string $ip): array;

    /** @return array<int, OfferDescriptor> */
    public function fetchReadArticleOffersFor(User $user, string $ip): array;
}
