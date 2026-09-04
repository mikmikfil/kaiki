<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\DepartureResource\Pages;

use App\Filament\App\Resources\DepartureResource;
use App\Models\Departure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListDepartures extends ListRecords
{
    protected static string $resource = DepartureResource::class;

    /**
     * The create button appears only for someone who may create.
     *
     * Filament hides an action a policy denies, and `DeparturePolicy` splits
     * read from write — crew hold `ViewDepartures` and not `ManageCatalogue` —
     * so this is stated rather than assumed.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return Gate::allows('create', Departure::class)
            ? [CreateAction::make()]
            : [];
    }
}
