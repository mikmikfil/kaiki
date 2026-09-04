<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselBlockResource\Pages;

use App\Filament\App\Resources\VesselBlockResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVesselBlocks extends ListRecords
{
    protected static string $resource = VesselBlockResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
