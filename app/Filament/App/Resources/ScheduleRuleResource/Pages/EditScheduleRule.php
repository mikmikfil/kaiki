<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ScheduleRuleResource\Pages;

use App\Filament\App\Resources\ScheduleRuleResource;
use App\Models\ScheduleRule;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditScheduleRule extends EditRecord
{
    use ConsumesWeekdays;

    protected static string $resource = ScheduleRuleResource::class;

    /**
     * Delete removes the rule and **not** its departures (§2.3).
     *
     * They may already hold bookings. "Also cancel future empty departures" is
     * a separate explicit action, and it lands with #27 — the departures table
     * does not exist yet.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ScheduleRule $record */
        $record = $this->getRecord();

        return $this->fillWeekdays($data, $record);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->saveScheduleRule($record, $data);
    }
}
