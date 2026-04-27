<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserIpObservationResource\Pages;

use App\Filament\Resources\UserIpObservationResource;
use Filament\Resources\Pages\ListRecords;

class ListUserIpObservations extends ListRecords
{
    protected static string $resource = UserIpObservationResource::class;

    // No header actions — read-only audit surface. The data is appended
    // by the auth controllers via UserIpObserver; admin writes would
    // corrupt the multi-account-detection signal.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
