<?php

declare(strict_types=1);

namespace App\Offerwall;

use App\Models\User;
use App\Offerwall\Contracts\OfferDescriptor;
use App\Offerwall\Contracts\OfferwallPerUserAdapter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Helper that pulls per-(user, IP) offers from every enabled adapter that
 * implements {@see OfferwallPerUserAdapter} and merges them into a single
 * descriptor list.
 *
 * Two design rules SatPeek must keep, given that the external publisher
 * (BitcoTasks today) gates API access on a manual review of the publisher
 * application:
 *
 *   1. **BitcoTasks-optional.** If `OFFERWALLS_ENABLED` does not include
 *      `bitcotask` (the default in `.env.example`), or if the adapter is
 *      registered but `api_key` / `bearer_token` are unset, every method
 *      here returns `[]` — the surface keeps rendering internal inventory
 *      only.
 *
 *   2. **One bad adapter does not break the page.** A network blip, a
 *      mis-configured token, or a publisher outage is logged at warning
 *      level and skipped; the merge returns whatever the other adapters
 *      produced. The empty-array fallbacks inside the adapters themselves
 *      already cover most failure modes — this catch is the last line of
 *      defence against an unexpected throw escaping the SDK.
 */
class OfferwallMerge
{
    public function __construct(private readonly AdapterRegistry $registry) {}

    /** @return array<int, OfferDescriptor> */
    public function fetchPtcFor(User $user, string $ip): array
    {
        return $this->fetch('ptc', $user, $ip);
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchShortlinkFor(User $user, string $ip): array
    {
        return $this->fetch('shortlink', $user, $ip);
    }

    /** @return array<int, OfferDescriptor> */
    public function fetchReadArticleFor(User $user, string $ip): array
    {
        return $this->fetch('read_article', $user, $ip);
    }

    /**
     * @param  'ptc'|'shortlink'|'read_article'  $kind
     * @return array<int, OfferDescriptor>
     */
    private function fetch(string $kind, User $user, string $ip): array
    {
        $merged = [];
        foreach ($this->registry->enabled() as $adapter) {
            if (! $adapter instanceof OfferwallPerUserAdapter) {
                continue;
            }
            try {
                $offers = match ($kind) {
                    'ptc' => $adapter->fetchPtcOffersFor($user, $ip),
                    'shortlink' => $adapter->fetchShortlinkOffersFor($user, $ip),
                    'read_article' => $adapter->fetchReadArticleOffersFor($user, $ip),
                };
            } catch (Throwable $e) {
                Log::warning('offerwall merge: adapter threw', [
                    'adapter' => $adapter->name(),
                    'kind' => $kind,
                    'err' => $e->getMessage(),
                ]);

                continue;
            }
            foreach ($offers as $offer) {
                $merged[] = $offer;
            }
        }

        return $merged;
    }
}
