<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\CancellationPolicyResource\Pages;

use App\Filament\App\Resources\CancellationPolicyResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCancellationPolicies extends ListRecords
{
    protected static string $resource = CancellationPolicyResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
