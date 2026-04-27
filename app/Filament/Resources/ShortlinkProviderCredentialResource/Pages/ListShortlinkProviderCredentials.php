<?php

namespace App\Filament\Resources\ShortlinkProviderCredentialResource\Pages;

use App\Filament\Resources\ShortlinkProviderCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShortlinkProviderCredentials extends ListRecords
{
    protected static string $resource = ShortlinkProviderCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
