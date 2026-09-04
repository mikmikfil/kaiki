<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PortResource\Pages;

use App\Filament\App\Resources\PortResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPorts extends ListRecords
{
    protected static string $resource = PortResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
