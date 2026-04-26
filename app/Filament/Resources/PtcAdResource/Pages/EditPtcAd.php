<?php

namespace App\Filament\Resources\PtcAdResource\Pages;

use App\Filament\Resources\PtcAdResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPtcAd extends EditRecord
{
    protected static string $resource = PtcAdResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
