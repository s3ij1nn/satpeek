<?php

declare(strict_types=1);

namespace App\Filament\Resources\BalanceLedgerResource\Pages;

use App\Filament\Resources\BalanceLedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListBalanceLedgers extends ListRecords
{
    protected static string $resource = BalanceLedgerResource::class;

    // No header actions — read-only audit surface. The ledger is the
    // source of truth for users.balance_sat; admin writes here would
    // either silently desync the cached balance or hide a real bug.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
