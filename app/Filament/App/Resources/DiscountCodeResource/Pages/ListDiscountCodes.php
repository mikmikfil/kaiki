<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\DiscountCodeResource\Pages;

use App\Filament\App\Resources\DiscountCodeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiscountCodes extends ListRecords
{
    protected static string $resource = DiscountCodeResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
