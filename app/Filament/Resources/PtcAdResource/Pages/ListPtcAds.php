<?php

namespace App\Filament\Resources\PtcAdResource\Pages;

use App\Filament\Resources\PtcAdResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPtcAds extends ListRecords
{
    protected static string $resource = PtcAdResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
