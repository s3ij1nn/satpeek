<?php

declare(strict_types=1);

namespace App\Filament\Resources\OfferwallProviderSettingResource\Pages;

use App\Filament\Resources\OfferwallProviderSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOfferwallProviderSetting extends EditRecord
{
    protected static string $resource = OfferwallProviderSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
