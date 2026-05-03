<?php

namespace App\Filament\Resources\BotSignalWeightResource\Pages;

use App\Filament\Resources\BotSignalWeightResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBotSignalWeights extends ListRecords
{
    protected static string $resource = BotSignalWeightResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
