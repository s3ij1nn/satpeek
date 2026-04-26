<?php

namespace App\Console\Commands;

use App\Models\PtcAd;
use App\Models\Shortlink;
use App\Offerwall\AdapterRegistry;
use App\Offerwall\Contracts\OfferDescriptor;
use Illuminate\Console\Command;

class SyncOfferwallsCommand extends Command
{
    protected $signature = 'satpeek:sync-offerwalls';

    protected $description = 'Pull PTC + shortlink offers from enabled offerwall adapters into local cache.';

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
            $this->line("[{$name}] fetching ptc offers...");
            foreach ($adapter->fetchPtcOffers() as $offer) {
                $this->upsertPtc($offer);
            }
            $this->line("[{$name}] fetching shortlink offers...");
            foreach ($adapter->fetchShortlinkOffers() as $offer) {
                $this->upsertShortlink($offer);
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

    private function upsertShortlink(OfferDescriptor $o): void
    {
        Shortlink::updateOrCreate(
            ['source' => $o->source, 'external_id' => $o->externalId],
            [
                'title' => $o->title,
                'target_url' => $o->targetUrl,
                'reward_sat' => $o->rewardSat,
                'hold_seconds' => $o->durationSec,
                'daily_limit_per_user' => $o->dailyLimitPerUser,
                'is_active' => true,
            ]
        );
    }
}
