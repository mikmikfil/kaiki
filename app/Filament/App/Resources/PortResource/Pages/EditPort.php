<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PortResource\Pages;

use App\Filament\App\Resources\PortResource;
use App\Filament\App\Support\TitledByRecord;
use App\Filament\Support\MoreActions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPort extends EditRecord
{
    use TitledByRecord;

    protected static string $resource = PortResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return MoreActions::header([], [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ]);
    }
}
