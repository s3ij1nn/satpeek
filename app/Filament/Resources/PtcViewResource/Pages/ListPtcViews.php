<?php

namespace App\Filament\Resources\PtcViewResource\Pages;

use App\Filament\Resources\PtcViewResource;
use Filament\Resources\Pages\ListRecords;

class ListPtcViews extends ListRecords
{
    protected static string $resource = PtcViewResource::class;

    // No header actions — read-only resource. CreateAction would need a
    // form anyway, and we explicitly forbid creation via Resource::canCreate().
    protected function getHeaderActions(): array
    {
        return [];
    }
}
