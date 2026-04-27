<?php

namespace App\Filament\Resources\ShortlinkClickResource\Pages;

use App\Filament\Resources\ShortlinkClickResource;
use Filament\Resources\Pages\ListRecords;

class ListShortlinkClicks extends ListRecords
{
    protected static string $resource = ShortlinkClickResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
