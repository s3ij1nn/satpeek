<?php

namespace App\Filament\Resources\ShortlinkResource\Pages;

use App\Filament\Resources\ShortlinkResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShortlink extends EditRecord
{
    protected static string $resource = ShortlinkResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
