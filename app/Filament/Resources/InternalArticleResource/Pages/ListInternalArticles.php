<?php

namespace App\Filament\Resources\InternalArticleResource\Pages;

use App\Filament\Resources\InternalArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInternalArticles extends ListRecords
{
    protected static string $resource = InternalArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
