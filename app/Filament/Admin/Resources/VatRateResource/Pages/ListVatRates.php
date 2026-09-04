<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VatRateResource\Pages;

use App\Filament\Admin\Resources\VatRateResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVatRates extends ListRecords
{
    protected static string $resource = VatRateResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
