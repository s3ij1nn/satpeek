<?php

namespace App\Console\Commands;

use App\Models\PtcAd;
use App\Offerwall\AdapterRegistry;
use App\Offerwall\Contracts\OfferDescriptor;
use Illuminate\Console\Command;

class SyncOfferwallsCommand extends Command
{
    protected $signature = 'satpeek:sync-offerwalls';

    protected $description = 'Pull PTC offers from enabled offerwall adapters into local PtcAd inventory.';

    public function handle(AdapterRegistry $registry): int
    {
        $enabled = $registry->enabled();
        if (empty($enabled)) {
            $this->info('No offerwall adapters enabled — internal inventory only. Nothing to sync.');

            return self::SUCCESS;
        }
        foreach ($enabled as $adapter) {
            $name = $adapter->name();
            // Internal/admin-managed ads use source='internal' and are never
            // touched by sync. Only adapter-sourced rows get upserted here.
            //
            // Shortlinks are NOT synced here even though
            // OfferwallAdapter::fetchShortlinkOffers() exists on the
            // contract: post-v0.6.0 the /shortlinks surface reads from
            // ShortlinkProviderCredential rows (operator-managed) and
            // the partner-network section reads per-user from
            // OfferwallPerUserAdapter::fetchShortlinkOffersFor() at
            // page-render time. There is no consumer for upserted
            // legacy `shortlinks` rows — they were dead writes before.
            $this->line("[{$name}] fetching ptc offers...");
            foreach ($adapter->fetchPtcOffers() as $offer) {
                $this->upsertPtc($offer);
            }
        }

        return self::SUCCESS;
    }

    private function upsertPtc(OfferDescriptor $o): void
    {
        PtcAd::updateOrCreate(
            ['source' => $o->source, 'external_id' => $o->externalId],
            [
                'title' => $o->title,
                'description' => $o->description,
                'target_url' => $o->targetUrl,
                'reward_sat' => $o->rewardSat,
                'duration_sec' => $o->durationSec,
                'daily_limit_per_user' => $o->dailyLimitPerUser,
                'is_active' => true,
                'meta' => $o->meta,
            ]
        );
    }
}
