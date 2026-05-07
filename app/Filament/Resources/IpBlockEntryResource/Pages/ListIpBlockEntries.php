<?php

declare(strict_types=1);

namespace App\Filament\Resources\IpBlockEntryResource\Pages;

use App\Filament\Resources\IpBlockEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIpBlockEntries extends ListRecords
{
    protected static string $resource = IpBlockEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
