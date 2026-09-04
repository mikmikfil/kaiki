<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RatePlanResource\Pages;

use App\Filament\App\Resources\RatePlanResource;
use App\Models\RatePlan;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRatePlan extends EditRecord
{
    use ConsumesBandPrices;

    protected static string $resource = RatePlanResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), RestoreAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RatePlan $record */
        $record = $this->getRecord();

        return $this->fillBandPrices($data, $record);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->saveRatePlan($record, $data);
    }
}
