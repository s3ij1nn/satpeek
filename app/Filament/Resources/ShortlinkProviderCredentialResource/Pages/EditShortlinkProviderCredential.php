<?php

namespace App\Filament\Resources\ShortlinkProviderCredentialResource\Pages;

use App\Filament\Resources\ShortlinkProviderCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShortlinkProviderCredential extends EditRecord
{
    protected static string $resource = ShortlinkProviderCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
