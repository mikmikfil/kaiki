<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SeasonResource\Pages;

use App\Filament\App\Resources\SeasonResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeasons extends ListRecords
{
    protected static string $resource = SeasonResource::class;

    /**
     * **Το κουμπί μένει**, σε αντίθεση με τις Τιμές (Mike, 2026-09-23).
     *
     * A period is a tenant-level range of dates, not a child of a trip, so
     * making one before any trip exists is a legitimate order of work — unlike
     * a price list, which cannot exist before the thing it prices. What was
     * wrong here was only the empty state, which now lives on the table in
     * {@see SeasonResource::table()} where Filament v3 reads it from.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
