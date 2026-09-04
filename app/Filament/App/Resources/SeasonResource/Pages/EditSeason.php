<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SeasonResource\Pages;

use App\Filament\App\Resources\SeasonResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSeason extends EditRecord
{
    use ConsumesRangeRepeater;

    protected static string $resource = SeasonResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->saveSeason($record, $data);
    }
}
