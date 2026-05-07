<?php

declare(strict_types=1);

namespace App\Filament\Resources\IpBlockEntryResource\Pages;

use App\Filament\Resources\IpBlockEntryResource;
use App\Models\IpBlockEntry;
use Filament\Resources\Pages\CreateRecord;

class CreateIpBlockEntry extends CreateRecord
{
    protected static string $resource = IpBlockEntryResource::class;

    /**
     * After the record is created, hand off to the resource's hook so
     * the admin id, audit log, and cache flush are all stamped from
     * one place. Doing this work in the page (instead of a model
     * observer) keeps the auth context — Auth::id() is null in the
     * observer when the row originates from a console seeder.
     */
    protected function afterCreate(): void
    {
        /** @var IpBlockEntry $record */
        $record = $this->record;
        IpBlockEntryResource::recordCreated($record);
    }
}
