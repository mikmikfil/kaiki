<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RatePlanResource\Pages;

use App\Filament\App\Resources\RatePlanResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRatePlans extends ListRecords
{
    protected static string $resource = RatePlanResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
