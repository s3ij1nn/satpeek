<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAuditLogResource\Pages;

use App\Filament\Resources\SystemAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSystemAuditLogs extends ListRecords
{
    protected static string $resource = SystemAuditLogResource::class;
}
