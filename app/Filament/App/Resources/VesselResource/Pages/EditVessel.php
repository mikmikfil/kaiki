<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\VesselResource\Pages;

use App\Filament\App\Resources\VesselResource;
use App\Filament\App\Resources\VesselResource\Pages\Concerns\TranslatesVesselFormData;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditVessel extends EditRecord
{
    use TranslatesVesselFormData;

    protected static string $resource = VesselResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
