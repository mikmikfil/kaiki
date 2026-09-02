<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselResource\Pages;

use App\Filament\App\Resources\VesselResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVessels extends ListRecords
{
    protected static string $resource = VesselResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
